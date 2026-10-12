<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';

export interface CancelTarget {
    id: number;
    code: string;
    guest_name: string | null;
    room: string | null;
    paid_total: number;
}

/**
 * "No llegó" o "Cancelar" desde una lista. Las dos pasan la reserva al
 * historial y liberan la habitación; la diferencia es lo que queda escrito
 * (y cuál cuenta como no-show en los reportes). Se puede reabrir después.
 */
const props = defineProps<{
    target: CancelTarget | null;
    kind: 'cancel' | 'no_show';
}>();

const emit = defineEmits<{ close: [] }>();

const toast = useToasts();
const reason = ref('');
const busy = ref(false);

watch(
    () => props.target,
    () => (reason.value = ''),
);

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n || 0);

async function submit() {
    const target = props.target;
    if (!target || busy.value) return;
    const noShow = props.kind === 'no_show';
    busy.value = true;
    try {
        await axios.patch(`/api/reservations/${target.id}/cancel`, {
            no_show: noShow,
            reason: reason.value.trim() || null,
        });
        toast.success(
            noShow
                ? 'Se registró que el huésped no llegó'
                : 'Reserva cancelada',
            `${target.code} pasó al historial y la habitación quedó libre.`,
        );
        emit('close');
        router.reload({ preserveScroll: true } as any);
    } catch (e: any) {
        toast.error(
            'No se pudo completar la acción',
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
            <form
                v-if="target"
                class="flex max-h-[calc(100dvh-6rem)] flex-col"
                @submit.prevent="submit"
            >
                <div
                    class="flex items-start gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border"
                        :class="
                            kind === 'no_show'
                                ? 'border-warning/10 bg-warning/10 text-warning'
                                : 'border-danger/10 bg-danger/10 text-danger'
                        "
                    >
                        <Lucide
                            :icon="kind === 'no_show' ? 'UserX' : 'Ban'"
                            class="h-4 w-4"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-medium">
                            {{
                                kind === 'no_show'
                                    ? 'El huésped no llegó'
                                    : 'Cancelar reserva'
                            }}
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ target.code }} ·
                            {{ target.guest_name ?? 'Anónimo' }} · habitación
                            {{ target.room ?? 'por asignar' }}
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
                    <p class="text-xs text-slate-600 dark:text-slate-300">
                        Pasa al historial y la habitación queda libre para
                        venderse. Si fue un error, se reabre desde su ficha con
                        el mismo folio.
                    </p>
                    <p
                        v-if="target.paid_total > 0"
                        class="flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning"
                    >
                        <Lucide
                            icon="TriangleAlert"
                            class="mt-0.5 h-3.5 w-3.5 shrink-0"
                        />
                        Ya pagó {{ money(target.paid_total) }}. Si le toca
                        reembolso, regístralo desde la ficha de la reserva.
                    </p>
                    <FormInput
                        v-model="reason"
                        type="text"
                        maxlength="255"
                        class="h-9 text-xs"
                        placeholder="Motivo (queda en el historial)"
                    />
                </div>
                <div
                    class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <Button
                        type="button"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        @click="emit('close')"
                        >Volver</Button
                    >
                    <Button
                        type="submit"
                        :variant="kind === 'no_show' ? 'warning' : 'danger'"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="busy"
                    >
                        {{
                            busy
                                ? 'Guardando…'
                                : kind === 'no_show'
                                  ? 'Confirmar que no llegó'
                                  : 'Cancelar reserva'
                        }}
                    </Button>
                </div>
            </form>
        </Dialog.Panel>
    </Dialog>
</template>
