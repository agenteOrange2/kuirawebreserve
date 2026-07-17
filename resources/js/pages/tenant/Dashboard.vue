<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/components/Base/Button';
import Chart from '@/components/Base/Chart';
import { FormSelect } from '@/components/Base/Form';
import { Tab } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface Metric { title: string; value: string; change: number | null; desc: string }
interface SeriesPoint { label: string; value: number }
interface StatusCount { value: string; label: string; color: string; count: number }
interface ActivityRow { id: number; room: string | null; from: string | null; to: string; to_color: string; by: string; at: string }
interface ArrivalRow { id: number; code: string; guest_name: string; room: string | null; eta: string | null; time: string; people: number | null; checked_in: boolean }
interface DepartureRow { id: number; code: string; guest_name: string; room: string | null; time: string; balance: number }

const props = defineProps<{
    expiringHolds: { id: number; code: string; guest_name: string | null; room: string | null; expires_at: string }[];
    hero: { revenue: string; change: number | null; month: string };
    metrics: Metric[];
    series: {
        revenue: SeriesPoint[];
        occupancy: SeriesPoint[];
        revenue_today: number;
        revenue_change: number | null;
        occupancy_today: number;
        occupancy_change: number | null;
    };
    guestStatus: { in_house: number; checked_out: number; pending: number };
    roomTypeDistribution: { label: string; count: number }[];
    statuses: StatusCount[];
    occupancy: { occupied: number; total: number; percent: number; reserved: number; available: number };
    arrivals: ArrivalRow[];
    departures: DepartureRow[];
    totals: { rooms: number; zones: number; roomTypes: number; staff: number; properties: number };
    plan: { name: string; max_rooms: number | null; max_users: number | null };
    recentActivity: ActivityRow[];
}>();

const money = (n: number) => '$' + new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 }).format(n ?? 0);

// Semáforo -> tokens del theme (primary/success/info/warning/pending/dark).
const themeHex: Record<string, string> = {
    green: '#0d9488', cyan: '#0891b2', red: '#03045e', orange: '#c2410c', blue: '#ca8a04', gray: '#1e293b',
};
const dotColor: Record<string, string> = {
    green: 'bg-success', cyan: 'bg-info', red: 'bg-primary', orange: 'bg-pending', blue: 'bg-warning', gray: 'bg-dark',
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
    available: 'BedDouble', reserved: 'CalendarClock', occupied: 'UserCheck', dirty: 'Trash2', cleaning: 'Sparkles', maintenance: 'Wrench',
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
    datasets: [{ data: props.series.occupancy.map((p) => p.value), borderColor: '#03045e', backgroundColor: 'rgba(3,4,94,0.08)', fill: true }],
}));
const revenueLine = computed(() => ({
    labels: props.series.revenue.map((p) => p.label),
    datasets: [{ data: props.series.revenue.map((p) => p.value), borderColor: '#0d9488', backgroundColor: 'rgba(13,148,136,0.10)', fill: true }],
}));

// Donut de tipos con la paleta del theme.
const donutPalette = ['#03045e', '#0891b2', '#0d9488', '#ca8a04', '#c2410c', '#b91c1c', '#1e293b'];
const donutData = computed(() => ({
    labels: props.roomTypeDistribution.map((t) => t.label),
    datasets: [{ data: props.roomTypeDistribution.map((t) => t.count), backgroundColor: props.roomTypeDistribution.map((_, i) => donutPalette[i % donutPalette.length]), borderWidth: 0, hoverOffset: 4 }],
}));
const donutOptions = { cutout: '75%', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { enabled: true } } };

const barSegments = computed(() =>
    props.statuses.filter((s) => s.count > 0).map((s) => ({ ...s, width: props.totals.rooms > 0 ? (s.count / props.totals.rooms) * 100 : 0 })),
);

const activityIcon = (color: string): Icon => {
    const status = props.statuses.find((s) => s.color === color);
    return status ? statusIcon[status.value] : 'Activity';
};

interface QuickAction { label: string; icon: Icon; route: string; color: string }
const quickActions: QuickAction[] = [
    { label: 'Nueva reserva', icon: 'CalendarPlus', route: 'tenant.reservations', color: 'border-primary/10 bg-primary/10 text-primary' },
    { label: 'Cobrar / POS', icon: 'ShoppingCart', route: 'tenant.pos', color: 'border-success/10 bg-success/10 text-success' },
    { label: 'Ver plano', icon: 'Map', route: 'tenant.plano', color: 'border-info/10 bg-info/10 text-info' },
    { label: 'Huéspedes', icon: 'Users', route: 'tenant.guests', color: 'border-pending/10 bg-pending/10 text-pending' },
];

const cellClass =
    'box shadow-[5px_3px_5px_#00000005] first:border-l last:border-r first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] rounded-l-none rounded-r-none border-x-0 dark:bg-darkmode-600';
</script>

<template>
    <RazeLayout title="Dashboard">
        <div class="grid grid-cols-12 gap-y-10 gap-x-6">
            <!-- Holds por vencer: apartados que expiran en < 30 min -->
            <div v-if="expiringHolds.length" class="col-span-12 -mb-5">
                <div class="box box--stacked border-l-4 border-l-warning p-4">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <div class="flex items-center gap-2 text-sm font-medium">
                            <Lucide icon="AlarmClock" class="h-4 w-4 text-warning" />
                            Apartados por vencer
                        </div>
                        <Link
                            v-for="hold in expiringHolds"
                            :key="hold.id"
                            :href="route('tenant.reservations', { reservation: hold.id })"
                            class="flex items-center gap-1.5 rounded-full bg-warning/10 px-3 py-1.5 text-xs font-medium text-warning transition hover:bg-warning/20"
                        >
                            {{ hold.code }} · {{ hold.guest_name ?? 'Sin nombre' }}<template v-if="hold.room"> · hab. {{ hold.room }}</template>
                            <span class="font-normal">expira {{ hold.expires_at }}</span>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Onboarding -->
            <div v-if="!hasRooms" class="col-span-12">
                <div class="box box--stacked border-l-4 border-l-primary p-5">
                    <div class="flex flex-wrap items-center gap-4">
                        <Lucide icon="Sparkles" class="h-8 w-8 text-primary" />
                        <div class="flex-1">
                            <div class="font-medium">¡Bienvenido! Configura tu hotel en 2 pasos</div>
                            <p class="mt-1 text-sm text-slate-500">
                                1) Define tus <Link :href="route('tenant.catalog')" class="text-primary underline">zonas y tipos</Link> ·
                                2) Da de alta tus <Link :href="route('tenant.rooms')" class="text-primary underline">habitaciones</Link> y acomódalas en el
                                <Link :href="route('tenant.plano')" class="text-primary underline">plano</Link>.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===================== Hotel Performance Insights ===================== -->
            <div class="col-span-12 flex flex-col 2xl:col-span-9">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">Resumen operativo</div>
                    <div class="ml-auto flex gap-2">
                        <Button as="a" :href="route('tenant.plano')" variant="outline-secondary" class="rounded-[0.5rem] bg-white">
                            <Lucide icon="Map" class="mr-2 h-4 w-4 stroke-[1.3]" /> Plano
                        </Button>
                        <Button as="a" :href="route('tenant.reservations')" variant="primary" class="rounded-[0.5rem] shadow-md shadow-primary/20">
                            <Lucide icon="CalendarPlus" class="mr-2 h-4 w-4 stroke-[1.3]" /> Nueva reserva
                        </Button>
                    </div>
                </div>
                <div class="mt-3.5 flex flex-1">
                    <div class="box box--stacked flex w-full flex-col gap-3 p-3 xl:flex-row">
                        <!-- Hero de ingresos -->
                        <div class="relative flex flex-col items-center gap-8 overflow-hidden rounded-[0.6rem] bg-gradient-to-b from-theme-2/90 to-theme-1/[0.85] px-8 py-9 xl:w-[300px] xl:flex-none xl:items-start">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full border border-white/10 bg-white/10">
                                <Lucide icon="CreditCard" class="h-6 w-6 text-white fill-white/10" />
                            </div>
                            <div class="text-center xl:text-left">
                                <div class="text-base text-white/90">Ingresos de {{ hero.month }}</div>
                                <div class="mt-2 flex items-center justify-center xl:justify-start">
                                    <div class="text-2xl font-medium text-white">{{ hero.revenue }}</div>
                                    <div v-if="hero.change !== null" class="ml-2.5 flex items-center rounded-full border border-success/50 bg-success/50 py-[2px] pl-[7px] pr-1 text-xs font-medium text-white/90">
                                        {{ Math.abs(hero.change) }}%
                                        <Lucide :icon="hero.change >= 0 ? 'ChevronUp' : 'ChevronDown'" class="ml-px h-4 w-4 stroke-[1.5]" />
                                    </div>
                                </div>
                                <div class="mt-3 leading-normal text-white/70">Pagos de reservas y ventas de POS acumulados en el mes.</div>
                            </div>
                            <div class="mt-auto w-full">
                                <Button as="a" :href="route('tenant.reservations')" rounded class="relative w-full justify-start border-white/20 bg-white/10 px-4 py-2.5 text-white hover:bg-white/[0.15]">
                                    Ver reservas
                                    <div class="absolute right-0 mr-0.5 flex h-9 w-9 items-center justify-center rounded-full border border-white/10 bg-white/10">
                                        <Lucide icon="ArrowRight" class="h-4 w-4" />
                                    </div>
                                </Button>
                            </div>
                        </div>

                        <!-- Rejilla de métricas -->
                        <div class="flex w-full flex-col rounded-[0.6rem] border border-dashed border-slate-300/80 p-5 sm:px-8 sm:py-7">
                            <div class="flex flex-col gap-x-3 gap-y-2 sm:flex-row sm:items-center">
                                <div class="relative">
                                    <Lucide icon="CalendarCheck2" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3]" />
                                    <FormSelect class="pl-9 sm:w-44">
                                        <option>Hoy</option>
                                        <option>Esta semana</option>
                                        <option>Este mes</option>
                                    </FormSelect>
                                </div>
                                <div class="text-xs text-slate-500 sm:ml-auto">Indicadores en tiempo real del hotel</div>
                            </div>
                            <div class="mt-6 grid flex-1 grid-cols-2 gap-x-6 gap-y-6 sm:grid-cols-3 xl:grid-cols-4">
                                <div v-for="metric in metrics" :key="metric.title" class="flex flex-col justify-center">
                                    <div class="mb-1 flex items-center">
                                        <div class="text-base font-medium">{{ metric.value }}</div>
                                        <div v-if="metric.change !== null" :class="['ml-2 -mr-1 flex items-center text-xs', metric.change < 0 ? 'text-danger' : 'text-success']">
                                            {{ Math.abs(metric.change) }}%
                                            <Lucide class="ml-px h-4 w-4 stroke-[1]" :icon="metric.change < 0 ? 'ChevronDown' : 'ChevronUp'" />
                                        </div>
                                    </div>
                                    <div class="flex items-center text-slate-500">
                                        <span class="truncate">{{ metric.title }}</span>
                                        <span :title="metric.desc" class="inline-flex cursor-help">
                                            <Lucide class="ml-1.5 h-3.5 w-3.5 stroke-[1.3] text-slate-400" icon="Info" />
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
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">Ocupación vs. ingresos</div>
                </div>
                <div class="mt-3.5 grid flex-1 grid-cols-2 gap-6 2xl:auto-rows-fr 2xl:grid-cols-1">
                    <div class="box box--stacked flex flex-col p-5">
                        <div class="text-base text-slate-500">Ocupación (7 días)</div>
                        <div class="mt-1 flex items-center">
                            <div class="text-xl font-medium">{{ series.occupancy_today }}%</div>
                            <div v-if="series.occupancy_change !== null" :class="['ml-2 -mr-1 flex items-center text-xs', series.occupancy_change < 0 ? 'text-danger' : 'text-success']">
                                {{ Math.abs(series.occupancy_change) }}%
                                <Lucide :icon="series.occupancy_change < 0 ? 'ChevronDown' : 'ChevronUp'" class="ml-px h-4 w-4" />
                            </div>
                        </div>
                        <div class="mt-4 flex min-h-[87px] flex-1 items-end">
                            <Chart type="line" :data="occupancyLine" :options="lineOptions" class="!h-[87px]" />
                        </div>
                    </div>
                    <div class="box box--stacked flex flex-col p-5">
                        <div class="text-base text-slate-500">Ingresos (7 días)</div>
                        <div class="mt-1 flex items-center">
                            <div class="text-xl font-medium">{{ money(series.revenue_today) }}</div>
                            <div v-if="series.revenue_change !== null" :class="['ml-2 -mr-1 flex items-center text-xs', series.revenue_change < 0 ? 'text-danger' : 'text-success']">
                                {{ Math.abs(series.revenue_change) }}%
                                <Lucide :icon="series.revenue_change < 0 ? 'ChevronDown' : 'ChevronUp'" class="ml-px h-4 w-4" />
                            </div>
                        </div>
                        <div class="mt-4 flex min-h-[87px] flex-1 items-end">
                            <Chart type="line" :data="revenueLine" :options="lineOptions" class="!h-[87px]" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===================== Actividad reciente ===================== -->
            <div class="col-span-12 flex flex-col md:col-span-6 xl:col-span-4">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">Actividad reciente</div>
                    <span v-if="guestStatus.pending" class="ml-auto rounded-full bg-pending/10 px-2.5 py-0.5 text-xs font-medium text-pending">
                        {{ guestStatus.pending }} por confirmar
                    </span>
                </div>
                <div class="mt-3.5 box box--stacked flex flex-1 flex-col p-5">
                    <div class="mb-5 border-b border-dashed border-slate-300/70 pb-5">
                        <div class="flex items-center">
                            <div class="text-xl font-medium">{{ occupancy.occupied }}/{{ occupancy.total }}</div>
                            <div class="ml-2 text-xs text-slate-500">habitaciones ocupadas</div>
                        </div>
                        <div class="mt-3 flex h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400">
                            <div v-for="seg in barSegments" :key="seg.value" class="h-full first:rounded-l-full last:rounded-r-full" :class="dotColor[seg.color]" :style="{ width: `${seg.width}%` }" :title="`${seg.label}: ${seg.count}`" />
                        </div>
                    </div>
                    <div v-if="recentActivity.length" class="flex flex-col gap-5">
                        <div v-for="log in recentActivity" :key="log.id" class="flex items-center">
                            <div class="flex h-10 w-10 flex-none items-center justify-center rounded-full border" :class="tint[log.to_color]">
                                <Lucide :icon="activityIcon(log.to_color)" class="h-[1.15rem] w-[1.15rem]" />
                            </div>
                            <div class="ml-3.5 flex w-full flex-col gap-y-1 sm:flex-row sm:items-center">
                                <div>
                                    <div class="font-medium whitespace-nowrap">Hab. {{ log.room ?? '—' }}</div>
                                    <div class="mt-0.5 text-xs text-slate-500 whitespace-nowrap">{{ log.from ?? '—' }} → {{ log.to }} · {{ log.by }}</div>
                                </div>
                                <span class="mr-auto flex items-center rounded-lg border px-2.5 py-1 text-xs font-medium sm:ml-auto sm:mr-0" :class="tint[log.to_color]">
                                    <span class="mr-1.5 h-1 w-1 rounded-full" :class="dotColor[log.to_color]" />
                                    <span class="-mt-px">{{ log.at }}</span>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="py-6 text-center text-sm text-slate-500">Sin movimientos todavía.</div>
                </div>
            </div>

            <!-- ===================== Estado de huéspedes ===================== -->
            <div class="col-span-12 flex flex-col md:col-span-6 xl:col-span-4">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">Estado de huéspedes</div>
                </div>
                <div class="mt-3.5 box box--stacked flex flex-1 flex-col">
                    <div class="relative m-2.5 flex flex-col rounded-[0.6rem] border border-dashed box shadow-sm bg-gradient-to-b from-transparent to-theme-1/[0.03] pt-[70px] before:absolute before:inset-0 before:bg-texture-black before:bg-[center_1rem] before:bg-cover before:bg-no-repeat before:opacity-90 before:content-['']">
                        <div class="z-10 mx-auto -mb-6 mt-auto h-14 w-14 rounded-full border border-theme-1/20 bg-white/80 p-1">
                            <div class="relative z-10 flex h-full w-full items-center justify-center rounded-full border border-primary/[0.15] bg-gradient-to-b from-theme-2/90 to-theme-1/[0.85] shadow-sm">
                                <Lucide icon="UserCheck" class="h-5 w-5 text-white fill-white/10" />
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-1 flex-col p-5">
                        <div class="mt-9 mb-10 text-center">
                            <div class="flex items-center justify-center">
                                <div class="text-xl font-medium">{{ guestStatus.in_house }}</div>
                                <div class="ml-2 text-xs text-slate-500">en casa</div>
                            </div>
                            <div class="mt-1.5 text-slate-500">Huéspedes actualmente hospedados</div>
                            <div class="mt-4 flex justify-center gap-3">
                                <span title="Huéspedes en casa" class="flex items-center rounded-md border border-success/10 bg-success/10 px-2 py-0.5 text-xs text-success">
                                    <span class="mr-1.5 h-1.5 w-1.5 rounded-full bg-success" /> {{ guestStatus.in_house }} En casa
                                </span>
                                <span title="Check-outs de hoy" class="flex items-center rounded-md border border-pending/10 bg-pending/10 px-2 py-0.5 text-xs text-pending">
                                    <span class="mr-1.5 h-1.5 w-1.5 rounded-full bg-pending" /> {{ guestStatus.checked_out }} Salidas
                                </span>
                                <span title="Reservas por confirmar" class="flex items-center rounded-md border border-warning/10 bg-warning/10 px-2 py-0.5 text-xs text-warning">
                                    <span class="mr-1.5 h-1.5 w-1.5 rounded-full bg-warning" /> {{ guestStatus.pending }} Pend.
                                </span>
                            </div>
                        </div>
                        <Button as="a" :href="route('tenant.reservations')" class="mt-auto w-full border-dashed border-slate-300 hover:bg-slate-50">
                            <Lucide icon="ExternalLink" class="mr-2 h-4 w-4 stroke-[1.3]" /> Registrar check-in
                        </Button>
                    </div>
                </div>
            </div>

            <!-- ===================== Distribución por tipo ===================== -->
            <div class="col-span-12 flex flex-col md:col-span-6 xl:col-span-4">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">Distribución de habitaciones</div>
                </div>
                <div class="mt-3.5 box box--stacked flex flex-1 flex-col p-5">
                    <Tab.Group class="mt-1 flex flex-1 flex-col">
                        <Tab.List variant="boxed-tabs" class="mx-auto w-3/4 rounded-[0.6rem] border-slate-200 bg-white shadow-sm">
                            <Tab class="bg-slate-50 first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] [&[aria-selected='true']_button]:text-current">
                                <Tab.Button class="w-full whitespace-nowrap rounded-[0.6rem] text-slate-500" as="button">Por tipo</Tab.Button>
                            </Tab>
                            <Tab class="bg-slate-50 first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] [&[aria-selected='true']_button]:text-current">
                                <Tab.Button class="w-full whitespace-nowrap rounded-[0.6rem] text-slate-500" as="button">Semáforo</Tab.Button>
                            </Tab>
                        </Tab.List>
                        <Tab.Panels class="mt-8 flex-1">
                            <!-- Por tipo -->
                            <Tab.Panel>
                                <div v-if="roomTypeDistribution.length" class="relative mx-auto w-4/5">
                                    <div class="h-[190px]"><Chart type="doughnut" :data="donutData" :options="donutOptions" class="!h-[190px]" /></div>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <div class="text-center">
                                            <div class="text-lg font-medium text-slate-600/90">{{ totals.rooms }}</div>
                                            <div class="mt-1 text-slate-500">Habitaciones</div>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="py-10 text-center text-sm text-slate-500">Sin tipos de habitación aún.</div>
                                <div class="mt-5 flex flex-wrap justify-center gap-x-5 gap-y-2">
                                    <div v-for="(t, i) in roomTypeDistribution" :key="t.label" class="flex items-center text-slate-500">
                                        <div class="mr-2 h-2 w-2 rounded-full" :style="{ backgroundColor: donutPalette[i % donutPalette.length] }" />
                                        {{ t.label }} ({{ t.count }})
                                    </div>
                                </div>
                            </Tab.Panel>
                            <!-- Semáforo -->
                            <Tab.Panel>
                                <div class="grid grid-cols-2 gap-4">
                                    <div v-for="status in statuses" :key="status.value" class="flex items-center">
                                        <div class="flex h-9 w-9 flex-none items-center justify-center rounded-full border" :class="tint[status.color]">
                                            <Lucide :icon="statusIcon[status.value]" class="h-4 w-4" />
                                        </div>
                                        <div class="ml-2.5">
                                            <div class="text-base font-medium leading-none">{{ status.count }}</div>
                                            <div class="mt-1 text-xs text-slate-500">{{ status.label }}</div>
                                        </div>
                                    </div>
                                </div>
                            </Tab.Panel>
                        </Tab.Panels>
                    </Tab.Group>
                    <Button as="a" :href="route('tenant.plano')" class="mt-6 w-full border-dashed border-slate-300 hover:bg-slate-50">
                        <Lucide icon="ExternalLink" class="mr-2 h-4 w-4 stroke-[1.3]" /> Ver plano
                    </Button>
                </div>
            </div>

            <!-- ===================== Accesos rápidos ===================== -->
            <div class="col-span-12">
                <div class="grid grid-cols-2 gap-6 sm:grid-cols-4">
                    <Link v-for="action in quickActions" :key="action.label" :href="route(action.route)" class="box box--stacked flex items-center gap-3 p-4 transition hover:-translate-y-0.5">
                        <div class="flex h-11 w-11 flex-none items-center justify-center rounded-full border" :class="action.color">
                            <Lucide :icon="action.icon" class="h-5 w-5" />
                        </div>
                        <div>
                            <div class="text-sm font-medium">{{ action.label }}</div>
                            <div class="mt-0.5 flex items-center text-xs text-primary">Abrir <Lucide icon="ArrowRight" class="ml-1 h-3 w-3" /></div>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- ===================== Llegadas / Salidas ===================== -->
            <div class="col-span-12 xl:col-span-6">
                <div class="flex items-center md:h-10">
                    <div class="flex items-center text-base font-medium">
                        <div class="mr-2 flex h-7 w-7 items-center justify-center rounded-full border border-success/10 bg-success/10"><Lucide icon="LogIn" class="h-4 w-4 text-success stroke-[1.5]" /></div>
                        Llegadas de hoy
                        <span v-if="arrivals.length" class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400">{{ arrivals.length }}</span>
                    </div>
                    <Link :href="route('tenant.reservations')" class="ml-auto text-xs font-medium text-primary">Ver reservas</Link>
                </div>
                <div class="mt-2 overflow-auto lg:overflow-visible">
                    <Table v-if="arrivals.length" class="border-separate border-spacing-y-[8px]">
                        <Table.Tbody>
                            <Table.Tr v-for="a in arrivals" :key="a.id">
                                <Table.Td :class="cellClass">
                                    <div class="font-medium whitespace-nowrap">{{ a.guest_name }}</div>
                                    <div class="mt-0.5 text-xs text-slate-500 whitespace-nowrap">{{ a.code }}</div>
                                </Table.Td>
                                <Table.Td :class="cellClass" class="text-sm text-slate-500">
                                    <span v-if="a.room" class="inline-flex items-center gap-1 whitespace-nowrap"><Lucide icon="BedDouble" class="h-3.5 w-3.5" /> {{ a.room }}</span>
                                    <span v-else class="whitespace-nowrap text-slate-400">Sin asignar</span>
                                </Table.Td>
                                <Table.Td :class="cellClass" class="whitespace-nowrap text-sm text-slate-500"><Lucide icon="Clock" class="mr-1 inline h-3.5 w-3.5" />{{ a.eta || a.time }}</Table.Td>
                                <Table.Td :class="cellClass" class="text-right">
                                    <span v-if="a.checked_in" class="whitespace-nowrap rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success">En casa</span>
                                    <span v-else class="whitespace-nowrap rounded-full bg-info/10 px-2 py-0.5 text-xs font-medium text-info">Por registrar</span>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div v-else class="box box--stacked flex flex-col items-center justify-center gap-3 py-10 text-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full border border-success/10 bg-success/10"><Lucide icon="CalendarCheck" class="h-6 w-6 text-success" /></div>
                        <p class="text-sm text-slate-500">Sin llegadas programadas para hoy.</p>
                    </div>
                </div>
            </div>

            <div class="col-span-12 xl:col-span-6">
                <div class="flex items-center md:h-10">
                    <div class="flex items-center text-base font-medium">
                        <div class="mr-2 flex h-7 w-7 items-center justify-center rounded-full border border-pending/10 bg-pending/10"><Lucide icon="LogOut" class="h-4 w-4 text-pending stroke-[1.5]" /></div>
                        Salidas de hoy
                        <span v-if="departures.length" class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400">{{ departures.length }}</span>
                    </div>
                    <Link :href="route('tenant.pos')" class="ml-auto text-xs font-medium text-primary">Ir a cobrar</Link>
                </div>
                <div class="mt-2 overflow-auto lg:overflow-visible">
                    <Table v-if="departures.length" class="border-separate border-spacing-y-[8px]">
                        <Table.Tbody>
                            <Table.Tr v-for="d in departures" :key="d.id">
                                <Table.Td :class="cellClass">
                                    <div class="font-medium whitespace-nowrap">{{ d.guest_name }}</div>
                                    <div class="mt-0.5 text-xs text-slate-500 whitespace-nowrap">{{ d.code }}</div>
                                </Table.Td>
                                <Table.Td :class="cellClass" class="text-sm text-slate-500">
                                    <span v-if="d.room" class="inline-flex items-center gap-1 whitespace-nowrap"><Lucide icon="BedDouble" class="h-3.5 w-3.5" /> {{ d.room }}</span>
                                    <span v-else class="text-slate-400">—</span>
                                </Table.Td>
                                <Table.Td :class="cellClass" class="whitespace-nowrap text-sm text-slate-500"><Lucide icon="Clock" class="mr-1 inline h-3.5 w-3.5" />{{ d.time }}</Table.Td>
                                <Table.Td :class="cellClass" class="text-right">
                                    <span v-if="d.balance > 0" class="whitespace-nowrap rounded-full bg-danger/10 px-2 py-0.5 text-xs font-medium text-danger">Saldo {{ money(d.balance) }}</span>
                                    <span v-else class="whitespace-nowrap rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success">Pagada</span>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div v-else class="box box--stacked flex flex-col items-center justify-center gap-3 py-10 text-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full border border-pending/10 bg-pending/10"><Lucide icon="DoorOpen" class="h-6 w-6 text-pending" /></div>
                        <p class="text-sm text-slate-500">Sin salidas programadas para hoy.</p>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
