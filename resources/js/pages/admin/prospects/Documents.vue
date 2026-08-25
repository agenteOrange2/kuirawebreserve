<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface DocumentRow {
    uuid: string;
    title: string;
    service: string;
    service_label: string;
    original_name: string;
    size: number;
    sort: number;
    url: string;
    updated_at: string | null;
}

const props = defineProps<{
    documents: DocumentRow[];
    services: { key: string; label: string }[];
}>();

const uploadOpen = ref(false);
const editing = ref<DocumentRow | null>(null);
const deleting = ref<DocumentRow | null>(null);
const uploadInput = ref<HTMLInputElement | null>(null);
const editInput = ref<HTMLInputElement | null>(null);

const serviceTone: Record<string, string> = {
    web: 'bg-primary/10 text-primary',
    social: 'bg-info/10 text-info',
    reservas: 'bg-success/10 text-success',
    general: 'bg-pending/10 text-pending',
};

const uploadForm = useForm({
    title: '',
    service: props.services[0]?.key ?? 'general',
    sort: 0,
    file: null as File | null,
});

const editForm = useForm({
    _method: 'patch',
    title: '',
    service: 'general',
    sort: 0,
    file: null as File | null,
});

function formatSize(bytes: number): string {
    if (bytes >= 1_000_000) {
        return `${(bytes / 1_000_000).toFixed(1)} MB`;
    }
    return `${Math.max(1, Math.round(bytes / 1000))} KB`;
}

function openUpload(): void {
    uploadForm.reset();
    uploadForm.clearErrors();
    uploadOpen.value = true;
}

function pickUploadFile(event: Event): void {
    uploadForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submitUpload(): void {
    uploadForm.post(route('admin.prospects.documents.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadOpen.value = false;
            uploadForm.reset();
        },
    });
}

function openEdit(document: DocumentRow): void {
    editing.value = document;
    editForm.clearErrors();
    editForm.title = document.title;
    editForm.service = document.service;
    editForm.sort = document.sort;
    editForm.file = null;
}

function pickEditFile(event: Event): void {
    editForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submitEdit(): void {
    if (!editing.value) {
        return;
    }

    // POST con _method patch: PATCH multipart no llega parseado a PHP.
    editForm.post(
        route('admin.prospects.documents.update', editing.value.uuid),
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                editing.value = null;
            },
        },
    );
}

function confirmDelete(): void {
    if (!deleting.value) {
        return;
    }

    router.delete(
        route('admin.prospects.documents.destroy', deleting.value.uuid),
        {
            preserveScroll: true,
            onSuccess: () => {
                deleting.value = null;
            },
        },
    );
}
</script>

<template>
    <RazeLayout title="Documentos de prospectos">
        <div
            class="mt-2 flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center"
        >
            <div>
                <h1 class="text-lg font-medium group-[.mode--light]:text-white">
                    Documentos de prospectos
                </h1>
                <p class="text-sm text-slate-500">
                    PDF que se envían por correo y WhatsApp según los servicios
                    elegidos en el registro
                </p>
            </div>
            <div class="flex gap-2 md:ml-auto">
                <Button
                    :as="Link"
                    :href="route('admin.prospects')"
                    variant="outline-secondary"
                    class="bg-white/80 dark:bg-darkmode-400/80"
                >
                    <Lucide icon="ArrowLeft" class="mr-2 h-4 w-4" />
                    Volver a prospectos
                </Button>
                <Button variant="primary" @click="openUpload">
                    <Lucide icon="Plus" class="mr-2 h-4 w-4" />
                    Subir documento
                </Button>
            </div>
        </div>

        <div v-if="documents.length" class="mt-5 grid grid-cols-12 gap-5">
            <div
                v-for="document in documents"
                :key="document.uuid"
                class="col-span-12 md:col-span-6 xl:col-span-4"
            >
                <div class="box box--stacked flex h-full flex-col p-5">
                    <div class="flex items-start gap-3">
                        <span
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                            ><Lucide icon="FileText" class="h-5 w-5"
                        /></span>
                        <div class="min-w-0">
                            <div
                                class="font-medium text-slate-800 dark:text-slate-200"
                            >
                                {{ document.title }}
                            </div>
                            <span
                                class="mt-1.5 inline-flex rounded-md px-2 py-0.5 text-xs font-medium"
                                :class="
                                    serviceTone[document.service] ??
                                    'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                "
                                >{{ document.service_label }}</span
                            >
                        </div>
                    </div>
                    <div class="mt-4 flex-1 text-xs text-slate-500">
                        <div class="truncate">{{ document.original_name }}</div>
                        <div class="mt-1">
                            {{ formatSize(document.size) }}
                            <template v-if="document.updated_at">
                                · Actualizado {{ document.updated_at }}
                            </template>
                        </div>
                    </div>
                    <div
                        class="mt-4 flex gap-1.5 border-t border-dashed border-slate-200 pt-4"
                    >
                        <Button
                            :as="'a'"
                            :href="document.url"
                            target="_blank"
                            variant="outline-secondary"
                            size="sm"
                        >
                            <Lucide
                                icon="ExternalLink"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Ver
                        </Button>
                        <Button
                            variant="outline-secondary"
                            size="sm"
                            @click="openEdit(document)"
                        >
                            <Lucide icon="Pencil" class="mr-1.5 h-3.5 w-3.5" />
                            Editar
                        </Button>
                        <Button
                            variant="outline-danger"
                            size="sm"
                            class="ml-auto"
                            @click="deleting = document"
                        >
                            <Lucide icon="Trash2" class="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <div
            v-else
            class="box box--stacked mt-5 flex min-h-72 flex-col items-center justify-center p-8 text-center"
        >
            <span
                class="flex h-16 w-16 items-center justify-center rounded-full bg-primary/5"
                ><Lucide icon="FileText" class="h-7 w-7 text-primary"
            /></span>
            <h2 class="mt-4 text-base font-medium">Aún no hay documentos</h2>
            <p class="mt-1 max-w-sm text-sm text-slate-500">
                Sube los PDF de cada servicio: se adjuntan al correo del
                registro y se comparten por WhatsApp.
            </p>
            <Button class="mt-5" variant="primary" @click="openUpload">
                <Lucide icon="Plus" class="mr-2 h-4 w-4" />
                Subir documento
            </Button>
        </div>

        <Dialog :open="uploadOpen" @close="uploadOpen = false">
            <Dialog.Panel>
                <div class="p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <span
                            class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                            ><Lucide icon="Plus" class="h-5 w-5"
                        /></span>
                        <Dialog.Title class="text-lg font-medium"
                            >Subir documento</Dialog.Title
                        >
                    </div>
                    <div class="mt-6 space-y-5">
                        <label class="block"
                            ><span class="mb-2 block text-sm font-medium"
                                >Título</span
                            ><FormInput
                                v-model="uploadForm.title"
                                type="text"
                                placeholder="Ej. Presentación de páginas web"
                            />
                            <FormHelp
                                v-if="uploadForm.errors.title"
                                class="text-danger"
                                >{{ uploadForm.errors.title }}</FormHelp
                            ></label
                        >
                        <label class="block"
                            ><span class="mb-2 block text-sm font-medium"
                                >Servicio</span
                            ><FormSelect v-model="uploadForm.service">
                                <option
                                    v-for="service in services"
                                    :key="service.key"
                                    :value="service.key"
                                >
                                    {{ service.label }}
                                </option></FormSelect
                            >
                            <FormHelp
                                v-if="uploadForm.errors.service"
                                class="text-danger"
                                >{{ uploadForm.errors.service }}</FormHelp
                            ></label
                        >
                        <div>
                            <span class="mb-2 block text-sm font-medium"
                                >Archivo PDF</span
                            >
                            <Button
                                variant="outline-secondary"
                                type="button"
                                @click="uploadInput?.click()"
                            >
                                <Lucide icon="FileText" class="mr-2 h-4 w-4" />
                                {{ uploadForm.file?.name ?? 'Elegir archivo' }}
                            </Button>
                            <input
                                ref="uploadInput"
                                type="file"
                                accept="application/pdf"
                                class="hidden"
                                @change="pickUploadFile"
                            />
                            <FormHelp
                                v-if="uploadForm.errors.file"
                                class="text-danger"
                                >{{ uploadForm.errors.file }}</FormHelp
                            >
                            <FormHelp v-else>Solo PDF, máx. 10 MB.</FormHelp>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-3">
                        <Button
                            variant="outline-secondary"
                            @click="uploadOpen = false"
                            >Cancelar</Button
                        >
                        <Button
                            variant="primary"
                            :disabled="uploadForm.processing"
                            @click="submitUpload"
                            >{{
                                uploadForm.processing
                                    ? 'Subiendo...'
                                    : 'Subir documento'
                            }}</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <Dialog :open="editing !== null" @close="editing = null">
            <Dialog.Panel>
                <div v-if="editing" class="p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <span
                            class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                            ><Lucide icon="Pencil" class="h-5 w-5"
                        /></span>
                        <Dialog.Title class="text-lg font-medium"
                            >Editar documento</Dialog.Title
                        >
                    </div>
                    <div class="mt-6 space-y-5">
                        <label class="block"
                            ><span class="mb-2 block text-sm font-medium"
                                >Título</span
                            ><FormInput v-model="editForm.title" type="text" />
                            <FormHelp
                                v-if="editForm.errors.title"
                                class="text-danger"
                                >{{ editForm.errors.title }}</FormHelp
                            ></label
                        >
                        <label class="block"
                            ><span class="mb-2 block text-sm font-medium"
                                >Servicio</span
                            ><FormSelect v-model="editForm.service">
                                <option
                                    v-for="service in services"
                                    :key="service.key"
                                    :value="service.key"
                                >
                                    {{ service.label }}
                                </option></FormSelect
                            >
                            <FormHelp
                                v-if="editForm.errors.service"
                                class="text-danger"
                                >{{ editForm.errors.service }}</FormHelp
                            ></label
                        >
                        <div>
                            <span class="mb-2 block text-sm font-medium"
                                >Reemplazar archivo (opcional)</span
                            >
                            <Button
                                variant="outline-secondary"
                                type="button"
                                @click="editInput?.click()"
                            >
                                <Lucide icon="FileText" class="mr-2 h-4 w-4" />
                                {{
                                    editForm.file?.name ??
                                    'Conservar archivo actual'
                                }}
                            </Button>
                            <input
                                ref="editInput"
                                type="file"
                                accept="application/pdf"
                                class="hidden"
                                @change="pickEditFile"
                            />
                            <FormHelp
                                v-if="editForm.errors.file"
                                class="text-danger"
                                >{{ editForm.errors.file }}</FormHelp
                            >
                            <FormHelp v-else
                                >El enlace público no cambia: lo que ya
                                compartiste seguirá abriendo la versión
                                nueva.</FormHelp
                            >
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-3">
                        <Button
                            variant="outline-secondary"
                            @click="editing = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="primary"
                            :disabled="editForm.processing"
                            @click="submitEdit"
                            >{{
                                editForm.processing
                                    ? 'Guardando...'
                                    : 'Guardar cambios'
                            }}</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div v-if="deleting" class="p-6 text-center sm:p-8">
                    <span
                        class="mx-auto flex h-14 w-14 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                        ><Lucide icon="Trash2" class="h-6 w-6"
                    /></span>
                    <Dialog.Title class="mt-4 text-lg font-medium"
                        >Eliminar documento</Dialog.Title
                    >
                    <p class="mt-2 text-sm text-slate-500">
                        Se eliminará "{{ deleting.title }}" y su enlace público
                        dejará de funcionar, incluido en los mensajes ya
                        enviados.
                    </p>
                    <div class="mt-6 flex justify-center gap-3">
                        <Button
                            variant="outline-secondary"
                            @click="deleting = null"
                            >Cancelar</Button
                        >
                        <Button variant="danger" @click="confirmDelete"
                            >Eliminar</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
