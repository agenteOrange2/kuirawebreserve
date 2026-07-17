<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface MetaLinkRow {
    id: number;
    tenant_id: string;
    tenant_name: string;
    type: string;
    type_label: string;
    external_id: string;
    waba_id: string | null;
    masked_token: string;
    name: string | null;
    active: boolean;
    last_event_at: string | null;
}
interface EvoRow {
    id: number;
    name: string | null;
    base_url: string;
    instance: string;
    active: boolean;
    last_event_at: string | null;
}
const props = defineProps<{
    tenant: { id: string; name: string; domain: string | null };
    meta: MetaLinkRow[];
    evolution: EvoRow[];
    metaConfig: {
        mode: string;
        webhook_url: string;
        verify_token: string;
        app_configured: boolean;
    };
}>();

const toast = useToasts();
const saving = ref(false);

const metaTypeMeta: Record<
    string,
    { label: string; icon: string; tone: string; idLabel: string }
> = {
    whatsapp: {
        label: 'WhatsApp',
        icon: 'MessageCircle',
        tone: 'bg-success/10 text-success',
        idLabel: 'phone_number_id',
    },
    messenger: {
        label: 'Messenger',
        icon: 'Facebook',
        tone: 'bg-primary/10 text-primary',
        idLabel: 'page_id',
    },
    instagram: {
        label: 'Instagram',
        icon: 'Instagram',
        tone: 'bg-pending/10 text-pending',
        idLabel: 'ig_business_id',
    },
};

const channelCount = () => props.meta.length + props.evolution.length;

// Recarga solo los datos de canales del hotel tras una mutación.
const reloadChannels = () => router.reload({ only: ['meta', 'evolution'] });

// ── Vincular canal Meta (el hotel ya está fijo por la vista) ──
const showMetaForm = ref(false);
const metaError = ref<string | null>(null);
const metaForm = reactive({
    type: 'whatsapp',
    external_id: '',
    waba_id: '',
    access_token: '',
    name: '',
});

function openMetaForm() {
    metaForm.type = 'whatsapp';
    metaForm.external_id = '';
    metaForm.waba_id = '';
    metaForm.access_token = '';
    metaForm.name = '';
    metaError.value = null;
    showMetaForm.value = true;
}

async function submitMetaLink() {
    saving.value = true;
    metaError.value = null;
    try {
        await axios.post(route('admin.meta.store'), {
            ...metaForm,
            tenant_id: props.tenant.id,
            name: metaForm.name || null,
            waba_id: metaForm.waba_id || null,
        });
        showMetaForm.value = false;
        toast.success(
            'Canal vinculado',
            'El webhook ya enruta este canal al hotel.',
        );
        reloadChannels();
    } catch (e: any) {
        metaError.value =
            e.response?.data?.message ??
            (
                Object.values(e.response?.data?.errors ?? {})[0] as
                    string[] | undefined
            )?.[0] ??
            'No se pudo vincular.';
    } finally {
        saving.value = false;
    }
}

async function toggleMetaLink(link: MetaLinkRow) {
    try {
        await axios.patch(route('admin.meta.update', link.id), {
            active: !link.active,
        });
        reloadChannels();
    } catch (e: any) {
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    }
}

async function deleteMetaLink(link: MetaLinkRow) {
    await axios.delete(route('admin.meta.destroy', link.id));
    toast.success('Canal desvinculado');
    reloadChannels();
}

const copiedField = ref<string | null>(null);
async function copyMeta(field: string, value: string) {
    await navigator.clipboard.writeText(value);
    copiedField.value = field;
    setTimeout(() => (copiedField.value = null), 2000);
}

// ── Diagnóstico y reparación del webhook ──
interface DiagnoseResult {
    token_ok: boolean;
    // WhatsApp
    phone?: string | null;
    quality?: string | null;
    callback_url?: string | null;
    callback_ok?: boolean | null;
    // Messenger / Instagram (página)
    identity?: string | null;
    page_id?: string | null;
    subscribed_fields?: string[] | null;
    // Comunes
    subscribed: string[] | null;
    last_event_at: string | null;
}
const qualityMeta: Record<string, { label: string; tone: string }> = {
    GREEN: { label: 'Calidad buena', tone: 'bg-success/10 text-success' },
    YELLOW: { label: 'Calidad media', tone: 'bg-warning/10 text-warning' },
    RED: { label: 'Calidad baja', tone: 'bg-danger/10 text-danger' },
};
const diagnosingLink = ref<MetaLinkRow | null>(null);
const diagnoseLoading = ref(false);
const diagnoseError = ref<string | null>(null);
const diagnoseResult = ref<DiagnoseResult | null>(null);

async function runDiagnose() {
    if (!diagnosingLink.value) return;
    diagnoseLoading.value = true;
    diagnoseError.value = null;
    diagnoseResult.value = null;
    try {
        const { data } = await axios.post<DiagnoseResult>(
            route('admin.meta.diagnose', diagnosingLink.value.id),
        );
        diagnoseResult.value = data;
    } catch (e: any) {
        diagnoseError.value =
            e.response?.data?.message ?? 'No se pudo consultar a Meta.';
    } finally {
        diagnoseLoading.value = false;
    }
}

function openDiagnose(link: MetaLinkRow) {
    diagnosingLink.value = link;
    void runDiagnose();
}

const resubscribing = ref<number | null>(null);
async function resubscribe(link: MetaLinkRow) {
    if (resubscribing.value !== null) return;
    resubscribing.value = link.id;
    try {
        const { data } = await axios.post<{ ok: boolean; message: string }>(
            route('admin.meta.resubscribe', link.id),
        );
        toast.success('Suscripción reparada', data.message);
        if (diagnosingLink.value?.id === link.id) await runDiagnose();
    } catch (e: any) {
        toast.error(
            'No se pudo reparar',
            e.response?.data?.message ?? 'Ocurrió un error al llamar a Meta.',
        );
    } finally {
        resubscribing.value = null;
    }
}

// ── Edición de canal Meta (id externo, nombre, WABA ID y token) ──
const editingMetaLink = ref<MetaLinkRow | null>(null);
const metaEditForm = reactive({
    external_id: '',
    name: '',
    waba_id: '',
    access_token: '',
});

function openMetaEdit(link: MetaLinkRow) {
    editingMetaLink.value = link;
    metaEditForm.external_id = link.external_id;
    metaEditForm.name = link.name ?? '';
    metaEditForm.waba_id = link.waba_id ?? '';
    metaEditForm.access_token = '';
}

async function submitMetaEdit() {
    if (!editingMetaLink.value) return;
    saving.value = true;
    try {
        const payload: Record<string, unknown> = {
            name: metaEditForm.name || null,
        };
        // Corregir un id mal capturado sin tener que borrar y recrear el canal.
        if (metaEditForm.external_id.trim())
            payload.external_id = metaEditForm.external_id.trim();
        // Instagram vía página guarda aquí el page_id vinculado (suscripción);
        // la ruta Instagram Login (token IGAA) lo deja vacío.
        if (['whatsapp', 'instagram'].includes(editingMetaLink.value.type))
            payload.waba_id = metaEditForm.waba_id || null;
        // El token solo viaja si se capturó uno nuevo; vacío = conservar el actual.
        if (metaEditForm.access_token)
            payload.access_token = metaEditForm.access_token;
        await axios.patch(
            route('admin.meta.update', editingMetaLink.value.id),
            payload,
        );
        editingMetaLink.value = null;
        toast.success('Canal actualizado');
        reloadChannels();
    } catch (e: any) {
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ??
                (
                    Object.values(e.response?.data?.errors ?? {})[0] as
                        string[] | undefined
                )?.[0] ??
                'Ocurrió un error.',
        );
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Canales de mensajería">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-lg font-medium">
                            Canales de mensajería
                        </h1>
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                metaConfig.mode === 'production'
                                    ? 'bg-success/10 text-success'
                                    : 'bg-warning/10 text-warning'
                            "
                        >
                            {{
                                metaConfig.mode === 'production'
                                    ? 'Producción'
                                    : 'Entorno de prueba'
                            }}
                        </span>
                    </div>
                    <p class="text-sm text-slate-500">
                        {{ tenant.name
                        }}<span v-if="tenant.domain" class="text-slate-400">
                            · {{ tenant.domain }}</span
                        >
                        — WhatsApp, Messenger e Instagram
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
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
                    <Button
                        variant="primary"
                        class="rounded-[0.5rem] shadow-md shadow-primary/20"
                        @click="openMetaForm()"
                    >
                        <Lucide icon="Plus" class="mr-2 h-4 w-4 stroke-[1.3]" />
                        Vincular canal
                    </Button>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-5">
                <!-- Config del webhook -->
                <div class="col-span-12">
                    <div class="box box--stacked flex flex-col p-5">
                        <div
                            class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Webhook" class="h-3.5 w-3.5" />
                            Configuración en developers.facebook.com
                        </div>
                        <div
                            class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2"
                        >
                            <div>
                                <div class="mb-1 text-xs text-slate-500">
                                    URL del webhook (Callback URL)
                                </div>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2 rounded-lg bg-slate-800 px-3 py-2 text-left font-mono text-xs text-slate-200"
                                    title="Copiar"
                                    @click="
                                        copyMeta('url', metaConfig.webhook_url)
                                    "
                                >
                                    <span class="min-w-0 flex-1 truncate">{{
                                        metaConfig.webhook_url
                                    }}</span>
                                    <Lucide
                                        :icon="
                                            copiedField === 'url'
                                                ? 'Check'
                                                : 'Copy'
                                        "
                                        class="h-3.5 w-3.5 shrink-0"
                                        :class="{
                                            'text-success':
                                                copiedField === 'url',
                                        }"
                                    />
                                </button>
                            </div>
                            <div>
                                <div class="mb-1 text-xs text-slate-500">
                                    Verify token
                                </div>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2 rounded-lg bg-slate-800 px-3 py-2 text-left font-mono text-xs text-slate-200"
                                    title="Copiar"
                                    @click="
                                        copyMeta(
                                            'token',
                                            metaConfig.verify_token,
                                        )
                                    "
                                >
                                    <span class="min-w-0 flex-1 truncate">{{
                                        metaConfig.verify_token
                                    }}</span>
                                    <Lucide
                                        :icon="
                                            copiedField === 'token'
                                                ? 'Check'
                                                : 'Copy'
                                        "
                                        class="h-3.5 w-3.5 shrink-0"
                                        :class="{
                                            'text-success':
                                                copiedField === 'token',
                                        }"
                                    />
                                </button>
                            </div>
                        </div>
                        <div
                            class="mt-4 flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                            />
                            <span>
                                Pega ambos en Webhooks de tu app de Meta y
                                suscribe el campo "messages". En entorno de
                                prueba usa el número de prueba de WhatsApp Cloud
                                API (gratis, hasta 5 destinos verificados).
                                <span
                                    v-if="!metaConfig.app_configured"
                                    class="font-medium text-warning"
                                    >Falta META_APP_ID/SECRET en el .env — la
                                    firma no se valida.</span
                                >
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Canales del hotel -->
                <div class="col-span-12">
                    <div class="box box--stacked flex flex-col">
                        <div
                            class="flex flex-wrap items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                        >
                            <Lucide
                                icon="MessagesSquare"
                                class="h-4 w-4 stroke-[1.5] text-primary"
                            />
                            <h2 class="text-base font-medium">
                                Canales conectados
                            </h2>
                            <span
                                class="ml-auto rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                            >
                                {{ channelCount() }}
                                {{ channelCount() === 1 ? 'canal' : 'canales' }}
                            </span>
                        </div>

                        <div
                            v-if="channelCount()"
                            class="divide-y divide-dashed divide-slate-300/70 px-5 py-2"
                        >
                            <!-- Canales Meta -->
                            <div
                                v-for="link in meta"
                                :key="`meta-${link.id}`"
                                class="flex items-center gap-3 py-3"
                                :class="{ 'opacity-60': !link.active }"
                            >
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                                    :class="
                                        metaTypeMeta[link.type]?.tone ??
                                        'bg-slate-100 text-slate-500'
                                    "
                                >
                                    <Lucide
                                        :icon="
                                            (metaTypeMeta[link.type]
                                                ?.icon as any) ??
                                            'MessageCircle'
                                        "
                                        class="h-4 w-4"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="truncate text-sm font-medium"
                                            >{{
                                                link.name || link.type_label
                                            }}</span
                                        >
                                        <span
                                            class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium"
                                            :class="
                                                link.active
                                                    ? 'bg-success/10 text-success'
                                                    : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                            "
                                        >
                                            {{
                                                link.active
                                                    ? 'Activo'
                                                    : 'Pausado'
                                            }}
                                        </span>
                                    </div>
                                    <div
                                        class="mt-0.5 flex items-center gap-2 font-mono text-[10px] text-slate-400"
                                    >
                                        <span class="truncate">{{
                                            link.external_id
                                        }}</span>
                                        <span>{{ link.masked_token }}</span>
                                    </div>
                                    <div
                                        class="mt-0.5 flex items-center gap-1 text-[10px]"
                                        :class="
                                            link.last_event_at
                                                ? 'text-slate-500'
                                                : 'text-slate-400'
                                        "
                                    >
                                        <Lucide
                                            icon="Activity"
                                            class="h-3 w-3 shrink-0"
                                        />
                                        <span>{{
                                            link.last_event_at
                                                ? `Último evento hace ${link.last_event_at}`
                                                : 'Sin eventos recibidos'
                                        }}</span>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    title="Editar canal"
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                                    @click="openMetaEdit(link)"
                                >
                                    <Lucide icon="Pencil" class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    title="Diagnosticar"
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                                    @click="openDiagnose(link)"
                                >
                                    <Lucide
                                        icon="Stethoscope"
                                        class="h-4 w-4"
                                    />
                                </button>
                                <button
                                    type="button"
                                    title="Reparar suscripción"
                                    :disabled="resubscribing === link.id"
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-primary/10 hover:text-primary disabled:pointer-events-none disabled:opacity-50"
                                    @click="resubscribe(link)"
                                >
                                    <Lucide
                                        :icon="
                                            resubscribing === link.id
                                                ? 'RefreshCw'
                                                : 'Wrench'
                                        "
                                        class="h-4 w-4"
                                        :class="{
                                            'animate-spin':
                                                resubscribing === link.id,
                                        }"
                                    />
                                </button>
                                <FormSwitch
                                    class="shrink-0"
                                    title="Activar o pausar"
                                >
                                    <FormSwitch.Input
                                        :checked="link.active"
                                        type="checkbox"
                                        @change="toggleMetaLink(link)"
                                    />
                                </FormSwitch>
                                <button
                                    type="button"
                                    title="Desvincular"
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                    @click="deleteMetaLink(link)"
                                >
                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                </button>
                            </div>

                            <!-- Canales Evolution (los gestiona el hotel) -->
                            <div
                                v-for="evo in evolution"
                                :key="`evo-${evo.id}`"
                                class="flex items-center gap-3 py-3"
                                :class="{ 'opacity-60': !evo.active }"
                                title="Conectada por el hotel en su panel /asistente"
                            >
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                                >
                                    <Lucide
                                        icon="MessageCircle"
                                        class="h-4 w-4"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="truncate text-sm font-medium"
                                            >{{
                                                evo.name ||
                                                `WhatsApp ${evo.instance}`
                                            }}</span
                                        >
                                        <span
                                            class="shrink-0 rounded-full bg-info/10 px-2 py-0.5 text-[10px] font-medium text-info"
                                            >Evolution</span
                                        >
                                        <span
                                            class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium"
                                            :class="
                                                evo.active
                                                    ? 'bg-success/10 text-success'
                                                    : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                            "
                                        >
                                            {{
                                                evo.active
                                                    ? 'Activo'
                                                    : 'Pausado'
                                            }}
                                        </span>
                                    </div>
                                    <div
                                        class="mt-0.5 flex items-center gap-2 font-mono text-[10px] text-slate-400"
                                    >
                                        <span class="truncate">{{
                                            evo.base_url
                                        }}</span>
                                        <span>{{ evo.instance }}</span>
                                    </div>
                                    <div
                                        class="mt-0.5 flex items-center gap-1 text-[10px]"
                                        :class="
                                            evo.last_event_at
                                                ? 'text-slate-500'
                                                : 'text-slate-400'
                                        "
                                    >
                                        <Lucide
                                            icon="Activity"
                                            class="h-3 w-3 shrink-0"
                                        />
                                        <span>{{
                                            evo.last_event_at
                                                ? `Último evento hace ${evo.last_event_at}`
                                                : 'Sin eventos recibidos'
                                        }}</span>
                                    </div>
                                </div>
                                <span
                                    class="flex items-center gap-1 text-xs text-slate-400"
                                    title="Conectada por el hotel en su panel /asistente"
                                >
                                    <Lucide icon="Info" class="h-3.5 w-3.5" />
                                    Gestionada por el hotel
                                </span>
                            </div>
                        </div>

                        <div
                            v-else
                            class="flex flex-col items-center gap-3 px-5 py-10 text-center"
                        >
                            <Lucide
                                icon="MessageSquareDashed"
                                class="h-8 w-8 text-slate-300"
                            />
                            <span class="text-sm text-slate-400"
                                >Este hotel no tiene canales conectados
                                todavía.</span
                            >
                            <Button
                                variant="outline-primary"
                                size="sm"
                                class="rounded-[0.5rem] bg-white"
                                @click="openMetaForm()"
                            >
                                <Lucide
                                    icon="Plus"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Vincular canal Meta
                            </Button>
                            <p class="text-xs text-slate-400">
                                Los canales de WhatsApp por Evolution los
                                conecta el hotel desde su propio panel.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal vincular canal Meta -->
        <Dialog :open="showMetaForm" @close="showMetaForm = false">
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="submitMetaLink">
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full"
                            :class="
                                metaTypeMeta[metaForm.type]?.tone ??
                                'bg-primary/10 text-primary'
                            "
                        >
                            <Lucide
                                :icon="
                                    (metaTypeMeta[metaForm.type]
                                        ?.icon as any) ?? 'Share2'
                                "
                                class="h-5 w-5"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                Vincular canal a {{ tenant.name }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Los mensajes de este canal entrarán a la bandeja
                                de este hotel
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showMetaForm = false"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>
                    <div class="space-y-4 px-6 py-5">
                        <div>
                            <label class="mb-1 block text-sm">Canal</label>
                            <FormSelect v-model="metaForm.type">
                                <option value="whatsapp">WhatsApp</option>
                                <option value="messenger">Messenger</option>
                                <option value="instagram">Instagram</option>
                            </FormSelect>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">{{
                                metaTypeMeta[metaForm.type]?.idLabel ??
                                'ID externo'
                            }}</label>
                            <FormInput
                                v-model="metaForm.external_id"
                                type="text"
                                class="font-mono"
                                placeholder="1055XXXXXXXXXXX"
                            />
                            <p class="mt-1 text-xs text-slate-400">
                                WhatsApp: el phone_number_id del panel de la
                                app. Messenger: el id de la página. Instagram:
                                el id de la CUENTA profesional (no el de la
                                página).
                            </p>
                        </div>
                        <div v-if="metaForm.type !== 'messenger'">
                            <label class="mb-1 block text-sm">{{
                                metaForm.type === 'instagram'
                                    ? 'Page ID de la página de Facebook vinculada'
                                    : 'WhatsApp Business Account ID (opcional)'
                            }}</label>
                            <FormInput
                                v-model="metaForm.waba_id"
                                type="text"
                                class="font-mono"
                                placeholder="1042XXXXXXXXXXX"
                            />
                            <FormHelp>
                                {{
                                    metaForm.type === 'instagram'
                                        ? 'La suscripción del webhook vive en la página de Facebook vinculada a la cuenta de Instagram; sin este id no se puede diagnosticar ni reparar.'
                                        : 'Necesario para diagnosticar y reparar la suscripción del webhook.'
                                }}
                            </FormHelp>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm"
                                >Access token</label
                            >
                            <FormInput
                                v-model="metaForm.access_token"
                                type="password"
                                class="font-mono"
                                placeholder="EAAG…"
                                autocomplete="off"
                            />
                            <p class="mt-1 text-xs text-slate-400">
                                Se guarda cifrado. En prueba sirve el token
                                temporal (24 h) del panel de Meta.
                            </p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm"
                                >Etiqueta (opcional)</label
                            >
                            <FormInput
                                v-model="metaForm.name"
                                type="text"
                                placeholder="WhatsApp prueba"
                            />
                        </div>
                        <p
                            v-if="metaError"
                            class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                        >
                            {{ metaError }}
                        </p>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="showMetaForm = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="shadow-md shadow-primary/20"
                            :disabled="
                                saving ||
                                !metaForm.external_id ||
                                !metaForm.access_token
                            "
                        >
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{ saving ? 'Vinculando…' : 'Vincular' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal editar canal Meta -->
        <Dialog
            :open="editingMetaLink !== null"
            @close="editingMetaLink = null"
        >
            <Dialog.Panel>
                <form
                    v-if="editingMetaLink"
                    class="flex flex-col"
                    @submit.prevent="submitMetaEdit"
                >
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full"
                            :class="
                                metaTypeMeta[editingMetaLink.type]?.tone ??
                                'bg-primary/10 text-primary'
                            "
                        >
                            <Lucide icon="Pencil" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">Editar canal</h2>
                            <p
                                class="mt-0.5 truncate font-mono text-xs text-slate-500"
                            >
                                {{
                                    editingMetaLink.name ||
                                    editingMetaLink.type_label
                                }}
                                · {{ editingMetaLink.external_id }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="editingMetaLink = null"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>
                    <div
                        class="max-h-[85vh] space-y-4 overflow-y-auto px-6 py-5"
                    >
                        <div>
                            <label class="mb-1 block text-sm">{{
                                metaTypeMeta[editingMetaLink.type]?.idLabel ??
                                'ID externo'
                            }}</label>
                            <FormInput
                                v-model="metaEditForm.external_id"
                                type="text"
                                class="font-mono"
                            />
                            <FormHelp>
                                {{
                                    editingMetaLink.type === 'instagram'
                                        ? 'El id de la CUENTA profesional (el que sale junto a la foto de perfil en el panel de Meta), no el de la app.'
                                        : 'Corrígelo aquí si quedó mal capturado; no hace falta borrar el canal.'
                                }}
                            </FormHelp>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm"
                                >Nombre (opcional)</label
                            >
                            <FormInput
                                v-model="metaEditForm.name"
                                type="text"
                                placeholder="WhatsApp prueba"
                            />
                        </div>
                        <div v-if="editingMetaLink.type !== 'messenger'">
                            <label class="mb-1 block text-sm">{{
                                editingMetaLink.type === 'instagram'
                                    ? 'Page ID de la página de Facebook vinculada (solo ruta vía página)'
                                    : 'WhatsApp Business Account ID'
                            }}</label>
                            <FormInput
                                v-model="metaEditForm.waba_id"
                                type="text"
                                class="font-mono"
                                placeholder="1042XXXXXXXXXXX"
                            />
                            <FormHelp>
                                {{
                                    editingMetaLink.type === 'instagram'
                                        ? 'Déjalo VACÍO si el canal usa token IGAA (Instagram Login): esa ruta no pasa por ninguna página.'
                                        : 'Necesario para diagnosticar y reparar la suscripción.'
                                }}
                            </FormHelp>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm"
                                >Identificador de acceso (token)</label
                            >
                            <FormInput
                                v-model="metaEditForm.access_token"
                                type="password"
                                class="font-mono"
                                placeholder="Dejar vacío para conservar el actual"
                                autocomplete="off"
                            />
                            <FormHelp
                                >El token temporal de Meta caduca (~24 h): pega
                                aquí el nuevo cuando la salida falle con error
                                de autenticación.</FormHelp
                            >
                        </div>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="editingMetaLink = null"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="shadow-md shadow-primary/20"
                            :disabled="saving"
                        >
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{ saving ? 'Guardando…' : 'Guardar' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal diagnóstico de canal -->
        <Dialog :open="diagnosingLink !== null" @close="diagnosingLink = null">
            <Dialog.Panel>
                <div class="flex flex-col">
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                        >
                            <Lucide icon="Stethoscope" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                Diagnóstico del canal
                            </h2>
                            <p
                                class="mt-0.5 truncate font-mono text-xs text-slate-500"
                            >
                                {{
                                    diagnosingLink?.name ||
                                    diagnosingLink?.type_label
                                }}
                                · {{ diagnosingLink?.external_id }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="diagnosingLink = null"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>
                    <div class="max-h-[85vh] overflow-y-auto px-6 py-5">
                        <div
                            v-if="diagnoseLoading"
                            class="flex items-center justify-center gap-2 py-10 text-sm text-slate-500"
                        >
                            <Lucide
                                icon="RefreshCw"
                                class="h-4 w-4 animate-spin"
                            />
                            Consultando a Meta…
                        </div>
                        <p
                            v-else-if="diagnoseError"
                            class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                        >
                            {{ diagnoseError }}
                        </p>
                        <div
                            v-else-if="diagnoseResult"
                            class="space-y-4 text-sm"
                        >
                            <div class="flex items-start gap-2.5">
                                <Lucide
                                    :icon="
                                        diagnoseResult.token_ok
                                            ? 'CircleCheck'
                                            : 'CircleAlert'
                                    "
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                    :class="
                                        diagnoseResult.token_ok
                                            ? 'text-success'
                                            : 'text-danger'
                                    "
                                />
                                <div class="min-w-0">
                                    <div
                                        class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                                    >
                                        Token
                                    </div>
                                    <div class="mt-0.5">
                                        {{
                                            diagnoseResult.token_ok
                                                ? 'Token vigente'
                                                : 'Token inválido o caducado — genera uno nuevo en Meta y edita el canal'
                                        }}
                                    </div>
                                </div>
                            </div>
                            <div
                                v-if="diagnosingLink?.type === 'whatsapp'"
                                class="flex items-start gap-2.5"
                            >
                                <Lucide
                                    :icon="
                                        diagnoseResult.phone
                                            ? 'CircleCheck'
                                            : 'CircleAlert'
                                    "
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                    :class="
                                        diagnoseResult.phone
                                            ? 'text-success'
                                            : 'text-warning'
                                    "
                                />
                                <div class="min-w-0">
                                    <div
                                        class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                                    >
                                        Número
                                    </div>
                                    <div
                                        class="mt-0.5 flex flex-wrap items-center gap-2"
                                    >
                                        <span>{{
                                            diagnoseResult.phone ??
                                            'Sin información'
                                        }}</span>
                                        <span
                                            v-if="diagnoseResult.quality"
                                            class="rounded-full px-2 py-0.5 text-[10px] font-medium"
                                            :class="
                                                qualityMeta[
                                                    diagnoseResult.quality
                                                ]?.tone ??
                                                'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                            "
                                        >
                                            {{
                                                qualityMeta[
                                                    diagnoseResult.quality
                                                ]?.label ??
                                                diagnoseResult.quality
                                            }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="flex items-start gap-2.5">
                                <Lucide
                                    :icon="
                                        diagnoseResult.identity
                                            ? 'CircleCheck'
                                            : 'CircleAlert'
                                    "
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                    :class="
                                        diagnoseResult.identity
                                            ? 'text-success'
                                            : 'text-warning'
                                    "
                                />
                                <div class="min-w-0">
                                    <div
                                        class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                                    >
                                        {{
                                            diagnosingLink?.type === 'instagram'
                                                ? 'Cuenta de Instagram'
                                                : 'Página'
                                        }}
                                    </div>
                                    <div class="mt-0.5">
                                        {{
                                            diagnoseResult.identity ??
                                            'Sin información (revisa el token y el ID)'
                                        }}
                                    </div>
                                </div>
                            </div>
                            <div
                                v-if="diagnosingLink?.type === 'whatsapp'"
                                class="flex items-start gap-2.5"
                            >
                                <Lucide
                                    :icon="
                                        diagnoseResult.callback_ok
                                            ? 'CircleCheck'
                                            : 'CircleAlert'
                                    "
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                    :class="
                                        diagnoseResult.callback_ok
                                            ? 'text-success'
                                            : diagnoseResult.callback_ok ===
                                                false
                                              ? 'text-danger'
                                              : 'text-warning'
                                    "
                                />
                                <div class="min-w-0">
                                    <div
                                        class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                                    >
                                        Callback
                                    </div>
                                    <div class="mt-0.5">
                                        <template
                                            v-if="
                                                diagnoseResult.callback_ok ===
                                                true
                                            "
                                            >URL de webhook correcta</template
                                        >
                                        <template
                                            v-else-if="
                                                diagnoseResult.callback_ok ===
                                                false
                                            "
                                        >
                                            La URL registrada en Meta NO es la
                                            de la plataforma
                                            <span
                                                v-if="
                                                    diagnoseResult.callback_url
                                                "
                                                class="mt-1 block rounded bg-slate-100 px-2 py-1 font-mono text-xs break-all text-slate-500 dark:bg-darkmode-400"
                                                >{{
                                                    diagnoseResult.callback_url
                                                }}</span
                                            >
                                        </template>
                                        <template v-else
                                            >Sin información</template
                                        >
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-start gap-2.5">
                                <Lucide
                                    :icon="
                                        diagnoseResult.subscribed?.length
                                            ? 'CircleCheck'
                                            : 'CircleAlert'
                                    "
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                    :class="
                                        diagnoseResult.subscribed === null
                                            ? 'text-warning'
                                            : diagnoseResult.subscribed.length
                                              ? 'text-success'
                                              : 'text-danger'
                                    "
                                />
                                <div class="min-w-0">
                                    <div
                                        class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                                    >
                                        Suscripción
                                    </div>
                                    <div class="mt-0.5">
                                        <template
                                            v-if="
                                                diagnoseResult.subscribed ===
                                                null
                                            "
                                        >
                                            {{
                                                diagnosingLink?.type ===
                                                'instagram'
                                                    ? 'Captura el page_id de la página vinculada (campo WABA/Página) para verificar la suscripción'
                                                    : 'Captura el WABA ID para verificar la suscripción'
                                            }}
                                        </template>
                                        <div
                                            v-else-if="
                                                diagnoseResult.subscribed.length
                                            "
                                            class="space-y-1.5"
                                        >
                                            <div class="flex flex-wrap gap-1.5">
                                                <span
                                                    v-for="app in diagnoseResult.subscribed"
                                                    :key="app"
                                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                                                    >{{ app }}</span
                                                >
                                            </div>
                                            <div
                                                v-if="
                                                    diagnoseResult
                                                        .subscribed_fields
                                                        ?.length
                                                "
                                                class="flex flex-wrap items-center gap-1.5"
                                            >
                                                <span
                                                    class="text-xs text-slate-400"
                                                    >Campos:</span
                                                >
                                                <span
                                                    v-for="field in diagnoseResult.subscribed_fields"
                                                    :key="field"
                                                    class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary"
                                                    >{{ field }}</span
                                                >
                                                <span
                                                    v-if="
                                                        !diagnoseResult.subscribed_fields.includes(
                                                            'messages',
                                                        )
                                                    "
                                                    class="text-xs text-danger"
                                                    >Falta el campo messages:
                                                    usa Reparar
                                                    suscripción</span
                                                >
                                            </div>
                                        </div>
                                        <span v-else class="text-danger"
                                            >Ninguna app suscrita a la
                                            cuenta/página: usa Reparar
                                            suscripción</span
                                        >
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-start gap-2.5">
                                <Lucide
                                    :icon="
                                        diagnoseResult.last_event_at
                                            ? 'CircleCheck'
                                            : 'CircleAlert'
                                    "
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                    :class="
                                        diagnoseResult.last_event_at
                                            ? 'text-success'
                                            : 'text-warning'
                                    "
                                />
                                <div class="min-w-0">
                                    <div
                                        class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                                    >
                                        Último evento
                                    </div>
                                    <div class="mt-0.5">
                                        {{
                                            diagnoseResult.last_event_at
                                                ? `Hace ${diagnoseResult.last_event_at}`
                                                : 'Nunca'
                                        }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="diagnosingLink = null"
                            >Cerrar</Button
                        >
                        <Button
                            v-if="
                                diagnosingLink?.waba_id ||
                                diagnosingLink?.type === 'messenger'
                            "
                            type="button"
                            variant="primary"
                            class="shadow-md shadow-primary/20"
                            :disabled="
                                resubscribing !== null || diagnoseLoading
                            "
                            @click="
                                diagnosingLink && resubscribe(diagnosingLink)
                            "
                        >
                            <Lucide icon="Wrench" class="mr-2 h-4 w-4" />
                            {{
                                resubscribing !== null
                                    ? 'Reparando…'
                                    : 'Reparar suscripción'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
