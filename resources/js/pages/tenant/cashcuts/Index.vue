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

interface Source {
    key: string;
    label: string;
    count: number;
    total: number;
}
interface Method {
    key: string;
    label: string;
    total: number;
}
interface Movement {
    at: string | null;
    concept: string;
    detail: string | null;
    method: string;
    amount: number;
    // false = no entró a esta caja (fianza en garantía, cargo a habitación
    // o devolución): se lista para el rastro pero no es cobro del periodo.
    collected: boolean;
}
interface PendingItem {
    kind: string;
    label: string;
    detail: string | null;
    amount: number;
}
interface Pending {
    count: number;
    total: number;
    items: PendingItem[];
}
interface Preview {
    // Ámbito calculado: 'rooms' (recepción) o 'pos' (punto de venta).
    scope: string;
    orders_count: number;
    orders_total: number;
    orders_cost: number;
    orders_profit: number;
    orders_room: number;
    payments_count: number;
    payments_total: number;
    cash_total: number;
    card_total: number;
    transfer_total: number;
    grand_total: number;
    expected_cash: number;
    // Fondo de caja inicial del turno (solo cortes por turno, ámbito de
    // recepción): el arqueo compara contra el cajón completo.
    opening_cash: number;
    // Fianzas (depósito en garantía) del periodo: no son venta — solo
    // ajustan el efectivo esperado del arqueo (entra al cobrarlas en
    // efectivo, sale al devolverlas). Solo en el ámbito de recepción.
    guarantees_count: number;
    guarantees_collected: number;
    guarantees_cash_in: number;
    guarantees_cash_out: number;
    sources: Source[];
    methods: Method[];
    movements: Movement[];
    pending: Pending;
}
interface Cut {
    id: number;
    user: string;
    scope: string;
    scope_label: string;
    shift_id: number | null;
    opened_at: string;
    closed_at: string;
    orders_count: number;
    payments_count: number;
    grand_total: number;
    cash_total: number;
    card_total: number;
    transfer_total: number;
    expected_cash: number;
    opening_cash: number;
    counted_cash: number | null;
    difference: number;
    pending_count: number;
    pending_total: number;
    pending_items: PendingItem[];
    notes: string | null;
    by: string | null;
}
interface ScopeOption {
    key: string;
    label: string;
}
interface ShiftOption {
    id: number;
    is_open: boolean;
    label: string;
}

const props = defineProps<{
    property: { id: number; name: string };
    staff: { id: number; name: string }[];
    // Ámbitos que este usuario puede cortar en este hotel (Recepción si ve
    // reservas; Punto de venta si el módulo está activo).
    scopes: ScopeOption[];
    filters: {
        user: number | null;
        scope: string | null;
        shift: number | null;
        from: string;
        to: string;
    };
    // Turnos del encargado sin corte de este ámbito (para cortar por turno).
    shifts: ShiftOption[];
    selectedUser: { id: number; name: string } | null;
    period: { from: string; to: string };
    preview: Preview | null;
    cuts: Cut[];
    canManage: boolean;
}>();

const toast = useToasts();
const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n || 0);

const userId = ref<string | number>(props.filters.user ?? '');
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const shiftSel = ref<string | number>(props.filters.shift ?? '');

const activeScope = computed(() => props.filters.scope);
const activeScopeLabel = computed(
    () => props.scopes.find((s) => s.key === activeScope.value)?.label ?? '',
);

function applyFilters() {
    router.get(
        route('tenant.cashcuts'),
        {
            user: userId.value || undefined,
            scope: activeScope.value || undefined,
            // Con turno elegido, el backend usa SU periodo (from/to fuera).
            shift: shiftSel.value || undefined,
            from: shiftSel.value ? undefined : from.value || undefined,
            to: shiftSel.value ? undefined : to.value || undefined,
        },
        { preserveScroll: true },
    );
}

// Cambiar de ámbito reinicia periodo y turno: cada caja arranca donde
// quedó SU último corte.
function setScope(key: string) {
    if (key === activeScope.value) return;
    router.get(
        route('tenant.cashcuts'),
        { user: userId.value || undefined, scope: key },
        { preserveScroll: true },
    );
}

const scopeBadge = (scope: string) =>
    scope === 'rooms'
        ? 'bg-primary/10 text-primary'
        : scope === 'pos'
          ? 'bg-success/10 text-success'
          : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400';

// El corte tiene caso aunque el total sea cero cuando hubo movimiento de
// fianzas (solo recepción): el arqueo cambia sin ser venta.
const canCut = computed(() => {
    if (!props.preview) return false;
    return (
        props.preview.grand_total > 0 ||
        props.preview.guarantees_count > 0 ||
        props.preview.guarantees_cash_out > 0
    );
});

const methodIcons: Record<string, Icon> = {
    cash: 'Banknote',
    card: 'CreditCard',
    transfer: 'ArrowLeftRight',
};

// ── Guardar corte (arqueo) ──
const showClose = ref(false);
const countedCash = ref<string | number>('');
const notes = ref('');
const saving = ref(false);

const difference = computed(() => {
    if (countedCash.value === '' || countedCash.value === null) return null;
    if (!props.preview) return null;
    return (
        Math.round(
            (Number(countedCash.value) - props.preview.expected_cash) * 100,
        ) / 100
    );
});

async function submitCut() {
    saving.value = true;
    try {
        await axios.post(route('tenant.cashcuts.store'), {
            user_id: props.selectedUser?.id,
            scope: props.filters.scope,
            shift_id: props.filters.shift,
            from: props.filters.from,
            to: props.filters.to,
            counted_cash: countedCash.value === '' ? null : countedCash.value,
            notes: notes.value || null,
        });
        showClose.value = false;
        countedCash.value = '';
        notes.value = '';
        toast.success(
            'Corte guardado',
            'El periodo quedó contabilizado y cerrado.',
        );
        router.reload();
    } catch (e: any) {
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        saving.value = false;
    }
}

// ── Detalle de un corte guardado ──
// Los movimientos se reconstruyen en el servidor al abrir el modal (traer
// la lista de los 20 cortes de la página sería tirar el trabajo).
const detailCut = ref<Cut | null>(null);
const detailMovements = ref<Movement[] | null>(null);
const loadingMovements = ref(false);

async function openDetail(cut: Cut) {
    detailCut.value = cut;
    detailMovements.value = null;
    loadingMovements.value = true;
    try {
        const { data } = await axios.get(
            route('tenant.cashcuts.movements', { cashCut: cut.id }),
        );
        detailMovements.value = data.movements;
    } catch {
        detailMovements.value = [];
    } finally {
        loadingMovements.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Cortes de caja">
        <div class="grid grid-cols-12 gap-x-6 gap-y-8">
            <!-- Encabezado -->
            <div class="col-span-12">
                <div
                    class="box box--stacked flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary sm:h-14 sm:w-14"
                        >
                            <Lucide
                                icon="Calculator"
                                class="h-5 w-5 sm:h-7 sm:w-7"
                            />
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-lg font-medium sm:text-xl">
                                Cortes de caja
                            </h1>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ property.name }} · la caja de recepción y la
                                del punto de venta se cortan por separado
                            </p>
                        </div>
                    </div>
                    <div
                        class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2.5"
                    >
                        <Button
                            as="a"
                            :href="route('tenant.shifts')"
                            variant="outline-secondary"
                            class="rounded-[0.5rem] bg-white"
                        >
                            <Lucide
                                icon="Clock"
                                class="mr-2 h-4 w-4 stroke-[1.3]"
                            />
                            Turnos
                        </Button>
                        <Button
                            v-if="canManage && preview"
                            variant="primary"
                            class="rounded-[0.5rem] shadow-md shadow-primary/20"
                            :disabled="!canCut"
                            @click="showClose = true"
                        >
                            <Lucide
                                icon="ClipboardCheck"
                                class="mr-2 h-4 w-4 stroke-[1.3]"
                            />
                            Hacer corte
                        </Button>
                    </div>
                </div>

                <!-- Ámbito: cada caja es su propio corte -->
                <div
                    v-if="scopes.length > 1"
                    class="mt-5 flex w-full gap-1 rounded-[0.6rem] bg-slate-100 p-1 sm:w-fit dark:bg-darkmode-400"
                >
                    <button
                        v-for="s in scopes"
                        :key="s.key"
                        type="button"
                        class="flex flex-1 items-center justify-center gap-2 rounded-[0.5rem] px-4 py-2 text-sm transition sm:flex-none"
                        :class="
                            s.key === activeScope
                                ? 'bg-white font-medium text-primary shadow-sm dark:bg-darkmode-600'
                                : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                        "
                        @click="setScope(s.key)"
                    >
                        <Lucide
                            :icon="
                                s.key === 'pos' ? 'ShoppingCart' : 'BedDouble'
                            "
                            class="h-4 w-4"
                        />
                        {{ s.label }}
                    </button>
                </div>

                <!-- Filtros -->
                <div
                    class="box box--stacked mt-5 flex flex-wrap items-end gap-3 p-3"
                >
                    <div>
                        <label class="mb-1 block text-xs text-slate-500"
                            >Encargado</label
                        >
                        <div class="relative">
                            <Lucide
                                icon="User"
                                class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                            />
                            <FormSelect v-model="userId" class="w-52 pl-9">
                                <option
                                    v-for="s in staff"
                                    :key="s.id"
                                    :value="s.id"
                                >
                                    {{ s.name }}
                                </option>
                            </FormSelect>
                        </div>
                    </div>
                    <div v-if="shifts.length">
                        <label class="mb-1 block text-xs text-slate-500"
                            >Turno</label
                        >
                        <div class="relative">
                            <Lucide
                                icon="Clock"
                                class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                            />
                            <FormSelect
                                v-model="shiftSel"
                                class="w-60 pl-9"
                                @change="applyFilters"
                            >
                                <option value="">Periodo libre</option>
                                <option
                                    v-for="s in shifts"
                                    :key="s.id"
                                    :value="s.id"
                                >
                                    {{ s.label }}
                                </option>
                            </FormSelect>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500"
                            >Desde</label
                        >
                        <FormInput
                            v-model="from"
                            type="datetime-local"
                            class="w-52"
                            :disabled="Boolean(filters.shift)"
                            :title="
                                filters.shift
                                    ? 'El periodo lo define el turno elegido'
                                    : undefined
                            "
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500"
                            >Hasta</label
                        >
                        <FormInput
                            v-model="to"
                            type="datetime-local"
                            class="w-52"
                            :disabled="Boolean(filters.shift)"
                            :title="
                                filters.shift
                                    ? 'El periodo lo define el turno elegido'
                                    : undefined
                            "
                        />
                    </div>
                    <Button
                        variant="outline-primary"
                        class="rounded-[0.5rem] bg-white"
                        @click="applyFilters"
                    >
                        <Lucide icon="RefreshCw" class="mr-2 h-4 w-4" />
                        Calcular
                    </Button>
                    <div
                        class="ml-auto flex items-center gap-2 rounded-[0.5rem] border border-dashed border-slate-300/70 px-3 py-2 text-xs text-slate-500 dark:border-darkmode-400"
                    >
                        <Lucide
                            icon="CalendarRange"
                            class="h-4 w-4 text-primary"
                        />
                        {{ period.from }} → {{ period.to }}
                    </div>
                </div>
            </div>

            <!-- Sin ámbitos disponibles (p. ej. cocina sin módulo POS) -->
            <div v-if="!preview" class="col-span-12">
                <div
                    class="box box--stacked flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <Lucide
                        icon="Calculator"
                        class="h-10 w-10 text-slate-300"
                    />
                    <div>
                        <p class="text-sm font-medium text-slate-600">
                            No tienes cajas por cortar
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            El corte de recepción requiere permiso para ver
                            reservas y el de punto de venta requiere el módulo
                            activo.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Corte en curso -->
            <div v-if="preview" class="col-span-12">
                <div class="flex items-center gap-2.5 md:h-10">
                    <div class="text-base font-medium">
                        Corte en curso · {{ selectedUser?.name }}
                    </div>
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="scopeBadge(preview.scope)"
                    >
                        {{ activeScopeLabel }}
                    </span>
                </div>

                <div class="mt-3.5 grid grid-cols-12 gap-5">
                    <!-- Total contabilizado -->
                    <div
                        class="box box--stacked col-span-12 p-5 sm:col-span-6"
                        :class="
                            preview.scope === 'pos'
                                ? '2xl:col-span-3'
                                : '2xl:col-span-6'
                        "
                    >
                        <div class="flex items-center justify-between">
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                            >
                                <Lucide
                                    icon="Wallet"
                                    class="h-5 w-5 text-primary"
                                />
                            </div>
                            <div class="text-2xl font-medium">
                                {{ money(preview.grand_total) }}
                            </div>
                        </div>
                        <div class="mt-4 text-sm font-medium">
                            Total cobrado
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            <template v-if="preview.scope === 'pos'">
                                {{ preview.orders_count }} ventas
                            </template>
                            <template v-else>
                                {{ preview.payments_count }} cobros de reservas
                                y estancias
                            </template>
                        </div>
                    </div>
                    <!-- Efectivo esperado -->
                    <div
                        class="box box--stacked col-span-12 p-5 sm:col-span-6"
                        :class="
                            preview.scope === 'pos'
                                ? '2xl:col-span-3'
                                : '2xl:col-span-6'
                        "
                    >
                        <div class="flex items-center justify-between">
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-full border border-success/10 bg-success/10"
                            >
                                <Lucide
                                    icon="Banknote"
                                    class="h-5 w-5 text-success"
                                />
                            </div>
                            <div class="text-2xl font-medium text-success">
                                {{ money(preview.expected_cash) }}
                            </div>
                        </div>
                        <div class="mt-4 text-sm font-medium">
                            Efectivo esperado
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            Lo que debe haber en caja
                        </div>
                        <div
                            v-if="preview.opening_cash > 0"
                            class="mt-1 text-xs text-slate-500"
                        >
                            Incluye el fondo inicial del turno:
                            {{ money(preview.opening_cash) }}.
                        </div>
                        <div
                            v-if="
                                preview.guarantees_cash_in > 0 ||
                                preview.guarantees_cash_out > 0
                            "
                            class="mt-1 text-xs text-slate-500"
                        >
                            Incluye fianzas en garantía:
                            {{ money(preview.guarantees_cash_in) }}
                            cobradas<template
                                v-if="preview.guarantees_cash_out > 0"
                            >
                                −
                                {{ money(preview.guarantees_cash_out) }}
                                devueltas</template
                            >
                            (no son venta).
                        </div>
                    </div>
                    <!-- Utilidad POS (solo ámbito punto de venta) -->
                    <div
                        v-if="preview.scope === 'pos'"
                        class="box box--stacked col-span-12 p-5 sm:col-span-6 2xl:col-span-3"
                    >
                        <div class="flex items-center justify-between">
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-full border border-info/10 bg-info/10"
                            >
                                <Lucide
                                    icon="TrendingUp"
                                    class="h-5 w-5 text-info"
                                />
                            </div>
                            <div class="text-2xl font-medium">
                                {{ money(preview.orders_profit) }}
                            </div>
                        </div>
                        <div class="mt-4 text-sm font-medium">Utilidad POS</div>
                        <div class="mt-1 text-xs text-slate-500">
                            Venta {{ money(preview.orders_total) }} − costo
                            {{ money(preview.orders_cost) }}
                        </div>
                    </div>
                    <!-- Cargado a habitación (solo ámbito punto de venta) -->
                    <div
                        v-if="preview.scope === 'pos'"
                        class="box box--stacked col-span-12 p-5 sm:col-span-6 2xl:col-span-3"
                    >
                        <div class="flex items-center justify-between">
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-full border border-warning/10 bg-warning/10"
                            >
                                <Lucide
                                    icon="BedDouble"
                                    class="h-5 w-5 text-warning"
                                />
                            </div>
                            <div class="text-2xl font-medium">
                                {{ money(preview.orders_room) }}
                            </div>
                        </div>
                        <div class="mt-4 text-sm font-medium">
                            Cargado a habitación
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            Se cobra en el check-out (no es efectivo)
                        </div>
                    </div>
                </div>
            </div>

            <!-- Desglose por método de pago -->
            <div v-if="preview" class="col-span-12 flex flex-col xl:col-span-5">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">
                        Desglose por método de pago
                    </div>
                </div>
                <div class="box box--stacked mt-3.5 flex-1 p-5">
                    <div class="space-y-4">
                        <div
                            v-for="m in preview.methods"
                            :key="m.key"
                            class="flex items-center gap-3"
                        >
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 dark:bg-darkmode-400"
                            >
                                <Lucide
                                    :icon="methodIcons[m.key] ?? 'Circle'"
                                    class="h-4 w-4"
                                />
                            </div>
                            <div class="flex-1">
                                <div
                                    class="flex items-center justify-between text-sm"
                                >
                                    <span class="font-medium">{{
                                        m.label
                                    }}</span>
                                    <span class="font-medium">{{
                                        money(m.total)
                                    }}</span>
                                </div>
                                <div
                                    class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                                >
                                    <div
                                        class="h-full rounded-full bg-primary/70"
                                        :style="{
                                            width: `${preview.grand_total > 0 ? (m.total / preview.grand_total) * 100 : 0}%`,
                                        }"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div
                        class="mt-5 flex items-center justify-between border-t border-dashed border-slate-300/70 pt-4 text-sm dark:border-darkmode-400"
                    >
                        <span class="font-medium">Total</span>
                        <span class="text-base font-medium">{{
                            money(preview.grand_total)
                        }}</span>
                    </div>
                    <p
                        class="mt-4 flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:bg-darkmode-700"
                    >
                        <Lucide icon="Info" class="h-4 w-4 shrink-0" />
                        El corte de {{ activeScopeLabel.toLowerCase() }} cuenta
                        lo que
                        <span class="font-medium">{{
                            selectedUser?.name
                        }}</span>
                        cobró en esa caja durante el periodo. Al guardarlo, el
                        siguiente corte del mismo ámbito arranca desde aquí.
                    </p>
                </div>
            </div>

            <!-- Movimientos del periodo -->
            <div v-if="preview" class="col-span-12 flex flex-col xl:col-span-7">
                <div class="flex items-center gap-2 md:h-10">
                    <div class="text-base font-medium">
                        Movimientos del periodo
                    </div>
                    <span
                        class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500 dark:bg-darkmode-400"
                    >
                        {{ preview.movements.length }}
                    </span>
                </div>
                <div class="box box--stacked mt-3.5 flex-1 p-5">
                    <div
                        v-if="preview.movements.length"
                        class="max-h-96 overflow-auto"
                    >
                        <Table>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>Hora</Table.Th>
                                    <Table.Th>Concepto</Table.Th>
                                    <Table.Th>Método</Table.Th>
                                    <Table.Th class="text-right"
                                        >Monto</Table.Th
                                    >
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr
                                    v-for="(m, i) in preview.movements"
                                    :key="i"
                                >
                                    <Table.Td
                                        class="text-sm whitespace-nowrap text-slate-500"
                                        >{{ m.at }}</Table.Td
                                    >
                                    <Table.Td>
                                        <div class="text-sm font-medium">
                                            {{ m.concept }}
                                        </div>
                                        <div
                                            v-if="m.detail"
                                            class="text-xs text-slate-500"
                                        >
                                            {{ m.detail }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td
                                        class="text-sm whitespace-nowrap text-slate-500"
                                        >{{ m.method }}</Table.Td
                                    >
                                    <Table.Td class="text-right">
                                        <span
                                            class="font-medium whitespace-nowrap"
                                            :class="
                                                m.amount < 0
                                                    ? 'text-danger'
                                                    : !m.collected
                                                      ? 'text-slate-400'
                                                      : ''
                                            "
                                            :title="
                                                !m.collected && m.amount >= 0
                                                    ? 'No entró a esta caja (fianza o cargo a habitación)'
                                                    : undefined
                                            "
                                        >
                                            {{ m.amount < 0 ? '−' : ''
                                            }}{{ money(Math.abs(m.amount)) }}
                                        </span>
                                    </Table.Td>
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>
                    </div>
                    <div
                        v-else
                        class="flex flex-col items-center gap-3 py-10 text-center text-slate-400"
                    >
                        <Lucide icon="ReceiptText" class="h-8 w-8" />
                        <p class="text-sm">
                            Sin movimientos en este periodo.
                        </p>
                    </div>
                    <p
                        v-if="preview.movements.length"
                        class="mt-4 flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:bg-darkmode-700"
                    >
                        <Lucide icon="Info" class="h-4 w-4 shrink-0" />
                        Los montos en gris no entraron a esta caja: fianzas en
                        garantía o ventas cargadas a habitación que se cobran
                        en el check-out.
                    </p>
                </div>
            </div>

            <!-- Pagos pendientes al momento del corte -->
            <div v-if="preview" class="col-span-12">
                <div class="flex items-center gap-2 md:h-10">
                    <div class="text-base font-medium">Pagos pendientes</div>
                    <span
                        v-if="preview.pending.count > 0"
                        class="rounded-full bg-warning/10 px-2.5 py-0.5 text-xs font-medium text-warning"
                    >
                        {{ preview.pending.count }} ·
                        {{ money(preview.pending.total) }}
                    </span>
                </div>
                <div class="box box--stacked mt-3.5 p-5">
                    <template v-if="preview.pending.items.length">
                        <div class="space-y-2">
                            <div
                                v-for="(p, i) in preview.pending.items"
                                :key="i"
                                class="flex items-center justify-between gap-3 rounded-lg border border-slate-200/70 px-3.5 py-2.5 dark:border-darkmode-400"
                            >
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <span
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-warning/10 text-warning"
                                    >
                                        <Lucide
                                            :icon="
                                                p.kind === 'order'
                                                    ? 'ShoppingCart'
                                                    : p.kind === 'reservation'
                                                      ? 'CalendarClock'
                                                      : 'BedDouble'
                                            "
                                            class="h-4 w-4"
                                        />
                                    </span>
                                    <div class="min-w-0">
                                        <div
                                            class="truncate text-sm font-medium"
                                        >
                                            {{ p.label }}
                                        </div>
                                        <div
                                            v-if="p.detail"
                                            class="truncate text-xs text-slate-500"
                                        >
                                            {{ p.detail }}
                                        </div>
                                    </div>
                                </div>
                                <span class="font-medium whitespace-nowrap">{{
                                    money(p.amount)
                                }}</span>
                            </div>
                        </div>
                        <div
                            class="mt-4 flex items-center justify-between border-t border-dashed border-slate-300/70 pt-3 text-sm dark:border-darkmode-400"
                        >
                            <span class="font-medium"
                                >Total pendiente de cobro</span
                            >
                            <span class="font-medium text-warning">{{
                                money(preview.pending.total)
                            }}</span>
                        </div>
                        <p
                            class="mt-3 flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:bg-darkmode-700"
                        >
                            <Lucide icon="Info" class="h-4 w-4 shrink-0" />
                            Saldos vivos al momento de calcular: huéspedes en
                            casa con saldo, reservas con pago vencido y ventas
                            cargadas a habitación. Al guardar el corte quedan
                            congelados para el relevo de turno.
                        </p>
                    </template>
                    <div
                        v-else
                        class="flex items-center gap-2.5 text-sm text-slate-500"
                    >
                        <span
                            class="flex h-8 w-8 items-center justify-center rounded-full bg-success/10 text-success"
                        >
                            <Lucide icon="CircleCheck" class="h-4 w-4" />
                        </span>
                        Sin pagos pendientes al momento del corte.
                    </div>
                </div>
            </div>

            <!-- Historial de cortes -->
            <div class="col-span-12">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">Cortes guardados</div>
                </div>
                <div
                    class="box box--stacked mt-3.5 overflow-auto p-5 lg:overflow-visible"
                >
                    <Table v-if="cuts.length">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Encargado</Table.Th>
                                <Table.Th>Ámbito</Table.Th>
                                <Table.Th>Periodo</Table.Th>
                                <Table.Th class="text-right">Total</Table.Th>
                                <Table.Th class="text-right">Efectivo</Table.Th>
                                <Table.Th class="text-right"
                                    >Diferencia</Table.Th
                                >
                                <Table.Th class="text-right" />
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="c in cuts" :key="c.id">
                                <Table.Td class="font-medium">{{
                                    c.user
                                }}</Table.Td>
                                <Table.Td>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
                                        :class="scopeBadge(c.scope)"
                                    >
                                        {{ c.scope_label }}
                                    </span>
                                </Table.Td>
                                <Table.Td
                                    class="text-sm whitespace-nowrap text-slate-500"
                                    >{{ c.closed_at }}</Table.Td
                                >
                                <Table.Td class="text-right font-medium">{{
                                    money(c.grand_total)
                                }}</Table.Td>
                                <Table.Td class="text-right text-sm">
                                    <span class="text-slate-500"
                                        >esp. {{ money(c.expected_cash) }}</span
                                    >
                                    <span v-if="c.counted_cash !== null">
                                        · cont.
                                        {{ money(c.counted_cash) }}</span
                                    >
                                </Table.Td>
                                <Table.Td class="text-right">
                                    <span
                                        v-if="c.counted_cash !== null"
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            c.difference === 0
                                                ? 'bg-success/10 text-success'
                                                : c.difference > 0
                                                  ? 'bg-info/10 text-info'
                                                  : 'bg-danger/10 text-danger'
                                        "
                                    >
                                        {{
                                            c.difference === 0
                                                ? 'Cuadra'
                                                : c.difference > 0
                                                  ? `Sobra ${money(c.difference)}`
                                                  : `Falta ${money(Math.abs(c.difference))}`
                                        }}
                                    </span>
                                    <span v-else class="text-xs text-slate-400"
                                        >sin arqueo</span
                                    >
                                </Table.Td>
                                <Table.Td class="text-right">
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        <button
                                            type="button"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-primary dark:hover:bg-darkmode-400"
                                            @click="openDetail(c)"
                                        >
                                            <Lucide
                                                icon="Eye"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                        <a
                                            :href="
                                                route('tenant.cashcuts.pdf', {
                                                    cashCut: c.id,
                                                })
                                            "
                                            title="Descargar PDF para firma"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-primary dark:hover:bg-darkmode-400"
                                        >
                                            <Lucide
                                                icon="Download"
                                                class="h-4 w-4"
                                            />
                                        </a>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div
                        v-else
                        class="flex flex-col items-center gap-3 py-10 text-center text-slate-400"
                    >
                        <Lucide icon="ClipboardList" class="h-8 w-8" />
                        <p class="text-sm">Aún no hay cortes guardados.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal hacer corte (arqueo) -->
        <Dialog size="lg" :open="showClose" @close="showClose = false">
            <Dialog.Panel>
                <div v-if="preview" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="ClipboardCheck" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                Corte de {{ activeScopeLabel.toLowerCase() }} ·
                                {{ selectedUser?.name }}
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                {{ period.from }} → {{ period.to }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="mt-5 space-y-2 rounded-lg bg-slate-50 p-4 text-sm dark:bg-darkmode-700"
                    >
                        <div class="flex justify-between">
                            <span class="text-slate-500">Total cobrado</span
                            ><span class="font-medium">{{
                                money(preview.grand_total)
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500"
                                >Tarjeta + transferencia</span
                            ><span>{{
                                money(
                                    preview.card_total + preview.transfer_total,
                                )
                            }}</span>
                        </div>
                        <div
                            v-if="preview.opening_cash > 0"
                            class="flex justify-between"
                        >
                            <span class="text-slate-500"
                                >Fondo inicial del turno</span
                            ><span>{{ money(preview.opening_cash) }}</span>
                        </div>
                        <div
                            class="flex justify-between border-t border-dashed border-slate-300/70 pt-2 dark:border-darkmode-400"
                        >
                            <span class="font-medium"
                                >Efectivo esperado en caja</span
                            ><span class="font-medium text-success">{{
                                money(preview.expected_cash)
                            }}</span>
                        </div>
                        <div
                            v-if="preview.pending.count > 0"
                            class="flex justify-between"
                        >
                            <span class="text-slate-500"
                                >Pendientes que quedan para el relevo</span
                            ><span class="font-medium text-warning"
                                >{{ preview.pending.count }} ·
                                {{ money(preview.pending.total) }}</span
                            >
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="mb-1 block text-sm"
                            >Efectivo contado (arqueo)</label
                        >
                        <div class="relative">
                            <Lucide
                                icon="Banknote"
                                class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                            />
                            <FormInput
                                v-model="countedCash"
                                type="number"
                                step="0.01"
                                min="0"
                                class="pl-9"
                                placeholder="Cuenta el dinero físico…"
                            />
                        </div>
                        <div
                            v-if="difference !== null"
                            class="mt-2 flex items-center justify-between rounded-lg px-3 py-2 text-sm"
                            :class="
                                difference === 0
                                    ? 'bg-success/10 text-success'
                                    : difference > 0
                                      ? 'bg-info/10 text-info'
                                      : 'bg-danger/10 text-danger'
                            "
                        >
                            <span class="font-medium">{{
                                difference === 0
                                    ? 'Caja cuadra'
                                    : difference > 0
                                      ? 'Sobrante'
                                      : 'Faltante'
                            }}</span>
                            <span class="font-medium">{{
                                money(Math.abs(difference))
                            }}</span>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="mb-1 block text-sm"
                            >Notas (opcional)</label
                        >
                        <FormInput
                            v-model="notes"
                            type="text"
                            placeholder="Observaciones del turno…"
                        />
                    </div>

                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="showClose = false"
                            >Cancelar</Button
                        >
                        <Button
                            variant="primary"
                            class="shadow-md shadow-primary/20"
                            :disabled="saving"
                            @click="submitCut"
                        >
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{ saving ? 'Guardando…' : 'Guardar corte' }}
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
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-sm font-semibold text-white"
                        >
                            {{
                                detailCut.user
                                    .trim()
                                    .split(/\s+/)
                                    .slice(0, 2)
                                    .map((p) => p.charAt(0).toUpperCase())
                                    .join('')
                            }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2
                                class="flex items-center gap-2 text-base font-medium"
                            >
                                Corte de {{ detailCut.user }}
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="scopeBadge(detailCut.scope)"
                                >
                                    {{ detailCut.scope_label }}
                                </span>
                            </h2>
                            <p
                                class="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500"
                            >
                                <Lucide
                                    icon="CalendarRange"
                                    class="h-3.5 w-3.5"
                                />
                                {{ detailCut.opened_at }} →
                                {{ detailCut.closed_at }}
                                <template v-if="detailCut.shift_id">
                                    · Turno #{{ detailCut.shift_id }}
                                </template>
                            </p>
                        </div>
                        <span
                            v-if="detailCut.counted_cash !== null"
                            class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium"
                            :class="
                                detailCut.difference === 0
                                    ? 'bg-success/10 text-success'
                                    : detailCut.difference > 0
                                      ? 'bg-info/10 text-info'
                                      : 'bg-danger/10 text-danger'
                            "
                        >
                            <Lucide
                                :icon="
                                    detailCut.difference === 0
                                        ? 'CircleCheck'
                                        : 'TriangleAlert'
                                "
                                class="h-3.5 w-3.5"
                            />
                            {{
                                detailCut.difference === 0
                                    ? 'Cuadra'
                                    : detailCut.difference > 0
                                      ? `Sobra ${money(detailCut.difference)}`
                                      : `Falta ${money(Math.abs(detailCut.difference))}`
                            }}
                        </span>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="detailCut = null"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5">
                        <!-- Total + movimientos -->
                        <div class="grid grid-cols-2 gap-3">
                            <div
                                class="rounded-lg border border-primary/20 bg-primary/5 p-4 text-center"
                            >
                                <div
                                    class="flex items-center justify-center gap-1.5 text-xs text-slate-500"
                                >
                                    <Lucide
                                        icon="Wallet"
                                        class="h-3.5 w-3.5 text-primary"
                                    />
                                    Total cobrado
                                </div>
                                <div
                                    class="mt-1 text-xl font-medium text-primary"
                                >
                                    {{ money(detailCut.grand_total) }}
                                </div>
                            </div>
                            <div
                                class="rounded-lg border border-slate-200/70 p-4 text-center dark:border-darkmode-400"
                            >
                                <div
                                    class="flex items-center justify-center gap-1.5 text-xs text-slate-500"
                                >
                                    <Lucide
                                        icon="ReceiptText"
                                        class="h-3.5 w-3.5"
                                    />
                                    Movimientos
                                </div>
                                <div class="mt-1 text-xl font-medium">
                                    {{
                                        detailCut.orders_count +
                                        detailCut.payments_count
                                    }}
                                </div>
                                <div class="text-xs text-slate-400">
                                    <template v-if="detailCut.scope === 'pos'">
                                        {{ detailCut.orders_count }} ventas POS
                                    </template>
                                    <template
                                        v-else-if="detailCut.scope === 'rooms'"
                                    >
                                        {{ detailCut.payments_count }} cobros de
                                        reservas
                                    </template>
                                    <template v-else>
                                        {{ detailCut.orders_count }} POS ·
                                        {{ detailCut.payments_count }} cobros
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- Desglose por método -->
                        <div>
                            <div
                                class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide icon="CreditCard" class="h-3.5 w-3.5" />
                                Por método de pago
                            </div>
                            <div class="space-y-2">
                                <div
                                    class="flex items-center justify-between rounded-lg border border-slate-200/70 px-3.5 py-2.5 dark:border-darkmode-400"
                                >
                                    <span
                                        class="flex items-center gap-2.5 text-sm"
                                    >
                                        <span
                                            class="flex h-8 w-8 items-center justify-center rounded-full bg-success/10 text-success"
                                            ><Lucide
                                                icon="Banknote"
                                                class="h-4 w-4"
                                        /></span>
                                        Efectivo
                                    </span>
                                    <span class="font-medium">{{
                                        money(detailCut.cash_total)
                                    }}</span>
                                </div>
                                <div
                                    class="flex items-center justify-between rounded-lg border border-slate-200/70 px-3.5 py-2.5 dark:border-darkmode-400"
                                >
                                    <span
                                        class="flex items-center gap-2.5 text-sm"
                                    >
                                        <span
                                            class="flex h-8 w-8 items-center justify-center rounded-full bg-info/10 text-info"
                                            ><Lucide
                                                icon="CreditCard"
                                                class="h-4 w-4"
                                        /></span>
                                        Tarjeta
                                    </span>
                                    <span class="font-medium">{{
                                        money(detailCut.card_total)
                                    }}</span>
                                </div>
                                <div
                                    class="flex items-center justify-between rounded-lg border border-slate-200/70 px-3.5 py-2.5 dark:border-darkmode-400"
                                >
                                    <span
                                        class="flex items-center gap-2.5 text-sm"
                                    >
                                        <span
                                            class="flex h-8 w-8 items-center justify-center rounded-full bg-warning/10 text-warning"
                                            ><Lucide
                                                icon="ArrowLeftRight"
                                                class="h-4 w-4"
                                        /></span>
                                        Transferencia
                                    </span>
                                    <span class="font-medium">{{
                                        money(detailCut.transfer_total)
                                    }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Movimientos del periodo -->
                        <div>
                            <div
                                class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide
                                    icon="ReceiptText"
                                    class="h-3.5 w-3.5"
                                />
                                Movimientos del periodo
                            </div>
                            <div
                                v-if="loadingMovements"
                                class="flex items-center gap-2 rounded-lg border border-dashed border-slate-300/70 px-3.5 py-3 text-sm text-slate-400 dark:border-darkmode-400"
                            >
                                <Lucide
                                    icon="LoaderCircle"
                                    class="h-4 w-4 animate-spin"
                                />
                                Cargando movimientos…
                            </div>
                            <div
                                v-else-if="
                                    detailMovements && detailMovements.length
                                "
                                class="max-h-72 overflow-auto rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                            >
                                <Table>
                                    <Table.Thead>
                                        <Table.Tr>
                                            <Table.Th>Hora</Table.Th>
                                            <Table.Th>Concepto</Table.Th>
                                            <Table.Th class="text-right"
                                                >Monto</Table.Th
                                            >
                                        </Table.Tr>
                                    </Table.Thead>
                                    <Table.Tbody>
                                        <Table.Tr
                                            v-for="(m, i) in detailMovements"
                                            :key="i"
                                        >
                                            <Table.Td
                                                class="text-xs whitespace-nowrap text-slate-500"
                                                >{{ m.at }}</Table.Td
                                            >
                                            <Table.Td>
                                                <div class="text-sm">
                                                    {{ m.concept }}
                                                </div>
                                                <div
                                                    class="text-xs text-slate-500"
                                                >
                                                    <template v-if="m.detail"
                                                        >{{ m.detail }} ·
                                                    </template>
                                                    {{ m.method }}
                                                </div>
                                            </Table.Td>
                                            <Table.Td class="text-right">
                                                <span
                                                    class="text-sm font-medium whitespace-nowrap"
                                                    :class="
                                                        m.amount < 0
                                                            ? 'text-danger'
                                                            : !m.collected
                                                              ? 'text-slate-400'
                                                              : ''
                                                    "
                                                    :title="
                                                        !m.collected &&
                                                        m.amount >= 0
                                                            ? 'No entró a esta caja (fianza o cargo a habitación)'
                                                            : undefined
                                                    "
                                                >
                                                    {{ m.amount < 0 ? '−' : ''
                                                    }}{{
                                                        money(
                                                            Math.abs(m.amount),
                                                        )
                                                    }}
                                                </span>
                                            </Table.Td>
                                        </Table.Tr>
                                    </Table.Tbody>
                                </Table>
                            </div>
                            <p
                                v-else
                                class="flex items-center gap-1.5 rounded-lg border border-dashed border-slate-300/70 px-3.5 py-3 text-xs text-slate-400 dark:border-darkmode-400"
                            >
                                <Lucide icon="Info" class="h-3.5 w-3.5" />
                                Sin movimientos que mostrar para este corte.
                            </p>
                        </div>

                        <!-- Pendientes congelados al momento del corte -->
                        <div v-if="detailCut.pending_count > 0">
                            <div
                                class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide
                                    icon="CalendarClock"
                                    class="h-3.5 w-3.5"
                                />
                                Pagos pendientes al momento del corte
                            </div>
                            <div class="space-y-2">
                                <div
                                    v-for="(p, i) in detailCut.pending_items"
                                    :key="i"
                                    class="flex items-center justify-between gap-3 rounded-lg border border-slate-200/70 px-3.5 py-2.5 dark:border-darkmode-400"
                                >
                                    <div class="min-w-0">
                                        <div
                                            class="truncate text-sm font-medium"
                                        >
                                            {{ p.label }}
                                        </div>
                                        <div
                                            v-if="p.detail"
                                            class="truncate text-xs text-slate-500"
                                        >
                                            {{ p.detail }}
                                        </div>
                                    </div>
                                    <span
                                        class="text-sm font-medium whitespace-nowrap"
                                        >{{ money(p.amount) }}</span
                                    >
                                </div>
                                <div
                                    class="flex items-center justify-between px-3.5 text-sm"
                                >
                                    <span class="font-medium"
                                        >Total pendiente</span
                                    >
                                    <span class="font-medium text-warning">{{
                                        money(detailCut.pending_total)
                                    }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Arqueo de efectivo -->
                        <div>
                            <div
                                class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide
                                    icon="ClipboardCheck"
                                    class="h-3.5 w-3.5"
                                />
                                Arqueo de efectivo
                            </div>
                            <div
                                class="rounded-lg border border-dashed border-slate-300/70 p-4 dark:border-darkmode-400"
                            >
                                <div
                                    v-if="detailCut.opening_cash > 0"
                                    class="mb-2 flex items-center justify-between text-sm"
                                >
                                    <span class="text-slate-500"
                                        >Fondo inicial del turno</span
                                    >
                                    <span>{{
                                        money(detailCut.opening_cash)
                                    }}</span>
                                </div>
                                <div
                                    class="flex items-center justify-between text-sm"
                                >
                                    <span class="text-slate-500"
                                        >Efectivo esperado</span
                                    >
                                    <span class="font-medium text-success">{{
                                        money(detailCut.expected_cash)
                                    }}</span>
                                </div>
                                <template
                                    v-if="detailCut.counted_cash !== null"
                                >
                                    <div
                                        class="mt-2 flex items-center justify-between text-sm"
                                    >
                                        <span class="text-slate-500"
                                            >Efectivo contado</span
                                        >
                                        <span class="font-medium">{{
                                            money(detailCut.counted_cash)
                                        }}</span>
                                    </div>
                                    <div
                                        class="mt-3 flex items-center justify-between rounded-lg px-3 py-2 text-sm font-medium"
                                        :class="
                                            detailCut.difference === 0
                                                ? 'bg-success/10 text-success'
                                                : detailCut.difference > 0
                                                  ? 'bg-info/10 text-info'
                                                  : 'bg-danger/10 text-danger'
                                        "
                                    >
                                        <span class="flex items-center gap-1.5">
                                            <Lucide
                                                :icon="
                                                    detailCut.difference === 0
                                                        ? 'CircleCheck'
                                                        : detailCut.difference >
                                                            0
                                                          ? 'PiggyBank'
                                                          : 'TriangleAlert'
                                                "
                                                class="h-4 w-4"
                                            />
                                            {{
                                                detailCut.difference === 0
                                                    ? 'La caja cuadra'
                                                    : detailCut.difference > 0
                                                      ? 'Sobrante'
                                                      : 'Faltante'
                                            }}
                                        </span>
                                        <span>{{
                                            detailCut.difference === 0
                                                ? '—'
                                                : money(
                                                      Math.abs(
                                                          detailCut.difference,
                                                      ),
                                                  )
                                        }}</span>
                                    </div>
                                </template>
                                <p
                                    v-else
                                    class="mt-2 flex items-center gap-1.5 text-xs text-slate-400"
                                >
                                    <Lucide icon="Info" class="h-3.5 w-3.5" />
                                    Este corte se guardó sin arqueo de efectivo.
                                </p>
                            </div>
                        </div>

                        <p
                            v-if="detailCut.notes"
                            class="flex items-start gap-2 rounded-lg bg-slate-50 px-3.5 py-2.5 text-sm text-slate-600 dark:bg-darkmode-700 dark:text-slate-300"
                        >
                            <Lucide
                                icon="StickyNote"
                                class="mt-0.5 h-4 w-4 shrink-0 text-slate-400"
                            />
                            {{ detailCut.notes }}
                        </p>
                    </div>

                    <!-- Footer -->
                    <div
                        class="flex items-center justify-between border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <span
                            class="flex items-center gap-1.5 text-xs text-slate-400"
                        >
                            <Lucide icon="UserCheck" class="h-3.5 w-3.5" />
                            Registrado por {{ detailCut.by ?? '—' }}
                        </span>
                        <div class="flex items-center gap-2">
                            <Button
                                as="a"
                                variant="outline-primary"
                                :href="
                                    route('tenant.cashcuts.pdf', {
                                        cashCut: detailCut.id,
                                    })
                                "
                            >
                                <Lucide icon="Download" class="mr-2 h-4 w-4" />
                                PDF para firma
                            </Button>
                            <Button
                                variant="outline-secondary"
                                @click="detailCut = null"
                                >Cerrar</Button
                            >
                        </div>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
