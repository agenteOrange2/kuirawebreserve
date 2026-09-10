<script setup lang="ts">
import axios from 'axios';
import { computed, inject, ref, watch } from 'vue';
import Lucide from '@/components/Base/Lucide';
import { FloorPlanKey } from '../../context';
import { formatMoney } from '../../format';
import StayFolioDialog from '../StayFolioDialog.vue';
import type { CheckoutFolio } from '../../types';

/**
 * Historial: quién ha pasado por este cuarto y qué se movió hoy.
 *
 * El listado es para ELEGIR, no para leer: renglones a ras, chicos, con el
 * nombre, las fechas y lo que dejó. La cuenta de la visita —cuatro cifras,
 * los consumos y los pagos— se abre en su propio modal; como acordeón
 * empujaba la lista hacia abajo y quedaba embutida en un renglón.
 *
 * Los datos se piden al abrir el tab, no con el plano: son diez estancias por
 * cuarto y el plano ya carga bastante.
 */
interface UpcomingRow {
    id: number;
    code: string;
    guest_name: string;
    rate_plan: string | null;
    status_label: string;
    starts_at: string;
    ends_at: string;
    starts_today: boolean;
    total_amount: number;
}

interface StayRow {
    id: number;
    guest_name: string;
    rate_plan: string | null;
    channel: string | null;
    check_in_at: string | null;
    check_out_at: string | null;
    active: boolean;
    amount: number;
    consumos_total: number;
    vehicle_plate: string | null;
    vehicle_id: number | null;
}

const ctx = inject(FloorPlanKey)!;
const room = computed(() => ctx.room.value!);

const stays = ref<StayRow[]>([]);
const upcoming = ref<UpcomingRow[]>([]);
const loading = ref(false);
const loaded = ref(false);

/** Estancia abierta en el modal de cuenta y su folio. */
const openStay = ref<StayRow | null>(null);
const detail = ref<CheckoutFolio | null>(null);
const detailLoading = ref(false);

async function loadStays() {
    if (loading.value) {
        return;
    }

    loading.value = true;

    try {
        const { data } = await axios.get(`/api/rooms/${room.value.id}/stays`);
        stays.value = data.stays ?? [];
        upcoming.value = data.upcoming ?? [];
        loaded.value = true;
    } catch {
        ctx.onError('No se pudo leer el historial de la habitación.');
    } finally {
        loading.value = false;
    }
}

async function openFolio(stay: StayRow) {
    if (!ctx.canViewStays) {
        return;
    }

    openStay.value = stay;
    detail.value = null;

    // La estancia que está adentro ya tiene su cuenta cargada por el plano.
    if (stay.active && ctx.folio.value !== null) {
        detail.value = ctx.folio.value;

        return;
    }

    detailLoading.value = true;

    try {
        const { data } = await axios.get(`/api/stays/${stay.id}/folio`);
        detail.value = data;
    } catch {
        ctx.onError('No se pudo abrir la cuenta de esa estancia.');
        openStay.value = null;
    } finally {
        detailLoading.value = false;
    }
}

function closeFolio() {
    openStay.value = null;
    detail.value = null;
}

// Cambiar de cuarto vuelve a pedir: el historial es del cuarto abierto.
watch(
    () => room.value.id,
    () => {
        stays.value = [];
        upcoming.value = [];
        loaded.value = false;
        closeFolio();
        void loadStays();
    },
    { immediate: true },
);

const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
</script>

<template>
    <div class="grid items-start gap-4 xl:grid-cols-3">
        <section
            class="overflow-hidden rounded-xl border border-slate-200/70 xl:col-span-2 dark:border-darkmode-400"
        >
            <div :class="cardHeader">
                <div
                    :class="sectionIcon"
                    class="border-primary/10 bg-primary/10 text-primary"
                >
                    <Lucide icon="Users" class="h-4 w-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-medium">Quién ha estado aquí</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Las últimas estancias; toca una para ver su cuenta.
                    </p>
                </div>
                <a
                    :href="route('tenant.rooms.history', room.id)"
                    class="ml-auto inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 text-xs font-medium text-slate-500 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                >
                    <Lucide icon="History" class="h-3.5 w-3.5" />
                    Historial completo
                </a>
            </div>

            <p
                v-if="loading && !loaded"
                class="px-4 py-3 text-xs text-slate-500"
            >
                Leyendo el historial…
            </p>

            <p
                v-else-if="!stays.length"
                class="px-4 py-3 text-xs text-slate-500"
            >
                Todavía no hay estancias registradas en esta habitación.
            </p>

            <div
                v-else
                class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
            >
                <button
                    v-for="stay in stays"
                    :key="stay.id"
                    type="button"
                    class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition hover:bg-slate-50 disabled:cursor-default disabled:hover:bg-transparent dark:hover:bg-darkmode-700/50"
                    :title="
                        ctx.canViewStays
                            ? 'Ver la cuenta de esta estancia'
                            : 'Tu usuario no puede ver cuentas de estancias'
                    "
                    :disabled="!ctx.canViewStays"
                    @click="openFolio(stay)"
                >
                    <div
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                        :class="
                            stay.active
                                ? 'bg-primary/10 text-primary'
                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                        "
                    >
                        <Lucide
                            :icon="stay.active ? 'DoorOpen' : 'User'"
                            class="h-3.5 w-3.5"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div
                            class="flex flex-wrap items-center gap-x-2 gap-y-1"
                        >
                            <span class="truncate text-xs font-medium">{{
                                stay.guest_name
                            }}</span>
                            <span
                                v-if="stay.vehicle_plate"
                                class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] tracking-wider dark:bg-darkmode-400"
                                >{{ stay.vehicle_plate }}</span
                            >
                            <span
                                v-if="stay.active"
                                class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                >Adentro</span
                            >
                        </div>
                        <p class="mt-0.5 truncate text-[11px] text-slate-500">
                            {{ stay.check_in_at ?? '—' }} →
                            {{ stay.check_out_at ?? 'sigue adentro' }}
                            <template v-if="stay.rate_plan">
                                · {{ stay.rate_plan }}</template
                            >
                        </p>
                    </div>
                    <div class="shrink-0 text-right">
                        <div class="text-xs font-medium">
                            {{ formatMoney(stay.amount) }}
                        </div>
                        <div
                            v-if="stay.consumos_total > 0"
                            class="text-[11px] text-slate-500"
                        >
                            +{{ formatMoney(stay.consumos_total) }} consumo
                        </div>
                    </div>
                    <Lucide
                        v-if="ctx.canViewStays"
                        icon="ChevronRight"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                </button>
            </div>
        </section>

        <div class="space-y-4">
            <!-- Lo que viene: sirve para saber hasta cuándo se puede extender a
                 quien está adentro sin pisar a nadie. -->
            <section
                v-if="upcoming.length"
                class="overflow-hidden rounded-xl border border-info/20 dark:border-info/30"
            >
                <div :class="cardHeader">
                    <div
                        :class="sectionIcon"
                        class="border-info/10 bg-info/10 text-info"
                    >
                        <Lucide icon="CalendarClock" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-sm font-medium">Lo que viene</h3>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Reservas vivas de esta habitación.
                        </p>
                    </div>
                </div>
                <div
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="reservation in upcoming"
                        :key="reservation.id"
                        class="flex items-start gap-3 px-4 py-2.5"
                    >
                        <div class="min-w-0 flex-1">
                            <div
                                class="flex flex-wrap items-center gap-x-2 gap-y-1"
                            >
                                <span class="truncate text-xs font-medium">{{
                                    reservation.guest_name
                                }}</span>
                                <span class="text-[11px] text-slate-500">{{
                                    reservation.code
                                }}</span>
                                <span
                                    v-if="reservation.starts_today"
                                    class="rounded-full bg-info/10 px-2 py-0.5 text-[11px] font-medium text-info"
                                    >Llega hoy</span
                                >
                            </div>
                            <p class="mt-0.5 text-[11px] text-slate-500">
                                {{ reservation.starts_at }} →
                                {{ reservation.ends_at }} ·
                                {{ reservation.status_label }}
                            </p>
                        </div>
                        <span class="shrink-0 text-xs font-medium">{{
                            formatMoney(reservation.total_amount)
                        }}</span>
                    </div>
                </div>
            </section>

            <section
                class="overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
            >
                <div :class="cardHeader">
                    <div
                        :class="sectionIcon"
                        class="border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="History" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-sm font-medium">Cambios de hoy</h3>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Movimientos de estado de esta habitación.
                        </p>
                    </div>
                </div>

                <div
                    v-if="room.today_history.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="entry in room.today_history"
                        :key="entry.id"
                        class="flex items-start justify-between gap-3 px-4 py-2.5"
                    >
                        <div class="min-w-0">
                            <div class="truncate text-xs font-medium">
                                {{
                                    entry.from_label
                                        ? `${entry.from_label} → `
                                        : ''
                                }}{{ entry.to_label }}
                            </div>
                            <div class="mt-0.5 text-[11px] text-slate-500">
                                {{
                                    entry.auto
                                        ? 'Sistema'
                                        : (entry.changed_by ?? 'Sistema')
                                }}
                            </div>
                        </div>
                        <div class="shrink-0 text-[11px] text-slate-500">
                            {{ entry.created_at ?? '—' }}
                        </div>
                    </div>
                </div>
                <p v-else class="px-4 py-3 text-xs text-slate-500">
                    Sin cambios registrados hoy.
                </p>
            </section>
        </div>

        <StayFolioDialog
            :open="openStay !== null"
            :room-number="room.number"
            :stay="openStay"
            :folio="detail"
            :loading="detailLoading"
            @close="closeFolio"
        />
    </div>
</template>
