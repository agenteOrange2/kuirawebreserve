<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import QRCode from 'qrcode';
import { reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface Aspect {
    key: string;
    label: string;
}

const props = defineProps<{
    property: { id: number; name: string };
    aspects: Aspect[];
    sending: { thanks_enabled: boolean; survey_enabled: boolean };
    answeredCount: number;
    qrRooms: Array<{ id: number; label: string; url: string }>;
}>();

const toast = useToasts();
const saving = ref(false);
const errors = reactive<Record<string, string>>({});

// ── QR por habitación ──
// Los códigos se generan en el navegador y se mandan a una ventana de
// impresión: un cartel por habitación, listo para recortar y pegar.
const printingQr = ref(false);

async function printQrCodes() {
    if (!props.qrRooms.length || printingQr.value) return;
    printingQr.value = true;
    try {
        const cards = await Promise.all(
            props.qrRooms.map(async (room) => {
                const dataUrl = await QRCode.toDataURL(room.url, {
                    width: 220,
                    margin: 1,
                });
                return `<div class="card">
                    <div class="hotel">${props.property.name}</div>
                    <img src="${dataUrl}" alt="QR" />
                    <div class="room">Habitación ${room.label}</div>
                    <div class="hint">Escanea y cuéntanos cómo te fue</div>
                </div>`;
            }),
        );

        const win = window.open('', '_blank');
        if (!win) {
            toast.error(
                'No se pudo abrir la ventana',
                'Permite las ventanas emergentes para imprimir los códigos.',
            );
            return;
        }

        win.document.write(`<!doctype html><html lang="es"><head>
            <meta charset="utf-8" />
            <title>Códigos QR de encuesta · ${props.property.name}</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: system-ui, sans-serif; padding: 24px; }
                .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
                .card { border: 1px dashed #94a3b8; border-radius: 12px; padding: 16px; text-align: center; break-inside: avoid; }
                .hotel { font-size: 11px; color: #64748b; }
                img { width: 160px; height: 160px; margin: 8px auto; display: block; }
                .room { font-weight: 600; font-size: 14px; }
                .hint { font-size: 11px; color: #64748b; margin-top: 4px; }
                @media print { body { padding: 0; } }
            </style>
        </head><body><div class="grid">${cards.join('')}</div></body></html>`);
        win.document.close();
        win.focus();
        setTimeout(() => win.print(), 300);
    } finally {
        printingQr.value = false;
    }
}

// Copia editable: key vacía = aspecto nuevo (el backend le genera su
// identificador estable a partir del texto).
const aspects = ref<Aspect[]>(props.aspects.map((a) => ({ ...a })));

const MAX_ASPECTS = 8;

// ── Alta y edición en modal ──
// La lista muestra el aspecto y su orden; escribir pasa al modal. Antes cada
// aspecto era un input suelto en fila con sus flechas y su bote de basura.
const aspectModal = ref(false);
const editingAspect = ref<number | null>(null);
const aspectDraft = ref('');

function openAspect(index: number | null) {
    editingAspect.value = index;
    aspectDraft.value = index === null ? '' : aspects.value[index].label;
    Object.keys(errors).forEach((k) => delete errors[k]);
    aspectModal.value = true;
}

// Cada acción persiste sola: no hay un "Guardar" al final que se pueda
// olvidar tras escribir un aspecto.
async function saveAspect() {
    const label = aspectDraft.value.trim();

    if (label === '') {
        toast.error('Falta el nombre', 'Escribe qué se va a calificar.');
        return;
    }

    const previo = [...aspects.value];

    if (editingAspect.value === null) {
        aspects.value = [...aspects.value, { key: '', label }];
    } else {
        aspects.value = aspects.value.map((a, i) =>
            i === editingAspect.value ? { ...a, label } : a,
        );
    }

    const ok = await submit();

    if (ok) {
        aspectModal.value = false;
    } else {
        aspects.value = previo;
    }
}

async function removeAspect(index: number) {
    const previo = [...aspects.value];
    aspects.value = aspects.value.filter((_, i) => i !== index);

    if (!(await submit())) aspects.value = previo;
}

async function move(index: number, delta: number) {
    const target = index + delta;
    if (target < 0 || target >= aspects.value.length) return;

    const previo = [...aspects.value];
    const copy = [...aspects.value];
    [copy[index], copy[target]] = [copy[target], copy[index]];
    aspects.value = copy;

    if (!(await submit())) aspects.value = previo;
}

async function submit(): Promise<boolean> {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        const { data } = await axios.patch(
            `/api/properties/${props.property.id}`,
            {
                settings: {
                    survey_aspects: aspects.value
                        .filter((a) => a.label.trim() !== '')
                        .map((a) => ({
                            key: a.key || null,
                            label: a.label.trim(),
                        })),
                },
            },
        );
        // Las llaves generadas por el backend regresan para que un segundo
        // guardado no cree aspectos duplicados.
        aspects.value = (data.settings?.survey_aspects ?? []).map(
            (a: Aspect) => ({ ...a }),
        );
        toast.success(
            'Guardado',
            'El cuestionario quedó actualizado; aplica desde el siguiente envío.',
        );

        return true;
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(
                ([key, msgs]) => (errors[key] = (msgs as string[])[0]),
            );
            toast.error('Revisa el formulario', Object.values(errors)[0]);
        } else {
            toast.error('Error', data?.message ?? 'No se pudo guardar.');
        }

        return false;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Encuestas">
        <div class="mt-2">
            <!-- Header de tarjeta, mismo patrón que Usuarios: icono en
                 círculo + título + acciones a la derecha -->
            <div
                class="box box--stacked flex flex-wrap items-center justify-between gap-4 p-5"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <div
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Smile" class="h-7 w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl font-medium">
                            Cuestionario de experiencia
                        </h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Personaliza qué aspectos califica el huésped (1 a 5
                            estrellas). La calificación general y el comentario
                            van siempre.
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        as="a"
                        :href="route('tenant.hotel-settings')"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="ArrowLeft"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Volver a Ajustes
                    </Button>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-6">
                <!-- Editor de aspectos -->
                <div class="col-span-12 xl:col-span-7">
                    <div class="box box--stacked">
                        <div
                            class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="ListChecks"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-base font-medium">
                                    Aspectos que se califican
                                </h2>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                Cada aspecto es una pregunta de 1 a 5 estrellas,
                                opcional para el huésped. El orden de aquí es el
                                orden en que las verá.
                            </p>
                        </div>
                        <div class="p-5">
                            <div
                                v-if="aspects.length"
                                class="mt-4 flex flex-col gap-2"
                            >
                                <div
                                    v-for="(aspect, index) in aspects"
                                    :key="aspect.key || `nuevo-${index}`"
                                    class="flex items-center gap-3 rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400"
                                >
                                    <div class="flex flex-col">
                                        <button
                                            type="button"
                                            class="flex h-5 w-5 items-center justify-center rounded text-slate-400 transition hover:text-primary disabled:opacity-30"
                                            :disabled="index === 0 || saving"
                                            title="Subir"
                                            @click="move(index, -1)"
                                        >
                                            <Lucide
                                                icon="ChevronUp"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                        <button
                                            type="button"
                                            class="flex h-5 w-5 items-center justify-center rounded text-slate-400 transition hover:text-primary disabled:opacity-30"
                                            :disabled="
                                                index === aspects.length - 1 ||
                                                saving
                                            "
                                            title="Bajar"
                                            @click="move(index, 1)"
                                        >
                                            <Lucide
                                                icon="ChevronDown"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                    </div>
                                    <span
                                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs text-slate-500 dark:bg-darkmode-400"
                                    >
                                        {{ index + 1 }}
                                    </span>
                                    <span
                                        class="min-w-0 flex-1 truncate text-sm"
                                    >
                                        {{ aspect.label }}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline-secondary"
                                        size="sm"
                                        class="shrink-0 rounded-[0.5rem] bg-white"
                                        @click="openAspect(index)"
                                    >
                                        <Lucide
                                            icon="Settings"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Editar
                                    </Button>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                        title="Quitar aspecto"
                                        :disabled="saving"
                                        @click="removeAspect(index)"
                                    >
                                        <Lucide icon="Trash2" class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                            <p
                                v-else
                                class="mt-4 rounded-lg border border-dashed border-slate-300/70 py-6 text-center text-sm text-slate-500 dark:border-darkmode-400"
                            >
                                Sin aspectos: la encuesta pregunta solo la
                                calificación general y el comentario.
                            </p>

                            <FormHelp
                                v-if="errors['settings.survey_aspects']"
                                class="mt-2 text-danger"
                            >
                                {{ errors['settings.survey_aspects'] }}
                            </FormHelp>

                            <Button
                                variant="outline-primary"
                                class="mt-4 rounded-[0.5rem]"
                                :disabled="
                                    aspects.length >= MAX_ASPECTS || saving
                                "
                                @click="openAspect(null)"
                            >
                                <Lucide icon="Plus" class="mr-2 h-4 w-4" />
                                Agregar aspecto
                            </Button>
                            <FormHelp>
                                Hasta {{ MAX_ASPECTS }} aspectos. Sin ninguno,
                                la encuesta pregunta solo la calificación
                                general y el comentario. Renombrar un aspecto
                                conserva sus respuestas anteriores; quitarlo
                                deja de preguntarlo pero no borra lo ya
                                respondido.
                            </FormHelp>
                        </div>
                    </div>
                </div>

                <!-- Vista previa + estado del envío -->
                <div class="col-span-12 xl:col-span-5">
                    <div class="box box--stacked">
                        <div
                            class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="Eye"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-base font-medium">
                                    Así la ve el huésped
                                </h2>
                            </div>
                        </div>
                        <div class="p-5">
                            <div
                                class="mt-3 rounded-xl border border-slate-200/70 p-4 dark:border-darkmode-400"
                            >
                                <div class="text-center">
                                    <div
                                        class="text-sm font-medium text-slate-700 dark:text-slate-300"
                                    >
                                        En general
                                    </div>
                                    <div
                                        class="mt-1.5 flex justify-center gap-1"
                                    >
                                        <Lucide
                                            v-for="n in 5"
                                            :key="n"
                                            icon="Star"
                                            class="h-6 w-6"
                                            :class="
                                                n <= 4
                                                    ? 'fill-current text-warning'
                                                    : 'text-slate-300'
                                            "
                                        />
                                    </div>
                                </div>
                                <div
                                    v-if="aspects.some((a) => a.label.trim())"
                                    class="mt-4 space-y-2 border-t border-dashed border-slate-200/70 pt-3 dark:border-darkmode-400"
                                >
                                    <div
                                        v-for="(
                                            aspect, index
                                        ) in aspects.filter((a) =>
                                            a.label.trim(),
                                        )"
                                        :key="`preview-${index}`"
                                        class="flex items-center justify-between gap-3"
                                    >
                                        <span class="text-sm text-slate-600">{{
                                            aspect.label
                                        }}</span>
                                        <span class="flex gap-0.5">
                                            <Lucide
                                                v-for="n in 5"
                                                :key="n"
                                                icon="Star"
                                                class="h-4 w-4 text-slate-300"
                                            />
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <div
                                        class="rounded-lg border border-slate-200/70 px-3 py-2 text-xs text-slate-400 dark:border-darkmode-400"
                                    >
                                        ¿Algo que quieras contarnos? (opcional)
                                    </div>
                                </div>
                            </div>

                            <div
                                class="mt-4 flex items-start gap-2 rounded-lg px-3.5 py-2.5 text-xs"
                                :class="
                                    sending.thanks_enabled &&
                                    sending.survey_enabled
                                        ? 'bg-success/10 text-success'
                                        : 'bg-warning/10 text-warning'
                                "
                            >
                                <Lucide
                                    :icon="
                                        sending.thanks_enabled &&
                                        sending.survey_enabled
                                            ? 'CircleCheck'
                                            : 'TriangleAlert'
                                    "
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                />
                                <span>
                                    <template
                                        v-if="
                                            sending.thanks_enabled &&
                                            sending.survey_enabled
                                        "
                                    >
                                        El envío está activo: el link de la
                                        encuesta viaja con el agradecimiento al
                                        completar cada estancia.
                                    </template>
                                    <template v-else>
                                        El envío está apagado. Se activa en
                                        <Link
                                            :href="
                                                route('tenant.guest-notices')
                                            "
                                            class="font-medium underline"
                                            >Avisos al huésped</Link
                                        >, junto al agradecimiento al salir.
                                    </template>
                                </span>
                            </div>

                            <p class="mt-3 text-xs text-slate-500">
                                {{ answeredCount }} respuesta(s) recibidas hasta
                                hoy. Los cambios aplican desde el siguiente
                                envío; las respuestas anteriores no se pierden.
                            </p>
                        </div>
                    </div>

                    <!-- QR por habitación -->
                    <div class="box box--stacked mt-5">
                        <div
                            class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="QrCode"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-base font-medium">
                                    QR dentro de la habitación
                                </h2>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                Cada habitación tiene una liga fija: al
                                escanearla, el huésped cae en la encuesta de SU
                                estancia (la que está en curso o la salida
                                reciente). Imprime los carteles y pégalos en las
                                habitaciones.
                            </p>
                        </div>
                        <div class="p-5">
                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <Button
                                    variant="outline-primary"
                                    class="rounded-[0.5rem] bg-white"
                                    :disabled="!qrRooms.length || printingQr"
                                    @click="printQrCodes"
                                >
                                    <Lucide
                                        icon="Printer"
                                        class="mr-2 h-4 w-4 stroke-[1.3]"
                                    />
                                    {{
                                        printingQr
                                            ? 'Generando…'
                                            : `Imprimir códigos (${qrRooms.length})`
                                    }}
                                </Button>
                                <span class="text-xs text-slate-400">
                                    Un cartel por habitación, listos para
                                    recortar.
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alta y edición del aspecto en modal. -->
        <Dialog :open="aspectModal" @close="aspectModal = false">
            <Dialog.Panel>
                <Dialog.Title>
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ListChecks" class="h-5 w-5" />
                    </div>
                    <h2 class="ml-3 text-base font-medium">
                        {{
                            editingAspect === null
                                ? 'Agregar aspecto'
                                : 'Editar aspecto'
                        }}
                    </h2>
                </Dialog.Title>
                <Dialog.Description>
                    <label class="mb-1 block text-sm">¿Qué se califica?</label>
                    <FormInput
                        v-model="aspectDraft"
                        type="text"
                        maxlength="60"
                        placeholder="Limpieza, Atención, Alberca..."
                        @keyup.enter="saveAspect"
                    />
                    <FormHelp>
                        El huésped lo verá como una pregunta de 1 a 5 estrellas,
                        opcional. Renombrarlo conserva las respuestas que ya
                        tenía.
                    </FormHelp>
                    <FormHelp
                        v-if="errors['settings.survey_aspects']"
                        class="text-danger"
                    >
                        {{ errors['settings.survey_aspects'] }}
                    </FormHelp>
                </Dialog.Description>
                <Dialog.Footer>
                    <Button
                        variant="outline-secondary"
                        class="mr-2 w-24"
                        @click="aspectModal = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="primary"
                        class="w-28"
                        :disabled="saving"
                        @click="saveAspect"
                    >
                        {{ saving ? 'Guardando…' : 'Guardar' }}
                    </Button>
                </Dialog.Footer>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
