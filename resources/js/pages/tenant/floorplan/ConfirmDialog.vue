<script setup lang="ts">
import Button from '@/components/Base/Button';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';

/**
 * Confirmación del plano (canon §8): círculo de color, título, explicación
 * corta y los dos botones a la derecha. Reemplaza los `window.confirm` del
 * navegador, que en la tablet del mostrador salían como un cuadro gris con el
 * dominio arriba y sin forma de leer bien un texto de tres renglones.
 */
withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        message: string;
        confirmLabel?: string;
        icon?: Icon;
        tone?: 'danger' | 'primary' | 'warning';
        busy?: boolean;
    }>(),
    {
        confirmLabel: 'Sí, continuar',
        icon: 'TriangleAlert',
        tone: 'danger',
        busy: false,
    },
);

defineEmits<{
    (e: 'confirm'): void;
    (e: 'cancel'): void;
}>();

const circle = {
    danger: 'border-danger/10 bg-danger/10 text-danger',
    primary: 'border-primary/10 bg-primary/10 text-primary',
    warning: 'border-warning/10 bg-warning/10 text-warning',
};
</script>

<template>
    <Dialog :open="open" @close="busy || $emit('cancel')">
        <Dialog.Panel>
            <div class="p-5">
                <div class="flex items-start gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border"
                        :class="circle[tone]"
                    >
                        <Lucide :icon="icon" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <Dialog.Title
                            as="h2"
                            class="block border-0 p-0 text-base font-medium"
                            >{{ title }}</Dialog.Title
                        >
                        <p
                            class="mt-0.5 text-xs leading-relaxed text-slate-500"
                        >
                            {{ message }}
                        </p>
                    </div>
                </div>
                <div
                    class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                >
                    <Button
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="busy"
                        @click="$emit('cancel')"
                        >Cancelar</Button
                    >
                    <Button
                        :variant="tone === 'danger' ? 'danger' : 'primary'"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="busy"
                        @click="$emit('confirm')"
                    >
                        <Lucide :icon="icon" class="mr-1.5 h-3.5 w-3.5" />
                        {{ confirmLabel }}
                    </Button>
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
