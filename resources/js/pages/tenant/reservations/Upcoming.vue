<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormDate, FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog, Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';
import CancelReservationDialog from './board/CancelReservationDialog.vue';
import type { CancelTarget } from './board/CancelReservationDialog.vue';
import { channelLabel } from './board/chartColors';
import ConfirmReservationDialog from './board/ConfirmReservationDialog.vue';
import type { ConfirmTarget } from './board/ConfirmReservationDialog.vue';
import FilterChips from './board/FilterChips.vue';
import {
    arrivalUrgency,
    groupByArrivalDay,
    urgencyBorder,
} from './board/grouping';
import GroupRow from './board/GroupRow.vue';
import GuestCell from './board/GuestCell.vue';
import KpiStrip from './board/KpiStrip.vue';
import type { KpiItem } from './board/KpiStrip.vue';
import ListPager from './board/ListPager.vue';
import { paymentMeta } from './board/meta';
import PayMeter from './board/PayMeter.vue';
import ScreenGuide from './board/ScreenGuide.vue';
import { useScreenGuide } from './board/useScreenGuide';
import ReservationsNav from './ReservationsNav.vue';

interface PriceLine {
    concept: string;
    amount: number;
}

interface FrozenLine {
    name: string;
    qty: number;
    total: number;
}

interface ExperienceLine {
    name: string;
    starts_at: string;
    people: number;
    total: number;
}

interface UpcomingRow {
    id: number;
    code: string;
    guest_name: string | null;
    guest_phone: string | null;
    guest_email: string | null;
    num_people: number;
    room: string | null;
    room_type: string | null;
    rate_plan: string | null;
    starts_at: string;
    ends_at: string;
    starts_today: boolean;
    arrival_pending: boolean;
    /** Fechas en palabras ("Hoy 15:37", "2 noches") y el día del grupo. */
    starts_label: string;
    ends_label: string;
    span_label: string | null;
    day_key: string;
    day_label: string;
    hold_state: 'expired' | 'urgent' | 'live' | null;
    hold_countdown: string | null;
    eta: string | null;
    status: string;
    status_label: string;
    total_amount: string;
    extra_charges: PriceLine[];
    products: FrozenLine[];
    extras: FrozenLine[];
    experiences: ExperienceLine[];
    source_channel: string;
    notes: string | null;
    guest_notes: string | null;
    deposit_amount: string;
    payment_status: string;
    payment_status_label: string;
    payment_due_at: string | null;
    payment_overdue: boolean;
    pending_transfer_request: boolean;
    hold_expires_at: string | null;
    paid_total: number;
    pending_balance: number;
    updated_at: string | null;
    timeline: {
        id: string;
        message: string;
        by: string | null;
        at: string | null;
    }[];
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

type When = '' | 'hoy' | 'manana' | 'semana';
type PaymentKey =
    | ''
    | 'sin_pago'
    | 'parcial'
    | 'anticipo'
    | 'pagada'
    | 'vencido';

const props = defineProps<{
    property: { id: number; name: string };
    reservations: {
        data: UpcomingRow[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    /** Cifras de TODO lo apartado, no de la página ni del filtro. */
    summary: {
        total: number;
        today: number;
        tomorrow: number;
        pending: number;
        overdue_payments: number;
        balance: number;
        balance_label: string;
        /** Llegadas por día (14 días): cuenta de cada grupo de la tabla. */
        days: { date: string; label: string; count: number }[];
    };
    filters: {
        q: string;
        status: string;
        date: string;
        date_label: string;
        cuando: When;
        pago: PaymentKey;
    };
    statusOptions: { value: string; label: string }[];
    canManage: boolean;
    manualCheckinAllowed: boolean;
    holdMinutes: number;
}>();

const guide = useScreenGuide('proximas');

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n || 0);

const holdLabel = computed(() =>
    props.holdMinutes >= 60 && props.holdMinutes % 60 === 0
        ? `${props.holdMinutes / 60} h`
        : `${props.holdMinutes} min`,
);

// ── Buscador y filtros (en el servidor, con debounce) ──
const q = ref(props.filters.q);
const status = ref(props.filters.status);
// Día de llegada: llega marcado desde el pulso de la semana del tablero o
// desde una barra de la gráfica.
const date = ref(props.filters.date);
const cuando = ref<When>(props.filters.cuando);
const pago = ref<PaymentKey>(props.filters.pago);

let timer: ReturnType<typeof setTimeout> | null = null;
watch([q, status, date, cuando, pago], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            route('tenant.reservations.upcoming'),
            {
                q: q.value || undefined,
                status: status.value || undefined,
                date: date.value || undefined,
                cuando: date.value ? undefined : cuando.value || undefined,
                pago: pago.value || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['reservations', 'filters'],
            },
        );
    }, 300);
});

// Un día exacto y un atajo ("esta semana") no se suman: el día manda.
watch(date, (value) => {
    if (value) cuando.value = '';
});
watch(cuando, (value) => {
    if (value) date.value = '';
});

const filtersActive = computed(
    () =>
        !!(q.value || status.value || date.value || cuando.value || pago.value),
);

function clearFilters() {
    q.value = '';
    status.value = '';
    date.value = '';
    cuando.value = '';
    pago.value = '';
}

function scrollToList() {
    document
        .getElementById('lista-proximas')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function focusWhen(value: When) {
    cuando.value = value;
    scrollToList();
}

function focusPayment(key: string) {
    pago.value = key as PaymentKey;
    scrollToList();
}

function focusStatus(value: string) {
    status.value = value;
    scrollToList();
}

// La franja de cifras: cada una filtra la lista (las gráficas viven en
// /reservas/proximas/reporte).
const activeKpi = computed(() =>
    cuando.value === 'hoy'
        ? 'today'
        : status.value === 'pending'
          ? 'pending'
          : pago.value
            ? 'balance'
            : '',
);
const kpis = computed<KpiItem[]>(() => [
    {
        key: 'total',
        icon: 'CalendarDays',
        tone: 'primary',
        value: props.summary.total,
        label: props.summary.total === 1 ? 'apartada' : 'apartadas',
        hint: 'De hoy en adelante',
    },
    {
        key: 'today',
        icon: 'LogIn',
        tone: 'info',
        value: props.summary.today,
        label: 'llegan hoy',
        hint: props.summary.tomorrow
            ? `${props.summary.tomorrow} mañana`
            : 'Nadie mañana',
    },
    {
        key: 'pending',
        icon: 'AlarmClock',
        tone: 'pending',
        value: props.summary.pending,
        label: 'sin confirmar',
        hint: `Se liberan solas a ${holdLabel.value}`,
    },
    {
        key: 'balance',
        icon: 'Banknote',
        tone: props.summary.overdue_payments ? 'danger' : 'pending',
        value: props.summary.balance_label,
        label: 'por cobrar',
        hint: props.summary.overdue_payments
            ? `${props.summary.overdue_payments} con pago vencido`
            : 'Sin contar fianzas',
        alert: props.summary.overdue_payments > 0,
    },
]);

function onKpi(key: string) {
    if (key === 'total') {
        clearFilters();
        scrollToList();
    } else if (key === 'today') focusWhen('hoy');
    else if (key === 'pending') focusStatus('pending');
    else focusPayment(props.summary.overdue_payments ? 'vencido' : 'sin_pago');
}

// La tabla agrupada por día de llegada: la cuenta de cada grupo es de TODO
// lo apartado ese día (summary.days), no de la página.
const dayCounts = computed(() =>
    Object.fromEntries(props.summary.days.map((d) => [d.date, d.count])),
);
const groups = computed(() =>
    groupByArrivalDay(props.reservations.data, dayCounts.value),
);

const whenChips = computed(() => [
    { key: '', label: 'Todas' },
    {
        key: 'hoy',
        label: 'Llegan hoy',
        count: props.summary.today,
        tone: 'info' as const,
    },
    { key: 'manana', label: 'Mañana', count: props.summary.tomorrow },
    { key: 'semana', label: 'Próximos 7 días' },
]);

// Mismo semáforo de estados que /reservas.
const statusMeta: Record<string, { class: string; icon: Icon }> = {
    pending: { class: 'bg-warning/10 text-warning', icon: 'Clock' },
    confirmed: { class: 'bg-primary/10 text-primary', icon: 'CircleCheck' },
};
const statusFor = (s: string) =>
    statusMeta[s] ?? {
        class: 'bg-slate-100 text-slate-600',
        icon: 'CircleHelp' as Icon,
    };

const tableHead =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
// Mismo ancho para el botón principal en todos los renglones: la columna
// de acciones queda alineada aunque uno diga "Confirmar" y otro "Cobrar".
const mainButton =
    'h-8 w-[6.75rem] justify-center rounded-[0.5rem] text-xs whitespace-nowrap';
const rowAction =
    'flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary';

function paymentBadge(r: UpcomingRow): string {
    if (r.payment_overdue) return 'bg-danger/10 text-danger';
    if (r.payment_status === 'paid') return 'bg-success/10 text-success';
    // Abonó algo pero no llegó al anticipo: que se note, no "sin pago".
    if (r.payment_status === 'partial') return 'bg-pending/10 text-pending';
    if (r.payment_status === 'deposit_paid') return 'bg-info/10 text-info';
    return 'bg-slate-100 text-slate-500 dark:bg-darkmode-400';
}

// ── A dónde lleva cada acción ──
const detailHref = (r: UpcomingRow) => `/reservas/${r.id}`;
const checkInHref = (r: UpcomingRow) => `/reservas/${r.id}?checkin=1`;
const payHref = (r: UpcomingRow) => `/reservas/${r.id}?pagar=1`;
const editHref = (r: UpcomingRow) =>
    `${route('tenant.reservations.operation')}?edit=${r.id}`;

/** Recibir solo tiene sentido el día que llega (o si ya se le pasó). */
const canReceive = (r: UpcomingRow) =>
    props.manualCheckinAllowed &&
    r.status === 'confirmed' &&
    (r.starts_today || r.arrival_pending);

/**
 * La acción que toca, una sola por renglón y con nombre: antes el renglón
 * tenía un ojo y un ícono de "abrir en otra pantalla", y en la capacitación
 * nadie sabía que ahí se confirmaba o se cobraba.
 */
const mainAction = (r: UpcomingRow): 'confirm' | 'receive' | 'pay' | null =>
    r.status === 'pending'
        ? 'confirm'
        : canReceive(r)
          ? 'receive'
          : r.pending_balance > 0
            ? 'pay'
            : null;

// ── Detalle y acciones en modal ──
const detail = ref<UpcomingRow | null>(null);
const confirmTarget = ref<ConfirmTarget | null>(null);
const cancelTarget = ref<CancelTarget | null>(null);
const cancelKind = ref<'cancel' | 'no_show'>('cancel');

function askConfirm(r: UpcomingRow) {
    detail.value = null;
    confirmTarget.value = r;
}

function askCancel(r: UpcomingRow, kind: 'cancel' | 'no_show') {
    detail.value = null;
    cancelKind.value = kind;
    cancelTarget.value = r;
}

const detailLines = computed(() => {
    if (!detail.value) return [];
    const r = detail.value;
    return [
        ...r.products.map((l) => ({
            key: `p-${l.name}`,
            label: `${l.qty} × ${l.name}`,
            amount: l.total,
        })),
        ...r.extras.map((l) => ({
            key: `e-${l.name}`,
            label: `${l.qty} × ${l.name}`,
            amount: l.total,
        })),
        ...r.experiences.map((l) => ({
            key: `x-${l.name}`,
            label: `${l.name} (${l.people} pers.)`,
            amount: l.total,
        })),
        ...r.extra_charges.map((l) => ({
            key: `c-${l.concept}`,
            label: l.concept,
            amount: l.amount,
        })),
    ];
});

const guideSteps = [
    {
        icon: 'CalendarDays' as Icon,
        title: 'Lee la lista por día',
        text: 'Las reservas van agrupadas por día de llegada: "Hoy", "Mañana" y los que siguen. La raya roja a la izquierda es lo atrasado; la ámbar, lo de hoy.',
    },
    {
        icon: 'MousePointerClick' as Icon,
        title: 'Usa el botón del renglón',
        text: 'Cada reserva trae su siguiente paso: Confirmar si está apartada, Recibir el día que llega, Cobrar si debe. La barrita verde dice cuánto ya pagó.',
    },
    {
        icon: 'Ellipsis' as Icon,
        title: 'Lo demás, en los tres puntos',
        text: 'Editar, abrir la ficha, "no llegó" o cancelar. Las cifras de arriba filtran la lista; las gráficas están en "Ver reporte".',
    },
];
</script>

<template>
    <RazeLayout title="Próximas reservas">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="CalendarDays" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Próximas reservas</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ property.name }} · {{ summary.total }}
                            {{ summary.total === 1 ? 'apartada' : 'apartadas' }}
                            de hoy en adelante: confirma, cobra y recibe
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Link
                        :href="route('tenant.reservations')"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        <span class="sm:hidden">Volver</span>
                        <span class="hidden sm:inline">Volver a reservas</span>
                    </Link>
                    <button
                        type="button"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-info/30 hover:text-info dark:border-darkmode-400 dark:bg-darkmode-600"
                        :class="
                            guide.open.value ? 'border-info/30 text-info' : ''
                        "
                        @click="guide.toggle()"
                    >
                        <Lucide icon="Lightbulb" class="h-3.5 w-3.5" />
                        Cómo se usa
                    </button>
                    <Button
                        :as="Link"
                        :href="route('tenant.reservations.upcoming.report')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="ChartColumn"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.3]"
                        />
                        Ver reporte
                    </Button>
                    <Button
                        :as="Link"
                        :href="route('tenant.reservations.calendar')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="CalendarRange"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.3]"
                        />
                        Calendario
                    </Button>
                    <Button
                        v-if="canManage"
                        :as="Link"
                        :href="`${route('tenant.reservations.operation')}?intent=reserve`"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Nueva reserva
                    </Button>
                </div>
            </div>

            <ReservationsNav current="upcoming" />

            <ScreenGuide
                v-if="guide.open.value"
                purpose="Todo lo que ya está apartado de hoy en adelante, agrupado por día de llegada. Aquí se confirma, se cobra el anticipo y se recibe al huésped el día que llega."
                :steps="guideSteps"
                @close="guide.close()"
            />

            <KpiStrip :items="kpis" :active="activeKpi" @select="onKpi" />

            <div id="lista-proximas" class="box box--stacked mt-4 scroll-mt-4">
                <!-- Filtros, en franja gris dentro del mismo box -->
                <div
                    class="space-y-2.5 border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700/40"
                >
                    <div
                        class="flex flex-col gap-2.5 sm:flex-row sm:flex-wrap sm:items-center"
                    >
                        <div class="relative w-full min-w-0 sm:w-72">
                            <Lucide
                                icon="Search"
                                class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                            />
                            <FormInput
                                v-model="q"
                                type="search"
                                placeholder="Huésped, teléfono, folio o habitación"
                                class="h-9 pl-9 text-xs"
                            />
                        </div>
                        <FormSelect
                            v-model="status"
                            class="h-9 w-full text-xs sm:w-44"
                            aria-label="Estado de la reserva"
                        >
                            <option value="">Todos los estados</option>
                            <option
                                v-for="option in statusOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </FormSelect>
                        <FormSelect
                            v-model="pago"
                            class="h-9 w-full text-xs sm:w-44"
                            aria-label="Cómo viene pagada"
                        >
                            <option value="">Cualquier pago</option>
                            <option
                                v-for="(meta, key) in paymentMeta"
                                :key="key"
                                :value="key"
                            >
                                {{ meta.label }}
                            </option>
                        </FormSelect>
                        <div class="w-full sm:w-40">
                            <FormDate
                                v-model="date"
                                input-class="h-9 text-xs"
                                placeholder="Un día exacto"
                            />
                        </div>
                        <button
                            v-if="filtersActive"
                            type="button"
                            class="text-xs font-medium text-primary hover:underline sm:ml-auto"
                            @click="clearFilters"
                        >
                            Limpiar filtros
                        </button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <FilterChips
                            v-model="cuando"
                            :options="whenChips"
                            label="Llegada"
                        />
                        <span
                            v-if="date"
                            class="inline-flex h-8 items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-3 text-xs font-medium text-primary"
                        >
                            <Lucide icon="CalendarDays" class="h-3.5 w-3.5" />
                            {{ filters.date_label || date }}
                            <button
                                type="button"
                                class="ml-0.5 rounded-full hover:text-danger"
                                title="Quitar el día"
                                @click="date = ''"
                            >
                                <Lucide icon="X" class="h-3.5 w-3.5" />
                            </button>
                        </span>
                    </div>
                </div>

                <!-- Escritorio: tabla agrupada por día de llegada -->
                <div
                    v-if="reservations.data.length"
                    class="hidden overflow-auto lg:block lg:overflow-visible"
                >
                    <Table hover>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th :class="[tableHead, 'pl-5']"
                                    >Huésped</Table.Th
                                >
                                <Table.Th :class="tableHead"
                                    >Habitación</Table.Th
                                >
                                <Table.Th :class="tableHead">Estancia</Table.Th>
                                <Table.Th :class="[tableHead, 'text-right']"
                                    >Cobro</Table.Th
                                >
                                <Table.Th :class="tableHead">Estado</Table.Th>
                                <Table.Th :class="[tableHead, 'text-right']"
                                    >Acciones</Table.Th
                                >
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <template v-for="group in groups" :key="group.key">
                                <GroupRow
                                    :label="group.label"
                                    :count="group.count"
                                    :tone="group.tone"
                                    :colspan="6"
                                />
                                <Table.Tr
                                    v-for="r in group.rows"
                                    :key="r.id"
                                    class="align-middle"
                                >
                                    <Table.Td
                                        class="max-w-[20rem] py-3"
                                        :class="
                                            urgencyBorder(arrivalUrgency(r))
                                        "
                                    >
                                        <GuestCell
                                            :name="r.guest_name"
                                            :meta="[
                                                r.code,
                                                channelLabel(r.source_channel),
                                            ]"
                                            :phone="r.guest_phone"
                                            @open="detail = r"
                                        />
                                    </Table.Td>
                                    <Table.Td class="py-3 whitespace-nowrap">
                                        <div class="text-sm font-medium">
                                            {{ r.room ?? 'Sin asignar' }}
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            {{ r.room_type }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td class="py-3 whitespace-nowrap">
                                        <div
                                            class="text-xs font-medium tabular-nums"
                                        >
                                            {{ r.starts_label }}
                                            <span
                                                class="font-normal text-slate-400"
                                                >→</span
                                            >
                                            {{ r.ends_label }}
                                        </div>
                                        <div
                                            class="mt-0.5 text-xs text-slate-500"
                                        >
                                            {{ r.span_label }} ·
                                            {{ r.num_people }}
                                            {{
                                                r.num_people === 1
                                                    ? 'persona'
                                                    : 'personas'
                                            }}
                                            <template v-if="r.eta">
                                                · llega {{ r.eta }}</template
                                            >
                                        </div>
                                    </Table.Td>
                                    <Table.Td class="py-3">
                                        <PayMeter
                                            :total="Number(r.total_amount)"
                                            :paid="r.paid_total"
                                            :balance="r.pending_balance"
                                            :overdue="r.payment_overdue"
                                        />
                                    </Table.Td>
                                    <Table.Td class="py-3 whitespace-nowrap">
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                            :class="statusFor(r.status).class"
                                        >
                                            <Lucide
                                                :icon="statusFor(r.status).icon"
                                                class="h-3 w-3"
                                            />
                                            {{
                                                r.arrival_pending
                                                    ? 'Sin llegada'
                                                    : r.status_label
                                            }}
                                        </span>
                                        <div
                                            v-if="r.hold_countdown"
                                            class="mt-0.5 text-[11px]"
                                            :class="
                                                r.hold_state === 'expired'
                                                    ? 'text-danger'
                                                    : r.hold_state === 'urgent'
                                                      ? 'text-warning'
                                                      : 'text-slate-400'
                                            "
                                        >
                                            {{
                                                r.hold_state === 'expired'
                                                    ? r.hold_countdown
                                                    : `se libera ${r.hold_countdown}`
                                            }}
                                        </div>
                                        <div
                                            v-else-if="
                                                r.pending_transfer_request
                                            "
                                            class="mt-0.5 text-[11px] text-pending"
                                        >
                                            Transferencia por verificar
                                        </div>
                                    </Table.Td>
                                    <Table.Td class="py-3">
                                        <div
                                            class="flex items-center justify-end gap-1.5"
                                        >
                                            <template v-if="canManage">
                                                <Button
                                                    v-if="
                                                        mainAction(r) ===
                                                        'confirm'
                                                    "
                                                    variant="primary"
                                                    :class="mainButton"
                                                    @click="askConfirm(r)"
                                                >
                                                    <Lucide
                                                        icon="CircleCheck"
                                                        class="mr-1.5 h-3.5 w-3.5"
                                                    />
                                                    Confirmar
                                                </Button>
                                                <Button
                                                    v-else-if="
                                                        mainAction(r) ===
                                                        'receive'
                                                    "
                                                    :as="Link"
                                                    :href="checkInHref(r)"
                                                    variant="success"
                                                    :class="[
                                                        mainButton,
                                                        'text-white',
                                                    ]"
                                                >
                                                    <Lucide
                                                        icon="LogIn"
                                                        class="mr-1.5 h-3.5 w-3.5"
                                                    />
                                                    Recibir
                                                </Button>
                                                <Button
                                                    v-else-if="
                                                        mainAction(r) === 'pay'
                                                    "
                                                    :as="Link"
                                                    :href="payHref(r)"
                                                    variant="outline-primary"
                                                    :class="[
                                                        mainButton,
                                                        'bg-white dark:bg-darkmode-600',
                                                    ]"
                                                >
                                                    <Lucide
                                                        icon="Banknote"
                                                        class="mr-1.5 h-3.5 w-3.5"
                                                    />
                                                    Cobrar
                                                </Button>
                                                <span
                                                    v-else
                                                    :class="mainButton"
                                                    aria-hidden="true"
                                                />
                                            </template>
                                            <Menu v-if="canManage">
                                                <Menu.Button
                                                    as="button"
                                                    type="button"
                                                    :class="rowAction"
                                                    title="Más acciones"
                                                >
                                                    <Lucide
                                                        icon="Ellipsis"
                                                        class="h-4 w-4"
                                                    />
                                                </Menu.Button>
                                                <Menu.Items class="w-56">
                                                    <Menu.Item
                                                        v-if="
                                                            r.pending_balance >
                                                                0 &&
                                                            mainAction(r) !==
                                                                'pay'
                                                        "
                                                        :as="Link"
                                                        :href="payHref(r)"
                                                    >
                                                        <Lucide
                                                            icon="Banknote"
                                                            class="mr-2 h-4 w-4"
                                                        />
                                                        Cobrar o registrar pago
                                                    </Menu.Item>
                                                    <Menu.Item
                                                        v-if="
                                                            manualCheckinAllowed &&
                                                            mainAction(r) !==
                                                                'receive'
                                                        "
                                                        :as="Link"
                                                        :href="checkInHref(r)"
                                                    >
                                                        <Lucide
                                                            icon="LogIn"
                                                            class="mr-2 h-4 w-4"
                                                        />
                                                        Registrar llegada
                                                    </Menu.Item>
                                                    <Menu.Item
                                                        :as="Link"
                                                        :href="editHref(r)"
                                                    >
                                                        <Lucide
                                                            icon="Pencil"
                                                            class="mr-2 h-4 w-4"
                                                        />
                                                        Editar fechas o datos
                                                    </Menu.Item>
                                                    <Menu.Item
                                                        :as="Link"
                                                        :href="detailHref(r)"
                                                    >
                                                        <Lucide
                                                            icon="FileText"
                                                            class="mr-2 h-4 w-4"
                                                        />
                                                        Abrir la ficha
                                                    </Menu.Item>
                                                    <Menu.Divider />
                                                    <Menu.Item
                                                        as="button"
                                                        type="button"
                                                        class="text-warning"
                                                        @click="
                                                            askCancel(
                                                                r,
                                                                'no_show',
                                                            )
                                                        "
                                                    >
                                                        <Lucide
                                                            icon="UserX"
                                                            class="mr-2 h-4 w-4"
                                                        />
                                                        El huésped no llegó
                                                    </Menu.Item>
                                                    <Menu.Item
                                                        as="button"
                                                        type="button"
                                                        class="text-danger"
                                                        @click="
                                                            askCancel(
                                                                r,
                                                                'cancel',
                                                            )
                                                        "
                                                    >
                                                        <Lucide
                                                            icon="Ban"
                                                            class="mr-2 h-4 w-4"
                                                        />
                                                        Cancelar reserva
                                                    </Menu.Item>
                                                </Menu.Items>
                                            </Menu>
                                        </div>
                                    </Table.Td>
                                </Table.Tr>
                            </template>
                        </Table.Tbody>
                    </Table>
                </div>

                <!-- Móvil y tablet: los mismos grupos, apilados -->
                <div v-if="reservations.data.length" class="lg:hidden">
                    <template v-for="group in groups" :key="`m-${group.key}`">
                        <GroupRow
                            as="div"
                            :label="group.label"
                            :count="group.count"
                            :tone="group.tone"
                        />
                        <div
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="r in group.rows"
                                :key="r.id"
                                class="px-4 py-3"
                                :class="urgencyBorder(arrivalUrgency(r))"
                            >
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <GuestCell
                                        class="min-w-0 flex-1"
                                        :name="r.guest_name"
                                        :meta="[
                                            r.code,
                                            r.room ? `Hab. ${r.room}` : null,
                                        ]"
                                        @open="detail = r"
                                    />
                                    <PayMeter
                                        class="!w-24 shrink-0"
                                        :total="Number(r.total_amount)"
                                        :paid="r.paid_total"
                                        :balance="r.pending_balance"
                                        :overdue="r.payment_overdue"
                                    />
                                </div>
                                <div
                                    class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"
                                >
                                    <span
                                        class="font-medium text-slate-700 tabular-nums dark:text-slate-300"
                                    >
                                        {{ r.starts_label }} →
                                        {{ r.ends_label }}
                                    </span>
                                    <span>· {{ r.span_label }}</span>
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="statusFor(r.status).class"
                                    >
                                        {{
                                            r.arrival_pending
                                                ? 'Sin llegada'
                                                : r.status_label
                                        }}
                                    </span>
                                    <span
                                        v-if="r.hold_countdown"
                                        class="text-[11px]"
                                        :class="
                                            r.hold_state === 'expired'
                                                ? 'text-danger'
                                                : 'text-warning'
                                        "
                                    >
                                        {{
                                            r.hold_state === 'expired'
                                                ? r.hold_countdown
                                                : `se libera ${r.hold_countdown}`
                                        }}
                                    </span>
                                </div>
                                <div class="mt-2.5 grid grid-cols-2 gap-2">
                                    <template v-if="canManage">
                                        <Button
                                            v-if="mainAction(r) === 'confirm'"
                                            variant="primary"
                                            class="h-8 rounded-[0.5rem] text-xs"
                                            @click="askConfirm(r)"
                                        >
                                            <Lucide
                                                icon="CircleCheck"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            Confirmar
                                        </Button>
                                        <Button
                                            v-else-if="
                                                mainAction(r) === 'receive'
                                            "
                                            :as="Link"
                                            :href="checkInHref(r)"
                                            variant="success"
                                            class="h-8 rounded-[0.5rem] text-xs text-white"
                                        >
                                            <Lucide
                                                icon="LogIn"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            Recibir
                                        </Button>
                                        <Button
                                            v-else-if="mainAction(r) === 'pay'"
                                            :as="Link"
                                            :href="payHref(r)"
                                            variant="outline-primary"
                                            class="h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                                        >
                                            <Lucide
                                                icon="Banknote"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            Cobrar
                                        </Button>
                                    </template>
                                    <Button
                                        variant="outline-secondary"
                                        class="h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                                        :class="
                                            canManage && mainAction(r)
                                                ? ''
                                                : 'col-span-2'
                                        "
                                        @click="detail = r"
                                    >
                                        <Lucide
                                            icon="Eye"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Ver más
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <div
                    v-if="!reservations.data.length"
                    class="flex flex-col items-center gap-2 px-5 py-10 text-center"
                >
                    <Lucide
                        :icon="filtersActive ? 'SearchX' : 'CalendarDays'"
                        class="h-8 w-8 text-slate-300"
                    />
                    <p class="text-sm font-medium text-slate-600">
                        {{
                            filtersActive
                                ? 'Nada coincide con ese filtro'
                                : 'No hay reservas apartadas a futuro'
                        }}
                    </p>
                    <p class="text-xs text-slate-500">
                        {{
                            filtersActive
                                ? 'Prueba con el folio o el teléfono, o quita el filtro.'
                                : 'Lo que se aparte desde el sitio, el asistente o el mostrador aparece aquí.'
                        }}
                    </p>
                    <button
                        v-if="filtersActive"
                        type="button"
                        class="mt-1 text-xs font-medium text-primary hover:underline"
                        @click="clearFilters"
                    >
                        Ver todas
                    </button>
                </div>

                <ListPager
                    :links="reservations.links"
                    :from="reservations.from"
                    :to="reservations.to"
                    :total="reservations.total"
                />
            </div>
        </div>

        <!-- Detalle de la reserva, con sus acciones -->
        <Dialog :open="detail !== null" size="lg" @close="detail = null">
            <Dialog.Panel v-if="detail" class="sm:w-[94vw] lg:w-[640px]">
                <div class="flex max-h-[calc(100dvh-6rem)] flex-col">
                    <div
                        class="flex items-start gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="CalendarDays" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-base font-medium">
                                    {{ detail.guest_name ?? 'Anónimo' }}
                                </h2>
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="statusFor(detail.status).class"
                                >
                                    <Lucide
                                        :icon="statusFor(detail.status).icon"
                                        class="h-3 w-3"
                                    />
                                    {{ detail.status_label }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ detail.code }} · actualizada
                                {{ detail.updated_at ?? '—' }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-darkmode-400"
                            title="Cerrar"
                            @click="detail = null"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                        <div
                            class="grid grid-cols-1 gap-x-4 gap-y-3 text-xs sm:grid-cols-2"
                        >
                            <div>
                                <div class="text-[11px] text-slate-400">
                                    Habitación
                                </div>
                                <div class="text-sm font-medium">
                                    {{ detail.room ?? 'Sin asignar' }}
                                    <span
                                        v-if="detail.room_type"
                                        class="text-xs font-normal text-slate-500"
                                    >
                                        · {{ detail.room_type }}</span
                                    >
                                </div>
                                <div
                                    v-if="detail.rate_plan"
                                    class="text-slate-500"
                                >
                                    Tarifa: {{ detail.rate_plan }}
                                </div>
                            </div>
                            <div>
                                <div class="text-[11px] text-slate-400">
                                    Estancia
                                </div>
                                <div class="text-sm font-medium tabular-nums">
                                    {{ detail.starts_at }}
                                    <span class="text-slate-400">→</span>
                                    {{ detail.ends_at }}
                                </div>
                                <div class="text-slate-500">
                                    {{ detail.num_people }}
                                    {{
                                        detail.num_people === 1
                                            ? 'persona'
                                            : 'personas'
                                    }}
                                    ·
                                    {{ channelLabel(detail.source_channel) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-[11px] text-slate-400">
                                    Contacto
                                </div>
                                <a
                                    v-if="detail.guest_phone"
                                    :href="`tel:${detail.guest_phone}`"
                                    class="block text-sm text-primary hover:underline"
                                    >{{ detail.guest_phone }}</a
                                >
                                <div v-else class="text-sm">—</div>
                                <div class="text-slate-500">
                                    {{ detail.guest_email ?? 'Sin correo' }}
                                </div>
                            </div>
                            <div>
                                <div class="text-[11px] text-slate-400">
                                    Pago
                                </div>
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="paymentBadge(detail)"
                                >
                                    {{
                                        detail.payment_overdue
                                            ? 'Pago vencido'
                                            : detail.payment_status_label
                                    }}
                                </span>
                                <div class="mt-0.5 text-slate-500">
                                    Pagó {{ money(detail.paid_total) }} · debe
                                    {{ money(detail.pending_balance) }}
                                </div>
                                <div
                                    v-if="detail.payment_due_at"
                                    :class="
                                        detail.payment_overdue
                                            ? 'text-danger'
                                            : 'text-slate-500'
                                    "
                                >
                                    Saldo para el {{ detail.payment_due_at }}
                                </div>
                            </div>
                        </div>

                        <!-- Desglose -->
                        <div
                            class="mt-4 rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                        >
                            <div
                                v-for="line in detailLines"
                                :key="line.key"
                                class="flex items-center justify-between gap-3 border-b border-dashed border-slate-200/80 px-3.5 py-2 text-xs last:border-0 dark:border-darkmode-400"
                            >
                                <span class="min-w-0 truncate text-slate-600">{{
                                    line.label
                                }}</span>
                                <span class="tabular-nums">{{
                                    money(line.amount)
                                }}</span>
                            </div>
                            <div
                                class="flex items-center justify-between gap-3 px-3.5 py-2.5 text-sm font-medium"
                                :class="
                                    detailLines.length > 0 &&
                                    'border-t border-slate-200/70 dark:border-darkmode-400'
                                "
                            >
                                <span>Total</span>
                                <span class="tabular-nums">{{
                                    money(Number(detail.total_amount))
                                }}</span>
                            </div>
                        </div>

                        <p
                            v-if="detail.guest_notes"
                            class="mt-3 text-xs text-slate-500"
                        >
                            Nota del huésped: {{ detail.guest_notes }}
                        </p>
                        <p
                            v-if="detail.notes"
                            class="mt-2 text-xs whitespace-pre-line text-slate-500"
                        >
                            Nota interna: {{ detail.notes }}
                        </p>

                        <!-- Línea de tiempo -->
                        <div v-if="detail.timeline.length" class="mt-4">
                            <div
                                class="mb-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Qué ha pasado
                            </div>
                            <div
                                v-for="item in detail.timeline"
                                :key="item.id"
                                class="flex items-start gap-2.5 border-l-2 border-slate-200 py-1.5 pl-3 text-xs dark:border-darkmode-400"
                            >
                                <div class="min-w-0">
                                    <div>{{ item.message }}</div>
                                    <div class="text-[11px] text-slate-400">
                                        {{ item.at }}
                                        <template v-if="item.by">
                                            · {{ item.by }}</template
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div
                        class="border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <div
                            v-if="canManage"
                            class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:justify-end"
                        >
                            <Button
                                v-if="detail.status === 'pending'"
                                variant="primary"
                                class="h-9 rounded-[0.5rem] text-xs"
                                @click="askConfirm(detail)"
                            >
                                <Lucide
                                    icon="CircleCheck"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Confirmar
                            </Button>
                            <Button
                                v-if="manualCheckinAllowed"
                                :as="Link"
                                :href="checkInHref(detail)"
                                :variant="
                                    canReceive(detail)
                                        ? 'success'
                                        : 'outline-success'
                                "
                                class="h-9 rounded-[0.5rem] text-xs"
                                :class="
                                    canReceive(detail)
                                        ? 'text-white'
                                        : 'bg-white dark:bg-darkmode-600'
                                "
                            >
                                <Lucide
                                    icon="LogIn"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Registrar llegada
                            </Button>
                            <Button
                                v-if="detail.pending_balance > 0"
                                :as="Link"
                                :href="payHref(detail)"
                                variant="outline-primary"
                                class="h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                            >
                                <Lucide
                                    icon="Banknote"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Cobrar
                            </Button>
                            <Button
                                :as="Link"
                                :href="detailHref(detail)"
                                variant="outline-secondary"
                                class="h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                            >
                                <Lucide
                                    icon="FileText"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Abrir ficha
                            </Button>
                        </div>
                        <div
                            class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2"
                            :class="canManage ? '' : 'mt-0'"
                        >
                            <template v-if="canManage">
                                <button
                                    type="button"
                                    class="text-xs font-medium text-warning hover:underline"
                                    @click="askCancel(detail, 'no_show')"
                                >
                                    El huésped no llegó
                                </button>
                                <button
                                    type="button"
                                    class="text-xs font-medium text-danger hover:underline"
                                    @click="askCancel(detail, 'cancel')"
                                >
                                    Cancelar reserva
                                </button>
                            </template>
                            <Button
                                variant="outline-secondary"
                                class="ml-auto h-9 rounded-[0.5rem] px-5 text-xs"
                                @click="detail = null"
                            >
                                Cerrar
                            </Button>
                        </div>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <ConfirmReservationDialog
            :target="confirmTarget"
            :hold-label="holdLabel"
            @close="confirmTarget = null"
        />
        <CancelReservationDialog
            :target="cancelTarget"
            :kind="cancelKind"
            @close="cancelTarget = null"
        />
    </RazeLayout>
</template>
