<script setup lang="ts">
import Lucide from '@/components/Base/Lucide';

// Contador − n + de 44px para el dedo. El tope lo pone quien lo usa (la
// ocupación real del cuarto, el cupo de la sesión): el + se apaga ahí en
// vez de dejar subir y regañar después.
const props = withDefaults(
    defineProps<{
        modelValue: number;
        min?: number;
        max?: number;
        label?: string;
        size?: 'md' | 'sm';
    }>(),
    { min: 0, max: 99, label: 'cantidad', size: 'md' },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: number): void }>();

function set(n: number) {
    emit('update:modelValue', Math.max(props.min, Math.min(props.max, n)));
}
</script>

<template>
    <div
        class="flex shrink-0 items-center gap-1.5"
        role="group"
        :aria-label="label"
    >
        <button
            type="button"
            class="flex items-center justify-center rounded-full border border-slate-200 bg-slate-100 text-slate-600 transition hover:border-slate-300 disabled:cursor-not-allowed disabled:opacity-50"
            :class="size === 'sm' ? 'h-9 w-9' : 'h-11 w-11'"
            :disabled="modelValue <= min"
            :aria-label="`Quitar ${label}`"
            @click="set(modelValue - 1)"
        >
            <Lucide icon="Minus" class="h-3.5 w-3.5" />
        </button>
        <span
            class="w-8 text-center text-base font-medium text-slate-800"
            aria-live="polite"
            >{{ modelValue }}</span
        >
        <button
            type="button"
            class="flex items-center justify-center rounded-full border border-slate-200 bg-slate-100 text-slate-600 transition hover:border-slate-300 disabled:cursor-not-allowed disabled:opacity-50"
            :class="size === 'sm' ? 'h-9 w-9' : 'h-11 w-11'"
            :disabled="modelValue >= max"
            :aria-label="`Agregar ${label}`"
            @click="set(modelValue + 1)"
        >
            <Lucide icon="Plus" class="h-3.5 w-3.5" />
        </button>
    </div>
</template>
