<script setup lang="ts">
import { computed } from 'vue';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';

/**
 * La cáscara de TODOS los modales del plano (canon §7 de
 * anatomia-de-pantalla.md), escrita una sola vez.
 *
 * Existe porque el panel del theme se ancla con `mt-16` dentro de un
 * `fixed inset-0` y no tiene scroll propio: el modal que no acota su alto con
 * `100dvh - 6rem` y no scrollea solo el cuerpo deja el botón de guardar fuera
 * de la pantalla del teléfono. Pasaba en la mitad de los modales del plano, y
 * cada uno lo resolvía (o no) a su manera, con `86vh`, `70vh` o nada.
 *
 * Cabecera y pie quedan fijos; el cuerpo es lo único que se mueve. En el
 * teléfono los botones del pie van a lo ancho y apilados, con el principal
 * arriba (el `flex-col-reverse`): es el que el pulgar busca primero.
 */
type Tone =
    | 'primary'
    | 'success'
    | 'info'
    | 'warning'
    | 'pending'
    | 'danger'
    | 'dark';

const props = withDefaults(
    defineProps<{
        open: boolean;
        icon: Icon;
        title: string;
        subtitle?: string | null;
        tone?: Tone;
        /** Anchos del panel; el del theme ya trae `w-[90%]` en el teléfono. */
        panelClass?: string;
        /** Formularios con dinero o captura larga: el clic fuera no los tira. */
        staticBackdrop?: boolean;
        /** Mientras guarda, ni la X ni el clic fuera lo cierran. */
        busy?: boolean;
        /** Cuerpo sin padding (paneles que traen su propio acomodo). */
        flush?: boolean;
    }>(),
    {
        subtitle: null,
        tone: 'primary',
        panelClass: 'sm:w-[560px]',
        staticBackdrop: false,
        busy: false,
        flush: false,
    },
);

const emit = defineEmits<{ (e: 'close'): void }>();

defineSlots<{
    default(): unknown;
    footer?(): unknown;
    'footer-start'?(): unknown;
    'header-extra'?(): unknown;
}>();

// Clases completas y literales: Tailwind no ve las que se arman con `${tone}`.
const toneClass: Record<Tone, string> = {
    primary: 'border-primary/10 bg-primary/10 text-primary',
    success: 'border-success/10 bg-success/10 text-success',
    info: 'border-info/10 bg-info/10 text-info',
    warning: 'border-warning/10 bg-warning/10 text-warning',
    pending: 'border-pending/10 bg-pending/10 text-pending',
    danger: 'border-danger/10 bg-danger/10 text-danger',
    dark: 'border-dark/10 bg-dark/10 text-dark dark:text-slate-300',
};

const circle = computed(() => toneClass[props.tone]);

function close() {
    if (!props.busy) {
        emit('close');
    }
}
</script>

<template>
    <Dialog
        :open="open"
        size="lg"
        :static-backdrop="staticBackdrop"
        @close="close"
    >
        <Dialog.Panel :class="panelClass">
            <div class="flex max-h-[calc(100dvh-6rem)] flex-col">
                <div
                    class="flex shrink-0 items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border"
                        :class="circle"
                    >
                        <Lucide :icon="icon" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div
                            class="flex flex-wrap items-center gap-x-2 gap-y-1"
                        >
                            <Dialog.Title
                                as="h2"
                                class="block border-0 p-0 text-base font-medium"
                                >{{ title }}</Dialog.Title
                            >
                            <slot name="header-extra" />
                        </div>
                        <p
                            v-if="subtitle"
                            class="mt-0.5 text-xs text-slate-500"
                        >
                            {{ subtitle }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 disabled:opacity-40 dark:hover:bg-darkmode-400"
                        aria-label="Cerrar"
                        :disabled="busy"
                        @click="close"
                    >
                        <Lucide icon="X" class="h-4 w-4" />
                    </button>
                </div>

                <div
                    class="min-h-0 flex-1 overflow-y-auto"
                    :class="flush ? '' : 'space-y-5 px-5 py-4'"
                >
                    <slot />
                </div>

                <div
                    v-if="$slots.footer || $slots['footer-start']"
                    class="flex shrink-0 flex-col gap-3 border-t border-slate-200/70 px-5 py-3.5 sm:flex-row sm:items-center dark:border-darkmode-400"
                >
                    <div
                        v-if="$slots['footer-start']"
                        class="min-w-0 text-xs text-slate-500 sm:flex-1"
                    >
                        <slot name="footer-start" />
                    </div>
                    <div
                        class="flex flex-col-reverse gap-2 sm:ml-auto sm:flex-row sm:items-center [&>*]:w-full sm:[&>*]:w-auto"
                    >
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
