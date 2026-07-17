<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import Chart from '@/components/Base/Chart';
import { FormInput } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface SeriesBucket {
    label: string;
    reservations: number;
    cancelled: number;
    revenue: number;
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

const props = defineProps<{
    property: { id: number; name: string };
    filters: { period: string; from: string; to: string };
    period: { label: string; from: string; to: string };
    kpis: {
        total: number;
        confirmed: number;
        checked_in: number;
        completed: number;
        pending: number;
        cancelled: number;
        no_show: number;
        cancel_rate: number;
        no_show_rate: number;
        reserved_value: number;
        avg_reservation: number;
        payments_total: number;
        orders_total: number;
        revenue_total: number;
        check_ins: number;
        check_outs: number;
    };
    series: SeriesBucket[];
    byStatus: StatusRow[];
    byRoomType: RoomTypeRow[];
    byChannel: ChannelRow[];
}>();

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n ?? 0);

// ── Selector de periodo ───────────────────────────────────────
const periods: { key: string; label: string; icon: Icon }[] = [
    { key: 'week', label: 'Semana', icon: 'CalendarDays' },
    { key: 'month', label: 'Mes', icon: 'Calendar' },
    { key: 'year', label: 'Año', icon: 'CalendarRange' },
    { key: 'custom', label: 'Personalizado', icon: 'CalendarSearch' },
];

const customFrom = ref(props.filters.from);
const customTo = ref(props.filters.to);

function goTo(period: string) {
    if (period === 'custom') {
        applyCustom();
        return;
    }
    router.get(
        route('tenant.reservations.reports'),
        { period },
        { preserveScroll: true },
    );
}

function applyCustom() {
    if (!customFrom.value || !customTo.value) return;
    router.get(
        route('tenant.reservations.reports'),
        { period: 'custom', from: customFrom.value, to: customTo.value },
        { preserveScroll: true },
    );
}

const showCustom = ref(props.filters.period === 'custom');

const pdfUrl = computed(() =>
    route('tenant.reservations.reports.pdf', {
        period: props.filters.period,
        ...(props.filters.period === 'custom'
            ? { from: props.filters.from, to: props.filters.to }
            : {}),
    }),
);

// ── Charts (tokens del theme) ─────────────────────────────────
const statusHex: Record<string, string> = {
    pending: '#ca8a04',
    confirmed: '#03045e',
    checked_in: '#0d9488',
    completed: '#1e293b',
    cancelled: '#b91c1c',
    no_show: '#c2410c',
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

const revenueData = computed(() => ({
    labels: props.series.map((b) => b.label),
    datasets: [
        {
            label: 'Ingresos',
            data: props.series.map((b) => b.revenue),
            backgroundColor: 'rgba(13,148,136,0.75)',
            borderRadius: 4,
        },
    ],
}));

const revenueOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { x: { grid: { display: false } }, y: { beginAtZero: true } },
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

const channelLabels: Record<string, string> = {
    front_desk: 'Mostrador',
    phone: 'Teléfono',
    web: 'Web',
    whatsapp: 'WhatsApp',
    walk_in: 'Walk-in',
};
const channelIcons: Record<string, Icon> = {
    front_desk: 'ConciergeBell',
    phone: 'Phone',
    web: 'Globe',
    whatsapp: 'MessageCircle',
    walk_in: 'Footprints',
};

const effective = computed(
    () => props.kpis.confirmed + props.kpis.checked_in + props.kpis.completed,
);
const maxChannel = computed(() =>
    Math.max(1, ...props.byChannel.map((c) => c.count)),
);
</script>

<template>
    <RazeLayout title="Reportes de reservas">
        <div class="grid grid-cols-12 gap-x-6 gap-y-10">
            <!-- Encabezado -->
            <div class="col-span-12">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-lg font-medium">
                            Reportes de reservas
                        </h1>
                        <p class="text-sm text-slate-500">
                            {{ property.name }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            as="a"
                            :href="route('tenant.reservations')"
                            variant="outline-secondary"
                            class="rounded-[0.5rem] bg-white"
                        >
                            <Lucide
                                icon="ArrowLeft"
                                class="mr-2 h-4 w-4 stroke-[1.3]"
                            />
                            Volver a reservas
                        </Button>
                        <Button
                            as="a"
                            :href="pdfUrl"
                            variant="primary"
                            class="rounded-[0.5rem] shadow-md shadow-primary/20"
                        >
                            <Lucide
                                icon="FileDown"
                                class="mr-2 h-4 w-4 stroke-[1.3]"
                            />
                            Descargar PDF
                        </Button>
                    </div>
                </div>

                <!-- Barra de periodo -->
                <div
                    class="box box--stacked mt-5 flex flex-wrap items-center gap-3 p-3"
                >
                    <div
                        class="inline-flex flex-wrap gap-1 rounded-[0.6rem] bg-slate-100/80 p-1 dark:bg-darkmode-700"
                    >
                        <button
                            v-for="p in periods"
                            :key="p.key"
                            class="flex items-center gap-2 rounded-[0.5rem] px-3.5 py-1.5 text-sm font-medium transition"
                            :class="
                                filters.period === p.key
                                    ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                            "
                            @click="
                                p.key === 'custom'
                                    ? (showCustom = true)
                                    : goTo(p.key)
                            "
                        >
                            <Lucide
                                :icon="p.icon"
                                class="h-4 w-4 stroke-[1.3]"
                            />
                            {{ p.label }}
                        </button>
                    </div>
                    <div
                        v-if="showCustom || filters.period === 'custom'"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <div class="relative">
                            <Lucide
                                icon="Calendar"
                                class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                            />
                            <FormInput
                                v-model="customFrom"
                                type="date"
                                class="w-40 pl-9"
                            />
                        </div>
                        <span class="text-slate-400">→</span>
                        <div class="relative">
                            <Lucide
                                icon="Calendar"
                                class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                            />
                            <FormInput
                                v-model="customTo"
                                type="date"
                                class="w-40 pl-9"
                            />
                        </div>
                        <Button
                            variant="outline-primary"
                            class="rounded-[0.5rem] bg-white"
                            :disabled="!customFrom || !customTo"
                            @click="applyCustom"
                        >
                            <Lucide icon="Check" class="mr-1.5 h-4 w-4" />
                            Aplicar
                        </Button>
                    </div>
                    <div
                        class="ml-auto flex items-center gap-2 rounded-[0.5rem] border border-dashed border-slate-300/70 px-3 py-1.5 text-sm dark:border-darkmode-400"
                    >
                        <Lucide
                            icon="CalendarRange"
                            class="h-4 w-4 stroke-[1.3] text-primary"
                        />
                        <span class="font-medium">{{ period.label }}</span>
                        <span class="hidden text-xs text-slate-400 sm:inline"
                            >{{ period.from }} – {{ period.to }}</span
                        >
                    </div>
                </div>

                <!-- KPIs -->
                <div class="mt-5 grid grid-cols-12 gap-5">
                    <div
                        class="box box--stacked col-span-12 p-5 sm:col-span-6 2xl:col-span-3"
                    >
                        <div class="flex items-center">
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                            >
                                <Lucide
                                    icon="CalendarDays"
                                    class="h-6 w-6 fill-primary/10 text-primary"
                                />
                            </div>
                            <div class="ml-auto text-right">
                                <div class="text-2xl leading-none font-medium">
                                    {{ kpis.total }}
                                </div>
                                <div class="mt-1 text-xs text-slate-500">
                                    {{ effective }} efectivas
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 text-base font-medium">
                            Reservas del periodo
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            {{ kpis.pending }} pendiente(s) por confirmar
                        </div>
                    </div>

                    <div
                        class="box box--stacked col-span-12 p-5 sm:col-span-6 2xl:col-span-3"
                    >
                        <div class="flex items-center">
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-full border border-danger/10 bg-danger/10"
                            >
                                <Lucide
                                    icon="Ban"
                                    class="h-6 w-6 fill-danger/10 text-danger"
                                />
                            </div>
                            <div class="ml-auto text-right">
                                <div class="text-2xl leading-none font-medium">
                                    {{ kpis.cancelled + kpis.no_show }}
                                </div>
                                <div class="mt-1 text-xs text-slate-500">
                                    {{ kpis.cancelled }} canc. ·
                                    {{ kpis.no_show }} no-show
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 text-base font-medium">
                            Canceladas / No-show
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            Tasas: {{ kpis.cancel_rate }}% canc. ·
                            {{ kpis.no_show_rate }}% no-show
                        </div>
                    </div>

                    <div
                        class="box box--stacked col-span-12 p-5 sm:col-span-6 2xl:col-span-3"
                    >
                        <div class="flex items-center">
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-full border border-success/10 bg-success/10"
                            >
                                <Lucide
                                    icon="DollarSign"
                                    class="h-6 w-6 fill-success/10 text-success"
                                />
                            </div>
                            <div class="ml-auto text-right">
                                <div class="text-2xl leading-none font-medium">
                                    {{ money(kpis.revenue_total) }}
                                </div>
                                <div class="mt-1 text-xs text-slate-500">
                                    cobrado en el periodo
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 text-base font-medium">Ingresos</div>
                        <div
                            class="mt-1 flex flex-wrap gap-x-3 text-xs text-slate-500"
                        >
                            <span
                                >Reservas {{ money(kpis.payments_total) }}</span
                            >
                            <span>POS {{ money(kpis.orders_total) }}</span>
                        </div>
                    </div>

                    <div
                        class="box box--stacked col-span-12 p-5 sm:col-span-6 2xl:col-span-3"
                    >
                        <div class="flex items-center">
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-full border border-info/10 bg-info/10"
                            >
                                <Lucide
                                    icon="ArrowRightLeft"
                                    class="h-6 w-6 fill-info/10 text-info"
                                />
                            </div>
                            <div
                                class="ml-auto flex items-center gap-4 text-right"
                            >
                                <div>
                                    <div
                                        class="text-2xl leading-none font-medium text-success"
                                    >
                                        {{ kpis.check_ins }}
                                    </div>
                                    <div class="mt-1 text-xs text-slate-500">
                                        check-ins
                                    </div>
                                </div>
                                <div>
                                    <div
                                        class="text-2xl leading-none font-medium text-pending"
                                    >
                                        {{ kpis.check_outs }}
                                    </div>
                                    <div class="mt-1 text-xs text-slate-500">
                                        check-outs
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 text-base font-medium">Movimiento</div>
                        <div class="mt-1 text-xs text-slate-500">
                            Reserva promedio: {{ money(kpis.avg_reservation) }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Evolución + estados -->
            <div class="col-span-12 flex flex-col xl:col-span-8">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">
                        Evolución del periodo
                    </div>
                </div>
                <div class="box box--stacked mt-3.5 flex flex-1 flex-col p-5">
                    <div class="h-[260px]">
                        <Chart
                            type="line"
                            :data="lineData"
                            :options="lineOptions"
                            class="!h-[260px]"
                        />
                    </div>
                    <div
                        class="mt-6 border-t border-dashed border-slate-300/70 pt-5 dark:border-darkmode-400"
                    >
                        <div
                            class="mb-3 text-sm font-medium text-slate-600 dark:text-slate-300"
                        >
                            Ingresos por periodo
                        </div>
                        <div class="h-[160px]">
                            <Chart
                                type="bar"
                                :data="revenueData"
                                :options="revenueOptions"
                                class="!h-[160px]"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-span-12 flex flex-col xl:col-span-4">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">Por estado</div>
                </div>
                <div class="box box--stacked mt-3.5 flex flex-1 flex-col p-5">
                    <template v-if="byStatus.length">
                        <div class="relative mx-auto w-full max-w-[210px]">
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
                                    <div class="text-lg font-medium">
                                        {{ kpis.total }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        reservas
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-5 space-y-2.5">
                            <div
                                v-for="s in byStatus"
                                :key="s.status"
                                class="flex items-center text-sm"
                            >
                                <span
                                    class="mr-2 h-2 w-2 rounded-full"
                                    :style="{
                                        backgroundColor:
                                            statusHex[s.status] ?? '#94a3b8',
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
                                    class="ml-2 w-12 text-right text-xs text-slate-400"
                                >
                                    {{
                                        kpis.total
                                            ? Math.round(
                                                  (s.count / kpis.total) * 100,
                                              )
                                            : 0
                                    }}%
                                </span>
                            </div>
                        </div>
                    </template>
                    <div
                        v-else
                        class="flex flex-1 flex-col items-center justify-center gap-2 py-10 text-slate-400"
                    >
                        <Lucide icon="ChartPie" class="h-8 w-8" />
                        <p class="text-sm">Sin reservas en el periodo.</p>
                    </div>
                </div>
            </div>

            <!-- Desgloses -->
            <div class="col-span-12 flex flex-col xl:col-span-7">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">
                        Por tipo de habitación
                    </div>
                </div>
                <div
                    class="box box--stacked mt-3.5 flex-1 overflow-auto p-5 lg:overflow-visible"
                >
                    <Table v-if="byRoomType.length">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Tipo</Table.Th>
                                <Table.Th class="text-right">Reservas</Table.Th>
                                <Table.Th class="text-right"
                                    >Canc. / No-show</Table.Th
                                >
                                <Table.Th class="text-right"
                                    >Ingresos reservados</Table.Th
                                >
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="row in byRoomType" :key="row.name">
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
                                <Table.Td class="text-right font-medium">{{
                                    money(row.revenue)
                                }}</Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div
                        v-else
                        class="py-10 text-center text-sm text-slate-500"
                    >
                        Sin datos en el periodo.
                    </div>
                </div>
            </div>

            <div class="col-span-12 flex flex-col xl:col-span-5">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">Por canal</div>
                </div>
                <div class="box box--stacked mt-3.5 flex-1 p-5">
                    <div v-if="byChannel.length" class="space-y-4">
                        <div v-for="row in byChannel" :key="row.channel">
                            <div class="flex items-center text-sm">
                                <Lucide
                                    :icon="channelIcons[row.channel] ?? 'Tag'"
                                    class="mr-2 h-4 w-4 text-slate-400"
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
                        class="flex flex-col items-center gap-2 py-10 text-slate-400"
                    >
                        <Lucide icon="Radio" class="h-8 w-8" />
                        <p class="text-sm">Sin datos en el periodo.</p>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
