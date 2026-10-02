<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import BrandingLoginMock from '@/components/BrandingLoginMock.vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormLabel,
    FormSwitch,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    property: { id: number; name: string };
    settings: {
        panel_primary: string | null;
        panel_menu_from: string | null;
        panel_menu_to: string | null;
    };
    login: {
        enabled: boolean;
        hint: string;
        title: string;
        subtitle: string;
        logo_url: string | null;
        background_url: string | null;
        url: string;
    };
    platform: {
        app_name: string;
        logo_url: string | null;
        login_subtitle: string | null;
        login_background_url: string | null;
    };
}>();

const toast = useToasts();
const saving = ref(false);

// Colores del theme Kuira (los mismos de resources/css/app.css); sin
// overrides guardados el panel usa exactamente estos.
const DEFAULTS = {
    primary: '#03045e',
    menu_from: '#03045e',
    menu_to: '#0c4a6e',
};

const form = reactive({
    primary: props.settings.panel_primary ?? DEFAULTS.primary,
    menu_from: props.settings.panel_menu_from ?? DEFAULTS.menu_from,
    menu_to: props.settings.panel_menu_to ?? DEFAULTS.menu_to,
});

// Temas listos: acento + degradado del menú, pensados para texto blanco
// encima (el menú lateral siempre escribe en blanco).
const presets: {
    name: string;
    primary: string;
    menu_from: string;
    menu_to: string;
}[] = [
    { name: 'Kuira (original)', ...DEFAULTS },
    {
        name: 'Océano',
        primary: '#0e7490',
        menu_from: '#164e63',
        menu_to: '#0e7490',
    },
    {
        name: 'Esmeralda',
        primary: '#047857',
        menu_from: '#064e3b',
        menu_to: '#047857',
    },
    {
        name: 'Vino',
        primary: '#9f1239',
        menu_from: '#4c0519',
        menu_to: '#881337',
    },
    {
        name: 'Púrpura',
        primary: '#6d28d9',
        menu_from: '#2e1065',
        menu_to: '#5b21b6',
    },
    {
        name: 'Medianoche',
        primary: '#1d4ed8',
        menu_from: '#172554',
        menu_to: '#1e3a8a',
    },
    {
        name: 'Cacao',
        primary: '#92400e',
        menu_from: '#451a03',
        menu_to: '#78350f',
    },
    {
        name: 'Grafito',
        primary: '#334155',
        menu_from: '#0f172a',
        menu_to: '#334155',
    },
];

const isPresetActive = (p: (typeof presets)[number]) =>
    form.primary.toLowerCase() === p.primary &&
    form.menu_from.toLowerCase() === p.menu_from &&
    form.menu_to.toLowerCase() === p.menu_to;

function applyPreset(p: (typeof presets)[number]) {
    form.primary = p.primary;
    form.menu_from = p.menu_from;
    form.menu_to = p.menu_to;
}

const isDefaultTheme = computed(() => isPresetActive(presets[0]));

// Pisa (o limpia) las variables del theme en <html> — lo mismo que hace
// RazeLayout al cargar, para ver el cambio sin recargar la página.
function applyToPanel(colors: {
    primary: string | null;
    menu_from: string | null;
    menu_to: string | null;
}) {
    const rootStyle = document.documentElement.style;
    const apply = (cssVar: string, value: string | null) =>
        value
            ? rootStyle.setProperty(cssVar, value)
            : rootStyle.removeProperty(cssVar);
    apply('--color-primary', colors.primary);
    apply('--color-theme-1', colors.menu_from);
    apply('--color-theme-2', colors.menu_to);
}

async function save(colors: {
    primary: string | null;
    menu_from: string | null;
    menu_to: string | null;
}) {
    saving.value = true;
    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: {
                panel_primary: colors.primary,
                panel_menu_from: colors.menu_from,
                panel_menu_to: colors.menu_to,
            },
        });
        applyToPanel(colors);
        toast.success(
            'Apariencia guardada',
            colors.primary
                ? 'El panel ya usa los colores de tu hotel; aplica para todo tu equipo.'
                : 'El panel volvió al tema original.',
        );
    } catch (e: any) {
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Revisa los colores elegidos.',
        );
    } finally {
        saving.value = false;
    }
}

// Guardar el tema Kuira = limpiar overrides (null), no fijar los hex:
// así futuros ajustes del theme de la plataforma llegan solos al hotel.
const submit = () =>
    save(
        isDefaultTheme.value
            ? { primary: null, menu_from: null, menu_to: null }
            : {
                  primary: form.primary,
                  menu_from: form.menu_from,
                  menu_to: form.menu_to,
              },
    );

// ── Login en el dominio del hotel ───────────────────────────────────────
const DEFAULT_HINT = 'Ingresa tus credenciales para acceder';
const DEFAULT_SUBTITLE =
    'Reservas, atención por WhatsApp y cobros de tu hotel en un solo lugar.';
const platformSubtitle = props.platform.login_subtitle || DEFAULT_SUBTITLE;

const loginSaved = reactive({
    enabled: props.login.enabled,
    hint: props.login.hint ?? '',
    title: props.login.title ?? '',
    subtitle: props.login.subtitle ?? '',
});
const loginForm = reactive({ ...loginSaved });
const savingLogin = ref(false);
const loginDirty = computed(
    () =>
        loginForm.enabled !== loginSaved.enabled ||
        loginForm.hint !== loginSaved.hint ||
        loginForm.title !== loginSaved.title ||
        loginForm.subtitle !== loginSaved.subtitle,
);
const loginErrors = ref<Record<string, string>>({});

const backgroundUrl = ref<string | null>(props.login.background_url);
const backgroundInput = ref<HTMLInputElement | null>(null);
const uploadingBackground = ref(false);
const confirmRemoveBackground = ref(false);
const showLoginPreview = ref(false);

// Lo que verá el equipo: con la marca del hotel encendida, lo del hotel y
// lo que falte de la plataforma; apagada, el login de la plataforma tal cual.
const preview = computed(() =>
    loginForm.enabled
        ? {
              appName: props.property.name,
              logoUrl: props.login.logo_url ?? props.platform.logo_url,
              heading: props.property.name,
              hint: loginForm.hint.trim() || DEFAULT_HINT,
              title: loginForm.title.trim() || props.property.name,
              subtitle: loginForm.subtitle.trim() || platformSubtitle,
              backgroundUrl:
                  backgroundUrl.value ?? props.platform.login_background_url,
              poweredBy: props.platform.app_name,
          }
        : {
              appName: props.platform.app_name,
              logoUrl: props.platform.logo_url,
              heading: props.platform.app_name,
              hint: DEFAULT_HINT,
              title: props.platform.app_name,
              subtitle: platformSubtitle,
              backgroundUrl: props.platform.login_background_url,
              poweredBy: null,
          },
);

async function saveLogin() {
    savingLogin.value = true;
    loginErrors.value = {};
    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: {
                login_brand_enabled: loginForm.enabled,
                login_hint: loginForm.hint.trim() || null,
                login_title: loginForm.title.trim() || null,
                login_subtitle: loginForm.subtitle.trim() || null,
            },
        });
        Object.assign(loginSaved, loginForm);
        toast.success(
            'Login guardado',
            loginForm.enabled
                ? 'Tu equipo ya ve la marca del hotel al entrar.'
                : 'El login vuelve a mostrar la marca de la plataforma.',
        );
    } catch (e: any) {
        const errors = e.response?.data?.errors ?? {};
        loginErrors.value = Object.fromEntries(
            Object.entries(errors).map(([key, msgs]) => [
                key.replace('settings.login_', ''),
                (msgs as string[])[0],
            ]),
        );
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Revisa los textos del login.',
        );
    } finally {
        savingLogin.value = false;
    }
}

function discardLogin() {
    Object.assign(loginForm, loginSaved);
    loginErrors.value = {};
}

async function onPickBackground(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        toast.error('Formato no admitido', 'Usa JPG, PNG o WebP.');
        return;
    }
    if (file.size > 4 * 1024 * 1024) {
        toast.error(
            'La imagen pesa demasiado',
            `Pesa ${(file.size / 1024 / 1024).toFixed(1)} MB; el máximo es 4 MB.`,
        );
        return;
    }
    uploadingBackground.value = true;
    try {
        const body = new FormData();
        body.append('background', file);
        const { data } = await axios.post('/api/login-background', body);
        backgroundUrl.value = data.background_url;
        toast.success('Fondo actualizado', 'Ya se ve en el login del hotel.');
    } catch (e: any) {
        toast.error(
            'No se pudo subir',
            e.response?.data?.errors?.background?.[0] ??
                e.response?.data?.message ??
                'Intenta con otra imagen.',
        );
    } finally {
        uploadingBackground.value = false;
    }
}

async function removeBackground() {
    uploadingBackground.value = true;
    try {
        await axios.delete('/api/login-background');
        backgroundUrl.value = null;
        confirmRemoveBackground.value = false;
        toast.success(
            'Fondo quitado',
            'El login usa el fondo de la plataforma.',
        );
    } catch {
        toast.error('No se pudo quitar', 'Intenta de nuevo.');
    } finally {
        uploadingBackground.value = false;
    }
}

function resetTheme() {
    applyPreset(presets[0]);
    save({ primary: null, menu_from: null, menu_to: null });
}
</script>

<template>
    <RazeLayout title="Apariencia del panel">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Palette" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Apariencia del panel
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Los colores del panel de {{ property.name }} y la
                            pantalla de inicio de sesión de tu equipo. El wizard
                            público tiene su propia apariencia.
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

            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                <!-- Temas listos + colores personalizados -->
                <div class="col-span-12 xl:col-span-7">
                    <div class="box box--stacked p-5">
                        <div
                            class="mb-1 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="SwatchBook" class="h-3.5 w-3.5" />
                            Temas listos
                        </div>
                        <p class="mb-4 text-xs text-slate-500">
                            Elige uno y guárdalo, o úsalo como punto de partida
                            y afínalo abajo.
                        </p>
                        <div class="grid grid-cols-12 gap-3">
                            <button
                                v-for="preset in presets"
                                :key="preset.name"
                                type="button"
                                class="col-span-6 rounded-lg border p-3 text-left transition sm:col-span-4 xl:col-span-3"
                                :class="
                                    isPresetActive(preset)
                                        ? 'border-primary/60 ring-1 ring-primary/30'
                                        : 'border-slate-200/70 hover:border-primary/30 dark:border-darkmode-400'
                                "
                                @click="applyPreset(preset)"
                            >
                                <span
                                    class="block h-9 w-full rounded-md"
                                    :style="{
                                        background: `linear-gradient(135deg, ${preset.menu_from}, ${preset.menu_to})`,
                                    }"
                                />
                                <span
                                    class="mt-2 flex items-center gap-1.5 text-xs font-medium"
                                >
                                    <span
                                        class="h-3 w-3 shrink-0 rounded-full"
                                        :style="{
                                            backgroundColor: preset.primary,
                                        }"
                                    />
                                    <span class="truncate">{{
                                        preset.name
                                    }}</span>
                                </span>
                            </button>
                        </div>

                        <div
                            class="mt-5 border-t border-dashed border-slate-300/70 pt-4 dark:border-darkmode-400"
                        >
                            <div
                                class="mb-1 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide icon="Pipette" class="h-3.5 w-3.5" />
                                Colores personalizados
                            </div>
                            <p class="mb-4 text-xs text-slate-500">
                                El menú lateral siempre escribe en blanco: usa
                                colores oscuros para que se lea bien.
                            </p>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <label class="mb-1 block text-xs"
                                        >Botones y acentos</label
                                    >
                                    <div class="flex items-center gap-2">
                                        <FormInput
                                            v-model="form.primary"
                                            type="color"
                                            class="!h-10 !w-14 shrink-0 !p-1"
                                        />
                                        <span
                                            class="font-mono text-xs text-slate-500 uppercase"
                                            >{{ form.primary }}</span
                                        >
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs"
                                        >Menú lateral (arriba)</label
                                    >
                                    <div class="flex items-center gap-2">
                                        <FormInput
                                            v-model="form.menu_from"
                                            type="color"
                                            class="!h-10 !w-14 shrink-0 !p-1"
                                        />
                                        <span
                                            class="font-mono text-xs text-slate-500 uppercase"
                                            >{{ form.menu_from }}</span
                                        >
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs"
                                        >Menú lateral (abajo)</label
                                    >
                                    <div class="flex items-center gap-2">
                                        <FormInput
                                            v-model="form.menu_to"
                                            type="color"
                                            class="!h-10 !w-14 shrink-0 !p-1"
                                        />
                                        <span
                                            class="font-mono text-xs text-slate-500 uppercase"
                                            >{{ form.menu_to }}</span
                                        >
                                    </div>
                                </div>
                            </div>
                            <FormHelp>
                                El degradado del menú va de "arriba" hacia
                                "abajo"; con el mismo color en ambos queda
                                sólido.
                            </FormHelp>
                        </div>

                        <div class="mt-5 flex flex-wrap justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline-secondary"
                                class="h-9 rounded-[0.5rem] bg-white text-xs"
                                :disabled="saving || isDefaultTheme"
                                title="Borra los colores del hotel y regresa al tema Kuira"
                                @click="resetTheme"
                            >
                                <Lucide icon="RotateCcw" class="mr-2 h-4 w-4" />
                                Restablecer tema original
                            </Button>
                            <Button
                                type="button"
                                variant="primary"
                                class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                                :disabled="saving"
                                @click="submit"
                            >
                                <Lucide
                                    icon="Check"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                {{
                                    saving ? 'Guardando…' : 'Guardar apariencia'
                                }}
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Vista previa en vivo de lo elegido -->
                <div class="col-span-12 flex flex-col xl:col-span-5">
                    <div class="box box--stacked flex flex-1 flex-col p-5">
                        <div
                            class="mb-1 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Eye" class="h-3.5 w-3.5" /> Vista
                            previa
                        </div>
                        <p class="mb-4 text-xs text-slate-500">
                            Así se verá el panel; el cambio real se aplica al
                            guardar.
                        </p>
                        <div
                            class="flex flex-1 overflow-hidden rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                        >
                            <!-- Mini menú lateral -->
                            <div
                                class="flex w-32 shrink-0 flex-col gap-2 p-3 text-white"
                                :style="{
                                    background: `linear-gradient(to bottom, ${form.menu_from}, ${form.menu_to})`,
                                }"
                            >
                                <div class="flex items-center gap-2">
                                    <span
                                        class="flex h-6 w-6 items-center justify-center rounded-md bg-white/10"
                                    >
                                        <Lucide
                                            icon="Building2"
                                            class="h-3.5 w-3.5"
                                        />
                                    </span>
                                    <span class="truncate text-[10px]">{{
                                        property.name
                                    }}</span>
                                </div>
                                <div
                                    class="mt-2 rounded bg-white/15 px-2 py-1.5 text-[10px]"
                                >
                                    Dashboard
                                </div>
                                <div
                                    class="px-2 py-1.5 text-[10px] text-white/70"
                                >
                                    Reservas
                                </div>
                                <div
                                    class="px-2 py-1.5 text-[10px] text-white/70"
                                >
                                    Hotel
                                </div>
                                <div
                                    class="px-2 py-1.5 text-[10px] text-white/70"
                                >
                                    Ventas
                                </div>
                            </div>
                            <!-- Mini contenido -->
                            <div
                                class="flex flex-1 flex-col gap-3 bg-slate-50 p-4 dark:bg-darkmode-600"
                            >
                                <div
                                    class="text-sm font-medium"
                                    :style="{ color: form.primary }"
                                >
                                    Título con acento
                                </div>
                                <div
                                    class="rounded-lg border border-slate-200/70 bg-white p-3 dark:border-darkmode-400 dark:bg-darkmode-500"
                                >
                                    <div
                                        class="mb-2 h-2 w-2/3 rounded bg-slate-200 dark:bg-darkmode-300"
                                    />
                                    <div
                                        class="h-2 w-1/2 rounded bg-slate-100 dark:bg-darkmode-400"
                                    />
                                </div>
                                <div class="mt-auto flex gap-2">
                                    <span
                                        class="rounded-md px-3 py-1.5 text-[11px] font-medium text-white"
                                        :style="{
                                            backgroundColor: form.primary,
                                        }"
                                    >
                                        Botón principal
                                    </span>
                                    <span
                                        class="rounded-md border px-3 py-1.5 text-[11px] font-medium"
                                        :style="{
                                            borderColor: form.primary,
                                            color: form.primary,
                                        }"
                                    >
                                        Secundario
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div
                            class="mt-3 flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                            />
                            <span
                                >Los colores de estado (verde disponible, rojo
                                errores, el semáforo de habitaciones) no
                                cambian: son parte del lenguaje del
                                sistema.</span
                            >
                        </div>
                    </div>
                </div>
            </div>

            <!-- Login del hotel -->
            <div class="box box--stacked mt-4 overflow-hidden">
                <div
                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="LogIn" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium">
                            Pantalla de inicio de sesión
                        </div>
                        <div class="text-xs break-words text-slate-500">
                            Lo que ve tu equipo al entrar en
                            {{ props.login.url.replace(/^https?:\/\//, '') }}
                        </div>
                    </div>
                    <label
                        class="flex w-full cursor-pointer items-center gap-2 pl-[46px] text-xs text-slate-600 sm:w-auto sm:pl-0 dark:text-slate-300"
                    >
                        <FormSwitch>
                            <FormSwitch.Input
                                v-model="loginForm.enabled"
                                type="checkbox"
                            />
                        </FormSwitch>
                        Usar la marca del hotel
                    </label>
                </div>

                <div class="grid grid-cols-12">
                    <div
                        class="col-span-12 space-y-5 px-4 py-4 sm:px-5 xl:col-span-7"
                    >
                        <div
                            v-if="!loginForm.enabled"
                            class="flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                            />
                            <span
                                >Apagado: el login de tu hotel se ve igual que
                                el de {{ platform.app_name }}, con su logo y sus
                                textos.</span
                            >
                        </div>

                        <template v-else>
                            <section>
                                <div
                                    class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    Identidad
                                </div>
                                <div
                                    class="mt-3 flex flex-wrap items-center gap-3 rounded-lg border border-slate-200/70 px-3 py-3 sm:flex-nowrap dark:border-darkmode-400"
                                >
                                    <div
                                        class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-dashed border-slate-300/80 bg-slate-50 dark:border-darkmode-400 dark:bg-darkmode-700"
                                    >
                                        <img
                                            v-if="props.login.logo_url"
                                            :src="props.login.logo_url"
                                            :alt="property.name"
                                            class="max-h-full max-w-full object-contain p-1"
                                        />
                                        <Lucide
                                            v-else
                                            icon="ImageOff"
                                            class="h-5 w-5 text-slate-300"
                                        />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium">
                                            {{ property.name }}
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            {{
                                                props.login.logo_url
                                                    ? 'Logo y nombre del hotel, los mismos del wizard y los correos.'
                                                    : 'Sin logo: se usa el de la plataforma. Súbelo en Contacto.'
                                            }}
                                        </div>
                                    </div>
                                    <div
                                        class="w-full pl-[68px] sm:w-auto sm:pl-0"
                                    >
                                        <Link
                                            :href="
                                                route(
                                                    'tenant.general-settings.contact',
                                                )
                                            "
                                            class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-[0.5rem] border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600 dark:text-slate-300"
                                        >
                                            <Lucide
                                                icon="PenLine"
                                                class="h-3.5 w-3.5"
                                            />
                                            {{
                                                props.login.logo_url
                                                    ? 'Cambiar'
                                                    : 'Subir logo'
                                            }}
                                        </Link>
                                    </div>
                                </div>
                                <p class="mt-2 text-[11px] text-slate-400">
                                    Los colores del login son los del panel
                                    (arriba). Abajo del formulario siempre
                                    aparece "Con la tecnología de
                                    {{ platform.app_name }}".
                                </p>
                            </section>

                            <section>
                                <div
                                    class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    Textos
                                </div>
                                <div class="mt-3 space-y-4">
                                    <div>
                                        <div
                                            class="flex items-center justify-between"
                                        >
                                            <FormLabel htmlFor="login-hint"
                                                >Instrucción del
                                                formulario</FormLabel
                                            >
                                            <span
                                                class="text-[11px] text-slate-400"
                                                >{{
                                                    loginForm.hint.length
                                                }}/160</span
                                            >
                                        </div>
                                        <FormInput
                                            id="login-hint"
                                            v-model="loginForm.hint"
                                            type="text"
                                            maxlength="160"
                                            :placeholder="DEFAULT_HINT"
                                            class="h-9 text-xs"
                                        />
                                        <FormHelp
                                            v-if="loginErrors.hint"
                                            class="text-danger"
                                            >{{ loginErrors.hint }}</FormHelp
                                        >
                                    </div>
                                    <div>
                                        <div
                                            class="flex items-center justify-between"
                                        >
                                            <FormLabel htmlFor="login-title"
                                                >Título de la portada</FormLabel
                                            >
                                            <span
                                                class="text-[11px] text-slate-400"
                                                >{{
                                                    loginForm.title.length
                                                }}/120</span
                                            >
                                        </div>
                                        <FormTextarea
                                            id="login-title"
                                            v-model="loginForm.title"
                                            rows="2"
                                            maxlength="120"
                                            :placeholder="property.name"
                                            class="text-xs"
                                        />
                                        <FormHelp
                                            v-if="loginErrors.title"
                                            class="text-danger"
                                            >{{ loginErrors.title }}</FormHelp
                                        >
                                        <FormHelp v-else
                                            >Vacío = el nombre del hotel. Los
                                            saltos de línea se
                                            respetan.</FormHelp
                                        >
                                    </div>
                                    <div>
                                        <div
                                            class="flex items-center justify-between"
                                        >
                                            <FormLabel htmlFor="login-subtitle"
                                                >Texto de apoyo</FormLabel
                                            >
                                            <span
                                                class="text-[11px] text-slate-400"
                                                >{{
                                                    loginForm.subtitle.length
                                                }}/300</span
                                            >
                                        </div>
                                        <FormTextarea
                                            id="login-subtitle"
                                            v-model="loginForm.subtitle"
                                            rows="3"
                                            maxlength="300"
                                            :placeholder="platformSubtitle"
                                            class="text-xs"
                                        />
                                        <FormHelp
                                            v-if="loginErrors.subtitle"
                                            class="text-danger"
                                            >{{
                                                loginErrors.subtitle
                                            }}</FormHelp
                                        >
                                    </div>
                                </div>
                            </section>

                            <section>
                                <div
                                    class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    Foto de fondo
                                </div>
                                <div
                                    class="mt-3 flex flex-wrap items-center gap-3 rounded-lg border border-slate-200/70 px-3 py-3 sm:flex-nowrap dark:border-darkmode-400"
                                >
                                    <div
                                        class="flex h-14 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-dashed border-slate-300/80 bg-slate-50 dark:border-darkmode-400 dark:bg-darkmode-700"
                                    >
                                        <img
                                            v-if="backgroundUrl"
                                            :src="backgroundUrl"
                                            alt="Fondo del login"
                                            class="h-full w-full object-cover"
                                        />
                                        <Lucide
                                            v-else
                                            icon="ImageUp"
                                            class="h-5 w-5 text-slate-300"
                                        />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium">
                                            {{
                                                backgroundUrl
                                                    ? 'Foto del hotel'
                                                    : 'Fondo de la plataforma'
                                            }}
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            JPG, PNG o WebP de hasta 4 MB,
                                            horizontal. Se tiñe con los colores
                                            del panel para que el texto se lea.
                                        </div>
                                    </div>
                                    <div
                                        class="flex w-full shrink-0 items-center gap-1 pl-[92px] sm:w-auto sm:pl-0"
                                    >
                                        <Button
                                            type="button"
                                            variant="outline-secondary"
                                            class="h-8 rounded-[0.5rem] bg-white px-3 text-xs dark:bg-darkmode-600"
                                            :disabled="uploadingBackground"
                                            @click="backgroundInput?.click()"
                                        >
                                            <Lucide
                                                :icon="
                                                    uploadingBackground
                                                        ? 'Loader'
                                                        : 'Upload'
                                                "
                                                :class="[
                                                    'mr-1.5 h-3.5 w-3.5',
                                                    uploadingBackground &&
                                                        'animate-spin',
                                                ]"
                                            />
                                            {{
                                                backgroundUrl
                                                    ? 'Cambiar'
                                                    : 'Subir foto'
                                            }}
                                        </Button>
                                        <button
                                            v-if="backgroundUrl"
                                            type="button"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                            title="Quitar"
                                            :disabled="uploadingBackground"
                                            @click="
                                                confirmRemoveBackground = true
                                            "
                                        >
                                            <Lucide
                                                icon="Trash2"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                    </div>
                                    <input
                                        ref="backgroundInput"
                                        type="file"
                                        accept=".jpg,.jpeg,.png,.webp"
                                        class="hidden"
                                        @change="onPickBackground"
                                    />
                                </div>
                            </section>
                        </template>
                    </div>

                    <!-- Vista previa -->
                    <div
                        class="col-span-12 border-t border-slate-200/60 bg-slate-50/70 px-4 py-4 sm:px-5 xl:col-span-5 xl:border-t-0 xl:border-l dark:border-darkmode-400 dark:bg-darkmode-700/40"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div
                                class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Vista previa
                            </div>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 text-xs font-medium text-primary"
                                @click="showLoginPreview = true"
                            >
                                <Lucide icon="Maximize2" class="h-3.5 w-3.5" />
                                Ampliar
                            </button>
                        </div>
                        <BrandingLoginMock
                            class="mt-3"
                            :app-name="preview.appName"
                            :logo-url="preview.logoUrl"
                            :heading="preview.heading"
                            :hint="preview.hint"
                            :title="preview.title"
                            :subtitle="preview.subtitle"
                            :background-url="preview.backgroundUrl"
                            overlay="strong"
                            :powered-by="preview.poweredBy"
                        />
                        <p class="mt-2 text-[11px] text-slate-400">
                            Usa los colores guardados del panel. En el celular
                            solo se ve el formulario.
                        </p>
                    </div>
                </div>

                <div
                    class="flex flex-col gap-2 border-t border-slate-200/60 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5 dark:border-darkmode-400"
                >
                    <div
                        class="inline-flex items-center gap-1.5 text-xs"
                        :class="loginDirty ? 'text-warning' : 'text-slate-500'"
                    >
                        <Lucide
                            :icon="loginDirty ? 'CircleDot' : 'CircleCheck'"
                            class="h-3.5 w-3.5"
                        />
                        {{
                            loginDirty
                                ? 'Hay cambios sin guardar'
                                : 'Todo guardado'
                        }}
                    </div>
                    <div class="flex items-center justify-end gap-2">
                        <Button
                            v-if="loginDirty"
                            type="button"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-4 text-xs"
                            :disabled="savingLogin"
                            @click="discardLogin"
                        >
                            Descartar
                        </Button>
                        <Button
                            type="button"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="savingLogin || !loginDirty"
                            @click="saveLogin"
                        >
                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                            {{ savingLogin ? 'Guardando...' : 'Guardar login' }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Confirmar quitar la foto de fondo -->
        <Dialog
            :open="confirmRemoveBackground"
            @close="confirmRemoveBackground = false"
        >
            <Dialog.Panel>
                <div class="flex items-start gap-3 p-5">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                    >
                        <Lucide icon="Trash2" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-base font-medium">
                            ¿Quitar la foto de fondo?
                        </h2>
                        <p class="mt-1 text-xs text-slate-500">
                            El login de tu hotel vuelve al fondo de
                            {{ platform.app_name }}. Se aplica de inmediato.
                        </p>
                    </div>
                </div>
                <div
                    class="flex justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <Button
                        type="button"
                        variant="outline-secondary"
                        class="h-9 px-5 text-xs"
                        :disabled="uploadingBackground"
                        @click="confirmRemoveBackground = false"
                        >Cancelar</Button
                    >
                    <Button
                        type="button"
                        variant="danger"
                        class="h-9 px-5 text-xs"
                        :disabled="uploadingBackground"
                        @click="removeBackground"
                        >{{
                            uploadingBackground ? 'Quitando...' : 'Sí, quitar'
                        }}</Button
                    >
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Login a tamaño grande -->
        <Dialog
            :open="showLoginPreview"
            size="xl"
            @close="showLoginPreview = false"
        >
            <Dialog.Panel class="sm:w-[94vw] lg:w-[1040px]">
                <div class="flex max-h-[calc(100dvh-6rem)] flex-col">
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="MonitorSmartphone" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                Vista previa del login
                            </h2>
                            <p class="text-xs text-slate-500">
                                {{
                                    loginDirty
                                        ? 'Incluye los cambios que aún no guardas.'
                                        : 'Así lo ve hoy tu equipo.'
                                }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            title="Cerrar"
                            @click="showLoginPreview = false"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                        <BrandingLoginMock
                            large
                            :app-name="preview.appName"
                            :logo-url="preview.logoUrl"
                            :heading="preview.heading"
                            :hint="preview.hint"
                            :title="preview.title"
                            :subtitle="preview.subtitle"
                            :background-url="preview.backgroundUrl"
                            overlay="strong"
                            :powered-by="preview.poweredBy"
                        />
                    </div>
                    <div
                        class="flex justify-end border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 px-5 text-xs"
                            @click="showLoginPreview = false"
                            >Cerrar</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
