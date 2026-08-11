<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Lucide from '@/components/Base/Lucide';

interface RegistroService {
    key: string;
    label: string;
}

const props = withDefaults(
    defineProps<{
        services: RegistroService[];
    }>(),
    {
        services: () => [],
    },
);

const page = usePage();
const brandName = computed(
    () =>
        (page.props.branding as { app_name?: string } | undefined)?.app_name ||
        'KuiraReserve',
);
const brandLogo = computed(
    () =>
        (page.props.branding as { logo_url?: string | null } | undefined)
            ?.logo_url ?? null,
);

const submitted = ref(false);

const serviceIcons: Record<string, string> = {
    web: 'Globe2',
    social: 'Share2',
    reservas: 'CalendarCheck2',
};

const phoneCodes = [
    { value: '+52', label: 'México +52' },
    { value: '+1', label: 'Estados Unidos +1' },
    { value: 'otro', label: 'Otra lada' },
];

const form = useForm({
    name: '',
    hotel_name: '',
    email: '',
    phone_code: '+52',
    phone_code_other: '',
    phone: '',
    has_whatsapp: false,
    services: [] as string[],
    privacy: false,
    website: '',
});

function toggleService(key: string): void {
    if (form.services.includes(key)) {
        form.services = form.services.filter((service) => service !== key);
    } else {
        form.services = [...form.services, key];
    }
}

function submit(): void {
    submitted.value = false;
    form.transform((data) => ({
        ...data,
        phone_code:
            data.phone_code === 'otro'
                ? data.phone_code_other
                : data.phone_code,
    })).post(route('prospects.register.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            submitted.value = true;
        },
    });
}
</script>

<template>
    <div class="registro-page min-h-screen bg-slate-100">
        <Head title="Registro" />

        <header class="border-b border-slate-200/70 bg-white">
            <div
                class="mx-auto flex max-w-3xl items-center justify-between px-5 py-4"
            >
                <div class="flex items-center gap-3">
                    <img
                        v-if="brandLogo"
                        :src="brandLogo"
                        :alt="brandName"
                        class="h-9 w-9 rounded-lg object-contain"
                    />
                    <span
                        v-else
                        class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary text-white"
                        ><Lucide icon="Building2" class="h-4 w-4"
                    /></span>
                    <span class="text-sm font-semibold text-slate-900">{{
                        brandName
                    }}</span>
                </div>
                <Link
                    :href="route('home')"
                    class="text-xs font-medium text-slate-500 transition hover:text-primary"
                    >Conocer más</Link
                >
            </div>
        </header>

        <main class="relative overflow-hidden px-5 py-10 sm:py-14">
            <div class="registro-orb" />
            <div class="relative mx-auto max-w-5xl">
                <div class="text-center">
                    <span class="registro-eyebrow">Registro de interés</span>
                    <h1
                        class="mt-4 text-2xl leading-tight font-semibold tracking-tight text-slate-950 sm:text-3xl"
                    >
                        Cuéntanos qué necesita tu hotel
                    </h1>
                    <p
                        class="mx-auto mt-3 max-w-md text-sm leading-6 text-slate-600"
                    >
                        Elige los servicios que te interesan y déjanos tus
                        datos. Te enviaremos la información a tu correo en unos
                        minutos.
                    </p>
                </div>

                <div
                    class="mt-8 rounded-2xl border border-white bg-white p-6 shadow-2xl shadow-slate-300/40 sm:p-8"
                >
                    <div
                        v-if="submitted"
                        class="flex min-h-[420px] flex-col items-center justify-center text-center"
                    >
                        <span
                            class="flex h-20 w-20 items-center justify-center rounded-full bg-success/10"
                            ><Lucide
                                icon="CheckCircle2"
                                class="h-10 w-10 text-success"
                        /></span>
                        <h2 class="mt-6 text-2xl font-semibold text-slate-900">
                            ¡Gracias por registrarte!
                        </h2>
                        <p
                            class="mt-3 max-w-sm text-sm leading-6 text-slate-500"
                        >
                            Te enviamos la información a tu correo. Muy pronto
                            nos pondremos en contacto contigo.
                        </p>
                        <button
                            class="mt-7 text-sm font-semibold text-primary hover:underline"
                            @click="submitted = false"
                        >
                            Registrar a alguien más
                        </button>
                    </div>

                    <form v-else @submit.prevent="submit">
                        <h2 class="text-sm font-semibold text-slate-700">
                            ¿Qué servicios te interesan? *
                        </h2>
                        <p class="mt-1 text-xs text-slate-500">
                            Puedes elegir uno o varios.
                        </p>
                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            <button
                                v-for="service in props.services"
                                :key="service.key"
                                type="button"
                                class="registro-service"
                                :class="{
                                    'is-selected': form.services.includes(
                                        service.key,
                                    ),
                                }"
                                @click="toggleService(service.key)"
                            >
                                <span
                                    class="flex h-10 w-10 items-center justify-center rounded-xl border border-primary/10 bg-primary/10 text-primary"
                                    ><Lucide
                                        :icon="
                                            (serviceIcons[service.key] ??
                                                'Check') as any
                                        "
                                        class="h-4 w-4"
                                /></span>
                                <span
                                    class="text-sm font-medium text-slate-800"
                                    >{{ service.label }}</span
                                >
                                <span class="registro-service-check"
                                    ><Lucide icon="Check" class="h-3 w-3"
                                /></span>
                            </button>
                        </div>
                        <small
                            v-if="form.errors.services"
                            class="mt-2 block text-xs text-danger"
                            >{{ form.errors.services }}</small
                        >

                        <div class="mt-7 grid gap-5 sm:grid-cols-2">
                            <label class="registro-field"
                                ><span>Nombre completo *</span
                                ><input
                                    v-model="form.name"
                                    type="text"
                                    autocomplete="name"
                                    placeholder="Tu nombre y apellidos"
                                /><small v-if="form.errors.name">{{
                                    form.errors.name
                                }}</small></label
                            >
                            <label class="registro-field"
                                ><span>Empresa (hotel o motel) *</span
                                ><input
                                    v-model="form.hotel_name"
                                    type="text"
                                    autocomplete="organization"
                                    placeholder="Nombre de tu empresa"
                                /><small v-if="form.errors.hotel_name">{{
                                    form.errors.hotel_name
                                }}</small></label
                            >
                            <label class="registro-field"
                                ><span>Correo electrónico *</span
                                ><input
                                    v-model="form.email"
                                    type="email"
                                    autocomplete="email"
                                    placeholder="tu@hotel.com"
                                /><small v-if="form.errors.email">{{
                                    form.errors.email
                                }}</small></label
                            >
                            <label class="registro-field"
                                ><span>Teléfono *</span>
                                <div class="flex gap-2">
                                    <select
                                        v-model="form.phone_code"
                                        class="registro-select shrink-0"
                                    >
                                        <option
                                            v-for="code in phoneCodes"
                                            :key="code.value"
                                            :value="code.value"
                                        >
                                            {{ code.label }}
                                        </option>
                                    </select>
                                    <div
                                        v-if="form.phone_code === 'otro'"
                                        class="w-20 shrink-0"
                                    >
                                        <input
                                            v-model="form.phone_code_other"
                                            type="text"
                                            inputmode="tel"
                                            placeholder="+34"
                                        />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <input
                                            v-model="form.phone"
                                            type="tel"
                                            autocomplete="tel-national"
                                            placeholder="000 000 0000"
                                        />
                                    </div>
                                </div>
                                <small v-if="form.errors.phone_code">{{
                                    form.errors.phone_code
                                }}</small>
                                <small v-if="form.errors.phone">{{
                                    form.errors.phone
                                }}</small></label
                            >
                        </div>

                        <label
                            class="mt-5 flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700"
                            ><input
                                v-model="form.has_whatsapp"
                                type="checkbox"
                                class="rounded border-slate-300 text-primary focus:ring-primary"
                            /><Lucide
                                icon="MessageCircle"
                                class="h-4 w-4 text-success"
                            /><span
                                >Este teléfono cuenta con WhatsApp</span
                            ></label
                        >

                        <label class="sr-only" aria-hidden="true"
                            >Sitio web<input
                                v-model="form.website"
                                tabindex="-1"
                                autocomplete="off"
                        /></label>

                        <label
                            class="mt-5 flex cursor-pointer items-start gap-3 text-xs leading-5 text-slate-500"
                            ><input
                                v-model="form.privacy"
                                type="checkbox"
                                class="mt-0.5 rounded border-slate-300 text-primary focus:ring-primary"
                            /><span
                                >Acepto que mis datos sean usados para atender
                                esta solicitud comercial.</span
                            ></label
                        >
                        <small
                            v-if="form.errors.privacy"
                            class="mt-1 block text-xs text-danger"
                            >{{ form.errors.privacy }}</small
                        >

                        <button
                            type="submit"
                            class="group mt-6 flex w-full items-center justify-center rounded-xl bg-primary px-6 py-3.5 font-medium text-white shadow-lg shadow-primary/20 transition hover:bg-theme-2 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="form.processing"
                        >
                            <span>{{
                                form.processing
                                    ? 'Enviando registro...'
                                    : 'Enviar mi registro'
                            }}</span
                            ><Lucide
                                v-if="!form.processing"
                                icon="ArrowRight"
                                class="ml-2 h-4 w-4 transition-transform group-hover:translate-x-1"
                            />
                        </button>
                        <p class="mt-4 text-center text-[11px] text-slate-400">
                            <Lucide
                                icon="LockKeyhole"
                                class="mr-1 inline h-3 w-3"
                            />
                            Tus datos se almacenan de forma segura.
                        </p>
                    </form>
                </div>
            </div>
        </main>
    </div>
</template>

<style scoped>
@reference "../../css/app.css";

.registro-page {
    font-family: 'Public Sans', sans-serif;
}

.registro-eyebrow {
    @apply inline-flex rounded-full border border-primary/10 bg-primary/5 px-3 py-1.5 text-xs font-semibold tracking-wider text-primary uppercase;
}

.registro-field {
    @apply block;
}

.registro-field > span {
    @apply mb-2 block text-xs font-semibold text-slate-700;
}

.registro-field input {
    @apply w-full rounded-lg border-slate-200 bg-slate-50 px-3.5 py-3 text-sm text-slate-800 transition placeholder:text-slate-400 focus:border-primary focus:bg-white focus:ring-2 focus:ring-primary/10;
}

.registro-field small {
    @apply mt-1 block text-xs text-danger;
}

.registro-select {
    @apply rounded-lg border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-800 transition focus:border-primary focus:bg-white focus:ring-2 focus:ring-primary/10;
}

.registro-service {
    @apply relative flex flex-col items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-left transition hover:border-primary/30 hover:bg-white;
}

.registro-service.is-selected {
    @apply border-primary bg-white ring-2 ring-primary/10;
}

.registro-service-check {
    @apply absolute top-3 right-3 flex h-5 w-5 items-center justify-center rounded-full border border-slate-200 bg-white text-transparent transition;
}

.registro-service.is-selected .registro-service-check {
    @apply border-primary bg-primary text-white;
}

.registro-orb {
    position: absolute;
    top: -10rem;
    right: -8rem;
    height: 32rem;
    width: 32rem;
    border-radius: 9999px;
    filter: blur(1px);
    pointer-events: none;
    background: radial-gradient(circle, rgb(8 145 178 / 0.13), transparent 68%);
}
</style>
