<script setup lang="ts">
import { computed, ref } from 'vue';
import { formatMoney, formatMoneyShort } from '@/lib/bookingFormat';

export interface DonutPart {
    key: string;
    label: string;
    amount: number;
}

/**
 * De qué se compone el total (hospedaje, complementos, experiencias): dona
 * con el total al centro y la leyenda con cada cifra al lado, así nada se
 * lee solo por el color. Un solo tono (el acento del hotel) en intensidades
 * de mayor a menor: con el color de cada hotel no se puede garantizar una
 * paleta de varios tonos legible. Con menos de dos partes no hay nada que
 * comparar y quien la usa no la pinta.
 */
const props = defineProps<{
    parts: DonutPart[];
    total: number;
    currency: string;
    discount?: number;
}>();

const size = 100;
const stroke = 13;
const radius = (size - stroke) / 2;
const circumference = 2 * Math.PI * radius;
const gap = 3;
const opacities = [1, 0.62, 0.38, 0.22];

const active = ref<string | null>(null);

const visible = computed(() =>
    props.parts.filter((p) => p.amount > 0).slice(0, opacities.length),
);
const sum = computed(() => visible.value.reduce((acc, p) => acc + p.amount, 0));

const arcs = computed(() => {
    let offset = 0;
    return visible.value.map((part, i) => {
        const length = (part.amount / (sum.value || 1)) * circumference;
        const arc = {
            ...part,
            opacity: opacities[i],
            dash: `${Math.max(0, length - gap)} ${circumference}`,
            offset: -offset,
            share: Math.round((part.amount / (sum.value || 1)) * 100),
        };
        offset += length;
        return arc;
    });
});
</script>

<template>
    <figure class="flex items-center gap-4">
        <div
            class="relative shrink-0"
            :style="{ width: `${size}px`, height: `${size}px` }"
        >
            <svg
                :width="size"
                :height="size"
                :viewBox="`0 0 ${size} ${size}`"
                class="-rotate-90"
                role="img"
                :aria-label="`Total ${formatMoney(total, currency)}: ${arcs.map((a) => `${a.label} ${a.share}%`).join(', ')}`"
            >
                <circle
                    :cx="size / 2"
                    :cy="size / 2"
                    :r="radius"
                    fill="none"
                    stroke="var(--booking-chart, var(--color-primary))"
                    stroke-opacity="0.08"
                    :stroke-width="stroke"
                />
                <circle
                    v-for="arc in arcs"
                    :key="arc.key"
                    :cx="size / 2"
                    :cy="size / 2"
                    :r="radius"
                    fill="none"
                    stroke="var(--booking-chart, var(--color-primary))"
                    :stroke-opacity="arc.opacity"
                    :stroke-width="active === arc.key ? stroke + 4 : stroke"
                    :stroke-dasharray="arc.dash"
                    :stroke-dashoffset="arc.offset"
                    class="cursor-pointer transition-all duration-200"
                    @mouseenter="active = arc.key"
                    @mouseleave="active = null"
                >
                    <title>
                        {{ arc.label }}:
                        {{ formatMoney(arc.amount, currency) }} ({{
                            arc.share
                        }}%)
                    </title>
                </circle>
            </svg>
            <div
                class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center"
            >
                <span class="text-[11px] text-slate-500">Total</span>
                <span class="text-sm font-semibold text-slate-800">{{
                    formatMoneyShort(total)
                }}</span>
            </div>
        </div>
        <figcaption class="min-w-0 flex-1 space-y-1">
            <!-- Nombre completo arriba y su parte abajo: en la columna del
                 resumen (330px) "Hospedaje" salía cortado en "Ho..." -->
            <div
                v-for="arc in arcs"
                :key="arc.key"
                class="flex items-start gap-2 rounded-md px-1.5 py-1 text-xs transition"
                :class="active === arc.key ? 'bg-slate-100' : ''"
                @mouseenter="active = arc.key"
                @mouseleave="active = null"
            >
                <span
                    class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full"
                    :style="{
                        backgroundColor:
                            'var(--booking-chart, var(--color-primary))',
                        opacity: arc.opacity,
                    }"
                />
                <span class="min-w-0 flex-1">
                    <span class="block text-slate-700">{{ arc.label }}</span>
                    <span class="block text-[11px] text-slate-500"
                        >{{ arc.share }}% del total</span
                    >
                </span>
                <span class="shrink-0 font-medium text-slate-700">{{
                    formatMoneyShort(arc.amount)
                }}</span>
            </div>
            <div
                v-if="discount && discount > 0"
                class="flex items-center gap-2 px-1.5 py-1 text-xs text-success"
            >
                <span
                    class="h-2.5 w-2.5 shrink-0 rounded-full border-2 border-success"
                />
                <span class="flex-1">Descuento</span>
                <span class="font-medium"
                    >−{{ formatMoneyShort(discount) }}</span
                >
            </div>
        </figcaption>
    </figure>
</template>
