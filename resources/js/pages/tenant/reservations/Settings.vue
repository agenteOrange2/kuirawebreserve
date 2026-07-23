<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormLabel } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface Appearance {
    bg_from: string;
    bg_to: string;
    accent: string;
    theme: string;
    logo_url: string | null;
}

const props = defineProps<{
    property: { id: number; name: string };
    wizardUrl: string;
    appearance: Appearance;
    defaults: { bg_from: string; bg_to: string; accent: string; theme: string };
    canManage: boolean;
}>();

const toast = useToasts();

// ── Colores y modo ──
const form = reactive({
    bg_from: props.appearance.bg_from,
    bg_to: props.appearance.bg_to,
    accent: props.appearance.accent,
    theme: props.appearance.theme,
});
const saved = ref({ ...form });
const dirty = computed(
    () =>
        form.bg_from !== saved.value.bg_from ||
        form.bg_to !== saved.value.bg_to ||
        form.accent !== saved.value.accent ||
        form.theme !== saved.value.theme,
);
const busy = ref(false);

const colorRows = [
    {
        key: 'bg_from' as const,
        label: 'Fondo (arriba)',
        help: 'Inicio del degradado detrás del wizard.',
    },
    {
        key: 'bg_to' as const,
        label: 'Fondo (abajo)',
        help: 'Fin del degradado; puede ser el mismo color para un fondo liso.',
    },
    {
        key: 'accent' as const,
        label: 'Color de acento',
        help: 'Botones, precios y detalles destacados dentro de la tarjeta.',
    },
];

// Solo hex completo (#rrggbb): lo mismo que valida el servidor.
function onHexInput(key: (typeof colorRows)[number]['key'], value: string) {
    const hex = value.trim();
    if (/^#[0-9a-fA-F]{6}$/.test(hex)) form[key] = hex.toLowerCase();
}

function resetColors() {
    form.bg_from = props.defaults.bg_from;
    form.bg_to = props.defaults.bg_to;
    form.accent = props.defaults.accent;
}

// El encabezado del wizard (nombre, pasos) es texto blanco fijo: con un
// fondo muy claro no se lee — se avisa en vez de prohibir.
function luminance(hex: string): number {
    const n = parseInt(hex.slice(1), 16);
    return (
        (0.2126 * ((n >> 16) & 255) +
            0.7152 * ((n >> 8) & 255) +
            0.0722 * (n & 255)) /
        255
    );
}
const lightBgWarning = computed(
    () => luminance(form.bg_from) > 0.65 || luminance(form.bg_to) > 0.65,
);

const themeOptions = [
    {
        value: 'light',
        label: 'Claro',
        icon: 'Sun' as const,
        help: 'Tarjeta blanca con texto oscuro (como hasta ahora).',
    },
    {
        value: 'dark',
        label: 'Oscuro',
        icon: 'Moon' as const,
        help: 'Tarjeta oscura con texto claro, siempre.',
    },
    {
        value: 'auto',
        label: 'Automático',
        icon: 'MonitorSmartphone' as const,
        help: 'Sigue la preferencia del dispositivo de cada huésped.',
    },
];

async function save() {
    if (busy.value) return;
    busy.value = true;
    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: {
                wizard_bg_from: form.bg_from,
                wizard_bg_to: form.bg_to,
                wizard_accent: form.accent,
                wizard_theme: form.theme,
            },
        });
        saved.value = { ...form };
        toast.success(
            'Apariencia guardada',
            'Los cambios ya se ven en el wizard público.',
        );
    } catch (error: any) {
        toast.error(
            'No se pudo guardar',
            error.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        busy.value = false;
    }
}

// El logo vive en Datos generales (/ajustes/general): aquí solo se
// refleja en la vista previa.
const logoUrl = computed(() => props.appearance.logo_url);

// ── Vista previa ──
const previewGradient = computed(() => ({
    backgroundImage: `linear-gradient(to bottom, ${form.bg_from}, ${form.bg_to})`,
}));
const previewDark = computed(() => form.theme === 'dark');
</script>

<template>
    <RazeLayout title="Apariencia del wizard">
        <div class="mt-2">
            <!-- Encabezado -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">
                        Apariencia del wizard de reservas
                    </h1>
                    <p class="text-sm text-slate-500">
                        {{ property.name }} · aplica a todas las páginas
                        públicas: reservas, experiencias, grupos y consulta
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        :as="Link"
                        :href="route('tenant.reservations')"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="ArrowLeft"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Volver a reservas
                    </Button>
                    <Button
                        as="a"
                        :href="wizardUrl"
                        target="_blank"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="ExternalLink"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Ver wizard
                    </Button>
                    <Button
                        variant="primary"
                        :disabled="!dirty || busy || !canManage"
                        @click="save"
                    >
                        <Lucide icon="Save" class="mr-2 h-4 w-4" />
                        {{ busy ? 'Guardando…' : 'Guardar cambios' }}
                    </Button>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-5">
                <!-- Columna de ajustes -->
                <div class="col-span-12 flex flex-col gap-5 xl:col-span-7">
                    <!-- Logo: vive en Datos generales (identidad del hotel) -->
                    <div class="box box--stacked p-5">
                        <div class="flex flex-wrap items-center gap-3">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full border border-primary/10 bg-primary/10 text-primary"
                            >
                                <img
                                    v-if="logoUrl"
                                    :src="logoUrl"
                                    alt="Logo del hotel"
                                    class="h-full w-full object-contain p-1"
                                />
                                <Lucide
                                    v-else
                                    icon="ImageUp"
                                    class="h-5 w-5"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="font-medium">Logo del hotel</div>
                                <div class="text-xs text-slate-500">
                                    Se administra en Datos generales; aquí solo
                                    se refleja en la vista previa.
                                </div>
                            </div>
                            <Button
                                :as="Link"
                                :href="route('tenant.general-settings')"
                                variant="outline-secondary"
                                class="rounded-[0.5rem]"
                            >
                                <Lucide
                                    icon="ChevronRight"
                                    class="mr-2 h-4 w-4 stroke-[1.3]"
                                />
                                Ir a Datos generales
                            </Button>
                        </div>
                    </div>

                    <!-- Colores -->
                    <div class="box box--stacked p-5">
                        <div class="flex flex-wrap items-center gap-3">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                            >
                                <Lucide icon="Palette" class="h-5 w-5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="font-medium">Colores</div>
                                <div class="text-xs text-slate-500">
                                    Fondo degradado y color de acento de todas
                                    las páginas públicas.
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
                        <div class="mt-4 flex flex-col gap-4">
                            <div
                                v-for="row in colorRows"
                                :key="row.key"
                                class="flex flex-wrap items-center gap-3"
                            >
                                <div class="w-full sm:w-44">
                                    <FormLabel class="!mb-0">{{
                                        row.label
                                    }}</FormLabel>
                                    <div class="text-xs text-slate-400">
                                        {{ row.help }}
                                    </div>
                                </div>
                                <input
                                    v-model="form[row.key]"
                                    type="color"
                                    class="h-10 w-14 shrink-0 cursor-pointer rounded-md border border-slate-200 bg-white p-1 shadow-sm dark:border-darkmode-400 dark:bg-darkmode-800"
                                    :title="row.label"
                                />
                                <FormInput
                                    :model-value="form[row.key]"
                                    type="text"
                                    class="w-32 font-mono uppercase"
                                    maxlength="7"
                                    @update:model-value="
                                        (v: string) => onHexInput(row.key, v)
                                    "
                                />
                            </div>
                        </div>
                        <p
                            v-if="lightBgWarning"
                            class="mt-4 flex items-start gap-1.5 text-xs font-medium text-warning"
                        >
                            <Lucide
                                icon="ShieldAlert"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0"
                            />
                            El nombre del hotel y los pasos se muestran en texto
                            blanco: con un fondo tan claro pueden no leerse
                            bien. Revisa la vista previa.
                        </p>
                    </div>

                    <!-- Modo claro / oscuro -->
                    <div class="box box--stacked p-5">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                            >
                                <Lucide icon="Moon" class="h-5 w-5" />
                            </div>
                            <div>
                                <div class="font-medium">Modo de la tarjeta</div>
                                <div class="text-xs text-slate-500">
                                    Cómo se ve el interior de las páginas
                                    públicas (fechas, habitaciones, datos).
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <label
                                v-for="option in themeOptions"
                                :key="option.value"
                                class="flex cursor-pointer flex-col gap-1.5 rounded-xl border p-3.5 transition"
                                :class="
                                    form.theme === option.value
                                        ? 'border-primary/40 bg-primary/5'
                                        : 'border-slate-200/70 hover:bg-slate-50 dark:border-darkmode-400 dark:hover:bg-darkmode-500'
                                "
                            >
                                <input
                                    v-model="form.theme"
                                    type="radio"
                                    name="wizard-theme"
                                    :value="option.value"
                                    class="hidden"
                                />
                                <span
                                    class="flex items-center gap-2 text-sm font-medium"
                                    :class="
                                        form.theme === option.value &&
                                        'text-primary'
                                    "
                                >
                                    <Lucide
                                        :icon="option.icon"
                                        class="h-4 w-4"
                                    />
                                    {{ option.label }}
                                </span>
                                <span class="text-xs text-slate-500">
                                    {{ option.help }}
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Vista previa -->
                <div class="col-span-12 xl:col-span-5">
                    <div class="box box--stacked sticky top-24 overflow-hidden">
                        <div
                            class="flex items-center justify-between border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2 font-medium">
                                <Lucide
                                    icon="Eye"
                                    class="h-4 w-4 text-slate-400"
                                />
                                Vista previa
                            </div>
                            <span
                                v-if="form.theme === 'auto'"
                                class="text-xs text-slate-500"
                            >
                                Automático: aquí se muestra el modo claro
                            </span>
                        </div>
                        <div class="p-5">
                            <div
                                class="rounded-2xl p-4 sm:p-5"
                                :style="previewGradient"
                            >
                                <!-- Header del wizard -->
                                <div
                                    class="flex items-center gap-2.5 text-white"
                                >
                                    <img
                                        v-if="logoUrl"
                                        :src="logoUrl"
                                        alt="Logo"
                                        class="h-9 w-9 shrink-0 rounded-full bg-white object-contain p-0.5"
                                    />
                                    <div
                                        v-else
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10"
                                    >
                                        <Lucide
                                            icon="Building2"
                                            class="h-4 w-4"
                                        />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="truncate text-sm font-medium"
                                        >
                                            {{ property.name }}
                                        </div>
                                        <div class="text-[11px] text-white/70">
                                            Reserva en línea · precios en vivo
                                        </div>
                                    </div>
                                    <div
                                        class="flex h-7 w-7 items-center justify-center rounded-full bg-white/10"
                                    >
                                        <Lucide icon="Phone" class="h-3 w-3" />
                                    </div>
                                </div>
                                <!-- Pasos -->
                                <div
                                    class="mt-3 flex flex-wrap items-center gap-1.5 text-[10px] font-medium text-white/80"
                                >
                                    <span
                                        class="flex h-4 w-4 items-center justify-center rounded-full bg-white"
                                        :style="{ color: form.accent }"
                                        >1</span
                                    >
                                    Fechas
                                    <span class="h-px w-4 bg-white/30" />
                                    <span
                                        class="flex h-4 w-4 items-center justify-center rounded-full bg-white/20"
                                        >2</span
                                    >
                                    Habitación
                                    <span class="h-px w-4 bg-white/30" />
                                    <span
                                        class="flex h-4 w-4 items-center justify-center rounded-full bg-white/20"
                                        >3</span
                                    >
                                    Tus datos
                                </div>
                                <!-- Tarjeta -->
                                <div
                                    class="mt-3 rounded-xl p-4 shadow-xl"
                                    :class="
                                        previewDark
                                            ? 'bg-darkmode-600'
                                            : 'bg-white'
                                    "
                                >
                                    <div
                                        class="text-sm font-medium"
                                        :class="
                                            previewDark
                                                ? 'text-slate-200'
                                                : 'text-slate-800'
                                        "
                                    >
                                        ¿Cuándo quieres reservar?
                                    </div>
                                    <div
                                        class="mt-0.5 text-xs"
                                        :class="
                                            previewDark
                                                ? 'text-slate-400'
                                                : 'text-slate-500'
                                        "
                                    >
                                        El precio se calcula al momento.
                                    </div>
                                    <div class="mt-3 grid grid-cols-2 gap-2">
                                        <div
                                            v-for="fake in ['Llegada', 'Salida']"
                                            :key="fake"
                                            class="rounded-md border px-2.5 py-2 text-xs"
                                            :class="
                                                previewDark
                                                    ? 'border-darkmode-400 bg-darkmode-800 text-slate-400'
                                                    : 'border-slate-200 text-slate-500'
                                            "
                                        >
                                            {{ fake }}
                                        </div>
                                    </div>
                                    <div
                                        class="mt-3 flex items-center justify-center gap-2 rounded-lg py-2.5 text-xs font-medium text-white"
                                        :style="{
                                            backgroundColor: form.accent,
                                        }"
                                    >
                                        <Lucide
                                            icon="CalendarCheck"
                                            class="h-3.5 w-3.5"
                                        />
                                        Ver disponibilidad
                                    </div>
                                </div>
                                <p
                                    class="mt-3 text-center text-[10px] text-white/70"
                                >
                                    Impulsado por KuiraWebReserve
                                </p>
                            </div>
                            <FormHelp class="mt-3">
                                La vista previa es una miniatura: usa "Ver
                                wizard" para revisar la página real después de
                                guardar.
                            </FormHelp>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
