<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import DocumentFormModal from './DocumentFormModal.vue';
import type { DocumentRow } from './types';

const props = defineProps<{
    documents: DocumentRow[];
    services: { key: string; label: string }[];
    demand: Record<string, number>;
}>();

const page = usePage();
const toasts = useToasts();

const rowAction =
    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition';

const serviceTone: Record<string, { badge: string; circle: string }> = {
    web: {
        badge: 'bg-primary/10 text-primary',
        circle: 'border-primary/10 bg-primary/10 text-primary',
    },
    social: {
        badge: 'bg-info/10 text-info',
        circle: 'border-info/10 bg-info/10 text-info',
    },
    reservas: {
        badge: 'bg-success/10 text-success',
        circle: 'border-success/10 bg-success/10 text-success',
    },
    general: {
        badge: 'bg-pending/10 text-pending',
        circle: 'border-pending/10 bg-pending/10 text-pending',
    },
};
const fallbackTone = {
    badge: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    circle: 'border-slate-200 bg-slate-100 text-slate-500',
};
const toneOf = (service: string) => serviceTone[service] ?? fallbackTone;

const serviceIcon: Record<string, string> = {
    web: 'Globe',
    social: 'Share2',
    reservas: 'CalendarCheck',
    general: 'Files',
};

function toastFlash(): void {
    const flash = page.props.flash as
        | { success?: string | null; error?: string | null }
        | undefined;
    if (flash?.success) {
        toasts.success(flash.success);
    } else if (flash?.error) {
        toasts.error(flash.error);
    }
}

function formatSize(bytes: number): string {
    if (bytes >= 1_000_000) {
        return `${(bytes / 1_000_000).toFixed(1)} MB`;
    }
    return `${Math.max(1, Math.round(bytes / 1000))} KB`;
}

// ── Cobertura por servicio ──
const coverage = computed(() =>
    props.services.map((service) => {
        const count = props.documents.filter(
            (d) => d.service === service.key,
        ).length;
        const asking = props.demand[service.key] ?? 0;
        return {
            ...service,
            count,
            asking,
            // Un servicio que piden y no tiene PDF propio solo manda los generales.
            uncovered: service.key !== 'general' && count === 0 && asking > 0,
        };
    }),
);

const uncovered = computed(() => coverage.value.filter((c) => c.uncovered));

// ── Filtro ──
const serviceFilter = ref('');
const visible = computed(() =>
    serviceFilter.value
        ? props.documents.filter((d) => d.service === serviceFilter.value)
        : props.documents,
);

function toggleService(key: string): void {
    serviceFilter.value = serviceFilter.value === key ? '' : key;
}

// ── Alta / edición ──
const formOpen = ref(false);
const editing = ref<DocumentRow | null>(null);

function openForm(document: DocumentRow | null = null): void {
    editing.value = document;
    formOpen.value = true;
}

function onSaved(): void {
    toastFlash();
    formOpen.value = false;
}

async function copyLink(document: DocumentRow): Promise<void> {
    try {
        await navigator.clipboard.writeText(document.url);
        toasts.success('Enlace copiado.');
    } catch {
        toasts.error('No se pudo copiar el enlace.');
    }
}

// ── Borrado ──
const deleting = ref<DocumentRow | null>(null);
const deletingBusy = ref(false);

function confirmDelete(): void {
    if (!deleting.value) {
        return;
    }
    deletingBusy.value = true;
    router.delete(
        route('admin.prospects.documents.destroy', deleting.value.uuid),
        {
            preserveScroll: true,
            onSuccess: () => {
                toastFlash();
                deleting.value = null;
            },
            onFinish: () => {
                deletingBusy.value = false;
            },
        },
    );
}
</script>

<template>
    <RazeLayout title="Documentos de prospectos">
        <div class="mt-2">
            <!-- Encabezado -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="FileText" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Documentos de prospectos
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Los PDF que reciben por correo y WhatsApp según los
                            servicios que eligieron.
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Link
                        :href="route('admin.prospects')"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Volver a prospectos
                    </Link>
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        @click="openForm()"
                    >
                        <Lucide icon="FileUp" class="mr-1.5 h-3.5 w-3.5" />
                        Subir documento
                    </Button>
                </div>
            </div>

            <!-- Cobertura: un renglón por servicio, filtra al tocarlo -->
            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                <button
                    v-for="service in coverage"
                    :key="service.key"
                    type="button"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 text-left transition hover:border-slate-300 xl:col-span-3"
                    :class="
                        serviceFilter === service.key
                            ? 'ring-2 ring-primary/30'
                            : ''
                    "
                    :title="
                        serviceFilter === service.key
                            ? 'Quitar filtro'
                            : 'Ver solo los de este servicio'
                    "
                    @click="toggleService(service.key)"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                        :class="toneOf(service.key).circle"
                    >
                        <Lucide
                            :icon="
                                (serviceIcon[service.key] ?? 'FileText') as any
                            "
                            class="h-4 w-4"
                        />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ service.count }}
                            <span class="text-xs font-normal text-slate-500">{{
                                service.count === 1 ? 'documento' : 'documentos'
                            }}</span>
                        </div>
                        <div
                            class="truncate text-xs leading-tight text-slate-500"
                        >
                            {{
                                service.key === 'general'
                                    ? 'Generales'
                                    : service.label
                            }}
                        </div>
                        <div
                            class="hidden truncate text-[11px] sm:block"
                            :class="
                                service.uncovered
                                    ? 'text-pending'
                                    : 'text-slate-400'
                            "
                        >
                            {{
                                service.key === 'general'
                                    ? 'Le llegan a todos'
                                    : service.uncovered
                                      ? `${service.asking} lo piden y no hay PDF`
                                      : `${service.asking} prospectos activos lo piden`
                            }}
                        </div>
                    </div>
                </button>
            </div>

            <!-- Aviso de servicios sin documento propio -->
            <div
                v-if="uncovered.length"
                class="box box--stacked mt-4 flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                >
                    <Lucide icon="TriangleAlert" class="h-4 w-4" />
                </div>
                <p class="min-w-0 flex-1 text-xs text-slate-500">
                    <span
                        class="font-medium text-slate-700 dark:text-slate-300"
                    >
                        {{ uncovered.map((c) => c.label).join(', ') }}
                    </span>
                    {{ uncovered.length === 1 ? 'no tiene' : 'no tienen' }}
                    documento propio: quien lo pide solo recibe los generales.
                </p>
                <Button
                    variant="outline-secondary"
                    class="h-8 shrink-0 rounded-[0.5rem] text-xs"
                    @click="openForm()"
                >
                    <Lucide icon="FileUp" class="mr-1.5 h-3.5 w-3.5" />
                    Subir ahora
                </Button>
            </div>

            <!-- Listado -->
            <div class="box box--stacked mt-4 overflow-hidden">
                <div
                    class="flex flex-col gap-2 border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 sm:flex-row sm:items-center dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <FormSelect
                        v-model="serviceFilter"
                        class="h-9 text-xs sm:w-60"
                    >
                        <option value="">Todos los servicios</option>
                        <option
                            v-for="service in services"
                            :key="service.key"
                            :value="service.key"
                        >
                            {{ service.label }}
                        </option>
                    </FormSelect>
                    <span class="text-xs text-slate-500 sm:ml-auto">
                        {{ visible.length }}
                        {{ visible.length === 1 ? 'documento' : 'documentos' }}
                        · se envían en este orden
                    </span>
                </div>

                <div
                    v-if="visible.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="document in visible"
                        :key="document.uuid"
                        class="flex flex-col gap-3 px-4 py-3 transition hover:bg-slate-50/70 sm:flex-row sm:items-center sm:px-5 dark:hover:bg-darkmode-400/30"
                    >
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-danger/10 text-danger"
                            >
                                <Lucide icon="FileText" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="flex min-w-0 items-center gap-2">
                                    <a
                                        :href="document.url"
                                        target="_blank"
                                        rel="noopener"
                                        class="truncate text-sm font-medium hover:text-primary"
                                        >{{ document.title }}</a
                                    >
                                    <span
                                        class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="toneOf(document.service).badge"
                                        >{{
                                            document.service === 'general'
                                                ? 'General'
                                                : document.service_label
                                        }}</span
                                    >
                                </div>
                                <div
                                    class="truncate text-xs leading-tight text-slate-500"
                                >
                                    {{ document.original_name }} ·
                                    {{ formatSize(document.size) }}
                                </div>
                            </div>
                        </div>

                        <div
                            class="flex items-center justify-between gap-3 pl-12 sm:contents"
                        >
                            <div
                                class="text-xs whitespace-nowrap text-slate-500 sm:w-48 sm:shrink-0"
                            >
                                <div v-if="document.updated_at">
                                    Actualizado {{ document.updated_at }}
                                </div>
                                <div class="text-[11px] text-slate-400">
                                    Orden {{ document.sort }}
                                </div>
                            </div>
                            <div
                                class="flex items-center justify-end gap-1 sm:shrink-0"
                            >
                                <a
                                    :href="document.url"
                                    target="_blank"
                                    rel="noopener"
                                    :class="rowAction"
                                    class="hover:bg-primary/10 hover:text-primary"
                                    title="Ver PDF"
                                >
                                    <Lucide
                                        icon="ExternalLink"
                                        class="h-4 w-4"
                                    />
                                </a>
                                <button
                                    type="button"
                                    :class="rowAction"
                                    class="hover:bg-primary/10 hover:text-primary"
                                    title="Copiar enlace público"
                                    @click="copyLink(document)"
                                >
                                    <Lucide icon="Link2" class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    :class="rowAction"
                                    class="hover:bg-primary/10 hover:text-primary"
                                    title="Editar o reemplazar"
                                    @click="openForm(document)"
                                >
                                    <Lucide icon="Pencil" class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    :class="rowAction"
                                    class="hover:bg-danger/10 hover:text-danger"
                                    title="Eliminar"
                                    @click="deleting = document"
                                >
                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-2 px-6 py-12 text-center"
                >
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="FileText" class="h-4 w-4" />
                    </div>
                    <p class="text-xs text-slate-500">
                        {{
                            serviceFilter
                                ? 'Este servicio todavía no tiene documentos.'
                                : 'Aún no hay documentos. Sube los PDF de cada servicio: se adjuntan al correo del registro y se comparten por WhatsApp.'
                        }}
                    </p>
                    <Button
                        variant="outline-primary"
                        class="mt-1 h-9 rounded-[0.5rem] text-xs"
                        @click="openForm()"
                    >
                        <Lucide icon="FileUp" class="mr-1.5 h-3.5 w-3.5" />
                        Subir documento
                    </Button>
                </div>
            </div>
        </div>

        <DocumentFormModal
            :open="formOpen"
            :document="editing"
            :services="services"
            :default-service="serviceFilter || undefined"
            @close="formOpen = false"
            @saved="onSaved"
        />

        <!-- Confirmar borrado -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div v-if="deleting" class="p-5">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <Dialog.Title
                                class="block border-0 p-0 text-base font-medium"
                                >Eliminar "{{ deleting.title }}"</Dialog.Title
                            >
                            <p class="mt-0.5 text-xs text-slate-500">
                                Su enlace público deja de abrir, también en los
                                mensajes que ya se mandaron. No se puede
                                deshacer.
                            </p>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            @click="deleting = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="deletingBusy"
                            @click="confirmDelete"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                deletingBusy ? 'Eliminando...' : 'Sí, eliminar'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
