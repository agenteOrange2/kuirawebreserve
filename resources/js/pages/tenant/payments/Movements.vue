<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';
import PaymentsNav from './PaymentsNav.vue';

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

defineProps<{
    recentPayments: PaymentsPage;
    canManage: boolean;
    canCashCuts: boolean;
    canSettings: boolean;
}>();

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
    <RazeLayout title="Movimientos">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ArrowLeftRight" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Movimientos</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Todo el dinero que se registró, por cualquier vía.
                            Búscalo por folio, referencia o huésped.
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
                current="movements"
                :can-manage="canManage"
                :can-cash-cuts="canCashCuts"
            />

            <!-- Últimos pagos -->
            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10"
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
                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="relative w-full sm:w-64">
                        <Lucide
                            icon="Search"
                            class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400"
                        />
                        <FormInput
                            v-model="paymentsQ"
                            type="text"
                            class="h-9 pl-9 text-xs"
                            placeholder="Folio, referencia o huésped"
                        />
                    </div>
                    <FormSelect
                        v-model="paymentsMethod"
                        class="h-9 w-full text-xs sm:w-44"
                    >
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
                        class="px-4 py-6 text-center text-xs text-slate-500"
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
                            class="h-8 rounded-[0.5rem] bg-white text-xs"
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
                            class="h-8 rounded-[0.5rem] bg-white text-xs"
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
