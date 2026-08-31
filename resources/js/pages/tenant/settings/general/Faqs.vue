<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSwitch,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface FaqRow {
    id: number;
    question: string;
    answer: string;
    active: boolean;
    sort_order: number;
}

const props = defineProps<{
    property: { id: number; name: string };
    faqs: FaqRow[];
}>();

const toast = useToasts();

const faqs = ref<FaqRow[]>([...props.faqs]);
const faqModal = ref(false);
const faqEditing = ref<FaqRow | null>(null);
const faqSaving = ref(false);
const faqDeleting = ref<FaqRow | null>(null);
const faqErrors = reactive<Record<string, string>>({});
const faqForm = reactive({ question: '', answer: '' });

function openFaqModal(faq: FaqRow | null = null) {
    faqEditing.value = faq;
    faqForm.question = faq?.question ?? '';
    faqForm.answer = faq?.answer ?? '';
    Object.keys(faqErrors).forEach((k) => delete faqErrors[k]);
    faqModal.value = true;
}

async function submitFaq() {
    faqSaving.value = true;
    Object.keys(faqErrors).forEach((k) => delete faqErrors[k]);
    try {
        if (faqEditing.value) {
            const { data } = await axios.patch<FaqRow>(
                `/api/faqs/${faqEditing.value.id}`,
                {
                    question: faqForm.question,
                    answer: faqForm.answer,
                    active: faqEditing.value.active,
                },
            );
            faqs.value = faqs.value.map((f) => (f.id === data.id ? data : f));
            toast.success(
                'Pregunta actualizada',
                'El asistente usará la nueva respuesta.',
            );
        } else {
            const { data } = await axios.post<FaqRow>('/api/faqs', {
                question: faqForm.question,
                answer: faqForm.answer,
            });
            faqs.value = [...faqs.value, data];
            toast.success(
                'Pregunta agregada',
                'El asistente ya puede responderla.',
            );
        }
        faqModal.value = false;
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(
                ([key, msgs]) => (faqErrors[key] = (msgs as string[])[0]),
            );
        } else {
            toast.error(
                'Error',
                data?.message ?? 'No se pudo guardar la pregunta.',
            );
        }
    } finally {
        faqSaving.value = false;
    }
}

async function toggleFaq(faq: FaqRow) {
    try {
        const { data } = await axios.patch<FaqRow>(`/api/faqs/${faq.id}`, {
            question: faq.question,
            answer: faq.answer,
            active: !faq.active,
        });
        faqs.value = faqs.value.map((f) => (f.id === data.id ? data : f));
    } catch {
        toast.error('Error', 'No se pudo cambiar el estado de la pregunta.');
    }
}

async function deleteFaq() {
    if (!faqDeleting.value) return;
    try {
        await axios.delete(`/api/faqs/${faqDeleting.value.id}`);
        faqs.value = faqs.value.filter((f) => f.id !== faqDeleting.value!.id);
        toast.success('Pregunta eliminada', 'El asistente dejará de usarla.');
        faqDeleting.value = null;
    } catch {
        toast.error('Error', 'No se pudo eliminar la pregunta.');
    }
}
</script>

<template>
    <RazeLayout title="Preguntas frecuentes">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="MessageCircleQuestion" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Preguntas frecuentes
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Respuestas puntuales que el asistente usará tal cual
                            cuando el huésped pregunte algo parecido.
                        </p>
                    </div>
                </div>
                <div
                    class="flex w-full flex-wrap items-center gap-2 md:w-auto md:shrink-0 md:justify-end"
                >
                    <!-- El volver vive con las acciones, no flotando encima
                         de la tarjeta. -->
                    <Link
                        :href="route('tenant.general-settings')"
                        class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Datos generales
                    </Link>
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        @click="openFaqModal()"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Agregar pregunta
                    </Button>
                </div>
            </div>

            <div class="box box--stacked mt-4">
                <div class="p-4">
                    <div
                        v-if="faqs.length"
                        class="flex flex-col divide-y divide-dashed divide-slate-300/70"
                    >
                        <div
                            v-for="faq in faqs"
                            :key="faq.id"
                            class="flex items-start gap-3 py-3 first:pt-0 last:pb-0"
                        >
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                            >
                                <Lucide
                                    icon="MessageCircleQuestion"
                                    class="h-4 w-4 text-primary"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="truncate font-medium"
                                        :class="{
                                            'text-slate-400 line-through':
                                                !faq.active,
                                        }"
                                    >
                                        {{ faq.question }}
                                    </span>
                                    <span
                                        v-if="!faq.active"
                                        class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400"
                                    >
                                        Pausada
                                    </span>
                                </div>
                                <p class="mt-0.5 text-sm text-slate-500">
                                    {{ faq.answer }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3 pt-1">
                                <FormSwitch
                                    title="El asistente solo usa las preguntas activas"
                                >
                                    <FormSwitch.Input
                                        :checked="faq.active"
                                        type="checkbox"
                                        @change="toggleFaq(faq)"
                                    />
                                </FormSwitch>
                                <a
                                    href="#"
                                    class="flex items-center text-primary"
                                    @click.prevent="openFaqModal(faq)"
                                >
                                    <Lucide icon="Pencil" class="h-4 w-4" />
                                </a>
                                <a
                                    href="#"
                                    class="flex items-center text-danger"
                                    @click.prevent="faqDeleting = faq"
                                >
                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                </a>
                            </div>
                        </div>
                    </div>
                    <div v-else class="py-6 text-center text-sm text-slate-500">
                        Aún no hay preguntas frecuentes. Agrega las dudas más
                        comunes de tus huéspedes y el asistente las responderá
                        al instante.
                    </div>
                    <div
                        class="mt-4 flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                    >
                        <Lucide
                            icon="Bot"
                            class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                        />
                        <span
                            >Las preguntas activas se suman a las políticas como
                            contexto del asistente. Entre más específicas sean
                            las respuestas, más preciso será el bot.</span
                        >
                    </div>
                </div>
            </div>
        </div>
        <!-- Modal: agregar / editar FAQ -->
        <Dialog :open="faqModal" @close="faqModal = false">
            <Dialog.Panel>
                <div class="p-4">
                    <div class="mb-4 flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                        >
                            <Lucide
                                icon="MessageCircleQuestion"
                                class="h-5 w-5 text-primary"
                            />
                        </div>
                        <div>
                            <h2 class="text-sm font-medium">
                                {{
                                    faqEditing
                                        ? 'Editar pregunta'
                                        : 'Nueva pregunta frecuente'
                                }}
                            </h2>
                            <p class="text-xs text-slate-500">
                                El asistente responderá con este texto, tal
                                cual.
                            </p>
                        </div>
                    </div>
                    <form class="space-y-4" @submit.prevent="submitFaq">
                        <div>
                            <label class="mb-1 block text-xs">Pregunta</label>
                            <FormInput
                                v-model="faqForm.question"
                                type="text"
                                placeholder="¿El hotel tiene alberca?"
                                class="h-9 text-xs"
                            />
                            <FormHelp
                                v-if="faqErrors.question"
                                class="text-danger"
                                >{{ faqErrors.question }}</FormHelp
                            >
                        </div>
                        <div>
                            <label class="mb-1 block text-xs">Respuesta</label>
                            <FormTextarea
                                v-model="faqForm.answer"
                                rows="4"
                                placeholder="Sí, contamos con alberca al aire libre, abierta de 8:00 a 22:00, incluida en tu estancia."
                            />
                            <FormHelp
                                v-if="faqErrors.answer"
                                class="text-danger"
                                >{{ faqErrors.answer }}</FormHelp
                            >
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <Button
                                type="button"
                                variant="outline-secondary"
                                @click="faqModal = false"
                                >Cancelar</Button
                            >
                            <Button
                                type="submit"
                                variant="primary"
                                :disabled="faqSaving"
                            >
                                {{
                                    faqSaving
                                        ? 'Guardando…'
                                        : faqEditing
                                          ? 'Guardar cambios'
                                          : 'Agregar pregunta'
                                }}
                            </Button>
                        </div>
                    </form>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal: eliminar FAQ -->
        <Dialog :open="faqDeleting !== null" @close="faqDeleting = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide
                        icon="AlertTriangle"
                        class="mx-auto mb-3 h-12 w-12 text-danger"
                    />
                    <h2 class="text-sm font-medium">
                        ¿Eliminar esta pregunta?
                    </h2>
                    <p class="mt-2 text-sm text-slate-500">
                        "{{ faqDeleting?.question }}" — el asistente dejará de
                        usarla. Si solo quieres pausarla, usa el switch.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="faqDeleting = null"
                            >Cancelar</Button
                        >
                        <Button variant="danger" @click="deleteFaq"
                            >Sí, eliminar</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
