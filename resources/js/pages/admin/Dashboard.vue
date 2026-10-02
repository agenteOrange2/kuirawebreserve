<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import type { ChartData, ChartOptions } from 'chart.js';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Button from '@/components/Base/Button';
import Chart from '@/components/Base/Chart';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface AiTenantRow {
    id: string;
    name: string;
    plan_label: string;
    suspended: boolean;
    enabled: boolean;
    provider_label: string | null;
    used: number;
    limit: number | null;
    prompt_tokens: number;
    completion_tokens: number;
}

interface ActivityDay {
    date: string;
    label: string;
    long: string;
    replies: number;
    by_tenant: { name: string; replies: number }[];
}

interface PlanSlice {
    key: string;
    label: string;
    public: boolean;
    count: number;
    mrr: number;
    tenants: { id: string; name: string; suspended: boolean; price: number }[];
}

const props = defineProps<{
    stats: {
        tenants: number;
        active: number;
        suspended: number;
        new_month: number;
        mrr: number;
        ai_replies_month: number;
        ai_replies_prev: number;
        ai_tokens_month: number;
        ai_keys_active: number;
        ai_keys_total: number;
        prospects_new: number;
    };
    monthLabel: string;
    activity: ActivityDay[];
    plans: PlanSlice[];
    growth: {
        month: string;
        label: string;
        tenants: number;
        prospects: number;
    }[];
    aiTenants: AiTenantRow[];
    alerts: {
        id: number;
        severity: 'danger' | 'warning';
        title: string;
        tenant: string | null;
        url: string | null;
        ago: string | null;
    }[];
    alertCounts: { danger: number; warning: number };
    recentTenants: {
        id: string;
        name: string;
        plan_label: string;
        suspended: boolean;
        domain: string | null;
        price: number;
        created_ago: string | null;
        created_at: string | null;
    }[];
}>();

const money = (n: number) => `$${n.toLocaleString('es-MX')}`;
const num = (n: number) => n.toLocaleString('es-MX');
// Formato compacto para tokens (la base del costo): 12400 → "12.4k".
function compact(n: number): string {
    if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
    if (n >= 1_000) return `${(n / 1_000).toFixed(1)}k`;
    return `${n}`;
}

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';

// ── Modo oscuro: las gráficas pintan en canvas y no leen clases ──
const isDark = ref(false);
let observer: MutationObserver | undefined;
onMounted(() => {
    const read = () =>
        (isDark.value = document.documentElement.classList.contains('dark'));
    read();
    observer = new MutationObserver(read);
    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
});
onBeforeUnmount(() => observer?.disconnect());

// Paleta categórica validada (dataviz: banda de luminosidad, separación
// para daltonismo y contraste), con su paso propio para modo oscuro. El
// orden es fijo: el color sigue al plan, no a su lugar en el ranking.
const SERIES_LIGHT = ['#3451c7', '#c2410c', '#0891b2', '#9333ea', '#db2777'];
const SERIES_DARK = ['#5b78e6', '#c2410c', '#0891b2', '#9333ea', '#db2777'];
const OTHER = '#94a3b8';
const series = (i: number) =>
    i < SERIES_LIGHT.length
        ? (isDark.value ? SERIES_DARK : SERIES_LIGHT)[i]
        : OTHER;
const surface = computed(() => (isDark.value ? '#28334e' : '#ffffff'));
const ink = computed(() => (isDark.value ? '#94a3b8' : '#64748b'));
const grid = computed(() => (isDark.value ? '#303d5d' : '#eef2f6'));

// ── Cifras ──
const repliesDelta = computed(() => {
    const prev = props.stats.ai_replies_prev;
    if (!prev) return null;
    return Math.round(((props.stats.ai_replies_month - prev) / prev) * 100);
});

// ── Actividad del bot: barras por día ──
const activityTotal = computed(() =>
    props.activity.reduce((sum, d) => sum + d.replies, 0),
);
const activityPeak = computed(() =>
    props.activity.reduce(
        (best, d) => (d.replies > best.replies ? d : best),
        props.activity[0],
    ),
);

const activityData = computed<ChartData<'bar'>>(() => ({
    labels: props.activity.map((d) => d.label),
    datasets: [
        {
            label: 'Respuestas',
            data: props.activity.map((d) => d.replies),
            backgroundColor: series(0),
            hoverBackgroundColor: isDark.value ? '#8ea2f0' : '#24399a',
            borderRadius: { topLeft: 4, topRight: 4 },
            borderSkipped: 'bottom',
            maxBarThickness: 18,
        },
    ],
}));

const baseScales = computed(() => ({
    x: {
        grid: { display: false },
        border: { display: false },
        ticks: {
            color: ink.value,
            font: { size: 10 },
            maxRotation: 0,
            autoSkip: true,
            maxTicksLimit: 10,
        },
    },
    y: {
        beginAtZero: true,
        border: { display: false },
        grid: { color: grid.value, lineWidth: 1 },
        ticks: {
            color: ink.value,
            font: { size: 10 },
            precision: 0,
            maxTicksLimit: 5,
            callback: (v: string | number) => compact(Number(v)),
        },
    },
}));

const tooltip = computed(() => ({
    backgroundColor: isDark.value ? '#0f172a' : '#1e293b',
    padding: 10,
    cornerRadius: 8,
    titleFont: { size: 11, weight: 'bold' as const },
    bodyFont: { size: 11 },
    displayColors: true,
    boxPadding: 4,
}));

const activityOptions = computed<ChartOptions<'bar'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 300 },
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: {
            ...tooltip.value,
            displayColors: false,
            callbacks: {
                title: (items) =>
                    props.activity[items[0].dataIndex]?.long ?? '',
                label: (item) => `${num(Number(item.raw))} respuestas`,
                footer: (items) =>
                    props.activity[items[0].dataIndex]?.replies
                        ? 'Toca para ver por hotel'
                        : '',
            },
        },
    },
    scales: baseScales.value,
    onClick: (_e, elements, chart) => {
        // Con interacción por índice basta tocar la columna, no la barra.
        const active = elements[0] ?? chart.getActiveElements()[0];
        if (active) openDay(active.index);
    },
    onHover: (e, elements) => {
        const target = e.native?.target as HTMLElement | undefined;
        if (target) target.style.cursor = elements.length ? 'pointer' : '';
    },
}));

const dayOpen = ref<ActivityDay | null>(null);
function openDay(index: number) {
    const day = props.activity[index];
    if (day?.replies) dayOpen.value = day;
}

// ── Ingreso por plan: dona ──
const mrrTotal = computed(() => props.plans.reduce((s, p) => s + p.mrr, 0));
const planShare = (p: PlanSlice) =>
    mrrTotal.value ? Math.round((p.mrr / mrrTotal.value) * 100) : 0;

const donutData = computed<ChartData<'doughnut'>>(() => ({
    labels: props.plans.map((p) => p.label),
    datasets: [
        {
            data: props.plans.map((p) => p.mrr),
            backgroundColor: props.plans.map((_, i) => series(i)),
            // El espacio entre rebanadas es el color de la tarjeta, no un borde.
            borderColor: surface.value,
            borderWidth: 2,
            hoverOffset: 4,
        },
    ],
}));

const donutOptions = computed<ChartOptions<'doughnut'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    cutout: '72%',
    animation: { duration: 300 },
    plugins: {
        legend: { display: false },
        tooltip: {
            ...tooltip.value,
            callbacks: {
                label: (item) => {
                    const p = props.plans[item.dataIndex];
                    return ` ${money(p.mrr)} al mes · ${p.count} ${p.count === 1 ? 'hotel' : 'hoteles'}`;
                },
            },
        },
    },
    onClick: (_e, elements) => {
        if (elements[0]) planOpen.value = props.plans[elements[0].index];
    },
    onHover: (e, elements) => {
        const target = e.native?.target as HTMLElement | undefined;
        if (target) target.style.cursor = elements.length ? 'pointer' : '';
    },
}));

const planOpen = ref<PlanSlice | null>(null);
const planIndex = (p: PlanSlice | null) =>
    p ? props.plans.findIndex((x) => x.key === p.key) : -1;

// ── Crecimiento: barras agrupadas, una misma unidad (conteo) ──
const growthTotals = computed(() => ({
    tenants: props.growth.reduce((s, m) => s + m.tenants, 0),
    prospects: props.growth.reduce((s, m) => s + m.prospects, 0),
}));

const growthData = computed<ChartData<'bar'>>(() => ({
    labels: props.growth.map((m) => m.label),
    datasets: [
        {
            label: 'Hoteles nuevos',
            data: props.growth.map((m) => m.tenants),
            backgroundColor: series(0),
            borderRadius: { topLeft: 4, topRight: 4 },
            borderSkipped: 'bottom',
            maxBarThickness: 16,
        },
        {
            label: 'Prospectos',
            data: props.growth.map((m) => m.prospects),
            backgroundColor: series(2),
            borderRadius: { topLeft: 4, topRight: 4 },
            borderSkipped: 'bottom',
            maxBarThickness: 16,
        },
    ],
}));

const growthOptions = computed<ChartOptions<'bar'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 300 },
    interaction: { mode: 'index', intersect: false },
    datasets: { bar: { categoryPercentage: 0.6, barPercentage: 0.9 } },
    plugins: {
        legend: { display: false },
        tooltip: tooltip.value,
    },
    scales: baseScales.value,
}));

// ── Consumo de IA por hotel ──
const quotaPercent = (row: AiTenantRow) =>
    row.limit ? Math.min(100, Math.round((row.used / row.limit) * 100)) : null;
const quotaTone = (pct: number | null) =>
    pct === null
        ? 'bg-primary/60'
        : pct >= 100
          ? 'bg-danger'
          : pct >= 80
            ? 'bg-warning'
            : 'bg-primary';
const maxUsed = computed(() =>
    Math.max(...props.aiTenants.map((r) => r.used), 1),
);
// Con cuota la barra mide contra la cuota; sin cuota, contra el que más usa.
const barWidth = (row: AiTenantRow) =>
    row.limit
        ? Math.min(100, (row.used / row.limit) * 100)
        : (row.used / maxUsed.value) * 100;

const aiOpen = ref<AiTenantRow | null>(null);

function go(url: string | null) {
    if (url) router.visit(url);
}
</script>

<template>
    <RazeLayout title="Panel de plataforma">
        <div class="mt-2">
            <!-- Encabezado -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="LayoutDashboard" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Panel de plataforma
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cómo va el negocio en
                            <span class="capitalize">{{ monthLabel }}</span
                            >: hoteles, ingreso y consumo de IA.
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Link
                        :href="route('admin.alerts')"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-[0.5rem] border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-600 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600 dark:text-slate-300"
                    >
                        <Lucide icon="Bell" class="h-3.5 w-3.5" />
                        Notificaciones
                        <span
                            v-if="alertCounts.danger + alertCounts.warning"
                            class="rounded-full px-1.5 text-[11px] font-medium text-white"
                            :class="
                                alertCounts.danger ? 'bg-danger' : 'bg-warning'
                            "
                            >{{
                                alertCounts.danger + alertCounts.warning
                            }}</span
                        >
                    </Link>
                    <Button
                        :as="Link"
                        :href="route('admin.tenants.index')"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                    >
                        <Lucide icon="Building2" class="mr-1.5 h-3.5 w-3.5" />
                        Hoteles
                    </Button>
                </div>
            </div>

            <!-- Lo que pide atención hoy -->
            <div v-if="alerts.length" class="box box--stacked mt-4">
                <div :class="cardHeader">
                    <div
                        :class="[
                            sectionIcon,
                            alertCounts.danger
                                ? 'border-danger/10 bg-danger/10 text-danger'
                                : 'border-warning/10 bg-warning/10 text-warning',
                        ]"
                    >
                        <Lucide icon="TriangleAlert" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-medium">Pide atención</h2>
                        <p class="text-xs text-slate-500">
                            <template v-if="alertCounts.danger"
                                >{{ alertCounts.danger }}
                                {{
                                    alertCounts.danger === 1
                                        ? 'urgente'
                                        : 'urgentes'
                                }}<template v-if="alertCounts.warning">
                                    ·
                                </template></template
                            ><template v-if="alertCounts.warning"
                                >{{ alertCounts.warning }} por atender</template
                            >
                        </p>
                    </div>
                    <Link
                        :href="route('admin.alerts')"
                        class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-[0.5rem] border border-slate-200 px-3 text-xs font-medium text-slate-600 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:text-slate-300"
                    >
                        Ver todas
                        <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
                    </Link>
                </div>
                <div
                    class="grid divide-y divide-slate-200/60 md:grid-cols-2 md:divide-y-0 dark:divide-darkmode-400"
                >
                    <button
                        v-for="(a, i) in alerts"
                        :key="a.id"
                        type="button"
                        class="flex items-center gap-2.5 px-4 py-2.5 text-left transition hover:bg-slate-50/70 dark:hover:bg-darkmode-400/30"
                        :class="{
                            'md:border-t md:border-slate-200/60 md:dark:border-darkmode-400':
                                i >= 2,
                            'md:border-l md:border-slate-200/60 md:dark:border-darkmode-400':
                                i % 2 === 1,
                        }"
                        @click="go(a.url)"
                    >
                        <Lucide
                            :icon="
                                a.severity === 'danger'
                                    ? 'OctagonAlert'
                                    : 'TriangleAlert'
                            "
                            class="h-4 w-4 shrink-0"
                            :class="
                                a.severity === 'danger'
                                    ? 'text-danger'
                                    : 'text-warning'
                            "
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-xs font-medium">{{
                                a.title
                            }}</span>
                            <span
                                class="block truncate text-[11px] text-slate-500"
                                >{{ a.tenant ?? 'Plataforma' }} ·
                                {{ a.ago }}</span
                            >
                        </span>
                        <Lucide
                            icon="ChevronRight"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                    </button>
                </div>
            </div>

            <!-- Cifras -->
            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                <Link
                    :href="route('admin.tenants.index')"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 transition hover:ring-1 hover:ring-primary/20 xl:col-span-3"
                >
                    <div
                        :class="[
                            sectionIcon,
                            'border-primary/10 bg-primary/10 text-primary',
                        ]"
                    >
                        <Lucide icon="Building2" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.active }} de {{ stats.tenants }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Hoteles activos
                        </div>
                        <div
                            class="truncate text-[11px]"
                            :class="
                                stats.suspended
                                    ? 'text-danger'
                                    : 'text-slate-400'
                            "
                        >
                            <template v-if="stats.suspended"
                                >{{ stats.suspended }} suspendido{{
                                    stats.suspended === 1 ? '' : 's'
                                }}</template
                            >
                            <template v-else
                                >{{ stats.new_month }} alta{{
                                    stats.new_month === 1 ? '' : 's'
                                }}
                                este mes</template
                            >
                        </div>
                    </div>
                </Link>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
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
                            {{ money(stats.mrr) }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Ingreso mensual
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            Plan + servicios, hoteles activos
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        :class="[
                            sectionIcon,
                            'border-info/10 bg-info/10 text-info',
                        ]"
                    >
                        <Lucide icon="MessagesSquare" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-baseline gap-1.5">
                            <span class="text-sm font-medium">{{
                                num(stats.ai_replies_month)
                            }}</span>
                            <span
                                v-if="repliesDelta !== null"
                                class="inline-flex items-center text-[11px] font-medium"
                                :class="
                                    repliesDelta >= 0
                                        ? 'text-success'
                                        : 'text-slate-500'
                                "
                                title="Contra los mismos días del mes pasado"
                            >
                                <Lucide
                                    :icon="
                                        repliesDelta >= 0
                                            ? 'ArrowUpRight'
                                            : 'ArrowDownRight'
                                    "
                                    class="h-3 w-3"
                                />{{ Math.abs(repliesDelta) }} %
                            </span>
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Respuestas IA del mes
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            {{ compact(stats.ai_tokens_month) }} tokens
                        </div>
                    </div>
                </div>
                <Link
                    :href="route('admin.prospects')"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 transition hover:ring-1 hover:ring-primary/20 xl:col-span-3"
                >
                    <div
                        :class="[
                            sectionIcon,
                            'border-pending/10 bg-pending/10 text-pending',
                        ]"
                    >
                        <Lucide icon="ContactRound" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.prospects_new }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Prospectos por contactar
                        </div>
                        <div
                            class="truncate text-[11px]"
                            :class="
                                stats.ai_keys_active
                                    ? 'text-slate-400'
                                    : 'font-medium text-danger'
                            "
                        >
                            {{ stats.ai_keys_active }} de
                            {{ stats.ai_keys_total }} llaves de IA activas
                        </div>
                    </div>
                </Link>
            </div>

            <div class="mt-4 grid grid-cols-12 items-stretch gap-5">
                <!-- Actividad del bot: barras por día -->
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
                            <div class="min-w-0 flex-1">
                                <h2 class="text-sm font-medium">
                                    Respuestas del bot por día
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Últimos {{ activity.length }} días · toca un
                                    día para verlo por hotel
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="text-sm font-medium">
                                    {{ num(activityTotal) }}
                                </div>
                                <div class="text-[11px] text-slate-400">
                                    en total
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col px-4 py-3">
                            <Chart
                                v-if="activityTotal"
                                type="bar"
                                :data="activityData"
                                :options="activityOptions"
                                class="!h-[220px] w-full"
                            />
                            <div
                                v-else
                                class="flex h-[220px] flex-col items-center justify-center gap-2 rounded-[0.6rem] border border-dashed border-slate-300/70 text-center dark:border-darkmode-400"
                            >
                                <Lucide
                                    icon="ChartColumn"
                                    class="h-5 w-5 text-slate-300"
                                />
                                <p class="text-xs text-slate-500">
                                    El bot no ha contestado nada en estos días.
                                </p>
                            </div>
                            <p
                                v-if="activityTotal && activityPeak"
                                class="mt-2 text-[11px] text-slate-400"
                            >
                                El día de más trabajo fue el
                                {{ activityPeak.long }}, con
                                {{ num(activityPeak.replies) }} respuestas.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Ingreso por plan: dona -->
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
                                <h2 class="text-sm font-medium">
                                    Ingreso por plan
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Lo que paga al mes cada grupo de hoteles
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col px-4 py-3">
                            <template v-if="mrrTotal">
                                <div
                                    class="relative mx-auto h-[170px] w-[170px]"
                                >
                                    <Chart
                                        type="doughnut"
                                        :data="donutData"
                                        :options="donutOptions"
                                        class="!h-[170px] !w-[170px]"
                                    />
                                    <div
                                        class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center"
                                    >
                                        <div class="text-sm font-medium">
                                            {{ money(mrrTotal) }}
                                        </div>
                                        <div class="text-[11px] text-slate-500">
                                            al mes
                                        </div>
                                    </div>
                                </div>
                                <!-- Leyenda: identidad sin depender del color,
                                     con las cifras; abre el detalle -->
                                <div
                                    class="mt-3 divide-y divide-dashed divide-slate-200/70 dark:divide-darkmode-400"
                                >
                                    <button
                                        v-for="(p, i) in plans"
                                        :key="p.key"
                                        type="button"
                                        class="flex w-full items-center gap-2 py-1.5 text-left text-xs transition hover:text-primary"
                                        @click="planOpen = p"
                                    >
                                        <span
                                            class="h-2.5 w-2.5 shrink-0 rounded-sm"
                                            :style="{
                                                backgroundColor: series(i),
                                            }"
                                        />
                                        <span class="min-w-0 flex-1 truncate">
                                            {{ p.label }}
                                            <span class="text-slate-400"
                                                >· {{ p.count }}
                                                {{
                                                    p.count === 1
                                                        ? 'hotel'
                                                        : 'hoteles'
                                                }}</span
                                            >
                                        </span>
                                        <span
                                            class="font-medium tabular-nums"
                                            >{{ money(p.mrr) }}</span
                                        >
                                        <span
                                            class="w-9 text-right text-[11px] text-slate-400 tabular-nums"
                                            >{{ planShare(p) }} %</span
                                        >
                                    </button>
                                </div>
                            </template>
                            <div
                                v-else
                                class="flex flex-1 flex-col items-center justify-center gap-2 py-8 text-center"
                            >
                                <Lucide
                                    icon="ChartPie"
                                    class="h-5 w-5 text-slate-300"
                                />
                                <p class="text-xs text-slate-500">
                                    Todavía no hay hoteles activos pagando.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Crecimiento: barras agrupadas -->
                <div class="col-span-12 flex flex-col xl:col-span-5">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-info/10 bg-info/10 text-info',
                                ]"
                            >
                                <Lucide icon="TrendingUp" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">Crecimiento</h2>
                                <p class="text-xs text-slate-500">
                                    Altas y registros por mes, últimos
                                    {{ growth.length }}
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col px-4 py-3">
                            <div
                                class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-slate-500"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <span
                                        class="h-2.5 w-2.5 rounded-sm"
                                        :style="{ backgroundColor: series(0) }"
                                    />
                                    Hoteles nuevos ·
                                    <span
                                        class="font-medium text-slate-700 dark:text-slate-300"
                                        >{{ growthTotals.tenants }}</span
                                    >
                                </span>
                                <span class="inline-flex items-center gap-1.5">
                                    <span
                                        class="h-2.5 w-2.5 rounded-sm"
                                        :style="{ backgroundColor: series(2) }"
                                    />
                                    Prospectos registrados ·
                                    <span
                                        class="font-medium text-slate-700 dark:text-slate-300"
                                        >{{ growthTotals.prospects }}</span
                                    >
                                </span>
                            </div>
                            <Chart
                                v-if="
                                    growthTotals.tenants +
                                    growthTotals.prospects
                                "
                                type="bar"
                                :data="growthData"
                                :options="growthOptions"
                                class="!h-[200px] w-full"
                            />
                            <div
                                v-else
                                class="flex h-[200px] items-center justify-center rounded-[0.6rem] border border-dashed border-slate-300/70 text-xs text-slate-500 dark:border-darkmode-400"
                            >
                                Sin altas ni registros en estos meses.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Consumo de IA por hotel: barras horizontales -->
                <div class="col-span-12 flex flex-col xl:col-span-7">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-warning/10 bg-warning/10 text-warning',
                                ]"
                            >
                                <Lucide icon="Bot" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h2 class="text-sm font-medium">
                                    Consumo de IA por hotel
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Respuestas del mes contra su cuota
                                </p>
                            </div>
                            <Link
                                :href="route('admin.ai')"
                                class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-[0.5rem] border border-slate-200 px-3 text-xs font-medium text-slate-600 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:text-slate-300"
                            >
                                Agentes IA
                                <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
                            </Link>
                        </div>
                        <div
                            v-if="aiTenants.length"
                            class="flex-1 divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <button
                                v-for="row in aiTenants"
                                :key="row.id"
                                type="button"
                                class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition hover:bg-slate-50/70 dark:hover:bg-darkmode-400/30"
                                @click="aiOpen = row"
                            >
                                <div class="w-32 shrink-0 sm:w-40">
                                    <div class="truncate text-xs font-medium">
                                        {{ row.name }}
                                    </div>
                                    <div
                                        class="truncate text-[11px]"
                                        :class="
                                            !row.enabled || row.suspended
                                                ? 'text-slate-400'
                                                : 'text-slate-500'
                                        "
                                    >
                                        {{
                                            row.suspended
                                                ? 'Suspendido'
                                                : !row.enabled
                                                  ? 'Bot apagado'
                                                  : row.plan_label
                                        }}
                                    </div>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                                    >
                                        <div
                                            class="h-full rounded-full transition-all"
                                            :class="
                                                quotaTone(quotaPercent(row))
                                            "
                                            :style="{
                                                width: `${Math.max(barWidth(row), row.used ? 2 : 0)}%`,
                                            }"
                                        />
                                    </div>
                                </div>
                                <div
                                    class="w-28 shrink-0 text-right text-xs tabular-nums"
                                >
                                    <span class="font-medium">{{
                                        num(row.used)
                                    }}</span>
                                    <span class="text-slate-400">
                                        /
                                        {{
                                            row.limit
                                                ? num(row.limit)
                                                : 'sin tope'
                                        }}</span
                                    >
                                    <div
                                        v-if="quotaPercent(row) !== null"
                                        class="text-[11px]"
                                        :class="
                                            (quotaPercent(row) ?? 0) >= 80
                                                ? 'font-medium text-warning'
                                                : 'text-slate-400'
                                        "
                                    >
                                        {{ quotaPercent(row) }} % de su cuota
                                    </div>
                                </div>
                            </button>
                        </div>
                        <div
                            v-else
                            class="flex flex-1 items-center justify-center px-4 py-10 text-xs text-slate-500"
                        >
                            Ningún hotel con IA todavía.
                        </div>
                    </div>
                </div>

                <!-- Hoteles recientes -->
                <div class="col-span-12">
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-primary/10 bg-primary/10 text-primary',
                                ]"
                            >
                                <Lucide icon="Sparkles" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h2 class="text-sm font-medium">
                                    Hoteles recientes
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Los últimos que se dieron de alta
                                </p>
                            </div>
                            <Link
                                :href="route('admin.tenants.index')"
                                class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-[0.5rem] border border-slate-200 px-3 text-xs font-medium text-slate-600 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:text-slate-300"
                            >
                                Ver todos
                                <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
                            </Link>
                        </div>
                        <div
                            v-if="recentTenants.length"
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <Link
                                v-for="t in recentTenants"
                                :key="t.id"
                                :href="route('admin.tenants.show', t.id)"
                                class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5 transition hover:bg-slate-50/70 sm:px-5 dark:hover:bg-darkmode-400/30"
                            >
                                <div
                                    class="min-w-0 flex-1 basis-full sm:basis-auto"
                                >
                                    <div class="flex items-center gap-1.5">
                                        <span
                                            class="truncate text-sm font-medium"
                                            >{{ t.name }}</span
                                        >
                                        <span
                                            v-if="t.suspended"
                                            class="rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                            >Suspendido</span
                                        >
                                    </div>
                                    <div
                                        class="truncate text-xs text-slate-500"
                                    >
                                        {{ t.domain ?? t.id }}
                                    </div>
                                </div>
                                <span
                                    class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                    >{{ t.plan_label }}</span
                                >
                                <span
                                    class="w-24 text-right text-xs font-medium tabular-nums"
                                    >{{ money(t.price) }}/mes</span
                                >
                                <span
                                    class="ml-auto text-right text-[11px] text-slate-400 sm:ml-0 sm:w-28"
                                    :title="t.created_at ?? undefined"
                                    >Alta {{ t.created_ago }}</span
                                >
                            </Link>
                        </div>
                        <p
                            v-else
                            class="px-4 py-8 text-center text-xs text-slate-500"
                        >
                            Aún no hay hoteles.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detalle de un día de actividad -->
        <Dialog :open="dayOpen !== null" @close="dayOpen = null">
            <Dialog.Panel>
                <div
                    v-if="dayOpen"
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                >
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="ChartColumn" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2
                                class="text-base font-medium first-letter:uppercase"
                            >
                                {{ dayOpen.long }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ num(dayOpen.replies) }} respuestas del bot en
                                {{ dayOpen.by_tenant.length }}
                                {{
                                    dayOpen.by_tenant.length === 1
                                        ? 'hotel'
                                        : 'hoteles'
                                }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            title="Cerrar"
                            @click="dayOpen = null"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>
                    <div
                        class="min-h-0 flex-1 divide-y divide-slate-200/60 overflow-y-auto dark:divide-darkmode-400"
                    >
                        <div
                            v-for="row in dayOpen.by_tenant"
                            :key="row.name"
                            class="flex items-center gap-3 px-5 py-2.5"
                        >
                            <span
                                class="w-40 shrink-0 truncate text-xs font-medium"
                                >{{ row.name }}</span
                            >
                            <div
                                class="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                            >
                                <div
                                    class="h-full rounded-full bg-primary"
                                    :style="{
                                        width: `${(row.replies / dayOpen.replies) * 100}%`,
                                    }"
                                />
                            </div>
                            <span
                                class="w-16 shrink-0 text-right text-xs tabular-nums"
                                >{{ num(row.replies) }}</span
                            >
                        </div>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Hoteles de un plan -->
        <Dialog :open="planOpen !== null" @close="planOpen = null">
            <Dialog.Panel>
                <div
                    v-if="planOpen"
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                >
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <span
                            class="h-10 w-10 shrink-0 rounded-full border-[6px]"
                            :style="{
                                borderColor: series(planIndex(planOpen)),
                            }"
                        />
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-base font-medium">
                                Plan {{ planOpen.label }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ planOpen.count }}
                                {{ planOpen.count === 1 ? 'hotel' : 'hoteles' }}
                                · {{ money(planOpen.mrr) }} al mes ·
                                {{ planShare(planOpen) }} % del ingreso<template
                                    v-if="!planOpen.public"
                                >
                                    · plan privado</template
                                >
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            title="Cerrar"
                            @click="planOpen = null"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>
                    <div
                        class="min-h-0 flex-1 divide-y divide-slate-200/60 overflow-y-auto dark:divide-darkmode-400"
                    >
                        <Link
                            v-for="t in planOpen.tenants"
                            :key="t.id"
                            :href="route('admin.tenants.plan', t.id)"
                            class="flex items-center gap-3 px-5 py-2.5 transition hover:bg-slate-50/70 dark:hover:bg-darkmode-400/30"
                        >
                            <span
                                class="min-w-0 flex-1 truncate text-sm font-medium"
                                >{{ t.name }}</span
                            >
                            <span
                                v-if="t.suspended"
                                class="rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                >Suspendido, no paga</span
                            >
                            <span class="text-xs font-medium tabular-nums"
                                >{{ money(t.price) }}/mes</span
                            >
                            <Lucide
                                icon="ChevronRight"
                                class="h-3.5 w-3.5 text-slate-400"
                            />
                        </Link>
                    </div>
                    <div
                        class="flex justify-end border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Link
                            :href="
                                route('admin.tenants.index', {
                                    plan: planOpen.key,
                                })
                            "
                            class="inline-flex h-9 items-center gap-1.5 rounded-[0.5rem] border border-slate-200 px-4 text-xs font-medium text-slate-600 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:text-slate-300"
                        >
                            Ver en el listado
                            <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
                        </Link>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Consumo de IA de un hotel -->
        <Dialog :open="aiOpen !== null" @close="aiOpen = null">
            <Dialog.Panel>
                <div v-if="aiOpen" class="p-5">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                        >
                            <Lucide icon="Bot" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-base font-medium">
                                {{ aiOpen.name }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Plan {{ aiOpen.plan_label }} ·
                                {{
                                    aiOpen.enabled
                                        ? 'bot encendido'
                                        : 'bot apagado'
                                }}
                            </p>
                        </div>
                    </div>
                    <dl
                        class="mt-4 divide-y divide-dashed divide-slate-200/70 border-y border-dashed border-slate-200/70 text-xs dark:divide-darkmode-400 dark:border-darkmode-400"
                    >
                        <div class="flex justify-between py-2">
                            <dt class="text-slate-500">Respuestas del mes</dt>
                            <dd class="font-medium tabular-nums">
                                {{ num(aiOpen.used) }}
                                <span class="font-normal text-slate-400"
                                    >de
                                    {{
                                        aiOpen.limit
                                            ? num(aiOpen.limit)
                                            : 'sin tope'
                                    }}</span
                                >
                            </dd>
                        </div>
                        <div class="flex justify-between py-2">
                            <dt class="text-slate-500">Tokens de entrada</dt>
                            <dd class="font-medium tabular-nums">
                                {{ num(aiOpen.prompt_tokens) }}
                            </dd>
                        </div>
                        <div class="flex justify-between py-2">
                            <dt class="text-slate-500">Tokens de salida</dt>
                            <dd class="font-medium tabular-nums">
                                {{ num(aiOpen.completion_tokens) }}
                            </dd>
                        </div>
                        <div class="flex justify-between py-2">
                            <dt class="text-slate-500">
                                Promedio por respuesta
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{
                                    aiOpen.used
                                        ? num(
                                              Math.round(
                                                  (aiOpen.prompt_tokens +
                                                      aiOpen.completion_tokens) /
                                                      aiOpen.used,
                                              ),
                                          ) + ' tokens'
                                        : 'Sin respuestas'
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between py-2">
                            <dt class="text-slate-500">Proveedor</dt>
                            <dd class="font-medium">
                                {{
                                    aiOpen.provider_label ??
                                    'Cadena de la plataforma'
                                }}
                            </dd>
                        </div>
                    </dl>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            @click="aiOpen = null"
                            >Cerrar</Button
                        >
                        <Button
                            :as="Link"
                            :href="route('admin.tenants.assistant', aiOpen.id)"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs shadow-md shadow-primary/20"
                        >
                            <Lucide
                                icon="SlidersHorizontal"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Ajustar su asistente
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
