<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface Source { key: string; label: string; count: number; total: number }
interface Method { key: string; label: string; total: number }
interface Preview {
    orders_count: number; orders_total: number; orders_cost: number; orders_profit: number; orders_room: number;
    payments_count: number; payments_total: number;
    cash_total: number; card_total: number; transfer_total: number; grand_total: number; expected_cash: number;
    sources: Source[]; methods: Method[];
}
interface Cut {
    id: number; user: string; opened_at: string; closed_at: string; orders_count: number; payments_count: number;
    grand_total: number; cash_total: number; card_total: number; transfer_total: number; expected_cash: number;
    counted_cash: number | null; difference: number; notes: string | null; by: string | null;
}

const props = defineProps<{
    property: { id: number; name: string };
    staff: { id: number; name: string }[];
    filters: { user: number | null; from: string; to: string };
    selectedUser: { id: number; name: string } | null;
    period: { from: string; to: string };
    preview: Preview;
    cuts: Cut[];
    canManage: boolean;
}>();

const toast = useToasts();
const money = (n: number) => '$' + new Intl.NumberFormat('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0);

const userId = ref<string | number>(props.filters.user ?? '');
const from = ref(props.filters.from);
const to = ref(props.filters.to);

function applyFilters() {
    router.get(route('tenant.cashcuts'), {
        user: userId.value || undefined,
        from: from.value || undefined,
        to: to.value || undefined,
    }, { preserveScroll: true });
}

const methodIcons: Record<string, Icon> = { cash: 'Banknote', card: 'CreditCard', transfer: 'ArrowLeftRight' };

// ── Guardar corte (arqueo) ──
const showClose = ref(false);
const countedCash = ref<string | number>('');
const notes = ref('');
const saving = ref(false);

const difference = computed(() => {
    if (countedCash.value === '' || countedCash.value === null) return null;
    return Math.round((Number(countedCash.value) - props.preview.expected_cash) * 100) / 100;
});

async function submitCut() {
    saving.value = true;
    try {
        await axios.post(route('tenant.cashcuts.store'), {
            user_id: props.selectedUser?.id,
            from: props.filters.from,
            to: props.filters.to,
            counted_cash: countedCash.value === '' ? null : countedCash.value,
            notes: notes.value || null,
        });
        showClose.value = false;
        countedCash.value = '';
        notes.value = '';
        toast.success('Corte guardado', 'El periodo quedó contabilizado y cerrado.');
        router.reload();
    } catch (e: any) {
        toast.error('No se pudo guardar', e.response?.data?.message ?? 'Ocurrió un error.');
    } finally {
        saving.value = false;
    }
}

const detailCut = ref<Cut | null>(null);
</script>

<template>
    <RazeLayout title="Cortes de venta">
        <div class="grid grid-cols-12 gap-y-8 gap-x-6">
            <!-- Encabezado -->
            <div class="col-span-12">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-lg font-medium">Cortes de venta</h1>
                        <p class="text-sm text-slate-500">{{ property.name }} · contabiliza lo cobrado por cada encargado</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button as="a" :href="route('tenant.shifts')" variant="outline-secondary" class="rounded-[0.5rem] bg-white">
                            <Lucide icon="Clock" class="mr-2 h-4 w-4 stroke-[1.3]" /> Turnos
                        </Button>
                        <Button v-if="canManage" variant="primary" class="rounded-[0.5rem] shadow-md shadow-primary/20" :disabled="preview.grand_total <= 0" @click="showClose = true">
                            <Lucide icon="ClipboardCheck" class="mr-2 h-4 w-4 stroke-[1.3]" /> Hacer corte
                        </Button>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="mt-5 box box--stacked flex flex-wrap items-end gap-3 p-3">
                    <div>
                        <label class="mb-1 block text-xs text-slate-500">Encargado</label>
                        <div class="relative">
                            <Lucide icon="User" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                            <FormSelect v-model="userId" class="w-52 pl-9">
                                <option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </FormSelect>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500">Desde</label>
                        <FormInput v-model="from" type="datetime-local" class="w-52" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500">Hasta</label>
                        <FormInput v-model="to" type="datetime-local" class="w-52" />
                    </div>
                    <Button variant="outline-primary" class="rounded-[0.5rem] bg-white" @click="applyFilters">
                        <Lucide icon="RefreshCw" class="mr-2 h-4 w-4" /> Calcular
                    </Button>
                    <div class="ml-auto flex items-center gap-2 rounded-[0.5rem] border border-dashed border-slate-300/70 px-3 py-2 text-xs text-slate-500 dark:border-darkmode-400">
                        <Lucide icon="CalendarRange" class="h-4 w-4 text-primary" />
                        {{ period.from }} → {{ period.to }}
                    </div>
                </div>
            </div>

            <!-- Corte en curso -->
            <div class="col-span-12">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">Corte en curso · {{ selectedUser?.name }}</div>
                </div>

                <div class="mt-3.5 grid grid-cols-12 gap-5">
                    <!-- Total contabilizado -->
                    <div class="col-span-12 p-5 sm:col-span-6 2xl:col-span-3 box box--stacked">
                        <div class="flex items-center justify-between">
                            <div class="flex h-11 w-11 items-center justify-center rounded-full border border-primary/10 bg-primary/10"><Lucide icon="Wallet" class="h-5 w-5 text-primary" /></div>
                            <div class="text-2xl font-medium">{{ money(preview.grand_total) }}</div>
                        </div>
                        <div class="mt-4 text-sm font-medium">Total cobrado</div>
                        <div class="mt-1 text-xs text-slate-500">{{ preview.orders_count }} ventas · {{ preview.payments_count }} cobros</div>
                    </div>
                    <!-- Efectivo esperado -->
                    <div class="col-span-12 p-5 sm:col-span-6 2xl:col-span-3 box box--stacked">
                        <div class="flex items-center justify-between">
                            <div class="flex h-11 w-11 items-center justify-center rounded-full border border-success/10 bg-success/10"><Lucide icon="Banknote" class="h-5 w-5 text-success" /></div>
                            <div class="text-2xl font-medium text-success">{{ money(preview.expected_cash) }}</div>
                        </div>
                        <div class="mt-4 text-sm font-medium">Efectivo esperado</div>
                        <div class="mt-1 text-xs text-slate-500">Lo que debe haber en caja</div>
                    </div>
                    <!-- Utilidad POS -->
                    <div class="col-span-12 p-5 sm:col-span-6 2xl:col-span-3 box box--stacked">
                        <div class="flex items-center justify-between">
                            <div class="flex h-11 w-11 items-center justify-center rounded-full border border-info/10 bg-info/10"><Lucide icon="TrendingUp" class="h-5 w-5 text-info" /></div>
                            <div class="text-2xl font-medium">{{ money(preview.orders_profit) }}</div>
                        </div>
                        <div class="mt-4 text-sm font-medium">Utilidad POS</div>
                        <div class="mt-1 text-xs text-slate-500">Venta {{ money(preview.orders_total) }} − costo {{ money(preview.orders_cost) }}</div>
                    </div>
                    <!-- Cargado a habitación -->
                    <div class="col-span-12 p-5 sm:col-span-6 2xl:col-span-3 box box--stacked">
                        <div class="flex items-center justify-between">
                            <div class="flex h-11 w-11 items-center justify-center rounded-full border border-warning/10 bg-warning/10"><Lucide icon="BedDouble" class="h-5 w-5 text-warning" /></div>
                            <div class="text-2xl font-medium">{{ money(preview.orders_room) }}</div>
                        </div>
                        <div class="mt-4 text-sm font-medium">Cargado a habitación</div>
                        <div class="mt-1 text-xs text-slate-500">Se cobra en el check-out (no es efectivo)</div>
                    </div>
                </div>
            </div>

            <!-- Desglose por método + por origen -->
            <div class="col-span-12 flex flex-col xl:col-span-6">
                <div class="flex items-center md:h-10"><div class="text-base font-medium">Desglose por método de pago</div></div>
                <div class="mt-3.5 box box--stacked flex-1 p-5">
                    <div class="space-y-4">
                        <div v-for="m in preview.methods" :key="m.key" class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 dark:bg-darkmode-400"><Lucide :icon="methodIcons[m.key] ?? 'Circle'" class="h-4 w-4" /></div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="font-medium">{{ m.label }}</span>
                                    <span class="font-medium">{{ money(m.total) }}</span>
                                </div>
                                <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400">
                                    <div class="h-full rounded-full bg-primary/70" :style="{ width: `${preview.grand_total > 0 ? (m.total / preview.grand_total) * 100 : 0}%` }" />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 flex items-center justify-between border-t border-dashed border-slate-300/70 pt-4 text-sm dark:border-darkmode-400">
                        <span class="font-medium">Total</span>
                        <span class="text-base font-medium">{{ money(preview.grand_total) }}</span>
                    </div>
                </div>
            </div>

            <div class="col-span-12 flex flex-col xl:col-span-6">
                <div class="flex items-center md:h-10"><div class="text-base font-medium">Desglose por origen</div></div>
                <div class="mt-3.5 box box--stacked flex-1 p-5">
                    <div class="space-y-3">
                        <div v-for="s in preview.sources" :key="s.key" class="flex items-center justify-between rounded-lg border border-slate-200/70 p-3.5 dark:border-darkmode-400">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-full" :class="s.key === 'pos' ? 'bg-primary/10 text-primary' : 'bg-success/10 text-success'"><Lucide :icon="s.key === 'pos' ? 'ShoppingCart' : 'CalendarCheck'" class="h-5 w-5" /></div>
                                <div>
                                    <div class="text-sm font-medium">{{ s.label }}</div>
                                    <div class="text-xs text-slate-500">{{ s.count }} movimiento(s)</div>
                                </div>
                            </div>
                            <div class="text-base font-medium">{{ money(s.total) }}</div>
                        </div>
                    </div>
                    <p class="mt-4 flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:bg-darkmode-700">
                        <Lucide icon="Info" class="h-4 w-4 shrink-0" /> El corte cuenta lo que <span class="font-medium">{{ selectedUser?.name }}</span> cobró en el periodo. Al guardarlo, el siguiente corte arranca desde aquí.
                    </p>
                </div>
            </div>

            <!-- Historial de cortes -->
            <div class="col-span-12">
                <div class="flex items-center md:h-10"><div class="text-base font-medium">Cortes guardados</div></div>
                <div class="mt-3.5 box box--stacked overflow-auto p-5 lg:overflow-visible">
                    <Table v-if="cuts.length">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Encargado</Table.Th>
                                <Table.Th>Periodo</Table.Th>
                                <Table.Th class="text-right">Total</Table.Th>
                                <Table.Th class="text-right">Efectivo</Table.Th>
                                <Table.Th class="text-right">Diferencia</Table.Th>
                                <Table.Th class="text-right" />
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="c in cuts" :key="c.id">
                                <Table.Td class="font-medium">{{ c.user }}</Table.Td>
                                <Table.Td class="whitespace-nowrap text-sm text-slate-500">{{ c.closed_at }}</Table.Td>
                                <Table.Td class="text-right font-medium">{{ money(c.grand_total) }}</Table.Td>
                                <Table.Td class="text-right text-sm">
                                    <span class="text-slate-500">esp. {{ money(c.expected_cash) }}</span>
                                    <span v-if="c.counted_cash !== null"> · cont. {{ money(c.counted_cash) }}</span>
                                </Table.Td>
                                <Table.Td class="text-right">
                                    <span
                                        v-if="c.counted_cash !== null"
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="c.difference === 0 ? 'bg-success/10 text-success' : c.difference > 0 ? 'bg-info/10 text-info' : 'bg-danger/10 text-danger'"
                                    >
                                        {{ c.difference === 0 ? 'Cuadra' : c.difference > 0 ? `Sobra ${money(c.difference)}` : `Falta ${money(Math.abs(c.difference))}` }}
                                    </span>
                                    <span v-else class="text-xs text-slate-400">sin arqueo</span>
                                </Table.Td>
                                <Table.Td class="text-right">
                                    <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-primary dark:hover:bg-darkmode-400" @click="detailCut = c"><Lucide icon="Eye" class="h-4 w-4" /></button>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div v-else class="flex flex-col items-center gap-3 py-10 text-center text-slate-400">
                        <Lucide icon="ClipboardList" class="h-8 w-8" />
                        <p class="text-sm">Aún no hay cortes guardados.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal hacer corte (arqueo) -->
        <Dialog size="lg" :open="showClose" @close="showClose = false">
            <Dialog.Panel>
                <div class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"><Lucide icon="ClipboardCheck" class="h-5 w-5" /></div>
                        <div>
                            <h2 class="text-base font-medium">Cerrar corte de {{ selectedUser?.name }}</h2>
                            <p class="mt-0.5 text-sm text-slate-500">{{ period.from }} → {{ period.to }}</p>
                        </div>
                    </div>

                    <div class="mt-5 space-y-2 rounded-lg bg-slate-50 p-4 text-sm dark:bg-darkmode-700">
                        <div class="flex justify-between"><span class="text-slate-500">Total cobrado</span><span class="font-medium">{{ money(preview.grand_total) }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-500">Tarjeta + transferencia</span><span>{{ money(preview.card_total + preview.transfer_total) }}</span></div>
                        <div class="flex justify-between border-t border-dashed border-slate-300/70 pt-2 dark:border-darkmode-400"><span class="font-medium">Efectivo esperado en caja</span><span class="font-medium text-success">{{ money(preview.expected_cash) }}</span></div>
                    </div>

                    <div class="mt-4">
                        <label class="mb-1 block text-sm">Efectivo contado (arqueo)</label>
                        <div class="relative">
                            <Lucide icon="Banknote" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                            <FormInput v-model="countedCash" type="number" step="0.01" min="0" class="pl-9" placeholder="Cuenta el dinero físico…" />
                        </div>
                        <div v-if="difference !== null" class="mt-2 flex items-center justify-between rounded-lg px-3 py-2 text-sm" :class="difference === 0 ? 'bg-success/10 text-success' : difference > 0 ? 'bg-info/10 text-info' : 'bg-danger/10 text-danger'">
                            <span class="font-medium">{{ difference === 0 ? 'Caja cuadra' : difference > 0 ? 'Sobrante' : 'Faltante' }}</span>
                            <span class="font-medium">{{ money(Math.abs(difference)) }}</span>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="mb-1 block text-sm">Notas (opcional)</label>
                        <FormInput v-model="notes" type="text" placeholder="Observaciones del turno…" />
                    </div>

                    <div class="mt-6 flex justify-end gap-2">
                        <Button variant="outline-secondary" @click="showClose = false">Cancelar</Button>
                        <Button variant="primary" class="shadow-md shadow-primary/20" :disabled="saving" @click="submitCut">
                            <Lucide icon="Check" class="mr-2 h-4 w-4" /> {{ saving ? 'Guardando…' : 'Guardar corte' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal detalle de corte -->
        <Dialog size="lg" :open="detailCut !== null" @close="detailCut = null">
            <Dialog.Panel>
                <div v-if="detailCut" class="flex max-h-[85vh] flex-col">
                    <!-- Header -->
                    <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-sm font-semibold text-white">
                            {{ detailCut.user.trim().split(/\s+/).slice(0, 2).map((p) => p.charAt(0).toUpperCase()).join('') }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">Corte de {{ detailCut.user }}</h2>
                            <p class="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500">
                                <Lucide icon="CalendarRange" class="h-3.5 w-3.5" /> {{ detailCut.opened_at }} → {{ detailCut.closed_at }}
                            </p>
                        </div>
                        <span
                            v-if="detailCut.counted_cash !== null"
                            class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium"
                            :class="detailCut.difference === 0 ? 'bg-success/10 text-success' : detailCut.difference > 0 ? 'bg-info/10 text-info' : 'bg-danger/10 text-danger'"
                        >
                            <Lucide :icon="detailCut.difference === 0 ? 'CircleCheck' : 'TriangleAlert'" class="h-3.5 w-3.5" />
                            {{ detailCut.difference === 0 ? 'Cuadra' : detailCut.difference > 0 ? `Sobra ${money(detailCut.difference)}` : `Falta ${money(Math.abs(detailCut.difference))}` }}
                        </span>
                        <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400" @click="detailCut = null"><Lucide icon="X" class="h-5 w-5" /></button>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5">
                        <!-- Total + movimientos -->
                        <div class="grid grid-cols-2 gap-3">
                            <div class="rounded-lg border border-primary/20 bg-primary/5 p-4 text-center">
                                <div class="flex items-center justify-center gap-1.5 text-xs text-slate-500"><Lucide icon="Wallet" class="h-3.5 w-3.5 text-primary" /> Total cobrado</div>
                                <div class="mt-1 text-xl font-medium text-primary">{{ money(detailCut.grand_total) }}</div>
                            </div>
                            <div class="rounded-lg border border-slate-200/70 p-4 text-center dark:border-darkmode-400">
                                <div class="flex items-center justify-center gap-1.5 text-xs text-slate-500"><Lucide icon="ReceiptText" class="h-3.5 w-3.5" /> Movimientos</div>
                                <div class="mt-1 text-xl font-medium">{{ detailCut.orders_count + detailCut.payments_count }}</div>
                                <div class="text-xs text-slate-400">{{ detailCut.orders_count }} POS · {{ detailCut.payments_count }} cobros</div>
                            </div>
                        </div>

                        <!-- Desglose por método -->
                        <div>
                            <div class="mb-3 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="CreditCard" class="h-3.5 w-3.5" /> Por método de pago
                            </div>
                            <div class="space-y-2">
                                <div class="flex items-center justify-between rounded-lg border border-slate-200/70 px-3.5 py-2.5 dark:border-darkmode-400">
                                    <span class="flex items-center gap-2.5 text-sm">
                                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-success/10 text-success"><Lucide icon="Banknote" class="h-4 w-4" /></span>
                                        Efectivo
                                    </span>
                                    <span class="font-medium">{{ money(detailCut.cash_total) }}</span>
                                </div>
                                <div class="flex items-center justify-between rounded-lg border border-slate-200/70 px-3.5 py-2.5 dark:border-darkmode-400">
                                    <span class="flex items-center gap-2.5 text-sm">
                                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-info/10 text-info"><Lucide icon="CreditCard" class="h-4 w-4" /></span>
                                        Tarjeta
                                    </span>
                                    <span class="font-medium">{{ money(detailCut.card_total) }}</span>
                                </div>
                                <div class="flex items-center justify-between rounded-lg border border-slate-200/70 px-3.5 py-2.5 dark:border-darkmode-400">
                                    <span class="flex items-center gap-2.5 text-sm">
                                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-warning/10 text-warning"><Lucide icon="ArrowLeftRight" class="h-4 w-4" /></span>
                                        Transferencia
                                    </span>
                                    <span class="font-medium">{{ money(detailCut.transfer_total) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Arqueo de efectivo -->
                        <div>
                            <div class="mb-3 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="ClipboardCheck" class="h-3.5 w-3.5" /> Arqueo de efectivo
                            </div>
                            <div class="rounded-lg border border-dashed border-slate-300/70 p-4 dark:border-darkmode-400">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-slate-500">Efectivo esperado</span>
                                    <span class="font-medium text-success">{{ money(detailCut.expected_cash) }}</span>
                                </div>
                                <template v-if="detailCut.counted_cash !== null">
                                    <div class="mt-2 flex items-center justify-between text-sm">
                                        <span class="text-slate-500">Efectivo contado</span>
                                        <span class="font-medium">{{ money(detailCut.counted_cash) }}</span>
                                    </div>
                                    <div class="mt-3 flex items-center justify-between rounded-lg px-3 py-2 text-sm font-medium" :class="detailCut.difference === 0 ? 'bg-success/10 text-success' : detailCut.difference > 0 ? 'bg-info/10 text-info' : 'bg-danger/10 text-danger'">
                                        <span class="flex items-center gap-1.5">
                                            <Lucide :icon="detailCut.difference === 0 ? 'CircleCheck' : detailCut.difference > 0 ? 'PiggyBank' : 'TriangleAlert'" class="h-4 w-4" />
                                            {{ detailCut.difference === 0 ? 'La caja cuadra' : detailCut.difference > 0 ? 'Sobrante' : 'Faltante' }}
                                        </span>
                                        <span>{{ detailCut.difference === 0 ? '—' : money(Math.abs(detailCut.difference)) }}</span>
                                    </div>
                                </template>
                                <p v-else class="mt-2 flex items-center gap-1.5 text-xs text-slate-400">
                                    <Lucide icon="Info" class="h-3.5 w-3.5" /> Este corte se guardó sin arqueo de efectivo.
                                </p>
                            </div>
                        </div>

                        <p v-if="detailCut.notes" class="flex items-start gap-2 rounded-lg bg-slate-50 px-3.5 py-2.5 text-sm text-slate-600 dark:bg-darkmode-700 dark:text-slate-300">
                            <Lucide icon="StickyNote" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" /> {{ detailCut.notes }}
                        </p>
                    </div>

                    <!-- Footer -->
                    <div class="flex items-center justify-between border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <span class="flex items-center gap-1.5 text-xs text-slate-400">
                            <Lucide icon="UserCheck" class="h-3.5 w-3.5" /> Registrado por {{ detailCut.by ?? '—' }}
                        </span>
                        <Button variant="outline-secondary" @click="detailCut = null">Cerrar</Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
