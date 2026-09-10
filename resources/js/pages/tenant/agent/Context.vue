<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormTextarea } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    property: { id: number; name: string };
    agentInstructions: string;
    template: string;
    prompt: string;
    /** Resumen legible de lo que el bot sabe (mismo payload del prompt). */
    knows: {
        contacto: {
            label: string;
            value: string | null;
            hint: string | null;
        }[];
        links: { label: string; url: string }[];
        operacion: {
            label: string;
            value: string | null;
            hint: string | null;
        }[];
        room_types: {
            name: string;
            units: number | null;
            has_description: boolean;
            photos_url: string | null;
            occupancy: string | null;
        }[];
    };
}>();

/** Lo que le falta al bot, dicho en una línea y no escondido en el JSON. */
const missing = computed(() => {
    const gaps: string[] = [];

    [...props.knows.contacto, ...props.knows.operacion]
        .filter((fact) => !fact.value)
        .forEach((fact) => gaps.push(fact.label.toLowerCase()));

    const sinFotos = props.knows.room_types.filter((t) => !t.photos_url);
    if (sinFotos.length) {
        gaps.push(`fotos de ${sinFotos.length} tipo(s)`);
    }

    const sinDesc = props.knows.room_types.filter((t) => !t.has_description);
    if (sinDesc.length) {
        gaps.push(`descripción de ${sinDesc.length} tipo(s)`);
    }

    return gaps;
});

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';

const toast = useToasts();

const instructions = ref(props.agentInstructions ?? '');
const saving = ref(false);
const refreshing = ref(false);
const confirmTemplate = ref(false);

function useTemplate() {
    // Con texto capturado se pide confirmación antes de reemplazarlo.
    if (instructions.value.trim()) {
        confirmTemplate.value = true;
        return;
    }
    applyTemplate();
}

function applyTemplate() {
    instructions.value = props.template;
    confirmTemplate.value = false;
}

function refreshPrompt() {
    router.reload({
        only: ['prompt'],
        onStart: () => (refreshing.value = true),
        onFinish: () => (refreshing.value = false),
    });
}

async function saveInstructions() {
    saving.value = true;
    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: { agent_instructions: instructions.value.trim() || null },
        });
        toast.success('Instrucciones guardadas');
        router.reload({ only: ['prompt'] });
    } catch (e: any) {
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Contexto del bot">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="BookOpen" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Contexto del bot</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ property.name }} · lo que el asistente sabe de tu
                            hotel y cómo se comporta.
                        </p>
                    </div>
                </div>
                <div
                    class="flex w-full flex-wrap items-center gap-2 md:w-auto md:shrink-0 md:justify-end"
                >
                    <!-- El volver vive con las acciones, no flotando encima
                         de la tarjeta. -->
                    <Link
                        :href="route('tenant.agent')"
                        class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Volver al Asistente
                    </Link>
                </div>
            </div>

            <!-- Qué le falta: lo primero, porque es la pregunta real -->
            <div
                class="box box--stacked mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 text-xs"
                :class="missing.length ? 'border-l-4 border-l-warning' : ''"
            >
                <Lucide
                    :icon="missing.length ? 'TriangleAlert' : 'CircleCheck'"
                    class="h-4 w-4 shrink-0"
                    :class="missing.length ? 'text-warning' : 'text-success'"
                />
                <span v-if="missing.length" class="min-w-0">
                    <span class="font-medium">
                        Le falta {{ missing.length }}
                        {{ missing.length === 1 ? 'dato' : 'datos' }}:
                    </span>
                    {{ missing.join(' · ') }}
                </span>
                <span v-else class="font-medium text-success">
                    El asistente tiene todos sus datos: contacto, ligas,
                    horarios, políticas y las fotos de cada tipo.
                </span>
            </div>

            <div class="mt-4 grid grid-cols-12 items-start gap-5">
                <!-- Lo que el bot sabe, en legible -->
                <div class="col-span-12 space-y-5 xl:col-span-5">
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-primary/10 bg-primary/10 text-primary"
                            >
                                <Lucide icon="Building2" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Identidad y ligas
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Lo que comparte cuando le preguntan.
                                </p>
                            </div>
                        </div>
                        <div
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="fact in knows.contacto"
                                :key="fact.label"
                                class="flex items-start justify-between gap-3 px-4 py-2.5 text-xs"
                            >
                                <span class="shrink-0 text-slate-500">
                                    {{ fact.label }}
                                </span>
                                <span
                                    v-if="fact.value"
                                    class="min-w-0 truncate text-right font-medium"
                                    :title="fact.value"
                                >
                                    {{ fact.value }}
                                </span>
                                <span
                                    v-else
                                    class="shrink-0 rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                                    :title="fact.hint ?? undefined"
                                >
                                    Falta
                                </span>
                            </div>
                        </div>
                        <div
                            v-if="knows.links.length"
                            class="flex flex-wrap gap-1.5 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <a
                                v-for="link in knows.links"
                                :key="link.url"
                                :href="link.url"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] text-slate-600 transition hover:bg-primary/10 hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                :title="link.url"
                            >
                                <Lucide
                                    icon="Link"
                                    class="h-3 w-3 shrink-0 text-slate-400"
                                />
                                <span class="truncate">{{ link.label }}</span>
                            </a>
                        </div>
                    </div>

                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-info/10 bg-info/10 text-info"
                            >
                                <Lucide icon="ScrollText" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Reglas y dinero
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Con esto contesta horarios, políticas y
                                    depósito.
                                </p>
                            </div>
                        </div>
                        <div
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="fact in knows.operacion"
                                :key="fact.label"
                                class="flex items-start justify-between gap-3 px-4 py-2.5 text-xs"
                            >
                                <span class="shrink-0 text-slate-500">
                                    {{ fact.label }}
                                </span>
                                <span
                                    v-if="fact.value"
                                    class="min-w-0 truncate text-right font-medium"
                                    :title="fact.value"
                                >
                                    {{ fact.value }}
                                </span>
                                <span
                                    v-else
                                    class="shrink-0 rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                                    :title="fact.hint ?? undefined"
                                >
                                    Falta
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-success/10 bg-success/10 text-success"
                            >
                                <Lucide icon="BedDouble" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Lo que vende
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Cada tipo con su liga de fotos y su
                                    capacidad.
                                </p>
                            </div>
                        </div>
                        <div
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="type in knows.room_types"
                                :key="type.name"
                                class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-2.5"
                            >
                                <span
                                    class="min-w-0 flex-1 truncate text-xs font-medium"
                                >
                                    {{ type.name }}
                                </span>
                                <span
                                    v-if="type.occupancy"
                                    class="text-[11px] text-slate-400"
                                >
                                    {{ type.occupancy }}
                                </span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        type.has_description
                                            ? 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                            : 'bg-warning/10 text-warning'
                                    "
                                >
                                    {{
                                        type.has_description
                                            ? 'con descripción'
                                            : 'sin descripción'
                                    }}
                                </span>
                                <a
                                    v-if="type.photos_url"
                                    :href="type.photos_url"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success transition hover:bg-success/20"
                                    :title="type.photos_url"
                                >
                                    <Lucide icon="Image" class="h-3 w-3" />
                                    fotos
                                </a>
                                <span
                                    v-else
                                    class="rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                                >
                                    sin fotos
                                </span>
                            </div>
                        </div>
                        <p
                            class="border-t border-dashed border-slate-300/70 px-4 py-2.5 text-[11px] text-slate-400 dark:border-darkmode-400"
                        >
                            Las ligas de fotos se capturan en Zonas y tipos; los
                            horarios, políticas y contacto en Datos generales.
                        </p>
                    </div>
                </div>

                <!-- Instrucciones del hotel -->
                <div class="col-span-12 xl:col-span-7">
                    <div class="box box--stacked flex h-full flex-col p-4">
                        <h2 class="text-sm font-medium">
                            Instrucciones del hotel
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Personalizan a tu asistente: tono, qué promover,
                            reglas de tu negocio. Van debajo de las reglas de
                            seguridad de la plataforma — el bot nunca cobra ni
                            confirma reservas.
                        </p>
                        <FormTextarea
                            v-model="instructions"
                            rows="20"
                            class="mt-3 font-mono text-xs"
                            placeholder="Ej. — Preséntate como la asistente del hotel. — Si preguntan por semanas o meses, ofrece primero la tarifa semanal. — Nunca prometas late check-out; eso lo autoriza recepción."
                        />
                        <FormHelp>
                            Vacío = sin instrucciones adicionales del hotel.
                        </FormHelp>
                        <div
                            class="mt-3 flex flex-wrap items-center justify-end gap-2"
                        >
                            <Button
                                variant="outline-secondary"
                                class="h-9 rounded-[0.5rem] bg-white text-xs"
                                @click="useTemplate"
                            >
                                <Lucide
                                    icon="FileText"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Usar plantilla base
                            </Button>
                            <Button
                                variant="primary"
                                class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                                :disabled="saving"
                                @click="saveInstructions"
                            >
                                <Lucide
                                    icon="Check"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                {{ saving ? 'Guardando...' : 'Guardar' }}
                            </Button>
                        </div>
                        <p
                            class="mt-3 flex items-start gap-2 border-t border-dashed border-slate-300/70 pt-3 text-[11px] text-slate-500 dark:border-darkmode-400"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-500"
                            />
                            <span>
                                La plantilla cubre los errores más comunes del
                                bot: mezclar tarifas de otro tipo de habitación,
                                confundir el precio por unidad con el total, y
                                apartar sin confirmar el monto.
                            </span>
                        </p>
                    </div>
                </div>

                <!-- Prompt efectivo: a lo ancho, que es un JSON largo -->
                <div class="col-span-12">
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-slate-200/70 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400"
                            >
                                <Lucide icon="Braces" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Prompt efectivo
                                </h2>
                                <p class="text-xs text-slate-500">
                                    El texto exacto que recibe el modelo, armado
                                    en vivo con lo de arriba.
                                </p>
                            </div>
                            <Button
                                variant="outline-secondary"
                                class="ml-auto h-8 shrink-0 rounded-[0.5rem] bg-white text-xs"
                                :disabled="refreshing"
                                @click="refreshPrompt"
                            >
                                <Lucide
                                    icon="RefreshCw"
                                    class="mr-1.5 h-3.5 w-3.5"
                                    :class="{ 'animate-spin': refreshing }"
                                />
                                Actualizar
                            </Button>
                        </div>
                        <div class="p-4">
                            <pre
                                class="max-h-[60vh] overflow-auto rounded bg-slate-50 p-3 font-mono text-[11px] break-words whitespace-pre-wrap text-slate-600 dark:bg-darkmode-700 dark:text-slate-300"
                                >{{ prompt }}</pre
                            >
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Confirmación para reemplazar con la plantilla base -->
        <Dialog :open="confirmTemplate" @close="confirmTemplate = false">
            <Dialog.Panel>
                <div class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-warning/10 text-warning"
                        >
                            <Lucide icon="FileText" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                ¿Reemplazar con la plantilla base?
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                El texto actual del cuadro se perderá. No se
                                guarda nada hasta que presiones Guardar.
                            </p>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="confirmTemplate = false"
                            >Cancelar</Button
                        >
                        <Button
                            variant="primary"
                            class="shadow-md shadow-primary/20"
                            @click="applyTemplate"
                        >
                            <Lucide icon="FileText" class="mr-2 h-4 w-4" /> Sí,
                            reemplazar
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
