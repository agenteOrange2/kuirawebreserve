<script setup lang="ts">
import { computed } from 'vue';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import FolioActions from './FolioActions.vue';
import { formatMoney } from '../format';
import type { CheckoutFolio } from '../types';

/**
 * La cuenta de UNA estancia, en su propio modal.
 *
 * Se abría como acordeón dentro del tab de Historial: la lista de visitas se
 * empujaba hacia abajo y la cuenta —cuatro cifras, los consumos y los pagos—
 * quedaba embutida en un renglón. El listado es para elegir; la cuenta, para
 * leerse completa.
 */
const props = defineProps<{
    open: boolean;
    roomNumber: string;
    stay: {
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
    } | null;
    folio: CheckoutFolio | null;
    loading: boolean;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

const pending = computed(() => props.folio?.grand_pending ?? 0);
</script>

<template>
    <Dialog :open="open" size="lg" @close="emit('close')">
        <Dialog.Panel v-if="stay" class="sm:w-[94vw] lg:w-[760px]">
            <div class="flex max-h-[calc(100dvh-6rem)] flex-col">
                <div
                    class="flex items-start gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                        :class="
                            stay.active
                                ? 'border border-primary/10 bg-primary/10 text-primary'
                                : 'border border-slate-200/70 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400'
                        "
                    >
                        <Lucide
                            :icon="stay.active ? 'DoorOpen' : 'User'"
                            class="h-4 w-4"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div
                            class="flex flex-wrap items-center gap-x-2 gap-y-1"
                        >
                            <h2 class="text-base font-medium">
                                {{ stay.guest_name }}
                            </h2>
                            <span
                                v-if="stay.active"
                                class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                >Adentro</span
                            >
                            <span
                                v-if="stay.vehicle_plate"
                                class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] tracking-wider dark:bg-darkmode-400"
                                >{{ stay.vehicle_plate }}</span
                            >
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Habitación {{ roomNumber }} ·
                            {{ stay.check_in_at ?? '—' }} →
                            {{ stay.check_out_at ?? 'sigue adentro' }}
                            <template v-if="stay.rate_plan">
                                · {{ stay.rate_plan }}</template
                            >
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-darkmode-400"
                        aria-label="Cerrar"
                        @click="emit('close')"
                    >
                        <Lucide icon="X" class="h-4 w-4" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    <p v-if="loading" class="text-xs text-slate-500">
                        Abriendo la cuenta…
                    </p>

                    <template v-else-if="folio">
                        <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <div
                                class="rounded-xl border border-slate-200/70 px-3 py-2.5 dark:border-darkmode-400"
                            >
                                <dt class="text-[11px] text-slate-500">
                                    Hospedaje
                                </dt>
                                <dd class="mt-0.5 text-sm font-medium">
                                    {{ formatMoney(folio.lodging_total) }}
                                </dd>
                            </div>
                            <div
                                class="rounded-xl border border-slate-200/70 px-3 py-2.5 dark:border-darkmode-400"
                            >
                                <dt class="text-[11px] text-slate-500">
                                    Pagado
                                </dt>
                                <dd class="mt-0.5 text-sm font-medium">
                                    {{ formatMoney(folio.lodging_paid) }}
                                </dd>
                            </div>
                            <div
                                class="rounded-xl border border-slate-200/70 px-3 py-2.5 dark:border-darkmode-400"
                            >
                                <dt class="text-[11px] text-slate-500">
                                    Consumos
                                </dt>
                                <dd class="mt-0.5 text-sm font-medium">
                                    {{ formatMoney(stay.consumos_total) }}
                                </dd>
                            </div>
                            <div
                                class="rounded-xl px-3 py-2.5"
                                :class="
                                    pending > 0
                                        ? 'border border-danger/30 bg-danger/5'
                                        : 'border border-slate-200/70 dark:border-darkmode-400'
                                "
                            >
                                <dt
                                    class="text-[11px]"
                                    :class="
                                        pending > 0
                                            ? 'text-danger'
                                            : 'text-slate-500'
                                    "
                                >
                                    Saldo
                                </dt>
                                <dd
                                    class="mt-0.5 text-sm font-medium"
                                    :class="pending > 0 ? 'text-danger' : ''"
                                >
                                    {{
                                        pending > 0
                                            ? formatMoney(pending)
                                            : 'Sin saldo'
                                    }}
                                </dd>
                            </div>
                        </dl>

                        <section v-if="folio.consumption.length" class="mt-4">
                            <div
                                class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Lo que consumió
                            </div>
                            <div
                                class="mt-2 divide-y divide-slate-200/60 rounded-xl border border-slate-200/70 dark:divide-darkmode-400 dark:border-darkmode-400"
                            >
                                <div
                                    v-for="order in folio.consumption"
                                    :key="order.id"
                                    class="flex items-start gap-3 px-3.5 py-2.5 text-xs"
                                >
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate font-medium"
                                            >{{ order.summary }}</span
                                        >
                                        <span class="text-slate-500"
                                            >{{ order.created_at }} ·
                                            {{ order.method_label }}</span
                                        >
                                    </span>
                                    <span class="shrink-0 font-medium">{{
                                        formatMoney(order.total)
                                    }}</span>
                                </div>
                            </div>
                        </section>

                        <section v-if="folio.payments.length" class="mt-4">
                            <div
                                class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Lo que pagó
                            </div>
                            <div
                                class="mt-2 divide-y divide-slate-200/60 rounded-xl border border-slate-200/70 dark:divide-darkmode-400 dark:border-darkmode-400"
                            >
                                <div
                                    v-for="payment in folio.payments"
                                    :key="payment.id"
                                    class="flex items-center gap-3 px-3.5 py-2.5 text-xs"
                                >
                                    <span class="min-w-0 flex-1 truncate">
                                        <span class="font-medium">{{
                                            payment.kind_label
                                        }}</span>
                                        ·
                                        <span class="text-slate-500">{{
                                            payment.method_label
                                        }}</span>
                                    </span>
                                    <span class="shrink-0 text-slate-500">{{
                                        payment.paid_at
                                    }}</span>
                                    <span class="shrink-0 font-medium">{{
                                        formatMoney(payment.amount)
                                    }}</span>
                                </div>
                            </div>
                        </section>

                        <p
                            v-if="
                                !folio.consumption.length &&
                                !folio.payments.length
                            "
                            class="mt-4 rounded-xl border border-dashed border-slate-300/70 px-3.5 py-3 text-xs text-slate-500 dark:border-darkmode-400"
                        >
                            Esta visita no dejó consumos ni pagos registrados.
                        </p>
                    </template>

                    <p v-else class="text-xs text-slate-500">
                        No se pudo leer la cuenta de esta estancia.
                    </p>
                </div>

                <div
                    v-if="folio"
                    class="flex flex-wrap items-center gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <FolioActions :folio="folio" />
                    <a
                        v-if="stay.vehicle_id"
                        :href="`/vehiculos/${stay.vehicle_id}`"
                        class="inline-flex h-9 items-center gap-1.5 rounded-[0.5rem] border border-slate-200 px-3 text-xs text-slate-600 transition hover:border-primary/40 hover:text-primary dark:border-darkmode-400 dark:text-slate-300"
                    >
                        <Lucide icon="Car" class="h-3.5 w-3.5" />
                        Ficha del vehículo
                    </a>
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
