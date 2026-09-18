<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import Chart from '@/components/Base/Chart';
import { FormDate, FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface RoomRow {
    id: number;
    name: string;
    type: string | null;
    zone: string | null;
    status_label: string;
    uses: number;
    nights: number;
    percent: number;
    revenue: number;
    consumos: number;
    total: number;
    adr: number;
    revpar: number;
    avg_stay_label: string;
    incidents: number;
    incidents_open: number;
    incident_cost: number;
    cleanings: number;
    cleaning_minutes: number;
    out_of_service_days: number;
    usage_count: number;
    usage_limit: number | null;
}

interface GroupRow {
    name: string;
    rooms: number;
    uses: number;
    nights: number;
    revenue: number;
    revenue_label: string;
    percent: number;
}

interface Option {
    id: number;
    label: string;
}

const props = defineProps<{
    property: { id: number; name: string };
    filters: {
        period: string;
        from: string;
        to: string;
        label: string;
        room: number | null;
        type: number | null;
        zone: number | null;
    };
    summary: {
        rooms: number;
        days: number;
        uses: number;
        uses_prev: number;
        nights: number;
        available: number;
        percent: number;
        revenue: number;
        revenue_label: string;
        revenue_prev: number;
        consumos_label: string;
        total_label: string;
        adr_label: string;
        revpar_label: string;
        avg_stay_label: string;
        idle_rooms: number;
        out_of_service_days: number;
        incidents: number;
        incidents_open: number;
        incident_cost_label: string;
        cleanings: number;
        avg_cleaning_label: string;
    };
    rooms: RoomRow[];
    idle: RoomRow[];
    daily: { date: string; label: string; occupied: number; uses: number }[];
    /** Promedio de habitaciones ocupadas por día de la semana. */
    weekdays: {
        label: string;
        days: number;
        occupied: number;
        uses: number;
        average: number;
    }[];
    types: GroupRow[];
    zones: GroupRow[];
    maintenance: {
        categories: {
            category: string;
            label: string;
            count: number;
            cost_label: string;
        }[];
        avg_resolution_label: string;
        blocked_rooms: number;
    };
    catalog: { rooms: Option[]; types: Option[]; zones: Option[] };
}>();

const money = (value: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value) || 0);

const pct = (value: number) =>
    `${new Intl.NumberFormat('es-MX', { maximumFractionDigits: 1 }).format(value)}%`;

const plural = (n: number, one: string, many: string) =>
    `${n} ${n === 1 ? one : many}`;

// ── Constantes de anatomía ────────────────────────────────────
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const sectionLabel =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
const tableHead = sectionLabel;

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
const typeSel = ref<string | number>(props.filters.type ?? '');
const zoneSel = ref<string | number>(props.filters.zone ?? '');
const showCustom = ref(props.filters.period === 'custom');

function query(period: string) {
    return {
        period,
        room: roomSel.value || undefined,
        type: typeSel.value || undefined,
        zone: zoneSel.value || undefined,
        ...(period === 'custom'
            ? { from: customFrom.value, to: customTo.value }
            : {}),
    };
}

function goTo(period: string) {
    if (period === 'custom') {
        showCustom.value = true;
        applyCustom();

        return;
    }

    router.get(route('tenant.rooms.reports'), query(period), {
        preserveScroll: true,
    });
}

function applyCustom() {
    if (!customFrom.value || !customTo.value) return;

    router.get(route('tenant.rooms.reports'), query('custom'), {
        preserveScroll: true,
    });
}

const pdfUrl = computed(() =>
    route('tenant.rooms.reports.pdf', {
        period: props.filters.period,
        ...(props.filters.room ? { room: props.filters.room } : {}),
        ...(props.filters.type ? { type: props.filters.type } : {}),
        ...(props.filters.zone ? { zone: props.filters.zone } : {}),
        ...(props.filters.period === 'custom'
            ? { from: props.filters.from, to: props.filters.to }
            : {}),
    }),
);

// ── Cifras del periodo ────────────────────────────────────────
/** Cuánto cambió contra el periodo anterior del mismo largo. */
const trend = (now: number, before: number) => {
    if (before <= 0) {
        return now > 0
            ? { label: 'Sin comparación', tone: 'text-slate-400' }
            : null;
    }

    const diff = ((now - before) / before) * 100;
    const rounded = Math.round(diff);

    if (rounded === 0) {
        return {
            label: 'Igual que el periodo anterior',
            tone: 'text-slate-400',
        };
    }

    return {
        label: `${rounded > 0 ? '+' : ''}${rounded}% contra el periodo anterior`,
        tone: rounded > 0 ? 'text-success' : 'text-danger',
    };
};

const usesTrend = computed(() =>
    trend(props.summary.uses, props.summary.uses_prev),
);
const revenueTrend = computed(() =>
    trend(props.summary.revenue, props.summary.revenue_prev),
);

interface Tile {
    key: string;
    icon: Icon;
    tone: string;
    value: string;
    label: string;
    detail: string;
    detailTone?: string;
}

const tiles = computed<Tile[]>(() => [
    {
        key: 'uses',
        icon: 'Repeat' as Icon,
        tone: 'border-primary/10 bg-primary/10 text-primary',
        value: String(props.summary.uses),
        label:
            props.summary.uses === 1
                ? 'Renta registrada'
                : 'Rentas registradas',
        detail: usesTrend.value?.label ?? 'Entradas del periodo',
        detailTone: usesTrend.value?.tone,
    },
    {
        key: 'occupancy',
        icon: 'ChartPie' as Icon,
        tone: 'border-info/10 bg-info/10 text-info',
        value: pct(props.summary.percent),
        label: 'De uso',
        detail: `${props.summary.nights} de ${props.summary.available} noches disponibles`,
    },
    {
        key: 'revenue',
        icon: 'Banknote' as Icon,
        tone: 'border-success/10 bg-success/10 text-success',
        value: props.summary.revenue_label,
        label: 'Hospedaje vendido',
        detail:
            revenueTrend.value?.label ??
            `Más ${props.summary.consumos_label} de consumos`,
        detailTone: revenueTrend.value?.tone,
    },
    {
        key: 'adr',
        icon: 'Tag' as Icon,
        tone: 'border-pending/10 bg-pending/10 text-pending',
        value: props.summary.adr_label,
        label: 'Tarifa promedio',
        detail: `${props.summary.revpar_label} por habitación y día`,
    },
    {
        key: 'stay',
        icon: 'Clock' as Icon,
        tone: 'border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400',
        value: props.summary.avg_stay_label,
        label: 'Dura cada renta',
        detail: 'Promedio de lo que se queda el huésped',
    },
    {
        key: 'idle',
        icon: 'DoorClosed' as Icon,
        tone: props.summary.idle_rooms
            ? 'border-danger/10 bg-danger/10 text-danger'
            : 'border-slate-200 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400',
        value: String(props.summary.idle_rooms),
        label: 'Sin rentarse',
        detail: props.summary.idle_rooms
            ? 'No se ocuparon ni una noche'
            : 'Todas se rentaron al menos una vez',
        detailTone: props.summary.idle_rooms ? 'text-danger' : undefined,
    },
]);

// ── Orden de la tabla ─────────────────────────────────────────
type SortKey = 'nights' | 'uses' | 'revenue' | 'percent' | 'incidents';

const sortKey = ref<SortKey>('nights');

const sortedRooms = computed(() =>
    [...props.rooms].sort((a, b) => b[sortKey.value] - a[sortKey.value]),
);

/** La barra de la tabla se mide contra la habitación que más trabajó. */
const peakNights = computed(() =>
    Math.max(1, ...props.rooms.map((room) => room.nights)),
);

// ── Gráficas (tokens del theme) ───────────────────────────────
const baseOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
        x: { grid: { display: false }, ticks: { font: { size: 10 } } },
        y: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 } } },
    },
};

// Rangos largos llegan agrupados por mes desde el servidor (365 barras no
// se leen); el título tiene que decir lo que se está viendo.
const groupedByMonth = computed(() => props.summary.days > 62);

const dailyData = computed(() => ({
    labels: props.daily.map((day) => day.label),
    datasets: [
        {
            label: groupedByMonth.value
                ? 'Noches ocupadas'
                : 'Habitaciones ocupadas',
            data: props.daily.map((day) => day.occupied),
            backgroundColor: 'rgba(3,4,94,0.75)',
            borderRadius: 4,
        },
        {
            label: groupedByMonth.value ? 'Rentas del mes' : 'Rentas del día',
            data: props.daily.map((day) => day.uses),
            backgroundColor: 'rgba(13,148,136,0.75)',
            borderRadius: 4,
        },
    ],
}));

const dailyOptions = {
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
};

/** El día más lleno marca la altura de las barras del semanario. */
const peakWeekday = computed(() =>
    Math.max(0.1, ...props.weekdays.map((day) => day.average)),
);

const typeHex = [
    '#03045e',
    '#0d9488',
    '#ca8a04',
    '#1e293b',
    '#94a3b8',
    '#b91c1c',
];

const typesData = computed(() => ({
    labels: props.types.map((row) => row.name),
    datasets: [
        {
            data: props.types.map((row) => row.revenue),
            backgroundColor: props.types.map(
                (_, index) => typeHex[index % typeHex.length],
            ),
            borderWidth: 0,
            hoverOffset: 4,
        },
    ],
}));

const typesOptions = {
    cutout: '70%',
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx: { label: string; parsed: number }) =>
                    `${ctx.label}: ${money(ctx.parsed)}`,
            },
        },
    },
};
</script>

<template>
    <RazeLayout title="Reportes de habitaciones">
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
                            Reportes de habitaciones
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ property.name }} · {{ filters.label }}
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Link
                        :href="route('tenant.rooms')"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Volver a habitaciones
                    </Link>
                    <Button
                        as="a"
                        :href="pdfUrl"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide icon="Download" class="mr-1.5 h-3.5 w-3.5" />
                        Descargar PDF
                    </Button>
                </div>
            </div>

            <!-- Periodo y filtros -->
            <div class="box box--stacked mt-4 p-4">
                <div class="flex flex-wrap items-center gap-2.5">
                    <div
                        class="inline-flex gap-1 rounded-[0.7rem] border border-slate-200/80 bg-slate-100/70 p-1 dark:border-darkmode-400 dark:bg-darkmode-700"
                    >
                        <button
                            v-for="p in periods"
                            :key="p.key"
                            type="button"
                            class="flex h-8 items-center gap-1.5 rounded-[0.5rem] px-3 text-xs font-medium transition"
                            :class="
                                filters.period === p.key
                                    ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                            "
                            @click="goTo(p.key)"
                        >
                            <Lucide :icon="p.icon" class="h-3.5 w-3.5" />
                            {{ p.label }}
                        </button>
                    </div>

                    <template v-if="showCustom || filters.period === 'custom'">
                        <FormDate
                            v-model="customFrom"
                            input-class="h-9 text-xs"
                            class="w-full sm:w-36"
                        />
                        <FormDate
                            v-model="customTo"
                            input-class="h-9 text-xs"
                            class="w-full sm:w-36"
                        />
                        <Button
                            variant="outline-primary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs"
                            @click="applyCustom"
                        >
                            Aplicar
                        </Button>
                    </template>

                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <FormSelect
                            v-model="zoneSel"
                            class="h-9 w-full text-xs sm:w-40"
                            @change="goTo(filters.period)"
                        >
                            <option value="">Todas las zonas</option>
                            <option
                                v-for="zone in catalog.zones"
                                :key="zone.id"
                                :value="zone.id"
                            >
                                {{ zone.label }}
                            </option>
                        </FormSelect>
                        <FormSelect
                            v-model="typeSel"
                            class="h-9 w-full text-xs sm:w-40"
                            @change="goTo(filters.period)"
                        >
                            <option value="">Todos los tipos</option>
                            <option
                                v-for="type in catalog.types"
                                :key="type.id"
                                :value="type.id"
                            >
                                {{ type.label }}
                            </option>
                        </FormSelect>
                        <FormSelect
                            v-model="roomSel"
                            class="h-9 w-full text-xs sm:w-44"
                            @change="goTo(filters.period)"
                        >
                            <option value="">Todas las habitaciones</option>
                            <option
                                v-for="room in catalog.rooms"
                                :key="room.id"
                                :value="room.id"
                            >
                                {{ room.label }}
                            </option>
                        </FormSelect>
                    </div>
                </div>
            </div>

            <!-- Cifras del periodo -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">Cómo se usaron</span>
                <span class="hidden text-[11px] text-slate-400 sm:inline">
                    {{ summary.rooms }}
                    {{ summary.rooms === 1 ? 'habitación' : 'habitaciones' }}
                    ·
                    {{ summary.days }}
                    {{ summary.days === 1 ? 'día' : 'días' }}
                </span>
            </div>
            <div class="mt-2 grid auto-rows-fr grid-cols-12 gap-4">
                <div
                    v-for="tile in tiles"
                    :key="tile.key"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 lg:col-span-4 xl:col-span-2"
                >
                    <div :class="[sectionIcon, tile.tone]">
                        <Lucide :icon="tile.icon" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium tabular-nums">
                            {{ tile.value }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            {{ tile.label }}
                        </div>
                        <div
                            class="text-[11px] leading-tight"
                            :class="tile.detailTone ?? 'text-slate-400'"
                        >
                            {{ tile.detail }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Movimiento del periodo y mezcla por tipo -->
            <div class="mt-4 grid grid-cols-12 items-stretch gap-4">
                <div class="col-span-12 flex flex-col xl:col-span-8">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-primary/10 bg-primary/10 text-primary',
                                ]"
                            >
                                <Lucide icon="ChartColumn" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    {{
                                        groupedByMonth
                                            ? 'Mes por mes'
                                            : 'Día por día'
                                    }}
                                </div>
                                <div class="text-xs text-slate-500">
                                    Cuántas habitaciones se ocuparon y cuántas
                                    rentas entraron
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col px-4 py-3">
                            <div class="h-[240px]">
                                <Chart
                                    type="bar"
                                    :data="dailyData"
                                    :options="dailyOptions"
                                    class="!h-[240px]"
                                />
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
                                    'border-success/10 bg-success/10 text-success',
                                ]"
                            >
                                <Lucide icon="ChartPie" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Qué tipo deja más
                                </div>
                                <div class="text-xs text-slate-500">
                                    Hospedaje vendido por tipo de habitación
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col px-4 py-3">
                            <template v-if="types.length">
                                <div
                                    class="relative mx-auto w-full max-w-[170px]"
                                >
                                    <div class="h-[150px]">
                                        <Chart
                                            type="doughnut"
                                            :data="typesData"
                                            :options="typesOptions"
                                            class="!h-[150px]"
                                        />
                                    </div>
                                    <div
                                        class="absolute inset-0 flex items-center justify-center"
                                    >
                                        <div class="text-center">
                                            <div class="text-sm font-medium">
                                                {{ summary.revenue_label }}
                                            </div>
                                            <div
                                                class="text-[11px] text-slate-500"
                                            >
                                                vendido
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div
                                    class="mt-3 divide-y divide-slate-200/60 dark:divide-darkmode-400"
                                >
                                    <div
                                        v-for="(row, index) in types"
                                        :key="row.name"
                                        class="flex items-center gap-2 py-1.5 text-xs"
                                    >
                                        <span
                                            class="h-2 w-2 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    typeHex[
                                                        index % typeHex.length
                                                    ],
                                            }"
                                        />
                                        <span class="truncate">{{
                                            row.name
                                        }}</span>
                                        <span
                                            class="ml-auto shrink-0 font-medium tabular-nums"
                                        >
                                            {{ row.revenue_label }}
                                        </span>
                                    </div>
                                </div>
                            </template>
                            <p
                                v-else
                                class="py-10 text-center text-xs text-slate-500"
                            >
                                Sin rentas en el periodo.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabla por habitación -->
            <div class="box box--stacked mt-4">
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
                            Lo que trabajó cada una y lo que costó tenerla
                        </div>
                    </div>
                    <div class="ml-auto flex items-center gap-2">
                        <span
                            class="hidden text-[11px] text-slate-400 lg:block"
                        >
                            Ordenar por
                        </span>
                        <FormSelect v-model="sortKey" class="h-8 w-40 text-xs">
                            <option value="nights">Noches ocupadas</option>
                            <option value="uses">Rentas</option>
                            <option value="revenue">Lo que dejó</option>
                            <option value="percent">Porcentaje de uso</option>
                            <option value="incidents">Incidencias</option>
                        </FormSelect>
                    </div>
                </div>

                <div
                    v-if="sortedRooms.length"
                    class="hidden overflow-auto lg:block lg:overflow-visible"
                >
                    <Table hover>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th :class="tableHead"
                                    >Habitación</Table.Th
                                >
                                <Table.Th :class="tableHead">Uso</Table.Th>
                                <Table.Th :class="[tableHead, 'text-right']"
                                    >Rentas</Table.Th
                                >
                                <Table.Th :class="[tableHead, 'text-right']"
                                    >Noches</Table.Th
                                >
                                <Table.Th :class="[tableHead, 'text-right']"
                                    >Dejó</Table.Th
                                >
                                <Table.Th :class="[tableHead, 'text-right']"
                                    >Tarifa</Table.Th
                                >
                                <Table.Th :class="tableHead"
                                    >Mantenimiento</Table.Th
                                >
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr
                                v-for="room in sortedRooms"
                                :key="room.id"
                                class="align-top"
                            >
                                <Table.Td class="whitespace-nowrap">
                                    <Link
                                        :href="
                                            route('tenant.rooms.show', room.id)
                                        "
                                        class="text-sm font-medium transition hover:text-primary"
                                    >
                                        {{ room.name }}
                                    </Link>
                                    <div class="text-xs text-slate-500">
                                        {{ room.type }}
                                        <template v-if="room.zone">
                                            · {{ room.zone }}
                                        </template>
                                    </div>
                                </Table.Td>
                                <!-- La barra compara de un vistazo: la cifra
                                     sola no dice si 9 noches es mucho. -->
                                <Table.Td class="min-w-[9rem]">
                                    <div class="flex items-center gap-2">
                                        <div
                                            class="h-1.5 w-full max-w-[7rem] overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                                        >
                                            <div
                                                class="h-full rounded-full bg-primary"
                                                :style="{
                                                    width: `${Math.round((room.nights / peakNights) * 100)}%`,
                                                }"
                                            />
                                        </div>
                                        <span
                                            class="shrink-0 text-xs font-medium tabular-nums"
                                        >
                                            {{ pct(room.percent) }}
                                        </span>
                                    </div>
                                    <div
                                        class="mt-1 text-[11px] text-slate-400"
                                    >
                                        {{ room.avg_stay_label }} por renta
                                    </div>
                                </Table.Td>
                                <Table.Td
                                    class="text-right text-sm whitespace-nowrap tabular-nums"
                                >
                                    {{ room.uses }}
                                    <div
                                        v-if="room.usage_limit"
                                        class="text-[11px] text-slate-400"
                                    >
                                        {{ room.usage_count }} /
                                        {{ room.usage_limit }} en el contador
                                    </div>
                                </Table.Td>
                                <Table.Td
                                    class="text-right text-sm whitespace-nowrap tabular-nums"
                                >
                                    {{ room.nights }}
                                </Table.Td>
                                <Table.Td class="text-right whitespace-nowrap">
                                    <div
                                        class="text-sm font-medium tabular-nums"
                                    >
                                        {{ money(room.revenue) }}
                                    </div>
                                    <div
                                        v-if="room.consumos > 0"
                                        class="text-[11px] text-slate-400 tabular-nums"
                                    >
                                        + {{ money(room.consumos) }} consumos
                                    </div>
                                </Table.Td>
                                <Table.Td class="text-right whitespace-nowrap">
                                    <div class="text-sm tabular-nums">
                                        {{ money(room.adr) }}
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        por noche
                                    </div>
                                </Table.Td>
                                <Table.Td class="whitespace-nowrap">
                                    <div
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <span
                                            v-if="room.incidents"
                                            class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                            :class="
                                                room.incidents_open
                                                    ? 'bg-danger/10 text-danger'
                                                    : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                            "
                                            :title="
                                                room.incidents_open
                                                    ? `${room.incidents_open} sin resolver`
                                                    : 'Todas resueltas'
                                            "
                                        >
                                            {{
                                                plural(
                                                    room.incidents,
                                                    'incidencia',
                                                    'incidencias',
                                                )
                                            }}
                                        </span>
                                        <span
                                            v-if="room.out_of_service_days"
                                            class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                        >
                                            {{
                                                plural(
                                                    room.out_of_service_days,
                                                    'día fuera',
                                                    'días fuera',
                                                )
                                            }}
                                        </span>
                                        <span
                                            v-if="
                                                !room.incidents &&
                                                !room.out_of_service_days
                                            "
                                            class="text-[11px] text-slate-400"
                                        >
                                            Sin novedad
                                        </span>
                                    </div>
                                    <div
                                        v-if="room.cleanings"
                                        class="mt-1 text-[11px] text-slate-400"
                                    >
                                        {{
                                            plural(
                                                room.cleanings,
                                                'limpieza',
                                                'limpiezas',
                                            )
                                        }}
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </div>

                <!-- Móvil y tablet -->
                <div
                    v-if="sortedRooms.length"
                    class="divide-y divide-slate-200/60 lg:hidden dark:divide-darkmode-400"
                >
                    <div
                        v-for="room in sortedRooms"
                        :key="`m-${room.id}`"
                        class="px-4 py-3.5"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <Link
                                    :href="route('tenant.rooms.show', room.id)"
                                    class="block truncate text-sm font-medium transition hover:text-primary"
                                >
                                    {{ room.name }}
                                </Link>
                                <div class="text-xs text-slate-500">
                                    {{ room.type }}
                                    <template v-if="room.zone">
                                        · {{ room.zone }}
                                    </template>
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="text-sm font-medium tabular-nums">
                                    {{ money(room.revenue) }}
                                </div>
                                <div class="text-[11px] text-slate-400">
                                    {{ pct(room.percent) }} de uso
                                </div>
                            </div>
                        </div>
                        <div
                            class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <Lucide
                                    icon="Repeat"
                                    class="h-3.5 w-3.5 shrink-0 stroke-[1.3]"
                                />
                                {{ plural(room.uses, 'renta', 'rentas') }}
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <Lucide
                                    icon="Moon"
                                    class="h-3.5 w-3.5 shrink-0 stroke-[1.3]"
                                />
                                {{ plural(room.nights, 'noche', 'noches') }}
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <Lucide
                                    icon="Clock"
                                    class="h-3.5 w-3.5 shrink-0 stroke-[1.3]"
                                />
                                {{ room.avg_stay_label }}
                            </span>
                        </div>
                        <div
                            v-if="room.incidents || room.out_of_service_days"
                            class="mt-2 flex flex-wrap items-center gap-1.5"
                        >
                            <span
                                v-if="room.incidents"
                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="
                                    room.incidents_open
                                        ? 'bg-danger/10 text-danger'
                                        : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                "
                            >
                                {{
                                    plural(
                                        room.incidents,
                                        'incidencia',
                                        'incidencias',
                                    )
                                }}
                            </span>
                            <span
                                v-if="room.out_of_service_days"
                                class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                            >
                                {{
                                    plural(
                                        room.out_of_service_days,
                                        'día fuera',
                                        'días fuera',
                                    )
                                }}
                            </span>
                        </div>
                    </div>
                </div>

                <div
                    v-if="!sortedRooms.length"
                    class="flex flex-col items-center gap-2 px-5 py-10 text-center"
                >
                    <Lucide icon="BedDouble" class="h-8 w-8 text-slate-300" />
                    <p class="text-sm font-medium text-slate-600">
                        No hay habitaciones que reportar
                    </p>
                    <p class="text-xs text-slate-500">
                        Revisa los filtros de zona, tipo o habitación.
                    </p>
                </div>
            </div>

            <!-- Qué día se llena y qué zona trabaja -->
            <div class="mt-4 grid grid-cols-12 items-stretch gap-4">
                <div class="col-span-12 flex flex-col xl:col-span-6">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-info/10 bg-info/10 text-info',
                                ]"
                            >
                                <Lucide icon="CalendarDays" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Qué día se llena
                                </div>
                                <div class="text-xs text-slate-500">
                                    Habitaciones ocupadas en promedio por día de
                                    la semana
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col px-4 py-3">
                            <div class="grid grid-cols-7 gap-2">
                                <div
                                    v-for="day in weekdays"
                                    :key="day.label"
                                    class="flex flex-col items-center gap-2"
                                    :title="`${day.uses} rentas en ${day.days} ${day.days === 1 ? 'día' : 'días'}`"
                                >
                                    <span
                                        class="text-[11px] font-medium text-slate-500"
                                    >
                                        {{ day.label }}
                                    </span>
                                    <div
                                        class="flex h-24 w-full items-end justify-center"
                                    >
                                        <span
                                            class="w-5 rounded-t bg-primary/70"
                                            :style="{
                                                height: `${Math.max(3, Math.round((day.average / peakWeekday) * 100))}%`,
                                            }"
                                        />
                                    </div>
                                    <span
                                        class="text-xs font-medium tabular-nums"
                                    >
                                        {{ day.average }}
                                    </span>
                                </div>
                            </div>
                            <p class="mt-3 text-[11px] text-slate-400">
                                Con esto se decide la tarifa del fin de semana y
                                cuánta gente entra a turno.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 flex flex-col xl:col-span-6">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-primary/10 bg-primary/10 text-primary',
                                ]"
                            >
                                <Lucide icon="Map" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">Por zona</div>
                                <div class="text-xs text-slate-500">
                                    Qué parte de la propiedad trabaja más
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="zones.length"
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="zone in zones"
                                :key="zone.name"
                                class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium">
                                        {{ zone.name }}
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        {{
                                            plural(
                                                zone.rooms,
                                                'habitación',
                                                'habitaciones',
                                            )
                                        }}
                                        ·
                                        {{
                                            plural(zone.uses, 'renta', 'rentas')
                                        }}
                                    </div>
                                </div>
                                <span class="text-xs text-slate-500">
                                    {{ pct(zone.percent) }} de uso
                                </span>
                                <span
                                    class="w-24 text-right text-sm font-medium tabular-nums"
                                >
                                    {{ zone.revenue_label }}
                                </span>
                            </div>
                        </div>
                        <p
                            v-else
                            class="px-5 py-8 text-center text-xs text-slate-500"
                        >
                            Sin zonas configuradas.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Dinero dormido y mantenimiento -->
            <div class="mt-4 grid grid-cols-12 items-stretch gap-4">
                <div class="col-span-12 flex flex-col xl:col-span-5">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    idle.length
                                        ? 'border-danger/10 bg-danger/10 text-danger'
                                        : 'border-success/10 bg-success/10 text-success',
                                ]"
                            >
                                <Lucide icon="DoorClosed" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Dinero dormido
                                </div>
                                <div class="text-xs text-slate-500">
                                    Habitaciones que no se rentaron ni una vez
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="idle.length"
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="room in idle"
                                :key="`idle-${room.id}`"
                                class="flex flex-wrap items-center gap-2 px-4 py-2.5"
                            >
                                <Link
                                    :href="route('tenant.rooms.show', room.id)"
                                    class="text-sm font-medium transition hover:text-primary"
                                >
                                    {{ room.name }}
                                </Link>
                                <span class="text-xs text-slate-500">
                                    {{ room.type }}
                                </span>
                                <span
                                    v-if="room.out_of_service_days"
                                    class="ml-auto rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                >
                                    {{
                                        plural(
                                            room.out_of_service_days,
                                            'día fuera de servicio',
                                            'días fuera de servicio',
                                        )
                                    }}
                                </span>
                                <span
                                    v-else
                                    class="ml-auto text-[11px] text-slate-400"
                                >
                                    Estuvo disponible
                                </span>
                            </div>
                        </div>
                        <div
                            v-else
                            class="flex flex-1 flex-col items-center justify-center gap-2 px-5 py-8 text-center"
                        >
                            <Lucide
                                icon="CircleCheck"
                                class="h-8 w-8 text-slate-300"
                            />
                            <p class="text-sm font-medium text-slate-600">
                                Todas se rentaron
                            </p>
                            <p class="text-xs text-slate-500">
                                Ninguna habitación pasó el periodo vacía.
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
                                    'border-pending/10 bg-pending/10 text-pending',
                                ]"
                            >
                                <Lucide icon="Wrench" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Mantenimiento y limpieza
                                </div>
                                <div class="text-xs text-slate-500">
                                    Lo que costó tener las habitaciones listas
                                </div>
                            </div>
                        </div>
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 text-xs dark:border-darkmode-400 dark:bg-darkmode-600/40"
                        >
                            <span
                                class="inline-flex items-center gap-1.5 text-slate-500"
                            >
                                <Lucide
                                    icon="Wrench"
                                    class="h-3.5 w-3.5 text-slate-400"
                                />
                                <span
                                    class="font-medium text-slate-700 dark:text-slate-300"
                                >
                                    {{ summary.incidents }}
                                </span>
                                {{
                                    summary.incidents === 1
                                        ? 'incidencia'
                                        : 'incidencias'
                                }}
                                <span
                                    v-if="summary.incidents_open"
                                    class="text-danger"
                                >
                                    · {{ summary.incidents_open }} sin resolver
                                </span>
                            </span>
                            <span
                                class="inline-flex items-center gap-1.5 text-slate-500"
                            >
                                <Lucide
                                    icon="Banknote"
                                    class="h-3.5 w-3.5 text-slate-400"
                                />
                                <span
                                    class="font-medium text-slate-700 dark:text-slate-300"
                                >
                                    {{ summary.incident_cost_label }}
                                </span>
                                en reparaciones
                            </span>
                            <span
                                class="inline-flex items-center gap-1.5 text-slate-500"
                            >
                                <Lucide
                                    icon="Timer"
                                    class="h-3.5 w-3.5 text-slate-400"
                                />
                                <span
                                    class="font-medium text-slate-700 dark:text-slate-300"
                                >
                                    {{ maintenance.avg_resolution_label }}
                                </span>
                                en resolverse
                            </span>
                            <span
                                class="inline-flex items-center gap-1.5 text-slate-500"
                            >
                                <Lucide
                                    icon="Sparkles"
                                    class="h-3.5 w-3.5 text-slate-400"
                                />
                                <span
                                    class="font-medium text-slate-700 dark:text-slate-300"
                                >
                                    {{ summary.cleanings }}
                                </span>
                                {{
                                    summary.cleanings === 1
                                        ? 'limpieza'
                                        : 'limpiezas'
                                }}
                                <span class="text-slate-400">
                                    ({{ summary.avg_cleaning_label }} cada una)
                                </span>
                            </span>
                        </div>
                        <div
                            v-if="maintenance.categories.length"
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="row in maintenance.categories"
                                :key="row.category"
                                class="flex items-center gap-3 px-4 py-2.5"
                            >
                                <span class="min-w-0 flex-1 truncate text-sm">
                                    {{ row.label }}
                                </span>
                                <span class="text-xs text-slate-500">
                                    {{ plural(row.count, 'vez', 'veces') }}
                                </span>
                                <span
                                    class="w-24 text-right text-sm font-medium tabular-nums"
                                >
                                    {{ row.cost_label }}
                                </span>
                            </div>
                        </div>
                        <div
                            v-else
                            class="flex flex-1 flex-col items-center justify-center gap-2 px-5 py-8 text-center"
                        >
                            <Lucide
                                icon="CircleCheck"
                                class="h-8 w-8 text-slate-300"
                            />
                            <p class="text-sm font-medium text-slate-600">
                                Ninguna falla en el periodo
                            </p>
                            <p class="text-xs text-slate-500">
                                No se levantó ninguna incidencia de estas
                                habitaciones.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
