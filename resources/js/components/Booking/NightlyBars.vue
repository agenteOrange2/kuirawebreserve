<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    formatMoney,
    formatMoneyShort,
    formatShortDay,
} from '@/lib/bookingFormat';

export interface NightPrice {
    date: string;
    price: number;
    season: string | null;
}

/**
 * Precio de cada noche de la estancia: explica por qué el total no es
 * "noches × tarifa" cuando el fin de semana o una temporada cuestan
 * distinto. Barras ancladas abajo, un solo tono, etiqueta solo en la más
 * cara y la más barata, y el detalle de cada una al pasar o tocar. Quien la
 * usa decide pintarla solo si los precios cambian entre noches.
 */
const props = defineProps<{
    nights: NightPrice[];
    currency: string;
}>();

const active = ref<number | null>(null);

const max = computed(() => Math.max(...props.nights.map((n) => n.price), 1));
const min = computed(() => Math.min(...props.nights.map((n) => n.price)));
const average = computed(
    () =>
        props.nights.reduce((acc, n) => acc + n.price, 0) /
        Math.max(1, props.nights.length),
);
const maxIndex = computed(() =>
    props.nights.findIndex((n) => n.price === max.value),
);
const minIndex = computed(() =>
    props.nights.findIndex((n) => n.price === min.value),
);
// Con muchas noches no caben todas las etiquetas del eje.
const labelEvery = computed(() => Math.ceil(props.nights.length / 10));

const shown = computed(() =>
    active.value === null ? null : props.nights[active.value],
);
</script>

<template>
    <figure>
        <figcaption
            class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5"
        >
            <span class="text-sm font-medium text-slate-700"
                >Precio por noche</span
            >
            <span class="text-xs text-slate-500">
                <template v-if="shown">
                    {{ formatShortDay(shown.date) }} ·
                    <span class="font-medium text-slate-700">{{
                        formatMoney(shown.price, currency)
                    }}</span>
                    <template v-if="shown.season">
                        · {{ shown.season }}</template
                    >
                </template>
                <template v-else>
                    Promedio {{ formatMoneyShort(average) }}
                </template>
            </span>
        </figcaption>
        <div
            class="mt-3 flex h-28 items-end gap-1"
            role="img"
            :aria-label="
                nights
                    .map(
                        (n) =>
                            `${formatShortDay(n.date)} ${formatMoney(n.price, currency)}`,
                    )
                    .join(', ')
            "
        >
            <button
                v-for="(night, i) in nights"
                :key="night.date"
                type="button"
                class="group relative flex h-full min-w-0 flex-1 flex-col justify-end"
                :aria-label="`${formatShortDay(night.date)}: ${formatMoney(night.price, currency)}`"
                @mouseenter="active = i"
                @mouseleave="active = null"
                @focus="active = i"
                @blur="active = null"
                @click="active = active === i ? null : i"
            >
                <span
                    v-if="(i === maxIndex || i === minIndex) && max !== min"
                    class="mb-1 truncate text-center text-[11px] font-medium text-slate-600"
                    >{{ formatMoneyShort(night.price) }}</span
                >
                <span
                    class="block w-full rounded-t-[4px] transition-opacity"
                    :style="{
                        height: `${Math.max(10, (night.price / max) * 100)}%`,
                        backgroundColor:
                            'var(--booking-chart, var(--color-primary))',
                        opacity: active === null || active === i ? 0.85 : 0.35,
                    }"
                />
            </button>
        </div>
        <div class="mt-1.5 flex gap-1 border-t border-slate-200 pt-1.5">
            <span
                v-for="(night, i) in nights"
                :key="night.date"
                class="min-w-0 flex-1 truncate text-center text-[11px] text-slate-400"
                >{{
                    i % labelEvery === 0 ? formatShortDay(night.date) : ''
                }}</span
            >
        </div>
    </figure>
</template>
