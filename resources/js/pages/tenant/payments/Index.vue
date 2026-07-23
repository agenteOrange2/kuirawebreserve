<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput } from '@/components/Base/Form';
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

interface RecentPayment {
    id: number;
    subject: string;
    amount_label: string;
    method_label: string;
    paid_label: string;
    received_by: string;
}

const props = defineProps<{
    queue: PaymentQueueItem[];
    overdueBalances: OverdueBalance[];
    pendingLinks: PendingLink[];
    recentPayments: RecentPayment[];
    canManage: boolean;
}>();

const toast = useToasts();

// La cola se refresca sola: los comprobantes llegan a cualquier hora.
let poller: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    poller = setInterval(() => {
        router.reload({ only: ['queue', 'overdueBalances', 'pendingLinks', 'recentPayments'] });
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

async function approvePayment() {
    if (!verifying.value || paymentBusy.value) return;
    paymentBusy.value = true;
    try {
        const { data } = await axios.post(`/api/payment-requests/${verifying.value.id}/approve`, {
            reference: verifyReference.value.trim() || null,
        });
        toast.success(
            'Pago verificado',
            data.requires_attention
                ? 'El pago quedó registrado pero la reserva requiere atención (revisa disponibilidad).'
                : 'Se registró el pago y se avisó al huésped.',
        );
        verifying.value = null;
        verifyReference.value = '';
        router.reload();
    } catch (e: any) {
        toast.error('No se pudo aprobar', e.response?.data?.message ?? 'Ocurrió un error.');
    } finally {
        paymentBusy.value = false;
    }
}

async function rejectPayment() {
    if (!rejecting.value || paymentBusy.value || !rejectReason.value.trim()) return;
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
        toast.error('No se pudo rechazar', e.response?.data?.message ?? 'Ocurrió un error.');
    } finally {
        paymentBusy.value = false;
    }
}

async function copyLink(link: PendingLink) {
    if (!link.checkout_url) return;
    try {
        await navigator.clipboard.writeText(link.checkout_url);
        toast.success('Link copiado', `${link.amount_label} — compártelo con el huésped.`);
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
        toast.error('Error', e.response?.data?.message ?? 'No se pudo cancelar el cobro.');
    }
}
</script>

<template>
    <RazeLayout title="Pagos">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Pagos</h1>
                    <p class="mt-0.5 text-sm text-slate-500">
                        Todo el dinero en un lugar: transferencias por verificar, saldos vencidos, links de pago vivos y los últimos pagos.
                    </p>
                </div>
                <Button as="a" :href="route('tenant.online-payments')" variant="outline-primary" class="rounded-[0.5rem] bg-white">
                    <Lucide icon="ChartColumn" class="mr-2 h-4 w-4 stroke-[1.3]" /> Reporte de conciliación
                </Button>
            </div>

            <!-- Pagos por verificar (transferencias reportadas) -->
            <div v-if="canManage" class="mt-5 box box--stacked">
                <div class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-3.5 dark:border-darkmode-400">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full border border-pending/10 bg-pending/10">
                        <Lucide icon="Landmark" class="h-4 w-4 text-pending" />
                    </div>
                    <div>
                        <div class="text-sm font-medium">Pagos por verificar</div>
                        <p class="text-xs text-slate-500">Transferencias reportadas por huéspedes; al aprobar se registra el pago y se avisa por su canal.</p>
                    </div>
                    <span v-if="queue.length" class="ml-auto rounded-full bg-pending/10 px-2 py-0.5 text-xs font-medium text-pending">{{ queue.length }}</span>
                </div>
                <div v-if="queue.length" class="divide-y divide-dashed divide-slate-300/70">
                    <div v-for="item in queue" :key="item.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate text-sm font-medium">{{ item.guest_name }}</span>
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400">{{ item.reservation_code }}</span>
                                <span class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">{{ item.concept }} · {{ item.amount_label }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Solicitado por {{ item.requested_by }} {{ item.requested_at }}<template v-if="item.expires_at"> · vence {{ item.expires_at }}</template>
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
                                <Lucide icon="MessagesSquare" class="h-3.5 w-3.5" />
                            </Button>
                            <Button variant="primary" size="sm" class="rounded-[0.5rem]" @click="verifying = item">
                                <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" /> Aprobar
                            </Button>
                            <Button variant="outline-danger" size="sm" class="rounded-[0.5rem] bg-white" @click="rejecting = item">
                                <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" /> Rechazar
                            </Button>
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-6 text-center text-xs text-slate-500">Sin transferencias pendientes de verificar.</div>
            </div>

            <!-- Saldos vencidos -->
            <div v-if="canManage && overdueBalances.length" class="mt-5 box box--stacked">
                <div class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-3.5 dark:border-darkmode-400">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full border border-warning/10 bg-warning/10">
                        <Lucide icon="TriangleAlert" class="h-4 w-4 text-warning" />
                    </div>
                    <div>
                        <div class="text-sm font-medium">Saldos vencidos</div>
                        <p class="text-xs text-slate-500">Reservas confirmadas cuya fecha límite de pago ya pasó; decide si contactar, extender o cancelar.</p>
                    </div>
                    <span class="ml-auto rounded-full bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning">{{ overdueBalances.length }}</span>
                </div>
                <div class="divide-y divide-dashed divide-slate-300/70">
                    <div v-for="item in overdueBalances" :key="item.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate text-sm font-medium">{{ item.guest_name }}</span>
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400">{{ item.code }}</span>
                                <span class="rounded-full bg-warning/10 px-2 py-0.5 text-[10px] font-medium text-warning">Debe {{ item.pending_label }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">Venció {{ item.due_label }} · llega el {{ item.starts_label }}</p>
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
                                <Lucide icon="MessagesSquare" class="mr-1.5 h-3.5 w-3.5" /> Conversación
                            </Button>
                            <Button
                                as="a"
                                :href="route('tenant.reservations')"
                                variant="outline-secondary"
                                size="sm"
                                class="rounded-[0.5rem] bg-white"
                                title="Gestionar la reserva en el módulo de reservas"
                            >
                                <Lucide icon="CalendarDays" class="mr-1.5 h-3.5 w-3.5" /> Reserva
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Links de pago vivos -->
            <div class="mt-5 box box--stacked">
                <div class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-3.5 dark:border-darkmode-400">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                        <Lucide icon="Link" class="h-4 w-4 text-primary" />
                    </div>
                    <div>
                        <div class="text-sm font-medium">Links de pago vivos</div>
                        <p class="text-xs text-slate-500">Cobros de pasarela emitidos y aún sin pagar — cópialos para compartir o cancélalos si ya no aplican.</p>
                    </div>
                    <span v-if="pendingLinks.length" class="ml-auto rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">{{ pendingLinks.length }}</span>
                </div>
                <div v-if="pendingLinks.length" class="divide-y divide-dashed divide-slate-300/70">
                    <div v-for="link in pendingLinks" :key="link.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium">{{ link.subject }}</span>
                                <span class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">{{ link.concept }} · {{ link.amount_label }}</span>
                                <span v-if="link.provider" class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] capitalize text-slate-500 dark:bg-darkmode-400">{{ link.provider }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Emitido {{ link.created_label }}<template v-if="link.expires_label"> · vence {{ link.expires_label }}</template>
                            </p>
                        </div>
                        <div v-if="canManage" class="flex shrink-0 items-center gap-1.5">
                            <Button v-if="link.checkout_url" variant="outline-secondary" size="sm" class="rounded-[0.5rem] bg-white" @click="copyLink(link)">
                                <Lucide icon="Copy" class="mr-1.5 h-3.5 w-3.5" /> Copiar link
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
                <div v-else class="px-5 py-6 text-center text-xs text-slate-500">Sin links de pago vivos.</div>
            </div>

            <!-- Últimos pagos -->
            <div class="mt-5 box box--stacked">
                <div class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-3.5 dark:border-darkmode-400">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full border border-success/10 bg-success/10">
                        <Lucide icon="CircleCheck" class="h-4 w-4 text-success" />
                    </div>
                    <div>
                        <div class="text-sm font-medium">Últimos pagos registrados</div>
                        <p class="text-xs text-slate-500">Lo más reciente que entró, por cualquier vía. La conciliación completa vive en el reporte.</p>
                    </div>
                </div>
                <div class="overflow-auto lg:overflow-visible">
                    <Table v-if="recentPayments.length">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th class="whitespace-nowrap">Folio</Table.Th>
                                <Table.Th class="whitespace-nowrap">Monto</Table.Th>
                                <Table.Th class="whitespace-nowrap">Método</Table.Th>
                                <Table.Th class="whitespace-nowrap">Fecha</Table.Th>
                                <Table.Th class="whitespace-nowrap">Registró</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="payment in recentPayments" :key="payment.id">
                                <Table.Td class="font-medium">{{ payment.subject }}</Table.Td>
                                <Table.Td>{{ payment.amount_label }}</Table.Td>
                                <Table.Td>{{ payment.method_label }}</Table.Td>
                                <Table.Td class="text-slate-500">{{ payment.paid_label }}</Table.Td>
                                <Table.Td class="text-slate-500">{{ payment.received_by }}</Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div v-else class="px-5 py-6 text-center text-xs text-slate-500">Aún no hay pagos registrados.</div>
                </div>
            </div>
        </div>

        <!-- Modal aprobar pago -->
        <Dialog :open="verifying !== null" @close="verifying = null">
            <Dialog.Panel>
                <div v-if="verifying" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-success/10 text-success">
                            <Lucide icon="Landmark" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">Aprobar pago de {{ verifying.guest_name }}</h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                {{ verifying.concept }} de {{ verifying.amount_label }} · reserva {{ verifying.reservation_code }}. Confirma que la transferencia ya está en la cuenta del hotel.
                            </p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="mb-1 block text-sm">Referencia del banco (opcional)</label>
                        <FormInput v-model="verifyReference" type="text" placeholder="Clave de rastreo / folio SPEI" />
                    </div>
                    <div class="mt-4 flex items-center gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700">
                        <Lucide icon="Info" class="h-4 w-4 shrink-0" /> Se registra el pago, la reserva se confirma si cubre el anticipo y se avisa al huésped por su canal.
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button variant="outline-secondary" @click="verifying = null">Cancelar</Button>
                        <Button variant="primary" :disabled="paymentBusy" @click="approvePayment">
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
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger">
                            <Lucide icon="X" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">Rechazar pago de {{ rejecting.guest_name }}</h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                {{ rejecting.concept }} de {{ rejecting.amount_label }} · reserva {{ rejecting.reservation_code }}. El motivo se envía al huésped por su canal.
                            </p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="mb-1 block text-sm">Motivo</label>
                        <FormInput v-model="rejectReason" type="text" placeholder="No se localizó el depósito / monto distinto…" />
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button variant="outline-secondary" @click="rejecting = null">Cancelar</Button>
                        <Button variant="danger" :disabled="paymentBusy || !rejectReason.trim()" @click="rejectPayment">
                            <Lucide icon="X" class="mr-2 h-4 w-4" />
                            {{ paymentBusy ? 'Enviando…' : 'Rechazar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
