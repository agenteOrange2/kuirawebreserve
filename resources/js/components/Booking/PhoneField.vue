<script setup lang="ts">
import { computed, ref } from 'vue';
import { FormInput, FormSelect } from '@/components/Base/Form';
import { phoneError } from '@/lib/bookingFormat';

/**
 * Teléfono del huésped con su país: se manda SIEMPRE en internacional
 * (+<lada><número>), el mismo formato que el alta del panel. Avisa en
 * cuanto el número no cuadra (México y USA son 10 dígitos) en vez de dejar
 * que el servidor lo rechace o, peor, que el WhatsApp de confirmación se
 * vaya a un número equivocado.
 */
const props = defineProps<{
    id: string;
    // Mostrar el error aunque no se haya tocado (al intentar continuar).
    showErrors?: boolean;
    hint?: string;
}>();

const country = defineModel<'52' | '1' | 'otro'>('country', { required: true });
const number = defineModel<string>('number', { required: true });
const lada = defineModel<string>('lada', { required: true });

const touched = ref(false);
const error = computed(() =>
    phoneError(country.value, number.value, lada.value),
);
const visibleError = computed(() =>
    touched.value || props.showErrors ? error.value : null,
);

// Solo dígitos y espacios: el teclado de algunos celulares mete guiones.
function onNumber(value: string | number) {
    number.value = String(value ?? '').replace(/[^\d\s]/g, '');
}
</script>

<template>
    <div>
        <div class="flex flex-wrap gap-2">
            <FormSelect
                v-model="country"
                class="h-11 w-auto shrink-0 text-sm"
                aria-label="País del teléfono"
            >
                <option value="52">México +52</option>
                <option value="1">USA / Canadá +1</option>
                <option value="otro">Otro país</option>
            </FormSelect>
            <FormInput
                v-if="country === 'otro'"
                v-model="lada"
                type="tel"
                inputmode="numeric"
                class="h-11 w-24 shrink-0 text-sm"
                placeholder="Lada"
                aria-label="Lada internacional"
            />
            <FormInput
                :id="id"
                :model-value="number"
                type="tel"
                inputmode="numeric"
                autocomplete="tel-national"
                class="h-11 min-w-32 flex-1 text-sm"
                :class="visibleError ? 'border-danger' : ''"
                :placeholder="country === 'otro' ? 'Número' : '10 dígitos'"
                @update:model-value="onNumber"
                @blur="touched = true"
            />
        </div>
        <p v-if="visibleError" class="mt-1.5 text-xs text-danger">
            {{ visibleError }}
        </p>
        <p v-else-if="hint" class="mt-1.5 text-xs text-slate-500">
            {{ hint }}
        </p>
    </div>
</template>
