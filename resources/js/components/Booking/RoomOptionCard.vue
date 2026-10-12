<script setup lang="ts">
import { computed } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import { formatMoney, humanizeDurationLabel } from '@/lib/bookingFormat';
import type { LongStayOffer, PriceLine } from '@/lib/bookingPricing';
import {
    breakdownPercent,
    breakdownSavings,
    longStayHint,
} from '@/lib/bookingPricing';
import type { GalleryPhoto } from './PhotoGallery.vue';

export interface RoomOption {
    room_type_id: number;
    room_id: number | null;
    name: string;
    description: string | null;
    capacity: number;
    effective_capacity: number;
    amenities: string[];
    photos: GalleryPhoto[];
    duration_label: string;
    starts_at: string;
    ends_at: string;
    total: number;
    price_breakdown: PriceLine[];
    long_stay_offers?: LongStayOffer[];
    included_occupancy?: number | null;
    extra_guest_fee?: number | null;
    units?: number;
    nightly?: { date: string; price: number; season: string | null }[];
    requires_prepayment: boolean;
    available: boolean;
    rooms_count: number;
    advance_error: string | null;
}

/**
 * Una habitación en los resultados: foto, para cuántos, lo que incluye y el
 * total real de la estancia (con "por noche" al lado cuando son varias). La
 * foto y "Detalles" abren el modal con galería y desglose; "Elegir" sigue.
 */
const props = defineProps<{
    option: RoomOption;
    currency: string;
    nights: number | null;
}>();

defineEmits<{
    (e: 'choose'): void;
    (e: 'details'): void;
}>();

const perNight = computed(() =>
    props.nights && props.nights > 1 ? props.option.total / props.nights : null,
);

// Descuento por semana/mes ya aplicado: se tacha el precio normal para que
// se vea (antes "$18,000" no se distinguía de un precio sin promo).
const savings = computed(() => breakdownSavings(props.option.price_breakdown));
const percent = computed(() => breakdownPercent(props.option.price_breakdown));
const offerHint = computed(() =>
    props.option.available
        ? longStayHint(props.option.long_stay_offers, props.currency)
        : null,
);
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border bg-white transition"
        :class="
            option.available
                ? 'border-slate-200 hover:border-primary/40 hover:shadow-lg hover:shadow-slate-200/60'
                : 'border-slate-200 opacity-70'
        "
    >
        <button
            type="button"
            class="relative block w-full shrink-0 bg-slate-100"
            :class="option.photos.length ? 'h-44' : 'h-28'"
            :title="`Ver ${option.name}`"
            @click="$emit('details')"
        >
            <img
                v-if="option.photos.length"
                :src="option.photos[0].thumb_url"
                :alt="option.name"
                class="h-full w-full object-cover"
                loading="lazy"
            />
            <span v-else class="flex h-full w-full items-center justify-center">
                <Lucide icon="BedDouble" class="h-10 w-10 text-slate-300" />
            </span>
            <span
                v-if="option.photos.length > 1"
                class="absolute right-2 bottom-2 inline-flex items-center gap-1 rounded-full bg-black/55 px-2.5 py-1 text-xs font-medium text-white"
            >
                <Lucide icon="Images" class="h-3.5 w-3.5" />
                {{ option.photos.length }} fotos
            </span>
            <!-- Urgencia honesta: cuartos que de verdad quedan de este tipo -->
            <span
                v-if="
                    option.available &&
                    option.rooms_count >= 1 &&
                    option.rooms_count <= 3
                "
                class="absolute top-2 left-2 rounded-full bg-warning px-2.5 py-1 text-xs font-medium text-white shadow-sm"
            >
                {{
                    option.rooms_count === 1
                        ? 'Solo queda 1'
                        : `Quedan ${option.rooms_count}`
                }}
            </span>
            <span
                v-if="!option.available"
                class="absolute top-2 left-2 rounded-full bg-black/60 px-2.5 py-1 text-xs font-medium text-white"
            >
                Sin disponibilidad
            </span>
        </button>

        <div class="flex flex-1 flex-col p-4">
            <h3 class="text-base font-medium text-slate-800">
                {{ option.name }}
            </h3>
            <div
                class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-500"
            >
                <span class="inline-flex items-center gap-1.5">
                    <Lucide icon="Users" class="h-4 w-4" />
                    Hasta {{ option.effective_capacity }}
                    {{
                        option.effective_capacity === 1 ? 'persona' : 'personas'
                    }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <Lucide :icon="nights ? 'Moon' : 'Clock'" class="h-4 w-4" />
                    {{
                        nights
                            ? `${nights} ${nights === 1 ? 'noche' : 'noches'}`
                            : humanizeDurationLabel(option.duration_label)
                    }}
                </span>
            </div>
            <p
                v-if="option.effective_capacity > option.capacity"
                class="mt-1 text-xs text-slate-500"
            >
                Desde la persona {{ option.capacity + 1 }} hay cargo extra.
            </p>
            <div
                v-if="option.amenities.length"
                class="mt-2.5 flex flex-wrap gap-1.5"
            >
                <span
                    v-for="a in option.amenities.slice(0, 3)"
                    :key="a"
                    class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600"
                    >{{ a }}</span
                >
                <span
                    v-if="option.amenities.length > 3"
                    class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600"
                    >+{{ option.amenities.length - 3 }}</span
                >
            </div>
            <p
                v-if="option.advance_error"
                class="mt-2 flex items-start gap-1.5 text-xs text-warning"
            >
                <Lucide
                    icon="CalendarClock"
                    class="mt-px h-3.5 w-3.5 shrink-0"
                />
                {{ option.advance_error }}
            </p>

            <p
                v-if="offerHint"
                class="mt-2 flex items-start gap-1.5 text-xs text-success"
            >
                <Lucide
                    icon="BadgePercent"
                    class="mt-px h-3.5 w-3.5 shrink-0"
                />
                {{ offerHint }}
            </p>

            <div class="mt-auto pt-4">
                <div
                    class="flex flex-wrap items-end justify-between gap-3 border-t border-slate-200 pt-3.5"
                >
                    <div class="min-w-0">
                        <div
                            v-if="savings > 0"
                            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs"
                        >
                            <span class="text-slate-400 line-through">{{
                                formatMoney(option.total + savings, currency)
                            }}</span>
                            <span
                                class="rounded-full bg-success/10 px-2 py-0.5 font-medium text-success"
                                >{{ percent }}% menos</span
                            >
                        </div>
                        <div class="text-lg font-semibold text-slate-800">
                            {{ formatMoney(option.total, currency) }}
                        </div>
                        <div class="text-xs text-slate-500">
                            <template v-if="perNight">
                                {{ formatMoney(perNight, currency) }} por noche
                                en promedio
                            </template>
                            <template v-else>Total de tu estancia</template>
                        </div>
                    </div>
                    <div class="flex w-full gap-2 sm:w-auto">
                        <button
                            type="button"
                            class="inline-flex h-11 flex-1 items-center justify-center gap-1.5 rounded-full border border-slate-200 px-4 text-sm font-medium text-slate-600 transition hover:border-slate-300 sm:flex-none"
                            @click="$emit('details')"
                        >
                            Detalles
                        </button>
                        <Button
                            v-if="option.available"
                            variant="primary"
                            class="h-11 flex-1 rounded-full px-5 text-sm shadow-md shadow-primary/20 sm:flex-none"
                            @click="$emit('choose')"
                        >
                            Elegir
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </article>
</template>
