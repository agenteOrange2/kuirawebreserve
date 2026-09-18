<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/components/Base/Button';
import Chart from '@/components/Base/Chart';
import type { ChartConfiguration } from 'chart.js';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import RazeLayout from '@/layouts/RazeLayout.vue';
import PaymentsNav from './PaymentsNav.vue';

interface MethodRow {
    method: string;
    label: string;
    amount: number;
    amount_label: string;
    count: number;
}

const props = defineProps<{
    today: {
        net: number;
        collected: number;
        refunds: number;
        guarantees: number;
        guarantees_count: number;
        payments_count: number;
        net_label: string;
        collected_label: string;
        lodging_label: string;
        pos_label: string;
        refunds_label: string;
        guarantees_label: string;
        by_method: MethodRow[];
    };
    attention: {
        queue: { count: number; waiting_label: string | null; stale: boolean };
        overdue: { count: number; total_label: string };
        links: { count: number; expiring: number };
        settlements: { count: number };
        shift: { open: number; since_label: string | null; stale: boolean };
        cuts: {
            today: number;
            last_label: string | null;
            off: boolean;
            off_label: string | null;
        };
    };
    metrics: {
        series: { date: string; label: string; total: number }[];
        by_method: MethodRow[];
        range_label: string;
        total: number;
        total_label: string;
        best_label: string | null;
        daily_average_label: string;
    };
    canManage: boolean;
    canCashCuts: boolean;
    canSettings: boolean;
}>();

const plural = (n: number, one: string, many: string) =>
    `${n} ${n === 1 ? one : many}`;

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const sectionLabel =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';

/** Lo que entró hoy, para la franja de arriba. */
const todayTiles = computed(() => [
    {
        key: 'net',
        icon: 'Banknote' as Icon,
        tone: 'border-success/10 bg-success/10 text-success',
        value: props.today.net_label,
        label: 'Entró hoy',
        detail: props.today.refunds
            ? `Bruto ${props.today.collected_label} · devuelto ${props.today.refunds_label}`
            : plural(props.today.payments_count, 'abono', 'abonos'),
    },
    {
        key: 'lodging',
        icon: 'BedDouble' as Icon,
        tone: 'border-primary/10 bg-primary/10 text-primary',
        value: props.today.lodging_label,
        label: 'Hospedaje',
        detail: 'Abonos de reservas y estancias',
    },
    {
        key: 'pos',
        icon: 'ShoppingCart' as Icon,
        tone: 'border-info/10 bg-info/10 text-info',
        value: props.today.pos_label,
        label: 'Consumos y punto de venta',
        detail: 'Mostrador y lo liquidado en folio',
    },
    {
        key: 'guarantee',
        icon: 'ShieldCheck' as Icon,
        tone: 'border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400',
        value: props.today.guarantees_label,
        label: 'Fianzas en garantía',
        detail: 'No es ingreso: se devuelve al salir',
    },
]);

interface AreaCard {
    key: string;
    title: string;
    icon: Icon;
    tone: string;
    href: string;
    count: string;
    unit: string;
    detail: string;
    alert: string | null;
    show: boolean;
}

/** Cada tarjeta es un trabajo pendiente, con lo atorado marcado aparte. */
const cards = computed<AreaCard[]>(() =>
    [
        {
            key: 'verify',
            title: 'Por verificar',
            icon: 'Landmark' as Icon,
            tone: 'border-pending/10 bg-pending/10 text-pending',
            href: route('tenant.payments.verify'),
            count: String(props.attention.queue.count),
            unit:
                props.attention.queue.count === 1
                    ? 'transferencia'
                    : 'transferencias',
            detail: props.attention.queue.waiting_label
                ? `La más vieja lleva ${props.attention.queue.waiting_label}`
                : 'Nada esperando',
            alert: props.attention.queue.stale
                ? 'Alguien lleva horas sin su confirmación'
                : null,
            show: props.canManage,
        },
        {
            key: 'collect',
            title: 'Por cobrar',
            icon: 'TriangleAlert' as Icon,
            tone: 'border-warning/10 bg-warning/10 text-warning',
            href: route('tenant.payments.collect'),
            count: props.attention.overdue.total_label,
            unit: 'vencido',
            detail: `${plural(props.attention.overdue.count, 'reserva', 'reservas')} · ${plural(props.attention.links.count, 'link vivo', 'links vivos')}`,
            alert: props.attention.links.expiring
                ? `${plural(props.attention.links.expiring, 'link vence', 'links vencen')} hoy`
                : null,
            show: true,
        },
        {
            key: 'cashcuts',
            title: 'Cortes de caja',
            icon: 'Calculator' as Icon,
            tone: 'border-info/10 bg-info/10 text-info',
            href: props.canCashCuts ? route('tenant.cashcuts') : '',
            count: String(props.attention.cuts.today),
            unit: props.attention.cuts.today === 1 ? 'corte hoy' : 'cortes hoy',
            detail: props.attention.cuts.last_label
                ? `Último hace ${props.attention.cuts.last_label}`
                : 'Sin cortes registrados',
            alert: props.attention.cuts.off
                ? `El último no cuadró por ${props.attention.cuts.off_label}`
                : null,
            show: props.canCashCuts,
        },
        {
            key: 'shifts',
            title: 'Turnos abiertos',
            icon: 'Clock' as Icon,
            tone: 'border-primary/10 bg-primary/10 text-primary',
            href: props.canCashCuts ? route('tenant.shifts') : '',
            count: String(props.attention.shift.open),
            unit: props.attention.shift.open === 1 ? 'turno' : 'turnos',
            detail: props.attention.shift.since_label
                ? `El más viejo abrió hace ${props.attention.shift.since_label}`
                : 'Ningún turno abierto',
            alert: props.attention.shift.stale
                ? 'Lleva más de 12 horas: quizá nadie lo cerró'
                : null,
            show: props.canCashCuts,
        },
    ].filter((card) => card.show),
);

// Paleta del theme, la misma de los reportes para que un método sea del
// mismo color en todo el panel.
const methodHex: Record<string, string> = {
    cash: '#0d9488',
    card: '#03045e',
    transfer: '#ca8a04',
    online: '#1e293b',
};

const money = (value: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value) || 0);

const seriesData = computed(() => ({
    labels: props.metrics.series.map((d) => d.label),
    datasets: [
        {
            label: 'Entró',
            data: props.metrics.series.map((d) => d.total),
            backgroundColor: 'rgba(13,148,136,0.75)',
            borderRadius: 4,
        },
    ],
}));

const seriesOptions: ChartConfiguration<'bar'>['options'] = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx) => money(Number(ctx.parsed.y)),
            },
        },
    },
    scales: {
        x: { grid: { display: false }, ticks: { font: { size: 10 } } },
        y: {
            beginAtZero: true,
            ticks: {
                font: { size: 10 },
                callback: (value: number | string) =>
                    '$' +
                    new Intl.NumberFormat('es-MX', {
                        notation: 'compact',
                    }).format(Number(value)),
            },
        },
    },
};

const mixData = computed(() => ({
    labels: props.metrics.by_method.map((m) => m.label),
    datasets: [
        {
            data: props.metrics.by_method.map((m) => m.amount),
            backgroundColor: props.metrics.by_method.map(
                (m) => methodHex[m.method] ?? '#94a3b8',
            ),
            borderWidth: 0,
            hoverOffset: 4,
        },
    ],
}));

const mixOptions: ChartConfiguration<'doughnut'>['options'] = {
    cutout: '72%',
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx) => `${ctx.label}: ${money(Number(ctx.parsed))}`,
            },
        },
    },
};

const mixTotal = computed(() =>
    props.metrics.by_method.reduce((sum, m) => sum + m.amount, 0),
);
</script>

<template>
    <RazeLayout title="Caja y pagos">
        <div class="mt-2">
            <!-- Encabezado -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Wallet" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Caja y pagos</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cómo va el día y qué falta por resolver
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Button
                        as="a"
                        :href="route('tenant.online-payments')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide icon="Landmark" class="mr-1.5 h-3.5 w-3.5" />
                        Cobros en línea
                    </Button>
                    <Button
                        as="a"
                        :href="route('tenant.reservations.reports')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide icon="ChartColumn" class="mr-1.5 h-3.5 w-3.5" />
                        Reportes
                    </Button>
                </div>
            </div>

            <PaymentsNav
                current="hub"
                :can-manage="canManage"
                :can-cash-cuts="canCashCuts"
                :badges="{
                    verify: attention.queue.count,
                    collect: attention.overdue.count,
                }"
            />

            <!-- Lo que entró hoy -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">Hoy en caja</span>
                <span class="text-[11px] text-slate-400"
                    >Con la contabilidad de los cortes</span
                >
            </div>
            <div class="mt-2 grid auto-rows-fr grid-cols-12 gap-4">
                <div
                    v-for="tile in todayTiles"
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

            <!-- Métricas: la quincena en barras y la mezcla en dona -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">Últimos 14 días</span>
                <span class="text-[11px] text-slate-400">{{
                    metrics.range_label
                }}</span>
            </div>
            <div class="mt-2 grid grid-cols-12 items-stretch gap-4">
                <div class="col-span-12 flex flex-col xl:col-span-8">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-success/10 bg-success/10 text-success',
                                ]"
                            >
                                <Lucide icon="ChartColumn" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    {{ metrics.total_label }}
                                </div>
                                <div class="text-xs text-slate-500">
                                    Entró en la quincena
                                </div>
                            </div>
                            <div class="ml-auto hidden text-right sm:block">
                                <div class="text-sm font-medium">
                                    {{ metrics.daily_average_label }}
                                </div>
                                <div class="text-[11px] text-slate-400">
                                    Promedio por día
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col px-4 py-3">
                            <div class="h-[220px]">
                                <Chart
                                    type="bar"
                                    :data="seriesData"
                                    :options="seriesOptions"
                                    class="!h-[220px]"
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
                                    'border-primary/10 bg-primary/10 text-primary',
                                ]"
                            >
                                <Lucide icon="ChartPie" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Cómo se cobró
                                </div>
                                <div class="text-xs text-slate-500">
                                    Por método, en la quincena
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col px-4 py-3">
                            <template v-if="metrics.by_method.length">
                                <div
                                    class="relative mx-auto w-full max-w-[170px]"
                                >
                                    <div class="h-[150px]">
                                        <Chart
                                            type="doughnut"
                                            :data="mixData"
                                            :options="mixOptions"
                                            class="!h-[150px]"
                                        />
                                    </div>
                                    <div
                                        class="absolute inset-0 flex items-center justify-center"
                                    >
                                        <div class="text-center">
                                            <div class="text-sm font-medium">
                                                {{ metrics.total_label }}
                                            </div>
                                            <div
                                                class="text-[11px] text-slate-500"
                                            >
                                                cobrado
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3 space-y-2">
                                    <div
                                        v-for="row in metrics.by_method"
                                        :key="row.method"
                                        class="flex items-center text-xs"
                                    >
                                        <span
                                            class="mr-2 h-2 w-2 rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    methodHex[row.method] ??
                                                    '#94a3b8',
                                            }"
                                        />
                                        <span
                                            class="text-slate-600 dark:text-slate-300"
                                            >{{ row.label }}</span
                                        >
                                        <span class="ml-auto font-medium">{{
                                            row.amount_label
                                        }}</span>
                                        <span
                                            class="ml-2 w-10 text-right text-[11px] text-slate-400"
                                        >
                                            {{
                                                mixTotal
                                                    ? Math.round(
                                                          (row.amount /
                                                              mixTotal) *
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
                                    Sin cobros en la quincena.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Qué falta por resolver: cuatro tarjetas parejas -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">Qué falta por resolver</span>
            </div>
            <div class="mt-2 grid auto-rows-fr grid-cols-12 gap-4">
                <Link
                    v-for="card in cards"
                    :key="card.key"
                    :href="card.href"
                    class="box box--stacked col-span-12 flex flex-col gap-2 p-4 transition hover:shadow-md sm:col-span-6 xl:col-span-3"
                >
                    <div class="flex items-center gap-2.5">
                        <div :class="[sectionIcon, card.tone]">
                            <Lucide :icon="card.icon" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-sm font-medium">
                                {{ card.title }}
                            </div>
                            <div class="truncate text-[11px] text-slate-400">
                                {{ card.detail }}
                            </div>
                        </div>
                        <Lucide
                            icon="ChevronRight"
                            class="ml-auto h-4 w-4 shrink-0 text-slate-300"
                        />
                    </div>
                    <div class="mt-auto flex flex-wrap items-baseline gap-1.5">
                        <span class="text-sm font-medium">{{
                            card.count
                        }}</span>
                        <span class="text-xs text-slate-500">{{
                            card.unit
                        }}</span>
                    </div>
                    <span
                        v-if="card.alert"
                        class="inline-flex w-fit items-center gap-1 rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                    >
                        <Lucide icon="TriangleAlert" class="h-3 w-3" />
                        {{ card.alert }}
                    </span>
                </Link>
            </div>

            <!-- Lo demás del dinero, a un clic -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">Lo demás del dinero</span>
            </div>
            <div class="mt-2 grid auto-rows-fr grid-cols-12 gap-4">
                <Link
                    :href="route('tenant.reservations.settlements')"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 transition hover:shadow-md xl:col-span-3"
                >
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
                            {{ attention.settlements.count }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Cuentas por cerrar
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            Estancias con saldo suelto
                        </div>
                    </div>
                </Link>
                <Link
                    :href="route('tenant.online-payments')"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 transition hover:shadow-md xl:col-span-3"
                >
                    <div
                        :class="[
                            sectionIcon,
                            'border-info/10 bg-info/10 text-info',
                        ]"
                    >
                        <Lucide icon="Globe" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">Cobros en línea</div>
                        <div class="truncate text-xs text-slate-500">
                            Conciliación de pasarelas
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            Comisiones y depósitos
                        </div>
                    </div>
                </Link>
                <Link
                    v-if="canSettings"
                    :href="route('tenant.payment-methods')"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 transition hover:shadow-md xl:col-span-3"
                >
                    <div
                        :class="[
                            sectionIcon,
                            'border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400',
                        ]"
                    >
                        <Lucide icon="Settings" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">Métodos de pago</div>
                        <div class="truncate text-xs text-slate-500">
                            Cuentas, pasarelas y plazos
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            Lo que se le ofrece al huésped
                        </div>
                    </div>
                </Link>
                <Link
                    :href="route('tenant.reservations.reports')"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 transition hover:shadow-md xl:col-span-3"
                >
                    <div
                        :class="[
                            sectionIcon,
                            'border-primary/10 bg-primary/10 text-primary',
                        ]"
                    >
                        <Lucide icon="ChartColumn" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">Reportes</div>
                        <div class="truncate text-xs text-slate-500">
                            Vendido, cobrado y saldo
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            Por semana, mes o rango
                        </div>
                    </div>
                </Link>
            </div>
        </div>
    </RazeLayout>
</template>
