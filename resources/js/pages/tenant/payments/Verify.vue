<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import PaymentsNav from './PaymentsNav.vue';

interface PaymentQueueItem {
    id: number;
    reservation_id: number;
    group_id: number | null;
    reservation_code: string | null;
    guest_name: string;
    concept: string;
    amount_label: string;
    requested_at: string;
    expires_at: string | null;
    requested_by: string;
    conversation_id: number | null;
    has_receipt: boolean;
    /** Lo que el sistema leyó en el comprobante y lo que no cuadra. */
    receipt_check: {
        verdict: string | null;
        summary: string | null;
        warnings: string[];
    } | null;
}

interface ReceiptInfo {
    url: string;
    name: string;
    is_image: boolean;
}

interface QueueDetail {
    id: number;
    status: string;
    status_label: string;
    concept: string;
    amount_label: string;
    method: string;
    provider: string | null;
    requested_by: string;
    requested_at: string;
    expires_at: string | null;
    subject_code: string;
    guest: { name: string; phone: string | null; email: string | null };
    details: { label: string; value: string }[];
    bank_accounts: {
        bank: string;
        holder: string;
        /** Los tres: el depósito pudo llegar a cualquiera. */
        numbers: { number: string; label: string; internal: boolean }[];
    }[];
    receipt: ReceiptInfo | null;
    conversation_id: number | null;
}

interface ClosedRequest {
    id: number;
    reservation_code: string;
    guest_name: string;
    concept: string;
    amount_label: string;
    status: string;
    status_label: string;
    reason: string | null;
    closed_label: string;
}

defineProps<{
    queue: PaymentQueueItem[];
    closedRequests: ClosedRequest[];
    canManage: boolean;
    canCashCuts: boolean;
    canSettings: boolean;
}>();

const toast = useToasts();

// La cola se refresca sola: los comprobantes llegan a cualquier hora.
let poller: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    poller = setInterval(() => {
        router.reload({
            only: [
                'queue',
                'closedRequests',
                'overdueBalances',
                'pendingLinks',
                'recentPayments',
            ],
        });
    }, 15000);
});
onBeforeUnmount(() => {
    if (poller) clearInterval(poller);
});

// ── Verificación de transferencias ──
const verifying = ref<PaymentQueueItem | null>(null);
const rejecting = ref<PaymentQueueItem | null>(null);
const paymentBusy = ref(false);
const verifyReference = ref('');
const rejectReason = ref('');

// Foto/PDF del comprobante que se adjunta al aprobar (queda de evidencia).
const receiptFile = ref<File | null>(null);
const receiptPreview = ref<string | null>(null);

function onReceiptChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (receiptPreview.value) URL.revokeObjectURL(receiptPreview.value);
    receiptFile.value = file;
    receiptPreview.value =
        file && file.type.startsWith('image/')
            ? URL.createObjectURL(file)
            : null;
}

function clearReceipt() {
    if (receiptPreview.value) URL.revokeObjectURL(receiptPreview.value);
    receiptFile.value = null;
    receiptPreview.value = null;
}

// ── Detalle de una solicitud por verificar ──
const queueDetail = ref<QueueDetail | null>(null);
const queueDetailItem = ref<PaymentQueueItem | null>(null);
const queueDetailLoading = ref(false);

async function openQueueDetail(item: PaymentQueueItem) {
    queueDetailItem.value = item;
    queueDetailLoading.value = true;
    queueDetail.value = null;
    try {
        const { data } = await axios.get<QueueDetail>(
            `/api/payment-requests/${item.id}`,
        );
        queueDetail.value = data;
    } catch (e: any) {
        queueDetailItem.value = null;
        toast.error(
            'No se pudo cargar',
            e.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        queueDetailLoading.value = false;
    }
}

function closeQueueDetail() {
    queueDetail.value = null;
    queueDetailItem.value = null;
}

// Encadenar detalle → aprobar/rechazar sin perder contexto.
function approveFromDetail() {
    if (queueDetailItem.value) verifying.value = queueDetailItem.value;
    closeQueueDetail();
}

function rejectFromDetail() {
    if (queueDetailItem.value) rejecting.value = queueDetailItem.value;
    closeQueueDetail();
}

// La reserva ya tiene cubierto este dinero: el servidor no lo registra hasta
// que alguien confirme que de verdad entró de más. Caso real cabañas
// 2026-09-15: cinco comprobantes aprobados sobre transferencias ya
// capturadas a mano duplicaron $7,500.
const overpayWarning = ref<string | null>(null);
// El servidor dice qué se confirma: dinero de más, u otro pago distinto al ya capturado.
const overpayLabel = ref('Sí, entró dinero de más');

watch(verifying, () => {
    overpayWarning.value = null;
});

async function approvePayment() {
    if (!verifying.value || paymentBusy.value) return;
    paymentBusy.value = true;
    try {
        const form = new FormData();
        if (verifyReference.value.trim())
            form.append('reference', verifyReference.value.trim());
        if (receiptFile.value) form.append('receipt', receiptFile.value);
        if (overpayWarning.value) form.append('confirm_overpay', '1');
        const { data } = await axios.post(
            `/api/payment-requests/${verifying.value.id}/approve`,
            form,
        );
        if (data.linked) {
            toast.success(
                'Comprobante ligado',
                'Ese dinero ya estaba capturado: el comprobante quedó ligado a ese pago, sin duplicarlo.',
            );
        } else {
            toast.success(
                'Pago verificado',
                data.requires_attention
                    ? 'El pago quedó registrado pero la reserva requiere atención (revisa disponibilidad).'
                    : 'Se registró el pago y se avisó al huésped.',
            );
        }
        verifying.value = null;
        verifyReference.value = '';
        clearReceipt();
        router.reload();
    } catch (e: any) {
        if (
            e.response?.status === 422 &&
            e.response?.data?.needs_confirmation
        ) {
            overpayWarning.value = e.response.data.message;
            overpayLabel.value =
                e.response.data.confirm_label ?? 'Sí, entró dinero de más';
            return;
        }
        toast.error(
            'No se pudo aprobar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        paymentBusy.value = false;
    }
}

async function rejectPayment() {
    if (!rejecting.value || paymentBusy.value || !rejectReason.value.trim())
        return;
    paymentBusy.value = true;
    try {
        await axios.post(`/api/payment-requests/${rejecting.value.id}/reject`, {
            reason: rejectReason.value.trim(),
        });
        toast.success('Pago rechazado', 'Se avisó al huésped con el motivo.');
        rejecting.value = null;
        rejectReason.value = '';
        router.reload();
    } catch (e: any) {
        toast.error(
            'No se pudo rechazar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        paymentBusy.value = false;
    }
}

// ── Reemitir un cobro rechazado/vencido (el huésped corrigió) ──
const reissuingId = ref<number | null>(null);

async function reissueRequest(item: ClosedRequest) {
    if (reissuingId.value) return;
    reissuingId.value = item.id;
    try {
        const { data } = await axios.post(
            `/api/payment-requests/${item.id}/reissue`,
        );
        toast.success(
            'Cobro reemitido',
            data.rescued_receipt
                ? 'Volvió a la cola y el comprobante que llegó por el chat quedó adjunto.'
                : 'Volvió a la cola de verificación.',
        );
        router.reload();
    } catch (e: any) {
        toast.error(
            'No se pudo reemitir',
            e.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        reissuingId.value = null;
    }
}
</script>

<template>
    <RazeLayout title="Pagos por verificar">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Landmark" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Pagos por verificar
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Transferencias reportadas por huéspedes: al aprobar
                            se registra el pago y se le avisa por su canal.
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Link
                        :href="route('tenant.payments')"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Volver a caja y pagos
                    </Link>
                </div>
            </div>

            <PaymentsNav
                current="verify"
                :can-manage="canManage"
                :can-cash-cuts="canCashCuts"
                :badges="{ verify: queue.length }"
            />

            <!-- Pagos por verificar (transferencias reportadas) -->
            <div v-if="canManage" class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10"
                    >
                        <Lucide icon="Landmark" class="h-4 w-4 text-pending" />
                    </div>
                    <div>
                        <div class="text-sm font-medium">
                            Pagos por verificar
                        </div>
                        <p class="text-xs text-slate-500">
                            Transferencias reportadas por huéspedes; al aprobar
                            se registra el pago y se avisa por su canal.
                        </p>
                    </div>
                    <span
                        v-if="queue.length"
                        class="ml-auto rounded-full bg-pending/10 px-2 py-0.5 text-xs font-medium text-pending"
                        >{{ queue.length }}</span
                    >
                </div>
                <div
                    v-if="queue.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="item in queue"
                        :key="item.id"
                        class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate text-sm font-medium">{{
                                    item.guest_name
                                }}</span>
                                <span
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                                    >{{ item.reservation_code }}</span
                                >
                                <span
                                    class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                    >{{ item.concept }} ·
                                    {{ item.amount_label }}</span
                                >
                                <!-- Lo leído en el comprobante: monto, banco y
                                     clave de rastreo para cotejar con el banco
                                     sin abrir la foto -->
                                <span
                                    v-if="item.receipt_check?.summary"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        item.receipt_check.verdict === 'match'
                                            ? 'bg-success/10 text-success'
                                            : 'bg-pending/10 text-pending'
                                    "
                                    >{{ item.receipt_check.summary }}</span
                                >
                                <span
                                    v-else-if="item.has_receipt"
                                    class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                    >Comprobante por verificar</span
                                >
                            </div>
                            <div
                                v-if="item.receipt_check?.warnings?.length"
                                class="mt-1 flex flex-wrap gap-1.5"
                            >
                                <span
                                    v-for="(aviso, i) in item.receipt_check
                                        .warnings"
                                    :key="i"
                                    class="inline-flex items-center gap-1 rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                                >
                                    <Lucide
                                        icon="TriangleAlert"
                                        class="h-3 w-3"
                                    />
                                    {{ aviso }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Solicitado por {{ item.requested_by }}
                                {{ item.requested_at
                                }}<template v-if="item.expires_at">
                                    · vence {{ item.expires_at }}</template
                                >
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5">
                            <Button
                                v-if="item.conversation_id"
                                as="a"
                                :href="`${route('tenant.inbox')}?conversation=${item.conversation_id}`"
                                variant="outline-secondary"
                                class="h-8 rounded-[0.5rem] bg-white text-xs"
                                title="El comprobante llegó por conversación: revísalo en la Bandeja"
                            >
                                <Lucide
                                    icon="MessagesSquare"
                                    class="h-3.5 w-3.5"
                                />
                            </Button>
                            <Button
                                variant="outline-secondary"
                                class="h-8 rounded-[0.5rem] bg-white text-xs"
                                title="Ver todos los detalles de la solicitud"
                                @click="openQueueDetail(item)"
                            >
                                <Lucide icon="Eye" class="mr-1.5 h-3.5 w-3.5" />
                                Detalles
                            </Button>
                            <Button
                                variant="primary"
                                class="h-8 rounded-[0.5rem] text-xs"
                                @click="verifying = item"
                            >
                                <Lucide
                                    icon="Check"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Aprobar
                            </Button>
                            <Button
                                variant="outline-danger"
                                class="h-8 rounded-[0.5rem] bg-white text-xs"
                                @click="rejecting = item"
                            >
                                <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                                Rechazar
                            </Button>
                        </div>
                    </div>
                </div>
                <div
                    v-else
                    class="px-4 py-6 text-center text-xs text-slate-500"
                >
                    Sin transferencias pendientes de verificar.
                </div>

                <!-- Rechazadas/vencidas recientes: reemitir cuando el
                     huésped corrige — antes desaparecían sin regreso. -->
                <div
                    v-if="closedRequests.length"
                    class="border-t border-slate-200/60 dark:border-darkmode-400"
                >
                    <div
                        class="px-4 pt-3 pb-1 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                    >
                        Rechazadas y vencidas recientes
                    </div>
                    <div
                        class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                    >
                        <div
                            v-for="item in closedRequests"
                            :key="item.id"
                            class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        class="truncate text-sm text-slate-500"
                                        >{{ item.guest_name }}</span
                                    >
                                    <span
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                                        >{{ item.reservation_code }}</span
                                    >
                                    <span
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                                        >{{ item.concept }} ·
                                        {{ item.amount_label }}</span
                                    >
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="
                                            item.status === 'rejected'
                                                ? 'bg-danger/10 text-danger'
                                                : 'bg-warning/10 text-warning'
                                        "
                                        >{{ item.status_label }}</span
                                    >
                                </div>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ item.closed_label
                                    }}<template v-if="item.reason">
                                        · Motivo: {{ item.reason }}</template
                                    >
                                </p>
                            </div>
                            <Button
                                variant="outline-primary"
                                class="h-8 shrink-0 rounded-[0.5rem] bg-white text-xs"
                                :disabled="reissuingId === item.id"
                                title="Emite un cobro nuevo; si el comprobante corregido ya llegó por el chat, se adjunta solo"
                                @click="reissueRequest(item)"
                            >
                                <Lucide
                                    icon="RotateCcw"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                {{
                                    reissuingId === item.id
                                        ? 'Reemitiendo…'
                                        : 'Reemitir cobro'
                                }}
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Modal aprobar pago -->
        <Dialog :open="verifying !== null" @close="verifying = null">
            <Dialog.Panel>
                <div v-if="verifying" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                        >
                            <Lucide icon="Landmark" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                Aprobar pago de {{ verifying.guest_name }}
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                {{ verifying.concept }} de
                                {{ verifying.amount_label }} · reserva
                                {{ verifying.reservation_code }}. Confirma que
                                la transferencia ya está en la cuenta del hotel.
                            </p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="mb-1 block text-sm"
                            >Referencia del banco (opcional)</label
                        >
                        <FormInput
                            v-model="verifyReference"
                            type="text"
                            placeholder="Clave de rastreo / folio SPEI"
                        />
                    </div>
                    <div class="mt-4">
                        <label class="mb-1 block text-sm"
                            >Foto del comprobante (opcional)</label
                        >
                        <label
                            v-if="!receiptFile"
                            class="flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-4 text-xs text-slate-500 transition hover:border-primary/40 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <Lucide icon="ImageUp" class="h-4 w-4" />
                            Subir imagen o PDF del comprobante
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp,application/pdf"
                                class="hidden"
                                @change="onReceiptChange"
                            />
                        </label>
                        <div
                            v-else
                            class="flex items-center gap-3 rounded-lg border border-slate-200/70 p-2.5 dark:border-darkmode-400"
                        >
                            <img
                                v-if="receiptPreview"
                                :src="receiptPreview"
                                alt="Comprobante"
                                class="h-14 w-14 shrink-0 rounded object-cover"
                            />
                            <div
                                v-else
                                class="flex h-14 w-14 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                            >
                                <Lucide icon="FileText" class="h-6 w-6" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm">
                                    {{ receiptFile.name }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    Quedará adjunto como evidencia de la
                                    verificación.
                                </p>
                            </div>
                            <button
                                type="button"
                                class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                title="Quitar el archivo"
                                @click="clearReceipt"
                            >
                                <Lucide icon="X" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                    <div
                        class="mt-4 flex items-center gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                    >
                        <Lucide icon="Info" class="h-4 w-4 shrink-0" /> Se
                        registra el pago, la reserva se confirma si cubre el
                        anticipo y se avisa al huésped por su canal.
                    </div>
                    <!-- Dinero que la reserva ya tiene cubierto: se detiene y
                         se pide confirmar antes de registrarlo. -->
                    <div
                        v-if="overpayWarning"
                        class="mt-4 flex items-start gap-2 rounded-lg border border-pending/30 bg-pending/10 px-3 py-2.5 text-xs text-pending"
                    >
                        <Lucide
                            icon="TriangleAlert"
                            class="mt-0.5 h-4 w-4 shrink-0"
                        />
                        <span>{{ overpayWarning }}</span>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="verifying = null"
                            >Cancelar</Button
                        >
                        <Button
                            :variant="overpayWarning ? 'warning' : 'primary'"
                            :disabled="paymentBusy"
                            @click="approvePayment"
                        >
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{
                                paymentBusy
                                    ? 'Registrando…'
                                    : overpayWarning
                                      ? overpayLabel
                                      : 'Aprobar pago'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal rechazar pago -->
        <Dialog :open="rejecting !== null" @close="rejecting = null">
            <Dialog.Panel>
                <div v-if="rejecting" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                Rechazar pago de {{ rejecting.guest_name }}
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                {{ rejecting.concept }} de
                                {{ rejecting.amount_label }} · reserva
                                {{ rejecting.reservation_code }}. El motivo se
                                envía al huésped por su canal.
                            </p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="mb-1 block text-sm">Motivo</label>
                        <FormInput
                            v-model="rejectReason"
                            type="text"
                            placeholder="No se localizó el depósito / monto distinto…"
                        />
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="rejecting = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="paymentBusy || !rejectReason.trim()"
                            @click="rejectPayment"
                        >
                            <Lucide icon="X" class="mr-2 h-4 w-4" />
                            {{ paymentBusy ? 'Enviando…' : 'Rechazar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal detalle de solicitud por verificar -->
        <Dialog
            :open="queueDetailItem !== null"
            size="lg"
            @close="closeQueueDetail"
        >
            <Dialog.Panel>
                <div class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-pending/10 text-pending"
                        >
                            <Lucide icon="Landmark" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-base font-medium">
                                Detalle de la solicitud
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                {{ queueDetailItem?.reservation_code }} ·
                                {{ queueDetailItem?.guest_name }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="queueDetailLoading"
                        class="mt-6 flex items-center justify-center gap-2 py-8 text-sm text-slate-500"
                    >
                        <Lucide
                            icon="RefreshCw"
                            class="h-4 w-4 animate-spin text-primary"
                        />
                        Cargando detalles…
                    </div>

                    <div
                        v-else-if="queueDetail"
                        class="mt-4 max-h-[60vh] space-y-4 overflow-y-auto pr-1"
                    >
                        <!-- El cobro -->
                        <div
                            class="rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                        >
                            <div
                                class="border-b border-slate-200/70 px-4 py-2.5 text-xs font-medium tracking-wide text-slate-400 uppercase dark:border-darkmode-400"
                            >
                                El cobro
                            </div>
                            <div class="space-y-1.5 px-4 py-3 text-sm">
                                <div class="flex justify-between gap-3">
                                    <span class="text-slate-500">Concepto</span
                                    ><span class="font-medium"
                                        >{{ queueDetail.concept }} ·
                                        {{ queueDetail.amount_label }}</span
                                    >
                                </div>
                                <div class="flex justify-between gap-3">
                                    <span class="text-slate-500">Estado</span
                                    ><span>{{ queueDetail.status_label }}</span>
                                </div>
                                <div class="flex justify-between gap-3">
                                    <span class="text-slate-500"
                                        >Solicitado por</span
                                    ><span
                                        >{{ queueDetail.requested_by }} ·
                                        {{ queueDetail.requested_at }}</span
                                    >
                                </div>
                                <div
                                    v-if="queueDetail.expires_at"
                                    class="flex justify-between gap-3"
                                >
                                    <span class="text-slate-500">Vence</span
                                    ><span>{{ queueDetail.expires_at }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- El huésped -->
                        <div
                            class="rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                        >
                            <div
                                class="border-b border-slate-200/70 px-4 py-2.5 text-xs font-medium tracking-wide text-slate-400 uppercase dark:border-darkmode-400"
                            >
                                Huésped
                            </div>
                            <div class="space-y-1.5 px-4 py-3 text-sm">
                                <div class="flex justify-between gap-3">
                                    <span class="text-slate-500">Nombre</span
                                    ><span class="font-medium">{{
                                        queueDetail.guest.name
                                    }}</span>
                                </div>
                                <div
                                    v-if="queueDetail.guest.phone"
                                    class="flex justify-between gap-3"
                                >
                                    <span class="text-slate-500">Teléfono</span
                                    ><span>{{ queueDetail.guest.phone }}</span>
                                </div>
                                <div
                                    v-if="queueDetail.guest.email"
                                    class="flex justify-between gap-3"
                                >
                                    <span class="text-slate-500">Correo</span
                                    ><span class="truncate">{{
                                        queueDetail.guest.email
                                    }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- El sujeto (reserva / experiencia / grupo) -->
                        <div
                            v-if="queueDetail.details.length"
                            class="rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                        >
                            <div
                                class="border-b border-slate-200/70 px-4 py-2.5 text-xs font-medium tracking-wide text-slate-400 uppercase dark:border-darkmode-400"
                            >
                                {{ queueDetail.subject_code }}
                            </div>
                            <div class="space-y-1.5 px-4 py-3 text-sm">
                                <div
                                    v-for="row in queueDetail.details"
                                    :key="row.label"
                                    class="flex justify-between gap-3"
                                >
                                    <span class="text-slate-500">{{
                                        row.label
                                    }}</span
                                    ><span>{{ row.value }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Cuentas donde pudo caer el depósito -->
                        <div
                            v-if="queueDetail.bank_accounts.length"
                            class="rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                        >
                            <div
                                class="border-b border-slate-200/70 px-4 py-2.5 text-xs font-medium tracking-wide text-slate-400 uppercase dark:border-darkmode-400"
                            >
                                Cuentas del hotel
                            </div>
                            <div class="space-y-2.5 px-4 py-3 text-sm">
                                <div
                                    v-for="(
                                        account, i
                                    ) in queueDetail.bank_accounts"
                                    :key="i"
                                >
                                    <div class="text-slate-500">
                                        {{ account.bank }} ·
                                        {{ account.holder }}
                                    </div>
                                    <div
                                        v-for="n in account.numbers"
                                        :key="n.number"
                                        class="mt-0.5 flex justify-between gap-3"
                                        :class="
                                            n.internal ? 'text-slate-400' : ''
                                        "
                                    >
                                        <span class="text-xs"
                                            >{{ n.label
                                            }}<template v-if="n.internal">
                                                (interna)</template
                                            ></span
                                        >
                                        <span class="font-mono text-xs">{{
                                            n.number
                                        }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Comprobante adjunto -->
                        <div
                            v-if="queueDetail.receipt"
                            class="rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                        >
                            <div
                                class="border-b border-slate-200/70 px-4 py-2.5 text-xs font-medium tracking-wide text-slate-400 uppercase dark:border-darkmode-400"
                            >
                                Comprobante
                            </div>
                            <div class="px-4 py-3">
                                <a
                                    :href="queueDetail.receipt.url"
                                    target="_blank"
                                >
                                    <img
                                        v-if="queueDetail.receipt.is_image"
                                        :src="queueDetail.receipt.url"
                                        alt="Comprobante"
                                        class="max-h-56 rounded-lg border border-slate-200/70 object-contain dark:border-darkmode-400"
                                    />
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-2 text-sm text-primary underline"
                                    >
                                        <Lucide
                                            icon="FileText"
                                            class="h-4 w-4"
                                        />
                                        {{ queueDetail.receipt.name }}
                                    </span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="queueDetail"
                        class="mt-6 flex flex-wrap justify-end gap-2"
                    >
                        <Button
                            v-if="queueDetail.conversation_id"
                            as="a"
                            :href="route('tenant.inbox')"
                            variant="outline-secondary"
                            class="mr-auto bg-white"
                        >
                            <Lucide
                                icon="MessagesSquare"
                                class="mr-2 h-4 w-4"
                            />
                            Conversación
                        </Button>
                        <Button
                            variant="outline-danger"
                            class="bg-white"
                            @click="rejectFromDetail"
                        >
                            <Lucide icon="X" class="mr-2 h-4 w-4" /> Rechazar
                        </Button>
                        <Button variant="primary" @click="approveFromDetail">
                            <Lucide icon="Check" class="mr-2 h-4 w-4" /> Aprobar
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
