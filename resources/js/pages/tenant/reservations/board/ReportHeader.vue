<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';

/**
 * Encabezado de los reportes de cada pantalla de reservas: círculo, título y
 * subtítulo, y la pastilla "Volver a <pantalla>" como primer botón (canon).
 * El slot agrega acciones a la derecha.
 */
defineProps<{
    title: string;
    subtitle: string;
    icon: Icon;
    backHref: string;
    backLabel: string;
}>();
</script>

<template>
    <div
        class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
    >
        <div class="flex min-w-0 items-center gap-3">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
            >
                <Lucide :icon="icon" class="h-4 w-4" />
            </div>
            <div class="min-w-0">
                <h1 class="text-base font-medium">{{ title }}</h1>
                <p class="mt-0.5 text-xs text-slate-500">{{ subtitle }}</p>
            </div>
        </div>
        <div
            class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center"
        >
            <Link
                :href="backHref"
                class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
            >
                <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                {{ backLabel }}
            </Link>
            <slot />
        </div>
    </div>
</template>
