<script setup lang="ts">
import axios from 'axios';
import { reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import type { SlaHours } from './types';

const props = defineProps<{ open: boolean; sla: SlaHours }>();
const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'saved', hours: SlaHours): void;
}>();

const toast = useToasts();
const busy = ref(false);
const form = reactive<SlaHours>({ ...props.sla });

// Al abrir se recarga lo guardado: si el usuario cancela a medias, la
// próxima vez no debe ver sus números a medio escribir.
watch(
    () => props.open,
    (open) => {
        if (open) Object.assign(form, props.sla);
    },
);

const rows: Array<{
    key: keyof SlaHours;
    label: string;
    hint: string;
    tone: string;
}> = [
    {
        key: 'high',
        label: 'Alta',
        hint: 'Fuga, sin luz, sin agua: el cuarto no se puede vender.',
        tone: 'border-danger/10 bg-danger/10 text-danger',
    },
    {
        key: 'medium',
        label: 'Media',
        hint: 'Molesta al huésped pero se puede ocupar la habitación.',
        tone: 'border-pending/10 bg-pending/10 text-pending',
    },
    {
        key: 'low',
        label: 'Baja',
        hint: 'Detalles de mantenimiento que pueden esperar al hueco.',
        tone: 'border-slate-200/70 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400 dark:text-slate-300',
    },
];

async function save() {
    busy.value = true;
    try {
        const { data } = await axios.patch('/api/incidents-sla', {
            high: Number(form.high),
            medium: Number(form.medium),
            low: Number(form.low),
        });
        emit('saved', data.sla);
        emit('close');
        toast.success(
            'Tiempos actualizados',
            'Los plazos de las incidencias pendientes se recalcularon.',
        );
    } catch (e: any) {
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Revisa que sean horas entre 1 y 720.',
        );
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @close="emit('close')">
        <Dialog.Panel>
            <div class="p-5">
                <div class="mb-4 flex items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Timer" class="h-5 w-5" />
                    </div>
                    <div>
                        <h2 class="text-base font-medium">Tiempos objetivo</h2>
                        <p class="text-xs text-slate-500">
                            Cuántas horas puede vivir una falla antes de que el
                            sistema la marque como vencida y avise en la
                            campana.
                        </p>
                    </div>
                </div>

                <div class="space-y-2.5">
                    <div
                        v-for="row in rows"
                        :key="row.key"
                        class="flex items-center gap-3 rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400"
                    >
                        <span
                            class="w-16 shrink-0 rounded-full border px-2 py-0.5 text-center text-xs font-medium"
                            :class="row.tone"
                        >
                            {{ row.label }}
                        </span>
                        <p class="min-w-0 flex-1 text-xs text-slate-500">
                            {{ row.hint }}
                        </p>
                        <div class="flex shrink-0 items-center gap-2">
                            <FormInput
                                v-model="form[row.key]"
                                type="number"
                                min="1"
                                max="720"
                                class="w-20 text-center"
                            />
                            <span class="text-xs text-slate-500">horas</span>
                        </div>
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <Button
                        variant="outline-secondary"
                        class="min-h-11"
                        @click="emit('close')"
                        >Cancelar</Button
                    >
                    <Button
                        variant="primary"
                        class="min-h-11"
                        :disabled="busy"
                        @click="save"
                    >
                        {{ busy ? 'Guardando…' : 'Guardar tiempos' }}
                    </Button>
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
