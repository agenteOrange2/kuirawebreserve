<script setup lang="ts">
/**
 * Separador de grupo en las tablas de reservas: "HOY · VIERNES 9 OCT ——
 * 2 llegan". En la tabla va como renglón de celda completa (`colspan`); en
 * la lista del celular, como franja.
 */
withDefaults(
    defineProps<{
        label: string;
        /** "2 llegan", "3 salen"; vacío = sin cuenta. */
        count?: string;
        colspan?: number;
        /** Pinta el rótulo con el tono del grupo (vencidos en rojo). */
        tone?: 'danger' | 'warning' | 'info' | 'neutral';
        as?: 'tr' | 'div';
    }>(),
    { count: '', colspan: 6, tone: 'neutral', as: 'tr' },
);

const toneClass: Record<string, string> = {
    danger: 'text-danger',
    warning: 'text-warning',
    info: 'text-info',
    neutral: 'text-slate-500 dark:text-slate-400',
};
</script>

<template>
    <tr v-if="as === 'tr'">
        <td
            :colspan="colspan"
            class="border-b border-slate-200/60 bg-slate-50/80 px-5 py-1.5 dark:border-darkmode-400 dark:bg-darkmode-700/50"
        >
            <div class="flex items-center gap-3">
                <span
                    class="text-[11px] font-medium tracking-wide uppercase"
                    :class="toneClass[tone]"
                    >{{ label }}</span
                >
                <span
                    class="h-px flex-1 bg-slate-200/80 dark:bg-darkmode-400"
                />
                <span
                    v-if="count"
                    class="text-[11px] text-slate-400 tabular-nums"
                    >{{ count }}</span
                >
            </div>
        </td>
    </tr>
    <div
        v-else
        class="flex items-center gap-3 bg-slate-50/80 px-4 py-1.5 dark:bg-darkmode-700/50"
    >
        <span
            class="text-[11px] font-medium tracking-wide uppercase"
            :class="toneClass[tone]"
            >{{ label }}</span
        >
        <span class="h-px flex-1 bg-slate-200/80 dark:bg-darkmode-400" />
        <span v-if="count" class="text-[11px] text-slate-400 tabular-nums">{{
            count
        }}</span>
    </div>
</template>
