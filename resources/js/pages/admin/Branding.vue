<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import BrandingLoginMock from '@/components/BrandingLoginMock.vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormLabel,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import SettingsNav from '@/components/SettingsNav.vue';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import { brandTitle, syncBrandName } from '@/lib/brandTitle';

type Overlay = 'strong' | 'medium' | 'light';
type ImageField = 'logo' | 'favicon' | 'login_background';
type TabKey = 'brand' | 'login' | 'support';

interface Settings {
    app_name: string;
    login_heading: string;
    login_hint: string;
    login_title: string;
    login_subtitle: string;
    login_overlay: Overlay;
    support_email: string;
    support_whatsapp: string;
    logo_url: string | null;
    favicon_url: string | null;
    login_background_url: string | null;
}

const props = defineProps<{ settings: Settings }>();

const toast = useToasts();
const page = usePage();

const DEFAULT_NAME = 'KuiraReserve';
const DEFAULT_HINT = 'Ingresa tus credenciales para acceder';
const DEFAULT_SUBTITLE =
    'Reservas, atención por WhatsApp y cobros de tu hotel en un solo lugar.';

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const sectionLabel =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
const fieldIcon =
    'absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400';

// ── Formulario ──────────────────────────────────────────────────────────
const fromSettings = (s: Settings) => ({
    app_name: s.app_name ?? '',
    login_heading: s.login_heading ?? '',
    login_hint: s.login_hint ?? '',
    login_title: s.login_title ?? '',
    login_subtitle: s.login_subtitle ?? '',
    login_overlay: (s.login_overlay ?? 'strong') as Overlay,
    support_email: s.support_email ?? '',
    support_whatsapp: s.support_whatsapp ?? '',
    logo: null as File | null,
    favicon: null as File | null,
    login_background: null as File | null,
});

const form = useForm(fromSettings(props.settings));

// ── Pestañas (se recuerdan en ?tab= para poder ligarlas) ────────────────
const tabs: Array<{ key: TabKey; label: string; icon: Icon }> = [
    { key: 'brand', label: 'Marca', icon: 'Palette' },
    { key: 'login', label: 'Inicio de sesión', icon: 'LogIn' },
    { key: 'support', label: 'Soporte', icon: 'Headset' },
];
const tabOfField: Record<string, TabKey> = {
    app_name: 'brand',
    logo: 'brand',
    favicon: 'brand',
    login_heading: 'login',
    login_hint: 'login',
    login_title: 'login',
    login_subtitle: 'login',
    login_overlay: 'login',
    login_background: 'login',
    support_email: 'support',
    support_whatsapp: 'support',
};

const initialTab = (() => {
    try {
        const tab = new URLSearchParams(window.location.search).get('tab');
        return tabs.some((t) => t.key === tab) ? (tab as TabKey) : 'brand';
    } catch {
        return 'brand' as TabKey;
    }
})();
const activeTab = ref<TabKey>(initialTab);

watch(activeTab, (tab) => {
    const url = new URL(window.location.href);
    if (tab === 'brand') url.searchParams.delete('tab');
    else url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', url);
});

const tabHasError = (tab: TabKey) =>
    Object.keys(form.errors).some((field) => tabOfField[field] === tab);

const tabIsDirty = (tab: TabKey) => {
    const initial = fromSettings(props.settings) as Record<string, unknown>;
    return Object.entries(tabOfField).some(
        ([field, owner]) =>
            owner === tab &&
            (form as unknown as Record<string, unknown>)[field] !==
                initial[field],
    );
};

// ── Imágenes ────────────────────────────────────────────────────────────
const images: Record<
    ImageField,
    {
        label: string;
        hint: string;
        accept: string;
        exts: string[];
        maxKb: number;
        urlKey: 'logo_url' | 'favicon_url' | 'login_background_url';
    }
> = {
    logo: {
        label: 'Logo',
        hint: 'PNG, SVG o WebP de hasta 2 MB. Horizontal o cuadrado, con fondo transparente.',
        accept: '.png,.jpg,.jpeg,.svg,.webp',
        exts: ['png', 'jpg', 'jpeg', 'svg', 'webp'],
        maxKb: 2048,
        urlKey: 'logo_url',
    },
    favicon: {
        label: 'Favicon',
        hint: 'ICO, PNG o SVG cuadrado de hasta 512 KB. Se recomienda 64 x 64 px.',
        accept: '.ico,.png,.svg',
        exts: ['ico', 'png', 'svg'],
        maxKb: 512,
        urlKey: 'favicon_url',
    },
    login_background: {
        label: 'Imagen de fondo',
        hint: 'JPG, PNG o WebP de hasta 4 MB. Horizontal, al menos 1600 px de ancho.',
        accept: '.png,.jpg,.jpeg,.webp',
        exts: ['png', 'jpg', 'jpeg', 'webp'],
        maxKb: 4096,
        urlKey: 'login_background_url',
    },
};

// Vistas previas locales del archivo recién elegido. Una sola URL por
// archivo y se libera al cambiarlo (antes se creaba una en cada render).
const blobs = reactive<Record<ImageField, string | null>>({
    logo: null,
    favicon: null,
    login_background: null,
});
const setBlob = (field: ImageField, file: File | null) => {
    if (blobs[field]) URL.revokeObjectURL(blobs[field] as string);
    blobs[field] = file ? URL.createObjectURL(file) : null;
};
onBeforeUnmount(() =>
    (Object.keys(blobs) as ImageField[]).forEach((f) => setBlob(f, null)),
);

const preview = (field: ImageField) =>
    blobs[field] ?? props.settings[images[field].urlKey];

const inputs: Record<ImageField, HTMLInputElement | null> = {
    logo: null,
    favicon: null,
    login_background: null,
};
const dragging = ref<ImageField | null>(null);

function acceptFile(field: ImageField, file: File | null | undefined) {
    if (!file) return;
    const spec = images[field];
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    form.clearErrors(field);
    if (!spec.exts.includes(ext)) {
        form.setError(
            field,
            `Formato no admitido. Usa ${spec.exts.join(', ').toUpperCase()}.`,
        );
        return;
    }
    if (file.size > spec.maxKb * 1024) {
        form.setError(
            field,
            `El archivo pesa ${(file.size / 1024 / 1024).toFixed(1)} MB; el máximo es ${spec.maxKb >= 1024 ? `${spec.maxKb / 1024} MB` : `${spec.maxKb} KB`}.`,
        );
        return;
    }
    form[field] = file;
    setBlob(field, file);
}

function onPick(field: ImageField, event: Event) {
    const input = event.target as HTMLInputElement;
    acceptFile(field, input.files?.[0]);
    input.value = '';
}

function onDrop(field: ImageField, event: DragEvent) {
    dragging.value = null;
    acceptFile(field, event.dataTransfer?.files?.[0]);
}

function discardPicked(field: ImageField) {
    form[field] = null;
    form.clearErrors(field);
    setBlob(field, null);
}

// Quitar una imagen YA guardada: se confirma y se aplica al momento, sin
// arrastrar los demás cambios pendientes del formulario.
const removing = ref<ImageField | null>(null);
const removeForm = useForm<Record<string, boolean>>({});

function askRemove(field: ImageField) {
    if (form[field]) {
        discardPicked(field);
        return;
    }
    removing.value = field;
}

function confirmRemove() {
    const field = removing.value;
    if (!field) return;
    removeForm
        .transform(() => ({ [`remove_${field}`]: true }))
        .post(route('admin.branding.update'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toast.success(`${images[field].label} quitado`);
                if (field === 'favicon') refreshFavicon();
                removing.value = null;
            },
            onError: () =>
                toast.error('No se pudo quitar', 'Intenta de nuevo.'),
        });
}

// El favicon lo pinta app.blade.php una sola vez: tras guardar o quitar se
// cambia en vivo para no tener que recargar y ver el resultado.
function refreshFavicon() {
    const href = props.settings.favicon_url ?? '/favicon.ico';
    document
        .querySelectorAll<HTMLLinkElement>('link[rel="icon"]')
        .forEach((link) => {
            link.href = href;
            link.removeAttribute('type');
        });
}

// ── Guardar / descartar ─────────────────────────────────────────────────
function submit() {
    form.post(route('admin.branding.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            (Object.keys(blobs) as ImageField[]).forEach((f) =>
                setBlob(f, null),
            );
            form.defaults(fromSettings(props.settings));
            form.reset();
            // La pestaña ya se pintó con el nombre anterior: se corrige aquí.
            syncBrandName(page.props);
            document.title = brandTitle('Marca');
            refreshFavicon();
            toast.success(
                'Marca guardada',
                'El panel, el login y el favicon ya muestran los cambios.',
            );
        },
        onError: (errors) => {
            const first = Object.keys(errors)[0];
            if (first && tabOfField[first]) activeTab.value = tabOfField[first];
            toast.error('Revisa el formulario', Object.values(errors)[0]);
        },
    });
}

function discard() {
    (Object.keys(blobs) as ImageField[]).forEach((f) => setBlob(f, null));
    form.reset();
    form.clearErrors();
}

// ── Valores efectivos (lo que verá la gente si el campo queda vacío) ────
const effective = computed(() => {
    const name = form.app_name.trim() || DEFAULT_NAME;
    return {
        name,
        heading: form.login_heading.trim() || name,
        hint: form.login_hint.trim() || DEFAULT_HINT,
        title: form.login_title.trim() || name,
        subtitle: form.login_subtitle.trim() || DEFAULT_SUBTITLE,
    };
});

const overlays: Array<{ value: Overlay; label: string; help: string }> = [
    { value: 'strong', label: 'Fuerte', help: 'El texto se lee siempre' },
    { value: 'medium', label: 'Medio', help: 'Equilibrio' },
    { value: 'light', label: 'Suave', help: 'Luce más la foto' },
];

// Soporte: mismo criterio que EnsureTenantIsActive (lada 52 a 10 dígitos).
const whatsappDigits = computed(() => {
    const digits = form.support_whatsapp.replace(/\D+/g, '');
    if (!digits) return null;
    return digits.length === 10 ? `52${digits}` : digits;
});

const showLoginPreview = ref(false);
</script>

<template>
    <RazeLayout title="Marca">
        <div class="mt-2">
            <!-- Encabezado de página -->
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
                            Marca de la plataforma
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Nombre, logo, favicon y la pantalla de inicio de
                            sesión. Aplica en el panel central y en los dominios
                            de todos los hoteles.
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Button
                        type="button"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white px-3.5 text-xs dark:bg-darkmode-600"
                        @click="showLoginPreview = true"
                    >
                        <Lucide icon="Maximize2" class="mr-1.5 h-3.5 w-3.5" />
                        Ver login
                    </Button>
                    <Button
                        type="submit"
                        form="brand-form"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] px-4 text-xs"
                        :disabled="form.processing || !form.isDirty"
                    >
                        <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                        {{ form.processing ? 'Guardando...' : 'Guardar' }}
                    </Button>
                </div>
            </div>

            <div class="mt-4 flex flex-col gap-5 lg:flex-row">
                <SettingsNav />

                <form
                    id="brand-form"
                    class="min-w-0 flex-1"
                    @submit.prevent="submit"
                >
                    <div class="box box--stacked overflow-hidden">
                        <!-- Pestañas -->
                        <div
                            class="flex overflow-x-auto border-b border-slate-200/60 px-2 dark:border-darkmode-400"
                        >
                            <button
                                v-for="tab in tabs"
                                :key="tab.key"
                                type="button"
                                :class="[
                                    '-mb-px inline-flex h-11 shrink-0 items-center gap-1.5 border-b-2 px-3.5 text-xs font-medium transition',
                                    activeTab === tab.key
                                        ? 'border-primary text-primary'
                                        : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300',
                                ]"
                                @click="activeTab = tab.key"
                            >
                                <Lucide :icon="tab.icon" class="h-3.5 w-3.5" />
                                {{ tab.label }}
                                <span
                                    v-if="tabHasError(tab.key)"
                                    class="h-1.5 w-1.5 rounded-full bg-danger"
                                    title="Tiene errores"
                                ></span>
                                <span
                                    v-else-if="tabIsDirty(tab.key)"
                                    class="h-1.5 w-1.5 rounded-full bg-warning"
                                    title="Cambios sin guardar"
                                ></span>
                            </button>
                        </div>

                        <div class="grid grid-cols-12">
                            <!-- Campos -->
                            <div
                                class="col-span-12 space-y-6 px-4 py-4 sm:px-5 xl:col-span-7"
                            >
                                <!-- ───── Marca ───── -->
                                <template v-if="activeTab === 'brand'">
                                    <section>
                                        <div :class="sectionLabel">Nombre</div>
                                        <div class="mt-3">
                                            <div
                                                class="flex items-center justify-between"
                                            >
                                                <FormLabel htmlFor="brand-name"
                                                    >Nombre de la
                                                    plataforma</FormLabel
                                                >
                                                <span
                                                    class="text-[11px] text-slate-400"
                                                    >{{
                                                        form.app_name.length
                                                    }}/60</span
                                                >
                                            </div>
                                            <div class="relative">
                                                <Lucide
                                                    icon="Type"
                                                    :class="fieldIcon"
                                                />
                                                <FormInput
                                                    id="brand-name"
                                                    v-model="form.app_name"
                                                    type="text"
                                                    maxlength="60"
                                                    :placeholder="DEFAULT_NAME"
                                                    class="h-9 pl-9 text-xs"
                                                />
                                            </div>
                                            <FormHelp
                                                v-if="form.errors.app_name"
                                                class="text-danger"
                                                >{{
                                                    form.errors.app_name
                                                }}</FormHelp
                                            >
                                            <FormHelp v-else
                                                >Es el título de la pestaña del
                                                navegador, el nombre del menú
                                                lateral del panel central y, si
                                                no pones otro, el encabezado del
                                                login.</FormHelp
                                            >
                                        </div>
                                    </section>

                                    <section>
                                        <div :class="sectionLabel">
                                            Imágenes
                                        </div>
                                        <div
                                            class="mt-3 divide-y divide-slate-200/60 rounded-lg border border-slate-200/70 dark:divide-darkmode-400 dark:border-darkmode-400"
                                        >
                                            <template
                                                v-for="field in [
                                                    'logo',
                                                    'favicon',
                                                ] as ImageField[]"
                                                :key="field"
                                            >
                                                <div
                                                    :class="[
                                                        'flex flex-wrap items-center gap-3 px-3 py-3 transition sm:flex-nowrap',
                                                        dragging === field &&
                                                            'bg-primary/5',
                                                    ]"
                                                    @dragover.prevent="
                                                        dragging = field
                                                    "
                                                    @dragleave="dragging = null"
                                                    @drop.prevent="
                                                        onDrop(field, $event)
                                                    "
                                                >
                                                    <div
                                                        :class="[
                                                            'flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-dashed',
                                                            dragging === field
                                                                ? 'border-primary'
                                                                : 'border-slate-300/80 dark:border-darkmode-400',
                                                            'bg-slate-50 dark:bg-darkmode-700',
                                                        ]"
                                                    >
                                                        <img
                                                            v-if="
                                                                preview(field)
                                                            "
                                                            :src="
                                                                preview(
                                                                    field,
                                                                ) as string
                                                            "
                                                            :alt="
                                                                images[field]
                                                                    .label
                                                            "
                                                            :class="
                                                                field ===
                                                                'favicon'
                                                                    ? 'h-8 w-8 object-contain'
                                                                    : 'max-h-full max-w-full object-contain p-1'
                                                            "
                                                        />
                                                        <Lucide
                                                            v-else
                                                            icon="ImageUp"
                                                            class="h-5 w-5 text-slate-300"
                                                        />
                                                    </div>
                                                    <div class="min-w-0 flex-1">
                                                        <div
                                                            class="flex flex-wrap items-center gap-1.5 text-sm font-medium"
                                                        >
                                                            {{
                                                                images[field]
                                                                    .label
                                                            }}
                                                            <span
                                                                v-if="
                                                                    form[field]
                                                                "
                                                                class="rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                                                                >Sin
                                                                guardar</span
                                                            >
                                                        </div>
                                                        <div
                                                            v-if="
                                                                form.errors[
                                                                    field
                                                                ]
                                                            "
                                                            class="mt-0.5 text-xs text-danger"
                                                        >
                                                            {{
                                                                form.errors[
                                                                    field
                                                                ]
                                                            }}
                                                        </div>
                                                        <div
                                                            v-else
                                                            class="mt-0.5 text-xs text-slate-500"
                                                        >
                                                            {{
                                                                form[field]
                                                                    ?.name ??
                                                                images[field]
                                                                    .hint
                                                            }}
                                                        </div>
                                                    </div>
                                                    <div
                                                        class="flex w-full shrink-0 items-center gap-1 pl-[68px] sm:w-auto sm:pl-0"
                                                    >
                                                        <Button
                                                            type="button"
                                                            variant="outline-secondary"
                                                            class="h-8 rounded-[0.5rem] bg-white px-3 text-xs dark:bg-darkmode-600"
                                                            @click="
                                                                inputs[
                                                                    field
                                                                ]?.click()
                                                            "
                                                        >
                                                            <Lucide
                                                                icon="Upload"
                                                                class="mr-1.5 h-3.5 w-3.5"
                                                            />
                                                            {{
                                                                preview(field)
                                                                    ? 'Cambiar'
                                                                    : 'Subir'
                                                            }}
                                                        </Button>
                                                        <button
                                                            v-if="
                                                                preview(field)
                                                            "
                                                            type="button"
                                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                                            :title="
                                                                form[field]
                                                                    ? 'Descartar archivo elegido'
                                                                    : 'Quitar'
                                                            "
                                                            @click="
                                                                askRemove(field)
                                                            "
                                                        >
                                                            <Lucide
                                                                :icon="
                                                                    form[field]
                                                                        ? 'Undo2'
                                                                        : 'Trash2'
                                                                "
                                                                class="h-4 w-4"
                                                            />
                                                        </button>
                                                    </div>
                                                    <input
                                                        :ref="
                                                            (el) =>
                                                                (inputs[field] =
                                                                    el as HTMLInputElement | null)
                                                        "
                                                        type="file"
                                                        :accept="
                                                            images[field].accept
                                                        "
                                                        class="hidden"
                                                        @change="
                                                            onPick(
                                                                field,
                                                                $event,
                                                            )
                                                        "
                                                    />
                                                </div>
                                            </template>
                                        </div>
                                        <p
                                            class="mt-2 text-[11px] text-slate-400"
                                        >
                                            También puedes arrastrar el archivo
                                            sobre su renglón. Sin logo se usa el
                                            icono genérico; sin favicon, el de
                                            Kuira.
                                        </p>
                                    </section>
                                </template>

                                <!-- ───── Inicio de sesión ───── -->
                                <template v-else-if="activeTab === 'login'">
                                    <div
                                        class="flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                                    >
                                        <Lucide
                                            icon="Info"
                                            class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                                        />
                                        <span
                                            >Esto es el login del dominio
                                            central. En el dominio de cada hotel
                                            se ven su logo, su nombre y sus
                                            colores con la leyenda "Con la
                                            tecnología de {{ effective.name }}";
                                            lo que el hotel no personalice
                                            (fondo, texto de apoyo) sale de
                                            aquí.</span
                                        >
                                    </div>
                                    <section>
                                        <div :class="sectionLabel">
                                            Formulario (lado izquierdo)
                                        </div>
                                        <div
                                            class="mt-3 grid grid-cols-12 gap-4"
                                        >
                                            <div class="col-span-12">
                                                <div
                                                    class="flex items-center justify-between"
                                                >
                                                    <FormLabel
                                                        htmlFor="brand-heading"
                                                        >Encabezado</FormLabel
                                                    >
                                                    <span
                                                        class="text-[11px] text-slate-400"
                                                        >{{
                                                            form.login_heading
                                                                .length
                                                        }}/80</span
                                                    >
                                                </div>
                                                <FormInput
                                                    id="brand-heading"
                                                    v-model="form.login_heading"
                                                    type="text"
                                                    maxlength="80"
                                                    :placeholder="
                                                        effective.name
                                                    "
                                                    class="h-9 text-xs"
                                                />
                                                <FormHelp
                                                    v-if="
                                                        form.errors
                                                            .login_heading
                                                    "
                                                    class="text-danger"
                                                    >{{
                                                        form.errors
                                                            .login_heading
                                                    }}</FormHelp
                                                >
                                                <FormHelp v-else
                                                    >Vacío = el nombre de la
                                                    plataforma. Ejemplo:
                                                    "Bienvenido de
                                                    nuevo".</FormHelp
                                                >
                                            </div>
                                            <div class="col-span-12">
                                                <div
                                                    class="flex items-center justify-between"
                                                >
                                                    <FormLabel
                                                        htmlFor="brand-hint"
                                                        >Instrucción</FormLabel
                                                    >
                                                    <span
                                                        class="text-[11px] text-slate-400"
                                                        >{{
                                                            form.login_hint
                                                                .length
                                                        }}/160</span
                                                    >
                                                </div>
                                                <FormInput
                                                    id="brand-hint"
                                                    v-model="form.login_hint"
                                                    type="text"
                                                    maxlength="160"
                                                    :placeholder="DEFAULT_HINT"
                                                    class="h-9 text-xs"
                                                />
                                                <FormHelp
                                                    v-if="
                                                        form.errors.login_hint
                                                    "
                                                    class="text-danger"
                                                    >{{
                                                        form.errors.login_hint
                                                    }}</FormHelp
                                                >
                                            </div>
                                        </div>
                                    </section>

                                    <section>
                                        <div :class="sectionLabel">
                                            Portada (lado derecho)
                                        </div>
                                        <div class="mt-3 space-y-4">
                                            <div>
                                                <div
                                                    class="flex items-center justify-between"
                                                >
                                                    <FormLabel
                                                        htmlFor="brand-title"
                                                        >Título</FormLabel
                                                    >
                                                    <span
                                                        class="text-[11px] text-slate-400"
                                                        >{{
                                                            form.login_title
                                                                .length
                                                        }}/120</span
                                                    >
                                                </div>
                                                <FormTextarea
                                                    id="brand-title"
                                                    v-model="form.login_title"
                                                    rows="2"
                                                    maxlength="120"
                                                    :placeholder="
                                                        effective.name
                                                    "
                                                    class="text-xs"
                                                />
                                                <FormHelp
                                                    v-if="
                                                        form.errors.login_title
                                                    "
                                                    class="text-danger"
                                                    >{{
                                                        form.errors.login_title
                                                    }}</FormHelp
                                                >
                                                <FormHelp v-else
                                                    >Los saltos de línea se
                                                    respetan. Vacío = el nombre
                                                    de la plataforma.</FormHelp
                                                >
                                            </div>
                                            <div>
                                                <div
                                                    class="flex items-center justify-between"
                                                >
                                                    <FormLabel
                                                        htmlFor="brand-subtitle"
                                                        >Texto de
                                                        apoyo</FormLabel
                                                    >
                                                    <span
                                                        class="text-[11px] text-slate-400"
                                                        >{{
                                                            form.login_subtitle
                                                                .length
                                                        }}/300</span
                                                    >
                                                </div>
                                                <FormTextarea
                                                    id="brand-subtitle"
                                                    v-model="
                                                        form.login_subtitle
                                                    "
                                                    rows="3"
                                                    maxlength="300"
                                                    :placeholder="
                                                        DEFAULT_SUBTITLE
                                                    "
                                                    class="text-xs"
                                                />
                                                <FormHelp
                                                    v-if="
                                                        form.errors
                                                            .login_subtitle
                                                    "
                                                    class="text-danger"
                                                    >{{
                                                        form.errors
                                                            .login_subtitle
                                                    }}</FormHelp
                                                >
                                            </div>

                                            <!-- Imagen de fondo -->
                                            <div
                                                :class="[
                                                    'flex flex-wrap items-center gap-3 rounded-lg border px-3 py-3 transition sm:flex-nowrap',
                                                    dragging ===
                                                    'login_background'
                                                        ? 'border-primary bg-primary/5'
                                                        : 'border-slate-200/70 dark:border-darkmode-400',
                                                ]"
                                                @dragover.prevent="
                                                    dragging =
                                                        'login_background'
                                                "
                                                @dragleave="dragging = null"
                                                @drop.prevent="
                                                    onDrop(
                                                        'login_background',
                                                        $event,
                                                    )
                                                "
                                            >
                                                <div
                                                    class="flex h-14 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-dashed border-slate-300/80 bg-slate-50 dark:border-darkmode-400 dark:bg-darkmode-700"
                                                >
                                                    <img
                                                        v-if="
                                                            preview(
                                                                'login_background',
                                                            )
                                                        "
                                                        :src="
                                                            preview(
                                                                'login_background',
                                                            ) as string
                                                        "
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
                                                    <div
                                                        class="flex flex-wrap items-center gap-1.5 text-sm font-medium"
                                                    >
                                                        Imagen de fondo
                                                        <span
                                                            v-if="
                                                                form.login_background
                                                            "
                                                            class="rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                                                            >Sin guardar</span
                                                        >
                                                    </div>
                                                    <div
                                                        v-if="
                                                            form.errors
                                                                .login_background
                                                        "
                                                        class="mt-0.5 text-xs text-danger"
                                                    >
                                                        {{
                                                            form.errors
                                                                .login_background
                                                        }}
                                                    </div>
                                                    <div
                                                        v-else
                                                        class="mt-0.5 truncate text-xs text-slate-500"
                                                    >
                                                        {{
                                                            form
                                                                .login_background
                                                                ?.name ??
                                                            images
                                                                .login_background
                                                                .hint
                                                        }}
                                                    </div>
                                                </div>
                                                <div
                                                    class="flex w-full shrink-0 items-center gap-1 pl-[92px] sm:w-auto sm:pl-0"
                                                >
                                                    <Button
                                                        type="button"
                                                        variant="outline-secondary"
                                                        class="h-8 rounded-[0.5rem] bg-white px-3 text-xs dark:bg-darkmode-600"
                                                        @click="
                                                            inputs.login_background?.click()
                                                        "
                                                    >
                                                        <Lucide
                                                            icon="Upload"
                                                            class="mr-1.5 h-3.5 w-3.5"
                                                        />
                                                        {{
                                                            preview(
                                                                'login_background',
                                                            )
                                                                ? 'Cambiar'
                                                                : 'Subir'
                                                        }}
                                                    </Button>
                                                    <button
                                                        v-if="
                                                            preview(
                                                                'login_background',
                                                            )
                                                        "
                                                        type="button"
                                                        class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                                        :title="
                                                            form.login_background
                                                                ? 'Descartar archivo elegido'
                                                                : 'Quitar'
                                                        "
                                                        @click="
                                                            askRemove(
                                                                'login_background',
                                                            )
                                                        "
                                                    >
                                                        <Lucide
                                                            :icon="
                                                                form.login_background
                                                                    ? 'Undo2'
                                                                    : 'Trash2'
                                                            "
                                                            class="h-4 w-4"
                                                        />
                                                    </button>
                                                </div>
                                                <input
                                                    :ref="
                                                        (el) =>
                                                            (inputs.login_background =
                                                                el as HTMLInputElement | null)
                                                    "
                                                    type="file"
                                                    :accept="
                                                        images.login_background
                                                            .accept
                                                    "
                                                    class="hidden"
                                                    @change="
                                                        onPick(
                                                            'login_background',
                                                            $event,
                                                        )
                                                    "
                                                />
                                            </div>

                                            <!-- Velo -->
                                            <div>
                                                <FormLabel
                                                    >Velo sobre la
                                                    imagen</FormLabel
                                                >
                                                <div
                                                    class="grid grid-cols-3 gap-2"
                                                >
                                                    <button
                                                        v-for="option in overlays"
                                                        :key="option.value"
                                                        type="button"
                                                        :disabled="
                                                            !preview(
                                                                'login_background',
                                                            )
                                                        "
                                                        :class="[
                                                            'rounded-lg border px-3 py-2 text-left transition disabled:cursor-not-allowed disabled:opacity-50',
                                                            form.login_overlay ===
                                                            option.value
                                                                ? 'border-primary bg-primary/5'
                                                                : 'border-slate-200/70 hover:border-slate-300 dark:border-darkmode-400',
                                                        ]"
                                                        @click="
                                                            form.login_overlay =
                                                                option.value
                                                        "
                                                    >
                                                        <div
                                                            :class="[
                                                                'text-xs font-medium',
                                                                form.login_overlay ===
                                                                    option.value &&
                                                                    'text-primary',
                                                            ]"
                                                        >
                                                            {{ option.label }}
                                                        </div>
                                                        <div
                                                            class="truncate text-[11px] text-slate-500"
                                                        >
                                                            {{ option.help }}
                                                        </div>
                                                    </button>
                                                </div>
                                                <FormHelp
                                                    >Tiñe la foto con los
                                                    colores del theme para que
                                                    el texto blanco se lea. Sin
                                                    imagen queda el degradado
                                                    del theme.</FormHelp
                                                >
                                            </div>
                                        </div>
                                    </section>
                                </template>

                                <!-- ───── Soporte ───── -->
                                <template v-else>
                                    <section>
                                        <div :class="sectionLabel">
                                            Contacto de soporte
                                        </div>
                                        <p class="mt-1 text-xs text-slate-500">
                                            Se ofrece en la pantalla de hotel
                                            suspendido y en las páginas de
                                            error. Un canal en blanco no se
                                            muestra.
                                        </p>
                                        <div
                                            class="mt-3 grid grid-cols-12 gap-4"
                                        >
                                            <div
                                                class="col-span-12 sm:col-span-6"
                                            >
                                                <FormLabel
                                                    htmlFor="brand-support-whatsapp"
                                                    >WhatsApp</FormLabel
                                                >
                                                <div class="relative">
                                                    <Lucide
                                                        icon="MessageCircle"
                                                        :class="fieldIcon"
                                                    />
                                                    <FormInput
                                                        id="brand-support-whatsapp"
                                                        v-model="
                                                            form.support_whatsapp
                                                        "
                                                        type="tel"
                                                        maxlength="30"
                                                        placeholder="6141234567"
                                                        class="h-9 pl-9 text-xs"
                                                    />
                                                </div>
                                                <FormHelp
                                                    v-if="
                                                        form.errors
                                                            .support_whatsapp
                                                    "
                                                    class="text-danger"
                                                    >{{
                                                        form.errors
                                                            .support_whatsapp
                                                    }}</FormHelp
                                                >
                                                <FormHelp v-else
                                                    >A 10 dígitos se le agrega
                                                    la lada 52.</FormHelp
                                                >
                                            </div>
                                            <div
                                                class="col-span-12 sm:col-span-6"
                                            >
                                                <FormLabel
                                                    htmlFor="brand-support-email"
                                                    >Correo</FormLabel
                                                >
                                                <div class="relative">
                                                    <Lucide
                                                        icon="Mail"
                                                        :class="fieldIcon"
                                                    />
                                                    <FormInput
                                                        id="brand-support-email"
                                                        v-model="
                                                            form.support_email
                                                        "
                                                        type="email"
                                                        maxlength="120"
                                                        placeholder="soporte@ejemplo.com"
                                                        class="h-9 pl-9 text-xs"
                                                    />
                                                </div>
                                                <FormHelp
                                                    v-if="
                                                        form.errors
                                                            .support_email
                                                    "
                                                    class="text-danger"
                                                    >{{
                                                        form.errors
                                                            .support_email
                                                    }}</FormHelp
                                                >
                                            </div>
                                        </div>
                                    </section>
                                </template>
                            </div>

                            <!-- Vista previa -->
                            <div
                                class="col-span-12 border-t border-slate-200/60 bg-slate-50/70 px-4 py-4 sm:px-5 xl:col-span-5 xl:border-t-0 xl:border-l dark:border-darkmode-400 dark:bg-darkmode-700/40"
                            >
                                <div
                                    class="flex items-center justify-between gap-2"
                                >
                                    <div :class="sectionLabel">
                                        Vista previa
                                    </div>
                                    <button
                                        v-if="activeTab === 'login'"
                                        type="button"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-primary"
                                        @click="showLoginPreview = true"
                                    >
                                        <Lucide
                                            icon="Maximize2"
                                            class="h-3.5 w-3.5"
                                        />
                                        Ampliar
                                    </button>
                                </div>

                                <!-- Marca: pestaña, menú lateral y login -->
                                <div
                                    v-if="activeTab === 'brand'"
                                    class="mt-3 space-y-4"
                                >
                                    <div>
                                        <div
                                            class="mb-1.5 text-[11px] text-slate-500"
                                        >
                                            Pestaña del navegador
                                        </div>
                                        <div
                                            class="rounded-lg border border-slate-200/70 bg-slate-200/60 px-2 pt-2 dark:border-darkmode-400 dark:bg-darkmode-800"
                                        >
                                            <div
                                                class="flex w-60 max-w-full items-center gap-2 rounded-t-lg bg-white px-3 py-2 dark:bg-darkmode-600"
                                            >
                                                <img
                                                    v-if="preview('favicon')"
                                                    :src="
                                                        preview(
                                                            'favicon',
                                                        ) as string
                                                    "
                                                    alt=""
                                                    class="h-4 w-4 shrink-0 object-contain"
                                                />
                                                <Lucide
                                                    v-else
                                                    icon="Globe"
                                                    class="h-4 w-4 shrink-0 text-slate-400"
                                                />
                                                <span
                                                    class="truncate text-xs text-slate-600 dark:text-slate-300"
                                                    >Dashboard -
                                                    {{ effective.name }}</span
                                                >
                                                <Lucide
                                                    icon="X"
                                                    class="ml-auto h-3 w-3 shrink-0 text-slate-400"
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <div
                                            class="mb-1.5 text-[11px] text-slate-500"
                                        >
                                            Menú lateral del panel central
                                        </div>
                                        <div
                                            class="flex items-center gap-3 rounded-lg bg-linear-to-b from-theme-1 to-theme-2 px-4 py-3"
                                        >
                                            <div
                                                class="flex h-[38px] w-[38px] shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white/8"
                                            >
                                                <img
                                                    v-if="preview('logo')"
                                                    :src="
                                                        preview(
                                                            'logo',
                                                        ) as string
                                                    "
                                                    alt=""
                                                    class="h-full w-full rounded-lg bg-white object-contain p-0.5"
                                                />
                                                <Lucide
                                                    v-else
                                                    icon="Building2"
                                                    class="h-5 w-5 text-white"
                                                />
                                            </div>
                                            <div
                                                class="truncate text-sm font-medium text-white"
                                            >
                                                {{ effective.name }}
                                            </div>
                                        </div>
                                        <p
                                            class="mt-1.5 text-[11px] text-slate-400"
                                        >
                                            En el panel de cada hotel se usa el
                                            logo y el nombre del hotel.
                                        </p>
                                    </div>

                                    <div>
                                        <div
                                            class="mb-1.5 text-[11px] text-slate-500"
                                        >
                                            Login
                                        </div>
                                        <BrandingLoginMock
                                            :app-name="effective.name"
                                            :logo-url="preview('logo')"
                                            :heading="effective.heading"
                                            :hint="effective.hint"
                                            :title="effective.title"
                                            :subtitle="effective.subtitle"
                                            :background-url="
                                                preview('login_background')
                                            "
                                            :overlay="form.login_overlay"
                                        />
                                    </div>
                                </div>

                                <!-- Login -->
                                <div
                                    v-else-if="activeTab === 'login'"
                                    class="mt-3"
                                >
                                    <BrandingLoginMock
                                        :app-name="effective.name"
                                        :logo-url="preview('logo')"
                                        :heading="effective.heading"
                                        :hint="effective.hint"
                                        :title="effective.title"
                                        :subtitle="effective.subtitle"
                                        :background-url="
                                            preview('login_background')
                                        "
                                        :overlay="form.login_overlay"
                                    />
                                    <p class="mt-2 text-[11px] text-slate-400">
                                        Guía proporcional; en el celular solo se
                                        ve el formulario.
                                    </p>
                                </div>

                                <!-- Soporte: así lo ve un hotel suspendido -->
                                <div v-else class="mt-3">
                                    <div
                                        class="rounded-xl border border-slate-200/70 bg-white p-4 dark:border-darkmode-400 dark:bg-darkmode-600"
                                    >
                                        <div class="flex items-center gap-3">
                                            <div
                                                :class="[
                                                    sectionIcon,
                                                    'border-warning/10 bg-warning/10 text-warning',
                                                ]"
                                            >
                                                <Lucide
                                                    icon="CirclePause"
                                                    class="h-4 w-4"
                                                />
                                            </div>
                                            <div class="min-w-0">
                                                <div
                                                    class="text-sm font-medium"
                                                >
                                                    Panel suspendido
                                                </div>
                                                <div
                                                    class="text-xs text-slate-500"
                                                >
                                                    Así lo ve un hotel con la
                                                    cuenta suspendida.
                                                </div>
                                            </div>
                                        </div>
                                        <div
                                            v-if="
                                                whatsappDigits ||
                                                form.support_email.trim()
                                            "
                                            class="mt-4 flex flex-wrap gap-2"
                                        >
                                            <a
                                                v-if="whatsappDigits"
                                                :href="`https://wa.me/${whatsappDigits}`"
                                                target="_blank"
                                                rel="noopener"
                                                class="inline-flex h-8 items-center gap-1.5 rounded-[0.5rem] bg-success px-3 text-xs font-medium text-white"
                                                title="Abre el chat para probar el número"
                                            >
                                                <Lucide
                                                    icon="MessageCircle"
                                                    class="h-3.5 w-3.5"
                                                />
                                                WhatsApp
                                            </a>
                                            <a
                                                v-if="form.support_email.trim()"
                                                :href="`mailto:${form.support_email.trim()}`"
                                                class="inline-flex h-8 items-center gap-1.5 rounded-[0.5rem] border border-slate-200 px-3 text-xs font-medium text-slate-600 dark:border-darkmode-400 dark:text-slate-300"
                                            >
                                                <Lucide
                                                    icon="Mail"
                                                    class="h-3.5 w-3.5"
                                                />
                                                Correo
                                            </a>
                                        </div>
                                        <div
                                            v-else
                                            class="mt-4 rounded-lg border border-dashed border-warning/40 bg-warning/5 px-3 py-2 text-xs text-warning"
                                        >
                                            Sin contacto configurado: el hotel
                                            suspendido no tendrá a quién
                                            escribir.
                                        </div>
                                    </div>
                                    <p
                                        v-if="whatsappDigits"
                                        class="mt-2 text-[11px] text-slate-400"
                                    >
                                        Número que se usará: +{{
                                            whatsappDigits
                                        }}. El botón abre el chat para que lo
                                        pruebes.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Pie -->
                        <div
                            class="flex flex-col gap-2 border-t border-slate-200/60 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5 dark:border-darkmode-400"
                        >
                            <div
                                class="inline-flex items-center gap-1.5 text-xs"
                                :class="
                                    form.isDirty
                                        ? 'text-warning'
                                        : 'text-slate-500'
                                "
                            >
                                <Lucide
                                    :icon="
                                        form.isDirty
                                            ? 'CircleDot'
                                            : 'CircleCheck'
                                    "
                                    class="h-3.5 w-3.5"
                                />
                                {{
                                    form.isDirty
                                        ? 'Hay cambios sin guardar'
                                        : 'Todo guardado'
                                }}
                            </div>
                            <div class="flex items-center justify-end gap-2">
                                <Button
                                    v-if="form.isDirty"
                                    type="button"
                                    variant="outline-secondary"
                                    class="h-9 rounded-[0.5rem] px-4 text-xs"
                                    :disabled="form.processing"
                                    @click="discard"
                                >
                                    Descartar
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    class="h-9 rounded-[0.5rem] px-5 text-xs"
                                    :disabled="form.processing || !form.isDirty"
                                >
                                    <Lucide
                                        :icon="
                                            form.processing ? 'Loader' : 'Check'
                                        "
                                        :class="[
                                            'mr-1.5 h-3.5 w-3.5',
                                            form.processing && 'animate-spin',
                                        ]"
                                    />
                                    {{
                                        form.processing
                                            ? 'Guardando...'
                                            : 'Guardar cambios'
                                    }}
                                </Button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Confirmar quitar imagen guardada -->
        <Dialog :open="removing !== null" @close="removing = null">
            <Dialog.Panel>
                <div class="flex items-start gap-3 p-5">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                    >
                        <Lucide icon="Trash2" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-base font-medium">
                            ¿Quitar
                            {{
                                removing
                                    ? images[removing].label.toLowerCase()
                                    : ''
                            }}?
                        </h2>
                        <p class="mt-1 text-xs text-slate-500">
                            <template v-if="removing === 'logo'"
                                >El login y el menú del panel central vuelven al
                                icono genérico.</template
                            >
                            <template v-else-if="removing === 'favicon'"
                                >La pestaña del navegador vuelve al icono de
                                Kuira.</template
                            >
                            <template v-else
                                >El lado derecho del login vuelve al degradado
                                del theme.</template
                            >
                            Se aplica de inmediato en el panel central y en los
                            hoteles; el archivo se borra del servidor.
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
                        :disabled="removeForm.processing"
                        @click="removing = null"
                        >Cancelar</Button
                    >
                    <Button
                        type="button"
                        variant="danger"
                        class="h-9 px-5 text-xs"
                        :disabled="removeForm.processing"
                        @click="confirmRemove"
                        >{{
                            removeForm.processing ? 'Quitando...' : 'Sí, quitar'
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
                                    form.isDirty
                                        ? 'Incluye los cambios que aún no guardas.'
                                        : 'Así se ve hoy en el panel central y en los hoteles.'
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
                            :app-name="effective.name"
                            :logo-url="preview('logo')"
                            :heading="effective.heading"
                            :hint="effective.hint"
                            :title="effective.title"
                            :subtitle="effective.subtitle"
                            :background-url="preview('login_background')"
                            :overlay="form.login_overlay"
                        />
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 px-5 text-xs"
                            @click="showLoginPreview = false"
                            >Cerrar</Button
                        >
                        <Button
                            v-if="activeTab !== 'login'"
                            type="button"
                            variant="primary"
                            class="h-9 px-5 text-xs"
                            @click="
                                activeTab = 'login';
                                showLoginPreview = false;
                            "
                        >
                            <Lucide icon="PenLine" class="mr-1.5 h-3.5 w-3.5" />
                            Editar el login
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
