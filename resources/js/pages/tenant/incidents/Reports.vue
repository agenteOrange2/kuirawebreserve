<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import Chart from '@/components/Base/Chart';
import { FormInput, FormSelect } from '@/components/Base/Form';
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
}>();

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
</script>

<template>
    <RazeLayout title="Reportes de incidencias">
        <div class="mt-2">
            <!-- Encabezado -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link
                        :href="route('tenant.incidents')"
                        class="flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700"
                    >
                        <Lucide icon="ArrowLeft" class="h-4 w-4" /> Incidencias
                    </Link>
                    <h1 class="mt-1 text-lg font-medium">
                        Reportes de incidencias
                    </h1>
                    <p class="text-sm text-slate-500">
                        {{ period.label }} · del {{ period.from }} al
                        {{ period.to }}
                    </p>
                </div>
                <Button
                    as="a"
                    :href="pdfUrl"
                    variant="outline-primary"
                    class="rounded-[0.5rem] bg-white"
                >
                    <Lucide icon="Download" class="mr-2 h-4 w-4" />
                    Descargar PDF
                </Button>
            </div>

            <!-- Filtros: periodo + habitación -->
            <div
                class="box box--stacked mt-5 flex flex-wrap items-center gap-2 p-3"
            >
                <Button
                    v-for="p in periods"
                    :key="p.key"
                    :variant="
                        filters.period === p.key
                            ? 'primary'
                            : 'outline-secondary'
                    "
                    class="rounded-[0.5rem]"
                    :class="filters.period !== p.key && 'bg-white'"
                    @click="goTo(p.key)"
                >
                    <Lucide :icon="p.icon" class="mr-2 h-4 w-4" />
                    {{ p.label }}
                </Button>
                <template v-if="showCustom">
                    <FormInput
                        v-model="customFrom"
                        type="date"
                        class="w-40"
                        @change="applyCustom"
                    />
                    <span class="text-xs text-slate-400">→</span>
                    <FormInput
                        v-model="customTo"
                        type="date"
                        class="w-40"
                        @change="applyCustom"
                    />
                </template>
                <div class="sm:ml-auto">
                    <FormSelect
                        v-model="roomSel"
                        class="w-56"
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

            <!-- KPIs -->
            <div class="mt-5 grid grid-cols-12 gap-5">
                <div class="col-span-6 sm:col-span-4 2xl:col-span-2">
                    <div class="box box--stacked h-full p-5">
                        <div class="text-2xl font-medium">
                            {{ kpis.reported }}
                        </div>
                        <div class="mt-1 text-sm text-slate-500">
                            Reportadas
                        </div>
                    </div>
                </div>
                <div class="col-span-6 sm:col-span-4 2xl:col-span-2">
                    <div class="box box--stacked h-full p-5">
                        <div class="text-2xl font-medium text-success">
                            {{ kpis.resolved }}
                        </div>
                        <div class="mt-1 text-sm text-slate-500">
                            Resueltas en el periodo
                        </div>
                    </div>
                </div>
                <div class="col-span-6 sm:col-span-4 2xl:col-span-2">
                    <div class="box box--stacked h-full p-5">
                        <div class="text-2xl font-medium text-danger">
                            {{ kpis.pending }}
                        </div>
                        <div class="mt-1 text-sm text-slate-500">
                            Siguen pendientes
                        </div>
                    </div>
                </div>
                <div class="col-span-6 sm:col-span-4 2xl:col-span-2">
                    <div class="box box--stacked h-full p-5">
                        <div class="text-2xl font-medium">
                            {{ kpis.resolution_rate }}%
                        </div>
                        <div class="mt-1 text-sm text-slate-500">
                            Tasa de resolución
                        </div>
                    </div>
                </div>
                <div class="col-span-6 sm:col-span-4 2xl:col-span-2">
                    <div class="box box--stacked h-full p-5">
                        <div class="text-2xl font-medium">
                            {{ hoursLabel(kpis.avg_hours) }}
                        </div>
                        <div class="mt-1 text-sm text-slate-500">
                            Tiempo promedio de resolución
                        </div>
                    </div>
                </div>
                <div class="col-span-6 sm:col-span-4 2xl:col-span-2">
                    <div class="box box--stacked h-full p-5">
                        <div class="text-2xl font-medium text-warning">
                            {{ kpis.open_now }}
                        </div>
                        <div class="mt-1 text-sm text-slate-500">
                            Pendientes hoy (total)
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-6">
                <!-- Serie temporal -->
                <div class="col-span-12 flex flex-col xl:col-span-8">
                    <div class="box box--stacked flex-1 p-5">
                        <div class="text-base font-medium">
                            Evolución del periodo
                        </div>
                        <div v-if="series.length" class="mt-4 h-64">
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
                            <p class="text-sm">Sin datos en el periodo.</p>
                        </div>
                    </div>
                </div>

                <!-- Por prioridad -->
                <div class="col-span-12 flex flex-col xl:col-span-4">
                    <div class="box box--stacked flex-1 p-5">
                        <div class="text-base font-medium">Por prioridad</div>
                        <template v-if="byPriority.length">
                            <div class="mx-auto mt-4 h-40 w-40">
                                <Chart
                                    type="doughnut"
                                    :data="donutData"
                                    :options="donutOptions"
                                />
                            </div>
                            <div class="mt-4 space-y-1.5">
                                <div
                                    v-for="row in byPriority"
                                    :key="row.priority"
                                    class="flex items-center justify-between text-sm"
                                >
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="h-2.5 w-2.5 rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    priorityHex[row.priority],
                                            }"
                                        />
                                        {{ row.label }}
                                    </span>
                                    <span class="font-medium">{{
                                        row.count
                                    }}</span>
                                </div>
                            </div>
                        </template>
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 py-10 text-slate-400"
                        >
                            <Lucide icon="ChartPie" class="h-8 w-8" />
                            <p class="text-sm">Sin datos.</p>
                        </div>
                    </div>
                </div>

                <!-- Por habitación -->
                <div class="col-span-12 xl:col-span-8">
                    <div
                        class="box box--stacked overflow-auto p-5 lg:overflow-visible"
                    >
                        <div class="text-base font-medium">Por habitación</div>
                        <Table v-if="byRoom.length" class="mt-3">
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>Habitación</Table.Th>
                                    <Table.Th class="text-right"
                                        >Incidencias</Table.Th
                                    >
                                    <Table.Th class="text-right"
                                        >Alta prioridad</Table.Th
                                    >
                                    <Table.Th class="text-right"
                                        >Resueltas</Table.Th
                                    >
                                    <Table.Th
                                        class="text-right whitespace-nowrap"
                                        >Tiempo promedio</Table.Th
                                    >
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr v-for="row in byRoom" :key="row.name">
                                    <Table.Td class="font-medium">{{
                                        row.name
                                    }}</Table.Td>
                                    <Table.Td class="text-right">{{
                                        row.total
                                    }}</Table.Td>
                                    <Table.Td
                                        class="text-right"
                                        :class="
                                            row.high
                                                ? 'font-medium text-danger'
                                                : 'text-slate-400'
                                        "
                                        >{{ row.high }}</Table.Td
                                    >
                                    <Table.Td class="text-right">{{
                                        row.resolved
                                    }}</Table.Td>
                                    <Table.Td
                                        class="text-right whitespace-nowrap"
                                        >{{
                                            hoursLabel(row.avg_hours)
                                        }}</Table.Td
                                    >
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>
                        <p v-else class="mt-3 text-sm text-slate-400">
                            Sin incidencias en el periodo.
                        </p>
                    </div>
                </div>

                <!-- Por estado -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="box box--stacked h-full p-5">
                        <div class="text-base font-medium">
                            Estado de lo reportado
                        </div>
                        <div class="mt-3 space-y-2">
                            <div
                                v-for="row in byStatus"
                                :key="row.status"
                                class="flex items-center justify-between rounded-lg border border-slate-200/70 px-3.5 py-2.5 dark:border-darkmode-400"
                            >
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="statusClass(row.status)"
                                >
                                    {{ row.label }}
                                </span>
                                <span class="font-medium">{{ row.count }}</span>
                            </div>
                            <p
                                v-if="!byStatus.length"
                                class="text-sm text-slate-400"
                            >
                                Sin datos.
                            </p>
                            <p
                                class="flex items-center gap-1.5 pt-1 text-xs text-slate-400"
                            >
                                <Lucide icon="Info" class="h-3.5 w-3.5" />
                                {{ kpis.rooms_affected }} habitación(es) con al
                                menos una incidencia en el periodo.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Por tipo de falla -->
                <div class="col-span-12">
                    <div
                        class="box box--stacked overflow-auto p-5 lg:overflow-visible"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="text-base font-medium">
                                Por tipo de falla
                            </div>
                            <span
                                v-if="guestReported > 0"
                                class="rounded-full bg-info/10 px-2.5 py-0.5 text-xs font-medium text-info"
                            >
                                {{ guestReported }} reportada(s) por huéspedes
                            </span>
                        </div>
                        <Table v-if="byCategory.length" class="mt-3">
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>Tipo</Table.Th>
                                    <Table.Th class="text-right"
                                        >Incidencias</Table.Th
                                    >
                                    <Table.Th class="text-right"
                                        >Alta prioridad</Table.Th
                                    >
                                    <Table.Th
                                        class="text-right whitespace-nowrap"
                                        >Reportó huésped</Table.Th
                                    >
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr
                                    v-for="row in byCategory"
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
                                            row.high
                                                ? 'font-medium text-danger'
                                                : 'text-slate-400'
                                        "
                                        >{{ row.high }}</Table.Td
                                    >
                                    <Table.Td
                                        class="text-right"
                                        :class="
                                            row.guest ? '' : 'text-slate-400'
                                        "
                                        >{{ row.guest }}</Table.Td
                                    >
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>
                        <p v-else class="mt-3 text-sm text-slate-400">
                            Sin incidencias en el periodo.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
