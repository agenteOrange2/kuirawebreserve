<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/lib/bookingFormat';

/**
 * Cuánto lleva pagado de su reserva: un anillo con el porcentaje al centro
 * y las tres cifras al lado (total, pagado, por pagar). Es una sola
 * proporción, así que un anillo de avance la dice mejor que una dona de
 * varias partes. Completo se pinta en verde, con su palabra: el color
 * nunca va solo.
 */
const props = defineProps<{
    total: number;
    paid: number;
    currency: string;
}>();

const size = 84;
const stroke = 10;
const radius = (size - stroke) / 2;
const circumference = 2 * Math.PI * radius;

const ratio = computed(() =>
    props.total > 0 ? Math.min(1, Math.max(0, props.paid / props.total)) : 0,
);
const percent = computed(() => Math.round(ratio.value * 100));
const complete = computed(
    () => props.total > 0 && props.paid >= props.total - 0.005,
);
const color = computed(() =>
    complete.value
        ? 'var(--color-success)'
        : 'var(--booking-chart, var(--color-primary))',
);
const pending = computed(() => Math.max(0, props.total - props.paid));
const money = (n: number) => formatMoney(n, props.currency);
</script>

<template>
    <!-- Arriba el anillo con lo que falta (lo que importa hoy); abajo el
         total y lo pagado a todo el ancho: en la columna de 340px las cifras
         con "MXN" no cabían al lado del anillo. -->
    <figure>
        <div class="flex items-center gap-4">
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
                    :aria-label="`Pagado ${percent}%: ${money(paid)} de ${money(total)}`"
                >
                    <circle
                        :cx="size / 2"
                        :cy="size / 2"
                        :r="radius"
                        fill="none"
                        stroke="var(--booking-chart, var(--color-primary))"
                        stroke-opacity="0.1"
                        :stroke-width="stroke"
                    />
                    <circle
                        v-if="ratio > 0"
                        :cx="size / 2"
                        :cy="size / 2"
                        :r="radius"
                        fill="none"
                        :stroke="color"
                        :stroke-width="stroke"
                        stroke-linecap="round"
                        :stroke-dasharray="`${circumference * ratio} ${circumference}`"
                    />
                </svg>
                <div
                    class="absolute inset-0 flex flex-col items-center justify-center text-center"
                >
                    <span class="text-base font-semibold text-slate-800"
                        >{{ percent }}%</span
                    >
                    <span class="text-[11px] text-slate-500">pagado</span>
                </div>
            </div>
            <div class="min-w-0">
                <div class="text-sm text-slate-500">
                    {{ complete ? 'Tu reserva está' : 'Por pagar' }}
                </div>
                <div
                    class="text-lg font-semibold"
                    :class="complete ? 'text-success' : 'text-slate-800'"
                >
                    {{ complete ? 'Pagada' : money(pending) }}
                </div>
            </div>
        </div>
        <figcaption
            class="mt-4 space-y-1.5 border-t border-slate-200 pt-3 text-sm"
        >
            <div class="flex justify-between gap-3 text-slate-500">
                <span>Total</span>
                <span class="text-slate-700">{{ money(total) }}</span>
            </div>
            <div class="flex justify-between gap-3 text-slate-500">
                <span>Pagado</span>
                <span class="text-slate-700">{{ money(paid) }}</span>
            </div>
        </figcaption>
    </figure>
</template>
