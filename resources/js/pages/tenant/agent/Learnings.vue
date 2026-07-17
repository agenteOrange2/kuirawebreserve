<script setup lang="ts">
import axios from 'axios';
import { ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSwitch } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface GuidelineRow {
    id: number;
    instruction: string;
    active: boolean;
    source_conversation_id: number | null;
    created_by: string | null;
    created_at: string;
    created_at_human: string;
}

const props = defineProps<{
    guidelines: GuidelineRow[];
}>();

const toast = useToasts();
const guidelines = ref<GuidelineRow[]>([...props.guidelines]);
const input = ref('');
const saving = ref(false);

async function add() {
    if (input.value.trim().length < 10) return;
    saving.value = true;
    try {
        const { data } = await axios.post<GuidelineRow>(
            '/api/agent-guidelines',
            { instruction: input.value.trim() },
        );
        guidelines.value = [...guidelines.value, data];
        input.value = '';
        toast.success(
            'Aprendizaje guardado',
            'El bot lo recibe como regla desde su siguiente respuesta.',
        );
    } catch (e: any) {
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ??
                'Escribe la lección con al menos 10 caracteres.',
        );
    } finally {
        saving.value = false;
    }
}

async function toggle(guideline: GuidelineRow) {
    try {
        const { data } = await axios.patch<GuidelineRow>(
            `/api/agent-guidelines/${guideline.id}`,
            { active: !guideline.active },
        );
        guidelines.value = guidelines.value.map((g) =>
            g.id === data.id ? data : g,
        );
    } catch {
        toast.error('Error', 'No se pudo cambiar el estado.');
    }
}

async function remove(guideline: GuidelineRow) {
    try {
        await axios.delete(`/api/agent-guidelines/${guideline.id}`);
        guidelines.value = guidelines.value.filter(
            (g) => g.id !== guideline.id,
        );
        toast.success('Aprendizaje eliminado');
    } catch {
        toast.error('Error', 'No se pudo eliminar.');
    }
}
</script>

<template>
    <RazeLayout title="Aprendizajes del asistente">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-lg font-medium">
                        Aprendizajes del asistente
                    </h1>
                    <p class="mt-0.5 text-sm text-slate-500">
                        Área aislada: correcciones de conversaciones reales que
                        el bot recibe como reglas. Se capturan aquí o directo
                        desde la conversación en la Bandeja ("Enseñar al
                        asistente").
                    </p>
                </div>
                <Button
                    as="a"
                    :href="route('tenant.agent')"
                    variant="outline-secondary"
                    class="rounded-[0.5rem] bg-white"
                >
                    <Lucide
                        icon="ArrowLeft"
                        class="mr-2 h-4 w-4 stroke-[1.3]"
                    />
                    Volver al Asistente
                </Button>
            </div>

            <div class="box box--stacked mt-5 p-5">
                <div class="flex flex-wrap items-end gap-2">
                    <div class="min-w-0 flex-1">
                        <label class="mb-1 block text-sm">Nueva lección</label>
                        <FormInput
                            v-model="input"
                            type="text"
                            maxlength="500"
                            placeholder="Cuando pidan varias cabañas, aparta cada una con su herramienta y reporta el resultado de cada apartado"
                            @keyup.enter="add"
                        />
                    </div>
                    <Button
                        variant="primary"
                        class="rounded-[0.5rem]"
                        :disabled="saving || input.trim().length < 10"
                        @click="add"
                    >
                        <Lucide icon="Plus" class="mr-2 h-4 w-4" /> Agregar
                    </Button>
                </div>
                <p class="mt-2 text-xs text-slate-400">
                    Máximo 50 lecciones activas — pocas y precisas funcionan
                    mejor que un manual kilométrico.
                </p>

                <div
                    v-if="guidelines.length"
                    class="mt-4 flex flex-col divide-y divide-dashed divide-slate-300/70"
                >
                    <div
                        v-for="guideline in guidelines"
                        :key="guideline.id"
                        class="flex items-start gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <div
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                        >
                            <Lucide
                                icon="GraduationCap"
                                class="h-3.5 w-3.5 text-primary"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p
                                class="text-sm"
                                :class="{
                                    'text-slate-400 line-through':
                                        !guideline.active,
                                }"
                            >
                                {{ guideline.instruction }}
                            </p>
                            <p class="mt-0.5 text-xs text-slate-400">
                                {{ guideline.created_by ?? 'Staff' }} ·
                                {{ guideline.created_at_human }}
                                <template
                                    v-if="guideline.source_conversation_id"
                                >
                                    · desde una conversación de la
                                    Bandeja</template
                                >
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3 pt-1">
                            <FormSwitch
                                title="Solo los aprendizajes activos llegan al bot"
                            >
                                <FormSwitch.Input
                                    :checked="guideline.active"
                                    type="checkbox"
                                    @change="toggle(guideline)"
                                />
                            </FormSwitch>
                            <a
                                href="#"
                                class="flex items-center text-danger"
                                title="Eliminar"
                                @click.prevent="remove(guideline)"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </a>
                        </div>
                    </div>
                </div>
                <div
                    v-else
                    class="mt-4 rounded-lg border border-dashed border-slate-300/70 px-4 py-5 text-center text-xs text-slate-500 dark:border-darkmode-400"
                >
                    Aún sin aprendizajes. La primera vez que el bot responda
                    algo mal, captura aquí cómo debió comportarse — así se
                    alimenta con tu control, lección por lección.
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
