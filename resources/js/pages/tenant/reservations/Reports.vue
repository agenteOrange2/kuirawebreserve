<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import Chart from '@/components/Base/Chart';
import { FormDate, FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface SeriesBucket {
    label: string;
    reservations: number;
    cancelled: number;
    sold_value: number;
    occupancy: number;
}
interface StatusRow {
    status: string;
    label: string;
    count: number;
}
interface RoomTypeRow {
    name: string;
    total: number;
    cancelled: number;
    revenue: number;
}
interface ChannelRow {
    channel: string;
    count: number;
}
interface RoomOption {
    id: number;
    label: string;
}
interface RoomRow {
    id: number | null;
    name: string;
    uses: number;
    nights: number;
    capacity: number;
    percent: number | null;
    revenue: number;
}
interface MethodRow {
    method: string;
    label: string;
    amount: number;
    count: number;
}
interface ConceptRow {
    key: string;
    label: string;
    amount: number;
    note: string;
}
interface LeadRow {
    label: string;
    count: number;
}
interface PaymentStatusRow {
    status: string;
    label: string;
    count: number;
    value: number;
    pending: number;
}
interface DetailRow {
    id: number;
    code: string;
    guest: string;
    room: string;
    channel: string;
    created_at: string;
    lead_days: number | null;
    starts_at: string;
    ends_at: string;
    status: string;
    status_label: string;
    payment_status: string | null;
    payment_label: string;
    total: number;
    paid: number;
    pending: number;
}

const props = defineProps<{
    property: { id: number; name: string };
    filters: { period: string; from: string; to: string; room: number | null };
    period: { label: string; from: string; to: string; days: number };
    kpis: {
        total: number;
        created: number;
        sold: number;
        confirmed: number;
        checked_in: number;
        completed: number;
        pending: number;
        cancelled: number;
        no_show: number;
        cancel_rate: number;
        no_show_rate: number;
        avg_reservation: number;
        avg_lead_days: number;
        check_ins: number;
        check_outs: number;
    };
    money: {
        lodging: number;
        pos: number;
        collected: number;
        refunds: number;
        net: number;
        guarantees: number;
        guarantees_count: number;
        payments_count: number;
        reserved: number;
        walkin: number;
        sold_value: number;
        reserved_paid: number;
        reserved_pending: number;
        reserved_paid_pct: number;
        with_debt: number;
        by_method: MethodRow[];
        by_concept: ConceptRow[];
    };
    occupancy: {
        rooms: number;
        days: number;
        available: number;
        occupied: number;
        percent: number;
        uses: number;
        idle_rooms: number;
        unassigned_nights: number;
        best: RoomRow | null;
    };
    series: SeriesBucket[];
    byStatus: StatusRow[];
    byRoomType: RoomTypeRow[];
    byChannel: ChannelRow[];
    byRoom: RoomRow[];
    lead: LeadRow[];
    paymentStatus: PaymentStatusRow[];
    detail: DetailRow[];
    detailTotal: number;
    detailLimit: number | null;
    rooms: RoomOption[];
}>();

const fmt = (value: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value) || 0);

const pct = (value: number | null) =>
    value === null
        ? '—'
        : `${new Intl.NumberFormat('es-MX', { maximumFractionDigits: 1 }).format(value)}%`;

const plural = (n: number, one: string, many: string) =>
    `${n} ${n === 1 ? one : many}`;

// ── Constantes de anatomía ────────────────────────────────────
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const sectionLabel =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
const stripItem = 'inline-flex items-center gap-1.5 text-slate-500';
const stripValue = 'font-medium text-slate-700 dark:text-slate-300';
const stripDivider =
    'hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400';

// ── Selector de periodo ───────────────────────────────────────
const periods: { key: string; label: string; icon: Icon }[] = [
    { key: 'week', label: 'Semana', icon: 'CalendarDays' },
    { key: 'month', label: 'Mes', icon: 'Calendar' },
    { key: 'year', label: 'Año', icon: 'CalendarRange' },
    { key: 'custom', label: 'Rango', icon: 'CalendarSearch' },
];

const customFrom = ref(props.filters.from);
const customTo = ref(props.filters.to);
const roomSel = ref<string | number>(props.filters.room ?? '');
const showCustom = ref(props.filters.period === 'custom');

function query(period: string) {
    return {
        period,
        room: roomSel.value || undefined,
        ...(period === 'custom'
            ? { from: customFrom.value, to: customTo.value }
            : {}),
    };
}

function goTo(period: string) {
    if (period === 'custom') {
        applyCustom();
        return;
    }
    router.get(route('tenant.reservations.reports'), query(period), {
        preserveScroll: true,
    });
}

function applyCustom() {
    if (!customFrom.value || !customTo.value) return;
    router.get(route('tenant.reservations.reports'), query('custom'), {
        preserveScroll: true,
    });
}

function applyRoom() {
    goTo(props.filters.period);
}

const pdfUrl = computed(() =>
    route('tenant.reservations.reports.pdf', {
        period: props.filters.period,
        ...(props.filters.room ? { room: props.filters.room } : {}),
        ...(props.filters.period === 'custom'
            ? { from: props.filters.from, to: props.filters.to }
            : {}),
    }),
);

// ── Tarjetas de cifras ────────────────────────────────────────
interface Tile {
    key: string;
    icon: Icon;
    tone: string;
    value: string;
    label: string;
    detail: string;
}

const tiles = computed<Tile[]>(() => [
    {
        key: 'reservations',
        icon: 'CalendarDays',
        tone: 'border-primary/10 bg-primary/10 text-primary',
        value: String(props.kpis.total),
        label: 'Reservas que llegan',
        detail: `${props.kpis.sold} efectivas · ${props.kpis.pending} por confirmar`,
    },
    {
        key: 'lost',
        icon: 'Ban',
        tone: 'border-danger/10 bg-danger/10 text-danger',
        value: String(props.kpis.cancelled + props.kpis.no_show),
        label: 'Canceladas y no-show',
        detail: `${pct(props.kpis.cancel_rate)} canc. · ${pct(props.kpis.no_show_rate)} no-show`,
    },
    {
        key: 'occupancy',
        icon: 'PieChart',
        tone: 'border-info/10 bg-info/10 text-info',
        value: pct(props.occupancy.percent),
        label: 'Uso de habitaciones',
        detail: `${props.occupancy.occupied} de ${props.occupancy.available} noches`,
    },
    {
        key: 'uses',
        icon: 'ArrowRightLeft',
        tone: 'border-pending/10 bg-pending/10 text-pending',
        value: String(props.occupancy.uses),
        label: 'Usos del periodo',
        detail: `${props.kpis.check_ins} check-ins · ${props.kpis.check_outs} check-outs`,
    },
    {
        key: 'sold',
        icon: 'Tag',
        tone: 'border-primary/10 bg-primary/10 text-primary',
        value: fmt(props.money.sold_value),
        label: 'Hospedaje vendido',
        detail: `Reservas ${fmt(props.money.reserved)} · walk-ins ${fmt(props.money.walkin)}`,
    },
    {
        key: 'collected',
        icon: 'Banknote',
        tone: 'border-success/10 bg-success/10 text-success',
        value: fmt(props.money.net),
        label: 'Ingresos cobrados',
        detail: `Bruto ${fmt(props.money.collected)} · devoluciones ${fmt(props.money.refunds)}`,
    },
    {
        key: 'pending',
        icon: 'Hourglass',
        tone: 'border-warning/10 bg-warning/10 text-warning',
        value: fmt(props.money.reserved_pending),
        label: 'Saldo pendiente',
        detail: `${plural(props.money.with_debt, 'reserva debe', 'reservas deben')} de este periodo`,
    },
    {
        key: 'lead',
        icon: 'Clock',
        tone: 'border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400',
        value: plural(props.kpis.avg_lead_days, 'día', 'días'),
        label: 'Anticipación promedio',
        detail: `${props.kpis.created} creadas aquí · promedio ${fmt(props.kpis.avg_reservation)}`,
    },
]);

// ── Charts (tokens del theme) ─────────────────────────────────
const statusHex: Record<string, string> = {
    pending: '#ca8a04',
    confirmed: '#03045e',
    checked_in: '#0d9488',
    completed: '#1e293b',
    cancelled: '#b91c1c',
    no_show: '#c2410c',
};

const baseOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
        x: { grid: { display: false }, ticks: { font: { size: 10 } } },
        y: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 } } },
    },
};

const lineData = computed(() => ({
    labels: props.series.map((b) => b.label),
    datasets: [
        {
            label: 'Reservas',
            data: props.series.map((b) => b.reservations),
            borderColor: '#03045e',
            backgroundColor: 'rgba(3,4,94,0.08)',
            fill: true,
        },
        {
            label: 'Canceladas / No-show',
            data: props.series.map((b) => b.cancelled),
            borderColor: '#b91c1c',
            backgroundColor: 'rgba(185,28,28,0.06)',
            fill: true,
        },
    ],
}));

const lineOptions = {
    ...baseOptions,
    plugins: {
        legend: {
            display: true,
            position: 'bottom' as const,
            labels: {
                boxWidth: 8,
                boxHeight: 8,
                usePointStyle: true,
                font: { size: 10 },
            },
        },
    },
    elements: { point: { radius: 2 }, line: { tension: 0.4, borderWidth: 2 } },
};

const soldData = computed(() => ({
    labels: props.series.map((b) => b.label),
    datasets: [
        {
            label: 'Hospedaje vendido',
            data: props.series.map((b) => b.sold_value),
            backgroundColor: 'rgba(3,4,94,0.7)',
            borderRadius: 4,
        },
    ],
}));

const occupancyData = computed(() => ({
    labels: props.series.map((b) => b.label),
    datasets: [
        {
            label: 'Uso',
            data: props.series.map((b) => b.occupancy),
            borderColor: '#0d9488',
            backgroundColor: 'rgba(13,148,136,0.1)',
            fill: true,
        },
    ],
}));

const occupancyOptions = {
    ...baseOptions,
    scales: {
        x: baseOptions.scales.x,
        y: {
            beginAtZero: true,
            suggestedMax: 100,
            ticks: {
                precision: 0,
                font: { size: 10 },
                callback: (value: number | string) => `${value}%`,
            },
        },
    },
    elements: { point: { radius: 2 }, line: { tension: 0.4, borderWidth: 2 } },
};

const donutData = computed(() => ({
    labels: props.byStatus.map((s) => s.label),
    datasets: [
        {
            data: props.byStatus.map((s) => s.count),
            backgroundColor: props.byStatus.map(
                (s) => statusHex[s.status] ?? '#94a3b8',
            ),
            borderWidth: 0,
            hoverOffset: 4,
        },
    ],
}));

const donutOptions = {
    cutout: '72%',
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
};

// ── Etiquetas ─────────────────────────────────────────────────
const channelLabels: Record<string, string> = {
    front_desk: 'Mostrador',
    counter: 'Mostrador',
    phone: 'Teléfono',
    web: 'Sitio web',
    whatsapp: 'WhatsApp',
    agent: 'Asistente',
    walk_in: 'Sin reserva',
};
const channelIcons: Record<string, Icon> = {
    front_desk: 'ConciergeBell',
    counter: 'ConciergeBell',
    phone: 'Phone',
    web: 'Globe',
    whatsapp: 'MessageCircle',
    agent: 'Bot',
    walk_in: 'Footprints',
};
const methodIcons: Record<string, Icon> = {
    cash: 'Banknote',
    card: 'CreditCard',
    transfer: 'ArrowLeftRight',
    online: 'Globe',
};
const statusTone: Record<string, string> = {
    pending: 'bg-warning/10 text-warning',
    confirmed: 'bg-primary/10 text-primary',
    checked_in: 'bg-success/10 text-success',
    completed: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    cancelled: 'bg-danger/10 text-danger',
    no_show: 'bg-pending/10 text-pending',
};
const paymentTone: Record<string, string> = {
    paid: 'bg-success/10 text-success',
    deposit_paid: 'bg-info/10 text-info',
    partial: 'bg-warning/10 text-warning',
    unpaid: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
};

const maxChannel = computed(() =>
    Math.max(1, ...props.byChannel.map((c) => c.count)),
);
const maxLead = computed(() => Math.max(1, ...props.lead.map((l) => l.count)));
const maxMethod = computed(() =>
    Math.max(1, ...props.money.by_method.map((m) => m.amount)),
);
const roomTotals = computed(() => ({
    uses: props.byRoom.reduce((sum, r) => sum + r.uses, 0),
    nights: props.byRoom.reduce((sum, r) => sum + r.nights, 0),
    revenue: props.byRoom.reduce((sum, r) => sum + r.revenue, 0),
}));
const paymentStatusMax = computed(() =>
    Math.max(1, ...props.paymentStatus.map((r) => r.count)),
);
</script>

<template>
    <RazeLayout title="Reportes de reservas">
        <div class="mt-2">
            <!-- Encabezado -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ChartColumn" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Reportes de reservas
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ property.name }} · {{ period.label }}
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
                        Volver a reservas
                    </Link>
                    <Button
                        as="a"
                        :href="pdfUrl"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs"
                    >
                        <Lucide icon="FileDown" class="mr-1.5 h-3.5 w-3.5" />
                        Descargar PDF
                    </Button>
                </div>
            </div>

            <!-- Filtros + datos duros del rango -->
            <div class="box box--stacked mt-4 overflow-hidden">
                <div
                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600"
                >
                    <div
                        class="inline-flex flex-wrap gap-1 rounded-[0.5rem] bg-white p-1 shadow-sm dark:bg-darkmode-700"
                    >
                        <button
                            v-for="p in periods"
                            :key="p.key"
                            type="button"
                            class="flex h-7 items-center gap-1.5 rounded-[0.4rem] px-2.5 text-xs font-medium transition"
                            :class="
                                filters.period === p.key
                                    ? 'bg-primary/10 text-primary'
                                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                            "
                            @click="
                                p.key === 'custom'
                                    ? (showCustom = true)
                                    : goTo(p.key)
                            "
                        >
                            <Lucide :icon="p.icon" class="h-3.5 w-3.5" />
                            {{ p.label }}
                        </button>
                    </div>
                    <div
                        v-if="showCustom || filters.period === 'custom'"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <FormDate
                            v-model="customFrom"
                            class="w-36"
                            input-class="h-9 text-xs"
                        />
                        <span class="text-xs text-slate-400">a</span>
                        <FormDate
                            v-model="customTo"
                            class="w-36"
                            input-class="h-9 text-xs"
                        />
                        <Button
                            variant="outline-primary"
                            class="h-8 rounded-[0.5rem] bg-white text-xs"
                            :disabled="!customFrom || !customTo"
                            @click="applyCustom"
                        >
                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                            Aplicar
                        </Button>
                    </div>
                    <div class="relative ml-auto">
                        <Lucide
                            icon="BedDouble"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                        />
                        <FormSelect
                            v-model="roomSel"
                            class="h-9 w-52 pl-9 text-xs"
                            @change="applyRoom"
                        >
                            <option value="">Todas las habitaciones</option>
                            <option
                                v-for="room in rooms"
                                :key="room.id"
                                :value="room.id"
                            >
                                {{ room.label }}
                            </option>
                        </FormSelect>
                    </div>
                </div>
                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-3 text-xs"
                >
                    <span :class="stripItem">
                        <Lucide icon="CalendarRange" class="h-3.5 w-3.5" />
                        <span :class="stripValue"
                            >{{ period.from }} – {{ period.to }}</span
                        >
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        Días
                        <span :class="stripValue">{{ period.days }}</span>
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        Habitaciones medidas
                        <span :class="stripValue">{{ occupancy.rooms }}</span>
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        Abonos registrados
                        <span :class="stripValue">{{
                            money.payments_count
                        }}</span>
                    </span>
                    <span
                        class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] text-slate-500 md:ml-auto dark:bg-darkmode-400"
                    >
                        Las reservas se cuentan por fecha de llegada; el dinero,
                        por fecha de pago
                    </span>
                </div>
            </div>

            <!-- Resumen -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">Resumen del periodo</span>
            </div>
            <div class="mt-2 grid auto-rows-fr grid-cols-12 gap-4">
                <div
                    v-for="tile in tiles"
                    :key="tile.key"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div :class="[sectionIcon, tile.tone]">
                        <Lucide :icon="tile.icon" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">{{ tile.value }}</div>
                        <div class="truncate text-xs text-slate-500">
                            {{ tile.label }}
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            {{ tile.detail }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dinero -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">Dinero</span>
            </div>
            <div class="mt-2 grid grid-cols-12 items-stretch gap-4">
                <!-- Vendido, cobrado y saldo -->
                <div class="col-span-12 flex flex-col xl:col-span-4">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-primary/10 bg-primary/10 text-primary',
                                ]"
                            >
                                <Lucide icon="Tag" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Vendido y por cobrar
                                </div>
                                <div class="text-xs text-slate-500">
                                    Reservas que llegan en el rango
                                </div>
                            </div>
                        </div>
                        <div class="flex-1 px-4 py-3">
                            <div
                                class="flex items-end justify-between text-xs text-slate-500"
                            >
                                <span>Cobrado de esas reservas</span>
                                <span
                                    class="text-sm font-medium text-slate-700 dark:text-slate-300"
                                >
                                    {{ pct(money.reserved_paid_pct) }}
                                </span>
                            </div>
                            <div
                                class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                            >
                                <div
                                    class="h-full rounded-full bg-success/70"
                                    :style="{
                                        width: `${Math.min(100, money.reserved_paid_pct)}%`,
                                    }"
                                />
                            </div>
                            <div
                                class="mt-3 divide-y divide-slate-200/60 text-xs dark:divide-darkmode-400"
                            >
                                <div class="flex items-center gap-2 py-2">
                                    <span class="text-slate-500"
                                        >Hospedaje de reservas</span
                                    >
                                    <span class="ml-auto font-medium">{{
                                        fmt(money.reserved)
                                    }}</span>
                                </div>
                                <div class="flex items-center gap-2 py-2">
                                    <span class="text-slate-500"
                                        >Walk-ins sin reserva</span
                                    >
                                    <span class="ml-auto font-medium">{{
                                        fmt(money.walkin)
                                    }}</span>
                                </div>
                                <div class="flex items-center gap-2 py-2">
                                    <span class="text-slate-500"
                                        >Ya abonado</span
                                    >
                                    <span
                                        class="ml-auto font-medium text-success"
                                        >{{ fmt(money.reserved_paid) }}</span
                                    >
                                </div>
                                <div class="flex items-center gap-2 py-2">
                                    <span class="text-slate-500"
                                        >Saldo pendiente</span
                                    >
                                    <span
                                        class="ml-auto font-medium"
                                        :class="
                                            money.reserved_pending > 0
                                                ? 'text-warning'
                                                : 'text-slate-400'
                                        "
                                        >{{ fmt(money.reserved_pending) }}</span
                                    >
                                </div>
                            </div>
                            <p class="mt-2 text-[11px] text-slate-400">
                                El saldo es de las reservas de este rango. El
                                dinero suelto de estancias ya cerradas se
                                persigue en
                                <Link
                                    :href="
                                        route('tenant.reservations.settlements')
                                    "
                                    class="font-medium text-primary"
                                    >Cuentas por cerrar</Link
                                >.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Cobrado por concepto -->
                <div class="col-span-12 flex flex-col xl:col-span-4">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-success/10 bg-success/10 text-success',
                                ]"
                            >
                                <Lucide icon="Banknote" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Ingresos cobrados
                                </div>
                                <div class="text-xs text-slate-500">
                                    Con fecha de pago en el rango
                                </div>
                            </div>
                        </div>
                        <div class="flex-1 px-4 py-3">
                            <div
                                class="divide-y divide-slate-200/60 text-xs dark:divide-darkmode-400"
                            >
                                <div
                                    v-for="row in money.by_concept"
                                    :key="row.key"
                                    class="flex items-start gap-2 py-2"
                                >
                                    <div class="min-w-0">
                                        <div
                                            class="text-slate-600 dark:text-slate-300"
                                        >
                                            {{ row.label }}
                                        </div>
                                        <div class="text-[11px] text-slate-400">
                                            {{ row.note }}
                                        </div>
                                    </div>
                                    <span
                                        class="ml-auto font-medium"
                                        :class="
                                            row.amount < 0 ? 'text-danger' : ''
                                        "
                                        >{{ fmt(row.amount) }}</span
                                    >
                                </div>
                                <div
                                    v-if="!money.by_concept.length"
                                    class="py-6 text-center text-slate-400"
                                >
                                    Sin cobros en el periodo.
                                </div>
                                <div
                                    class="flex items-center gap-2 py-2 text-sm"
                                >
                                    <span class="font-medium">Neto</span>
                                    <span class="ml-auto font-medium">{{
                                        fmt(money.net)
                                    }}</span>
                                </div>
                            </div>
                            <div
                                class="mt-2 rounded-[0.5rem] bg-slate-50 px-3 py-2 text-[11px] text-slate-500 dark:bg-darkmode-600"
                            >
                                Fianzas en garantía
                                {{ fmt(money.guarantees) }} ({{
                                    money.guarantees_count
                                }}): no son ingreso, se devuelven al salir.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cobrado por método -->
                <div class="col-span-12 flex flex-col xl:col-span-4">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-info/10 bg-info/10 text-info',
                                ]"
                            >
                                <Lucide icon="Wallet" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Cómo se cobró
                                </div>
                                <div class="text-xs text-slate-500">
                                    Abonos por método de pago
                                </div>
                            </div>
                        </div>
                        <div class="flex-1 px-4 py-3">
                            <div
                                v-if="money.by_method.length"
                                class="space-y-3"
                            >
                                <div
                                    v-for="row in money.by_method"
                                    :key="row.method"
                                >
                                    <div class="flex items-center text-xs">
                                        <Lucide
                                            :icon="
                                                methodIcons[row.method] ??
                                                'Coins'
                                            "
                                            class="mr-1.5 h-3.5 w-3.5 text-slate-400"
                                        />
                                        <span
                                            class="text-slate-600 dark:text-slate-300"
                                            >{{ row.label }}</span
                                        >
                                        <span
                                            class="ml-1.5 text-[11px] text-slate-400"
                                            >{{ row.count }}</span
                                        >
                                        <span class="ml-auto font-medium">{{
                                            fmt(row.amount)
                                        }}</span>
                                    </div>
                                    <div
                                        class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                                    >
                                        <div
                                            class="h-full rounded-full bg-info/70"
                                            :style="{
                                                width: `${(row.amount / maxMethod) * 100}%`,
                                            }"
                                        />
                                    </div>
                                </div>
                            </div>
                            <div
                                v-else
                                class="flex flex-col items-center gap-2 py-8 text-slate-400"
                            >
                                <Lucide icon="Wallet" class="h-6 w-6" />
                                <p class="text-xs">Sin abonos en el periodo.</p>
                            </div>
                            <p class="mt-3 text-[11px] text-slate-400">
                                Abono por abono, con comprobante y cobros vivos,
                                en
                                <Link
                                    :href="route('tenant.payments')"
                                    class="font-medium text-primary"
                                    >Pagos</Link
                                >.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Uso de habitaciones -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">Uso de habitaciones</span>
                <span class="text-[11px] text-slate-400"
                    >Noches ocupadas sobre noches disponibles</span
                >
            </div>
            <div class="mt-2 grid grid-cols-12 items-stretch gap-4">
                <div class="col-span-12 flex flex-col xl:col-span-5">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-success/10 bg-success/10 text-success',
                                ]"
                            >
                                <Lucide icon="PieChart" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Porcentaje de uso
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ occupancy.occupied }} de
                                    {{ occupancy.available }} noches-habitación
                                </div>
                            </div>
                            <span
                                class="ml-auto rounded-full bg-success/10 px-2.5 py-1 text-[11px] font-medium text-success"
                                >{{ pct(occupancy.percent) }}</span
                            >
                        </div>
                        <div class="flex flex-1 flex-col px-4 py-3">
                            <div class="h-[180px]">
                                <Chart
                                    type="line"
                                    :data="occupancyData"
                                    :options="occupancyOptions"
                                    class="!h-[180px]"
                                />
                            </div>
                            <div
                                class="mt-3 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4"
                            >
                                <div
                                    class="rounded-[0.5rem] bg-slate-50 px-3 py-2 dark:bg-darkmode-600"
                                >
                                    <div class="font-medium">
                                        {{ occupancy.uses }}
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        Usos
                                    </div>
                                </div>
                                <div
                                    class="rounded-[0.5rem] bg-slate-50 px-3 py-2 dark:bg-darkmode-600"
                                >
                                    <div class="font-medium">
                                        {{ occupancy.idle_rooms }}
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        Sin rentarse
                                    </div>
                                </div>
                                <div
                                    class="rounded-[0.5rem] bg-slate-50 px-3 py-2 dark:bg-darkmode-600"
                                >
                                    <div class="truncate font-medium">
                                        {{ occupancy.best?.name ?? '—' }}
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        La más usada
                                    </div>
                                </div>
                                <div
                                    class="rounded-[0.5rem] bg-slate-50 px-3 py-2 dark:bg-darkmode-600"
                                >
                                    <div class="font-medium">
                                        {{ occupancy.unassigned_nights }}
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        Noches sin asignar
                                    </div>
                                </div>
                            </div>
                            <p class="mt-2 text-[11px] text-slate-400">
                                Cuenta las estancias registradas y las reservas
                                vendidas aunque nadie abriera el plano. Una
                                habitación cuenta una vez por día aunque rote
                                varias veces: la rotación se ve en los usos.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 flex flex-col xl:col-span-7">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-info/10 bg-info/10 text-info',
                                ]"
                            >
                                <Lucide icon="BedDouble" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Habitación por habitación
                                </div>
                                <div class="text-xs text-slate-500">
                                    Usos, noches y hospedaje vendido
                                </div>
                            </div>
                        </div>
                        <div class="flex-1 overflow-x-auto">
                            <Table v-if="byRoom.length" class="text-xs">
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th class="text-[11px]"
                                            >Habitación</Table.Th
                                        >
                                        <Table.Th
                                            class="w-16 text-right text-[11px]"
                                            >Usos</Table.Th
                                        >
                                        <Table.Th
                                            class="w-20 text-right text-[11px]"
                                            >Noches</Table.Th
                                        >
                                        <Table.Th class="w-40 text-[11px]"
                                            >% de uso</Table.Th
                                        >
                                        <Table.Th class="text-right text-[11px]"
                                            >Hospedaje</Table.Th
                                        >
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    <Table.Tr
                                        v-for="row in byRoom"
                                        :key="row.id ?? 'loose'"
                                    >
                                        <Table.Td class="font-medium">
                                            <Link
                                                v-if="row.id"
                                                :href="
                                                    route(
                                                        'tenant.rooms.show',
                                                        row.id,
                                                    )
                                                "
                                                class="hover:text-primary"
                                                >{{ row.name }}</Link
                                            >
                                            <span
                                                v-else
                                                class="text-slate-500"
                                                >{{ row.name }}</span
                                            >
                                        </Table.Td>
                                        <Table.Td class="text-right">{{
                                            row.uses
                                        }}</Table.Td>
                                        <Table.Td class="text-right">{{
                                            row.nights
                                        }}</Table.Td>
                                        <Table.Td>
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <div
                                                    class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                                                >
                                                    <div
                                                        class="h-full rounded-full"
                                                        :class="
                                                            (row.percent ??
                                                                0) >= 50
                                                                ? 'bg-success/70'
                                                                : 'bg-warning/70'
                                                        "
                                                        :style="{
                                                            width: `${Math.min(100, row.percent ?? 0)}%`,
                                                        }"
                                                    />
                                                </div>
                                                <span
                                                    class="w-12 text-right text-[11px] text-slate-500"
                                                    >{{
                                                        pct(row.percent)
                                                    }}</span
                                                >
                                            </div>
                                        </Table.Td>
                                        <Table.Td
                                            class="text-right font-medium"
                                            >{{ fmt(row.revenue) }}</Table.Td
                                        >
                                    </Table.Tr>
                                    <Table.Tr
                                        class="bg-slate-50/70 dark:bg-darkmode-600"
                                    >
                                        <Table.Td class="font-medium"
                                            >Total</Table.Td
                                        >
                                        <Table.Td
                                            class="text-right font-medium"
                                            >{{ roomTotals.uses }}</Table.Td
                                        >
                                        <Table.Td
                                            class="text-right font-medium"
                                            >{{ roomTotals.nights }}</Table.Td
                                        >
                                        <Table.Td
                                            class="text-[11px] text-slate-500"
                                            >{{ pct(occupancy.percent) }} del
                                            inventario</Table.Td
                                        >
                                        <Table.Td
                                            class="text-right font-medium"
                                            >{{
                                                fmt(roomTotals.revenue)
                                            }}</Table.Td
                                        >
                                    </Table.Tr>
                                </Table.Tbody>
                            </Table>
                            <div
                                v-else
                                class="py-10 text-center text-xs text-slate-500"
                            >
                                No hay habitaciones registradas.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reservas -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">Reservas</span>
            </div>
            <div class="mt-2 grid grid-cols-12 items-stretch gap-4">
                <div class="col-span-12 flex flex-col xl:col-span-8">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-primary/10 bg-primary/10 text-primary',
                                ]"
                            >
                                <Lucide icon="TrendingUp" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Evolución del periodo
                                </div>
                                <div class="text-xs text-slate-500">
                                    Llegadas y hospedaje vendido
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col px-4 py-3">
                            <div class="h-[200px]">
                                <Chart
                                    type="line"
                                    :data="lineData"
                                    :options="lineOptions"
                                    class="!h-[200px]"
                                />
                            </div>
                            <div
                                class="mt-3 border-t border-dashed border-slate-300/70 pt-3 dark:border-darkmode-400"
                            >
                                <div :class="sectionLabel">
                                    Hospedaje vendido
                                </div>
                                <div class="mt-2 h-[140px]">
                                    <Chart
                                        type="bar"
                                        :data="soldData"
                                        :options="baseOptions"
                                        class="!h-[140px]"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 flex flex-col xl:col-span-4">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400',
                                ]"
                            >
                                <Lucide icon="ChartPie" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Por estado
                                </div>
                                <div class="text-xs text-slate-500">
                                    Cómo terminaron las {{ kpis.total }}
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col px-4 py-3">
                            <template v-if="byStatus.length">
                                <div
                                    class="relative mx-auto w-full max-w-[170px]"
                                >
                                    <div class="h-[150px]">
                                        <Chart
                                            type="doughnut"
                                            :data="donutData"
                                            :options="donutOptions"
                                            class="!h-[150px]"
                                        />
                                    </div>
                                    <div
                                        class="absolute inset-0 flex items-center justify-center"
                                    >
                                        <div class="text-center">
                                            <div class="text-sm font-medium">
                                                {{ kpis.total }}
                                            </div>
                                            <div
                                                class="text-[11px] text-slate-500"
                                            >
                                                reservas
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3 space-y-2">
                                    <div
                                        v-for="s in byStatus"
                                        :key="s.status"
                                        class="flex items-center text-xs"
                                    >
                                        <span
                                            class="mr-2 h-2 w-2 rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    statusHex[s.status] ??
                                                    '#94a3b8',
                                            }"
                                        />
                                        <span
                                            class="text-slate-600 dark:text-slate-300"
                                            >{{ s.label }}</span
                                        >
                                        <span class="ml-auto font-medium">{{
                                            s.count
                                        }}</span>
                                        <span
                                            class="ml-2 w-10 text-right text-[11px] text-slate-400"
                                        >
                                            {{
                                                kpis.total
                                                    ? Math.round(
                                                          (s.count /
                                                              kpis.total) *
                                                              100,
                                                      )
                                                    : 0
                                            }}%
                                        </span>
                                    </div>
                                </div>
                            </template>
                            <div
                                v-else
                                class="flex flex-1 flex-col items-center justify-center gap-2 py-8 text-slate-400"
                            >
                                <Lucide icon="ChartPie" class="h-6 w-6" />
                                <p class="text-xs">
                                    Sin reservas en el periodo.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 flex flex-col xl:col-span-6">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-info/10 bg-info/10 text-info',
                                ]"
                            >
                                <Lucide icon="LayoutGrid" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Por tipo de habitación
                                </div>
                                <div class="text-xs text-slate-500">
                                    Hospedaje vendido por tipo
                                </div>
                            </div>
                        </div>
                        <div class="flex-1 overflow-x-auto">
                            <Table v-if="byRoomType.length" class="text-xs">
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th class="text-[11px]"
                                            >Tipo</Table.Th
                                        >
                                        <Table.Th class="text-right text-[11px]"
                                            >Reservas</Table.Th
                                        >
                                        <Table.Th class="text-right text-[11px]"
                                            >Canc. / No-show</Table.Th
                                        >
                                        <Table.Th class="text-right text-[11px]"
                                            >Vendido</Table.Th
                                        >
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    <Table.Tr
                                        v-for="row in byRoomType"
                                        :key="row.name"
                                    >
                                        <Table.Td class="font-medium">{{
                                            row.name
                                        }}</Table.Td>
                                        <Table.Td class="text-right">{{
                                            row.total
                                        }}</Table.Td>
                                        <Table.Td
                                            class="text-right"
                                            :class="
                                                row.cancelled
                                                    ? 'text-danger'
                                                    : 'text-slate-400'
                                            "
                                            >{{ row.cancelled }}</Table.Td
                                        >
                                        <Table.Td
                                            class="text-right font-medium"
                                            >{{ fmt(row.revenue) }}</Table.Td
                                        >
                                    </Table.Tr>
                                </Table.Tbody>
                            </Table>
                            <div
                                v-else
                                class="py-10 text-center text-xs text-slate-500"
                            >
                                Sin datos en el periodo.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 flex flex-col xl:col-span-6">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-pending/10 bg-pending/10 text-pending',
                                ]"
                            >
                                <Lucide icon="Radio" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">Por canal</div>
                                <div class="text-xs text-slate-500">
                                    De dónde llegó cada reserva
                                </div>
                            </div>
                        </div>
                        <div class="flex-1 px-4 py-3">
                            <div v-if="byChannel.length" class="space-y-3">
                                <div
                                    v-for="row in byChannel"
                                    :key="row.channel"
                                >
                                    <div class="flex items-center text-xs">
                                        <Lucide
                                            :icon="
                                                channelIcons[row.channel] ??
                                                'Tag'
                                            "
                                            class="mr-1.5 h-3.5 w-3.5 text-slate-400"
                                        />
                                        <span
                                            class="text-slate-600 dark:text-slate-300"
                                            >{{
                                                channelLabels[row.channel] ??
                                                row.channel
                                            }}</span
                                        >
                                        <span class="ml-auto font-medium">{{
                                            row.count
                                        }}</span>
                                    </div>
                                    <div
                                        class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                                    >
                                        <div
                                            class="h-full rounded-full bg-primary/70"
                                            :style="{
                                                width: `${(row.count / maxChannel) * 100}%`,
                                            }"
                                        />
                                    </div>
                                </div>
                            </div>
                            <div
                                v-else
                                class="flex flex-col items-center gap-2 py-8 text-slate-400"
                            >
                                <Lucide icon="Radio" class="h-6 w-6" />
                                <p class="text-xs">Sin datos en el periodo.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cuándo se reservó y si se pagó -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel"
                    >Cuándo se reservó y si se pagó</span
                >
            </div>
            <div class="mt-2 grid grid-cols-12 items-stretch gap-4">
                <div class="col-span-12 flex flex-col xl:col-span-4">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-primary/10 bg-primary/10 text-primary',
                                ]"
                            >
                                <Lucide icon="Clock" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Anticipación
                                </div>
                                <div class="text-xs text-slate-500">
                                    Del día que se reservó al de llegada
                                </div>
                            </div>
                            <span
                                class="ml-auto rounded-full bg-primary/10 px-2.5 py-1 text-[11px] font-medium text-primary"
                                >{{
                                    plural(kpis.avg_lead_days, 'día', 'días')
                                }}</span
                            >
                        </div>
                        <div class="flex-1 px-4 py-3">
                            <div class="space-y-3">
                                <div v-for="row in lead" :key="row.label">
                                    <div class="flex items-center text-xs">
                                        <span
                                            class="text-slate-600 dark:text-slate-300"
                                            >{{ row.label }}</span
                                        >
                                        <span class="ml-auto font-medium">{{
                                            row.count
                                        }}</span>
                                    </div>
                                    <div
                                        class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                                    >
                                        <div
                                            class="h-full rounded-full bg-primary/70"
                                            :style="{
                                                width: `${(row.count / maxLead) * 100}%`,
                                            }"
                                        />
                                    </div>
                                </div>
                            </div>
                            <p class="mt-3 text-[11px] text-slate-400">
                                {{ kpis.created }} reservas se capturaron dentro
                                de este rango, sin importar cuándo llegan.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 flex flex-col xl:col-span-8">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-warning/10 bg-warning/10 text-warning',
                                ]"
                            >
                                <Lucide icon="ReceiptText" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Estado de pago
                                </div>
                                <div class="text-xs text-slate-500">
                                    Reservas efectivas del rango
                                </div>
                            </div>
                        </div>
                        <div
                            class="flex-1 divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="row in paymentStatus"
                                :key="row.status"
                                class="flex flex-wrap items-center gap-2.5 px-4 py-3 sm:px-5"
                            >
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        paymentTone[row.status] ??
                                        'bg-slate-100 text-slate-500'
                                    "
                                    >{{ row.label }}</span
                                >
                                <span class="text-sm font-medium">{{
                                    row.count
                                }}</span>
                                <div
                                    class="h-2 min-w-[80px] flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                                >
                                    <div
                                        class="h-full rounded-full bg-primary/60"
                                        :style="{
                                            width: `${(row.count / paymentStatusMax) * 100}%`,
                                        }"
                                    />
                                </div>
                                <div class="text-right text-xs">
                                    <div :class="stripValue">
                                        {{ fmt(row.value) }}
                                    </div>
                                    <div
                                        class="text-[11px]"
                                        :class="
                                            row.pending > 0
                                                ? 'text-warning'
                                                : 'text-slate-400'
                                        "
                                    >
                                        Falta {{ fmt(row.pending) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detalle reserva por reserva -->
                <div class="col-span-12">
                    <div class="box box--stacked overflow-hidden">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-primary/10 bg-primary/10 text-primary',
                                ]"
                            >
                                <Lucide icon="ListOrdered" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Detalle del periodo
                                </div>
                                <div class="text-xs text-slate-500">
                                    Cuándo se hizo cada reserva, qué pagó y qué
                                    falta
                                </div>
                            </div>
                            <span
                                v-if="
                                    detailLimit !== null &&
                                    detailTotal > detail.length
                                "
                                class="ml-auto rounded-full bg-slate-100 px-2.5 py-1 text-[11px] text-slate-500 dark:bg-darkmode-400"
                            >
                                {{ detail.length }} de {{ detailTotal }} · el
                                PDF trae todas
                            </span>
                        </div>
                        <div class="overflow-x-auto">
                            <Table v-if="detail.length" class="text-xs">
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th class="text-[11px]"
                                            >Folio</Table.Th
                                        >
                                        <Table.Th class="text-[11px]"
                                            >Huésped</Table.Th
                                        >
                                        <Table.Th class="text-[11px]"
                                            >Habitación</Table.Th
                                        >
                                        <Table.Th class="text-[11px]"
                                            >Se reservó</Table.Th
                                        >
                                        <Table.Th class="text-[11px]"
                                            >Estancia</Table.Th
                                        >
                                        <Table.Th class="text-[11px]"
                                            >Pago</Table.Th
                                        >
                                        <Table.Th class="text-right text-[11px]"
                                            >Total</Table.Th
                                        >
                                        <Table.Th class="text-right text-[11px]"
                                            >Pagado</Table.Th
                                        >
                                        <Table.Th class="text-right text-[11px]"
                                            >Saldo</Table.Th
                                        >
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    <Table.Tr
                                        v-for="row in detail"
                                        :key="row.id"
                                    >
                                        <Table.Td>
                                            <Link
                                                :href="
                                                    route(
                                                        'tenant.reservations.detail',
                                                        row.id,
                                                    )
                                                "
                                                class="font-medium hover:text-primary"
                                                >{{ row.code }}</Link
                                            >
                                            <div
                                                class="mt-0.5 text-[11px] text-slate-400"
                                            >
                                                {{
                                                    channelLabels[
                                                        row.channel
                                                    ] ?? row.channel
                                                }}
                                            </div>
                                        </Table.Td>
                                        <Table.Td class="max-w-[160px]">
                                            <div class="truncate font-medium">
                                                {{ row.guest }}
                                            </div>
                                            <span
                                                class="mt-0.5 inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                :class="
                                                    statusTone[row.status] ??
                                                    'bg-slate-100 text-slate-500'
                                                "
                                                >{{ row.status_label }}</span
                                            >
                                        </Table.Td>
                                        <Table.Td class="text-slate-500">{{
                                            row.room
                                        }}</Table.Td>
                                        <Table.Td>
                                            <div>{{ row.created_at }}</div>
                                            <div
                                                class="text-[11px] text-slate-400"
                                            >
                                                {{
                                                    row.lead_days === null
                                                        ? '—'
                                                        : row.lead_days === 0
                                                          ? 'mismo día'
                                                          : `${plural(row.lead_days, 'día', 'días')} antes`
                                                }}
                                            </div>
                                        </Table.Td>
                                        <Table.Td class="whitespace-nowrap">
                                            {{ row.starts_at }}
                                            <span class="text-slate-400"
                                                >→</span
                                            >
                                            {{ row.ends_at }}
                                        </Table.Td>
                                        <Table.Td>
                                            <span
                                                class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                :class="
                                                    paymentTone[
                                                        row.payment_status ??
                                                            'unpaid'
                                                    ] ??
                                                    'bg-slate-100 text-slate-500'
                                                "
                                                >{{ row.payment_label }}</span
                                            >
                                        </Table.Td>
                                        <Table.Td class="text-right">{{
                                            fmt(row.total)
                                        }}</Table.Td>
                                        <Table.Td
                                            class="text-right text-success"
                                            >{{ fmt(row.paid) }}</Table.Td
                                        >
                                        <Table.Td
                                            class="text-right font-medium"
                                            :class="
                                                row.pending > 0
                                                    ? 'text-warning'
                                                    : 'text-slate-400'
                                            "
                                            >{{ fmt(row.pending) }}</Table.Td
                                        >
                                    </Table.Tr>
                                </Table.Tbody>
                            </Table>
                            <div
                                v-else
                                class="py-10 text-center text-xs text-slate-500"
                            >
                                Sin reservas en el periodo.
                            </div>
                        </div>
                        <div
                            v-if="
                                detailLimit !== null &&
                                detailTotal > detail.length
                            "
                            class="border-t border-slate-200/60 px-4 py-3 text-[11px] text-slate-500 dark:border-darkmode-400"
                        >
                            Se muestran las
                            {{ detail.length }} de mayor saldo. Para verlas
                            todas descarga el PDF o entra a
                            <Link
                                :href="route('tenant.reservations.history')"
                                class="font-medium text-primary"
                                >Historial</Link
                            >.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
