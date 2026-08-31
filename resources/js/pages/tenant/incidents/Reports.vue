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
    reported: number;
    resolved: number;
}
interface PriorityRow {
    priority: string;
    label: string;
    count: number;
}
interface StatusRow {
    status: string;
    label: string;
    count: number;
}
interface RoomRow {
    name: string;
    total: number;
    high: number;
    resolved: number;
    avg_hours: number | null;
}

const props = defineProps<{
    property: { id: number; name: string };
    rooms: Array<{ id: number; label: string }>;
    filters: {
        period: string;
        from: string;
        to: string;
        room: number | null;
    };
    period: { label: string; from: string; to: string };
    kpis: {
        reported: number;
        resolved: number;
        pending: number;
        high: number;
        resolution_rate: number;
        avg_hours: number | null;
        rooms_affected: number;
        open_now: number;
    };
    series: SeriesBucket[];
    byPriority: PriorityRow[];
    byStatus: StatusRow[];
    byRoom: RoomRow[];
    byCategory: Array<{
        name: string;
        total: number;
        high: number;
        guest: number;
    }>;
    guestReported: number;
    costs: {
        total: number;
        jobs: number;
        missing: number;
        charged: number;
        charged_jobs: number;
        byRoom: Array<{ name: string; jobs: number; cost: number }>;
        byTechnician: Array<{
            name: string;
            kind: string | null;
            jobs: number;
            cost: number;
        }>;
    };
}>();

const money = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
});

// ── Selector de periodo ───────────────────────────────────────
const periods: { key: string; label: string; icon: Icon }[] = [
    { key: 'day', label: 'Hoy', icon: 'CalendarCheck' },
    { key: 'week', label: 'Semana', icon: 'CalendarDays' },
    { key: 'month', label: 'Mes', icon: 'Calendar' },
    { key: 'year', label: 'Año', icon: 'CalendarRange' },
    { key: 'custom', label: 'Personalizado', icon: 'CalendarSearch' },
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
        showCustom.value = true;
        applyCustom();
        return;
    }
    showCustom.value = false;
    router.get(route('tenant.incidents.reports'), query(period), {
        preserveScroll: true,
    });
}

function applyCustom() {
    if (!customFrom.value || !customTo.value) return;
    router.get(route('tenant.incidents.reports'), query('custom'), {
        preserveScroll: true,
    });
}

function applyRoom() {
    router.get(route('tenant.incidents.reports'), query(props.filters.period), {
        preserveScroll: true,
    });
}

const pdfUrl = computed(() =>
    route('tenant.incidents.reports.pdf', query(props.filters.period)),
);

// ── Charts (mismos hex del reporte de reservas) ───────────────
const lineData = computed(() => ({
    labels: props.series.map((b) => b.label),
    datasets: [
        {
            label: 'Reportadas',
            data: props.series.map((b) => b.reported),
            borderColor: '#b91c1c',
            backgroundColor: 'rgba(185,28,28,0.06)',
            fill: true,
        },
        {
            label: 'Resueltas',
            data: props.series.map((b) => b.resolved),
            borderColor: '#0d9488',
            backgroundColor: 'rgba(13,148,136,0.08)',
            fill: true,
        },
    ],
}));

const lineOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: true,
            position: 'bottom' as const,
            labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true },
        },
    },
    scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, ticks: { precision: 0 } },
    },
    elements: { point: { radius: 2 }, line: { tension: 0.4, borderWidth: 2 } },
};

const priorityHex: Record<string, string> = {
    high: '#b91c1c',
    medium: '#ca8a04',
    low: '#64748b',
};

const donutData = computed(() => ({
    labels: props.byPriority.map((p) => p.label),
    datasets: [
        {
            data: props.byPriority.map((p) => p.count),
            backgroundColor: props.byPriority.map(
                (p) => priorityHex[p.priority] ?? '#94a3b8',
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

const statusClass = (status: string) =>
    status === 'open'
        ? 'bg-danger/10 text-danger'
        : status === 'in_progress'
          ? 'bg-warning/10 text-warning'
          : 'bg-success/10 text-success';

const hoursLabel = (hours: number | null) =>
    hours === null
        ? '—'
        : hours >= 48
          ? `${Math.round(hours / 24)} días`
          : `${hours} h`;

/** Tarjetas de cifras: mismo renglón compacto que el resto del panel. */
const kpiCards = computed(() => [
    {
        label: 'Reportadas',
        value: String(props.kpis.reported),
        icon: 'Wrench' as Icon,
        tint: 'border-primary/10 bg-primary/10 text-primary',
    },
    {
        label: 'Resueltas en el periodo',
        value: String(props.kpis.resolved),
        icon: 'CircleCheck' as Icon,
        tint: 'border-success/10 bg-success/10 text-success',
    },
    {
        label: 'Siguen pendientes',
        value: String(props.kpis.pending),
        icon: 'CircleAlert' as Icon,
        tint: 'border-danger/10 bg-danger/10 text-danger',
    },
    {
        label: 'Tasa de resolución',
        value: `${props.kpis.resolution_rate}%`,
        icon: 'ChartPie' as Icon,
        tint: 'border-info/10 bg-info/10 text-info',
    },
    {
        label: 'Tiempo promedio',
        value: hoursLabel(props.kpis.avg_hours),
        icon: 'Timer' as Icon,
        tint: 'border-warning/10 bg-warning/10 text-warning',
    },
    {
        label: 'Pendientes hoy (total)',
        value: String(props.kpis.open_now),
        icon: 'Hammer' as Icon,
        tint: 'border-pending/10 bg-pending/10 text-pending',
    },
]);

/** Qué habitación se está mirando, para la franja del encabezado. */
const roomFilterLabel = computed(
    () =>
        props.rooms.find((room) => room.id === props.filters.room)?.label ??
        'Todas las habitaciones',
);

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const stripItem = 'inline-flex items-center gap-1.5 text-slate-500';
const stripValue = 'font-medium text-slate-700 dark:text-slate-300';
const stripDivider =
    'hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400';
</script>

<template>
    <RazeLayout title="Reportes de incidencias">
        <div class="mt-2">
            <!-- Encabezado: el volver ya no cuelga sobre el título, va del
                 lado de las acciones. -->
            <div class="box box--stacked overflow-hidden">
                <div
                    class="flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="ChartColumn" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-base font-medium">
                                Reportes de incidencias
                            </h1>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Cuánto se rompe, qué tan rápido se arregla y
                                cuánto cuesta.
                            </p>
                        </div>
                    </div>
                    <div
                        class="flex w-full flex-wrap items-center gap-2 md:w-auto md:shrink-0 md:justify-end"
                    >
                        <Link
                            :href="route('tenant.incidents')"
                            class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                        >
                            <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                            Volver a incidencias
                        </Link>
                        <Button
                            as="a"
                            :href="pdfUrl"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        >
                            <Lucide
                                icon="Download"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Descargar PDF
                        </Button>
                    </div>
                </div>

                <!-- Qué se está viendo: periodo, filtro y alcance -->
                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-slate-200/60 bg-slate-50/70 px-4 py-3 text-xs sm:px-5 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <span :class="stripItem">
                        <Lucide
                            icon="CalendarRange"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">{{ period.label }}</span>
                        del {{ period.from }} al {{ period.to }}
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="BedDouble"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">
                            {{ roomFilterLabel }}
                        </span>
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="Building2"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">
                            {{ kpis.rooms_affected }}
                        </span>
                        con al menos una falla
                    </span>
                    <span
                        v-if="guestReported > 0"
                        class="inline-flex items-center gap-1.5 rounded-full bg-info/10 px-2.5 py-1 text-[11px] font-medium text-info md:ml-auto"
                    >
                        <Lucide icon="MessageSquare" class="h-3.5 w-3.5" />
                        {{ guestReported }} reportada(s) por huéspedes
                    </span>
                </div>
            </div>

            <!-- Periodo y habitación -->
            <div
                class="box box--stacked mt-4 flex flex-wrap items-center gap-2 p-3"
            >
                <Button
                    v-for="p in periods"
                    :key="p.key"
                    :variant="
                        filters.period === p.key
                            ? 'primary'
                            : 'outline-secondary'
                    "
                    class="h-9 rounded-[0.5rem] text-xs"
                    :class="filters.period !== p.key && 'bg-white'"
                    @click="goTo(p.key)"
                >
                    <Lucide :icon="p.icon" class="mr-1.5 h-3.5 w-3.5" />
                    {{ p.label }}
                </Button>
                <template v-if="showCustom">
                    <FormDate
                        v-model="customFrom"
                        class="w-36"
                        input-class="h-9 text-xs"
                        @change="applyCustom"
                    />
                    <span class="text-xs text-slate-400">→</span>
                    <FormDate
                        v-model="customTo"
                        class="w-36"
                        input-class="h-9 text-xs"
                        @change="applyCustom"
                    />
                </template>
                <div class="w-full sm:ml-auto sm:w-56">
                    <FormSelect
                        v-model="roomSel"
                        class="h-9 text-xs"
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

            <!-- Cifras del periodo -->
            <div class="mt-4 grid grid-cols-12 gap-4">
                <div
                    v-for="card in kpiCards"
                    :key="card.label"
                    class="col-span-6 sm:col-span-4 xl:col-span-2"
                >
                    <div
                        class="box box--stacked flex h-full items-center gap-2.5 p-3"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                            :class="card.tint"
                        >
                            <Lucide :icon="card.icon" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-sm font-medium">
                                {{ card.value }}
                            </div>
                            <div class="truncate text-xs text-slate-500">
                                {{ card.label }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-12 gap-5">
                <!-- Serie temporal -->
                <div class="col-span-12 flex flex-col xl:col-span-8">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-primary/10 bg-primary/10 text-primary"
                            >
                                <Lucide icon="ChartLine" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Evolución del periodo
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Lo que se reportó contra lo que se resolvió.
                                </p>
                            </div>
                        </div>
                        <div class="flex-1 px-4 py-3">
                            <div v-if="series.length" class="h-64">
                                <Chart
                                    type="line"
                                    :data="lineData"
                                    :options="lineOptions"
                                />
                            </div>
                            <div
                                v-else
                                class="flex flex-col items-center gap-2 py-10 text-slate-400"
                            >
                                <Lucide icon="ChartLine" class="h-8 w-8" />
                                <p class="text-xs">Sin datos en el periodo.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Por prioridad -->
                <div class="col-span-12 flex flex-col xl:col-span-4">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-danger/10 bg-danger/10 text-danger"
                            >
                                <Lucide icon="ChartPie" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Por prioridad
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Qué tan urgente es lo que entra.
                                </p>
                            </div>
                        </div>
                        <div class="flex-1 px-4 py-3">
                            <template v-if="byPriority.length">
                                <div class="mx-auto h-36 w-36">
                                    <Chart
                                        type="doughnut"
                                        :data="donutData"
                                        :options="donutOptions"
                                    />
                                </div>
                                <div class="mt-3 space-y-1.5">
                                    <div
                                        v-for="row in byPriority"
                                        :key="row.priority"
                                        class="flex items-center justify-between text-xs"
                                    >
                                        <span class="flex items-center gap-2">
                                            <span
                                                class="h-2.5 w-2.5 rounded-full"
                                                :style="{
                                                    backgroundColor:
                                                        priorityHex[
                                                            row.priority
                                                        ],
                                                }"
                                            />
                                            {{ row.label }}
                                        </span>
                                        <span class="font-medium">
                                            {{ row.count }}
                                        </span>
                                    </div>
                                </div>
                            </template>
                            <div
                                v-else
                                class="flex flex-col items-center gap-2 py-10 text-slate-400"
                            >
                                <Lucide icon="ChartPie" class="h-8 w-8" />
                                <p class="text-xs">Sin datos.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Por habitación -->
                <div class="col-span-12 flex flex-col xl:col-span-8">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-info/10 bg-info/10 text-info"
                            >
                                <Lucide icon="BedDouble" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Por habitación
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Dónde se concentran las fallas.
                                </p>
                            </div>
                        </div>
                        <div
                            class="flex-1 overflow-auto px-4 py-3 lg:overflow-visible"
                        >
                            <Table v-if="byRoom.length">
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th>Habitación</Table.Th>
                                        <Table.Th class="text-right">
                                            Incidencias
                                        </Table.Th>
                                        <Table.Th class="text-right">
                                            Alta prioridad
                                        </Table.Th>
                                        <Table.Th class="text-right">
                                            Resueltas
                                        </Table.Th>
                                        <Table.Th
                                            class="text-right whitespace-nowrap"
                                        >
                                            Tiempo promedio
                                        </Table.Th>
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    <Table.Tr
                                        v-for="row in byRoom"
                                        :key="row.name"
                                    >
                                        <Table.Td class="font-medium">
                                            {{ row.name }}
                                        </Table.Td>
                                        <Table.Td class="text-right">
                                            {{ row.total }}
                                        </Table.Td>
                                        <Table.Td
                                            class="text-right"
                                            :class="
                                                row.high
                                                    ? 'font-medium text-danger'
                                                    : 'text-slate-400'
                                            "
                                        >
                                            {{ row.high }}
                                        </Table.Td>
                                        <Table.Td class="text-right">
                                            {{ row.resolved }}
                                        </Table.Td>
                                        <Table.Td
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{ hoursLabel(row.avg_hours) }}
                                        </Table.Td>
                                    </Table.Tr>
                                </Table.Tbody>
                            </Table>
                            <p v-else class="text-xs text-slate-400">
                                Sin incidencias en el periodo.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Por estado -->
                <div class="col-span-12 flex flex-col xl:col-span-4">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-warning/10 bg-warning/10 text-warning"
                            >
                                <Lucide icon="ListChecks" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Estado de lo reportado
                                </h2>
                                <p class="text-xs text-slate-500">
                                    En qué acabó lo que entró en el periodo.
                                </p>
                            </div>
                        </div>
                        <div class="flex-1 space-y-2 px-4 py-3">
                            <div
                                v-for="row in byStatus"
                                :key="row.status"
                                class="flex items-center justify-between rounded-lg border border-slate-200/70 px-3 py-2 dark:border-darkmode-400"
                            >
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="statusClass(row.status)"
                                >
                                    {{ row.label }}
                                </span>
                                <span class="text-sm font-medium">
                                    {{ row.count }}
                                </span>
                            </div>
                            <p
                                v-if="!byStatus.length"
                                class="text-xs text-slate-400"
                            >
                                Sin datos.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Por tipo de falla -->
                <div class="col-span-12">
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-primary/10 bg-primary/10 text-primary"
                            >
                                <Lucide
                                    icon="SlidersHorizontal"
                                    class="h-4 w-4"
                                />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Por tipo de falla
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Qué se descompone una y otra vez.
                                </p>
                            </div>
                        </div>
                        <div
                            class="overflow-auto px-4 py-3 lg:overflow-visible"
                        >
                            <Table v-if="byCategory.length">
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th>Tipo</Table.Th>
                                        <Table.Th class="text-right">
                                            Incidencias
                                        </Table.Th>
                                        <Table.Th class="text-right">
                                            Alta prioridad
                                        </Table.Th>
                                        <Table.Th
                                            class="text-right whitespace-nowrap"
                                        >
                                            Reportó huésped
                                        </Table.Th>
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    <Table.Tr
                                        v-for="row in byCategory"
                                        :key="row.name"
                                    >
                                        <Table.Td class="font-medium">
                                            {{ row.name }}
                                        </Table.Td>
                                        <Table.Td class="text-right">
                                            {{ row.total }}
                                        </Table.Td>
                                        <Table.Td
                                            class="text-right"
                                            :class="
                                                row.high
                                                    ? 'font-medium text-danger'
                                                    : 'text-slate-400'
                                            "
                                        >
                                            {{ row.high }}
                                        </Table.Td>
                                        <Table.Td
                                            class="text-right"
                                            :class="
                                                row.guest
                                                    ? ''
                                                    : 'text-slate-400'
                                            "
                                        >
                                            {{ row.guest }}
                                        </Table.Td>
                                    </Table.Tr>
                                </Table.Tbody>
                            </Table>
                            <p v-else class="text-xs text-slate-400">
                                Sin incidencias en el periodo.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Costo de las reparaciones: se mide sobre lo resuelto,
                     que es cuando se paga, no cuando se reporta -->
                <div class="col-span-12">
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-success/10 bg-success/10 text-success"
                            >
                                <Lucide icon="Wallet" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Costo de las reparaciones
                                </h2>
                                <p class="text-xs text-slate-500">
                                    De lo resuelto en el periodo, que es cuando
                                    se paga.
                                </p>
                            </div>
                        </div>
                        <div class="px-4 py-3">
                            <div class="grid grid-cols-12 gap-3">
                                <div class="col-span-12 sm:col-span-4">
                                    <div
                                        class="h-full rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400"
                                    >
                                        <div class="text-xs text-slate-500">
                                            Se gastó
                                        </div>
                                        <div
                                            class="mt-0.5 text-base font-medium"
                                        >
                                            {{ money.format(costs.total) }}
                                        </div>
                                        <div
                                            class="mt-0.5 text-xs text-slate-400"
                                        >
                                            {{ costs.jobs }} reparación(es) con
                                            costo capturado
                                        </div>
                                    </div>
                                </div>
                                <div class="col-span-12 sm:col-span-4">
                                    <div
                                        class="h-full rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400"
                                    >
                                        <div class="text-xs text-slate-500">
                                            Se le cobró al huésped
                                        </div>
                                        <div
                                            class="mt-0.5 text-base font-medium"
                                        >
                                            {{ money.format(costs.charged) }}
                                        </div>
                                        <div
                                            class="mt-0.5 text-xs text-slate-400"
                                        >
                                            {{ costs.charged_jobs }} daño(s)
                                            cargados a una cuenta
                                        </div>
                                    </div>
                                </div>
                                <div class="col-span-12 sm:col-span-4">
                                    <div
                                        class="h-full rounded-lg border p-3"
                                        :class="
                                            costs.total - costs.charged > 0
                                                ? 'border-pending/20 bg-pending/5'
                                                : 'border-success/20 bg-success/5'
                                        "
                                    >
                                        <div class="text-xs text-slate-500">
                                            Puso la casa
                                        </div>
                                        <div
                                            class="mt-0.5 text-base font-medium"
                                        >
                                            {{
                                                money.format(
                                                    Math.max(
                                                        0,
                                                        costs.total -
                                                            costs.charged,
                                                    ),
                                                )
                                            }}
                                        </div>
                                        <div
                                            v-if="costs.missing > 0"
                                            class="mt-0.5 text-xs text-slate-400"
                                        >
                                            {{ costs.missing }} resuelta(s) sin
                                            costo capturado: la cuenta puede ser
                                            mayor.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 grid grid-cols-12 gap-5">
                                <div class="col-span-12 lg:col-span-6">
                                    <div
                                        class="mb-2 flex items-center gap-1.5 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                    >
                                        <Lucide
                                            icon="BedDouble"
                                            class="h-3.5 w-3.5"
                                        />
                                        Qué habitación sale cara
                                    </div>
                                    <Table v-if="costs.byRoom.length">
                                        <Table.Thead>
                                            <Table.Tr>
                                                <Table.Th>Habitación</Table.Th>
                                                <Table.Th class="text-right">
                                                    Trabajos
                                                </Table.Th>
                                                <Table.Th class="text-right">
                                                    Costo
                                                </Table.Th>
                                            </Table.Tr>
                                        </Table.Thead>
                                        <Table.Tbody>
                                            <Table.Tr
                                                v-for="row in costs.byRoom"
                                                :key="row.name"
                                            >
                                                <Table.Td class="font-medium">
                                                    {{ row.name }}
                                                </Table.Td>
                                                <Table.Td class="text-right">
                                                    {{ row.jobs }}
                                                </Table.Td>
                                                <Table.Td
                                                    class="text-right font-medium whitespace-nowrap"
                                                >
                                                    {{ money.format(row.cost) }}
                                                </Table.Td>
                                            </Table.Tr>
                                        </Table.Tbody>
                                    </Table>
                                    <p v-else class="text-xs text-slate-400">
                                        Nadie capturó costos en el periodo.
                                    </p>
                                </div>
                                <div class="col-span-12 lg:col-span-6">
                                    <div
                                        class="mb-2 flex items-center gap-1.5 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                    >
                                        <Lucide
                                            icon="HardHat"
                                            class="h-3.5 w-3.5"
                                        />
                                        Quién hizo el trabajo
                                    </div>
                                    <Table v-if="costs.byTechnician.length">
                                        <Table.Thead>
                                            <Table.Tr>
                                                <Table.Th>
                                                    Quién reparó
                                                </Table.Th>
                                                <Table.Th class="text-right">
                                                    Trabajos
                                                </Table.Th>
                                                <Table.Th class="text-right">
                                                    Costo
                                                </Table.Th>
                                            </Table.Tr>
                                        </Table.Thead>
                                        <Table.Tbody>
                                            <Table.Tr
                                                v-for="row in costs.byTechnician"
                                                :key="row.name"
                                            >
                                                <Table.Td>
                                                    <span class="font-medium">
                                                        {{ row.name }}
                                                    </span>
                                                    <div
                                                        v-if="row.kind"
                                                        class="text-[11px] text-slate-400"
                                                    >
                                                        {{ row.kind }}
                                                    </div>
                                                </Table.Td>
                                                <Table.Td class="text-right">
                                                    {{ row.jobs }}
                                                </Table.Td>
                                                <Table.Td
                                                    class="text-right font-medium whitespace-nowrap"
                                                >
                                                    {{ money.format(row.cost) }}
                                                </Table.Td>
                                            </Table.Tr>
                                        </Table.Tbody>
                                    </Table>
                                    <p v-else class="text-xs text-slate-400">
                                        Nadie capturó costos en el periodo.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
