<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { DocumentRow } from './types';

const props = defineProps<{
    open: boolean;
    // null = alta; con documento = edición.
    document: DocumentRow | null;
    services: { key: string; label: string }[];
    defaultService?: string;
}>();

const emit = defineEmits<{ close: []; saved: [] }>();

const fileInput = ref<HTMLInputElement | null>(null);
const isEdit = computed(() => props.document !== null);

const form = useForm({
    _method: 'post' as 'post' | 'patch',
    title: '',
    service: 'general',
    sort: 0 as number | string,
    file: null as File | null,
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }
        form.clearErrors();
        form._method = props.document ? 'patch' : 'post';
        form.title = props.document?.title ?? '';
        form.service =
            props.document?.service ??
            props.defaultService ??
            props.services[0]?.key ??
            'general';
        form.sort = props.document?.sort ?? 0;
        form.file = null;
        if (fileInput.value) {
            fileInput.value.value = '';
        }
    },
);

const fieldLabel = 'mb-1.5 block text-xs font-medium';
const sectionLabel =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';

function formatSize(bytes: number): string {
    if (bytes >= 1_000_000) {
        return `${(bytes / 1_000_000).toFixed(1)} MB`;
    }
    return `${Math.max(1, Math.round(bytes / 1000))} KB`;
}

function pickFile(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.file = file;
    form.clearErrors('file');
    // Sin título todavía: se propone el nombre del archivo.
    if (file && !form.title.trim()) {
        form.title = file.name.replace(/\.pdf$/i, '').replace(/[-_]+/g, ' ');
    }
}

function submit(): void {
    const url = props.document
        ? route('admin.prospects.documents.update', props.document.uuid)
        : route('admin.prospects.documents.store');

    // POST con _method patch: PATCH multipart no llega parseado a PHP.
    form.transform((data) => ({
        ...data,
        sort: data.sort === '' ? 0 : Number(data.sort),
    })).post(url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
}
</script>

<template>
    <Dialog :open="open" @close="emit('close')">
        <Dialog.Panel class="sm:w-[94vw] lg:w-[560px]">
            <form
                class="flex max-h-[calc(100dvh-6rem)] flex-col"
                @submit.prevent="submit"
            >
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide
                            :icon="isEdit ? 'Pencil' : 'FileUp'"
                            class="h-4 w-4"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <Dialog.Title
                            class="block border-0 p-0 text-base font-medium"
                        >
                            {{
                                isEdit ? 'Editar documento' : 'Subir documento'
                            }}
                        </Dialog.Title>
                        <p class="mt-0.5 truncate text-xs text-slate-500">
                            {{
                                isEdit
                                    ? document?.title
                                    : 'Se adjunta al correo y se comparte por WhatsApp.'
                            }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                        title="Cerrar"
                        @click="emit('close')"
                    >
                        <Lucide icon="X" class="h-4 w-4" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4">
                    <section>
                        <h3 :class="sectionLabel">Qué es</h3>
                        <div class="mt-2 grid grid-cols-12 gap-4">
                            <label class="col-span-12">
                                <span :class="fieldLabel">Título</span>
                                <FormInput
                                    v-model="form.title"
                                    type="text"
                                    class="h-9 text-xs"
                                    placeholder="Ej. Presentación de páginas web"
                                />
                                <span
                                    v-if="form.errors.title"
                                    class="mt-1 block text-xs text-danger"
                                    >{{ form.errors.title }}</span
                                >
                            </label>
                            <label class="col-span-8">
                                <span :class="fieldLabel">Servicio</span>
                                <FormSelect
                                    v-model="form.service"
                                    class="h-9 text-xs"
                                >
                                    <option
                                        v-for="service in services"
                                        :key="service.key"
                                        :value="service.key"
                                    >
                                        {{ service.label }}
                                    </option>
                                </FormSelect>
                                <span
                                    v-if="form.errors.service"
                                    class="mt-1 block text-xs text-danger"
                                    >{{ form.errors.service }}</span
                                >
                            </label>
                            <label class="col-span-4">
                                <span :class="fieldLabel">Orden</span>
                                <FormInput
                                    v-model="form.sort"
                                    type="number"
                                    min="0"
                                    max="999"
                                    class="h-9 text-xs"
                                />
                                <span
                                    v-if="form.errors.sort"
                                    class="mt-1 block text-xs text-danger"
                                    >{{ form.errors.sort }}</span
                                >
                            </label>
                        </div>
                        <p class="mt-2 text-[11px] text-slate-400">
                            Los generales le llegan a todos; el orden decide en
                            qué lugar aparece en el correo y en el mensaje.
                        </p>
                    </section>

                    <section>
                        <h3 :class="sectionLabel">Archivo</h3>
                        <div
                            class="mt-2 flex items-center gap-3 rounded-lg border border-dashed border-slate-300/80 p-3 dark:border-darkmode-400"
                        >
                            <div
                                class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg bg-danger/10 text-danger"
                            >
                                <Lucide icon="FileText" class="h-6 w-6" />
                            </div>
                            <div class="min-w-0 flex-1 text-xs">
                                <div class="truncate font-medium">
                                    {{
                                        form.file?.name ??
                                        document?.original_name ??
                                        'Ningún archivo elegido'
                                    }}
                                </div>
                                <div class="text-slate-500">
                                    <template v-if="form.file">
                                        {{ formatSize(form.file.size) }} ·
                                        {{
                                            isEdit
                                                ? 'reemplazará al actual'
                                                : 'listo para subir'
                                        }}
                                    </template>
                                    <template v-else-if="document">
                                        {{ formatSize(document.size) }} ·
                                        archivo actual
                                    </template>
                                    <template v-else
                                        >Solo PDF, hasta 10 MB.</template
                                    >
                                </div>
                            </div>
                            <Button
                                type="button"
                                variant="outline-secondary"
                                class="h-8 shrink-0 rounded-[0.5rem] text-xs"
                                @click="fileInput?.click()"
                            >
                                <Lucide
                                    icon="Upload"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                {{
                                    form.file || document
                                        ? 'Cambiar'
                                        : 'Elegir PDF'
                                }}
                            </Button>
                            <input
                                ref="fileInput"
                                type="file"
                                accept="application/pdf"
                                class="hidden"
                                @change="pickFile"
                            />
                        </div>
                        <span
                            v-if="form.errors.file"
                            class="mt-1 block text-xs text-danger"
                            >{{ form.errors.file }}</span
                        >
                        <p
                            v-else-if="isEdit"
                            class="mt-2 text-[11px] text-slate-400"
                        >
                            El enlace público no cambia: lo que ya compartiste
                            abrirá la versión nueva.
                        </p>
                        <div
                            v-if="form.progress"
                            class="mt-2 h-1 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                        >
                            <div
                                class="h-full bg-primary transition-all"
                                :style="{
                                    width: `${form.progress.percentage ?? 0}%`,
                                }"
                            />
                        </div>
                    </section>
                </div>

                <div
                    class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <Button
                        type="button"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        @click="emit('close')"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="form.processing || (!isEdit && !form.file)"
                    >
                        <Lucide
                            :icon="isEdit ? 'Save' : 'Upload'"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        {{
                            form.processing
                                ? isEdit
                                    ? 'Guardando...'
                                    : 'Subiendo...'
                                : isEdit
                                  ? 'Guardar'
                                  : 'Subir documento'
                        }}
                    </Button>
                </div>
            </form>
        </Dialog.Panel>
    </Dialog>
</template>
