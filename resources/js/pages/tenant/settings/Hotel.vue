<script setup lang="ts">
import axios from 'axios';
import { reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSwitch, FormTextarea } from '@/components/Base/Form';
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
    property: { id: number; name: string; address: string | null; timezone: string };
    settings: { check_in_time: string; check_out_time: string; currency: string; phone: string; email: string; policies: string };
    plan: string;
    faqs: FaqRow[];
}>();

const toast = useToasts();
const saving = ref(false);
const errors = reactive<Record<string, string>>({});

const form = reactive({
    name: props.property.name,
    address: props.property.address ?? '',
    timezone: props.property.timezone,
    check_in_time: props.settings.check_in_time,
    check_out_time: props.settings.check_out_time,
    currency: props.settings.currency,
    phone: props.settings.phone,
    email: props.settings.email,
    policies: props.settings.policies,
});

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            name: form.name,
            address: form.address || null,
            timezone: form.timezone,
            settings: {
                check_in_time: form.check_in_time || null,
                check_out_time: form.check_out_time || null,
                currency: form.currency || null,
                phone: form.phone || null,
                email: form.email || null,
                policies: form.policies || null,
            },
        });
        toast.success('Ajustes guardados', 'La configuración del hotel se actualizó.');
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(([key, msgs]) => (errors[key.replace('settings.', '')] = (msgs as string[])[0]));
            toast.error('Revisa el formulario', Object.values(errors)[0]);
        } else {
            toast.error('Error', data?.message ?? 'No se pudo guardar.');
        }
    } finally {
        saving.value = false;
    }
}

const iconInput = 'absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400';

// ---- FAQs (contexto extra del asistente IA) ----
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
            const { data } = await axios.patch<FaqRow>(`/api/faqs/${faqEditing.value.id}`, {
                question: faqForm.question,
                answer: faqForm.answer,
                active: faqEditing.value.active,
            });
            faqs.value = faqs.value.map((f) => (f.id === data.id ? data : f));
            toast.success('Pregunta actualizada', 'El asistente usará la nueva respuesta.');
        } else {
            const { data } = await axios.post<FaqRow>('/api/faqs', { question: faqForm.question, answer: faqForm.answer });
            faqs.value = [...faqs.value, data];
            toast.success('Pregunta agregada', 'El asistente ya puede responderla.');
        }
        faqModal.value = false;
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(([key, msgs]) => (faqErrors[key] = (msgs as string[])[0]));
        } else {
            toast.error('Error', data?.message ?? 'No se pudo guardar la pregunta.');
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
    <RazeLayout title="Ajustes del hotel">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Ajustes del hotel</h1>
                    <p class="text-sm text-slate-500">Datos generales, horarios y políticas</p>
                </div>
                <span class="flex items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1 text-xs font-medium capitalize text-primary">
                    <Lucide icon="BadgeCheck" class="h-3.5 w-3.5" /> Plan {{ plan }}
                </span>
            </div>

            <form class="mt-5 grid grid-cols-12 gap-6" @submit.prevent="submit">
                <!-- Datos generales -->
                <div class="col-span-12 xl:col-span-6">
                    <div class="box box--stacked p-5">
                        <div class="mb-4 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                            <Lucide icon="Building2" class="h-3.5 w-3.5" /> Datos generales
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1 block text-sm">Nombre del hotel</label>
                                <div class="relative">
                                    <Lucide icon="Building2" :class="iconInput" />
                                    <FormInput v-model="form.name" type="text" class="pl-9" />
                                </div>
                                <FormHelp v-if="errors.name" class="text-danger">{{ errors.name }}</FormHelp>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Dirección</label>
                                <div class="relative">
                                    <Lucide icon="MapPin" :class="iconInput" />
                                    <FormInput v-model="form.address" type="text" class="pl-9" placeholder="Calle, colonia, ciudad…" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm">Teléfono</label>
                                    <div class="relative">
                                        <Lucide icon="Phone" :class="iconInput" />
                                        <FormInput v-model="form.phone" type="text" class="pl-9" placeholder="+52…" />
                                    </div>
                                    <FormHelp v-if="errors.phone" class="text-danger">{{ errors.phone }}</FormHelp>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm">Email de contacto</label>
                                    <div class="relative">
                                        <Lucide icon="Mail" :class="iconInput" />
                                        <FormInput v-model="form.email" type="email" class="pl-9" placeholder="contacto@hotel.com" />
                                    </div>
                                    <FormHelp v-if="errors.email" class="text-danger">{{ errors.email }}</FormHelp>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 box box--stacked p-5">
                        <div class="mb-4 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                            <Lucide icon="Clock" class="h-3.5 w-3.5" /> Horarios y moneda
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-sm">Check-in desde</label>
                                <FormInput v-model="form.check_in_time" type="time" />
                                <FormHelp v-if="errors.check_in_time" class="text-danger">{{ errors.check_in_time }}</FormHelp>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Check-out hasta</label>
                                <FormInput v-model="form.check_out_time" type="time" />
                                <FormHelp v-if="errors.check_out_time" class="text-danger">{{ errors.check_out_time }}</FormHelp>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Moneda</label>
                                <div class="relative">
                                    <Lucide icon="DollarSign" :class="iconInput" />
                                    <FormInput v-model="form.currency" type="text" maxlength="3" class="pl-9 uppercase" placeholder="MXN" />
                                </div>
                                <FormHelp v-if="errors.currency" class="text-danger">{{ errors.currency }}</FormHelp>
                            </div>
                        </div>
                        <div class="mt-4">
                            <label class="mb-1 block text-sm">Zona horaria</label>
                            <div class="relative">
                                <Lucide icon="Globe" :class="iconInput" />
                                <FormInput v-model="form.timezone" type="text" class="pl-9" placeholder="America/Mexico_City" />
                            </div>
                            <FormHelp v-if="errors.timezone" class="text-danger">{{ errors.timezone }}</FormHelp>
                        </div>
                    </div>
                </div>

                <!-- Políticas -->
                <div class="col-span-12 flex flex-col xl:col-span-6">
                    <div class="box box--stacked flex flex-1 flex-col p-5">
                        <div class="mb-1 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                            <Lucide icon="ScrollText" class="h-3.5 w-3.5" /> Políticas del hotel
                        </div>
                        <p class="mb-3 text-xs text-slate-500">
                            Escríbelas en lenguaje natural: mascotas, estacionamiento, visitas, cancelaciones, niños…
                            <span class="font-medium">Los asistentes IA responderán a los huéspedes usando exactamente lo que pongas aquí.</span>
                        </p>
                        <FormTextarea
                            v-model="form.policies"
                            rows="14"
                            class="flex-1"
                            placeholder="Ej.
— No se permiten mascotas, excepto perros de asistencia.
— El estacionamiento es gratuito para huéspedes (1 auto por habitación).
— Cancelaciones sin costo hasta 24 h antes de la llegada.
— Check-in a partir de las 15:00; salidas después de las 12:00 generan cargo de $200/hora."
                        />
                        <FormHelp v-if="errors.policies" class="text-danger">{{ errors.policies }}</FormHelp>
                        <div class="mt-3 flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700">
                            <Lucide icon="Bot" class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                            <span>Estas políticas + horarios + tarifas serán la única fuente de verdad del agente de WhatsApp/webchat. Si algo no está escrito aquí, el agente dirá que no tiene esa información (no inventa).</span>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 flex justify-end">
                    <Button type="submit" variant="primary" class="rounded-[0.5rem] shadow-md shadow-primary/20" :disabled="saving">
                        <Lucide icon="Check" class="mr-2 h-4 w-4" /> {{ saving ? 'Guardando…' : 'Guardar ajustes' }}
                    </Button>
                </div>
            </form>

            <!-- Preguntas frecuentes (contexto del asistente IA) -->
            <div class="mt-6 box box--stacked">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-dashed border-slate-300/70 px-5 py-4">
                    <div>
                        <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                            <Lucide icon="MessageCircleQuestion" class="h-3.5 w-3.5" /> Preguntas frecuentes
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            Respuestas puntuales que el asistente usará tal cual: ¿hay alberca?, ¿aceptan tarjeta?, ¿hay servicio a la habitación?…
                        </p>
                    </div>
                    <Button variant="primary" class="rounded-[0.5rem]" @click="openFaqModal()">
                        <Lucide icon="Plus" class="mr-2 h-4 w-4" /> Agregar pregunta
                    </Button>
                </div>
                <div class="p-5">
                    <div v-if="faqs.length" class="flex flex-col divide-y divide-dashed divide-slate-300/70">
                        <div v-for="faq in faqs" :key="faq.id" class="flex items-start gap-4 py-3.5 first:pt-0 last:pb-0">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                                <Lucide icon="MessageCircleQuestion" class="h-4 w-4 text-primary" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="truncate font-medium" :class="{ 'text-slate-400 line-through': !faq.active }">
                                        {{ faq.question }}
                                    </span>
                                    <span
                                        v-if="!faq.active"
                                        class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400"
                                    >
                                        Pausada
                                    </span>
                                </div>
                                <p class="mt-0.5 text-sm text-slate-500">{{ faq.answer }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3 pt-1">
                                <FormSwitch title="El asistente solo usa las preguntas activas">
                                    <FormSwitch.Input :checked="faq.active" type="checkbox" @change="toggleFaq(faq)" />
                                </FormSwitch>
                                <a href="#" class="flex items-center text-primary" @click.prevent="openFaqModal(faq)">
                                    <Lucide icon="Pencil" class="h-4 w-4" />
                                </a>
                                <a href="#" class="flex items-center text-danger" @click.prevent="faqDeleting = faq">
                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                </a>
                            </div>
                        </div>
                    </div>
                    <div v-else class="py-6 text-center text-sm text-slate-500">
                        Aún no hay preguntas frecuentes. Agrega las dudas más comunes de tus huéspedes
                        y el asistente las responderá al instante.
                    </div>
                    <div class="mt-4 flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700">
                        <Lucide icon="Bot" class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                        <span>Las preguntas activas se suman a las políticas como contexto del asistente. Entre más específicas sean las respuestas, más preciso será el bot.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: agregar / editar FAQ -->
        <Dialog :open="faqModal" @close="faqModal = false">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="MessageCircleQuestion" class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">{{ faqEditing ? 'Editar pregunta' : 'Nueva pregunta frecuente' }}</h2>
                            <p class="text-xs text-slate-500">El asistente responderá con este texto, tal cual.</p>
                        </div>
                    </div>
                    <form class="space-y-4" @submit.prevent="submitFaq">
                        <div>
                            <label class="mb-1 block text-sm">Pregunta</label>
                            <FormInput v-model="faqForm.question" type="text" placeholder="¿El hotel tiene alberca?" />
                            <FormHelp v-if="faqErrors.question" class="text-danger">{{ faqErrors.question }}</FormHelp>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Respuesta</label>
                            <FormTextarea
                                v-model="faqForm.answer"
                                rows="4"
                                placeholder="Sí, contamos con alberca al aire libre, abierta de 8:00 a 22:00, incluida en tu estancia."
                            />
                            <FormHelp v-if="faqErrors.answer" class="text-danger">{{ faqErrors.answer }}</FormHelp>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline-secondary" @click="faqModal = false">Cancelar</Button>
                            <Button type="submit" variant="primary" :disabled="faqSaving">
                                {{ faqSaving ? 'Guardando…' : faqEditing ? 'Guardar cambios' : 'Agregar pregunta' }}
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
                    <Lucide icon="AlertTriangle" class="mx-auto mb-3 h-12 w-12 text-danger" />
                    <h2 class="text-base font-medium">¿Eliminar esta pregunta?</h2>
                    <p class="mt-2 text-sm text-slate-500">
                        "{{ faqDeleting?.question }}" — el asistente dejará de usarla. Si solo quieres
                        pausarla, usa el switch.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button variant="outline-secondary" @click="faqDeleting = null">Cancelar</Button>
                        <Button variant="danger" @click="deleteFaq">Sí, eliminar</Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
