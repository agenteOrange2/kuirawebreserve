<script setup lang="ts">
import type {
    ActiveElement,
    ChartEvent,
    ChartOptions,
    TooltipItem,
} from 'chart.js';
import { computed } from 'vue';
import Chart from '@/components/Base/Chart';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';

export interface BarItem {
    key: string;
    label: string;
    count: number;
    /** Hex propio de la barra; si falta, va el `color` del panel. */
    color?: string;
}

/**
 * Barras de las pantallas de reservas. Verticales para una serie de días
 * ("cuántos llegan cada día") y horizontales para comparar categorías
 * ("de dónde vienen"). Como la dona, tocar una barra filtra la lista y
 * tocarla otra vez quita el filtro.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        subtitle: string;
        icon: Icon;
        iconTone?: string;
        bars: BarItem[];
        color: string;
        horizontal?: boolean;
        active?: string;
        selectable?: boolean;
        /** Cómo se dice una unidad en el tooltip: "llegada", "apartado". */
        unit?: [string, string];
        emptyText?: string;
        /** El canvas solo recibe clases: dos alturas fijas. */
        size?: 'md' | 'sm';
    }>(),
    {
        iconTone: 'border-primary/10 bg-primary/10 text-primary',
        horizontal: false,
        active: '',
        selectable: true,
        unit: () => ['reserva', 'reservas'],
        emptyText: 'Todavía no hay nada que graficar',
        size: 'md',
    },
);

const emit = defineEmits<{ select: [key: string] }>();

const hasData = computed(() => props.bars.some((b) => b.count > 0));

function pick(key: string) {
    if (!props.selectable) return;
    emit('select', props.active === key ? '' : key);
}

const data = computed(() => ({
    labels: props.bars.map((b) => b.label),
    datasets: [
        {
            data: props.bars.map((b) => b.count),
            backgroundColor: props.bars.map((b) => {
                const base = b.color ?? props.color;
                return !props.active || props.active === b.key
                    ? base
                    : `${base}40`;
            }),
            borderRadius: 4,
            maxBarThickness: props.horizontal ? 16 : 26,
        },
    ],
}));

const maxCount = computed(() => Math.max(0, ...props.bars.map((b) => b.count)));

// Con una sola llegada por día las barras salían a todo lo alto y parecía
// un día lleno: la escala arranca en 4 como mínimo.
const valueAxis = computed(() => ({
    beginAtZero: true,
    suggestedMax: Math.max(4, maxCount.value),
    border: { display: false },
    grid: { color: 'rgba(148,163,184,0.18)' },
    ticks: { font: { size: 10 }, precision: 0, maxTicksLimit: 5 },
}));
const labelAxis = {
    grid: { display: false },
    border: { display: false },
    ticks: { font: { size: 10 }, maxRotation: 0, autoSkip: true },
};

const options = computed<ChartOptions<'bar'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: props.horizontal ? 'y' : 'x',
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx: TooltipItem<'bar'>) => {
                    const n = Number(
                        props.horizontal ? ctx.parsed.x : ctx.parsed.y,
                    );
                    return `${n} ${n === 1 ? props.unit[0] : props.unit[1]}`;
                },
            },
        },
    },
    scales: props.horizontal
        ? { x: valueAxis.value, y: labelAxis }
        : { x: labelAxis, y: valueAxis.value },
    onHover: (event: ChartEvent, elements: ActiveElement[]) => {
        const target = event.native?.target as HTMLElement | undefined;
        if (target) {
            target.style.cursor =
                props.selectable && elements.length ? 'pointer' : 'default';
        }
    },
    onClick: (_event: ChartEvent, elements: ActiveElement[]) => {
        const bar = elements.length ? props.bars[elements[0].index] : null;
        if (bar) pick(bar.key);
    },
}));

const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
</script>

<template>
    <div class="box box--stacked flex flex-1 flex-col">
        <div :class="cardHeader">
            <div
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                :class="iconTone"
            >
                <Lucide :icon="icon" class="h-4 w-4" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-sm font-medium">{{ title }}</div>
                <div class="text-xs text-slate-500">{{ subtitle }}</div>
            </div>
            <button
                v-if="active"
                type="button"
                class="text-xs font-medium text-primary hover:underline"
                @click="emit('select', '')"
            >
                Quitar filtro
            </button>
        </div>
        <div class="flex flex-1 flex-col px-4 py-3">
            <div
                v-if="hasData"
                class="w-full"
                :class="size === 'sm' ? 'h-[140px]' : 'h-[180px]'"
            >
                <Chart
                    type="bar"
                    :data="data"
                    :options="options"
                    :class="
                        size === 'sm'
                            ? '!h-[140px] w-full'
                            : '!h-[180px] w-full'
                    "
                />
            </div>
            <div
                v-else
                class="flex flex-1 flex-col items-center justify-center gap-1.5 py-8 text-center"
            >
                <Lucide icon="ChartColumn" class="h-5 w-5 text-slate-300" />
                <span class="text-xs text-slate-400">{{ emptyText }}</span>
            </div>
            <p
                v-if="hasData && selectable"
                class="mt-2 text-[11px] text-slate-400"
            >
                Toca una barra para ver solo esas en la lista.
            </p>
        </div>
        <div
            v-if="$slots.footer"
            class="border-t border-slate-200/60 px-4 py-2.5 dark:border-darkmode-400"
        >
            <slot name="footer" />
        </div>
    </div>
</template>
