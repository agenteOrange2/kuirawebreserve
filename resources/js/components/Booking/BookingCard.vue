<script setup lang="ts">
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';

/**
 * Tarjeta de un paso de las páginas públicas: cabecera con círculo,
 * título y explicación; cuerpo; y pie con "Atrás" a la izquierda y la
 * acción principal a la derecha. Es la anatomía del panel (franjas, no
 * cajas sueltas) con la escala de lectura del huésped: textos de 14px y
 * botones de 44px para el dedo.
 */
withDefaults(
    defineProps<{
        icon?: Icon;
        title?: string;
        subtitle?: string;
        tone?: 'primary' | 'success' | 'info' | 'warning' | 'danger';
        // Cuerpo sin relleno: listas a ras que ponen su propio padding.
        flush?: boolean;
        as?: 'section' | 'div';
    }>(),
    {
        icon: undefined,
        title: undefined,
        subtitle: undefined,
        tone: 'primary',
        flush: false,
        as: 'section',
    },
);

const tones: Record<string, string> = {
    primary: 'border-primary/10 bg-primary/10 text-primary',
    success: 'border-success/10 bg-success/10 text-success',
    info: 'border-info/10 bg-info/10 text-info',
    warning: 'border-warning/10 bg-warning/10 text-warning',
    danger: 'border-danger/10 bg-danger/10 text-danger',
};
</script>

<template>
    <component
        :is="as"
        class="overflow-hidden rounded-2xl bg-white shadow-xl shadow-black/10"
    >
        <header
            v-if="title || $slots.header"
            class="flex flex-wrap items-center gap-3 border-b border-slate-200/70 px-5 py-4 sm:px-6"
        >
            <div
                v-if="icon"
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border"
                :class="tones[tone]"
            >
                <Lucide :icon="icon" class="h-4 w-4" />
            </div>
            <!-- basis: en celular las acciones bajan de renglón en vez de
                 aplastar el título a una columna de tres palabras. -->
            <div class="min-w-0 flex-1 basis-56">
                <h2
                    v-if="title"
                    class="booking-title text-base font-medium text-slate-800 sm:text-lg"
                >
                    {{ title }}
                </h2>
                <p v-if="subtitle" class="mt-0.5 text-sm text-slate-500">
                    {{ subtitle }}
                </p>
                <slot name="header" />
            </div>
            <slot name="actions" />
        </header>

        <div :class="flush ? '' : 'p-5 sm:p-6'">
            <slot />
        </div>

        <footer
            v-if="$slots.footer"
            class="flex flex-wrap items-center gap-3 border-t border-slate-200/70 bg-slate-50 px-5 py-4 sm:px-6"
        >
            <slot name="footer" />
        </footer>
    </component>
</template>
