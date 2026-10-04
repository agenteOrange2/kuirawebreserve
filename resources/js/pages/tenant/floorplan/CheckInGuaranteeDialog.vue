<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import {
    type CounterMethod,
    useCounterMethods,
} from '@/composables/useCounterMethods';
import { formatMoney } from './format';
import PlanModal from './PlanModal.vue';
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
            method: CounterMethod;
            amount: number | null;
            reason: string | null;
            reference: string | null;
        },
    ): void;
}>();

// Lo que acepte la recepción de este hotel (/ajustes/metodos-pago →
// Políticas). La fianza estuvo cableada a efectivo y terminal "porque se
// recibe en la mano", y eso dejaba fuera al hotel que sí cobra depósitos por
// transferencia. La transferencia pide folio: es lo único con lo que se puede
// devolver el dinero días después.
const { methods, first } = useCounterMethods();

const method = ref<CounterMethod>('cash');
const editing = ref(false);
const amountInput = ref<number | string>(0);
const reason = ref('');
const reference = ref('');

// El cuarto que se ve: se queda el último mientras el modal se cierra, para
// que no desaparezca de golpe cuando el plano suelta la selección.
const shown = ref<RoomData | null>(props.room);
watch(
    () => props.room,
    (room) => {
        if (room) {
            shown.value = room;
        }
    },
);

// Cada apertura empieza limpia: arrastrar el ajuste de la llegada anterior
// es cómo se le cobra a alguien el monto que negoció el de antes.
watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        method.value = first.value;
        editing.value = false;
        amountInput.value = props.amount;
        reason.value = '';
        reference.value = '';
    },
    { immediate: true },
);

const adjusted = computed(
    () =>
        editing.value &&
        Math.round(Number(amountInput.value || 0) * 100) !==
            Math.round(props.amount * 100),
);

const needsReference = computed(() => method.value === 'transfer');

const blocked = computed(
    () =>
        (adjusted.value && !reason.value.trim()) ||
        (needsReference.value && !reference.value.trim()),
);

function confirm() {
    if (blocked.value) {
        return;
    }

    emit('confirm', {
        method: method.value,
        amount: adjusted.value ? Number(amountInput.value || 0) : null,
        reason: adjusted.value ? reason.value.trim() : null,
        reference: needsReference.value ? reference.value.trim() : null,
    });
}
</script>

<template>
    <PlanModal
        :open="open"
        icon="ShieldCheck"
        :title="`Registrar la llegada de la ${shown?.number ?? ''}`"
        :subtitle="shown?.upcoming_reservation?.guest_name ?? 'Anónimo'"
        panel-class="sm:w-[480px]"
        @close="emit('close')"
    >
        <div
            class="rounded-lg border border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
        >
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium">
                    Fianza {{ formatMoney(amount) }}
                </span>
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
                Depósito en garantía: se cobra ahora y se devuelve al registrar
                la salida. No cuenta como pago del hospedaje.
            </p>

            <!-- Ajuste a mano, con motivo obligatorio: quien devuelva ese
                 dinero días después solo va a tener esta nota. -->
            <div
                v-if="editing"
                class="mt-3 space-y-2 rounded-lg bg-slate-50 p-3 dark:bg-darkmode-700"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <label for="guarantee-amount" class="text-xs text-slate-500"
                        >Cobrar</label
                    >
                    <div class="relative w-32">
                        <Lucide
                            icon="DollarSign"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                        />
                        <FormInput
                            id="guarantee-amount"
                            v-model="amountInput"
                            type="number"
                            step="0.01"
                            min="0"
                            class="h-9 pl-9 text-xs"
                        />
                    </div>
                    <button
                        type="button"
                        class="ml-auto text-xs font-medium text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
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
                    class="h-9 text-xs"
                    placeholder="Motivo del ajuste (queda en el registro del pago)"
                />
                <p
                    v-if="adjusted && !reason.trim()"
                    class="text-xs text-danger"
                >
                    Sin motivo no se puede cobrar un monto distinto al de la
                    política.
                </p>
            </div>
        </div>

        <div v-if="methods.length">
            <div
                class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                Cómo la recibiste
            </div>
            <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                <button
                    v-for="m in methods"
                    :key="m.key"
                    type="button"
                    class="flex h-9 items-center justify-center gap-2 rounded-[0.5rem] border text-xs font-medium transition"
                    :class="
                        method === m.key
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'border-slate-200/70 text-slate-500 hover:bg-slate-50 dark:border-darkmode-400 dark:hover:bg-darkmode-400'
                    "
                    @click="method = m.key"
                >
                    {{ m.short }}
                </button>
            </div>

            <!-- El efectivo sale del cajón; una transferencia hay que
                 regresarla a una cuenta días después, y el folio es lo único
                 con lo que se puede hacer. -->
            <div v-if="needsReference" class="mt-3">
                <FormInput
                    v-model="reference"
                    type="text"
                    class="h-9 text-xs"
                    placeholder="Folio o referencia del comprobante"
                />
                <p v-if="!reference.trim()" class="mt-1 text-xs text-slate-500">
                    Sin el folio, esta fianza no se va a poder devolver.
                </p>
            </div>
        </div>

        <template #footer>
            <Button
                variant="outline-secondary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                @click="emit('close')"
            >
                Cancelar
            </Button>
            <Button
                variant="primary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="blocked"
                @click="confirm"
            >
                <Lucide icon="LogIn" class="mr-1.5 h-3.5 w-3.5" />
                Registrar llegada
            </Button>
        </template>
    </PlanModal>
</template>
