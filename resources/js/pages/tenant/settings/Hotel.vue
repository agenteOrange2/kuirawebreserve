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

interface PlanLimitRow {
    label: string;
    used: number;
    max: number | null;
}

interface PlanModuleRow {
    key: string;
    label: string;
    description: string;
    available: boolean;
    enabled: boolean;
    requested: boolean;
}

const props = defineProps<{
    property: {
        id: number;
        name: string;
        address: string | null;
        timezone: string;
    };
    settings: {
        check_in_time: string;
        check_out_time: string;
        currency: string;
        phone: string;
        email: string;
        policies: string;
    };
    plan: string;
    planCard: {
        label: string;
        price_monthly: number;
        limits: PlanLimitRow[];
        modules: PlanModuleRow[];
    };
    faqs: FaqRow[];
    paymentSummary: { active_gateways: number; transfer_accounts: number };
    mailSummary: { configured: boolean; from_address: string };
}>();

const toast = useToasts();
const saving = ref(false);
const errors = reactive<Record<string, string>>({});

// Tarjeta "Tu plan": uso de límites y solicitud de módulos.
const limitPercent = (l: PlanLimitRow) =>
    l.max === null || l.max === 0
        ? 0
        : Math.min(100, Math.round((l.used / l.max) * 100));

const requestedLocal = reactive<Record<string, boolean>>({});
const requestingModule = ref<string | null>(null);

const isRequested = (mod: PlanModuleRow) =>
    mod.requested || requestedLocal[mod.key] === true;

async function requestModule(mod: PlanModuleRow) {
    requestingModule.value = mod.key;
    try {
        await axios.post('/api/module-requests', { module: mod.key });
        requestedLocal[mod.key] = true;
        toast.success(
            'Solicitud enviada',
            `La plataforma revisará activar ${mod.label} para tu hotel.`,
        );
    } catch (e: any) {
        toast.error(
            'No se pudo enviar',
            e.response?.data?.message ?? 'Ocurrió un error inesperado.',
        );
    } finally {
        requestingModule.value = null;
    }
}

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
        toast.success(
            'Ajustes guardados',
            'La configuración del hotel se actualizó.',
        );
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(
                ([key, msgs]) =>
                    (errors[key.replace('settings.', '')] = (
                        msgs as string[]
                    )[0]),
            );
            toast.error('Revisa el formulario', Object.values(errors)[0]);
        } else {
            toast.error('Error', data?.message ?? 'No se pudo guardar.');
        }
    } finally {
        saving.value = false;
    }
}

const iconInput =
    'absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400';

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
    <RazeLayout title="Ajustes del hotel">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Ajustes del hotel</h1>
                    <p class="text-sm text-slate-500">
                        Datos generales, horarios y políticas
                    </p>
                </div>
                <span
                    class="flex items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1 text-xs font-medium text-primary capitalize"
                >
                    <Lucide icon="BadgeCheck" class="h-3.5 w-3.5" /> Plan
                    {{ plan }}
                </span>
            </div>

            <!-- Tu plan: límites con uso real y módulos incluidos -->
            <div class="box box--stacked mt-5 p-5">
                <div class="flex flex-wrap items-center gap-3">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                    >
                        <Lucide icon="Layers" class="h-5 w-5 text-primary" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-base font-medium">
                            Tu plan: {{ planCard.label }}
                        </div>
                        <div class="text-xs text-slate-500">
                            Lo que incluye tu plan y cuánto llevas usado. Para
                            cambiar de plan o activar un módulo, usa "Solicitar
                            activación" o contacta a la plataforma.
                        </div>
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-12 gap-5">
                    <div
                        class="col-span-12 flex flex-col rounded-[0.6rem] border border-dashed border-slate-300/70 p-5 lg:col-span-5 dark:border-darkmode-400"
                    >
                        <div
                            class="mb-4 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Gauge" class="h-3.5 w-3.5" /> Límites
                        </div>
                        <div
                            class="flex flex-1 flex-col justify-between gap-3.5"
                        >
                            <div v-for="l in planCard.limits" :key="l.label">
                                <div
                                    class="mb-1 flex items-center justify-between text-sm"
                                >
                                    <span class="text-slate-500">{{
                                        l.label
                                    }}</span>
                                    <span
                                        class="text-xs"
                                        :class="
                                            l.max !== null &&
                                            limitPercent(l) >= 100
                                                ? 'font-medium text-danger'
                                                : 'text-slate-500'
                                        "
                                    >
                                        {{ l.used
                                        }}{{
                                            l.max !== null
                                                ? ` de ${l.max}`
                                                : ' · sin límite'
                                        }}
                                    </span>
                                </div>
                                <div
                                    v-if="l.max !== null"
                                    class="h-1.5 rounded-full bg-slate-200/70 dark:bg-darkmode-400"
                                >
                                    <div
                                        class="h-1.5 rounded-full"
                                        :class="
                                            limitPercent(l) >= 100
                                                ? 'bg-danger'
                                                : limitPercent(l) >= 80
                                                  ? 'bg-warning'
                                                  : 'bg-primary'
                                        "
                                        :style="{
                                            width: `${limitPercent(l)}%`,
                                        }"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="col-span-12 flex flex-col rounded-[0.6rem] border border-dashed border-slate-300/70 p-5 lg:col-span-7 dark:border-darkmode-400"
                    >
                        <div
                            class="mb-4 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Blocks" class="h-3.5 w-3.5" /> Módulos
                        </div>
                        <div
                            class="divide-y divide-dashed divide-slate-200/80 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="mod in planCard.modules"
                                :key="mod.key"
                                class="flex items-center gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border"
                                    :class="
                                        mod.enabled
                                            ? 'border-success/10 bg-success/10 text-success'
                                            : 'border-slate-200/80 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400/50'
                                    "
                                >
                                    <Lucide
                                        :icon="mod.enabled ? 'Check' : 'Lock'"
                                        class="h-3.5 w-3.5"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="text-sm font-medium"
                                            :class="
                                                mod.enabled
                                                    ? ''
                                                    : 'text-slate-400'
                                            "
                                            >{{ mod.label }}</span
                                        >
                                        <span
                                            v-if="!mod.available"
                                            class="rounded-full bg-pending/10 px-2 py-0.5 text-[10px] font-medium text-pending"
                                        >
                                            Próximamente
                                        </span>
                                    </div>
                                    <div class="mt-0.5 text-xs text-slate-400">
                                        {{ mod.description }}
                                    </div>
                                </div>
                                <template v-if="!mod.enabled">
                                    <span
                                        v-if="isRequested(mod)"
                                        class="shrink-0 rounded-full bg-warning/10 px-2.5 py-1 text-xs text-warning"
                                    >
                                        Solicitud enviada
                                    </span>
                                    <Button
                                        v-else
                                        type="button"
                                        variant="outline-secondary"
                                        class="shrink-0 !px-3 !py-1 text-xs"
                                        :disabled="requestingModule === mod.key"
                                        @click="requestModule(mod)"
                                    >
                                        Solicitar activación
                                    </Button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <form class="mt-5 grid grid-cols-12 gap-6" @submit.prevent="submit">
                <!-- Datos generales -->
                <div class="col-span-12 xl:col-span-6">
                    <div class="box box--stacked p-5">
                        <div
                            class="mb-4 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Building2" class="h-3.5 w-3.5" />
                            Datos generales
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Nombre del hotel</label
                                >
                                <div class="relative">
                                    <Lucide
                                        icon="Building2"
                                        :class="iconInput"
                                    />
                                    <FormInput
                                        v-model="form.name"
                                        type="text"
                                        class="pl-9"
                                    />
                                </div>
                                <FormHelp
                                    v-if="errors.name"
                                    class="text-danger"
                                    >{{ errors.name }}</FormHelp
                                >
                            </div>
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Dirección</label
                                >
                                <div class="relative">
                                    <Lucide icon="MapPin" :class="iconInput" />
                                    <FormInput
                                        v-model="form.address"
                                        type="text"
                                        class="pl-9"
                                        placeholder="Calle, colonia, ciudad…"
                                    />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm"
                                        >Teléfono</label
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="Phone"
                                            :class="iconInput"
                                        />
                                        <FormInput
                                            v-model="form.phone"
                                            type="text"
                                            class="pl-9"
                                            placeholder="+52…"
                                        />
                                    </div>
                                    <FormHelp
                                        v-if="errors.phone"
                                        class="text-danger"
                                        >{{ errors.phone }}</FormHelp
                                    >
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm"
                                        >Email de contacto</label
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="Mail"
                                            :class="iconInput"
                                        />
                                        <FormInput
                                            v-model="form.email"
                                            type="email"
                                            class="pl-9"
                                            placeholder="contacto@hotel.com"
                                        />
                                    </div>
                                    <FormHelp
                                        v-if="errors.email"
                                        class="text-danger"
                                        >{{ errors.email }}</FormHelp
                                    >
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="box box--stacked mt-6 p-5">
                        <div
                            class="mb-4 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Clock" class="h-3.5 w-3.5" /> Horarios
                            y moneda
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Check-in desde</label
                                >
                                <FormInput
                                    v-model="form.check_in_time"
                                    type="time"
                                />
                                <FormHelp
                                    v-if="errors.check_in_time"
                                    class="text-danger"
                                    >{{ errors.check_in_time }}</FormHelp
                                >
                            </div>
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Check-out hasta</label
                                >
                                <FormInput
                                    v-model="form.check_out_time"
                                    type="time"
                                />
                                <FormHelp
                                    v-if="errors.check_out_time"
                                    class="text-danger"
                                    >{{ errors.check_out_time }}</FormHelp
                                >
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Moneda</label>
                                <div class="relative">
                                    <Lucide
                                        icon="DollarSign"
                                        :class="iconInput"
                                    />
                                    <FormInput
                                        v-model="form.currency"
                                        type="text"
                                        maxlength="3"
                                        class="pl-9 uppercase"
                                        placeholder="MXN"
                                    />
                                </div>
                                <FormHelp
                                    v-if="errors.currency"
                                    class="text-danger"
                                    >{{ errors.currency }}</FormHelp
                                >
                            </div>
                        </div>
                        <div class="mt-4">
                            <label class="mb-1 block text-sm"
                                >Zona horaria</label
                            >
                            <div class="relative">
                                <Lucide icon="Globe" :class="iconInput" />
                                <FormInput
                                    v-model="form.timezone"
                                    type="text"
                                    class="pl-9"
                                    placeholder="America/Mexico_City"
                                />
                            </div>
                            <FormHelp
                                v-if="errors.timezone"
                                class="text-danger"
                                >{{ errors.timezone }}</FormHelp
                            >
                        </div>
                    </div>

                    <Link
                        :href="route('tenant.wizard-settings')"
                        class="box box--stacked mt-6 flex items-center gap-4 p-5 transition hover:border-primary/30"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="ShoppingBag" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">Wizard de reservas</div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Área aparte: modalidad y huéspedes, extras del
                                punto de venta, resumen de métodos de pago.
                            </p>
                        </div>
                        <Lucide
                            icon="ArrowRight"
                            class="h-4 w-4 shrink-0 text-slate-400"
                        />
                    </Link>

                    <Link
                        :href="route('tenant.payment-methods')"
                        class="box box--stacked mt-6 flex items-center gap-4 p-5 transition hover:border-primary/30"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="CreditCard" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">Métodos de pago</div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Área aparte: pasarelas de pago, cuentas para
                                transferencia, confirmación automática y saldos.
                            </p>
                            <p
                                class="mt-1 text-xs"
                                :class="
                                    paymentSummary.active_gateways +
                                        paymentSummary.transfer_accounts >
                                    0
                                        ? 'text-success'
                                        : 'text-warning'
                                "
                            >
                                {{ paymentSummary.active_gateways }} pasarela(s)
                                activa(s) ·
                                {{ paymentSummary.transfer_accounts }} cuenta(s)
                                para transferencia
                            </p>
                        </div>
                        <Lucide
                            icon="ArrowRight"
                            class="h-4 w-4 shrink-0 text-slate-400"
                        />
                    </Link>

                    <Link
                        :href="route('tenant.mail-settings')"
                        class="box box--stacked mt-6 flex items-center gap-4 p-5 transition hover:border-primary/30"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Mail" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">Correo saliente</div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Área aparte: SMTP propio del hotel para que
                                confirmaciones y avisos al huésped salgan por
                                correo.
                            </p>
                            <p
                                class="mt-1 text-xs"
                                :class="
                                    mailSummary.configured
                                        ? 'text-success'
                                        : 'text-warning'
                                "
                            >
                                {{
                                    mailSummary.configured
                                        ? `SMTP configurado · remitente ${mailSummary.from_address || 'sin definir'}`
                                        : 'Sin configurar — los avisos solo salen por WhatsApp'
                                }}
                            </p>
                        </div>
                        <Lucide
                            icon="ArrowRight"
                            class="h-4 w-4 shrink-0 text-slate-400"
                        />
                    </Link>
                </div>

                <!-- Políticas -->
                <div class="col-span-12 flex flex-col xl:col-span-6">
                    <div class="box box--stacked flex flex-1 flex-col p-5">
                        <div
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="ScrollText" class="h-3.5 w-3.5" />
                            Políticas del hotel
                        </div>
                        <p class="mb-3 text-xs text-slate-500">
                            Escríbelas en lenguaje natural: mascotas,
                            estacionamiento, visitas, cancelaciones, niños…
                            <span class="font-medium"
                                >Los asistentes IA responderán a los huéspedes
                                usando exactamente lo que pongas aquí.</span
                            >
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
                        <FormHelp v-if="errors.policies" class="text-danger">{{
                            errors.policies
                        }}</FormHelp>
                        <div
                            class="mt-3 flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <Lucide
                                icon="Bot"
                                class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                            />
                            <span
                                >Estas políticas + horarios + tarifas serán la
                                única fuente de verdad del agente de
                                WhatsApp/webchat. Si algo no está escrito aquí,
                                el agente dirá que no tiene esa información (no
                                inventa).</span
                            >
                        </div>
                    </div>
                </div>

                <div class="col-span-12 flex justify-end">
                    <Button
                        type="submit"
                        variant="primary"
                        class="rounded-[0.5rem] shadow-md shadow-primary/20"
                        :disabled="saving"
                    >
                        <Lucide icon="Check" class="mr-2 h-4 w-4" />
                        {{ saving ? 'Guardando…' : 'Guardar ajustes' }}
                    </Button>
                </div>
            </form>

            <!-- Preguntas frecuentes (contexto del asistente IA) -->
            <div class="box box--stacked mt-6">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-dashed border-slate-300/70 px-5 py-4"
                >
                    <div>
                        <div
                            class="flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide
                                icon="MessageCircleQuestion"
                                class="h-3.5 w-3.5"
                            />
                            Preguntas frecuentes
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            Respuestas puntuales que el asistente usará tal
                            cual: ¿hay alberca?, ¿aceptan tarjeta?, ¿hay
                            servicio a la habitación?…
                        </p>
                    </div>
                    <Button
                        variant="primary"
                        class="rounded-[0.5rem]"
                        @click="openFaqModal()"
                    >
                        <Lucide icon="Plus" class="mr-2 h-4 w-4" /> Agregar
                        pregunta
                    </Button>
                </div>
                <div class="p-5">
                    <div
                        v-if="faqs.length"
                        class="flex flex-col divide-y divide-dashed divide-slate-300/70"
                    >
                        <div
                            v-for="faq in faqs"
                            :key="faq.id"
                            class="flex items-start gap-4 py-3.5 first:pt-0 last:pb-0"
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
                <div class="p-5">
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
                            <h2 class="text-base font-medium">
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
                            <label class="mb-1 block text-sm">Pregunta</label>
                            <FormInput
                                v-model="faqForm.question"
                                type="text"
                                placeholder="¿El hotel tiene alberca?"
                            />
                            <FormHelp
                                v-if="faqErrors.question"
                                class="text-danger"
                                >{{ faqErrors.question }}</FormHelp
                            >
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Respuesta</label>
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
                    <h2 class="text-base font-medium">
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
