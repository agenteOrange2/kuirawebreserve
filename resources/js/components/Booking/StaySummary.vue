<script setup lang="ts">
import { computed } from 'vue';
import Lucide from '@/components/Base/Lucide';
import { formatMoney, formatSecondaryMoney } from '@/lib/bookingFormat';
import type { DonutPart } from './TotalDonut.vue';
import TotalDonut from './TotalDonut.vue';

export interface SummaryLine {
    id: string;
    label: string;
    detail?: string | null;
    amount: number;
    /** Precio sin el descuento de temporada (semana, mes): se tacha. */
    regularAmount?: number | null;
    removable?: boolean;
}

/**
 * "Tu reserva": lo que lleva, cuánto es cada cosa y el total. En escritorio
 * va fijo al costado de cada paso; en celular se abre en un modal. La dona
 * aparece cuando el total tiene al menos dos partes (hospedaje + algo más).
 */
const props = defineProps<{
    title?: string;
    photo?: string | null;
    name: string;
    stay: string;
    occupancy?: string | null;
    lines: SummaryLine[];
    discount?: { code: string; amount: number } | null;
    total: number;
    currency: string;
    secondaryCurrency?: string | null;
    exchangeRate?: number | null;
    parts?: DonutPart[];
    removableCoupon?: boolean;
}>();

// Lo que ya se descontó por temporada/estancia larga: se dice bajo el total
// (las líneas ya traen el precio rebajado; no es otra resta).
const savings = computed(
    () =>
        Math.round(
            props.lines.reduce(
                (sum, line) =>
                    sum +
                    (line.regularAmount && line.regularAmount > line.amount
                        ? line.regularAmount - line.amount
                        : 0),
                0,
            ) * 100,
        ) / 100,
);

defineEmits<{
    (e: 'remove', id: string): void;
    (e: 'remove-coupon'): void;
}>();

const money = (n: number) => formatMoney(n, props.currency);
const secondary = computed(() =>
    formatSecondaryMoney(
        props.total,
        props.secondaryCurrency,
        props.exchangeRate,
    ),
);
const showDonut = computed(
    () => (props.parts ?? []).filter((p) => p.amount > 0).length >= 2,
);
</script>

<template>
    <div>
        <div class="flex items-start gap-3">
            <img
                v-if="photo"
                :src="photo"
                :alt="name"
                class="h-14 w-20 shrink-0 rounded-lg object-cover"
            />
            <div
                v-else
                class="flex h-14 w-20 shrink-0 items-center justify-center rounded-lg bg-slate-100"
            >
                <Lucide icon="BedDouble" class="h-6 w-6 text-slate-300" />
            </div>
            <div class="min-w-0">
                <div class="text-sm font-medium text-slate-800">{{ name }}</div>
                <div class="mt-0.5 text-xs text-slate-500">{{ stay }}</div>
                <div v-if="occupancy" class="text-xs text-slate-500">
                    {{ occupancy }}
                </div>
            </div>
        </div>

        <div class="mt-3 divide-y divide-slate-200 border-t border-slate-200">
            <div
                v-for="line in lines"
                :key="line.id"
                class="flex items-center gap-2 py-2.5 text-sm"
            >
                <div class="min-w-0 flex-1">
                    <div class="text-slate-700">{{ line.label }}</div>
                    <div v-if="line.detail" class="text-xs text-slate-500">
                        {{ line.detail }}
                    </div>
                </div>
                <span class="shrink-0 text-right">
                    <span
                        v-if="
                            line.regularAmount &&
                            line.regularAmount > line.amount
                        "
                        class="block text-xs text-slate-400 line-through"
                        >{{ money(line.regularAmount) }}</span
                    >
                    <span class="text-slate-700">{{ money(line.amount) }}</span>
                </span>
                <button
                    v-if="line.removable"
                    type="button"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                    :title="`Quitar ${line.label}`"
                    @click="$emit('remove', line.id)"
                >
                    <Lucide icon="X" class="h-4 w-4" />
                </button>
            </div>
            <div
                v-if="discount && discount.amount > 0"
                class="flex items-center gap-2 py-2.5 text-sm text-success"
            >
                <Lucide icon="TicketPercent" class="h-4 w-4 shrink-0" />
                <span class="min-w-0 flex-1">Cupón {{ discount.code }}</span>
                <span class="shrink-0 font-medium"
                    >−{{ money(discount.amount) }}</span
                >
                <button
                    v-if="removableCoupon"
                    type="button"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                    title="Quitar cupón"
                    @click="$emit('remove-coupon')"
                >
                    <Lucide icon="X" class="h-4 w-4" />
                </button>
            </div>
        </div>

        <div
            class="flex items-baseline justify-between gap-3 border-t border-slate-200 pt-3"
        >
            <span class="text-sm text-slate-500">Total</span>
            <span class="text-lg font-semibold text-slate-800">{{
                money(total)
            }}</span>
        </div>
        <div v-if="secondary" class="text-right text-xs text-slate-500">
            {{ secondary }}
        </div>
        <div
            v-if="savings > 0"
            class="mt-2 flex items-center justify-end gap-1.5 text-xs font-medium text-success"
        >
            <Lucide icon="BadgePercent" class="h-3.5 w-3.5" />
            Ahorras {{ money(savings) }} con el descuento
        </div>

        <div
            v-if="showDonut && parts"
            class="mt-4 border-t border-slate-200 pt-4"
        >
            <TotalDonut
                :parts="parts"
                :total="total"
                :currency="currency"
                :discount="discount?.amount ?? 0"
            />
        </div>

        <slot />
    </div>
</template>
