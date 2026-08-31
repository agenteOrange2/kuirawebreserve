<script setup lang="ts">
import Button from '@/components/Base/Button';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import CashPanel from './panels/CashPanel.vue';

/**
 * Caja del turno. No es de una habitación, así que no cabía como tab del modal
 * de habitación ni merecía una tarjeta permanente sobre el plano: vive detrás
 * del chip de la barra, que ya muestra el total.
 *
 * El cuerpo es el mismo `CashPanel`, que trae su refresco por reloj y el abrir
 * turno. El arqueo (contar el efectivo, la diferencia, las notas, el PDF) sigue
 * en /cortes: cerrar una caja de prisa desde un plano es justo lo que no
 * queremos.
 */
defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'error', message: string): void;
}>();
</script>

<template>
    <!-- Más ancho que un diálogo normal y con alto acotado: con los
         movimientos y el formulario de cierre, a 600px todo quedaba apretado y
         el conteo caía abajo del borde. -->
    <Dialog size="xl" :open="open" @close="emit('close')">
        <Dialog.Panel class="sm:w-[680px] lg:w-[860px]">
            <div class="flex max-h-[88vh] flex-col">
                <div
                    class="flex shrink-0 items-center gap-3.5 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                    >
                        <Lucide icon="Wallet" class="h-5 w-5 text-primary" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-medium">Caja del turno</h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cómo va el corte en curso, sin salir del plano.
                        </p>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto">
                    <CashPanel @error="(message) => emit('error', message)" />
                </div>

                <div
                    class="flex shrink-0 items-center justify-end border-t border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <Button
                        variant="outline-secondary"
                        class="rounded-[0.5rem] text-xs"
                        @click="emit('close')"
                        >Cerrar</Button
                    >
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
