<script setup lang="ts">
import axios from 'axios';
import { computed, inject, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import { FloorPlanKey } from '../../context';
import { formatMoney } from '../../format';
import FolioActions from '../FolioActions.vue';
import type { CheckoutFolio } from '../../types';

/**
 * Historial: quién ha pasado por este cuarto y qué se movió hoy.
 *
 * Cada estancia se abre —igual que el resumen del huésped que está adentro—
 * con su entrada y salida, su tarifa, lo que consumió y lo que pagó. Antes
 * solo se veían los cambios de estado del día, que no dicen quién estuvo ni
 * cuánto dejó: para revisar una visita había que salir a otra pantalla.
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

/** Estancia abierta y su cuenta (la del folio, que ya existe). */
const openStayId = ref<number | null>(null);
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

async function toggleStay(stay: StayRow) {
    if (openStayId.value === stay.id) {
        openStayId.value = null;

        return;
    }

    openStayId.value = stay.id;
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
        openStayId.value = null;
    } finally {
        detailLoading.value = false;
    }
}

// Cambiar de cuarto vuelve a pedir: el historial es del cuarto abierto.
watch(
    () => room.value.id,
    () => {
        stays.value = [];
        upcoming.value = [];
        loaded.value = false;
        openStayId.value = null;
        detail.value = null;
        void loadStays();
    },
    { immediate: true },
);
</script>

<template>
    <div class="space-y-5">
        <!-- Lo que viene: sirve para saber hasta cuándo se puede extender a
             quien está adentro sin pisar a nadie. -->
        <section
            v-if="upcoming.length"
            class="rounded-2xl border border-info/20 bg-info/5 p-4 dark:border-info/30 dark:bg-info/10"
        >
            <h3
                class="text-base font-semibold text-slate-900 dark:text-slate-100"
            >
                Lo que viene
            </h3>
            <p class="mt-1 text-sm text-slate-500">
                Reservas vivas para esta habitación.
            </p>
            <div class="mt-3 space-y-2">
                <div
                    v-for="reservation in upcoming"
                    :key="reservation.id"
                    class="flex items-start justify-between gap-3 rounded-xl bg-white/90 p-3 text-sm dark:bg-darkmode-600/80"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-x-2">
                            <span class="font-medium">{{
                                reservation.guest_name
                            }}</span>
                            <span class="text-xs text-slate-500">{{
                                reservation.code
                            }}</span>
                            <span
                                v-if="reservation.starts_today"
                                class="rounded-full bg-info/15 px-2 py-0.5 text-xs font-medium text-info"
                                >Llega hoy</span
                            >
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ reservation.starts_at }} →
                            {{ reservation.ends_at }} ·
                            {{ reservation.status_label }}
                        </p>
                    </div>
                    <span class="shrink-0 font-medium">{{
                        formatMoney(reservation.total_amount)
                    }}</span>
                </div>
            </div>
        </section>

        <section
            class="rounded-2xl border border-slate-200/70 p-4 dark:border-darkmode-400"
        >
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h3
                        class="text-base font-semibold text-slate-900 dark:text-slate-100"
                    >
                        Quién ha estado aquí
                    </h3>
                    <p class="mt-1 text-sm text-slate-500">
                        Toca una estancia para ver su cuenta y lo que consumió.
                    </p>
                </div>
                <Button
                    as="a"
                    :href="route('tenant.rooms.history', room.id)"
                    variant="outline-secondary"
                    class="min-h-10 shrink-0 rounded-[0.5rem]"
                >
                    <Lucide icon="History" class="mr-2 h-4 w-4" />
                    Historial completo
                </Button>
            </div>

            <p v-if="loading && !loaded" class="mt-4 text-sm text-slate-500">
                Leyendo el historial…
            </p>

            <p v-else-if="!stays.length" class="mt-4 text-sm text-slate-500">
                Todavía no hay estancias registradas en esta habitación.
            </p>

            <div v-else class="mt-3 space-y-2">
                <div
                    v-for="stay in stays"
                    :key="stay.id"
                    class="rounded-xl border border-slate-200/70 dark:border-darkmode-400"
                    :class="
                        openStayId === stay.id
                            ? 'border-primary/30 bg-primary/5 dark:border-primary/30 dark:bg-primary/10'
                            : ''
                    "
                >
                    <button
                        type="button"
                        class="flex w-full items-start gap-3 p-3 text-left"
                        :title="
                            ctx.canViewStays
                                ? 'Ver la cuenta de esta estancia'
                                : 'Tu usuario no puede ver cuentas de estancias'
                        "
                        :disabled="!ctx.canViewStays"
                        @click="toggleStay(stay)"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                            :class="
                                stay.active
                                    ? 'bg-primary/10 text-primary'
                                    : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                            "
                        >
                            <Lucide
                                :icon="stay.active ? 'DoorOpen' : 'User'"
                                class="h-4 w-4"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2">
                                <span class="font-medium">{{
                                    stay.guest_name
                                }}</span>
                                <span
                                    v-if="stay.vehicle_plate"
                                    class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs tracking-wider dark:bg-darkmode-400"
                                    >{{ stay.vehicle_plate }}</span
                                >
                                <span
                                    v-if="stay.active"
                                    class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                                    >Adentro</span
                                >
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ stay.check_in_at ?? '—' }} →
                                {{ stay.check_out_at ?? 'sigue adentro' }}
                                <template v-if="stay.rate_plan">
                                    · {{ stay.rate_plan }}</template
                                >
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="font-medium">
                                {{ formatMoney(stay.amount) }}
                            </div>
                            <div
                                v-if="stay.consumos_total > 0"
                                class="text-xs text-slate-500"
                            >
                                +{{ formatMoney(stay.consumos_total) }} consumo
                            </div>
                        </div>
                        <Lucide
                            v-if="ctx.canViewStays"
                            :icon="
                                openStayId === stay.id
                                    ? 'ChevronUp'
                                    : 'ChevronDown'
                            "
                            class="mt-1 h-4 w-4 shrink-0 text-slate-400"
                        />
                    </button>

                    <!-- La cuenta de esa visita: lo mismo que se ve del huésped
                         que está adentro, pero de una estancia ya cerrada. -->
                    <div
                        v-if="openStayId === stay.id"
                        class="border-t border-slate-200/70 px-3 py-3 dark:border-darkmode-400"
                    >
                        <p v-if="detailLoading" class="text-sm text-slate-500">
                            Abriendo la cuenta…
                        </p>

                        <template v-else-if="detail">
                            <dl
                                class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4"
                            >
                                <div
                                    class="rounded-lg bg-white/80 p-2.5 dark:bg-darkmode-600/80"
                                >
                                    <dt class="text-xs text-slate-500">
                                        Hospedaje
                                    </dt>
                                    <dd class="mt-0.5 font-medium">
                                        {{ formatMoney(detail.lodging_total) }}
                                    </dd>
                                </div>
                                <div
                                    class="rounded-lg bg-white/80 p-2.5 dark:bg-darkmode-600/80"
                                >
                                    <dt class="text-xs text-slate-500">
                                        Pagado
                                    </dt>
                                    <dd class="mt-0.5 font-medium">
                                        {{ formatMoney(detail.lodging_paid) }}
                                    </dd>
                                </div>
                                <div
                                    class="rounded-lg bg-white/80 p-2.5 dark:bg-darkmode-600/80"
                                >
                                    <dt class="text-xs text-slate-500">
                                        Consumos
                                    </dt>
                                    <dd class="mt-0.5 font-medium">
                                        {{ formatMoney(stay.consumos_total) }}
                                    </dd>
                                </div>
                                <div
                                    class="rounded-lg p-2.5"
                                    :class="
                                        detail.grand_pending > 0
                                            ? 'bg-danger/10'
                                            : 'bg-white/80 dark:bg-darkmode-600/80'
                                    "
                                >
                                    <dt
                                        class="text-xs"
                                        :class="
                                            detail.grand_pending > 0
                                                ? 'text-danger'
                                                : 'text-slate-500'
                                        "
                                    >
                                        Saldo
                                    </dt>
                                    <dd
                                        class="mt-0.5 font-medium"
                                        :class="
                                            detail.grand_pending > 0
                                                ? 'text-danger'
                                                : ''
                                        "
                                    >
                                        {{
                                            detail.grand_pending > 0
                                                ? formatMoney(
                                                      detail.grand_pending,
                                                  )
                                                : 'Sin saldo'
                                        }}
                                    </dd>
                                </div>
                            </dl>

                            <div v-if="detail.consumption.length" class="mt-3">
                                <div
                                    class="mb-1 text-xs font-medium text-slate-500"
                                >
                                    Lo que consumió
                                </div>
                                <div
                                    v-for="order in detail.consumption"
                                    :key="order.id"
                                    class="flex items-start gap-3 py-1 text-sm"
                                >
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate">{{
                                            order.summary
                                        }}</span>
                                        <span class="text-xs text-slate-500"
                                            >{{ order.created_at }} ·
                                            {{ order.method_label }}</span
                                        >
                                    </span>
                                    <span class="shrink-0">{{
                                        formatMoney(order.total)
                                    }}</span>
                                </div>
                            </div>

                            <div v-if="detail.payments.length" class="mt-3">
                                <div
                                    class="mb-1 text-xs font-medium text-slate-500"
                                >
                                    Lo que pagó
                                </div>
                                <div
                                    v-for="payment in detail.payments"
                                    :key="payment.id"
                                    class="flex items-center gap-3 py-1 text-sm"
                                >
                                    <span class="min-w-0 flex-1 truncate">
                                        {{ payment.kind_label }} ·
                                        <span class="text-slate-500">{{
                                            payment.method_label
                                        }}</span>
                                    </span>
                                    <span
                                        class="shrink-0 text-xs text-slate-500"
                                        >{{ payment.paid_at }}</span
                                    >
                                    <span class="shrink-0 font-medium">{{
                                        formatMoney(payment.amount)
                                    }}</span>
                                </div>
                            </div>

                            <p
                                v-if="
                                    !detail.consumption.length &&
                                    !detail.payments.length
                                "
                                class="text-sm text-slate-500"
                            >
                                Esta visita no dejó consumos ni pagos
                                registrados.
                            </p>

                            <div class="mt-3 flex flex-wrap items-center gap-3">
                                <FolioActions :folio="detail" />
                                <a
                                    v-if="stay.vehicle_id"
                                    :href="`/vehiculos/${stay.vehicle_id}`"
                                    class="inline-flex items-center gap-2 text-sm text-primary hover:underline"
                                >
                                    <Lucide icon="Car" class="h-4 w-4" />
                                    Ver la ficha del vehículo
                                </a>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </section>

        <section
            class="rounded-2xl border border-slate-200/70 p-4 dark:border-darkmode-400"
        >
            <h3
                class="text-base font-semibold text-slate-900 dark:text-slate-100"
            >
                Cambios de hoy
            </h3>
            <p class="mt-1 text-sm text-slate-500">
                Movimientos de estado registrados para esta habitación.
            </p>

            <div v-if="room.today_history.length" class="mt-4 space-y-2">
                <div
                    v-for="entry in room.today_history"
                    :key="entry.id"
                    class="flex items-start justify-between gap-3 rounded-xl bg-slate-50 p-3 text-sm dark:bg-darkmode-700/50"
                >
                    <div class="min-w-0">
                        <div class="font-medium">
                            {{ entry.from_label ? `${entry.from_label} → ` : ''
                            }}{{ entry.to_label }}
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            {{
                                entry.auto
                                    ? 'Sistema'
                                    : (entry.changed_by ?? 'Sistema')
                            }}
                        </div>
                    </div>
                    <div class="text-xs text-slate-500">
                        {{ entry.created_at ?? '—' }}
                    </div>
                </div>
            </div>
            <p v-else class="mt-4 text-sm text-slate-500">
                Sin cambios registrados hoy.
            </p>
        </section>
    </div>
</template>
