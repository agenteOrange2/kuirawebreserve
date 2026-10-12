<script setup lang="ts">
import { computed } from 'vue';

/**
 * El cobro de una reserva en una sola celda: la barrita de cuánto se ha
 * pagado del total y, en palabras, si debe, ya pagó o no ha dado nada.
 * Antes eran tres piezas (total, etiqueta de pago y "debe") y no se leía
 * de un vistazo si había que cobrar.
 */
const props = withDefaults(
    defineProps<{
        total: number;
        paid: number;
        balance: number;
        /** Pasó la fecha límite del saldo: barra y leyenda en rojo. */
        overdue?: boolean;
        align?: 'left' | 'right';
    }>(),
    { overdue: false, align: 'right' },
);

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n || 0);

const percent = computed(() =>
    props.total > 0
        ? Math.min(100, Math.round((props.paid / props.total) * 100))
        : props.balance > 0
          ? 0
          : 100,
);

const state = computed(() =>
    props.balance <= 0.009
        ? { text: 'Pagada', tone: 'text-success', bar: 'bg-success' }
        : props.overdue
          ? {
                text: `Debe ${money(props.balance)}`,
                tone: 'text-danger',
                bar: 'bg-danger',
            }
          : props.paid > 0
            ? {
                  text: `Debe ${money(props.balance)}`,
                  tone: 'text-pending',
                  bar: 'bg-success',
              }
            : {
                  text: 'Sin pago',
                  tone: 'text-slate-500',
                  bar: 'bg-success',
              },
);
</script>

<template>
    <div
        class="w-28 min-w-0"
        :class="align === 'right' ? 'ml-auto text-right' : ''"
        :title="`Pagado ${money(paid)} de ${money(total)}`"
    >
        <div class="text-xs font-medium tabular-nums" :class="state.tone">
            {{ state.text }}
        </div>
        <div
            class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
        >
            <div
                class="h-full rounded-full"
                :class="state.bar"
                :style="{ width: `${percent}%` }"
            />
        </div>
        <div
            v-if="overdue && balance > 0.009"
            class="mt-0.5 text-[11px] font-medium text-danger"
        >
            Pago vencido
        </div>
        <div v-else class="mt-0.5 text-[11px] text-slate-400 tabular-nums">
            de {{ money(total) }}
        </div>
    </div>
</template>
