<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSwitch, FormTextarea } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ClassSetting {
    responder_publico: boolean;
    mandar_privado: boolean;
    plantilla: string;
}

const props = defineProps<{
    settings: {
        activo: boolean;
        moderacion_automatica: boolean;
        palabras_bloqueadas: string[];
        avisar_quejas: boolean;
        clasificaciones: Record<string, ClassSetting>;
    };
    classifications: Record<string, string>;
    lockedClassifications: string[];
    agentReady: boolean;
    connected: string[];
}>();

const form = useForm({
    activo: props.settings.activo,
    moderacion_automatica: props.settings.moderacion_automatica,
    avisar_quejas: props.settings.avisar_quejas,
    palabras_bloqueadas: [...props.settings.palabras_bloqueadas],
    clasificaciones: JSON.parse(
        JSON.stringify(props.settings.clasificaciones),
    ) as Record<string, ClassSetting>,
});

const newWord = ref('');

function addWord() {
    const word = newWord.value.trim();
    if (word === '' || form.palabras_bloqueadas.includes(word)) return;
    form.palabras_bloqueadas.push(word);
    newWord.value = '';
}

function removeWord(word: string) {
    form.palabras_bloqueadas = form.palabras_bloqueadas.filter(
        (item) => item !== word,
    );
}

function isLocked(key: string) {
    return props.lockedClassifications.includes(key);
}

function save() {
    form.patch(route('tenant.social-settings.update'), {
        preserveScroll: true,
    });
}
</script>

<template>
    <RazeLayout title="Ajustes de redes sociales">
        <div class="mt-2 grid grid-cols-12 gap-5">
            <!-- Encabezado -->
            <div class="col-span-12">
                <div
                    class="box box--stacked flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary sm:h-14 sm:w-14"
                        >
                            <Lucide
                                icon="Settings"
                                class="h-5 w-5 sm:h-7 sm:w-7"
                            />
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-lg font-medium sm:text-xl">
                                Ajustes de redes sociales
                            </h1>
                            <p class="mt-1 text-sm text-slate-500">
                                Qué contesta el asistente por su cuenta y qué
                                espera a una persona.
                            </p>
                        </div>
                    </div>
                    <Button
                        as="a"
                        :href="route('tenant.social')"
                        variant="outline-secondary"
                        class="min-h-11 rounded-[0.5rem] bg-white"
                    >
                        <Lucide icon="ArrowLeft" class="mr-2 h-4 w-4" />
                        Volver
                    </Button>
                </div>
            </div>

            <!-- Avisos -->
            <div
                v-if="!agentReady || connected.length === 0"
                class="col-span-12"
            >
                <div
                    class="box box--stacked flex items-start gap-3 p-4 text-sm text-slate-500"
                >
                    <Lucide
                        icon="TriangleAlert"
                        class="mt-0.5 h-4 w-4 shrink-0 text-warning"
                    />
                    <div>
                        <p v-if="!agentReady">
                            El asistente todavía no tiene un proveedor de IA
                            configurado: los comentarios se guardarán, pero
                            nadie los clasificará hasta que se active.
                        </p>
                        <p v-if="connected.length === 0" class="mt-1">
                            Conecta la página de Facebook o la cuenta de
                            Instagram desde el asistente para empezar a recibir
                            comentarios.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Generales -->
            <div class="col-span-12 xl:col-span-6">
                <div class="box box--stacked h-full p-5">
                    <h2 class="text-base font-medium">Operación</h2>

                    <div class="mt-4 flex flex-col gap-4">
                        <FormSwitch>
                            <FormSwitch.Input
                                v-model="form.activo"
                                type="checkbox"
                            />
                            <FormSwitch.Label class="ml-2 text-sm">
                                El asistente atiende los comentarios
                                <span class="block text-xs text-slate-500">
                                    Apagado, todo queda en el panel para que lo
                                    trabaje el personal.
                                </span>
                            </FormSwitch.Label>
                        </FormSwitch>

                        <FormSwitch>
                            <FormSwitch.Input
                                v-model="form.avisar_quejas"
                                type="checkbox"
                            />
                            <FormSwitch.Label class="ml-2 text-sm">
                                Avisar al personal cuando llegue una queja
                                <span class="block text-xs text-slate-500">
                                    Las quejas nunca se responden solas: suena
                                    la campana para que conteste una persona.
                                </span>
                            </FormSwitch.Label>
                        </FormSwitch>

                        <FormSwitch>
                            <FormSwitch.Input
                                v-model="form.moderacion_automatica"
                                type="checkbox"
                            />
                            <FormSwitch.Label class="ml-2 text-sm">
                                Ocultar spam automáticamente
                                <span class="block text-xs text-slate-500">
                                    Ocultar no borra: el comentario sigue
                                    visible para quien lo escribió y se puede
                                    revertir desde el panel.
                                </span>
                            </FormSwitch.Label>
                        </FormSwitch>
                    </div>

                    <div class="mt-6">
                        <h3 class="text-sm font-medium">
                            Palabras bloqueadas
                        </h3>
                        <p class="mt-1 text-xs text-slate-500">
                            Un comentario con alguna de estas palabras se
                            oculta de inmediato, sin pasar por la IA.
                        </p>
                        <div class="mt-3 flex gap-2">
                            <FormInput
                                v-model="newWord"
                                placeholder="Escribe una palabra o frase"
                                class="flex-1"
                                @keyup.enter="addWord"
                            />
                            <Button
                                variant="outline-secondary"
                                class="min-h-11 rounded-[0.5rem] bg-white"
                                @click="addWord"
                            >
                                Agregar
                            </Button>
                        </div>
                        <div
                            v-if="form.palabras_bloqueadas.length > 0"
                            class="mt-3 flex flex-wrap gap-2"
                        >
                            <span
                                v-for="word in form.palabras_bloqueadas"
                                :key="word"
                                class="flex items-center gap-1.5 rounded-full border border-slate-200/70 px-3 py-1 text-xs dark:border-darkmode-400"
                            >
                                {{ word }}
                                <button
                                    type="button"
                                    title="Quitar"
                                    @click="removeWord(word)"
                                >
                                    <Lucide icon="X" class="h-3 w-3" />
                                </button>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Por tipo de comentario -->
            <div class="col-span-12 xl:col-span-6">
                <div class="box box--stacked h-full p-5">
                    <h2 class="text-base font-medium">
                        Según el tipo de comentario
                    </h2>
                    <p class="mt-1 text-xs text-slate-500">
                        La plantilla se usa solo si la IA no logra redactar la
                        respuesta.
                    </p>

                    <div class="mt-4 flex flex-col gap-4">
                        <div
                            v-for="(label, key) in classifications"
                            :key="key"
                            class="rounded-[0.5rem] border border-slate-200/70 p-4 dark:border-darkmode-400"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <span class="text-sm font-medium">
                                    {{ label }}
                                </span>
                                <span
                                    v-if="isLocked(key)"
                                    class="rounded-full border border-danger/20 bg-danger/10 px-2 py-0.5 text-xs text-danger"
                                >
                                    siempre lo atiende una persona
                                </span>
                            </div>

                            <div
                                v-if="!isLocked(key)"
                                class="mt-3 flex flex-col gap-3"
                            >
                                <FormSwitch>
                                    <FormSwitch.Input
                                        v-model="
                                            form.clasificaciones[key]
                                                .responder_publico
                                        "
                                        type="checkbox"
                                    />
                                    <FormSwitch.Label class="ml-2 text-xs">
                                        Responder en el hilo público
                                    </FormSwitch.Label>
                                </FormSwitch>
                                <FormSwitch>
                                    <FormSwitch.Input
                                        v-model="
                                            form.clasificaciones[key]
                                                .mandar_privado
                                        "
                                        type="checkbox"
                                    />
                                    <FormSwitch.Label class="ml-2 text-xs">
                                        Abrir el mensaje privado
                                    </FormSwitch.Label>
                                </FormSwitch>
                                <FormTextarea
                                    v-model="form.clasificaciones[key].plantilla"
                                    rows="2"
                                    class="text-sm"
                                    placeholder="Respuesta de respaldo"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-span-12 flex justify-end">
                <Button
                    variant="primary"
                    class="min-h-11 w-full rounded-[0.5rem] sm:w-40"
                    :disabled="form.processing"
                    @click="save"
                >
                    <Lucide icon="Save" class="mr-2 h-4 w-4" />
                    Guardar
                </Button>
            </div>
        </div>
    </RazeLayout>
</template>
