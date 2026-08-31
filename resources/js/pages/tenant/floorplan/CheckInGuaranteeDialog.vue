<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useCounterMethods } from '@/composables/useCounterMethods';
import { formatMoney } from './format';
import type { RoomData } from './types';

/**
 * Llegada de una reserva DESDE EL PLANO cuando el hotel cobra fianza.
 *
 * El botón de check-in del plano mandaba el check-in sin cuerpo, así que la
 * fianza no se cobraba: la misma llegada registrada desde /reservas sí la
 * cobraba y desde el plano no. Como el plano es el centro de trabajo del
 * mostrador, en la práctica el depósito se perdía casi siempre.
 *
 * Sin fianza activa este diálogo ni se abre — el plano registra la llegada
 * directo, como toda la vida.
 */
const props = defineProps<{
    open: boolean;
    room: RoomData | null;
    /** Fianza que le toca a ESTA reserva (con el escalón de su grupo). */
    amount: number;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (
        e: 'confirm',
        payload: {
            method: 'cash' | 'card';
            amount: number | null;
            reason: string | null;
        },
    ): void;
}>();

// La fianza se recibe en la mano: efectivo o terminal, nunca transferencia.
const { subset } = useCounterMethods();
const methods = subset(['cash', 'card']);

const method = ref<'cash' | 'card'>('cash');
const editing = ref(false);
const amountInput = ref<number | string>(0);
const reason = ref('');

// Cada apertura empieza limpia: arrastrar el ajuste de la llegada anterior
// es cómo se le cobra a alguien el monto que negoció el de antes.
watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        method.value = (methods.value[0]?.key ?? 'cash') as 'cash' | 'card';
        editing.value = false;
        amountInput.value = props.amount;
        reason.value = '';
    },
    { immediate: true },
);

const adjusted = computed(
    () =>
        editing.value &&
        Math.round(Number(amountInput.value || 0) * 100) !==
            Math.round(props.amount * 100),
);

const blocked = computed(() => adjusted.value && !reason.value.trim());

function confirm() {
    if (blocked.value) {
        return;
    }

    emit('confirm', {
        method: method.value,
        amount: adjusted.value ? Number(amountInput.value || 0) : null,
        reason: adjusted.value ? reason.value.trim() : null,
    });
}
</script>

<template>
    <Dialog :open="open" @close="emit('close')">
        <Dialog.Panel v-if="room">
            <div
                class="flex items-center gap-3.5 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                >
                    <Lucide icon="ShieldCheck" class="h-5 w-5 text-primary" />
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-medium">
                        Registrar la llegada de la {{ room.number }}
                    </h2>
                    <p class="mt-0.5 truncate text-xs text-slate-500">
                        {{ room.upcoming_reservation?.guest_name ?? 'Anónimo' }}
                    </p>
                </div>
            </div>

            <div class="space-y-4 px-5 py-4">
                <div
                    class="rounded-xl border border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                >
                    <div
                        class="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200"
                    >
                        Fianza: {{ formatMoney(amount) }}
                        <button
                            v-if="!editing"
                            type="button"
                            class="ml-auto text-xs font-medium text-primary hover:underline"
                            @click="editing = true"
                        >
                            Cobrar otro monto
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Depósito en garantía: se cobra ahora y se devuelve al
                        registrar la salida. No cuenta como pago del hospedaje.
                    </p>

                    <!-- Ajuste a mano, con motivo obligatorio: quien devuelva
                         ese dinero días después solo va a tener esta nota. -->
                    <div
                        v-if="editing"
                        class="mt-3 rounded-lg bg-slate-50 p-3 dark:bg-darkmode-700"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs text-slate-500">Cobrar</span>
                            <span class="text-xs text-slate-500">$</span>
                            <FormInput
                                v-model="amountInput"
                                type="number"
                                step="0.01"
                                min="0"
                                class="!w-28 text-center"
                            />
                            <button
                                type="button"
                                class="ml-auto text-xs font-medium text-slate-400 hover:text-slate-600"
                                @click="
                                    editing = false;
                                    amountInput = amount;
                                    reason = '';
                                "
                            >
                                Usar {{ formatMoney(amount) }}
                            </button>
                        </div>
                        <FormInput
                            v-if="adjusted"
                            v-model="reason"
                            type="text"
                            maxlength="255"
                            class="mt-2"
                            placeholder="Motivo del ajuste (queda en el registro del pago)"
                        />
                        <p v-if="blocked" class="mt-1 text-xs text-danger">
                            Sin motivo no se puede cobrar un monto distinto al
                            de la política.
                        </p>
                    </div>
                </div>

                <div v-if="methods.length">
                    <div class="text-xs font-medium text-slate-500">
                        ¿Cómo la recibiste?
                    </div>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <button
                            v-for="m in methods"
                            :key="m.key"
                            type="button"
                            class="flex min-h-11 items-center justify-center gap-2 rounded-lg border py-2 text-sm font-medium transition"
                            :class="
                                method === m.key
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-slate-200/70 text-slate-500 hover:bg-slate-50 dark:border-darkmode-400'
                            "
                            @click="method = m.key as 'cash' | 'card'"
                        >
                            {{ m.label }}
                        </button>
                    </div>
                </div>
            </div>

            <div
                class="flex justify-end gap-3 border-t border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
            >
                <Button
                    variant="outline-secondary"
                    class="min-h-11 px-5"
                    @click="emit('close')"
                >
                    Volver
                </Button>
                <Button
                    variant="primary"
                    class="min-h-11 px-5"
                    :disabled="blocked"
                    @click="confirm"
                >
                    <Lucide icon="LogIn" class="mr-1.5 h-3.5 w-3.5" />
                    Registrar llegada
                </Button>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
