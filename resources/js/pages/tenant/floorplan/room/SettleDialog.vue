<script setup lang="ts">
import { computed, inject, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import { useCounterMethods } from '@/composables/useCounterMethods';
import type { CounterMethod } from '@/composables/useCounterMethods';
import { FloorPlanKey } from '../context';
import { formatMoney } from '../format';
import MethodPicker from '../MethodPicker.vue';
import PlanModal from '../PlanModal.vue';

/**
 * Cobrar el saldo con el huésped adentro: lo que quedó de extender, la
 * persona extra o los consumos a la cuenta, sin registrar la salida. Antes
 * el único lugar para cobrarlo era el diálogo de salida.
 *
 * Vive DENTRO del modal de habitación (HeadlessUI lo trata como anidado).
 */
const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ (e: 'close'): void }>();

const ctx = inject(FloorPlanKey)!;
const { first } = useCounterMethods();

const method = ref<CounterMethod>(first.value);
const reference = ref('');
const busy = computed(() => ctx.busyAction.value === 'settle');

const folio = computed(() => ctx.folio.value);
const lodging = computed(() => folio.value?.lodging_pending ?? 0);
const consumption = computed(() => folio.value?.consumption_pending ?? 0);
const total = computed(
    () =>
        folio.value?.grand_pending ??
        ctx.room.value?.active_stay?.balance_due ??
        0,
);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        method.value = first.value;
        reference.value = '';
        // La cuenta del momento, no la de cuando se abrió el cuarto.
        ctx.reloadFolio();
    },
);

async function submit() {
    if (busy.value || total.value <= 0) return;

    const ok = await ctx.settleStay({
        payment_method: method.value,
        reference: reference.value.trim() || null,
    });

    if (ok) emit('close');
}
</script>

<template>
    <PlanModal
        :open="open"
        icon="Banknote"
        tone="success"
        title="Cobrar saldo"
        subtitle="Sin registrar la salida: el huésped sigue en la habitación."
        panel-class="sm:w-[480px]"
        :busy="busy"
        @close="emit('close')"
    >
        <div
            class="overflow-hidden rounded-lg border border-slate-200/70 text-xs dark:border-darkmode-400"
        >
            <div class="flex items-center justify-between px-3.5 py-2.5">
                <span class="text-slate-500">Hospedaje pendiente</span>
                <span class="font-medium">{{ formatMoney(lodging) }}</span>
            </div>
            <div
                v-if="consumption > 0"
                class="flex items-center justify-between border-t border-slate-200/70 px-3.5 py-2.5 dark:border-darkmode-400"
            >
                <span class="text-slate-500">Consumos a la cuenta</span>
                <span class="font-medium">{{ formatMoney(consumption) }}</span>
            </div>
            <div
                class="flex items-center justify-between border-t border-slate-200/70 bg-slate-50/70 px-3.5 py-2.5 dark:border-darkmode-400 dark:bg-darkmode-600"
            >
                <span class="font-medium">Por cobrar</span>
                <span class="text-sm font-medium text-success">{{
                    formatMoney(total)
                }}</span>
            </div>
        </div>

        <div>
            <div
                class="mb-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                Con qué paga
            </div>
            <MethodPicker
                v-model:method="method"
                v-model:reference="reference"
                :disabled="busy"
            />
        </div>

        <template #footer>
            <Button
                variant="outline-secondary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="busy"
                @click="emit('close')"
                >Cancelar</Button
            >
            <Button
                variant="primary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="busy || total <= 0 || ctx.folioLoading.value"
                @click="submit"
            >
                <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                {{ busy ? 'Cobrando…' : `Cobrar ${formatMoney(total)}` }}
            </Button>
        </template>
    </PlanModal>
</template>
