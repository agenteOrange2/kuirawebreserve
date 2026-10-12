<script setup lang="ts">
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';

export interface GuideStep {
    icon: Icon;
    title: string;
    text: string;
}

/**
 * "Cómo se usa esta pantalla": para qué sirve y los tres pasos de siempre.
 * Nació de una capacitación en la que hasta el dueño se perdió entre
 * Operación, Próximas, En casa y Pendientes. Se oculta con la X y vuelve con
 * el botón "Cómo se usa" del encabezado (ver useScreenGuide).
 */
defineProps<{
    purpose: string;
    steps: GuideStep[];
}>();

const emit = defineEmits<{ close: [] }>();
</script>

<template>
    <div class="box box--stacked mt-4 overflow-hidden">
        <div
            class="flex items-start gap-3 border-b border-slate-200/60 bg-info/5 px-4 py-3 dark:border-darkmode-400"
        >
            <div
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
            >
                <Lucide icon="Lightbulb" class="h-4 w-4" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-sm font-medium">Cómo se usa esta pantalla</div>
                <div class="mt-0.5 text-xs text-slate-600 dark:text-slate-300">
                    {{ purpose }}
                </div>
            </div>
            <button
                type="button"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-darkmode-400"
                title="Ocultar la guía (vuelve con el botón Cómo se usa)"
                @click="emit('close')"
            >
                <Lucide icon="X" class="h-4 w-4" />
            </button>
        </div>
        <ol
            class="grid grid-cols-1 divide-y divide-slate-200/60 sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:divide-darkmode-400"
        >
            <li
                v-for="(step, i) in steps"
                :key="step.title"
                class="flex items-start gap-2.5 px-4 py-3"
            >
                <span
                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-medium text-primary"
                    >{{ i + 1 }}</span
                >
                <div class="min-w-0">
                    <div
                        class="flex items-center gap-1.5 text-xs font-medium text-slate-700 dark:text-slate-200"
                    >
                        <Lucide
                            :icon="step.icon"
                            class="h-3.5 w-3.5 text-slate-400"
                        />
                        {{ step.title }}
                    </div>
                    <p class="mt-0.5 text-xs text-slate-500">{{ step.text }}</p>
                </div>
            </li>
        </ol>
    </div>
</template>
