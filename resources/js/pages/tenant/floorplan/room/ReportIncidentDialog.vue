<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { Dialog } from '@/components/Base/Headless';
import {
    FormCheck,
    FormInput,
    FormSelect,
    FormTextarea,
} from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';

/**
 * Levantar una falla del cuarto.
 *
 * Vivía como formulario desplegable dentro del tab de Mantenimiento: al
 * abrirlo empujaba las fallas abiertas fuera de la vista y dejaba el tab con
 * medio formulario a la izquierda y un hueco a la derecha. Un reporte es una
 * captura con principio y fin, así que va en su propio modal.
 */
const props = defineProps<{
    open: boolean;
    roomNumber: string;
    categories: { key: string; label: string }[];
    busy: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (
        e: 'submit',
        value: {
            title: string;
            category: string | null;
            priority: string;
            description: string | null;
            source: string;
            set_maintenance: boolean;
            photo: File | null;
        },
    ): void;
}>();

const blank = () => ({
    title: '',
    category: '',
    priority: 'medium',
    description: '',
    source: 'staff',
    set_maintenance: false,
});

const form = ref(blank());
const photo = ref<File | null>(null);
const photoInput = ref<HTMLInputElement | null>(null);

// Cada apertura empieza limpia: heredar lo que se escribió y no se mandó la
// vez pasada es la manera de reportar dos veces la misma falla.
watch(
    () => props.open,
    () => {
        form.value = blank();
        photo.value = null;

        if (photoInput.value) {
            photoInput.value.value = '';
        }
    },
);

// Una falla urgente saca el cuarto de venta salvo que alguien lo desmarque a
// propósito: vender un cuarto con una falla urgente abierta es el error caro.
watch(
    () => form.value.priority,
    (priority) => {
        if (priority === 'high') {
            form.value.set_maintenance = true;
        }
    },
);

// Miniatura de la foto elegida: sin verla, no se sabe si se tomó la correcta.
const photoPreview = ref<string | null>(null);

watch(photo, (file) => {
    if (photoPreview.value) {
        URL.revokeObjectURL(photoPreview.value);
    }

    photoPreview.value = file ? URL.createObjectURL(file) : null;
});

onBeforeUnmount(() => {
    if (photoPreview.value) {
        URL.revokeObjectURL(photoPreview.value);
    }
});

function onPhoto(event: Event) {
    const input = event.target as HTMLInputElement;
    photo.value = input.files?.[0] ?? null;
}

function removePhoto() {
    photo.value = null;

    if (photoInput.value) {
        photoInput.value.value = '';
    }
}

function submit() {
    if (!form.value.title.trim()) {
        return;
    }

    emit('submit', {
        title: form.value.title.trim(),
        category: form.value.category || null,
        priority: form.value.priority,
        description: form.value.description.trim() || null,
        source: form.value.source,
        set_maintenance: form.value.set_maintenance,
        photo: photo.value,
    });
}
</script>

<template>
    <Dialog :open="open" size="lg" @close="busy || emit('close')">
        <Dialog.Panel class="sm:w-[94vw] lg:w-[680px]">
            <form
                class="flex max-h-[calc(100dvh-6rem)] flex-col"
                @submit.prevent="submit"
            >
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                    >
                        <Lucide icon="TriangleAlert" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-medium">Levantar reporte</h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Falla de la habitación {{ roomNumber }}; queda en
                            incidencias con quien la reportó.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-darkmode-400"
                        aria-label="Cerrar"
                        @click="emit('close')"
                    >
                        <Lucide icon="X" class="h-4 w-4" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4">
                    <section>
                        <div
                            class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            Qué pasó
                        </div>
                        <div class="mt-2 grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label
                                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                                    for="incident-title"
                                    >Falla</label
                                >
                                <FormInput
                                    id="incident-title"
                                    v-model="form.title"
                                    type="text"
                                    maxlength="120"
                                    class="h-9 text-xs"
                                    placeholder="El aire no enfría"
                                    required
                                />
                            </div>
                            <div class="sm:col-span-2">
                                <label
                                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                                    for="incident-description"
                                    >Detalle</label
                                >
                                <FormTextarea
                                    id="incident-description"
                                    v-model="form.description"
                                    rows="3"
                                    maxlength="2000"
                                    class="text-xs"
                                    placeholder="Suena raro desde ayer y no baja de 26°"
                                />
                            </div>
                        </div>
                    </section>

                    <section>
                        <div
                            class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            Cómo se clasifica
                        </div>
                        <div class="mt-2 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label
                                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                                    for="incident-category"
                                    >Tipo de falla</label
                                >
                                <FormSelect
                                    id="incident-category"
                                    v-model="form.category"
                                    class="h-9 text-xs"
                                >
                                    <option value="">Sin clasificar</option>
                                    <option
                                        v-for="category in categories"
                                        :key="category.key"
                                        :value="category.key"
                                    >
                                        {{ category.label }}
                                    </option>
                                </FormSelect>
                            </div>
                            <div>
                                <label
                                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                                    for="incident-priority"
                                    >Urgencia</label
                                >
                                <FormSelect
                                    id="incident-priority"
                                    v-model="form.priority"
                                    class="h-9 text-xs"
                                >
                                    <option value="low">Puede esperar</option>
                                    <option value="medium">Normal</option>
                                    <option value="high">Urgente</option>
                                </FormSelect>
                            </div>
                            <div>
                                <label
                                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                                    for="incident-source"
                                    >Quién lo reporta</label
                                >
                                <FormSelect
                                    id="incident-source"
                                    v-model="form.source"
                                    class="h-9 text-xs"
                                >
                                    <option value="staff">El personal</option>
                                    <option value="guest">El huésped</option>
                                </FormSelect>
                            </div>
                            <!-- Foto en renglón: miniatura de 56 px y los
                                 botones a la derecha (canon §7). -->
                            <div class="sm:col-span-2">
                                <span
                                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                                    >Foto</span
                                >
                                <input
                                    id="incident-photo"
                                    ref="photoInput"
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="onPhoto"
                                />
                                <div
                                    class="flex items-center gap-3 rounded-lg border border-dashed border-slate-300/70 p-2 dark:border-darkmode-400"
                                >
                                    <div
                                        class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-md bg-slate-100 text-slate-400 dark:bg-darkmode-700"
                                    >
                                        <img
                                            v-if="photoPreview"
                                            :src="photoPreview"
                                            alt="Foto de la falla"
                                            class="h-full w-full object-cover"
                                        />
                                        <Lucide
                                            v-else
                                            icon="Camera"
                                            class="h-5 w-5"
                                        />
                                    </div>
                                    <span
                                        class="min-w-0 flex-1 truncate text-xs text-slate-500"
                                        >{{
                                            photo
                                                ? photo.name
                                                : 'Sin foto todavía'
                                        }}</span
                                    >
                                    <Button
                                        v-if="photo"
                                        variant="outline-secondary"
                                        type="button"
                                        class="h-8 rounded-[0.5rem] text-xs"
                                        @click="removePhoto"
                                    >
                                        Quitar
                                    </Button>
                                    <Button
                                        variant="outline-secondary"
                                        type="button"
                                        class="h-8 rounded-[0.5rem] text-xs"
                                        @click="photoInput?.click()"
                                    >
                                        <Lucide
                                            icon="Camera"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        {{ photo ? 'Cambiar' : 'Elegir' }}
                                    </Button>
                                </div>
                            </div>
                        </div>
                        <p class="mt-2 text-[11px] text-slate-400">
                            El tipo, el detalle y la foto son opcionales.
                        </p>
                    </section>

                    <label
                        class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-slate-200/70 px-3.5 py-3 text-xs dark:border-darkmode-400"
                    >
                        <FormCheck.Input
                            v-model="form.set_maintenance"
                            type="checkbox"
                            class="mt-0.5 shrink-0"
                        />
                        <span>
                            Sacar la habitación de venta (pasa a mantenimiento).
                            <span class="mt-0.5 block text-slate-500">
                                Una falla urgente lo marca sola; quítalo si el
                                cuarto se puede seguir vendiendo.
                            </span>
                        </span>
                    </label>
                </div>

                <div
                    class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <Button
                        variant="outline-secondary"
                        type="button"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="busy"
                        @click="emit('close')"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="primary"
                        type="submit"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="busy || !form.title.trim()"
                    >
                        {{ busy ? 'Reportando…' : 'Reportar falla' }}
                    </Button>
                </div>
            </form>
        </Dialog.Panel>
    </Dialog>
</template>
