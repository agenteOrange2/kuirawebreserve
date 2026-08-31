<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import Chart from '@/components/Base/Chart';
import { FormDate, FormSelect } from '@/components/Base/Form';
import { Tab } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface Metric {
    title: string;
    value: string;
    change: number | null;
    desc: string;
}
interface SeriesPoint {
    label: string;
    value: number;
}
interface StatusCount {
    value: string;
    label: string;
    color: string;
    count: number;
}
interface ActivityRow {
    id: number;
    room: string | null;
    from: string | null;
    to: string;
    to_color: string;
    by: string;
    at: string;
    at_full: string;
}
interface ArrivalRow {
    id: number;
    code: string;
    guest_name: string;
    room: string | null;
    eta: string | null;
    time: string;
    people: number | null;
    checked_in: boolean;
}
interface DepartureRow {
    id: number;
    code: string;
    guest_name: string;
    room: string | null;
    time: string;
    balance: number;
}

const props = defineProps<{
    filters: { range: string; from: string | null; to: string | null };
    expiringHolds: {
        id: number;
        code: string;
        guest_name: string | null;
        room: string | null;
        expires_at: string;
    }[];
    hero: { revenue: string; change: number | null; period: string };
    metrics: Metric[];
    series: {
        label: string;
        revenue: SeriesPoint[];
        occupancy: SeriesPoint[];
        revenue_total: number;
        revenue_change: number | null;
        occupancy_avg: number;
        occupancy_change: number | null;
    };
    guestStatus: { in_house: number; checked_out: number; pending: number };
    roomTypeDistribution: { label: string; count: number }[];
    // Mantenimiento pendiente (null sin el módulo incidencias).
    maintenance: {
        open: number;
        overdue: number;
        high: number;
        items: {
            id: number;
            title: string;
            room: string | null;
            priority: string;
            priority_label: string;
            overdue: boolean;
            age_hours: number;
        }[];
    } | null;
    statuses: StatusCount[];
    occupancy: {
        occupied: number;
        total: number;
        percent: number;
        reserved: number;
        available: number;
    };
    arrivals: ArrivalRow[];
    departures: DepartureRow[];
    totals: {
        rooms: number;
        zones: number;
        roomTypes: number;
        staff: number;
        properties: number;
    };
    plan: { name: string; max_rooms: number | null; max_users: number | null };
    recentActivity: ActivityRow[];
}>();

// Antigüedad en palabras: "hace 3 días" pesa más que "454 h".
const ageLabel = (hours: number) => {
    if (hours < 1) return 'recién';
    if (hours < 24) return `hace ${hours} h`;
    const days = Math.floor(hours / 24);

    return `hace ${days} ${days === 1 ? 'día' : 'días'}`;
};

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 }).format(n ?? 0);

// ── Filtro de periodo (hoy / semana / mes / personalizado) ──
const range = ref(props.filters.range);
const customFrom = ref(props.filters.from ?? '');
const customTo = ref(props.filters.to ?? '');

// El backend recorta el rango personalizado a este máximo; el input lo
// impide antes para que nadie pida 6 meses y reciba 3 sin enterarse.
const MAX_CUSTOM_DAYS = 92;

const maxCustomTo = computed(() => {
    if (!customFrom.value) return undefined;
    const limit = new Date(`${customFrom.value}T00:00`);
    limit.setDate(limit.getDate() + MAX_CUSTOM_DAYS);

    return limit.toISOString().slice(0, 10);
});

function applyPeriod() {
    // El personalizado espera a tener ambas fechas antes de recargar.
    if (range.value === 'custom' && (!customFrom.value || !customTo.value)) {
        return;
    }
    router.get(
        route('tenant.dashboard'),
        {
            range: range.value,
            from: range.value === 'custom' ? customFrom.value : undefined,
            to: range.value === 'custom' ? customTo.value : undefined,
        },
        { preserveScroll: true, preserveState: true },
    );
}

// Semáforo -> tokens del theme (primary/success/info/warning/pending/dark).
const dotColor: Record<string, string> = {
    green: 'bg-success',
    cyan: 'bg-info',
    red: 'bg-primary',
    orange: 'bg-pending',
    blue: 'bg-warning',
    gray: 'bg-dark',
};
const tint: Record<string, string> = {
    green: 'border-success/10 bg-success/10 text-success',
    cyan: 'border-info/10 bg-info/10 text-info',
    red: 'border-primary/10 bg-primary/10 text-primary',
    orange: 'border-pending/10 bg-pending/10 text-pending',
    blue: 'border-warning/10 bg-warning/10 text-warning',
    gray: 'border-dark/10 bg-dark/10 text-dark',
};
const statusIcon: Record<string, Icon> = {
    available: 'BedDouble',
    reserved: 'CalendarClock',
    occupied: 'UserCheck',
    dirty: 'Trash2',
    cleaning: 'Sparkles',
    maintenance: 'Wrench',
};

const hasRooms = computed(() => props.totals.rooms > 0);

const lineOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { enabled: true } },
    scales: { x: { display: false }, y: { display: false, beginAtZero: true } },
    elements: { point: { radius: 0 }, line: { tension: 0.4, borderWidth: 2 } },
};
const occupancyLine = computed(() => ({
    labels: props.series.occupancy.map((p) => p.label),
    datasets: [
        {
            data: props.series.occupancy.map((p) => p.value),
            borderColor: '#03045e',
            backgroundColor: 'rgba(3,4,94,0.08)',
            fill: true,
        },
    ],
}));
const revenueLine = computed(() => ({
    labels: props.series.revenue.map((p) => p.label),
    datasets: [
        {
            data: props.series.revenue.map((p) => p.value),
            borderColor: '#0d9488',
            backgroundColor: 'rgba(13,148,136,0.10)',
            fill: true,
        },
    ],
}));

// Donut de tipos con la paleta del theme.
// 10 tonos para que un hotel con muchos tipos no repita color (antes eran
// 7 y la 8ª cabaña salía igual que la 1ª).
const donutPalette = [
    '#03045e',
    '#0891b2',
    '#0d9488',
    '#ca8a04',
    '#c2410c',
    '#b91c1c',
    '#1e293b',
    '#0c4a6e',
    '#64748b',
    '#94a3b8',
];
const donutData = computed(() => ({
    labels: props.roomTypeDistribution.map((t) => t.label),
    datasets: [
        {
            data: props.roomTypeDistribution.map((t) => t.count),
            backgroundColor: props.roomTypeDistribution.map(
                (_, i) => donutPalette[i % donutPalette.length],
            ),
            borderWidth: 0,
            hoverOffset: 4,
        },
    ],
}));
const donutOptions = {
    cutout: '75%',
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { enabled: true } },
};

// Un periodo sin movimiento dibujaba una raya plana al fondo del canvas:
// se ve como gráfica rota. Con esto la tarjeta dice que no hubo nada.
const hasOccupancyData = computed(() =>
    props.series.occupancy.some((p) => p.value > 0),
);
const hasRevenueData = computed(() =>
    props.series.revenue.some((p) => p.value > 0),
);

const barSegments = computed(() =>
    props.statuses
        .filter((s) => s.count > 0)
        .map((s) => ({
            ...s,
            width:
                props.totals.rooms > 0
                    ? (s.count / props.totals.rooms) * 100
                    : 0,
        })),
);

// Movimiento del día para la tarjeta de huéspedes: antes solo decía
// cuántos hay en casa y el resto de la tarjeta era hueco.
const guestTiles = computed(() => [
    {
        key: 'arrivals',
        label: 'Llegadas hoy',
        value: props.arrivals.length,
        icon: 'LogIn' as Icon,
        tint: 'border-success/10 bg-success/10 text-success',
        title: `${props.arrivals.filter((a) => !a.checked_in).length} sin registrar`,
    },
    {
        key: 'departures',
        label: 'Salidas hoy',
        value: props.departures.length,
        icon: 'LogOut' as Icon,
        tint: 'border-pending/10 bg-pending/10 text-pending',
        title: 'Estancias que terminan hoy',
    },
    {
        key: 'pending',
        label: 'Por confirmar',
        value: props.guestStatus.pending,
        icon: 'CalendarClock' as Icon,
        tint: 'border-warning/10 bg-warning/10 text-warning',
        title: 'Reservas pendientes de confirmar',
    },
    {
        key: 'balance',
        label: 'Con saldo',
        value: props.departures.filter((d) => d.balance > 0).length,
        icon: 'Wallet' as Icon,
        tint: 'border-danger/10 bg-danger/10 text-danger',
        title: 'Salidas de hoy que aún deben dinero',
    },
]);

const activityIcon = (color: string): Icon => {
    const status = props.statuses.find((s) => s.color === color);
    return status ? statusIcon[status.value] : 'Activity';
};

interface QuickAction {
    label: string;
    icon: Icon;
    route: string;
    color: string;
}
const quickActions: QuickAction[] = [
    {
        label: 'Nueva reserva',
        icon: 'CalendarPlus',
        route: 'tenant.reservations',
        color: 'border-primary/10 bg-primary/10 text-primary',
    },
    {
        label: 'Cobrar / POS',
        icon: 'ShoppingCart',
        route: 'tenant.pos',
        color: 'border-success/10 bg-success/10 text-success',
    },
    {
        label: 'Ver plano',
        icon: 'Map',
        route: 'tenant.plano',
        color: 'border-info/10 bg-info/10 text-info',
    },
    {
        label: 'Huéspedes',
        icon: 'Users',
        route: 'tenant.guests',
        color: 'border-pending/10 bg-pending/10 text-pending',
    },
];

const cellClass =
    'box shadow-[5px_3px_5px_#00000005] first:border-l last:border-r first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] rounded-l-none rounded-r-none border-x-0 dark:bg-darkmode-600';
</script>

<template>
    <RazeLayout title="Dashboard">
        <div class="grid grid-cols-12 gap-x-5 gap-y-6">
            <!-- Holds por vencer: apartados que expiran en < 30 min -->
            <div v-if="expiringHolds.length" class="col-span-12 -mb-5">
                <div class="box box--stacked border-l-4 border-l-warning p-4">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <div
                            class="flex items-center gap-2 text-xs font-medium"
                        >
                            <Lucide
                                icon="AlarmClock"
                                class="h-4 w-4 text-warning"
                            />
                            Apartados por vencer
                        </div>
                        <Link
                            v-for="hold in expiringHolds"
                            :key="hold.id"
                            :href="
                                route('tenant.reservations', {
                                    reservation: hold.id,
                                })
                            "
                            class="flex items-center gap-1.5 rounded-full bg-warning/10 px-3 py-1.5 text-xs font-medium text-warning transition hover:bg-warning/20"
                        >
                            {{ hold.code }} ·
                            {{ hold.guest_name ?? 'Sin nombre'
                            }}<template v-if="hold.room">
                                · hab. {{ hold.room }}</template
                            >
                            <span class="font-normal"
                                >expira {{ hold.expires_at }}</span
                            >
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Mantenimiento pendiente: una falla abierta no puede
                 esperar a que alguien entre a /incidencias. -->
            <div
                v-if="maintenance && maintenance.open > 0"
                class="col-span-12 -mb-5"
            >
                <div
                    class="box box--stacked p-4"
                    :class="
                        maintenance.overdue > 0
                            ? 'border-l-4 border-l-danger'
                            : 'border-l-4 border-l-pending'
                    "
                >
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <div
                            class="flex items-center gap-2 text-xs font-medium"
                        >
                            <Lucide
                                icon="Wrench"
                                class="h-4 w-4"
                                :class="
                                    maintenance.overdue > 0
                                        ? 'text-danger'
                                        : 'text-pending'
                                "
                            />
                            <template v-if="maintenance.overdue > 0">
                                {{ maintenance.overdue }} falla(s) sin atender
                            </template>
                            <template v-else>
                                {{ maintenance.open }} falla(s) pendiente(s)
                            </template>
                        </div>
                        <Link
                            v-for="item in maintenance.items"
                            :key="item.id"
                            :href="route('tenant.incidents.show', item.id)"
                            class="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium transition"
                            :class="
                                item.overdue
                                    ? 'bg-danger/10 text-danger hover:bg-danger/20'
                                    : 'bg-pending/10 text-pending hover:bg-pending/20'
                            "
                        >
                            <template v-if="item.room"
                                >Hab. {{ item.room }} ·
                            </template>
                            {{ item.title }}
                            <span class="font-normal">
                                {{ ageLabel(item.age_hours) }}
                            </span>
                        </Link>
                        <Link
                            :href="route('tenant.incidents')"
                            class="text-xs font-medium text-primary hover:underline"
                        >
                            Ver todas
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Onboarding -->
            <div v-if="!hasRooms" class="col-span-12">
                <div class="box box--stacked border-l-4 border-l-primary p-4">
                    <div class="flex flex-wrap items-center gap-4">
                        <Lucide icon="Sparkles" class="h-6 w-6 text-primary" />
                        <div class="flex-1">
                            <div class="font-medium">
                                ¡Bienvenido! Configura tu hotel en 2 pasos
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                1) Define tus
                                <Link
                                    :href="route('tenant.catalog')"
                                    class="text-primary underline"
                                    >zonas y tipos</Link
                                >
                                · 2) Da de alta tus
                                <Link
                                    :href="route('tenant.rooms')"
                                    class="text-primary underline"
                                    >habitaciones</Link
                                >
                                y acomódalas en el
                                <Link
                                    :href="route('tenant.plano')"
                                    class="text-primary underline"
                                    >plano</Link
                                >.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===================== Hotel Performance Insights ===================== -->
            <div class="col-span-12 flex flex-col 2xl:col-span-9">
                <!-- Móvil: título arriba y botones en par a lo ancho; en una
                     sola fila aplastaban el título contra el borde. -->
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-center md:h-9"
                >
                    <div class="text-sm font-medium">Resumen operativo</div>
                    <div
                        class="grid grid-cols-2 gap-2 sm:ml-auto sm:flex sm:gap-2"
                    >
                        <Button
                            as="a"
                            :href="route('tenant.plano')"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs"
                        >
                            <Lucide
                                icon="Map"
                                class="mr-1.5 h-3.5 w-3.5 stroke-[1.3]"
                            />
                            Plano
                        </Button>
                        <Button
                            as="a"
                            :href="route('tenant.reservations')"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        >
                            <Lucide
                                icon="CalendarPlus"
                                class="mr-1.5 h-3.5 w-3.5 stroke-[1.3]"
                            />
                            Nueva reserva
                        </Button>
                    </div>
                </div>
                <div class="mt-3 flex flex-1">
                    <div
                        class="box box--stacked flex w-full flex-col gap-3 p-3 xl:flex-row"
                    >
                        <!-- Hero de ingresos -->
                        <div
                            class="relative flex flex-col items-center gap-5 overflow-hidden rounded-[0.6rem] bg-gradient-to-b from-theme-2/90 to-theme-1/[0.85] px-5 py-6 xl:w-[300px] xl:flex-none xl:items-start"
                        >
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full border border-white/10 bg-white/10"
                            >
                                <Lucide
                                    icon="CreditCard"
                                    class="h-5 w-5 fill-white/10 text-white"
                                />
                            </div>
                            <div class="text-center xl:text-left">
                                <div class="text-xs text-white/90">
                                    Ingresos de {{ hero.period }}
                                </div>
                                <div
                                    class="mt-2 flex items-center justify-center xl:justify-start"
                                >
                                    <div class="text-xl font-medium text-white">
                                        {{ hero.revenue }}
                                    </div>
                                    <div
                                        v-if="hero.change !== null"
                                        class="ml-2.5 flex items-center rounded-full border border-success/50 bg-success/50 py-[2px] pr-1 pl-[7px] text-xs font-medium text-white/90"
                                    >
                                        {{ Math.abs(hero.change) }}%
                                        <Lucide
                                            :icon="
                                                hero.change >= 0
                                                    ? 'ChevronUp'
                                                    : 'ChevronDown'
                                            "
                                            class="ml-px h-4 w-4 stroke-[1.5]"
                                        />
                                    </div>
                                </div>
                                <div
                                    class="mt-2 text-xs leading-normal text-white/70"
                                >
                                    Pagos de reservas y ventas de POS en el
                                    periodo elegido.
                                </div>
                            </div>
                            <div class="mt-auto w-full">
                                <Button
                                    as="a"
                                    :href="route('tenant.reservations')"
                                    rounded
                                    class="relative w-full justify-start border-white/20 bg-white/10 px-4 py-2 text-xs text-white hover:bg-white/[0.15]"
                                >
                                    Ver reservas
                                    <div
                                        class="absolute right-0 mr-0.5 flex h-8 w-8 items-center justify-center rounded-full border border-white/10 bg-white/10"
                                    >
                                        <Lucide
                                            icon="ArrowRight"
                                            class="h-4 w-4"
                                        />
                                    </div>
                                </Button>
                            </div>
                        </div>

                        <!-- Rejilla de métricas -->
                        <div
                            class="flex w-full flex-col rounded-[0.6rem] border border-dashed border-slate-300/80 p-4 sm:px-5 sm:py-5"
                        >
                            <div
                                class="flex flex-col gap-x-3 gap-y-2 sm:flex-row sm:items-center"
                            >
                                <div class="relative">
                                    <Lucide
                                        icon="CalendarCheck2"
                                        class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3]"
                                    />
                                    <FormSelect
                                        v-model="range"
                                        class="pl-9 sm:w-44"
                                        @change="applyPeriod"
                                    >
                                        <option value="today">Hoy</option>
                                        <option value="week">
                                            Esta semana
                                        </option>
                                        <option value="month">Este mes</option>
                                        <option value="custom">
                                            Personalizado
                                        </option>
                                    </FormSelect>
                                </div>
                                <div class="text-xs text-slate-500 sm:ml-auto">
                                    Comparado con el periodo anterior
                                </div>
                            </div>

                            <!-- Las dos fechas van en su propio renglón: en
                                 la misma fila del selector se partían a media
                                 frase ("Del [fecha] al" y el segundo campo
                                 abajo). -->
                            <div
                                v-if="range === 'custom'"
                                class="mt-4 flex flex-col gap-3 rounded-[0.6rem] border border-dashed border-slate-300/80 p-3 sm:flex-row sm:items-center dark:border-darkmode-400"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <label
                                        for="range-from"
                                        class="text-xs text-slate-500"
                                        >Del</label
                                    >
                                    <FormDate
                                        id="range-from"
                                        v-model="customFrom"
                                        class="w-full sm:w-44"
                                        :max="customTo || null"
                                        @change="applyPeriod"
                                    />
                                    <label
                                        for="range-to"
                                        class="text-xs text-slate-500"
                                        >al</label
                                    >
                                    <FormDate
                                        id="range-to"
                                        v-model="customTo"
                                        class="w-full sm:w-44"
                                        :min="customFrom || null"
                                        :max="maxCustomTo"
                                        @change="applyPeriod"
                                    />
                                </div>
                                <p
                                    class="flex items-center gap-1.5 text-xs text-slate-500 sm:ml-auto"
                                >
                                    <Lucide
                                        icon="Info"
                                        class="h-3.5 w-3.5 flex-none stroke-[1.3] text-slate-400"
                                    />
                                    <template v-if="!customFrom || !customTo">
                                        Elige las dos fechas para ver el
                                        periodo.
                                    </template>
                                    <template v-else>
                                        Hasta {{ MAX_CUSTOM_DAYS }} días por
                                        consulta.
                                    </template>
                                </p>
                            </div>
                            <div
                                class="mt-4 grid flex-1 grid-cols-2 gap-x-5 gap-y-4 sm:grid-cols-3 xl:grid-cols-4"
                            >
                                <div
                                    v-for="metric in metrics"
                                    :key="metric.title"
                                    class="flex flex-col justify-center"
                                >
                                    <div class="mb-1 flex items-center">
                                        <div class="text-base font-medium">
                                            {{ metric.value }}
                                        </div>
                                        <div
                                            v-if="metric.change !== null"
                                            :class="[
                                                '-mr-1 ml-2 flex items-center text-xs',
                                                metric.change < 0
                                                    ? 'text-danger'
                                                    : 'text-success',
                                            ]"
                                        >
                                            {{ Math.abs(metric.change) }}%
                                            <Lucide
                                                class="ml-px h-4 w-4 stroke-[1]"
                                                :icon="
                                                    metric.change < 0
                                                        ? 'ChevronDown'
                                                        : 'ChevronUp'
                                                "
                                            />
                                        </div>
                                    </div>
                                    <div
                                        class="flex items-start text-xs leading-tight text-slate-500"
                                    >
                                        <span>{{ metric.title }}</span>
                                        <span
                                            :title="metric.desc"
                                            class="inline-flex cursor-help"
                                        >
                                            <Lucide
                                                class="ml-1.5 h-3.5 w-3.5 stroke-[1.3] text-slate-400"
                                                icon="Info"
                                            />
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===================== Ocupación vs Ingresos ===================== -->
            <div class="col-span-12 flex flex-col 2xl:col-span-3">
                <div class="flex items-center md:h-9">
                    <div class="text-sm font-medium">
                        Ocupación vs. ingresos
                    </div>
                </div>
                <!-- Móvil: apiladas (lado a lado se aplastan y el canvas de
                     la gráfica desborda la página). -->
                <div
                    class="mt-3 grid flex-1 grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-6 2xl:auto-rows-fr 2xl:grid-cols-1"
                >
                    <div class="box box--stacked flex min-w-0 flex-col p-4">
                        <div class="text-xs text-slate-500">
                            Ocupación · {{ series.label }}
                        </div>
                        <div class="mt-1 flex items-center">
                            <div class="text-lg font-medium">
                                {{ series.occupancy_avg }}%
                            </div>
                            <div
                                v-if="series.occupancy_change !== null"
                                :class="[
                                    '-mr-1 ml-2 flex items-center text-xs',
                                    series.occupancy_change < 0
                                        ? 'text-danger'
                                        : 'text-success',
                                ]"
                            >
                                {{ Math.abs(series.occupancy_change) }}%
                                <Lucide
                                    :icon="
                                        series.occupancy_change < 0
                                            ? 'ChevronDown'
                                            : 'ChevronUp'
                                    "
                                    class="ml-px h-4 w-4"
                                />
                            </div>
                        </div>
                        <div
                            class="mt-4 flex min-h-[87px] w-full min-w-0 flex-1 items-end"
                        >
                            <Chart
                                v-if="hasOccupancyData"
                                type="line"
                                :data="occupancyLine"
                                :options="lineOptions"
                                class="!h-[87px] w-full"
                            />
                            <div
                                v-else
                                class="flex h-[87px] w-full flex-col items-center justify-center gap-1.5 rounded-[0.6rem] border border-dashed border-slate-300/70 text-center dark:border-darkmode-400"
                            >
                                <Lucide
                                    icon="BedDouble"
                                    class="h-4 w-4 text-slate-300"
                                />
                                <span class="text-xs text-slate-400">
                                    Sin noches ocupadas en el periodo
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="box box--stacked flex min-w-0 flex-col p-4">
                        <div class="text-xs text-slate-500">
                            Ingresos · {{ series.label }}
                        </div>
                        <div class="mt-1 flex items-center">
                            <div class="text-lg font-medium">
                                {{ money(series.revenue_total) }}
                            </div>
                            <div
                                v-if="series.revenue_change !== null"
                                :class="[
                                    '-mr-1 ml-2 flex items-center text-xs',
                                    series.revenue_change < 0
                                        ? 'text-danger'
                                        : 'text-success',
                                ]"
                            >
                                {{ Math.abs(series.revenue_change) }}%
                                <Lucide
                                    :icon="
                                        series.revenue_change < 0
                                            ? 'ChevronDown'
                                            : 'ChevronUp'
                                    "
                                    class="ml-px h-4 w-4"
                                />
                            </div>
                        </div>
                        <div
                            class="mt-4 flex min-h-[87px] w-full min-w-0 flex-1 items-end"
                        >
                            <Chart
                                v-if="hasRevenueData"
                                type="line"
                                :data="revenueLine"
                                :options="lineOptions"
                                class="!h-[87px] w-full"
                            />
                            <div
                                v-else
                                class="flex h-[87px] w-full flex-col items-center justify-center gap-1.5 rounded-[0.6rem] border border-dashed border-slate-300/70 text-center dark:border-darkmode-400"
                            >
                                <Lucide
                                    icon="Receipt"
                                    class="h-4 w-4 text-slate-300"
                                />
                                <span class="text-xs text-slate-400">
                                    Sin cobros en el periodo
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===================== Actividad reciente ===================== -->
            <div class="col-span-12 flex flex-col md:col-span-6 xl:col-span-4">
                <div class="flex items-center md:h-9">
                    <div class="text-sm font-medium">Actividad reciente</div>
                    <span
                        v-if="guestStatus.pending"
                        class="ml-auto rounded-full bg-pending/10 px-2.5 py-0.5 text-xs font-medium text-pending"
                    >
                        {{ guestStatus.pending }} por confirmar
                    </span>
                </div>
                <div class="box box--stacked mt-3 flex flex-1 flex-col p-4">
                    <div
                        class="mb-4 border-b border-dashed border-slate-300/70 pb-4"
                    >
                        <div class="flex items-center">
                            <div class="text-lg font-medium">
                                {{ occupancy.occupied }}/{{ occupancy.total }}
                            </div>
                            <div class="ml-2 text-xs text-slate-500">
                                habitaciones ocupadas
                            </div>
                            <div
                                class="ml-auto text-xs font-medium text-slate-500"
                            >
                                {{ occupancy.percent }}%
                            </div>
                        </div>
                        <div
                            class="mt-3 flex h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                        >
                            <div
                                v-for="seg in barSegments"
                                :key="seg.value"
                                class="h-full first:rounded-l-full last:rounded-r-full"
                                :class="dotColor[seg.color]"
                                :style="{ width: `${seg.width}%` }"
                                :title="`${seg.label}: ${seg.count}`"
                            />
                        </div>
                        <!-- La barra es el semáforo completo, no ocupación:
                             sin leyenda, 8 habitaciones limpias se leían como
                             "lleno". -->
                        <div
                            v-if="barSegments.length"
                            class="mt-2.5 flex flex-wrap gap-x-4 gap-y-1"
                        >
                            <span
                                v-for="seg in barSegments"
                                :key="seg.value"
                                class="flex items-center text-xs text-slate-500"
                            >
                                <span
                                    class="mr-1.5 h-1.5 w-1.5 rounded-full"
                                    :class="dotColor[seg.color]"
                                />
                                {{ seg.label }}
                                <span class="ml-1 font-medium text-slate-600">{{
                                    seg.count
                                }}</span>
                            </span>
                        </div>
                    </div>
                    <div
                        v-if="recentActivity.length"
                        class="flex flex-col gap-3.5"
                    >
                        <div
                            v-for="log in recentActivity"
                            :key="log.id"
                            class="flex items-center"
                        >
                            <div
                                class="flex h-9 w-9 flex-none items-center justify-center rounded-full border"
                                :class="tint[log.to_color]"
                            >
                                <Lucide
                                    :icon="activityIcon(log.to_color)"
                                    class="h-4 w-4"
                                />
                            </div>
                            <div
                                class="ml-3 flex min-w-0 flex-1 items-center gap-3"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium">
                                        Hab. {{ log.room ?? '—' }}
                                    </div>
                                    <div
                                        class="mt-0.5 line-clamp-2 text-xs leading-tight text-slate-500"
                                        :title="`${log.from ?? '—'} → ${log.to} · ${log.by}`"
                                    >
                                        {{ log.from ?? '—' }} → {{ log.to }} ·
                                        {{ log.by }}
                                    </div>
                                </div>
                                <span
                                    class="flex flex-none items-center rounded-lg border px-2.5 py-1 text-xs font-medium whitespace-nowrap"
                                    :class="tint[log.to_color]"
                                    :title="log.at_full"
                                >
                                    <span
                                        class="mr-1.5 h-1 w-1 rounded-full"
                                        :class="dotColor[log.to_color]"
                                    />
                                    <span class="-mt-px">{{ log.at }}</span>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="py-6 text-center text-xs text-slate-500">
                        Sin movimientos todavía.
                    </div>
                </div>
            </div>

            <!-- ===================== Estado de huéspedes ===================== -->
            <div class="col-span-12 flex flex-col md:col-span-6 xl:col-span-4">
                <div class="flex items-center md:h-9">
                    <div class="text-sm font-medium">Estado de huéspedes</div>
                </div>
                <div class="box box--stacked mt-3 flex flex-1 flex-col">
                    <!-- Banner del theme, más bajo: con el original la
                         tarjeta quedaba medio vacía cuando no hay nadie. -->
                    <div
                        class="box relative m-2.5 flex flex-col rounded-[0.6rem] border border-dashed bg-gradient-to-b from-transparent to-theme-1/[0.03] pt-[40px] shadow-sm before:absolute before:inset-0 before:bg-texture-black before:bg-cover before:bg-[center_1rem] before:bg-no-repeat before:opacity-90 before:content-['']"
                    >
                        <div
                            class="z-10 mx-auto mt-auto -mb-5 h-12 w-12 rounded-full border border-theme-1/20 bg-white/80 p-1"
                        >
                            <div
                                class="relative z-10 flex h-full w-full items-center justify-center rounded-full border border-primary/[0.15] bg-gradient-to-b from-theme-2/90 to-theme-1/[0.85] shadow-sm"
                            >
                                <Lucide
                                    icon="UserCheck"
                                    class="h-5 w-5 fill-white/10 text-white"
                                />
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-1 flex-col p-5">
                        <div class="mt-6 text-center">
                            <div class="flex items-center justify-center">
                                <div class="text-lg font-medium">
                                    {{ guestStatus.in_house }}
                                </div>
                                <div class="ml-2 text-xs text-slate-500">
                                    en casa
                                </div>
                            </div>
                            <div class="mt-1.5 text-xs text-slate-500">
                                Huéspedes hospedados en este momento
                            </div>
                        </div>

                        <!-- Movimiento del día: lo que hay que atender hoy -->
                        <!-- auto-rows-fr: si la tarjeta se estira para
                             emparejar a sus vecinas, crecen las casillas en
                             vez de dejar un hueco. -->
                        <div
                            class="mt-4 grid flex-1 auto-rows-fr grid-cols-2 gap-2.5"
                        >
                            <div
                                v-for="tile in guestTiles"
                                :key="tile.key"
                                :title="tile.title"
                                class="flex items-center gap-2.5 rounded-[0.6rem] border border-slate-200/70 bg-slate-50/60 p-2.5 dark:border-darkmode-400 dark:bg-darkmode-700"
                            >
                                <div
                                    class="flex h-9 w-9 flex-none items-center justify-center rounded-full border"
                                    :class="tile.tint"
                                >
                                    <Lucide :icon="tile.icon" class="h-4 w-4" />
                                </div>
                                <div class="min-w-0">
                                    <div
                                        class="text-sm leading-none font-medium"
                                    >
                                        {{ tile.value }}
                                    </div>
                                    <div
                                        class="mt-1 text-xs leading-tight text-slate-500"
                                    >
                                        {{ tile.label }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <Button
                            as="a"
                            :href="route('tenant.reservations')"
                            class="mt-4 h-10 w-full border-dashed border-slate-300 text-xs hover:bg-slate-50"
                        >
                            <Lucide
                                icon="ExternalLink"
                                class="mr-1.5 h-3.5 w-3.5 stroke-[1.3]"
                            />
                            Registrar check-in
                        </Button>
                    </div>
                </div>
            </div>

            <!-- ===================== Distribución por tipo ===================== -->
            <div class="col-span-12 flex flex-col md:col-span-6 xl:col-span-4">
                <div class="flex items-center md:h-9">
                    <div class="text-sm font-medium">
                        Distribución de habitaciones
                    </div>
                </div>
                <div class="box box--stacked mt-3 flex flex-1 flex-col p-4">
                    <Tab.Group class="mt-1 flex flex-1 flex-col">
                        <Tab.List
                            variant="boxed-tabs"
                            class="mx-auto w-3/4 rounded-[0.6rem] border-slate-200 bg-white shadow-sm"
                        >
                            <Tab
                                class="bg-slate-50 first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] [&[aria-selected='true']_button]:text-current"
                            >
                                <Tab.Button
                                    class="w-full rounded-[0.6rem] text-xs whitespace-nowrap text-slate-500"
                                    as="button"
                                    >Por tipo</Tab.Button
                                >
                            </Tab>
                            <Tab
                                class="bg-slate-50 first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] [&[aria-selected='true']_button]:text-current"
                            >
                                <Tab.Button
                                    class="w-full rounded-[0.6rem] text-xs whitespace-nowrap text-slate-500"
                                    as="button"
                                    >Semáforo</Tab.Button
                                >
                            </Tab>
                        </Tab.List>
                        <Tab.Panels
                            class="mt-4 flex flex-1 flex-col justify-center"
                        >
                            <!-- Por tipo -->
                            <Tab.Panel>
                                <div
                                    v-if="roomTypeDistribution.length"
                                    class="relative mx-auto w-4/5"
                                >
                                    <div class="h-[190px]">
                                        <Chart
                                            type="doughnut"
                                            :data="donutData"
                                            :options="donutOptions"
                                            class="!h-[190px]"
                                        />
                                    </div>
                                    <div
                                        class="absolute inset-0 flex items-center justify-center"
                                    >
                                        <div class="text-center">
                                            <div
                                                class="text-lg font-medium text-slate-600/90"
                                            >
                                                {{ totals.rooms }}
                                            </div>
                                            <div
                                                class="mt-1 text-xs text-slate-500"
                                            >
                                                Habitaciones
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div
                                    v-else
                                    class="py-8 text-center text-xs text-slate-500"
                                >
                                    Sin tipos de habitación aún.
                                </div>
                                <!-- Rejilla en vez de fila centrada: con
                                     muchos tipos la leyenda quedaba en
                                     renglones sueltos y desalineados. -->
                                <div
                                    v-if="roomTypeDistribution.length"
                                    class="mt-5 grid grid-cols-1 gap-x-5 gap-y-2 sm:grid-cols-2"
                                >
                                    <div
                                        v-for="(t, i) in roomTypeDistribution"
                                        :key="t.label"
                                        class="flex min-w-0 items-center text-xs text-slate-500"
                                        :title="`${t.label}: ${t.count}`"
                                    >
                                        <div
                                            class="mr-2 h-2 w-2 flex-none rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    donutPalette[
                                                        i % donutPalette.length
                                                    ],
                                            }"
                                        />
                                        <span class="truncate">{{
                                            t.label
                                        }}</span>
                                        <span
                                            class="ml-auto pl-2 font-medium text-slate-600 dark:text-slate-300"
                                            >{{ t.count }}</span
                                        >
                                    </div>
                                </div>
                            </Tab.Panel>
                            <!-- Semáforo -->
                            <Tab.Panel>
                                <div class="grid grid-cols-2 gap-3">
                                    <div
                                        v-for="status in statuses"
                                        :key="status.value"
                                        class="flex items-center"
                                    >
                                        <div
                                            class="flex h-9 w-9 flex-none items-center justify-center rounded-full border"
                                            :class="tint[status.color]"
                                        >
                                            <Lucide
                                                :icon="statusIcon[status.value]"
                                                class="h-4 w-4"
                                            />
                                        </div>
                                        <div class="ml-2.5">
                                            <div
                                                class="text-sm leading-none font-medium"
                                            >
                                                {{ status.count }}
                                            </div>
                                            <div
                                                class="mt-1 text-xs text-slate-500"
                                            >
                                                {{ status.label }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </Tab.Panel>
                        </Tab.Panels>
                    </Tab.Group>
                    <Button
                        as="a"
                        :href="route('tenant.plano')"
                        class="mt-4 h-10 w-full border-dashed border-slate-300 text-xs hover:bg-slate-50"
                    >
                        <Lucide
                            icon="ExternalLink"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.3]"
                        />
                        Ver plano
                    </Button>
                </div>
            </div>

            <!-- ===================== Accesos rápidos ===================== -->
            <div class="col-span-12">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <Link
                        v-for="action in quickActions"
                        :key="action.label"
                        :href="route(action.route)"
                        class="box box--stacked flex items-center gap-2.5 p-3.5 transition hover:-translate-y-0.5"
                    >
                        <div
                            class="flex h-9 w-9 flex-none items-center justify-center rounded-full border"
                            :class="action.color"
                        >
                            <Lucide :icon="action.icon" class="h-4 w-4" />
                        </div>
                        <div>
                            <div class="text-sm font-medium">
                                {{ action.label }}
                            </div>
                            <div
                                class="mt-0.5 flex items-center text-xs text-primary"
                            >
                                Abrir
                                <Lucide
                                    icon="ArrowRight"
                                    class="ml-1 h-3 w-3"
                                />
                            </div>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- ===================== Llegadas / Salidas ===================== -->
            <div class="col-span-12 xl:col-span-6">
                <div class="flex items-center md:h-9">
                    <div class="flex items-center text-sm font-medium">
                        <div
                            class="mr-2 flex h-6 w-6 items-center justify-center rounded-full border border-success/10 bg-success/10"
                        >
                            <Lucide
                                icon="LogIn"
                                class="h-3.5 w-3.5 stroke-[1.5] text-success"
                            />
                        </div>
                        Llegadas de hoy
                        <span
                            v-if="arrivals.length"
                            class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                            >{{ arrivals.length }}</span
                        >
                    </div>
                    <Link
                        :href="route('tenant.reservations')"
                        class="ml-auto text-xs font-medium text-primary"
                        >Ver reservas</Link
                    >
                </div>
                <div class="mt-2 overflow-auto lg:overflow-visible">
                    <Table
                        v-if="arrivals.length"
                        class="border-separate border-spacing-y-[8px]"
                    >
                        <Table.Tbody>
                            <Table.Tr v-for="a in arrivals" :key="a.id">
                                <Table.Td :class="cellClass">
                                    <div
                                        class="text-sm font-medium whitespace-nowrap"
                                    >
                                        {{ a.guest_name }}
                                    </div>
                                    <div
                                        class="mt-0.5 text-xs whitespace-nowrap text-slate-500"
                                    >
                                        {{ a.code }}
                                    </div>
                                </Table.Td>
                                <Table.Td
                                    :class="cellClass"
                                    class="text-xs text-slate-500"
                                >
                                    <span
                                        v-if="a.room"
                                        class="inline-flex items-center gap-1 whitespace-nowrap"
                                        ><Lucide
                                            icon="BedDouble"
                                            class="h-3.5 w-3.5"
                                        />
                                        {{ a.room }}</span
                                    >
                                    <span
                                        v-else
                                        class="whitespace-nowrap text-slate-400"
                                        >Sin asignar</span
                                    >
                                </Table.Td>
                                <Table.Td
                                    :class="cellClass"
                                    class="text-xs whitespace-nowrap text-slate-500"
                                    ><Lucide
                                        icon="Clock"
                                        class="mr-1 inline h-3.5 w-3.5"
                                    />{{ a.eta || a.time }}</Table.Td
                                >
                                <Table.Td :class="cellClass" class="text-right">
                                    <span
                                        v-if="a.checked_in"
                                        class="rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-success"
                                        >En casa</span
                                    >
                                    <span
                                        v-else
                                        class="rounded-full bg-info/10 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-info"
                                        >Por registrar</span
                                    >
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div
                        v-else
                        class="box box--stacked flex flex-col items-center justify-center gap-3 py-8 text-center"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full border border-success/10 bg-success/10"
                        >
                            <Lucide
                                icon="CalendarCheck"
                                class="h-5 w-5 text-success"
                            />
                        </div>
                        <p class="text-xs text-slate-500">
                            Sin llegadas programadas para hoy.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-span-12 xl:col-span-6">
                <div class="flex items-center md:h-9">
                    <div class="flex items-center text-sm font-medium">
                        <div
                            class="mr-2 flex h-6 w-6 items-center justify-center rounded-full border border-pending/10 bg-pending/10"
                        >
                            <Lucide
                                icon="LogOut"
                                class="h-3.5 w-3.5 stroke-[1.5] text-pending"
                            />
                        </div>
                        Salidas de hoy
                        <span
                            v-if="departures.length"
                            class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                            >{{ departures.length }}</span
                        >
                    </div>
                    <Link
                        :href="route('tenant.pos')"
                        class="ml-auto text-xs font-medium text-primary"
                        >Ir a cobrar</Link
                    >
                </div>
                <div class="mt-2 overflow-auto lg:overflow-visible">
                    <Table
                        v-if="departures.length"
                        class="border-separate border-spacing-y-[8px]"
                    >
                        <Table.Tbody>
                            <Table.Tr v-for="d in departures" :key="d.id">
                                <Table.Td :class="cellClass">
                                    <div
                                        class="text-sm font-medium whitespace-nowrap"
                                    >
                                        {{ d.guest_name }}
                                    </div>
                                    <div
                                        class="mt-0.5 text-xs whitespace-nowrap text-slate-500"
                                    >
                                        {{ d.code }}
                                    </div>
                                </Table.Td>
                                <Table.Td
                                    :class="cellClass"
                                    class="text-xs text-slate-500"
                                >
                                    <span
                                        v-if="d.room"
                                        class="inline-flex items-center gap-1 whitespace-nowrap"
                                        ><Lucide
                                            icon="BedDouble"
                                            class="h-3.5 w-3.5"
                                        />
                                        {{ d.room }}</span
                                    >
                                    <span v-else class="text-slate-400">—</span>
                                </Table.Td>
                                <Table.Td
                                    :class="cellClass"
                                    class="text-xs whitespace-nowrap text-slate-500"
                                    ><Lucide
                                        icon="Clock"
                                        class="mr-1 inline h-3.5 w-3.5"
                                    />{{ d.time }}</Table.Td
                                >
                                <Table.Td :class="cellClass" class="text-right">
                                    <span
                                        v-if="d.balance > 0"
                                        class="rounded-full bg-danger/10 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-danger"
                                        >Saldo {{ money(d.balance) }}</span
                                    >
                                    <span
                                        v-else
                                        class="rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-success"
                                        >Pagada</span
                                    >
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div
                        v-else
                        class="box box--stacked flex flex-col items-center justify-center gap-3 py-8 text-center"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full border border-pending/10 bg-pending/10"
                        >
                            <Lucide
                                icon="DoorOpen"
                                class="h-5 w-5 text-pending"
                            />
                        </div>
                        <p class="text-xs text-slate-500">
                            Sin salidas programadas para hoy.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
