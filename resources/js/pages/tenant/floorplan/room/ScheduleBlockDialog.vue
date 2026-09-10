<script setup lang="ts">
import { ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { Dialog } from '@/components/Base/Headless';
import { FormDate, FormInput } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';

/**
 * Programar mantenimiento: aparta FECHAS para que no se vendan y no toca el
 * semáforo de hoy —lo que más se confunde, así que lo dice el propio modal.
 *
 * Era un formulario desplegable dentro del tab; abrirlo empujaba la lista de
 * bloqueos y dejaba tres campos flotando en una franja gris.
 */
const props = defineProps<{
    open: boolean;
    roomNumber: string;
    busy: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (
        e: 'submit',
        value: { starts_at: string; ends_at: string; reason: string | null },
    ): void;
}>();

const blank = () => ({ starts_at: '', ends_at: '', reason: '' });

const form = ref(blank());

watch(
    () => props.open,
    () => {
        form.value = blank();
    },
);

function submit() {
    if (!form.value.starts_at || !form.value.ends_at) {
        return;
    }

    emit('submit', {
        starts_at: form.value.starts_at,
        ends_at: form.value.ends_at,
        reason: form.value.reason.trim() || null,
    });
}
</script>

<template>
    <Dialog :open="open" size="lg" @close="emit('close')">
        <Dialog.Panel class="sm:w-[94vw] lg:w-[620px]">
            <form
                class="flex max-h-[calc(100dvh-6rem)] flex-col"
                @submit.prevent="submit"
            >
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="CalendarOff" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-medium">Apartar fechas</h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Habitación {{ roomNumber }}: esas fechas dejan de
                            venderse y el semáforo de hoy no cambia.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-darkmode-400"
                        aria-label="Cerrar"
                        @click="emit('close')"
                    >
                        <Lucide icon="X" class="h-4 w-4" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label
                                class="text-xs text-slate-500"
                                for="block-from"
                                >Desde</label
                            >
                            <FormDate
                                id="block-from"
                                v-model="form.starts_at"
                                class="mt-1"
                                input-class="h-9 text-xs"
                                required
                            />
                        </div>
                        <div>
                            <label class="text-xs text-slate-500" for="block-to"
                                >Hasta</label
                            >
                            <FormDate
                                id="block-to"
                                v-model="form.ends_at"
                                class="mt-1"
                                input-class="h-9 text-xs"
                                required
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <label
                                class="text-xs text-slate-500"
                                for="block-reason"
                                >Motivo</label
                            >
                            <FormInput
                                id="block-reason"
                                v-model="form.reason"
                                type="text"
                                maxlength="255"
                                class="mt-1 h-9 text-xs"
                                placeholder="Pintura, plomería…"
                            />
                            <p class="mt-1.5 text-[11px] text-slate-400">
                                El motivo es opcional, pero es lo que verá quien
                                se pregunte por qué no se puede vender.
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <Button
                        variant="outline-secondary"
                        type="button"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        @click="emit('close')"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="primary"
                        type="submit"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="busy || !form.starts_at || !form.ends_at"
                    >
                        {{ busy ? 'Programando…' : 'Apartar fechas' }}
                    </Button>
                </div>
            </form>
        </Dialog.Panel>
    </Dialog>
</template>
