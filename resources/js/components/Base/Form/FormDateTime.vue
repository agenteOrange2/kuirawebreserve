<script lang="ts">
export default {
    inheritAttrs: false,
};
</script>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import FormDate from './FormDate.vue';
import FormTime from './FormTime.vue';

/**
 * Fecha y hora en una sola fila: el calendario del panel a la izquierda y la
 * hora a la derecha. Sustituye a <input type="datetime-local">, que cada
 * navegador dibuja distinto ("dd/mm/aaaa --:-- -----").
 *
 * Mismo contrato que el nativo: v-model en 'YYYY-MM-DDTHH:mm' y, como él,
 * solo vale cuando están las dos mitades — con media captura el valor es
 * cadena vacía, para no inventar una hora que nadie eligió.
 */
const props = withDefaults(
    defineProps<{
        modelValue?: string | null;
        /** Límites del calendario, en 'YYYY-MM-DD'. */
        min?: string | null;
        max?: string | null;
        disabled?: boolean;
        /** Minutos entre opción y opción de la lista de horas. */
        step?: number;
        /** Clases para los dos inputs (apariencia). */
        inputClass?: string;
    }>(),
    { step: 30 },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'change', value: string): void;
}>();

const date = ref('');
const time = ref('');

function split(value?: string | null) {
    const m = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/.exec(value ?? '');

    date.value = m ? m[1] : '';
    time.value = m ? m[2] : '';
}

split(props.modelValue);

const joined = computed(() =>
    date.value && time.value ? `${date.value}T${time.value}` : '',
);

function push() {
    if (joined.value === (props.modelValue ?? '')) return;

    emit('update:modelValue', joined.value);
    emit('change', joined.value);
}

// El valor de fuera manda, pero solo cuando trae algo: si el padre nos
// devuelve la cadena vacía que acabamos de emitir (media captura), borrar la
// fecha que el usuario ya eligió sería quitarle el trabajo de las manos.
watch(
    () => props.modelValue,
    (value) => {
        if (value) split(value);
    },
);
</script>

<template>
    <div class="flex flex-col gap-2 sm:flex-row">
        <FormDate
            v-model="date"
            :min="min"
            :max="max"
            :disabled="disabled"
            :clearable="false"
            :input-class="inputClass"
            class="min-w-0 sm:flex-1"
            v-bind="$attrs"
            @change="push"
        />
        <FormTime
            v-model="time"
            :disabled="disabled"
            :step="step"
            :input-class="inputClass"
            class="sm:w-32 sm:flex-none"
            @change="push"
        />
    </div>
</template>
