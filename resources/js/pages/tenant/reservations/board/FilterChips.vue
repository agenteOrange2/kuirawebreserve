<script setup lang="ts">
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';

export interface ChipOption {
    /** '' = "Todas": quita el filtro. */
    key: string;
    label: string;
    count?: number;
    icon?: Icon;
    /** El número que pesa se pinta con su tono. */
    tone?: 'danger' | 'warning' | 'pending' | 'info' | 'success';
}

/**
 * Filtros rápidos en pastillas: lo que más se busca a un toque, sin abrir
 * un select. Uno solo activo a la vez, como una pestaña.
 */
defineProps<{
    options: ChipOption[];
    modelValue: string;
    label?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const toneBadge: Record<string, string> = {
    danger: 'bg-danger/10 text-danger',
    warning: 'bg-warning/10 text-warning',
    pending: 'bg-pending/10 text-pending',
    info: 'bg-info/10 text-info',
    success: 'bg-success/10 text-success',
};
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-1.5"
        role="tablist"
        :aria-label="label"
    >
        <span
            v-if="label"
            class="mr-1 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >{{ label }}</span
        >
        <button
            v-for="option in options"
            :key="option.key || 'todas'"
            type="button"
            role="tab"
            :aria-selected="modelValue === option.key"
            class="inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition"
            :class="
                modelValue === option.key
                    ? 'border-primary/30 bg-primary/10 text-primary'
                    : 'border-slate-200 bg-white text-slate-500 hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600'
            "
            @click="emit('update:modelValue', option.key)"
        >
            <Lucide
                v-if="option.icon"
                :icon="option.icon"
                class="h-3.5 w-3.5"
            />
            {{ option.label }}
            <span
                v-if="option.count !== undefined"
                class="rounded-full px-1.5 text-[11px] tabular-nums"
                :class="
                    option.count && option.tone
                        ? toneBadge[option.tone]
                        : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                "
                >{{ option.count }}</span
            >
        </button>
    </div>
</template>
