<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    property: { id: number; name: string; address: string | null };
    logoUrl: string | null;
    settings: {
        phones: { code: string; number: string }[];
        emails: string[];
        website: string;
        maps_url: string;
        socials: { type: string; url: string }[];
        links: { label: string; url: string }[];
    };
}>();

const toast = useToasts();
const saving = ref(false);
const errors = reactive<Record<string, string>>({});

const form = reactive({
    name: props.property.name,
    address: props.property.address ?? '',
    website: props.settings.website,
    maps_url: props.settings.maps_url,
    phones: props.settings.phones.map((x) => ({ ...x })),
    emails: [...props.settings.emails],
    socials: props.settings.socials.map((x) => ({ ...x })),
    links: props.settings.links.map((x) => ({ ...x })),
});

const iconInput =
    'absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400';

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

// ── Contactos y redes en modal ──
// Eran repetidores en línea: al agregar aparecían campos vacíos entre los
// datos ya capturados, y la tarjeta crecía hacia abajo sin control. Ahora la
// lista muestra lo que hay y capturar pasa al modal, como las FAQs.
type ContactoTipo = 'phone' | 'email' | 'social';

const contactModal = ref(false);
const contactKind = ref<ContactoTipo>('phone');
const contactIndex = ref<number | null>(null);
const contactDraft = reactive({
    code: '52',
    number: '',
    email: '',
    type: 'facebook',
    url: '',
});

const contactTitles: Record<ContactoTipo, string> = {
    phone: 'teléfono',
    email: 'correo',
    social: 'red social',
};

function openContact(kind: ContactoTipo, index: number | null) {
    contactKind.value = kind;
    contactIndex.value = index;

    Object.assign(contactDraft, {
        code: '52',
        number: '',
        email: '',
        type: 'facebook',
        url: '',
    });

    if (index !== null) {
        if (kind === 'phone') {
            Object.assign(contactDraft, form.phones[index]);
        } else if (kind === 'email') {
            contactDraft.email = form.emails[index];
        } else {
            Object.assign(contactDraft, form.socials[index]);
        }
    }

    contactModal.value = true;
}

function saveContact() {
    if (contactKind.value === 'phone') {
        const digits = contactDraft.number.replace(/\D/g, '');

        if (digits.length < 10) {
            toast.error('Número incompleto', 'Escribe los 10 dígitos.');
            return;
        }

        const entry = { code: contactDraft.code, number: digits };
        if (contactIndex.value === null) form.phones.push(entry);
        else form.phones[contactIndex.value] = entry;
    } else if (contactKind.value === 'email') {
        const email = contactDraft.email.trim();

        if (!email.includes('@')) {
            toast.error('Correo inválido', 'Revisa la dirección.');
            return;
        }

        if (contactIndex.value === null) form.emails.push(email);
        else form.emails[contactIndex.value] = email;
    } else {
        const url = contactDraft.url.trim();

        if (url === '') {
            toast.error('Falta la liga', 'Pega la dirección de tu perfil.');
            return;
        }

        const entry = { type: contactDraft.type, url };
        if (contactIndex.value === null) form.socials.push(entry);
        else form.socials[contactIndex.value] = entry;
    }

    contactModal.value = false;
}

// Como lo lee una persona: +52 656 750 9087.
const prettyPhone = (code: string, number: string) => {
    const d = (number ?? '').replace(/\D/g, '');

    return d.length === 10
        ? `+${code} ${d.slice(0, 3)} ${d.slice(3, 6)} ${d.slice(6)}`
        : `+${code} ${d}`;
};

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        // El PATCH hace merge en el backend: esta pantalla manda lo suyo.
        await axios.patch(`/api/properties/${props.property.id}`, {
            name: form.name,
            address: form.address || null,
            settings: {
                phones: form.phones.filter((x) => x.number.trim() !== ''),
                emails: form.emails.filter((x) => x.trim() !== ''),
                website: form.website || null,
                maps_url: form.maps_url || null,
                socials: form.socials.filter((x) => x.url.trim() !== ''),
                links: form.links.filter(
                    (x) => x.url.trim() !== '' && x.label.trim() !== '',
                ),
            },
        });
        toast.success('Guardado', 'Los datos de contacto se actualizaron.');
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
</script>

<template>
    <RazeLayout title="Identidad y contacto">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Building2" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Identidad y contacto
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Quién eres, dónde estás y por dónde te escriben.
                            Todo esto alimenta al asistente y a las páginas
                            públicas.
                        </p>
                    </div>
                </div>
                <div
                    class="flex w-full flex-wrap items-center gap-2 md:w-auto md:shrink-0 md:justify-end"
                >
                    <!-- El volver vive con las acciones, no flotando
                         encima de la tarjeta. -->
                    <Link
                        :href="route('tenant.general-settings')"
                        class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Datos generales
                    </Link>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-12 items-start gap-5">
                <div class="col-span-12 xl:col-span-6">
                    <div class="box box--stacked">
                        <div
                            class="border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="Building2"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-sm font-medium">Identidad</h2>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                El nombre y el logo con los que te ve el huésped
                                en el wizard, los correos y la página de pago.
                            </p>
                        </div>
                        <div class="space-y-4 p-5">
                            <div>
                                <label class="mb-1 block text-xs"
                                    >Logo del hotel</label
                                >
                                <div class="flex flex-wrap items-center gap-3">
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
                                    >JPG, PNG o WebP, máximo 2 MB. Aparece junto
                                    al nombre en el wizard público de reservas;
                                    se guarda al momento.</FormHelp
                                >
                            </div>
                            <div>
                                <label class="mb-1 block text-xs"
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
                        </div>
                    </div>

                    <div class="box box--stacked mt-6">
                        <div
                            class="border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="MapPin"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-sm font-medium">Ubicación</h2>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                Dónde estás y cómo llegar.
                            </p>
                        </div>
                        <div class="space-y-4 p-5">
                            <div>
                                <label class="mb-1 block text-xs"
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
                                <label class="mb-1 block text-xs"
                                    >Link de Google Maps</label
                                >
                                <div class="relative">
                                    <Lucide icon="Map" :class="iconInput" />
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
                                <label class="mb-1 block text-xs"
                                    >Sitio web</label
                                >
                                <div class="relative">
                                    <Lucide icon="Globe" :class="iconInput" />
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
                                    "Volver al sitio" con esta
                                    dirección.</FormHelp
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 xl:col-span-6">
                    <div class="box box--stacked">
                        <div
                            class="border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="Phone"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-sm font-medium">Contacto</h2>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                Por dónde te escriben los huéspedes.
                            </p>
                        </div>
                        <div class="space-y-4 p-5">
                            <div>
                                <label class="mb-1 block text-xs"
                                    >Teléfonos</label
                                >
                                <div
                                    v-if="form.phones.length"
                                    class="flex flex-col gap-2"
                                >
                                    <div
                                        v-for="(phone, i) in form.phones"
                                        :key="i"
                                        class="flex items-center gap-2 rounded-lg border border-slate-200/70 p-2.5 dark:border-darkmode-400"
                                    >
                                        <Lucide
                                            icon="Phone"
                                            class="h-4 w-4 shrink-0 text-slate-400"
                                        />
                                        <span
                                            class="min-w-0 flex-1 truncate text-sm"
                                            >{{
                                                prettyPhone(
                                                    phone.code,
                                                    phone.number,
                                                )
                                            }}</span
                                        >
                                        <button
                                            type="button"
                                            class="shrink-0 rounded p-1.5 text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                                            title="Editar"
                                            @click="openContact('phone', i)"
                                        >
                                            <Lucide
                                                icon="Settings"
                                                class="h-3.5 w-3.5"
                                            />
                                        </button>
                                        <button
                                            v-if="form.phones.length > 1"
                                            type="button"
                                            class="shrink-0 rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
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
                                    class="mt-2 rounded-[0.5rem] bg-white"
                                    @click="openContact('phone', null)"
                                >
                                    <Lucide
                                        icon="Plus"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Agregar teléfono
                                </Button>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs"
                                    >Emails de contacto</label
                                >
                                <div
                                    v-if="form.emails.length"
                                    class="flex flex-col gap-2"
                                >
                                    <div
                                        v-for="(email, i) in form.emails"
                                        :key="i"
                                        class="flex items-center gap-2 rounded-lg border border-slate-200/70 p-2.5 dark:border-darkmode-400"
                                    >
                                        <Lucide
                                            icon="Mail"
                                            class="h-4 w-4 shrink-0 text-slate-400"
                                        />
                                        <span
                                            class="min-w-0 flex-1 truncate text-sm"
                                            >{{ email || 'Sin correo' }}</span
                                        >
                                        <button
                                            type="button"
                                            class="shrink-0 rounded p-1.5 text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                                            title="Editar"
                                            @click="openContact('email', i)"
                                        >
                                            <Lucide
                                                icon="Settings"
                                                class="h-3.5 w-3.5"
                                            />
                                        </button>
                                        <button
                                            v-if="form.emails.length > 1"
                                            type="button"
                                            class="shrink-0 rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
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
                                    class="mt-2 rounded-[0.5rem] bg-white"
                                    @click="openContact('email', null)"
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

                    <div class="box box--stacked mt-6">
                        <div
                            class="border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="Share2"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-sm font-medium">
                                    Redes sociales
                                </h2>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                Se muestran en la página de pago y donde el
                                huésped aterriza.
                            </p>
                        </div>
                        <div class="space-y-4 p-5">
                            <div
                                v-if="form.socials.length"
                                class="flex flex-col gap-2"
                            >
                                <div
                                    v-for="(social, i) in form.socials"
                                    :key="i"
                                    class="flex items-center gap-2 rounded-lg border border-slate-200/70 p-2.5 dark:border-darkmode-400"
                                >
                                    <span
                                        class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400"
                                    >
                                        {{
                                            socialTypes[social.type] ??
                                            social.type
                                        }}
                                    </span>
                                    <span
                                        class="min-w-0 flex-1 truncate text-xs text-slate-500"
                                        :title="social.url"
                                        >{{ social.url || 'Sin liga' }}</span
                                    >
                                    <button
                                        type="button"
                                        class="shrink-0 rounded p-1.5 text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                                        title="Editar"
                                        @click="openContact('social', i)"
                                    >
                                        <Lucide
                                            icon="Settings"
                                            class="h-3.5 w-3.5"
                                        />
                                    </button>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                        title="Quitar red"
                                        @click="form.socials.splice(i, 1)"
                                    >
                                        <Lucide icon="Trash2" class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                            <p v-else class="text-xs text-slate-500">
                                Sin redes capturadas.
                            </p>
                            <Button
                                v-if="form.socials.length < 10"
                                type="button"
                                variant="outline-secondary"
                                class="mt-2 rounded-[0.5rem] bg-white"
                                @click="openContact('social', null)"
                            >
                                <Lucide
                                    icon="Plus"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Agregar red social
                            </Button>
                        </div>
                    </div>

                    <div class="box box--stacked mt-6">
                        <div
                            class="border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="Link"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-sm font-medium">
                                    Enlaces útiles
                                </h2>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                Páginas de tu sitio que el asistente puede
                                compartir cuando vienen al caso: recorridos,
                                galería, cómo llegar.
                            </p>
                        </div>
                        <div class="space-y-4 p-5">
                            <div
                                v-if="form.links.length"
                                class="flex flex-col gap-3"
                            >
                                <div
                                    v-for="(link, i) in form.links"
                                    :key="i"
                                    class="flex flex-col gap-2 rounded-lg border border-slate-200/70 p-3 sm:flex-row sm:items-center dark:border-darkmode-400"
                                >
                                    <FormInput
                                        v-model="link.label"
                                        type="text"
                                        class="rounded-[0.5rem] sm:w-44"
                                        placeholder="Recorridos"
                                        maxlength="60"
                                    />
                                    <FormInput
                                        v-model="link.url"
                                        type="url"
                                        class="min-w-0 flex-1 rounded-[0.5rem]"
                                        placeholder="https://tusitio.com/recorridos/"
                                    />
                                    <button
                                        type="button"
                                        class="shrink-0 self-end rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger sm:self-auto"
                                        title="Quitar enlace"
                                        @click="form.links.splice(i, 1)"
                                    >
                                        <Lucide icon="Trash2" class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                            <p v-else class="text-xs text-slate-500">
                                Sin enlaces capturados.
                            </p>
                            <FormHelp v-if="errors['links']">
                                {{ errors['links'] }}
                            </FormHelp>
                            <Button
                                v-if="form.links.length < 6"
                                type="button"
                                variant="outline-secondary"
                                class="mt-2 rounded-[0.5rem] bg-white"
                                @click="form.links.push({ label: '', url: '' })"
                            >
                                <Lucide
                                    icon="Plus"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Agregar enlace
                            </Button>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 flex justify-end">
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        :disabled="saving"
                        @click="submit"
                    >
                        <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                        {{ saving ? 'Guardando…' : 'Guardar' }}
                    </Button>
                </div>
            </div>
        </div>

        <!-- Modal: teléfono, correo o red social -->
        <Dialog :open="contactModal" @close="contactModal = false">
            <Dialog.Panel>
                <Dialog.Title>
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide
                            :icon="
                                contactKind === 'phone'
                                    ? 'Phone'
                                    : contactKind === 'email'
                                      ? 'Mail'
                                      : 'Share2'
                            "
                            class="h-5 w-5"
                        />
                    </div>
                    <h2 class="ml-3 text-base font-medium">
                        {{ contactIndex === null ? 'Agregar' : 'Editar' }}
                        {{ contactTitles[contactKind] }}
                    </h2>
                </Dialog.Title>
                <Dialog.Description>
                    <div v-if="contactKind === 'phone'" class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs">País</label>
                            <FormSelect
                                v-model="contactDraft.code"
                                class="h-9 text-xs"
                            >
                                <option value="52">+52 México</option>
                                <option value="1">+1 EE. UU./Canadá</option>
                            </FormSelect>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs">Teléfono</label>
                            <FormInput
                                v-model="contactDraft.number"
                                type="tel"
                                placeholder="10 dígitos"
                                @keyup.enter="saveContact"
                                class="h-9 text-xs"
                            />
                        </div>
                    </div>

                    <div v-else-if="contactKind === 'email'">
                        <label class="mb-1 block text-xs">Correo</label>
                        <FormInput
                            v-model="contactDraft.email"
                            type="email"
                            placeholder="contacto@hotel.com"
                            @keyup.enter="saveContact"
                            class="h-9 text-xs"
                        />
                    </div>

                    <div v-else class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs">Red</label>
                            <FormSelect
                                v-model="contactDraft.type"
                                class="h-9 text-xs"
                            >
                                <option
                                    v-for="(label, key) in socialTypes"
                                    :key="key"
                                    :value="key"
                                >
                                    {{ label }}
                                </option>
                            </FormSelect>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs">Liga</label>
                            <FormInput
                                v-model="contactDraft.url"
                                type="url"
                                placeholder="https://..."
                                @keyup.enter="saveContact"
                                class="h-9 text-xs"
                            />
                        </div>
                    </div>
                </Dialog.Description>
                <Dialog.Footer>
                    <Button
                        variant="outline-secondary"
                        class="mr-2 w-24"
                        @click="contactModal = false"
                    >
                        Cancelar
                    </Button>
                    <Button variant="primary" class="w-24" @click="saveContact">
                        Aceptar
                    </Button>
                </Dialog.Footer>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
