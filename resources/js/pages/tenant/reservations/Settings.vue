<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormTextarea } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import {
    contrastRatio,
    onAccentColor,
    TITLE_FONT_STACKS,
} from '@/lib/colorContrast';

interface Texts {
    tagline: string;
    title: string;
    subtitle: string;
    search_button: string;
}

interface Appearance {
    bg_from: string;
    bg_to: string;
    accent: string;
    theme: string;
    header_text?: string;
    title_color?: string | null;
    button_text?: string;
    title_font?: string;
    texts?: Texts;
    logo_url: string | null;
}

const props = defineProps<{
    property: { id: number; name: string };
    wizardUrl: string;
    appearance: Appearance;
    defaults: {
        bg_from: string;
        bg_to: string;
        accent: string;
        theme: string;
        header_text: string;
        title_color: string | null;
        button_text: string;
        title_font: string;
    };
    textDefaults: Texts;
    canManage: boolean;
}>();

const toast = useToasts();

const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary';
const rowLabel = 'text-xs font-medium text-slate-700 dark:text-slate-300';

// ── Estado del formulario ──
// Los textos se guardan vacíos cuando son el de fábrica: así, si algún día
// se mejora el texto de fábrica, al hotel le llega sin hacer nada.
function textValue(key: keyof Texts): string {
    const v = props.appearance.texts?.[key] ?? '';
    return v === props.textDefaults[key] ? '' : v;
}

function snapshot() {
    return {
        bg_from: props.appearance.bg_from,
        bg_to: props.appearance.bg_to,
        accent: props.appearance.accent,
        theme: props.appearance.theme,
        header_text: props.appearance.header_text ?? props.defaults.header_text,
        title_color: props.appearance.title_color ?? '',
        button_text: props.appearance.button_text ?? 'auto',
        title_font: props.appearance.title_font ?? 'default',
        tagline: textValue('tagline'),
        title: textValue('title'),
        subtitle: textValue('subtitle'),
        search_button: textValue('search_button'),
    };
}

type FormState = ReturnType<typeof snapshot>;
const form = reactive<FormState>(snapshot());
const saved = ref<FormState>({ ...form });
const dirty = computed(() =>
    (Object.keys(form) as (keyof FormState)[]).some(
        (k) => form[k] !== saved.value[k],
    ),
);
const busy = ref(false);

function discard() {
    Object.assign(form, saved.value);
}

// Cerrar la pestaña con cambios sin guardar los perdía en silencio.
function onBeforeUnload(e: BeforeUnloadEvent) {
    if (!dirty.value) return;
    e.preventDefault();
    e.returnValue = '';
}
onMounted(() => window.addEventListener('beforeunload', onBeforeUnload));
onBeforeUnmount(() =>
    window.removeEventListener('beforeunload', onBeforeUnload),
);

// ── Colores ──
const HEX = /^#[0-9a-fA-F]{6}$/;

type ColorKey = 'bg_from' | 'bg_to' | 'accent' | 'header_text' | 'title_color';

function setHex(key: ColorKey, value: string | number) {
    const hex = String(value ?? '').trim();
    if (HEX.test(hex)) form[key] = hex.toLowerCase();
}

const palettes: {
    name: string;
    bg_from: string;
    bg_to: string;
    accent: string;
    header_text: string;
}[] = [
    {
        name: 'Marino',
        bg_from: '#03045e',
        bg_to: '#0c4a6e',
        accent: '#03045e',
        header_text: '#ffffff',
    },
    {
        name: 'Bosque',
        bg_from: '#14532d',
        bg_to: '#365314',
        accent: '#166534',
        header_text: '#ffffff',
    },
    {
        name: 'Atardecer',
        bg_from: '#7c2d12',
        bg_to: '#be123c',
        accent: '#c2410c',
        header_text: '#ffffff',
    },
    {
        name: 'Arena',
        bg_from: '#f5efe6',
        bg_to: '#e7d8c3',
        accent: '#8a5a2b',
        header_text: '#3f2a14',
    },
    {
        name: 'Carbón',
        bg_from: '#0d0d0d',
        bg_to: '#262626',
        accent: '#a8873b',
        header_text: '#ffffff',
    },
    {
        name: 'Lavanda',
        bg_from: '#4c1d95',
        bg_to: '#6d28d9',
        accent: '#6d28d9',
        header_text: '#ffffff',
    },
];

const colorRows: { key: ColorKey; label: string; help: string }[] = [
    { key: 'bg_from', label: 'Fondo (arriba)', help: 'Inicio del degradado.' },
    {
        key: 'bg_to',
        label: 'Fondo (abajo)',
        help: 'Fin del degradado; igual al de arriba para un fondo liso.',
    },
    {
        key: 'accent',
        label: 'Acento',
        help: 'Botones, precios y detalles destacados.',
    },
    {
        key: 'header_text',
        label: 'Texto sobre el fondo',
        help: 'Nombre del hotel, pasos y pie de página.',
    },
];

function applyPalette(p: (typeof palettes)[number]) {
    form.bg_from = p.bg_from;
    form.bg_to = p.bg_to;
    form.accent = p.accent;
    form.header_text = p.header_text;
}

function resetColors() {
    form.bg_from = props.defaults.bg_from;
    form.bg_to = props.defaults.bg_to;
    form.accent = props.defaults.accent;
    form.header_text = props.defaults.header_text;
    form.title_color = '';
    form.button_text = 'auto';
}

const buttonTextColor = computed(() =>
    onAccentColor(form.accent, form.button_text),
);

// Contraste real de cada combinación (WCAG): el texto sobre el fondo se
// mide contra el color más desfavorable del degradado.
interface ContrastCheck {
    label: string;
    ratio: number;
    min: number;
}
const checks = computed<ContrastCheck[]>(() => [
    {
        label: 'Texto sobre el fondo',
        ratio: Math.min(
            contrastRatio(form.header_text, form.bg_from),
            contrastRatio(form.header_text, form.bg_to),
        ),
        min: 4.5,
    },
    {
        label: 'Texto de los botones',
        ratio: contrastRatio(buttonTextColor.value, form.accent),
        min: 4.5,
    },
    {
        label: 'Títulos (modo claro)',
        ratio: contrastRatio(form.title_color || '#1e293b', '#ffffff'),
        min: 3,
    },
    {
        label: 'Acento sobre la tarjeta',
        ratio: contrastRatio(form.accent, '#ffffff'),
        min: 3,
    },
]);
const lowChecks = computed(() => checks.value.filter((c) => c.ratio < c.min));

const buttonTextOptions = [
    { value: 'auto', label: 'Automático' },
    { value: '#ffffff', label: 'Blanco' },
    { value: '#0f172a', label: 'Oscuro' },
];

// ── Textos ──
const textFields: {
    key: keyof Texts;
    label: string;
    help: string;
    max: number;
    long?: boolean;
}[] = [
    {
        key: 'tagline',
        label: 'Frase bajo el nombre del hotel',
        help: 'Arriba de todo, junto a tu logo.',
        max: 90,
    },
    {
        key: 'title',
        label: 'Título del primer paso',
        help: 'Lo primero que lee el huésped.',
        max: 70,
    },
    {
        key: 'subtitle',
        label: 'Explicación del primer paso',
        help: 'Una línea que diga qué hacer.',
        max: 160,
        long: true,
    },
    {
        key: 'search_button',
        label: 'Texto del botón de búsqueda',
        help: 'El botón que muestra las habitaciones.',
        max: 40,
    },
];

const shown = (key: keyof Texts) => form[key].trim() || props.textDefaults[key];

// ── Tipografía ──
const fontOptions: { value: string; label: string; help: string }[] = [
    {
        value: 'default',
        label: 'Como el sistema',
        help: 'La del resto del sitio.',
    },
    { value: 'serif', label: 'Elegante', help: 'Con remates, clásica.' },
    { value: 'rounded', label: 'Redondeada', help: 'Amable y cercana.' },
    { value: 'condensed', label: 'Compacta', help: 'Angosta, moderna.' },
];

// ── Modo de la tarjeta ──
const themeOptions: {
    value: string;
    label: string;
    icon: Icon;
    help: string;
}[] = [
    {
        value: 'light',
        label: 'Claro',
        icon: 'Sun',
        help: 'Tarjeta blanca con texto oscuro.',
    },
    {
        value: 'dark',
        label: 'Oscuro',
        icon: 'Moon',
        help: 'Tarjeta oscura con texto claro, siempre.',
    },
    {
        value: 'auto',
        label: 'Automático',
        icon: 'MonitorSmartphone',
        help: 'Sigue al celular o compu de cada huésped.',
    },
];

// ── Guardar ──
async function save() {
    if (busy.value || !dirty.value) return;
    busy.value = true;
    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: {
                wizard_bg_from: form.bg_from,
                wizard_bg_to: form.bg_to,
                wizard_accent: form.accent,
                wizard_theme: form.theme,
                wizard_header_text: form.header_text,
                wizard_title_color: form.title_color || null,
                wizard_button_text: form.button_text,
                wizard_title_font: form.title_font,
                wizard_text_tagline: form.tagline.trim() || null,
                wizard_text_title: form.title.trim() || null,
                wizard_text_subtitle: form.subtitle.trim() || null,
                wizard_text_search_button: form.search_button.trim() || null,
            },
        });
        saved.value = { ...form };
        toast.success(
            'Apariencia guardada',
            'Los cambios ya se ven en las páginas públicas.',
        );
    } catch (error: any) {
        const errors = error.response?.data?.errors as
            | Record<string, string[]>
            | undefined;
        toast.error(
            'No se pudo guardar',
            (errors ? Object.values(errors)[0]?.[0] : null) ??
                error.response?.data?.message ??
                'Intenta de nuevo.',
        );
    } finally {
        busy.value = false;
    }
}

const logoUrl = computed(() => props.appearance.logo_url);

// ── Vista previa ──
const previewDark = ref(props.appearance.theme === 'dark');
const effectiveDark = computed(() =>
    form.theme === 'auto' ? previewDark.value : form.theme === 'dark',
);
const previewRoot = computed(() => ({
    backgroundImage: `linear-gradient(to bottom, ${form.bg_from}, ${form.bg_to})`,
    color: form.header_text,
}));
const titleStyle = computed(() => ({
    fontFamily: TITLE_FONT_STACKS[form.title_font] ?? 'inherit',
    ...(form.title_color && !effectiveDark.value
        ? { color: form.title_color }
        : {}),
}));
</script>

<template>
    <RazeLayout title="Apariencia del wizard">
        <div class="mt-2 pb-20">
            <!-- Encabezado -->
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
                        <h1
                            class="flex flex-wrap items-center gap-2 text-base font-medium"
                        >
                            Apariencia del wizard de reservas
                            <span
                                v-if="dirty"
                                class="rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                                >Sin guardar</span
                            >
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ property.name }} · colores, textos y letra de
                            reservas, grupos, experiencias y consulta
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:shrink-0 md:items-center"
                >
                    <Link
                        :href="route('tenant.wizard-settings')"
                        class="col-span-2 inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary md:col-auto dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Ajustes del wizard
                    </Link>
                    <Button
                        as="a"
                        :href="wizardUrl"
                        target="_blank"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="ExternalLink"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        Ver wizard
                    </Button>
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        :disabled="!dirty || busy || !canManage"
                        @click="save"
                    >
                        <Lucide icon="Save" class="mr-1.5 h-3.5 w-3.5" />
                        {{ busy ? 'Guardando...' : 'Guardar cambios' }}
                    </Button>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-12 items-start gap-5">
                <div class="col-span-12 flex flex-col gap-4 xl:col-span-7">
                    <!-- Colores -->
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div :class="sectionIcon">
                                <Lucide icon="Palette" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium">Colores</div>
                                <div class="text-xs text-slate-500">
                                    Fondo, acento y colores de texto.
                                </div>
                            </div>
                            <button
                                type="button"
                                class="flex items-center gap-1.5 text-xs font-medium text-primary hover:underline"
                                @click="resetColors"
                            >
                                <Lucide icon="RotateCcw" class="h-3.5 w-3.5" />
                                Restaurar originales
                            </button>
                        </div>

                        <!-- Paletas -->
                        <div
                            class="border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div
                                class="mb-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Paletas sugeridas
                            </div>
                            <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
                                <button
                                    v-for="p in palettes"
                                    :key="p.name"
                                    type="button"
                                    class="group flex flex-col items-center gap-1.5 rounded-lg border p-1.5 transition"
                                    :class="
                                        form.bg_from === p.bg_from &&
                                        form.accent === p.accent
                                            ? 'border-primary/50 bg-primary/5'
                                            : 'border-slate-200/70 hover:border-primary/30 dark:border-darkmode-400'
                                    "
                                    @click="applyPalette(p)"
                                >
                                    <span
                                        class="flex h-9 w-full items-end justify-end rounded-md p-1"
                                        :style="{
                                            backgroundImage: `linear-gradient(135deg, ${p.bg_from}, ${p.bg_to})`,
                                        }"
                                    >
                                        <span
                                            class="h-3 w-3 rounded-full ring-2 ring-white/70"
                                            :style="{
                                                backgroundColor: p.accent,
                                            }"
                                        />
                                    </span>
                                    <span class="text-[11px] text-slate-500">{{
                                        p.name
                                    }}</span>
                                </button>
                            </div>
                        </div>

                        <div
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <!-- Fondo y acento -->
                            <div
                                v-for="row in colorRows"
                                :key="row.key"
                                class="flex flex-wrap items-center gap-3 px-4 py-3"
                            >
                                <div class="min-w-0 flex-1 basis-48">
                                    <div :class="rowLabel">{{ row.label }}</div>
                                    <div class="text-xs text-slate-500">
                                        {{ row.help }}
                                    </div>
                                </div>
                                <input
                                    v-model="form[row.key]"
                                    type="color"
                                    class="h-9 w-12 shrink-0 cursor-pointer rounded-md border border-slate-200 bg-white p-1 shadow-sm dark:border-darkmode-400 dark:bg-darkmode-800"
                                    :aria-label="row.label"
                                />
                                <FormInput
                                    :model-value="form[row.key]"
                                    type="text"
                                    class="h-9 w-28 font-mono text-xs uppercase"
                                    maxlength="7"
                                    :aria-label="`${row.label} (hex)`"
                                    @update:model-value="
                                        (v: string | number) =>
                                            setHex(row.key, v)
                                    "
                                />
                            </div>

                            <!-- Títulos -->
                            <div
                                class="flex flex-wrap items-center gap-3 px-4 py-3"
                            >
                                <div class="min-w-0 flex-1 basis-48">
                                    <div :class="rowLabel">Títulos</div>
                                    <div class="text-xs text-slate-500">
                                        Títulos de cada paso, en modo claro. En
                                        modo oscuro se quedan claros para que se
                                        lean.
                                    </div>
                                </div>
                                <template v-if="form.title_color">
                                    <input
                                        v-model="form.title_color"
                                        type="color"
                                        class="h-9 w-12 shrink-0 cursor-pointer rounded-md border border-slate-200 bg-white p-1 shadow-sm dark:border-darkmode-400 dark:bg-darkmode-800"
                                        aria-label="Color de títulos"
                                    />
                                    <FormInput
                                        :model-value="form.title_color"
                                        type="text"
                                        class="h-9 w-28 font-mono text-xs uppercase"
                                        maxlength="7"
                                        aria-label="Color de títulos (hex)"
                                        @update:model-value="
                                            (v: string | number) =>
                                                setHex('title_color', v)
                                        "
                                    />
                                    <button
                                        type="button"
                                        class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                        title="Usar el color de siempre"
                                        @click="form.title_color = ''"
                                    >
                                        <Lucide icon="X" class="h-4 w-4" />
                                    </button>
                                </template>
                                <button
                                    v-else
                                    type="button"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-[0.5rem] border border-dashed border-slate-300 px-3 text-xs font-medium text-slate-600 transition hover:border-primary/40 hover:text-primary dark:border-darkmode-400 dark:text-slate-300"
                                    @click="form.title_color = form.accent"
                                >
                                    <Lucide
                                        icon="Pipette"
                                        class="h-3.5 w-3.5"
                                    />
                                    Elegir un color
                                </button>
                            </div>

                            <!-- Texto de botones -->
                            <div
                                class="flex flex-wrap items-center gap-3 px-4 py-3"
                            >
                                <div class="min-w-0 flex-1 basis-48">
                                    <div :class="rowLabel">
                                        Texto de los botones
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        Automático elige blanco u oscuro según
                                        el acento.
                                    </div>
                                </div>
                                <div
                                    class="inline-flex rounded-lg bg-slate-100 p-1 dark:bg-darkmode-400"
                                >
                                    <button
                                        v-for="opt in buttonTextOptions"
                                        :key="opt.value"
                                        type="button"
                                        class="h-7 rounded-md px-3 text-xs font-medium transition"
                                        :class="
                                            form.button_text === opt.value
                                                ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                                : 'text-slate-500'
                                        "
                                        @click="form.button_text = opt.value"
                                    >
                                        {{ opt.label }}
                                    </button>
                                </div>
                                <span
                                    class="inline-flex h-9 items-center rounded-[0.5rem] px-3 text-xs font-medium"
                                    :style="{
                                        backgroundColor: form.accent,
                                        color: buttonTextColor,
                                    }"
                                    >Ejemplo</span
                                >
                            </div>
                        </div>

                        <!-- Contraste -->
                        <div
                            class="rounded-b-[0.6rem] border-t border-slate-200/60 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                        >
                            <div
                                class="mb-2 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Revisión de contraste
                                <span
                                    v-if="!lowChecks.length"
                                    class="rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-medium tracking-normal text-success normal-case"
                                    >Todo se lee bien</span
                                >
                            </div>
                            <div
                                class="grid grid-cols-1 gap-x-6 gap-y-1.5 sm:grid-cols-2"
                            >
                                <div
                                    v-for="c in checks"
                                    :key="c.label"
                                    class="flex items-center justify-between gap-2 text-xs"
                                >
                                    <span
                                        class="text-slate-600 dark:text-slate-300"
                                        >{{ c.label }}</span
                                    >
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="
                                            c.ratio >= c.min
                                                ? 'bg-success/10 text-success'
                                                : 'bg-warning/10 text-warning'
                                        "
                                        :title="`Contraste ${c.ratio.toFixed(1)}:1 (mínimo recomendado ${c.min}:1)`"
                                    >
                                        <Lucide
                                            :icon="
                                                c.ratio >= c.min
                                                    ? 'Check'
                                                    : 'TriangleAlert'
                                            "
                                            class="h-3 w-3"
                                        />
                                        {{ c.ratio.toFixed(1) }}:1
                                        {{ c.ratio >= c.min ? 'bien' : 'bajo' }}
                                    </span>
                                </div>
                            </div>
                            <p
                                v-if="lowChecks.length"
                                class="mt-2 text-xs text-slate-500"
                            >
                                Con contraste bajo a mucha gente le cuesta leer,
                                sobre todo en el celular y al sol. Puedes
                                guardarlo igual.
                            </p>
                        </div>
                    </div>

                    <!-- Textos -->
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div :class="sectionIcon">
                                <Lucide icon="Type" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium">Textos</div>
                                <div class="text-xs text-slate-500">
                                    Déjalos vacíos para usar el texto de
                                    siempre.
                                </div>
                            </div>
                        </div>
                        <div class="space-y-4 px-4 py-3">
                            <div v-for="field in textFields" :key="field.key">
                                <div
                                    class="mb-1.5 flex items-baseline justify-between gap-2"
                                >
                                    <label
                                        :for="`text-${field.key}`"
                                        :class="rowLabel"
                                    >
                                        {{ field.label }}
                                    </label>
                                    <span
                                        class="text-[11px]"
                                        :class="
                                            form[field.key].length >
                                            field.max * 0.9
                                                ? 'text-warning'
                                                : 'text-slate-400'
                                        "
                                        >{{ form[field.key].length }}/{{
                                            field.max
                                        }}</span
                                    >
                                </div>
                                <FormTextarea
                                    v-if="field.long"
                                    :id="`text-${field.key}`"
                                    v-model="form[field.key]"
                                    rows="2"
                                    class="text-xs"
                                    :maxlength="field.max"
                                    :placeholder="textDefaults[field.key]"
                                />
                                <FormInput
                                    v-else
                                    :id="`text-${field.key}`"
                                    v-model="form[field.key]"
                                    type="text"
                                    class="h-9 text-xs"
                                    :maxlength="field.max"
                                    :placeholder="textDefaults[field.key]"
                                />
                                <div
                                    class="mt-1 flex items-center justify-between gap-2 text-xs text-slate-500"
                                >
                                    <span>{{ field.help }}</span>
                                    <button
                                        v-if="form[field.key]"
                                        type="button"
                                        class="shrink-0 font-medium text-primary hover:underline"
                                        @click="form[field.key] = ''"
                                    >
                                        Usar el de siempre
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tipografía y modo -->
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div class="box box--stacked flex flex-col">
                            <div :class="cardHeader">
                                <div :class="sectionIcon">
                                    <Lucide
                                        icon="CaseSensitive"
                                        class="h-4 w-4"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium">
                                        Letra de los títulos
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        Sin descargas: se ve igual de rápido.
                                    </div>
                                </div>
                            </div>
                            <div class="grid flex-1 grid-cols-2 gap-2 p-4">
                                <button
                                    v-for="f in fontOptions"
                                    :key="f.value"
                                    type="button"
                                    class="flex flex-col items-start gap-0.5 rounded-lg border p-2.5 text-left transition"
                                    :class="
                                        form.title_font === f.value
                                            ? 'border-primary/50 bg-primary/5'
                                            : 'border-slate-200/70 hover:border-primary/30 dark:border-darkmode-400'
                                    "
                                    @click="form.title_font = f.value"
                                >
                                    <span
                                        class="text-base leading-tight"
                                        :style="{
                                            fontFamily:
                                                TITLE_FONT_STACKS[f.value],
                                        }"
                                        >Aa Bienvenido</span
                                    >
                                    <span class="text-xs font-medium">{{
                                        f.label
                                    }}</span>
                                    <span class="text-[11px] text-slate-500">{{
                                        f.help
                                    }}</span>
                                </button>
                            </div>
                        </div>

                        <div class="box box--stacked flex flex-col">
                            <div :class="cardHeader">
                                <div :class="sectionIcon">
                                    <Lucide icon="Moon" class="h-4 w-4" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium">
                                        Modo de la tarjeta
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        El interior de las páginas públicas.
                                    </div>
                                </div>
                            </div>
                            <div class="flex flex-1 flex-col gap-2 p-4">
                                <button
                                    v-for="option in themeOptions"
                                    :key="option.value"
                                    type="button"
                                    class="flex items-center gap-3 rounded-lg border p-2.5 text-left transition"
                                    :class="
                                        form.theme === option.value
                                            ? 'border-primary/50 bg-primary/5'
                                            : 'border-slate-200/70 hover:border-primary/30 dark:border-darkmode-400'
                                    "
                                    @click="form.theme = option.value"
                                >
                                    <Lucide
                                        :icon="option.icon"
                                        class="h-4 w-4 shrink-0"
                                        :class="
                                            form.theme === option.value
                                                ? 'text-primary'
                                                : 'text-slate-400'
                                        "
                                    />
                                    <span class="min-w-0">
                                        <span
                                            class="block text-xs font-medium"
                                            >{{ option.label }}</span
                                        >
                                        <span
                                            class="block text-[11px] text-slate-500"
                                            >{{ option.help }}</span
                                        >
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Logo: vive en Datos generales -->
                    <div
                        class="box box--stacked flex flex-wrap items-center gap-3 px-4 py-3"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <img
                                v-if="logoUrl"
                                :src="logoUrl"
                                alt="Logo del hotel"
                                class="h-full w-full object-contain p-1"
                            />
                            <Lucide v-else icon="ImageUp" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium">
                                Logo del hotel
                            </div>
                            <div class="text-xs text-slate-500">
                                Se cambia en Datos generales; aquí se ve en la
                                vista previa.
                            </div>
                        </div>
                        <Button
                            :as="Link"
                            :href="route('tenant.general-settings')"
                            variant="outline-secondary"
                            class="h-8 rounded-[0.5rem] bg-white text-xs"
                        >
                            Datos generales
                            <Lucide
                                icon="ChevronRight"
                                class="ml-1 h-3.5 w-3.5"
                            />
                        </Button>
                    </div>
                </div>

                <!-- Vista previa -->
                <div class="col-span-12 xl:sticky xl:top-24 xl:col-span-5">
                    <div class="box box--stacked overflow-hidden">
                        <div :class="cardHeader">
                            <div :class="sectionIcon">
                                <Lucide icon="Eye" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium">
                                    Vista previa
                                </div>
                                <div class="text-xs text-slate-500">
                                    Cambia mientras ajustas; aún no está
                                    guardado.
                                </div>
                            </div>
                            <div
                                v-if="form.theme === 'auto'"
                                class="inline-flex rounded-lg bg-slate-100 p-1 dark:bg-darkmode-400"
                            >
                                <button
                                    v-for="d in [false, true]"
                                    :key="String(d)"
                                    type="button"
                                    class="h-7 rounded-md px-2.5 text-xs font-medium transition"
                                    :class="
                                        previewDark === d
                                            ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                            : 'text-slate-500'
                                    "
                                    @click="previewDark = d"
                                >
                                    {{ d ? 'Oscuro' : 'Claro' }}
                                </button>
                            </div>
                        </div>
                        <div class="p-4">
                            <div class="rounded-2xl p-4" :style="previewRoot">
                                <!-- Encabezado -->
                                <div class="flex items-center gap-2.5">
                                    <img
                                        v-if="logoUrl"
                                        :src="logoUrl"
                                        alt="Logo"
                                        class="h-9 w-9 shrink-0 rounded-full bg-white object-contain p-0.5"
                                    />
                                    <div
                                        v-else
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-current/10"
                                    >
                                        <Lucide
                                            icon="Building2"
                                            class="h-4 w-4"
                                        />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="truncate text-sm font-medium"
                                            :style="{
                                                fontFamily:
                                                    TITLE_FONT_STACKS[
                                                        form.title_font
                                                    ],
                                            }"
                                        >
                                            {{ property.name }}
                                        </div>
                                        <div
                                            class="truncate text-[11px] opacity-75"
                                        >
                                            {{ shown('tagline') }}
                                        </div>
                                    </div>
                                    <div
                                        class="flex h-7 w-7 items-center justify-center rounded-full border border-current/25 bg-current/10"
                                    >
                                        <Lucide icon="Phone" class="h-3 w-3" />
                                    </div>
                                </div>
                                <!-- Pasos -->
                                <div
                                    class="mt-3 flex items-center gap-1.5 text-[10px] font-medium"
                                >
                                    <span
                                        class="flex h-4 w-4 items-center justify-center rounded-full bg-current ring-2 ring-current/25"
                                    >
                                        <span :style="{ color: form.bg_from }"
                                            >1</span
                                        >
                                    </span>
                                    Cuándo
                                    <span
                                        class="h-px flex-1 bg-current opacity-30"
                                    />
                                    <span
                                        class="flex h-4 w-4 items-center justify-center rounded-full bg-current/20 opacity-70"
                                        >2</span
                                    >
                                    <span class="opacity-70">Habitación</span>
                                    <span
                                        class="h-px flex-1 bg-current opacity-30"
                                    />
                                    <span
                                        class="flex h-4 w-4 items-center justify-center rounded-full bg-current/20 opacity-70"
                                        >3</span
                                    >
                                    <span class="opacity-70">Datos</span>
                                </div>
                                <!-- Tarjeta -->
                                <div
                                    class="mt-3 overflow-hidden rounded-xl shadow-xl"
                                    :class="
                                        effectiveDark
                                            ? 'bg-darkmode-600'
                                            : 'bg-white'
                                    "
                                >
                                    <div
                                        class="flex items-center gap-2.5 border-b px-3.5 py-3"
                                        :class="
                                            effectiveDark
                                                ? 'border-darkmode-400'
                                                : 'border-slate-200/70'
                                        "
                                    >
                                        <span
                                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full"
                                            :style="{
                                                backgroundColor: `${form.accent}1a`,
                                                color: effectiveDark
                                                    ? '#e2e8f0'
                                                    : form.accent,
                                            }"
                                        >
                                            <Lucide
                                                icon="CalendarDays"
                                                class="h-3.5 w-3.5"
                                            />
                                        </span>
                                        <div class="min-w-0">
                                            <div
                                                class="truncate text-sm font-medium"
                                                :class="
                                                    effectiveDark
                                                        ? 'text-slate-200'
                                                        : 'text-slate-800'
                                                "
                                                :style="titleStyle"
                                            >
                                                {{ shown('title') }}
                                            </div>
                                            <div
                                                class="line-clamp-2 text-[11px]"
                                                :class="
                                                    effectiveDark
                                                        ? 'text-slate-400'
                                                        : 'text-slate-500'
                                                "
                                            >
                                                {{ shown('subtitle') }}
                                            </div>
                                        </div>
                                    </div>
                                    <div
                                        class="grid grid-cols-7 gap-1 px-3.5 py-3"
                                    >
                                        <span
                                            v-for="d in 14"
                                            :key="d"
                                            class="flex h-5 items-center justify-center rounded text-[9px]"
                                            :style="
                                                d === 5 || d === 7
                                                    ? {
                                                          backgroundColor:
                                                              form.accent,
                                                          color: buttonTextColor,
                                                      }
                                                    : d === 6
                                                      ? {
                                                            backgroundColor: `${form.accent}24`,
                                                        }
                                                      : {}
                                            "
                                            :class="
                                                effectiveDark
                                                    ? 'text-slate-400'
                                                    : 'text-slate-500'
                                            "
                                            >{{ d }}</span
                                        >
                                    </div>
                                    <div
                                        class="flex justify-end border-t px-3.5 py-2.5"
                                        :class="
                                            effectiveDark
                                                ? 'border-darkmode-400 bg-darkmode-500'
                                                : 'border-slate-200/70 bg-slate-50'
                                        "
                                    >
                                        <span
                                            class="inline-flex h-7 items-center gap-1.5 rounded-full px-3 text-[11px] font-medium"
                                            :style="{
                                                backgroundColor: form.accent,
                                                color: buttonTextColor,
                                            }"
                                        >
                                            <Lucide
                                                icon="Search"
                                                class="h-3 w-3"
                                            />
                                            {{ shown('search_button') }}
                                        </span>
                                    </div>
                                </div>
                                <p
                                    class="mt-3 text-center text-[10px] opacity-75"
                                >
                                    Impulsado por KuiraWebReserve
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cambios sin guardar: siempre a la mano en una página larga -->
        <div
            v-if="dirty"
            class="fixed inset-x-0 bottom-4 z-40 flex justify-center px-4"
        >
            <div
                class="flex w-full max-w-xl flex-wrap items-center gap-3 rounded-xl border border-slate-200/70 bg-white px-4 py-3 shadow-2xl dark:border-darkmode-400 dark:bg-darkmode-600"
            >
                <span
                    class="inline-flex flex-1 items-center gap-2 text-xs text-slate-600 dark:text-slate-300"
                >
                    <Lucide icon="CircleDot" class="h-3.5 w-3.5 text-warning" />
                    Tienes cambios sin guardar
                </span>
                <Button
                    variant="outline-secondary"
                    class="h-9 rounded-[0.5rem] text-xs"
                    :disabled="busy"
                    @click="discard"
                >
                    Descartar
                </Button>
                <Button
                    variant="primary"
                    class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                    :disabled="busy || !canManage"
                    @click="save"
                >
                    <Lucide icon="Save" class="mr-1.5 h-3.5 w-3.5" />
                    {{ busy ? 'Guardando...' : 'Guardar' }}
                </Button>
            </div>
        </div>
    </RazeLayout>
</template>
