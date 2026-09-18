<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormDateTime,
    FormHelp,
    FormInput,
    FormLabel,
    FormSelect,
} from '@/components/Base/Form';
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
    // Salidas de efectivo del periodo: restan del efectivo esperado.
    expenses_count: number;
    expenses_total: number;
    expenses_by_category: ExpenseCategory[];
    expenses: Expense[];
}
interface ExpenseCategory {
    key: string;
    label: string;
    count: number;
    total: number;
}
interface Expense {
    id: number;
    category: string;
    category_label: string;
    concept: string;
    amount: number;
    at: string;
    by: string | null;
    receipt_url: string | null;
    /** Ya quedó dentro de un corte cerrado: no se borra. */
    locked: boolean;
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
    expenses_count: number;
    expenses_total: number;
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
interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}
interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    total: number;
    from: number | null;
    to: number | null;
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
    cuts: Paginated<Cut>;
    historyFilters: {
        scope: string;
        user: number | null;
        state: string;
    };
    historyStats: {
        count: number;
        total: number;
        off: number;
        without_count: number;
    };
    canManage: boolean;
}>();

const toast = useToasts();
const money = (n: number) => {
    const valor = n || 0;
    // El menos va antes del peso: "$-6,500.00" se lee como un precio raro.
    return (
        (valor < 0 ? '−$' : '$') +
        new Intl.NumberFormat('es-MX', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(Math.abs(valor))
    );
};

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

// ── Filtros del historial: paginado y filtrable aparte del corte en curso ──
const historyScope = ref(props.historyFilters.scope);
const historyUser = ref<number | string>(props.historyFilters.user ?? '');
const historyState = ref(props.historyFilters.state);

const historyFiltersActive = computed(
    () =>
        historyScope.value !== '' ||
        historyUser.value !== '' ||
        historyState.value !== '',
);

/**
 * El historial vive en la misma URL que el corte en curso, así que su
 * consulta arrastra los filtros de arriba; lo único que se reinicia es su
 * página.
 */
function applyHistory() {
    router.get(
        route('tenant.cashcuts'),
        {
            user: userId.value || undefined,
            scope: activeScope.value || undefined,
            shift: shiftSel.value || undefined,
            from: shiftSel.value ? undefined : from.value || undefined,
            to: shiftSel.value ? undefined : to.value || undefined,
            h_scope: historyScope.value || undefined,
            h_user: historyUser.value || undefined,
            h_state: historyState.value || undefined,
            h_page: undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['cuts', 'historyFilters', 'historyStats'],
        },
    );
}

watch([historyScope, historyUser, historyState], applyHistory);

function clearHistoryFilters() {
    historyScope.value = '';
    historyUser.value = '';
    historyState.value = '';
}

/**
 * Qué compone el efectivo esperado: va en el title de la tarjeta en vez de
 * colgar tres renglones de letra chica debajo de la cifra.
 */
const expectedCashHint = computed(() => {
    const parts = ['Lo que debe haber en el cajón al arquear.'];

    if ((props.preview?.opening_cash ?? 0) > 0) {
        parts.push(
            `Incluye el fondo inicial del turno: ${money(props.preview!.opening_cash)}.`,
        );
    }

    if (
        (props.preview?.guarantees_cash_in ?? 0) > 0 ||
        (props.preview?.guarantees_cash_out ?? 0) > 0
    ) {
        parts.push(
            `Incluye fianzas en garantía: ${money(props.preview!.guarantees_cash_in)} cobradas` +
                (props.preview!.guarantees_cash_out > 0
                    ? ` menos ${money(props.preview!.guarantees_cash_out)} devueltas`
                    : '') +
                ' (no son venta).',
        );
    }

    if ((props.preview?.expenses_total ?? 0) > 0) {
        parts.push(
            `Ya tiene descontados ${money(props.preview!.expenses_total)} de gastos y retiros del turno.`,
        );
    }

    return parts.join(' ');
});

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

// ── Gastos y retiros de la caja ──
// Se capturan DURANTE el turno, no al cortar: a las once de la noche nadie
// se acuerda de los $380 de gasolina de la mañana, y ese hueco salía como
// faltante del encargado.
const expenseCategories: { key: string; label: string }[] = [
    { key: 'insumos', label: 'Insumos y despensa' },
    { key: 'mantenimiento', label: 'Mantenimiento y reparaciones' },
    { key: 'transporte', label: 'Gasolina y transporte' },
    { key: 'servicios', label: 'Servicios y pagos' },
    { key: 'personal', label: 'Personal (adelantos, propinas)' },
    { key: 'retiro', label: 'Retiro a bóveda o depósito' },
    { key: 'otro', label: 'Otro' },
];

const showExpense = ref(false);
const expenseCategory = ref('insumos');
const expenseConcept = ref('');
const expenseAmount = ref<string | number>('');
const expenseReceipt = ref<File | null>(null);
const expenseReceiptInput = ref<HTMLInputElement | null>(null);
const savingExpense = ref(false);

function openExpense() {
    expenseCategory.value = 'insumos';
    expenseConcept.value = '';
    expenseAmount.value = '';
    clearReceipt();
    showExpense.value = true;
}

function pickReceipt(event: Event) {
    expenseReceipt.value =
        (event.target as HTMLInputElement).files?.[0] ?? null;
}

function clearReceipt() {
    expenseReceipt.value = null;
    if (expenseReceiptInput.value) expenseReceiptInput.value.value = '';
}

const expenseValid = computed(
    () =>
        expenseConcept.value.trim().length > 2 &&
        Number(expenseAmount.value) > 0,
);

async function submitExpense() {
    if (!expenseValid.value || savingExpense.value) return;
    savingExpense.value = true;
    try {
        const form = new FormData();
        form.append('user_id', String(props.selectedUser?.id ?? ''));
        form.append('scope', props.filters.scope ?? '');
        if (props.filters.shift)
            form.append('shift_id', String(props.filters.shift));
        form.append('category', expenseCategory.value);
        form.append('concept', expenseConcept.value.trim());
        form.append('amount', String(expenseAmount.value));
        if (expenseReceipt.value) form.append('receipt', expenseReceipt.value);

        await axios.post(route('tenant.cashexpenses.store'), form);
        showExpense.value = false;
        toast.success(
            'Gasto registrado',
            'Ya se descontó del efectivo esperado de esta caja.',
        );
        router.reload();
    } catch (e: any) {
        toast.error(
            'No se pudo registrar',
            e.response?.data?.message ?? 'Revisa el monto y el concepto.',
        );
    } finally {
        savingExpense.value = false;
    }
}

async function deleteExpense(expense: Expense) {
    try {
        await axios.delete(route('tenant.cashexpenses.destroy', expense.id));
        toast.success('Gasto eliminado', 'El efectivo esperado se recalculó.');
        router.reload();
    } catch (e: any) {
        toast.error(
            'No se pudo eliminar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
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
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Calculator" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Cortes de caja</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ property.name }} · la caja de recepción y la del
                            punto de venta se cortan por separado
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2.5"
                >
                    <Button
                        :as="Link"
                        :href="route('tenant.shifts')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="Clock"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.5]"
                        />
                        Turnos
                    </Button>
                    <Button
                        v-if="canManage && preview"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        :disabled="!canCut"
                        @click="showClose = true"
                    >
                        <Lucide
                            icon="ClipboardCheck"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.5]"
                        />
                        Hacer corte
                    </Button>
                </div>
            </div>

            <!-- Qué caja y de quién: ámbito y filtros en una sola tarjeta,
                 antes eran dos bloques separados. -->
            <div class="box box--stacked mt-4">
                <div
                    v-if="scopes.length > 1"
                    class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <span class="text-[11px] font-medium text-slate-400">
                        CAJA
                    </span>
                    <div
                        class="flex gap-1 rounded-[0.6rem] bg-slate-100 p-1 dark:bg-darkmode-400"
                    >
                        <button
                            v-for="s in scopes"
                            :key="s.key"
                            type="button"
                            class="flex h-7 items-center gap-1.5 rounded-[0.45rem] px-3 text-xs transition"
                            :class="
                                s.key === activeScope
                                    ? 'bg-white font-medium text-primary shadow-sm dark:bg-darkmode-600'
                                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                            "
                            @click="setScope(s.key)"
                        >
                            <Lucide
                                :icon="
                                    s.key === 'pos'
                                        ? 'ShoppingCart'
                                        : 'BedDouble'
                                "
                                class="h-3.5 w-3.5"
                            />
                            {{ s.label }}
                        </button>
                    </div>
                    <span
                        class="ml-auto inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 text-[11px] font-medium text-primary"
                    >
                        <Lucide icon="CalendarRange" class="h-3.5 w-3.5" />
                        {{ period.from }} → {{ period.to }}
                    </span>
                </div>

                <div class="bg-slate-50/70 px-4 py-3 dark:bg-darkmode-600/40">
                    <div class="mb-3 flex flex-wrap items-center gap-2.5">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Filter" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-sm font-medium">
                                Qué se va a cortar
                            </div>
                            <div class="text-xs text-slate-500">
                                Elige encargado y turno; el periodo lo define el
                                turno, o pon un rango a mano.
                            </div>
                        </div>
                        <span
                            v-if="scopes.length <= 1"
                            class="ml-auto inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 text-[11px] font-medium text-primary"
                        >
                            <Lucide icon="CalendarRange" class="h-3.5 w-3.5" />
                            {{ period.from }} → {{ period.to }}
                        </span>
                    </div>
                    <!--
                        Las columnas se cuentan según los campos que SÍ están:
                        con la plantilla fija de cinco, un hotel sin turnos
                        dejaba a Desde y Hasta en la pista angosta y la fecha
                        salía cortada ("26/08/20"). Desde/Hasta piden más ancho
                        que un select: llevan fecha Y hora.
                    -->
                    <div
                        class="grid grid-cols-1 gap-3 sm:grid-cols-2"
                        :class="
                            shifts.length
                                ? 'xl:grid-cols-[13rem_13rem_17rem_17rem_auto]'
                                : 'xl:grid-cols-[13rem_17rem_17rem_auto]'
                        "
                    >
                        <div>
                            <FormLabel htmlFor="cut-user">Encargado</FormLabel>
                            <div class="relative">
                                <Lucide
                                    icon="User"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                />
                                <FormSelect
                                    id="cut-user"
                                    v-model="userId"
                                    class="h-9 pl-9 text-xs"
                                >
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
                            <FormLabel htmlFor="cut-shift">Turno</FormLabel>
                            <div class="relative">
                                <Lucide
                                    icon="Clock"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                />
                                <FormSelect
                                    id="cut-shift"
                                    v-model="shiftSel"
                                    class="h-9 pl-9 text-xs"
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
                            <FormLabel htmlFor="cut-from">Desde</FormLabel>
                            <FormDateTime
                                id="cut-from"
                                v-model="from"
                                input-class="h-9 text-xs"
                                :disabled="Boolean(filters.shift)"
                                :title="
                                    filters.shift
                                        ? 'El periodo lo define el turno elegido'
                                        : undefined
                                "
                            />
                        </div>
                        <div>
                            <FormLabel htmlFor="cut-to">Hasta</FormLabel>
                            <FormDateTime
                                id="cut-to"
                                v-model="to"
                                input-class="h-9 text-xs"
                                :disabled="Boolean(filters.shift)"
                                :title="
                                    filters.shift
                                        ? 'El periodo lo define el turno elegido'
                                        : undefined
                                "
                            />
                        </div>
                        <div class="flex items-end">
                            <Button
                                variant="outline-primary"
                                class="h-9 w-full bg-white text-xs whitespace-nowrap xl:w-auto"
                                @click="applyFilters"
                            >
                                <Lucide
                                    icon="RefreshCw"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Calcular
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sin ámbitos disponibles (p. ej. cocina sin módulo POS) -->
            <div
                v-if="!preview"
                class="box box--stacked mt-4 flex flex-col items-center gap-3 px-5 py-12 text-center"
            >
                <div
                    class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                >
                    <Lucide icon="Calculator" class="h-7 w-7" />
                </div>
                <div>
                    <p class="text-sm font-medium">
                        No tienes cajas por cortar
                    </p>
                    <p class="mt-0.5 text-xs text-slate-500">
                        El corte de recepción requiere permiso para ver reservas
                        y el de punto de venta requiere el módulo activo.
                    </p>
                </div>
            </div>

            <template v-if="preview">
                <!-- Cifras del corte en curso -->
                <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                    <div
                        class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Wallet" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium">
                                {{ money(preview.grand_total) }}
                            </div>
                            <div class="truncate text-xs text-slate-500">
                                Total cobrado ·
                                <template v-if="preview.scope === 'pos'">
                                    {{ preview.orders_count }} ventas
                                </template>
                                <template v-else>
                                    {{ preview.payments_count }} cobros
                                </template>
                            </div>
                        </div>
                    </div>
                    <div
                        class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                        :title="expectedCashHint"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                            :class="
                                preview.expected_cash < 0
                                    ? 'border-danger/10 bg-danger/10 text-danger'
                                    : 'border-success/10 bg-success/10 text-success'
                            "
                        >
                            <Lucide icon="Banknote" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div
                                class="truncate text-sm font-medium"
                                :class="
                                    preview.expected_cash < 0
                                        ? 'text-danger'
                                        : 'text-success'
                                "
                            >
                                {{ money(preview.expected_cash) }}
                            </div>
                            <div class="truncate text-xs text-slate-500">
                                <template v-if="preview.expected_cash < 0">
                                    Salió más efectivo del que entró
                                </template>
                                <template v-else>
                                    Efectivo esperado en caja
                                </template>
                            </div>
                            <div
                                v-if="preview.expected_cash < 0"
                                class="truncate text-[11px] text-slate-400"
                            >
                                El periodo no arranca de un fondo de caja
                            </div>
                        </div>
                    </div>
                    <div
                        v-if="preview.scope === 'pos'"
                        class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                        :title="`Venta ${money(preview.orders_total)} menos costo ${money(preview.orders_cost)}`"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                        >
                            <Lucide icon="TrendingUp" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium">
                                {{ money(preview.orders_profit) }}
                            </div>
                            <div class="truncate text-xs text-slate-500">
                                Utilidad del punto de venta
                            </div>
                        </div>
                    </div>
                    <div
                        v-if="preview.scope === 'pos'"
                        class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                        title="Se cobra en el check-out: no es efectivo de esta caja"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                        >
                            <Lucide icon="BedDouble" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium">
                                {{ money(preview.orders_room) }}
                            </div>
                            <div class="truncate text-xs text-slate-500">
                                Cargado a habitación
                            </div>
                        </div>
                    </div>

                    <!-- Recepción tenía solo dos tarjetas, estiradas a media
                         pantalla cada una. Estas dos completan la fila con lo
                         que sí importa en esa caja: el pasivo de fianzas y lo
                         que queda por cobrar. -->
                    <div
                        v-if="preview.scope !== 'pos'"
                        class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                        title="Depósitos en garantía: entran al cajón pero no son venta"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                        >
                            <Lucide icon="ShieldCheck" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium">
                                {{ money(preview.guarantees_cash_in) }}
                            </div>
                            <div class="truncate text-xs text-slate-500">
                                Fianzas cobradas ·
                                {{ preview.guarantees_count }}
                            </div>
                            <div
                                v-if="preview.guarantees_cash_out > 0"
                                class="truncate text-[11px] text-danger"
                            >
                                Devueltas
                                {{ money(preview.guarantees_cash_out) }}
                            </div>
                        </div>
                    </div>
                    <div
                        v-if="preview.scope !== 'pos'"
                        class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                        title="Saldos vivos al momento: no entran al corte, pero es lo que falta por cobrar"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                            :class="
                                preview.pending.count > 0
                                    ? 'border-pending/10 bg-pending/10 text-pending'
                                    : 'border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400'
                            "
                        >
                            <Lucide icon="Hourglass" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium">
                                {{ money(preview.pending.total) }}
                            </div>
                            <div class="truncate text-xs text-slate-500">
                                Por cobrar · {{ preview.pending.count }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Avisos del efectivo esperado: fondo inicial y fianzas.
                     Van juntos en un renglón en vez de colgando de la
                     tarjeta. -->
                <div
                    v-if="
                        preview.opening_cash > 0 ||
                        preview.guarantees_cash_in > 0 ||
                        preview.guarantees_cash_out > 0 ||
                        preview.expenses_total > 0
                    "
                    class="box mt-4 flex flex-wrap items-center gap-x-3 gap-y-1.5 border-l-4 border-l-info px-4 py-2.5 text-xs text-slate-500"
                >
                    <Lucide icon="Info" class="h-4 w-4 shrink-0 text-info" />
                    <span v-if="preview.opening_cash > 0">
                        El efectivo esperado incluye el fondo inicial del turno,
                        <span class="font-medium">
                            {{ money(preview.opening_cash) }}
                        </span>
                        .
                    </span>
                    <span
                        v-if="
                            preview.guarantees_cash_in > 0 ||
                            preview.guarantees_cash_out > 0
                        "
                    >
                        Incluye fianzas en garantía:
                        <span class="font-medium">
                            {{ money(preview.guarantees_cash_in) }}
                        </span>
                        cobradas<template
                            v-if="preview.guarantees_cash_out > 0"
                        >
                            menos
                            <span class="font-medium">
                                {{ money(preview.guarantees_cash_out) }}
                            </span>
                            devueltas</template
                        >, que no son venta.
                    </span>
                    <span v-if="preview.expenses_total > 0">
                        Ya tiene descontados
                        <span class="font-medium text-danger">
                            {{ money(preview.expenses_total) }}
                        </span>
                        de gastos y retiros ({{ preview.expenses_count }}).
                    </span>
                </div>

                <div class="mt-4 grid grid-cols-12 items-start gap-5">
                    <!-- Movimientos del periodo -->
                    <div class="col-span-12 xl:col-span-7">
                        <div class="box box--stacked">
                            <div
                                class="flex flex-wrap items-center gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                            >
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-primary"
                                >
                                    <Lucide
                                        icon="ReceiptText"
                                        class="h-4 w-4"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <div class="font-medium">
                                        Movimientos del periodo
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        Transacción por transacción, en el orden
                                        en que ocurrieron.
                                    </div>
                                </div>
                                <span
                                    class="ml-auto rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                                >
                                    {{ preview.movements.length }}
                                </span>
                            </div>

                            <div
                                v-if="preview.movements.length"
                                class="max-h-[26rem] overflow-auto"
                            >
                                <Table sm hover class="text-xs">
                                    <Table.Thead>
                                        <Table.Tr>
                                            <Table.Th class="whitespace-nowrap">
                                                Hora
                                            </Table.Th>
                                            <Table.Th>Concepto</Table.Th>
                                            <Table.Th class="whitespace-nowrap">
                                                Método
                                            </Table.Th>
                                            <Table.Th
                                                class="text-right whitespace-nowrap"
                                            >
                                                Monto
                                            </Table.Th>
                                        </Table.Tr>
                                    </Table.Thead>
                                    <Table.Tbody>
                                        <Table.Tr
                                            v-for="(m, i) in preview.movements"
                                            :key="i"
                                        >
                                            <Table.Td
                                                class="whitespace-nowrap text-slate-500"
                                            >
                                                {{ m.at }}
                                            </Table.Td>
                                            <Table.Td>
                                                <div
                                                    class="text-sm font-medium"
                                                >
                                                    {{ m.concept }}
                                                </div>
                                                <div
                                                    v-if="m.detail"
                                                    class="text-slate-500"
                                                >
                                                    {{ m.detail }}
                                                </div>
                                            </Table.Td>
                                            <Table.Td
                                                class="whitespace-nowrap text-slate-500"
                                            >
                                                {{ m.method }}
                                            </Table.Td>
                                            <Table.Td class="text-right">
                                                <span
                                                    class="font-semibold whitespace-nowrap"
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

                            <div
                                v-else
                                class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                            >
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                                >
                                    <Lucide
                                        icon="ReceiptText"
                                        class="h-7 w-7"
                                    />
                                </div>
                                <div>
                                    <p class="text-sm font-medium">
                                        Sin movimientos en este periodo
                                    </p>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        Cambia el turno o el rango para ver otro
                                        tramo.
                                    </p>
                                </div>
                            </div>

                            <p
                                v-if="preview.movements.length"
                                class="flex items-center gap-2 border-t border-slate-200/60 px-4 py-2.5 text-xs text-slate-500 dark:border-darkmode-400"
                            >
                                <Lucide
                                    icon="Info"
                                    class="h-3.5 w-3.5 shrink-0"
                                />
                                Los montos en gris no entraron a esta caja:
                                fianzas en garantía o ventas cargadas a
                                habitación que se cobran en el check-out.
                            </p>
                        </div>
                    </div>

                    <!-- Desglose y salidas de la caja -->
                    <div class="col-span-12 xl:col-span-5">
                        <div class="box box--stacked">
                            <div
                                class="flex items-center gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                            >
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-info/10 text-info"
                                >
                                    <Lucide icon="ChartPie" class="h-4 w-4" />
                                </div>
                                <div class="min-w-0">
                                    <div class="font-medium">
                                        Desglose por método
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        Con qué se pagó lo cobrado en el
                                        periodo.
                                    </div>
                                </div>
                            </div>
                            <div class="space-y-3 p-4">
                                <div
                                    v-for="m in preview.methods"
                                    :key="m.key"
                                    class="flex items-center gap-2.5"
                                >
                                    <div
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 dark:bg-darkmode-400"
                                    >
                                        <Lucide
                                            :icon="
                                                methodIcons[m.key] ?? 'Circle'
                                            "
                                            class="h-3.5 w-3.5"
                                        />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="flex items-center justify-between text-xs"
                                        >
                                            <span class="font-medium">
                                                {{ m.label }}
                                            </span>
                                            <span class="font-semibold">
                                                {{ money(m.total) }}
                                            </span>
                                        </div>
                                        <div
                                            class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                                        >
                                            <div
                                                class="h-full rounded-full bg-primary/70 transition-all"
                                                :style="{
                                                    width: `${preview.grand_total > 0 ? (m.total / preview.grand_total) * 100 : 0}%`,
                                                }"
                                            />
                                        </div>
                                    </div>
                                </div>
                                <div
                                    class="flex items-center justify-between border-t border-dashed border-slate-300/70 pt-3 text-xs dark:border-darkmode-400"
                                >
                                    <span class="text-slate-500">
                                        Total del periodo
                                    </span>
                                    <span class="text-sm font-semibold">
                                        {{ money(preview.grand_total) }}
                                    </span>
                                </div>
                            </div>
                            <p
                                class="flex items-start gap-2 border-t border-slate-200/60 px-4 py-2.5 text-xs text-slate-500 dark:border-darkmode-400"
                            >
                                <Lucide
                                    icon="Info"
                                    class="mt-0.5 h-3.5 w-3.5 shrink-0"
                                />
                                <span>
                                    Este corte cuenta lo que
                                    <span class="font-medium">
                                        {{ selectedUser?.name }}
                                    </span>
                                    cobró en la caja de
                                    {{ activeScopeLabel.toLowerCase() }} durante
                                    el periodo. Al guardarlo, el siguiente corte
                                    del mismo ámbito arranca desde aquí.
                                </span>
                            </p>
                        </div>

                        <!-- Gastos y retiros del periodo -->
                        <div class="box box--stacked mt-5">
                            <div
                                class="flex flex-wrap items-center gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                            >
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-danger/10 text-danger"
                                >
                                    <Lucide
                                        icon="ArrowDownLeft"
                                        class="h-4 w-4"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="font-medium">
                                        Gastos y retiros
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        Dinero que salió del cajón en este
                                        periodo.
                                    </div>
                                </div>
                                <Button
                                    v-if="canManage"
                                    variant="outline-secondary"
                                    class="h-8 shrink-0 rounded-[0.5rem] bg-white text-xs"
                                    @click="openExpense"
                                >
                                    <Lucide
                                        icon="Plus"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Registrar gasto
                                </Button>
                            </div>

                            <div
                                v-if="preview.expenses.length"
                                class="divide-y divide-slate-100 dark:divide-darkmode-400/60"
                            >
                                <div
                                    v-for="e in preview.expenses"
                                    :key="e.id"
                                    class="flex items-center gap-3 px-4 py-2.5"
                                >
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="truncate text-sm font-medium"
                                        >
                                            {{ e.concept }}
                                        </div>
                                        <div
                                            class="truncate text-xs text-slate-500"
                                        >
                                            {{ e.category_label }} ·
                                            {{ e.at }}
                                            <template v-if="e.by">
                                                · {{ e.by }}
                                            </template>
                                        </div>
                                    </div>
                                    <span
                                        class="shrink-0 text-sm font-semibold text-danger"
                                    >
                                        −{{ money(e.amount) }}
                                    </span>
                                    <a
                                        v-if="e.receipt_url"
                                        :href="e.receipt_url"
                                        target="_blank"
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-info/10 hover:text-info"
                                        title="Ver comprobante"
                                    >
                                        <Lucide
                                            icon="Paperclip"
                                            class="h-4 w-4"
                                        />
                                    </a>
                                    <button
                                        v-if="canManage && !e.locked"
                                        type="button"
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                        title="Eliminar gasto"
                                        @click="deleteExpense(e)"
                                    >
                                        <Lucide icon="Trash2" class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>

                            <div
                                v-else
                                class="px-4 py-6 text-center text-xs text-slate-500"
                            >
                                Sin salidas de efectivo en este periodo. Si
                                alguien sacó dinero del cajón, regístralo aquí
                                para que el arqueo cuadre.
                            </div>

                            <div
                                v-if="preview.expenses.length"
                                class="border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                            >
                                <div
                                    v-for="c in preview.expenses_by_category"
                                    :key="c.key"
                                    class="flex items-center justify-between text-xs text-slate-500"
                                >
                                    <span>{{ c.label }} ({{ c.count }})</span>
                                    <span>{{ money(c.total) }}</span>
                                </div>
                                <div
                                    class="mt-2 flex items-center justify-between border-t border-dashed border-slate-300/70 pt-2 text-xs dark:border-darkmode-400"
                                >
                                    <span class="text-slate-500">
                                        Total que salió
                                    </span>
                                    <span
                                        class="text-sm font-semibold text-danger"
                                    >
                                        −{{ money(preview.expenses_total) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Pagos pendientes: bloque alto, a ancho completo para no
                 estirar la columna vecina y dejar un hueco. -->
                <div class="box box--stacked mt-4">
                    <div
                        class="flex flex-wrap items-center gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-full"
                            :class="
                                preview.pending.count > 0
                                    ? 'bg-warning/10 text-warning'
                                    : 'bg-success/10 text-success'
                            "
                        >
                            <Lucide
                                :icon="
                                    preview.pending.count > 0
                                        ? 'CircleAlert'
                                        : 'CircleCheck'
                                "
                                class="h-4 w-4"
                            />
                        </div>
                        <div class="min-w-0">
                            <div class="font-medium">Pagos pendientes</div>
                            <div class="text-xs text-slate-500">
                                Saldos vivos que hereda el relevo.
                            </div>
                        </div>
                        <span
                            v-if="preview.pending.count > 0"
                            class="ml-auto rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                        >
                            {{ preview.pending.count }} ·
                            {{ money(preview.pending.total) }}
                        </span>
                    </div>

                    <template v-if="preview.pending.items.length">
                        <div
                            class="max-h-72 divide-y divide-slate-200/60 overflow-y-auto dark:divide-darkmode-400"
                        >
                            <div
                                v-for="(p, i) in preview.pending.items"
                                :key="i"
                                class="flex items-center justify-between gap-3 px-4 py-2.5"
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
                                            class="h-3.5 w-3.5"
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
                                <span
                                    class="text-sm font-semibold whitespace-nowrap"
                                >
                                    {{ money(p.amount) }}
                                </span>
                            </div>
                        </div>
                        <div
                            class="flex items-center justify-between border-t border-slate-200/60 px-4 py-2.5 text-xs dark:border-darkmode-400"
                        >
                            <span class="text-slate-500">
                                Total pendiente de cobro
                            </span>
                            <span class="text-sm font-semibold text-warning">
                                {{ money(preview.pending.total) }}
                            </span>
                        </div>
                        <p
                            class="flex items-start gap-2 border-t border-slate-200/60 px-4 py-2.5 text-xs text-slate-500 dark:border-darkmode-400"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0"
                            />
                            <span>
                                Huéspedes en casa con saldo, reservas con pago
                                vencido y ventas cargadas a habitación. Al
                                guardar el corte quedan congelados para el
                                relevo de turno.
                            </span>
                        </p>
                    </template>

                    <div
                        v-else
                        class="flex items-center gap-2.5 px-4 py-6 text-xs text-slate-500"
                    >
                        <span
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                        >
                            <Lucide icon="CircleCheck" class="h-4 w-4" />
                        </span>
                        Sin pagos pendientes al momento del corte.
                    </div>
                </div>
            </template>

            <!-- Historial de cortes -->
            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <Lucide
                            icon="ClipboardList"
                            class="h-4 w-4 text-slate-400"
                        />
                        Cortes guardados
                        <span
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-darkmode-400"
                        >
                            {{ cuts.total }}
                        </span>
                    </div>
                    <div
                        class="ml-auto flex flex-wrap items-center gap-2 text-xs text-slate-500"
                    >
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 font-medium dark:bg-darkmode-400"
                            title="Suma de los cortes que cumplen el filtro"
                        >
                            <Lucide icon="Wallet" class="h-3.5 w-3.5" />
                            {{ money(historyStats.total) }}
                        </span>
                        <span
                            v-if="historyStats.off > 0"
                            class="inline-flex items-center gap-1.5 rounded-full bg-danger/10 px-2.5 py-1 font-medium text-danger"
                            title="Cortes en los que el arqueo no cuadró"
                        >
                            <Lucide icon="CircleAlert" class="h-3.5 w-3.5" />
                            {{ historyStats.off }} sin cuadrar
                        </span>
                        <span
                            v-if="historyStats.without_count > 0"
                            class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-500 dark:bg-darkmode-400"
                            title="Cortes guardados sin contar el efectivo"
                        >
                            {{ historyStats.without_count }} sin arqueo
                        </span>
                        <span v-if="cuts.data.length">
                            Mostrando {{ cuts.from }}-{{ cuts.to }} de
                            {{ cuts.total }}
                        </span>
                        <button
                            v-if="historyFiltersActive"
                            type="button"
                            class="font-medium text-primary hover:underline"
                            @click="clearHistoryFilters"
                        >
                            Limpiar filtros
                        </button>
                    </div>
                </div>

                <div
                    class="border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="mb-3 flex items-center gap-2.5">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                        >
                            <Lucide icon="Filter" class="h-4 w-4" />
                        </div>
                        <div>
                            <div class="text-sm font-medium">
                                Busca un corte guardado
                            </div>
                            <div class="text-xs text-slate-500">
                                Independiente del corte en curso: aquí se revisa
                                lo que ya se cerró.
                            </div>
                        </div>
                    </div>
                    <div
                        class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-[14rem_14rem_14rem_auto]"
                    >
                        <div>
                            <FormLabel htmlFor="history-scope">Caja</FormLabel>
                            <FormSelect
                                id="history-scope"
                                v-model="historyScope"
                                class="h-9 text-xs"
                            >
                                <option value="">Todas las cajas</option>
                                <option value="rooms">Recepción</option>
                                <option value="pos">Punto de venta</option>
                                <option value="all">Combinado (legacy)</option>
                            </FormSelect>
                        </div>
                        <div>
                            <FormLabel htmlFor="history-user">
                                Encargado
                            </FormLabel>
                            <FormSelect
                                id="history-user"
                                v-model="historyUser"
                                class="h-9 text-xs"
                            >
                                <option value="">Todos</option>
                                <option
                                    v-for="s in staff"
                                    :key="s.id"
                                    :value="s.id"
                                >
                                    {{ s.name }}
                                </option>
                            </FormSelect>
                        </div>
                        <div>
                            <FormLabel htmlFor="history-state">
                                Arqueo
                            </FormLabel>
                            <FormSelect
                                id="history-state"
                                v-model="historyState"
                                class="h-9 text-xs"
                            >
                                <option value="">Todos</option>
                                <option value="diff">Los que no cuadran</option>
                                <option value="sin-arqueo">
                                    Guardados sin contar
                                </option>
                            </FormSelect>
                        </div>
                        <div class="flex items-end">
                            <Button
                                v-if="historyFiltersActive"
                                type="button"
                                variant="outline-secondary"
                                class="h-9 w-full text-xs whitespace-nowrap xl:w-auto"
                                @click="clearHistoryFilters"
                            >
                                <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                                Limpiar
                            </Button>
                        </div>
                    </div>
                </div>

                <div v-if="cuts.data.length" class="overflow-auto">
                    <Table sm hover class="text-xs">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th class="whitespace-nowrap">
                                    Encargado
                                </Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Caja
                                </Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Cerrado
                                </Table.Th>
                                <Table.Th class="text-right whitespace-nowrap">
                                    Total
                                </Table.Th>
                                <Table.Th class="text-right whitespace-nowrap">
                                    Efectivo
                                </Table.Th>
                                <Table.Th class="text-right whitespace-nowrap">
                                    Arqueo
                                </Table.Th>
                                <Table.Th class="text-right whitespace-nowrap">
                                    Acciones
                                </Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="c in cuts.data" :key="c.id">
                                <Table.Td class="whitespace-nowrap">
                                    <div class="text-sm font-medium">
                                        {{ c.user }}
                                    </div>
                                    <div
                                        v-if="c.by && c.by !== c.user"
                                        class="text-slate-500"
                                    >
                                        Cerrado por {{ c.by }}
                                    </div>
                                </Table.Td>
                                <Table.Td class="whitespace-nowrap">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="scopeBadge(c.scope)"
                                    >
                                        {{ c.scope_label }}
                                    </span>
                                </Table.Td>
                                <Table.Td
                                    class="whitespace-nowrap text-slate-500"
                                >
                                    {{ c.closed_at }}
                                </Table.Td>
                                <Table.Td
                                    class="text-right font-semibold whitespace-nowrap"
                                >
                                    {{ money(c.grand_total) }}
                                </Table.Td>
                                <Table.Td
                                    class="text-right whitespace-nowrap text-slate-500"
                                >
                                    esp. {{ money(c.expected_cash) }}
                                    <template v-if="c.counted_cash !== null">
                                        · cont. {{ money(c.counted_cash) }}
                                    </template>
                                </Table.Td>
                                <Table.Td class="text-right whitespace-nowrap">
                                    <span
                                        v-if="c.counted_cash !== null"
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
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
                                    <span v-else class="text-slate-400">
                                        Sin arqueo
                                    </span>
                                </Table.Td>
                                <Table.Td class="text-right whitespace-nowrap">
                                    <div
                                        class="flex items-center justify-end gap-1.5"
                                    >
                                        <Button
                                            variant="outline-primary"
                                            class="h-8 rounded-[0.5rem] text-xs"
                                            @click="openDetail(c)"
                                        >
                                            <Lucide
                                                icon="Eye"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            Ver
                                        </Button>
                                        <a
                                            :href="
                                                route('tenant.cashcuts.pdf', {
                                                    cashCut: c.id,
                                                })
                                            "
                                            title="Descargar PDF para firma"
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-primary dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                                        >
                                            <Lucide
                                                icon="Download"
                                                class="h-3.5 w-3.5"
                                            />
                                        </a>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </div>

                <div
                    v-else-if="historyFiltersActive"
                    class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="SearchX" class="h-7 w-7" />
                    </div>
                    <div>
                        <p class="text-sm font-medium">
                            Ningún corte coincide con los filtros
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cambia la caja, el encargado o el estado del arqueo.
                        </p>
                    </div>
                    <Button
                        variant="outline-secondary"
                        @click="clearHistoryFilters"
                    >
                        <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                        Limpiar filtros
                    </Button>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="ClipboardList" class="h-7 w-7" />
                    </div>
                    <div>
                        <p class="text-sm font-medium">
                            Aún no hay cortes guardados
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Calcula un periodo y usa "Hacer corte" para dejar el
                            arqueo asentado.
                        </p>
                    </div>
                </div>

                <!-- Paginación: quince cortes por vuelta -->
                <div
                    v-if="cuts.links.length > 3"
                    class="flex flex-wrap justify-center gap-1 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <template v-for="(link, i) in cuts.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-state
                            preserve-scroll
                            class="rounded-md px-2.5 py-1 text-xs"
                            :class="
                                link.active
                                    ? 'bg-primary text-white'
                                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-darkmode-400'
                            "
                        >
                            <span v-html="link.label" />
                        </Link>
                        <span
                            v-else
                            class="px-2.5 py-1 text-xs text-slate-400"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>

        <!-- Modal registrar gasto de caja -->
        <Dialog size="lg" :open="showExpense" @close="showExpense = false">
            <Dialog.Panel class="sm:w-[94vw] lg:w-[560px]">
                <form
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                    @submit.prevent="submitExpense"
                >
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"
                        >
                            <Lucide icon="ArrowDownLeft" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                Registrar una salida de efectivo
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Caja de {{ activeScopeLabel.toLowerCase() }} ·
                                {{ selectedUser?.name }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showExpense = false"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>

                    <div
                        class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4"
                    >
                        <div>
                            <div
                                class="mb-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Qué se pagó
                            </div>
                            <div class="grid grid-cols-12 gap-4">
                                <div class="col-span-12 sm:col-span-7">
                                    <FormLabel class="text-xs">
                                        Concepto
                                    </FormLabel>
                                    <FormInput
                                        v-model="expenseConcept"
                                        type="text"
                                        maxlength="160"
                                        class="h-9 text-xs"
                                        placeholder="Gasolina de la camioneta"
                                    />
                                </div>
                                <div class="col-span-12 sm:col-span-5">
                                    <FormLabel class="text-xs">Monto</FormLabel>
                                    <FormInput
                                        v-model="expenseAmount"
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        class="h-9 text-xs"
                                        placeholder="0.00"
                                    />
                                </div>
                                <div class="col-span-12">
                                    <FormLabel class="text-xs">
                                        Categoría
                                    </FormLabel>
                                    <FormSelect
                                        v-model="expenseCategory"
                                        class="h-9 text-xs"
                                    >
                                        <option
                                            v-for="c in expenseCategories"
                                            :key="c.key"
                                            :value="c.key"
                                        >
                                            {{ c.label }}
                                        </option>
                                    </FormSelect>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div
                                class="mb-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Comprobante
                            </div>
                            <div class="flex items-center gap-3">
                                <input
                                    ref="expenseReceiptInput"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,application/pdf"
                                    class="h-9 flex-1 text-xs file:mr-3 file:h-9 file:rounded-[0.5rem] file:border-0 file:bg-slate-100 file:px-3 file:text-xs file:text-slate-600 dark:file:bg-darkmode-400"
                                    @change="pickReceipt"
                                />
                                <button
                                    v-if="expenseReceipt"
                                    type="button"
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                    title="Quitar archivo"
                                    @click="clearReceipt"
                                >
                                    <Lucide icon="X" class="h-4 w-4" />
                                </button>
                            </div>
                            <FormHelp class="text-xs">
                                Opcional: la foto del ticket es lo que sostiene
                                el gasto cuando alguien revisa el corte días
                                después.
                            </FormHelp>
                        </div>

                        <p
                            class="flex items-start gap-2 rounded-lg bg-slate-50 px-3.5 py-2.5 text-xs text-slate-500 dark:bg-darkmode-700"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0"
                            />
                            <span>
                                Se descuenta del efectivo esperado de esta caja.
                                Mientras el corte no se cierre lo puedes
                                eliminar; después queda como respaldo del
                                arqueo.
                            </span>
                        </p>
                    </div>

                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] bg-white px-5 text-xs"
                            @click="showExpense = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="!expenseValid || savingExpense"
                        >
                            {{
                                savingExpense ? 'Guardando…' : 'Registrar gasto'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal hacer corte (arqueo) -->
        <Dialog size="lg" :open="showClose" @close="showClose = false">
            <Dialog.Panel>
                <div v-if="preview" class="p-5">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
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
                        <label
                            class="mb-1 block text-xs font-medium text-slate-500"
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
                        <label
                            class="mb-1 block text-xs font-medium text-slate-500"
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
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
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
                                    class="mt-1 text-sm font-semibold text-primary"
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
                                <div class="mt-1 text-sm font-semibold">
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
                                    v-if="detailCut.expenses_count > 0"
                                    class="mb-2 flex items-center justify-between text-sm"
                                >
                                    <span class="text-slate-500">
                                        Gastos y retiros ({{
                                            detailCut.expenses_count
                                        }})
                                    </span>
                                    <span class="text-danger">
                                        −{{ money(detailCut.expenses_total) }}
                                    </span>
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
                        class="flex items-center justify-between border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
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
