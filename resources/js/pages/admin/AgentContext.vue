<script setup lang="ts">
import axios from 'axios';
import { ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormSwitch, FormTextarea } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    tenant: { id: string; name: string };
    platformInstructions: string | null;
    template: string;
    prompt: string;
    contextEditable: boolean;
    guidelinesEditable: boolean;
}>();

const toast = useToasts();

const instructions = ref(props.platformInstructions ?? '');
const promptText = ref(props.prompt);
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

async function refreshPrompt() {
    refreshing.value = true;
    try {
        const { data } = await axios.get<{ prompt: string }>(
            route('admin.ai.tenants.prompt', props.tenant.id),
        );
        promptText.value = data.prompt;
    } catch (e: any) {
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ??
                'Ocurrió un error al cargar el prompt.',
        );
    } finally {
        refreshing.value = false;
    }
}

const contextEditableLocal = ref(props.contextEditable);

async function toggleContextEditable() {
    const value = !contextEditableLocal.value;
    contextEditableLocal.value = value;
    try {
        await axios.patch(route('admin.ai.tenants.update', props.tenant.id), {
            context_editable: value,
        });
        toast.success('Visibilidad actualizada');
    } catch (e: any) {
        // Se revierte el switch si el servidor rechazó el cambio.
        contextEditableLocal.value = !value;
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    }
}

const guidelinesEditableLocal = ref(props.guidelinesEditable);

async function toggleGuidelinesEditable() {
    const value = !guidelinesEditableLocal.value;
    guidelinesEditableLocal.value = value;
    try {
        await axios.patch(route('admin.ai.tenants.update', props.tenant.id), {
            guidelines_editable: value,
        });
        toast.success('Visibilidad actualizada');
    } catch (e: any) {
        guidelinesEditableLocal.value = !value;
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    }
}

async function saveInstructions() {
    saving.value = true;
    try {
        await axios.patch(route('admin.ai.tenants.update', props.tenant.id), {
            platform_instructions: instructions.value.trim() || null,
        });
        toast.success('Instrucciones guardadas');
        await refreshPrompt();
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
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Contexto del bot</h1>
                    <p class="text-sm text-slate-500">
                        Instrucciones de plataforma y prompt efectivo de
                        {{ tenant.name }}
                    </p>
                </div>
                <Button
                    as="a"
                    :href="route('admin.ai')"
                    variant="outline-secondary"
                    class="rounded-[0.5rem] bg-white"
                >
                    <Lucide
                        icon="ArrowLeft"
                        class="mr-2 h-4 w-4 stroke-[1.3]"
                    />
                    Volver a Agentes IA
                </Button>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-5">
                <!-- Instrucciones de plataforma -->
                <div class="col-span-12 flex flex-col gap-5 xl:col-span-7">
                    <div class="box box--stacked flex flex-1 flex-col p-5">
                        <h2 class="text-base font-medium">
                            Instrucciones de plataforma
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Definen cómo cotiza, cómo aparta y qué dice de pagos
                            este bot; van por encima de las instrucciones del
                            hotel y debajo de las reglas de seguridad — nunca
                            cobra ni confirma reservas.
                        </p>
                        <FormTextarea
                            v-model="instructions"
                            rows="22"
                            class="mt-4 font-mono text-xs"
                            placeholder="Ej. — Cotiza siempre primero la opción más económica. — El pago se registra en recepción: nunca pidas datos de tarjeta. — Para reservar exige nombre completo y teléfono."
                        />
                        <FormHelp
                            >Vacío = sin instrucciones extra de
                            plataforma.</FormHelp
                        >
                        <div
                            class="mt-4 flex flex-wrap items-center justify-end gap-2"
                        >
                            <Button
                                variant="outline-primary"
                                class="rounded-[0.5rem] bg-white"
                                @click="useTemplate"
                            >
                                <Lucide
                                    icon="FileText"
                                    class="mr-2 h-4 w-4 stroke-[1.3]"
                                />
                                Usar plantilla base
                            </Button>
                            <Button
                                variant="primary"
                                class="rounded-[0.5rem] shadow-md shadow-primary/20"
                                :disabled="saving"
                                @click="saveInstructions"
                            >
                                <Lucide
                                    icon="Check"
                                    class="mr-2 h-4 w-4 stroke-[1.3]"
                                />
                                {{ saving ? 'Guardando…' : 'Guardar' }}
                            </Button>
                        </div>
                        <p
                            class="mt-4 flex items-start gap-2 border-t border-dashed border-slate-300/70 pt-4 text-xs text-slate-500 dark:border-darkmode-400"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-4 w-4 shrink-0 text-slate-500"
                            />
                            <span>
                                La plantilla cubre los errores más comunes del
                                bot: mezclar tarifas de otro tipo de habitación,
                                confundir el precio por unidad con el total, y
                                apartar sin confirmar el monto.
                            </span>
                        </p>
                    </div>

                    <!-- Visibilidad del contexto para el hotel -->
                    <div
                        class="box box--stacked flex items-start justify-between gap-4 p-5"
                    >
                        <div class="min-w-0">
                            <div class="text-sm font-medium">
                                El hotel puede ver y editar su contexto
                            </div>
                            <FormHelp>
                                Habilita la página Contexto del bot en el panel
                                del hotel (/asistente/contexto); apagado, solo
                                la plataforma gestiona este contexto.
                            </FormHelp>
                        </div>
                        <FormSwitch class="mt-1 shrink-0">
                            <FormSwitch.Input
                                :checked="contextEditableLocal"
                                type="checkbox"
                                @change="toggleContextEditable"
                            />
                        </FormSwitch>
                    </div>

                    <!-- Aprendizajes del bot: palanca hermana de la del contexto -->
                    <div
                        class="box box--stacked flex items-start justify-between gap-4 p-5"
                    >
                        <div class="min-w-0">
                            <div class="text-sm font-medium">
                                El hotel puede capturar aprendizajes del bot
                            </div>
                            <FormHelp>
                                Habilita la página Aprendizajes
                                (/asistente/aprendizajes) y el botón "Enseñar al
                                asistente" en su Bandeja: correcciones de
                                conversaciones reales que se inyectan al prompt.
                                Apagado, las lecciones las gestiona solo la
                                plataforma.
                            </FormHelp>
                        </div>
                        <FormSwitch class="mt-1 shrink-0">
                            <FormSwitch.Input
                                :checked="guidelinesEditableLocal"
                                type="checkbox"
                                @change="toggleGuidelinesEditable"
                            />
                        </FormSwitch>
                    </div>
                </div>

                <!-- Prompt efectivo -->
                <div class="col-span-12 xl:col-span-5">
                    <div class="box box--stacked flex h-full flex-col p-5">
                        <div
                            class="flex flex-wrap items-start justify-between gap-2"
                        >
                            <div class="min-w-0">
                                <h2 class="text-base font-medium">
                                    Prompt efectivo
                                </h2>
                                <p class="mt-1 text-sm text-slate-500">
                                    Así ve el mundo este bot: identidad, datos
                                    del hotel, tarifas, FAQs, tus instrucciones
                                    y las reglas — armado en vivo.
                                </p>
                            </div>
                            <Button
                                variant="outline-secondary"
                                size="sm"
                                class="shrink-0 rounded-[0.5rem] bg-white"
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
                        <pre
                            class="mt-4 max-h-[70vh] flex-1 overflow-auto rounded bg-slate-50 p-4 font-mono text-xs break-words whitespace-pre-wrap text-slate-600 dark:bg-darkmode-700 dark:text-slate-300"
                            >{{ promptText }}</pre>
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
                            <p class="mt-0.5 text-sm text-slate-500">
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
