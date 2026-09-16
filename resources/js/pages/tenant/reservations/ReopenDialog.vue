<script setup lang="ts">
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormDateTime, FormLabel } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';

/**
 * Reabrir (y reagendar) una reserva cancelada o un "no llegó", con su MISMO
 * código. Lo usan /reservas y el historial paginado.
 *
 * Caso real cabañas 2026-09-10: el apartado venció mientras el huésped
 * depositaba; el personal le dio su código por chat, pero la reserva seguía
 * cancelada y no había manera de revivirla — solo de hacer otra.
 */
const props = defineProps<{
    reservation: {
        id: number;
        code: string;
        guest_name: string | null;
        room: string | null;
        starts_at_input: string;
        ends_at_input: string;
        cancellation_reason: string | null;
        paid_total: number;
    } | null;
    /** Plazo del apartado del hotel, para decir cuánto dura si no se confirma. */
    holdMinutes: number;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'done'): void;
}>();

const toast = useToasts();

const start = ref('');
const end = ref('');
const confirmed = ref(true);
const busy = ref(false);

watch(
    () => props.reservation,
    (reservation) => {
        if (!reservation) return;

        start.value = reservation.starts_at_input;
        end.value = reservation.ends_at_input;
        // Si ya pagó algo, lo normal es devolverla confirmada; si no, como
        // apartado con su plazo. Quien atiende lo cambia aquí mismo.
        confirmed.value = Number(reservation.paid_total ?? 0) > 0;
    },
    { immediate: true },
);

const holdLabel = computed(() => {
    const minutes = props.holdMinutes;
    if (minutes < 60) return `${minutes} minuto${minutes === 1 ? '' : 's'}`;
    if (minutes % 1440 === 0) {
        const days = minutes / 1440;
        return `${days} día${days === 1 ? '' : 's'}`;
    }
    if (minutes % 60 === 0) {
        const hours = minutes / 60;
        return `${hours} hora${hours === 1 ? '' : 's'}`;
    }
    return `${minutes} minutos`;
});

/** Una llegada que ya pasó no se revive tal cual: hay que reagendar. */
const startPast = computed(() => {
    if (!start.value) return false;

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return new Date(start.value) < today;
});

async function submit() {
    const reservation = props.reservation;
    if (!reservation || busy.value) return;

    const rescheduled =
        start.value !== reservation.starts_at_input ||
        end.value !== reservation.ends_at_input;

    busy.value = true;
    try {
        await axios.patch(`/api/reservations/${reservation.id}/reopen`, {
            ...(rescheduled
                ? { starts_at: start.value, ends_at: end.value || null }
                : {}),
            confirmed: confirmed.value,
        });
        toast.success(
            rescheduled
                ? 'Reserva reabierta y reagendada'
                : 'Reserva reabierta',
            `${reservation.code} volvió ${confirmed.value ? 'confirmada' : `como apartado por ${holdLabel.value}`}.`,
        );
        emit('done');
    } catch (error: any) {
        toast.error(
            'No se pudo reabrir la reserva',
            error.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog size="lg" :open="reservation !== null" @close="emit('close')">
        <Dialog.Panel class="sm:w-[94vw] lg:w-[640px]">
            <form
                v-if="reservation"
                class="flex max-h-[calc(100dvh-6rem)] flex-col"
                @submit.prevent="submit"
            >
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="RotateCcw" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-medium">Reabrir reserva</h2>
                        <p class="mt-0.5 truncate text-xs text-slate-500">
                            {{ reservation.code }} ·
                            {{ reservation.guest_name ?? 'Anónimo' }} · Hab.
                            {{ reservation.room ?? 'por asignar' }}
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

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4">
                    <div
                        class="rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3.5 py-3 text-xs text-slate-600 dark:border-darkmode-400 dark:bg-darkmode-700 dark:text-slate-300"
                    >
                        Vuelve con el mismo código
                        <span class="font-medium">{{ reservation.code }}</span
                        >. Se revisa que la habitación siga libre; si ya se
                        vendió, se asigna otra del mismo tipo. Los pagos que ya
                        tenía se conservan.
                        <span
                            v-if="reservation.cancellation_reason"
                            class="mt-1.5 block text-slate-500"
                            >Se canceló por:
                            {{ reservation.cancellation_reason }}</span
                        >
                    </div>

                    <section>
                        <div
                            class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            Fechas
                        </div>
                        <div class="mt-2 grid gap-3 sm:grid-cols-2">
                            <div>
                                <FormLabel
                                    htmlFor="reopen-start"
                                    class="text-xs"
                                    >Llegada</FormLabel
                                >
                                <FormDateTime
                                    id="reopen-start"
                                    v-model="start"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="reopen-end" class="text-xs"
                                    >Salida</FormLabel
                                >
                                <FormDateTime id="reopen-end" v-model="end" />
                            </div>
                        </div>
                        <p
                            v-if="startPast"
                            class="mt-2 flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2.5 text-xs text-slate-700 dark:text-slate-200"
                        >
                            <Lucide
                                icon="TriangleAlert"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning"
                            />
                            La llegada ya pasó: elige fechas nuevas para
                            reagendarla.
                        </p>
                        <p v-else class="mt-2 text-[11px] text-slate-400">
                            Cambia las fechas para reagendarla; con fechas
                            nuevas el total se recalcula con su tarifa.
                        </p>
                    </section>

                    <section>
                        <div
                            class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            Cómo queda
                        </div>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <button
                                type="button"
                                class="rounded-lg border px-3.5 py-3 text-left text-xs transition"
                                :class="
                                    confirmed
                                        ? 'border-primary/40 bg-primary/5'
                                        : 'border-slate-200/70 hover:border-primary/30 dark:border-darkmode-400'
                                "
                                @click="confirmed = true"
                            >
                                <span
                                    class="flex items-center gap-1.5 font-medium"
                                    :class="confirmed ? 'text-primary' : ''"
                                >
                                    <Lucide
                                        icon="CircleCheck"
                                        class="h-3.5 w-3.5"
                                    />
                                    Confirmada
                                </span>
                                <span class="mt-1 block text-slate-500"
                                    >La habitación queda apartada para el
                                    huésped y se le avisa.</span
                                >
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border px-3.5 py-3 text-left text-xs transition"
                                :class="
                                    !confirmed
                                        ? 'border-primary/40 bg-primary/5'
                                        : 'border-slate-200/70 hover:border-primary/30 dark:border-darkmode-400'
                                "
                                @click="confirmed = false"
                            >
                                <span
                                    class="flex items-center gap-1.5 font-medium"
                                    :class="!confirmed ? 'text-primary' : ''"
                                >
                                    <Lucide icon="Clock" class="h-3.5 w-3.5" />
                                    Apartado
                                </span>
                                <span class="mt-1 block text-slate-500"
                                    >Vence en {{ holdLabel }} si nadie la
                                    confirma o paga.</span
                                >
                            </button>
                        </div>
                    </section>
                </div>

                <div
                    class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <Button
                        type="button"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        @click="emit('close')"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="busy || startPast"
                    >
                        <Lucide icon="RotateCcw" class="mr-1.5 h-3.5 w-3.5" />
                        {{ busy ? 'Reabriendo…' : 'Reabrir' }}
                    </Button>
                </div>
            </form>
        </Dialog.Panel>
    </Dialog>
</template>
