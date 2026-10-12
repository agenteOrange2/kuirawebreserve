<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';

export interface KpiItem {
    key: string;
    icon: Icon;
    /** Token del theme para el ícono y la cifra cuando pesa. */
    tone: 'primary' | 'info' | 'success' | 'warning' | 'pending' | 'danger';
    value: string | number;
    label: string;
    hint?: string;
    /** El contexto se pinta con el tono (algo vencido, algo que se debe). */
    alert?: boolean;
    /** Si es enlace a otra pantalla en vez de filtro. */
    href?: string;
}

/**
 * La franja de cifras de las pantallas de trabajo: un solo box con cuatro
 * celdas en línea (2×2 en el celular). Antes eran cuatro tarjetas altas
 * más las gráficas; ahora es una franja delgada y las gráficas viven en el
 * reporte. Cada celda filtra la lista de abajo.
 */
defineProps<{
    items: KpiItem[];
    /** La celda cuyo filtro está puesto. */
    active?: string;
}>();

const emit = defineEmits<{ select: [key: string] }>();

const iconTone: Record<KpiItem['tone'], string> = {
    primary: 'border-primary/10 bg-primary/10 text-primary',
    info: 'border-info/10 bg-info/10 text-info',
    success: 'border-success/10 bg-success/10 text-success',
    warning: 'border-warning/10 bg-warning/10 text-warning',
    pending: 'border-pending/10 bg-pending/10 text-pending',
    danger: 'border-danger/10 bg-danger/10 text-danger',
};
const hintTone: Record<KpiItem['tone'], string> = {
    primary: 'text-primary',
    info: 'text-info',
    success: 'text-success',
    warning: 'text-warning',
    pending: 'text-pending',
    danger: 'text-danger',
};
</script>

<template>
    <div
        class="box box--stacked mt-4 grid grid-cols-2 divide-slate-200/60 overflow-hidden lg:grid-cols-4 lg:divide-x dark:divide-darkmode-400"
    >
        <component
            :is="item.href ? Link : 'button'"
            v-for="(item, i) in items"
            :key="item.key"
            :type="item.href ? undefined : 'button'"
            :href="item.href"
            class="flex min-w-0 items-center gap-2.5 px-4 py-2.5 text-left transition hover:bg-slate-50 dark:hover:bg-darkmode-400/40"
            :class="[
                active === item.key
                    ? 'bg-primary/5 ring-1 ring-primary/20 ring-inset'
                    : '',
                i % 2 === 1
                    ? 'border-l border-slate-200/60 lg:border-l-0 dark:border-darkmode-400'
                    : '',
                i >= 2
                    ? 'border-t border-slate-200/60 lg:border-t-0 dark:border-darkmode-400'
                    : '',
            ]"
            @click="item.href ? undefined : emit('select', item.key)"
        >
            <div
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border"
                :class="iconTone[item.tone]"
            >
                <Lucide :icon="item.icon" class="h-3.5 w-3.5" />
            </div>
            <div class="min-w-0">
                <div class="flex items-baseline gap-1.5">
                    <span class="text-sm font-medium tabular-nums">{{
                        item.value
                    }}</span>
                    <span class="truncate text-xs text-slate-500">{{
                        item.label
                    }}</span>
                </div>
                <div
                    v-if="item.hint"
                    class="truncate text-[11px]"
                    :class="item.alert ? hintTone[item.tone] : 'text-slate-400'"
                >
                    {{ item.hint }}
                </div>
            </div>
        </component>
    </div>
</template>
