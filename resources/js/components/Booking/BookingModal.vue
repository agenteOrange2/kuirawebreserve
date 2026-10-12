<script setup lang="ts">
import { computed, inject, watch } from 'vue';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { revealEmbedTop } from '@/composables/useEmbedResize';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import { bookingThemeKey } from './theme';

/**
 * Modal de las páginas públicas con la anatomía del panel: cabecera y pie
 * fijos, cuerpo con scroll. El Dialog del theme se pinta en <body>, fuera
 * del div que lleva el color del hotel y la piel oscura: aquí se vuelven a
 * poner, si no el modal salía con el azul de fábrica y en blanco.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        subtitle?: string;
        icon?: Icon;
        width?: 'md' | 'lg' | 'xl';
        // Sin padding en el cuerpo (galerías, listas a ras).
        flush?: boolean;
    }>(),
    { subtitle: undefined, icon: undefined, width: 'lg', flush: false },
);

defineEmits<{ (e: 'close'): void }>();

const theme = inject(bookingThemeKey, null);

// Embebido, el modal se abre arriba del iframe: se lleva ahí la página.
watch(
    () => props.open,
    (open) => open && revealEmbedTop(),
);

const panelStyle = computed(() => (theme ? theme.vars.value : {}));

// El Panel del theme solo toma `class` si es texto (twMerge): un arreglo
// se perdía en silencio.
const panelClass = computed(() =>
    [
        widthClass.value,
        theme ? 'booking-public' : '',
        theme?.titleColor.value ? 'booking-title-color' : '',
        theme?.isDark.value ? 'booking-dark bg-white' : '',
    ]
        .filter(Boolean)
        .join(' '),
);

const widthClass = computed(
    () =>
        ({
            md: 'sm:w-[94vw] md:w-[480px]',
            lg: 'sm:w-[94vw] lg:w-[680px]',
            xl: 'sm:w-[94vw] lg:w-[920px]',
        })[props.width],
);
</script>

<template>
    <Dialog :open="open" size="lg" @close="$emit('close')">
        <Dialog.Panel :class="panelClass" :style="panelStyle">
            <div class="flex max-h-[calc(100dvh-6rem)] flex-col">
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4"
                >
                    <div
                        v-if="icon"
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide :icon="icon" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2
                            class="booking-title truncate text-base font-medium text-slate-800"
                        >
                            {{ title }}
                        </h2>
                        <p
                            v-if="subtitle"
                            class="mt-0.5 truncate text-sm text-slate-500"
                        >
                            {{ subtitle }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100"
                        title="Cerrar"
                        @click="$emit('close')"
                    >
                        <Lucide icon="X" class="h-5 w-5" />
                    </button>
                </div>
                <div
                    class="min-h-0 flex-1 overflow-y-auto"
                    :class="flush ? '' : 'px-5 py-4'"
                >
                    <slot />
                </div>
                <div
                    v-if="$slots.footer"
                    class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5"
                >
                    <slot name="footer" />
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
