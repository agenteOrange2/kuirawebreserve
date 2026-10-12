<script setup lang="ts">
import type { ActiveElement, ChartEvent, ChartOptions } from 'chart.js';
import { computed } from 'vue';
import Chart from '@/components/Base/Chart';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';

export interface DonutSlice {
    key: string;
    label: string;
    count: number;
    /** Hex: el canvas no lee clases de Tailwind. */
    color: string;
    /** Una línea de contexto debajo de la etiqueta. */
    hint?: string;
}

/**
 * Dona de las pantallas de reservas: la gráfica a la izquierda y la
 * leyenda como lista de renglones a la derecha. Tocar una rebanada o su
 * renglón filtra la lista de abajo (y tocarla otra vez quita el filtro):
 * la gráfica es una forma de buscar, no un adorno.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        subtitle: string;
        icon: Icon;
        /** Clases del círculo del encabezado (border-X/10 bg-X/10 text-X). */
        iconTone?: string;
        slices: DonutSlice[];
        centerValue: string | number;
        centerLabel: string;
        /** La rebanada que hoy filtra la lista ('' = ninguna). */
        active?: string;
        /** Sin esto, la dona se ve pero no filtra. */
        selectable?: boolean;
        emptyText?: string;
    }>(),
    {
        iconTone: 'border-primary/10 bg-primary/10 text-primary',
        active: '',
        selectable: true,
        emptyText: 'Todavía no hay nada que graficar',
    },
);

const emit = defineEmits<{ select: [key: string] }>();

const total = computed(() => props.slices.reduce((s, x) => s + x.count, 0));
const visible = computed(() => props.slices.filter((s) => s.count > 0));

function pick(key: string) {
    if (!props.selectable) return;
    emit('select', props.active === key ? '' : key);
}

const data = computed(() => ({
    labels: visible.value.map((s) => s.label),
    datasets: [
        {
            data: visible.value.map((s) => s.count),
            backgroundColor: visible.value.map((s) =>
                !props.active || props.active === s.key
                    ? s.color
                    : `${s.color}40`,
            ),
            borderWidth: 0,
            hoverOffset: 4,
        },
    ],
}));

const options = computed<ChartOptions<'doughnut'>>(() => ({
    cutout: '72%',
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    onHover: (event: ChartEvent, elements: ActiveElement[]) => {
        const target = event.native?.target as HTMLElement | undefined;
        if (target) {
            target.style.cursor =
                props.selectable && elements.length ? 'pointer' : 'default';
        }
    },
    onClick: (_event: ChartEvent, elements: ActiveElement[]) => {
        const slice = elements.length ? visible.value[elements[0].index] : null;
        if (slice) pick(slice.key);
    },
}));

const percent = (n: number) =>
    total.value ? `${Math.round((n / total.value) * 100)}%` : '0%';

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
        <div
            v-if="total"
            class="flex flex-1 flex-col items-center gap-4 px-4 py-3 sm:flex-row"
        >
            <div class="relative h-[132px] w-[132px] shrink-0">
                <Chart
                    type="doughnut"
                    :data="data"
                    :options="options"
                    class="!h-[132px] !w-[132px]"
                />
                <div
                    class="pointer-events-none absolute inset-0 flex items-center justify-center"
                >
                    <div class="text-center">
                        <div class="text-base font-medium tabular-nums">
                            {{ centerValue }}
                        </div>
                        <div class="text-[11px] text-slate-500">
                            {{ centerLabel }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="w-full min-w-0 flex-1 space-y-0.5">
                <component
                    :is="selectable ? 'button' : 'div'"
                    v-for="slice in slices"
                    :key="slice.key"
                    :type="selectable ? 'button' : undefined"
                    class="flex w-full min-w-0 items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs transition"
                    :class="[
                        selectable
                            ? 'hover:bg-slate-100 dark:hover:bg-darkmode-400'
                            : '',
                        active === slice.key
                            ? 'bg-primary/5 ring-1 ring-primary/20'
                            : '',
                        (active && active !== slice.key) || !slice.count
                            ? 'opacity-50'
                            : '',
                    ]"
                    :title="
                        selectable ? 'Ver solo estas en la lista' : undefined
                    "
                    @click="pick(slice.key)"
                >
                    <span
                        class="h-2 w-2 shrink-0 rounded-full"
                        :style="{ backgroundColor: slice.color }"
                    />
                    <span class="min-w-0 flex-1">
                        <span
                            class="block truncate text-slate-600 dark:text-slate-300"
                            >{{ slice.label }}</span
                        >
                        <span
                            v-if="slice.hint"
                            class="block truncate text-[11px] text-slate-400"
                            >{{ slice.hint }}</span
                        >
                    </span>
                    <span class="font-medium tabular-nums">{{
                        slice.count
                    }}</span>
                    <span
                        class="w-9 text-right text-[11px] text-slate-400 tabular-nums"
                        >{{ percent(slice.count) }}</span
                    >
                </component>
            </div>
        </div>
        <div
            v-else
            class="flex flex-1 flex-col items-center justify-center gap-1.5 px-4 py-8 text-center"
        >
            <Lucide icon="ChartPie" class="h-5 w-5 text-slate-300" />
            <span class="text-xs text-slate-400">{{ emptyText }}</span>
        </div>
        <div
            v-if="$slots.footer"
            class="border-t border-slate-200/60 px-4 py-2.5 dark:border-darkmode-400"
        >
            <slot name="footer" />
        </div>
    </div>
</template>
