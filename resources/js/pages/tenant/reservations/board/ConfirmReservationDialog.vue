<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';
import Button from '@/components/Base/Button';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';

export interface ConfirmTarget {
    id: number;
    code: string;
    guest_name: string | null;
    room: string | null;
    starts_at: string;
    pending_balance: number;
    paid_total: number;
    pending_transfer_request?: boolean;
}

/**
 * Confirmar un apartado sin salir de la lista (Próximas y Pendientes). Dice
 * lo que va a pasar y, si hay una transferencia esperando verificación,
 * avisa que confirmar aquí NO registra ese dinero — el incidente
 * RES-2026-0032 fue justo ese: se confirmó a mano y el pago quedó en el aire.
 */
const props = defineProps<{
    target: ConfirmTarget | null;
    holdLabel?: string;
}>();

const emit = defineEmits<{ close: [] }>();

const toast = useToasts();
const busy = ref(false);

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n || 0);

async function submit() {
    const target = props.target;
    if (!target || busy.value) return;
    busy.value = true;
    try {
        await axios.patch(`/api/reservations/${target.id}/confirm`);
        toast.success(
            'Reserva confirmada',
            `${target.code} quedó confirmada y se le avisó al huésped.`,
        );
        emit('close');
        router.reload({ preserveScroll: true } as any);
    } catch (e: any) {
        toast.error(
            'No se pudo confirmar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog size="lg" :open="target !== null" @close="emit('close')">
        <Dialog.Panel class="sm:w-[94vw] lg:w-[520px]">
            <div v-if="target" class="flex max-h-[calc(100dvh-6rem)] flex-col">
                <div
                    class="flex items-start gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="CircleCheck" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-medium">Confirmar reserva</h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ target.code }} ·
                            {{ target.guest_name ?? 'Anónimo' }} · llega
                            {{ target.starts_at }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-darkmode-400"
                        title="Cerrar"
                        @click="emit('close')"
                    >
                        <Lucide icon="X" class="h-4 w-4" />
                    </button>
                </div>
                <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-5 py-4">
                    <div
                        class="rounded-lg border border-dashed border-slate-300/70 bg-slate-50 p-3.5 text-xs text-slate-600 dark:border-darkmode-400 dark:bg-darkmode-700 dark:text-slate-300"
                    >
                        <p
                            class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            Qué va a pasar
                        </p>
                        <ul class="mt-2 list-inside list-disc space-y-1">
                            <li>
                                La habitación
                                {{ target.room ? target.room : 'asignada' }}
                                queda apartada en firme para el huésped.
                            </li>
                            <li>
                                Deja de correr el reloj del apartado{{
                                    holdLabel ? ` (${holdLabel})` : ''
                                }}: ya no se libera solo.
                            </li>
                            <li>Se le avisa al huésped por su canal.</li>
                            <li v-if="target.pending_balance > 0">
                                Sigue debiendo
                                {{ money(target.pending_balance) }}; confirmar
                                no registra ningún pago.
                            </li>
                        </ul>
                    </div>
                    <div
                        v-if="target.pending_transfer_request"
                        class="flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning/10 p-3.5 text-xs text-slate-700 dark:text-slate-200"
                    >
                        <Lucide
                            icon="TriangleAlert"
                            class="mt-0.5 h-4 w-4 shrink-0 text-warning"
                        />
                        <div class="min-w-0">
                            <p class="font-medium">
                                Hay una transferencia esperando verificación.
                            </p>
                            <p class="mt-1">
                                Si ya viste el depósito en el banco, mejor
                                apruébalo en
                                <a
                                    :href="route('tenant.payments.verify')"
                                    class="font-medium text-primary underline"
                                    >Pagos, en Verificar</a
                                >: eso registra el dinero, confirma la reserva y
                                le avisa al huésped, todo de una vez.
                            </p>
                        </div>
                    </div>
                </div>
                <div
                    class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <Button
                        type="button"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        @click="emit('close')"
                    >
                        Volver
                    </Button>
                    <Button
                        type="button"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="busy"
                        @click="submit"
                    >
                        <Lucide icon="CircleCheck" class="mr-1.5 h-3.5 w-3.5" />
                        {{ busy ? 'Confirmando…' : 'Confirmar reserva' }}
                    </Button>
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
