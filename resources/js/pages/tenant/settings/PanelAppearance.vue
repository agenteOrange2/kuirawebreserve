<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput } from '@/components/Base/Form';
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

function resetTheme() {
    applyPreset(presets[0]);
    save({ primary: null, menu_from: null, menu_to: null });
}
</script>

<template>
    <RazeLayout title="Apariencia del panel">
        <div class="mt-2">
            <!-- Header de tarjeta, mismo patrón que Usuarios: icono en
                 círculo + título + acción a la derecha -->
            <div
                class="box box--stacked flex flex-wrap items-center justify-between gap-4 p-5"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <div
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Palette" class="h-7 w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl font-medium">
                            Apariencia del panel
                        </h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Los colores de este panel para
                            {{ property.name }}: el menú lateral y el color de
                            botones y acentos. Aplica a todo tu equipo; el
                            wizard público tiene su propia apariencia.
                        </p>
                    </div>
                </div>
                <Button
                    as="a"
                    :href="route('tenant.general-settings')"
                    variant="outline-secondary"
                    class="rounded-[0.5rem] bg-white"
                >
                    <Lucide
                        icon="ArrowLeft"
                        class="mr-2 h-4 w-4 stroke-[1.3]"
                    />
                    Volver a Datos generales
                </Button>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-6">
                <!-- Temas listos + colores personalizados -->
                <div class="col-span-12 xl:col-span-7">
                    <div class="box box--stacked p-5">
                        <div
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
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
                                class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
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
                                    <label class="mb-1 block text-sm"
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
                                    <label class="mb-1 block text-sm"
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
                                    <label class="mb-1 block text-sm"
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
                                class="rounded-[0.5rem] bg-white"
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
                                class="rounded-[0.5rem] shadow-md shadow-primary/20"
                                :disabled="saving"
                                @click="submit"
                            >
                                <Lucide icon="Check" class="mr-2 h-4 w-4" />
                                {{ saving ? 'Guardando…' : 'Guardar apariencia' }}
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Vista previa en vivo de lo elegido -->
                <div class="col-span-12 flex flex-col xl:col-span-5">
                    <div class="box box--stacked flex flex-1 flex-col p-5">
                        <div
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
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
        </div>
    </RazeLayout>
</template>
