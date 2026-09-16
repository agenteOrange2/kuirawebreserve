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
 * nombre, las fechas y lo que dejó. Cada lista enseña cinco y trae su "Ver
 * todo" a la sección que le toca del historial completo: con diez, "lo que
 * viene" de una cabaña en temporada era una columna de dos pantallas junto a
 * una de dos renglones. La cuenta de la visita —cuatro cifras,
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
/** Cuántas hay en total; la lista solo trae las primeras cinco. */
const staysTotal = ref(0);
const upcomingTotal = ref(0);
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
        staysTotal.value = data.stays_total ?? stays.value.length;
        upcomingTotal.value = data.upcoming_total ?? upcoming.value.length;
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
        staysTotal.value = 0;
        upcomingTotal.value = 0;
        loaded.value = false;
        closeFolio();
        void loadStays();
    },
    { immediate: true },
);

/** Tope de cada lista del tab; el resto vive en el historial completo. */
const PREVIEW = 5;

// El servidor ya manda cinco estancias y cinco reservas; los cambios de hoy
// llegan con el plano, del más nuevo al más viejo, y se recortan aquí.
const todayChanges = computed(() => room.value.today_history.slice(0, PREVIEW));

/** Liga a la sección del historial completo que continúa cada lista. */
function historyUrl(section: 'estancias' | 'proximas' | 'semaforo'): string {
    return `${route('tenant.rooms.history', room.value.id)}#${section}`;
}

/** "5 de 14" cuando hay más de las que se ven; si no, solo el número. */
function countLabel(shown: number, total: number): string {
    return total > shown ? `${shown} de ${total}` : `${total}`;
}

const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const countBadge =
    'ml-auto shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400';
// Pie de cada lista: la salida a su sección del historial completo.
const cardFooter =
    'flex items-center justify-center gap-1.5 border-t border-slate-200/60 px-4 py-2.5 text-xs font-medium text-primary transition hover:bg-primary/5 dark:border-darkmode-400';
</script>

<template>
    <!-- Tres listas parejas: lo que ya pasó, lo que viene y lo que se movió
         hoy, cada una con su tope y su salida al historial completo. -->
    <div class="grid items-start gap-4 xl:grid-cols-3">
        <section
            class="overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
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
                        Toca una estancia para ver su cuenta.
                    </p>
                </div>
                <span v-if="staysTotal" :class="countBadge">{{
                    countLabel(stays.length, staysTotal)
                }}</span>
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
                        <div class="flex items-center gap-x-2">
                            <span class="truncate text-xs font-medium">{{
                                stay.guest_name
                            }}</span>
                            <span
                                v-if="stay.vehicle_plate"
                                class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] tracking-wider dark:bg-darkmode-400"
                                >{{ stay.vehicle_plate }}</span
                            >
                            <span
                                v-if="stay.active"
                                class="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                >Adentro</span
                            >
                        </div>
                        <p class="mt-0.5 truncate text-[11px] text-slate-500">
                            {{ stay.check_in_at ?? '—' }} →
                            {{ stay.check_out_at ?? 'sigue adentro' }}
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
                            +{{ formatMoney(stay.consumos_total) }}
                        </div>
                    </div>
                    <Lucide
                        v-if="ctx.canViewStays"
                        icon="ChevronRight"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                </button>
            </div>

            <a :href="historyUrl('estancias')" :class="cardFooter">
                Ver todo en el historial
                <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
            </a>
        </section>

        <!-- Lo que viene: sirve para saber hasta cuándo se puede extender a
             quien está adentro sin pisar a nadie. -->
        <section
            class="overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
        >
            <div :class="cardHeader">
                <div
                    :class="sectionIcon"
                    class="border-info/10 bg-info/10 text-info"
                >
                    <Lucide icon="CalendarClock" class="h-4 w-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-medium">Lo que viene</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Reservas vivas de esta habitación.
                    </p>
                </div>
                <span v-if="upcomingTotal" :class="countBadge">{{
                    countLabel(upcoming.length, upcomingTotal)
                }}</span>
            </div>

            <p
                v-if="loading && !loaded"
                class="px-4 py-3 text-xs text-slate-500"
            >
                Leyendo las reservas…
            </p>

            <p
                v-else-if="!upcoming.length"
                class="px-4 py-3 text-xs text-slate-500"
            >
                No hay reservas por venir en esta habitación.
            </p>

            <div
                v-else
                class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
            >
                <div
                    v-for="reservation in upcoming"
                    :key="reservation.id"
                    class="flex items-center gap-3 px-4 py-2.5"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-x-2">
                            <span class="truncate text-xs font-medium">{{
                                reservation.guest_name
                            }}</span>
                            <span
                                v-if="reservation.starts_today"
                                class="shrink-0 rounded-full bg-info/10 px-2 py-0.5 text-[11px] font-medium text-info"
                                >Llega hoy</span
                            >
                        </div>
                        <p class="mt-0.5 truncate text-[11px] text-slate-500">
                            {{ reservation.code }} ·
                            {{ reservation.starts_at }} →
                            {{ reservation.ends_at }}
                        </p>
                    </div>
                    <span class="shrink-0 text-xs font-medium">{{
                        formatMoney(reservation.total_amount)
                    }}</span>
                </div>
            </div>

            <a :href="historyUrl('proximas')" :class="cardFooter">
                Ver todo en el historial
                <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
            </a>
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
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-medium">Cambios de hoy</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Movimientos del semáforo de esta habitación.
                    </p>
                </div>
                <span v-if="room.today_history.length" :class="countBadge">{{
                    countLabel(todayChanges.length, room.today_history.length)
                }}</span>
            </div>

            <div
                v-if="todayChanges.length"
                class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
            >
                <div
                    v-for="entry in todayChanges"
                    :key="entry.id"
                    class="flex items-center justify-between gap-3 px-4 py-2.5"
                >
                    <div class="min-w-0">
                        <div class="truncate text-xs font-medium">
                            {{ entry.from_label ? `${entry.from_label} → ` : ''
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

            <a :href="historyUrl('semaforo')" :class="cardFooter">
                Ver todo en el historial
                <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
            </a>
        </section>

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
