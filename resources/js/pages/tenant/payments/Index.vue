<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface PaymentQueueItem {
    id: number;
    reservation_id: number;
    reservation_code: string | null;
    guest_name: string;
    concept: string;
    amount_label: string;
    requested_at: string;
    expires_at: string | null;
    requested_by: string;
    conversation_id: number | null;
}

interface OverdueBalance {
    id: number;
    code: string;
    guest_name: string;
    pending_label: string;
    due_label: string;
    starts_label: string;
    conversation_id: number | null;
}

interface PendingLink {
    id: number;
    subject: string;
    concept: string;
    amount_label: string;
    provider: string | null;
    checkout_url: string | null;
    expires_label: string | null;
    created_label: string;
}

interface ReceiptInfo {
    url: string;
    name: string;
    is_image: boolean;
}

interface RecentPayment {
    id: number;
    subject: string;
    guest_name: string | null;
    amount_label: string;
    fee_label: string | null;
    method_label: string;
    kind_label: string;
    concept: string | null;
    reference: string | null;
    gateway_ref: string | null;
    notes: string | null;
    paid_label: string;
    received_by: string;
    status: 'registered' | 'refunded';
    status_label: string;
    refunded_label: string | null;
    receipt: ReceiptInfo | null;
}

interface PaymentsPage {
    data: RecentPayment[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
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
    bank_accounts: { bank: string; holder: string; clabe: string }[];
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
    overdueBalances: OverdueBalance[];
    pendingLinks: PendingLink[];
    recentPayments: PaymentsPage;
    canManage: boolean;
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

async function approvePayment() {
    if (!verifying.value || paymentBusy.value) return;
    paymentBusy.value = true;
    try {
        const form = new FormData();
        if (verifyReference.value.trim())
            form.append('reference', verifyReference.value.trim());
        if (receiptFile.value) form.append('receipt', receiptFile.value);
        const { data } = await axios.post(
            `/api/payment-requests/${verifying.value.id}/approve`,
            form,
        );
        toast.success(
            'Pago verificado',
            data.requires_attention
                ? 'El pago quedó registrado pero la reserva requiere atención (revisa disponibilidad).'
                : 'Se registró el pago y se avisó al huésped.',
        );
        verifying.value = null;
        verifyReference.value = '';
        clearReceipt();
        router.reload();
    } catch (e: any) {
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

async function copyLink(link: PendingLink) {
    if (!link.checkout_url) return;
    try {
        await navigator.clipboard.writeText(link.checkout_url);
        toast.success(
            'Link copiado',
            `${link.amount_label} — compártelo con el huésped.`,
        );
    } catch {
        toast.error('No se pudo copiar', link.checkout_url);
    }
}

async function cancelLink(link: PendingLink) {
    try {
        await axios.delete(`/api/payment-requests/${link.id}`);
        toast.success('Cobro cancelado', 'El link deja de aceptar pagos.');
        router.reload({ only: ['pendingLinks'] });
    } catch (e: any) {
        toast.error(
            'Error',
            e.response?.data?.message ?? 'No se pudo cancelar el cobro.',
        );
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

// ── Últimos pagos: filtros, paginador y detalle ──
const initialParams = new URLSearchParams(window.location.search);
const paymentsQ = ref(initialParams.get('q') ?? '');
const paymentsMethod = ref(initialParams.get('method') ?? '');
const paymentDetail = ref<RecentPayment | null>(null);

function fetchPayments(page = 1) {
    router.get(
        window.location.pathname,
        {
            ...(page > 1 ? { payments_page: page } : {}),
            ...(paymentsQ.value.trim() ? { q: paymentsQ.value.trim() } : {}),
            ...(paymentsMethod.value ? { method: paymentsMethod.value } : {}),
        },
        {
            only: ['recentPayments'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

// Búsqueda reactiva con respiro para no disparar en cada tecla.
let searchTimer: ReturnType<typeof setTimeout> | null = null;
watch(paymentsQ, () => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => fetchPayments(1), 350);
});
watch(paymentsMethod, () => fetchPayments(1));
</script>

<template>
    <RazeLayout title="Pagos">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary sm:h-14 sm:w-14"
                    >
                        <Lucide icon="Wallet" class="h-5 w-5 sm:h-7 sm:w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-lg font-medium sm:text-xl">Pagos</h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Todo el dinero en un lugar: transferencias por
                            verificar, saldos vencidos, links de pago vivos y
                            los últimos pagos.
                        </p>
                    </div>
                </div>
                <Button
                    as="a"
                    :href="route('tenant.online-payments')"
                    variant="outline-primary"
                    class="w-full rounded-[0.5rem] bg-white md:w-auto"
                >
                    <Lucide
                        icon="ChartColumn"
                        class="mr-2 h-4 w-4 stroke-[1.3]"
                    />
                    Reporte de conciliación
                </Button>
            </div>

            <!-- Pagos por verificar (transferencias reportadas) -->
            <div v-if="canManage" class="box box--stacked mt-5">
                <div
                    class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-8 w-8 items-center justify-center rounded-full border border-pending/10 bg-pending/10"
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
                    class="divide-y divide-dashed divide-slate-300/70"
                >
                    <div
                        v-for="item in queue"
                        :key="item.id"
                        class="flex flex-wrap items-center gap-3 px-5 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate text-sm font-medium">{{
                                    item.guest_name
                                }}</span>
                                <span
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400"
                                    >{{ item.reservation_code }}</span
                                >
                                <span
                                    class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary"
                                    >{{ item.concept }} ·
                                    {{ item.amount_label }}</span
                                >
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
                                :href="route('tenant.inbox')"
                                variant="outline-secondary"
                                size="sm"
                                class="rounded-[0.5rem] bg-white"
                                title="El comprobante llegó por conversación: revísalo en la Bandeja"
                            >
                                <Lucide
                                    icon="MessagesSquare"
                                    class="h-3.5 w-3.5"
                                />
                            </Button>
                            <Button
                                variant="outline-secondary"
                                size="sm"
                                class="rounded-[0.5rem] bg-white"
                                title="Ver todos los detalles de la solicitud"
                                @click="openQueueDetail(item)"
                            >
                                <Lucide icon="Eye" class="mr-1.5 h-3.5 w-3.5" />
                                Detalles
                            </Button>
                            <Button
                                variant="primary"
                                size="sm"
                                class="rounded-[0.5rem]"
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
                                size="sm"
                                class="rounded-[0.5rem] bg-white"
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
                    class="px-5 py-6 text-center text-xs text-slate-500"
                >
                    Sin transferencias pendientes de verificar.
                </div>

                <!-- Rechazadas/vencidas recientes: reemitir cuando el
                     huésped corrige — antes desaparecían sin regreso. -->
                <div
                    v-if="closedRequests.length"
                    class="border-t border-dashed border-slate-300/70 dark:border-darkmode-400"
                >
                    <div
                        class="px-5 pt-3 pb-1 text-xs font-medium tracking-wide text-slate-400 uppercase"
                    >
                        Rechazadas y vencidas recientes
                    </div>
                    <div class="divide-y divide-dashed divide-slate-300/70">
                        <div
                            v-for="item in closedRequests"
                            :key="item.id"
                            class="flex flex-wrap items-center gap-3 px-5 py-3"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        class="truncate text-sm text-slate-500"
                                        >{{ item.guest_name }}</span
                                    >
                                    <span
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400"
                                        >{{ item.reservation_code }}</span
                                    >
                                    <span
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400"
                                        >{{ item.concept }} ·
                                        {{ item.amount_label }}</span
                                    >
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[10px] font-medium"
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
                                size="sm"
                                class="shrink-0 rounded-[0.5rem] bg-white"
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

            <!-- Saldos vencidos -->
            <div
                v-if="canManage && overdueBalances.length"
                class="box box--stacked mt-5"
            >
                <div
                    class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-8 w-8 items-center justify-center rounded-full border border-warning/10 bg-warning/10"
                    >
                        <Lucide
                            icon="TriangleAlert"
                            class="h-4 w-4 text-warning"
                        />
                    </div>
                    <div>
                        <div class="text-sm font-medium">Saldos vencidos</div>
                        <p class="text-xs text-slate-500">
                            Reservas confirmadas cuya fecha límite de pago ya
                            pasó; decide si contactar, extender o cancelar.
                        </p>
                    </div>
                    <span
                        class="ml-auto rounded-full bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning"
                        >{{ overdueBalances.length }}</span
                    >
                </div>
                <div class="divide-y divide-dashed divide-slate-300/70">
                    <div
                        v-for="item in overdueBalances"
                        :key="item.id"
                        class="flex flex-wrap items-center gap-3 px-5 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate text-sm font-medium">{{
                                    item.guest_name
                                }}</span>
                                <span
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400"
                                    >{{ item.code }}</span
                                >
                                <span
                                    class="rounded-full bg-warning/10 px-2 py-0.5 text-[10px] font-medium text-warning"
                                    >Debe {{ item.pending_label }}</span
                                >
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Venció {{ item.due_label }} · llega el
                                {{ item.starts_label }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5">
                            <Button
                                v-if="item.conversation_id"
                                as="a"
                                :href="route('tenant.inbox')"
                                variant="outline-secondary"
                                size="sm"
                                class="rounded-[0.5rem] bg-white"
                                title="Abrir la Bandeja para dar seguimiento"
                            >
                                <Lucide
                                    icon="MessagesSquare"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Conversación
                            </Button>
                            <Button
                                as="a"
                                :href="route('tenant.reservations')"
                                variant="outline-secondary"
                                size="sm"
                                class="rounded-[0.5rem] bg-white"
                                title="Gestionar la reserva en el módulo de reservas"
                            >
                                <Lucide
                                    icon="CalendarDays"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Reserva
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Links de pago vivos -->
            <div class="box box--stacked mt-5">
                <div
                    class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-8 w-8 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                    >
                        <Lucide icon="Link" class="h-4 w-4 text-primary" />
                    </div>
                    <div>
                        <div class="text-sm font-medium">
                            Links de pago vivos
                        </div>
                        <p class="text-xs text-slate-500">
                            Cobros de pasarela emitidos y aún sin pagar —
                            cópialos para compartir o cancélalos si ya no
                            aplican.
                        </p>
                    </div>
                    <span
                        v-if="pendingLinks.length"
                        class="ml-auto rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                        >{{ pendingLinks.length }}</span
                    >
                </div>
                <div
                    v-if="pendingLinks.length"
                    class="divide-y divide-dashed divide-slate-300/70"
                >
                    <div
                        v-for="link in pendingLinks"
                        :key="link.id"
                        class="flex flex-wrap items-center gap-3 px-5 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium">{{
                                    link.subject
                                }}</span>
                                <span
                                    class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary"
                                    >{{ link.concept }} ·
                                    {{ link.amount_label }}</span
                                >
                                <span
                                    v-if="link.provider"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 capitalize dark:bg-darkmode-400"
                                    >{{ link.provider }}</span
                                >
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Emitido {{ link.created_label
                                }}<template v-if="link.expires_label">
                                    · vence {{ link.expires_label }}</template
                                >
                            </p>
                        </div>
                        <div
                            v-if="canManage"
                            class="flex shrink-0 items-center gap-1.5"
                        >
                            <Button
                                v-if="link.checkout_url"
                                variant="outline-secondary"
                                size="sm"
                                class="rounded-[0.5rem] bg-white"
                                @click="copyLink(link)"
                            >
                                <Lucide
                                    icon="Copy"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Copiar link
                            </Button>
                            <button
                                type="button"
                                class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                title="Cancelar el cobro (el link deja de aceptar pagos)"
                                @click="cancelLink(link)"
                            >
                                <Lucide icon="Ban" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
                <div
                    v-else
                    class="px-5 py-6 text-center text-xs text-slate-500"
                >
                    Sin links de pago vivos.
                </div>
            </div>

            <!-- Últimos pagos -->
            <div class="box box--stacked mt-5">
                <div
                    class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-8 w-8 items-center justify-center rounded-full border border-success/10 bg-success/10"
                    >
                        <Lucide
                            icon="CircleCheck"
                            class="h-4 w-4 text-success"
                        />
                    </div>
                    <div>
                        <div class="text-sm font-medium">
                            Últimos pagos registrados
                        </div>
                        <p class="text-xs text-slate-500">
                            Lo más reciente que entró, por cualquier vía. La
                            conciliación completa vive en el reporte.
                        </p>
                    </div>
                </div>
                <!-- Filtros: folio/referencia/huésped + método -->
                <div
                    class="flex flex-wrap items-center gap-3 border-b border-dashed border-slate-300/70 px-5 py-3 dark:border-darkmode-400"
                >
                    <div class="relative w-full sm:w-64">
                        <Lucide
                            icon="Search"
                            class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400"
                        />
                        <FormInput
                            v-model="paymentsQ"
                            type="text"
                            class="pl-9"
                            placeholder="Folio, referencia o huésped…"
                        />
                    </div>
                    <FormSelect v-model="paymentsMethod" class="w-full sm:w-44">
                        <option value="">Todos los métodos</option>
                        <option value="transfer">Transferencia</option>
                        <option value="online">En línea</option>
                        <option value="cash">Efectivo</option>
                        <option value="card">Tarjeta</option>
                    </FormSelect>
                    <span
                        v-if="recentPayments.total"
                        class="ml-auto text-xs text-slate-500"
                    >
                        {{ recentPayments.total }} pago{{
                            recentPayments.total === 1 ? '' : 's'
                        }}
                    </span>
                </div>
                <div class="overflow-auto lg:overflow-visible">
                    <Table v-if="recentPayments.data.length">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th class="whitespace-nowrap"
                                    >Folio</Table.Th
                                >
                                <Table.Th class="whitespace-nowrap"
                                    >Huésped</Table.Th
                                >
                                <Table.Th class="whitespace-nowrap"
                                    >Monto</Table.Th
                                >
                                <Table.Th class="whitespace-nowrap"
                                    >Método</Table.Th
                                >
                                <Table.Th class="whitespace-nowrap"
                                    >Estatus</Table.Th
                                >
                                <Table.Th class="whitespace-nowrap"
                                    >Fecha</Table.Th
                                >
                                <Table.Th class="whitespace-nowrap"
                                    >Registró</Table.Th
                                >
                                <Table.Th class="w-10"></Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr
                                v-for="payment in recentPayments.data"
                                :key="payment.id"
                                class="cursor-pointer"
                                @click="paymentDetail = payment"
                            >
                                <Table.Td class="font-medium">{{
                                    payment.subject
                                }}</Table.Td>
                                <Table.Td class="text-slate-500">{{
                                    payment.guest_name ?? '—'
                                }}</Table.Td>
                                <Table.Td>{{ payment.amount_label }}</Table.Td>
                                <Table.Td>{{ payment.method_label }}</Table.Td>
                                <Table.Td>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            payment.status === 'refunded'
                                                ? 'bg-warning/10 text-warning'
                                                : 'bg-success/10 text-success'
                                        "
                                        :title="
                                            payment.refunded_label
                                                ? `Reembolsado ${payment.refunded_label}`
                                                : undefined
                                        "
                                        >{{ payment.status_label }}</span
                                    >
                                </Table.Td>
                                <Table.Td class="text-slate-500">{{
                                    payment.paid_label
                                }}</Table.Td>
                                <Table.Td class="text-slate-500">{{
                                    payment.received_by
                                }}</Table.Td>
                                <Table.Td>
                                    <button
                                        type="button"
                                        class="rounded p-1.5 text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                                        title="Ver detalles del pago"
                                        @click.stop="paymentDetail = payment"
                                    >
                                        <Lucide icon="Eye" class="h-4 w-4" />
                                    </button>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div
                        v-else
                        class="px-5 py-6 text-center text-xs text-slate-500"
                    >
                        {{
                            paymentsQ || paymentsMethod
                                ? 'Sin pagos que coincidan con el filtro.'
                                : 'Aún no hay pagos registrados.'
                        }}
                    </div>
                </div>
                <!-- Paginador -->
                <div
                    v-if="recentPayments.last_page > 1"
                    class="flex flex-wrap items-center justify-between gap-3 border-t border-dashed border-slate-300/70 px-5 py-3 dark:border-darkmode-400"
                >
                    <span class="text-xs text-slate-500">
                        Mostrando {{ recentPayments.from ?? 0 }}–{{
                            recentPayments.to ?? 0
                        }}
                        de {{ recentPayments.total }}
                    </span>
                    <div class="flex items-center gap-1.5">
                        <Button
                            variant="outline-secondary"
                            size="sm"
                            class="rounded-[0.5rem] bg-white"
                            :disabled="recentPayments.current_page <= 1"
                            @click="
                                fetchPayments(recentPayments.current_page - 1)
                            "
                        >
                            <Lucide icon="ChevronLeft" class="h-4 w-4" />
                            Anterior
                        </Button>
                        <span class="px-2 text-xs text-slate-500">
                            {{ recentPayments.current_page }} /
                            {{ recentPayments.last_page }}
                        </span>
                        <Button
                            variant="outline-secondary"
                            size="sm"
                            class="rounded-[0.5rem] bg-white"
                            :disabled="
                                recentPayments.current_page >=
                                recentPayments.last_page
                            "
                            @click="
                                fetchPayments(recentPayments.current_page + 1)
                            "
                        >
                            Siguiente
                            <Lucide icon="ChevronRight" class="h-4 w-4" />
                        </Button>
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
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="verifying = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="primary"
                            :disabled="paymentBusy"
                            @click="approvePayment"
                        >
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{ paymentBusy ? 'Registrando…' : 'Aprobar pago' }}
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
                            <div class="space-y-1.5 px-4 py-3 text-sm">
                                <div
                                    v-for="account in queueDetail.bank_accounts"
                                    :key="account.clabe"
                                    class="flex justify-between gap-3"
                                >
                                    <span class="text-slate-500"
                                        >{{ account.bank }} ·
                                        {{ account.holder }}</span
                                    >
                                    <span class="font-mono text-xs">{{
                                        account.clabe
                                    }}</span>
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

        <!-- Modal detalle de un pago registrado -->
        <Dialog :open="paymentDetail !== null" @close="paymentDetail = null">
            <Dialog.Panel>
                <div v-if="paymentDetail" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                        >
                            <Lucide icon="ReceiptText" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-base font-medium">
                                Pago de {{ paymentDetail.amount_label }}
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                {{ paymentDetail.subject
                                }}<template v-if="paymentDetail.guest_name">
                                    · {{ paymentDetail.guest_name }}</template
                                >
                            </p>
                        </div>
                        <span
                            class="ml-auto shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                paymentDetail.status === 'refunded'
                                    ? 'bg-warning/10 text-warning'
                                    : 'bg-success/10 text-success'
                            "
                            >{{ paymentDetail.status_label }}</span
                        >
                    </div>

                    <div
                        class="mt-4 max-h-[60vh] space-y-1.5 overflow-y-auto rounded-lg border border-slate-200/70 px-4 py-3 text-sm dark:border-darkmode-400"
                    >
                        <div class="flex justify-between gap-3">
                            <span class="text-slate-500">Método</span
                            ><span>{{ paymentDetail.method_label }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span class="text-slate-500">Tipo</span
                            ><span
                                >{{ paymentDetail.kind_label
                                }}<template v-if="paymentDetail.concept">
                                    · {{ paymentDetail.concept }}</template
                                ></span
                            >
                        </div>
                        <div
                            v-if="paymentDetail.fee_label"
                            class="flex justify-between gap-3"
                        >
                            <span class="text-slate-500"
                                >Comisión de pasarela</span
                            ><span>{{ paymentDetail.fee_label }}</span>
                        </div>
                        <div
                            v-if="paymentDetail.refunded_label"
                            class="flex justify-between gap-3"
                        >
                            <span class="text-slate-500">Reembolsado</span
                            ><span class="text-warning">{{
                                paymentDetail.refunded_label
                            }}</span>
                        </div>
                        <div
                            v-if="paymentDetail.reference"
                            class="flex justify-between gap-3"
                        >
                            <span class="text-slate-500">Referencia</span
                            ><span class="font-mono text-xs">{{
                                paymentDetail.reference
                            }}</span>
                        </div>
                        <div
                            v-if="paymentDetail.gateway_ref"
                            class="flex justify-between gap-3"
                        >
                            <span class="text-slate-500">Ref. de pasarela</span
                            ><span
                                class="max-w-[55%] truncate font-mono text-xs"
                                :title="paymentDetail.gateway_ref"
                                >{{ paymentDetail.gateway_ref }}</span
                            >
                        </div>
                        <div class="flex justify-between gap-3">
                            <span class="text-slate-500">Fecha</span
                            ><span>{{ paymentDetail.paid_label }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span class="text-slate-500">Registró</span
                            ><span>{{ paymentDetail.received_by }}</span>
                        </div>
                        <div
                            v-if="paymentDetail.notes"
                            class="flex justify-between gap-3"
                        >
                            <span class="text-slate-500">Notas</span
                            ><span class="max-w-[60%] text-right">{{
                                paymentDetail.notes
                            }}</span>
                        </div>
                    </div>

                    <div v-if="paymentDetail.receipt" class="mt-4">
                        <p
                            class="mb-1.5 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            Comprobante
                        </p>
                        <a :href="paymentDetail.receipt.url" target="_blank">
                            <img
                                v-if="paymentDetail.receipt.is_image"
                                :src="paymentDetail.receipt.url"
                                alt="Comprobante"
                                class="max-h-56 rounded-lg border border-slate-200/70 object-contain dark:border-darkmode-400"
                            />
                            <span
                                v-else
                                class="inline-flex items-center gap-2 text-sm text-primary underline"
                            >
                                <Lucide icon="FileText" class="h-4 w-4" />
                                {{ paymentDetail.receipt.name }}
                            </span>
                        </a>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <Button
                            variant="outline-secondary"
                            class="bg-white"
                            @click="paymentDetail = null"
                            >Cerrar</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
