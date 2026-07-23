<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
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
        currency_secondary: string | null;
        exchange_rate: number | null;
        policies: string;
        phones: { code: string; number: string }[];
        emails: string[];
        website: string;
        maps_url: string;
        socials: { type: string; url: string }[];
    };
    logoUrl: string | null;
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
    // Moneda: primaria + modo doble opcional (secundaria con tipo de cambio).
    currency: props.settings.currency || 'MXN',
    currency_mode: props.settings.currency_secondary ? 'both' : 'single',
    currency_secondary: props.settings.currency_secondary ?? 'USD',
    exchange_rate: props.settings.exchange_rate ?? ('' as string | number),
    policies: props.settings.policies,
    // Contacto enriquecido: al menos una fila de teléfono para no arrancar vacío.
    phones: props.settings.phones.length
        ? props.settings.phones.map((p) => ({ ...p }))
        : [{ code: '52', number: '' }],
    emails: props.settings.emails.length ? [...props.settings.emails] : [''],
    website: props.settings.website,
    maps_url: props.settings.maps_url,
    socials: props.settings.socials.map((s) => ({ ...s })),
});

// ── Logo del hotel (media, se guarda al momento — no espera al Guardar) ──
const logoUrl = ref(props.logoUrl);
const logoInput = ref<HTMLInputElement | null>(null);
const logoBusy = ref(false);

async function onLogoSelected(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file || logoBusy.value) return;
    logoBusy.value = true;
    try {
        const data = new FormData();
        data.append('logo', file);
        const res = await axios.post<{ logo_url: string }>(
            '/api/property-logo',
            data,
        );
        logoUrl.value = res.data.logo_url;
        toast.success(
            'Logo actualizado',
            'Ya aparece en el wizard público de reservas.',
        );
    } catch (error: any) {
        toast.error(
            'No se pudo subir el logo',
            error.response?.data?.message ?? 'Intenta con otra imagen.',
        );
    } finally {
        logoBusy.value = false;
        if (logoInput.value) logoInput.value.value = '';
    }
}

async function removeLogo() {
    if (logoBusy.value) return;
    logoBusy.value = true;
    try {
        await axios.delete('/api/property-logo');
        logoUrl.value = null;
        toast.success(
            'Logo quitado',
            'El wizard vuelve al icono genérico de hotel.',
        );
    } catch {
        toast.error('No se pudo quitar el logo', 'Intenta de nuevo.');
    } finally {
        logoBusy.value = false;
    }
}

// Redes disponibles (el icono lo pinta el backend en las páginas públicas).
const socialTypes: Record<string, string> = {
    facebook: 'Facebook',
    instagram: 'Instagram',
    tiktok: 'TikTok',
    youtube: 'YouTube',
    x: 'X (Twitter)',
    whatsapp: 'WhatsApp',
    other: 'Otro',
};

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
                // Solo se guarda la segunda moneda si el modo es "ambas" y
                // hay tipo de cambio; si no, se limpia.
                currency_secondary:
                    form.currency_mode === 'both'
                        ? form.currency_secondary
                        : null,
                exchange_rate:
                    form.currency_mode === 'both' && form.exchange_rate !== ''
                        ? Number(form.exchange_rate)
                        : null,
                policies: form.policies || null,
                phones: form.phones.filter((p) => p.number.trim() !== ''),
                emails: form.emails.filter((e) => e.trim() !== ''),
                website: form.website || null,
                maps_url: form.maps_url || null,
                socials: form.socials.filter((s) => s.url.trim() !== ''),
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
    <RazeLayout title="Datos generales">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link
                        href="/ajustes"
                        class="flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700"
                    >
                        <Lucide icon="ArrowLeft" class="h-4 w-4" /> Ajustes
                    </Link>
                    <h1 class="mt-1 text-lg font-medium">Datos generales</h1>
                    <p class="text-sm text-slate-500">
                        Contacto, redes, horarios y moneda, políticas y
                        preguntas frecuentes.
                    </p>
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
                            <!-- Logo: media aparte, se guarda al momento -->
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Logo del hotel</label
                                >
                                <div
                                    class="flex flex-wrap items-center gap-3"
                                >
                                    <div
                                        class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full border border-slate-200/70 bg-slate-50 dark:border-darkmode-400 dark:bg-darkmode-600"
                                    >
                                        <img
                                            v-if="logoUrl"
                                            :src="logoUrl"
                                            alt="Logo del hotel"
                                            class="h-full w-full object-contain p-1"
                                        />
                                        <Lucide
                                            v-else
                                            icon="Building2"
                                            class="h-5 w-5 text-slate-400"
                                        />
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            variant="outline-primary"
                                            class="rounded-[0.5rem]"
                                            :disabled="logoBusy"
                                            @click="logoInput?.click()"
                                        >
                                            <Lucide
                                                icon="ImageUp"
                                                class="mr-2 h-4 w-4"
                                            />
                                            {{
                                                logoUrl
                                                    ? 'Cambiar logo'
                                                    : 'Subir logo'
                                            }}
                                        </Button>
                                        <Button
                                            v-if="logoUrl"
                                            type="button"
                                            variant="outline-danger"
                                            class="rounded-[0.5rem]"
                                            :disabled="logoBusy"
                                            @click="removeLogo"
                                        >
                                            <Lucide
                                                icon="Trash2"
                                                class="mr-2 h-4 w-4"
                                            />
                                            Quitar
                                        </Button>
                                    </div>
                                    <input
                                        ref="logoInput"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        class="hidden"
                                        @change="onLogoSelected"
                                    />
                                </div>
                                <FormHelp
                                    >JPG, PNG o WebP, máximo 2 MB. Aparece
                                    junto al nombre en el wizard público de
                                    reservas; se guarda al momento.</FormHelp
                                >
                            </div>
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
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Link de Google Maps</label
                                >
                                <div class="relative">
                                    <Lucide
                                        icon="Map"
                                        :class="iconInput"
                                    />
                                    <FormInput
                                        v-model="form.maps_url"
                                        type="url"
                                        class="pl-9"
                                        placeholder="https://maps.app.goo.gl/…"
                                    />
                                </div>
                                <FormHelp
                                    v-if="errors.maps_url"
                                    class="text-danger"
                                    >{{ errors.maps_url }}</FormHelp
                                >
                                <FormHelp v-else
                                    >Pega el link "Compartir" de Google Maps;
                                    aparece como botón de cómo llegar.</FormHelp
                                >
                            </div>
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Sitio web</label
                                >
                                <div class="relative">
                                    <Lucide
                                        icon="Globe"
                                        :class="iconInput"
                                    />
                                    <FormInput
                                        v-model="form.website"
                                        type="url"
                                        class="pl-9"
                                        placeholder="https://tuhotel.com"
                                    />
                                </div>
                                <FormHelp
                                    v-if="errors.website"
                                    class="text-danger"
                                    >{{ errors.website }}</FormHelp
                                >
                                <FormHelp v-else
                                    >Al pagar en línea, el huésped ve un botón
                                    "Volver al sitio" con esta dirección.</FormHelp
                                >
                            </div>

                            <!-- Teléfonos: varios, cada uno con su lada -->
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Teléfonos</label
                                >
                                <div class="space-y-2">
                                    <div
                                        v-for="(phone, i) in form.phones"
                                        :key="i"
                                        class="flex items-center gap-2"
                                    >
                                        <FormSelect
                                            v-model="phone.code"
                                            class="!w-36"
                                        >
                                            <option value="52">
                                                +52 México
                                            </option>
                                            <option value="1">
                                                +1 EE. UU./Canadá
                                            </option>
                                        </FormSelect>
                                        <FormInput
                                            v-model="phone.number"
                                            type="tel"
                                            placeholder="10 dígitos"
                                        />
                                        <button
                                            v-if="form.phones.length > 1"
                                            type="button"
                                            class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                            title="Quitar teléfono"
                                            @click="form.phones.splice(i, 1)"
                                        >
                                            <Lucide
                                                icon="Trash2"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                    </div>
                                </div>
                                <Button
                                    v-if="form.phones.length < 5"
                                    type="button"
                                    variant="outline-secondary"
                                    size="sm"
                                    class="mt-2 rounded-[0.5rem] bg-white"
                                    @click="
                                        form.phones.push({
                                            code: '52',
                                            number: '',
                                        })
                                    "
                                >
                                    <Lucide
                                        icon="Plus"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Agregar teléfono
                                </Button>
                            </div>

                            <!-- Emails: varios -->
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Emails de contacto</label
                                >
                                <div class="space-y-2">
                                    <div
                                        v-for="(email, i) in form.emails"
                                        :key="i"
                                        class="flex items-center gap-2"
                                    >
                                        <div class="relative flex-1">
                                            <Lucide
                                                icon="Mail"
                                                :class="iconInput"
                                            />
                                            <FormInput
                                                v-model="form.emails[i]"
                                                type="email"
                                                class="pl-9"
                                                placeholder="contacto@hotel.com"
                                            />
                                        </div>
                                        <button
                                            v-if="form.emails.length > 1"
                                            type="button"
                                            class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                            title="Quitar email"
                                            @click="form.emails.splice(i, 1)"
                                        >
                                            <Lucide
                                                icon="Trash2"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                    </div>
                                </div>
                                <Button
                                    v-if="form.emails.length < 5"
                                    type="button"
                                    variant="outline-secondary"
                                    size="sm"
                                    class="mt-2 rounded-[0.5rem] bg-white"
                                    @click="form.emails.push('')"
                                >
                                    <Lucide
                                        icon="Plus"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Agregar email
                                </Button>
                            </div>
                        </div>
                    </div>

                    <!-- Redes sociales: los canales del hotel -->
                    <div class="box box--stacked mt-6 p-5">
                        <div
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Share2" class="h-3.5 w-3.5" /> Redes
                            sociales
                        </div>
                        <p class="mb-3 text-xs text-slate-500">
                            Se muestran en la página de pago y donde el huésped
                            aterriza — para llegarle por todos tus canales.
                        </p>
                        <div v-if="form.socials.length" class="space-y-2">
                            <div
                                v-for="(social, i) in form.socials"
                                :key="i"
                                class="flex items-center gap-2"
                            >
                                <FormSelect
                                    v-model="social.type"
                                    class="!w-40"
                                >
                                    <option
                                        v-for="(label, key) in socialTypes"
                                        :key="key"
                                        :value="key"
                                    >
                                        {{ label }}
                                    </option>
                                </FormSelect>
                                <FormInput
                                    v-model="social.url"
                                    type="url"
                                    placeholder="https://…"
                                />
                                <button
                                    type="button"
                                    class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                    title="Quitar red"
                                    @click="form.socials.splice(i, 1)"
                                >
                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                        <Button
                            v-if="form.socials.length < 10"
                            type="button"
                            variant="outline-secondary"
                            size="sm"
                            class="mt-2 rounded-[0.5rem] bg-white"
                            @click="
                                form.socials.push({
                                    type: 'facebook',
                                    url: '',
                                })
                            "
                        >
                            <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                            Agregar red social
                        </Button>
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
                                <FormSelect v-model="form.currency">
                                    <option value="MXN">
                                        Peso mexicano (MXN)
                                    </option>
                                    <option value="USD">Dólar (USD)</option>
                                </FormSelect>
                                <FormHelp
                                    v-if="errors.currency"
                                    class="text-danger"
                                    >{{ errors.currency }}</FormHelp
                                >
                            </div>
                        </div>

                        <!-- Doble moneda: muestra el "aprox" en la otra divisa -->
                        <div class="mt-4">
                            <label class="mb-1 block text-sm"
                                >¿Mostrar precios en dos monedas?</label
                            >
                            <FormSelect v-model="form.currency_mode">
                                <option value="single">
                                    Solo {{ form.currency }}
                                </option>
                                <option value="both">
                                    Ambas (con tipo de cambio)
                                </option>
                            </FormSelect>
                            <FormHelp
                                >El cobro siempre es en {{ form.currency }}; la
                                segunda moneda se muestra como referencia
                                ("aprox").</FormHelp
                            >
                        </div>
                        <div
                            v-if="form.currency_mode === 'both'"
                            class="mt-3 grid grid-cols-1 gap-4 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 p-3 sm:grid-cols-2 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Segunda moneda</label
                                >
                                <FormSelect v-model="form.currency_secondary">
                                    <option value="USD">Dólar (USD)</option>
                                    <option value="MXN">
                                        Peso mexicano (MXN)
                                    </option>
                                </FormSelect>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Tipo de cambio</label
                                >
                                <FormInput
                                    v-model="form.exchange_rate"
                                    type="number"
                                    step="0.0001"
                                    min="0.0001"
                                    placeholder="18.00"
                                />
                                <FormHelp
                                    >1 {{ form.currency_secondary }} =
                                    {{ form.exchange_rate || '…' }}
                                    {{ form.currency }}</FormHelp
                                >
                                <FormHelp
                                    v-if="errors.exchange_rate"
                                    class="text-danger"
                                    >{{ errors.exchange_rate }}</FormHelp
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
