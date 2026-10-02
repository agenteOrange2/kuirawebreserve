<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
} from '@/components/Base/Form';
import { Dialog, Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import TenantHeader from './TenantHeader.vue';
import type { PlanOption, TenantShell } from './types';

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
interface TelegramRow {
    id: number;
    name: string | null;
    bot_username: string | null;
    masked_token: string;
    webhook_url: string;
    active: boolean;
    last_event_at: string | null;
}
interface TiktokRow {
    id: number;
    name: string | null;
    business_id: string;
    masked_token: string;
    webhook_url: string;
    active: boolean;
    last_event_at: string | null;
}
interface MetaAppInfo {
    app_id: string;
    name: string | null;
    masked_app_secret: string;
    masked_ig_app_secret: string;
    login_config_id: string | null;
    updated_at: string | null;
}
const props = defineProps<{
    tenant: TenantShell;
    plans: PlanOption[];
    meta: MetaLinkRow[];
    evolution: EvoRow[];
    telegram: TelegramRow[];
    tiktok: TiktokRow[];
    metaConfig: {
        mode: string;
        webhook_url: string;
        verify_token: string;
        app_configured: boolean;
    };
    metaApp: MetaAppInfo | null;
    platformAppId: string;
    /** Catálogo de canales que la plataforma puede habilitar por hotel. */
    channelCatalog: { key: string; label: string }[];
    channelsAllowed: string[];
    /** Canales conectados contra el tope del plan (el admin puede pasarse). */
    channelLimit?: { max: number | null; used: number };
}>();

const toast = useToasts();
const saving = ref(false);

// ── Qué canales puede conectar el hotel desde SU panel ──
// El panel del hotel (/asistente) ofrecía los cuatro a todos; aquí se
// decide cuáles ve. Un canal ya conectado sigue visible aunque se apague.
const allowedChannels = ref<string[]>([...props.channelsAllowed]);
const savingChannels = ref(false);

async function toggleAllowedChannel(key: string) {
    const next = allowedChannels.value.includes(key)
        ? allowedChannels.value.filter((c) => c !== key)
        : [...allowedChannels.value, key];

    const previous = allowedChannels.value;
    allowedChannels.value = next;
    savingChannels.value = true;

    try {
        await axios.patch(route('admin.ai.tenants.update', props.tenant.id), {
            channels_allowed: next,
        });
        toast.success(
            'Canales actualizados',
            'El hotel verá en su panel solo los canales habilitados.',
        );
    } catch {
        allowedChannels.value = previous;
        toast.error('Error', 'No se pudo guardar la lista de canales.');
    } finally {
        savingChannels.value = false;
    }
}

// ── App de Meta propia del hotel (separación de apps por tenant) ──
const showAppForm = ref(false);
const savingApp = ref(false);
const confirmAppRemoval = ref(false);
const appError = ref<string | null>(null);
const appForm = reactive({
    app_id: '',
    app_secret: '',
    ig_app_secret: '',
    login_config_id: '',
    name: '',
});

function openAppForm() {
    appForm.app_id = props.metaApp?.app_id ?? '';
    appForm.app_secret = '';
    appForm.ig_app_secret = '';
    appForm.login_config_id = props.metaApp?.login_config_id ?? '';
    appForm.name = props.metaApp?.name ?? '';
    appError.value = null;
    showAppForm.value = true;
}

async function submitApp() {
    savingApp.value = true;
    appError.value = null;
    try {
        await axios.put(
            route('admin.tenants.meta-app.update', props.tenant.id),
            {
                app_id: appForm.app_id,
                app_secret: appForm.app_secret || null,
                ig_app_secret: appForm.ig_app_secret || null,
                login_config_id: appForm.login_config_id || null,
                name: appForm.name || null,
            },
        );
        toast.success(
            'App de Meta guardada',
            'Este hotel ahora firma y canjea con su propia app.',
        );
        showAppForm.value = false;
        router.reload({ only: ['metaApp'] });
    } catch (e: any) {
        const data = e.response?.data;
        const firstError = data?.errors
            ? (Object.values(data.errors)[0] as string[])?.[0]
            : null;
        appError.value = data?.message ?? firstError ?? 'No se pudo guardar.';
    } finally {
        savingApp.value = false;
    }
}

async function removeApp() {
    savingApp.value = true;
    try {
        await axios.delete(
            route('admin.tenants.meta-app.destroy', props.tenant.id),
        );
        toast.success(
            'App propia desconectada',
            'El hotel vuelve a usar la app de la plataforma.',
        );
        confirmAppRemoval.value = false;
        router.reload({ only: ['metaApp'] });
    } catch (e: any) {
        toast.error(
            'No se pudo desconectar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        savingApp.value = false;
    }
}

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

const channelCount = () =>
    props.meta.length +
    props.evolution.length +
    props.telegram.length +
    props.tiktok.length;

// Recarga solo los datos de canales del hotel tras una mutación.
const reloadChannels = () =>
    router.reload({ only: ['meta', 'evolution', 'telegram', 'tiktok'] });

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
                    | string[]
                    | undefined
            )?.[0] ??
            'No se pudo vincular.';
    } finally {
        saving.value = false;
    }
}

const copiedField = ref<string | null>(null);
async function copyMeta(field: string, value: string) {
    try {
        await navigator.clipboard.writeText(value);
        copiedField.value = field;
        setTimeout(() => (copiedField.value = null), 2000);
    } catch {
        toast.error(
            'No se pudo copiar',
            'El navegador bloqueó el portapapeles.',
        );
    }
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
                        | string[]
                        | undefined
                )?.[0] ??
                'Ocurrió un error.',
        );
    } finally {
        saving.value = false;
    }
}

// ── Telegram: conectar/editar bot (token de BotFather) ──
const showTelegramForm = ref(false);
const telegramError = ref<string | null>(null);
const editingTelegram = ref<TelegramRow | null>(null);
const telegramForm = reactive({ name: '', bot_token: '' });

function openTelegramForm(link: TelegramRow | null = null) {
    editingTelegram.value = link;
    telegramForm.name = link?.name ?? '';
    telegramForm.bot_token = '';
    telegramError.value = null;
    showTelegramForm.value = true;
}

async function submitTelegram() {
    saving.value = true;
    telegramError.value = null;
    try {
        if (editingTelegram.value) {
            await axios.patch(
                route('admin.telegram.update', editingTelegram.value.id),
                {
                    name: telegramForm.name || null,
                    // Vacío = conservar el token actual.
                    bot_token: telegramForm.bot_token || null,
                },
            );
            toast.success('Canal actualizado');
        } else {
            const { data } = await axios.post(route('admin.telegram.store'), {
                tenant_id: props.tenant.id,
                name: telegramForm.name || null,
                bot_token: telegramForm.bot_token,
            });
            toast.success(
                'Telegram conectado',
                data.webhook_configured
                    ? 'El webhook del bot quedó registrado; los mensajes ya entran a la bandeja.'
                    : 'El bot es válido pero el webhook no se pudo registrar. Usa Probar conexión para reintentar.',
            );
        }
        showTelegramForm.value = false;
        reloadChannels();
    } catch (e: any) {
        telegramError.value =
            e.response?.data?.message ??
            (
                Object.values(e.response?.data?.errors ?? {})[0] as
                    | string[]
                    | undefined
            )?.[0] ??
            'No se pudo guardar.';
    } finally {
        saving.value = false;
    }
}

const testingChannel = ref<string | null>(null);
async function testTelegram(link: TelegramRow) {
    testingChannel.value = `telegram-${link.id}`;
    try {
        const { data } = await axios.post(
            route('admin.telegram.test', link.id),
        );
        if (data.connection?.ok) {
            toast.success(
                'Bot conectado',
                `@${data.connection.username ?? link.bot_username ?? ''}` +
                    (data.webhook_configured
                        ? ' — webhook registrado.'
                        : ' — el webhook no se pudo registrar.'),
            );
        } else {
            toast.error(
                'Token rechazado',
                'Telegram no reconoce el token del bot; captura uno nuevo.',
            );
        }
        reloadChannels();
    } catch (e: any) {
        toast.error(
            'No se pudo probar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        testingChannel.value = null;
    }
}

// ── TikTok: conectar/editar cuenta (Business Messaging API) ──
const showTiktokForm = ref(false);
const tiktokError = ref<string | null>(null);
const editingTiktok = ref<TiktokRow | null>(null);
const tiktokForm = reactive({ name: '', business_id: '', access_token: '' });

function openTiktokForm(link: TiktokRow | null = null) {
    editingTiktok.value = link;
    tiktokForm.name = link?.name ?? '';
    tiktokForm.business_id = link?.business_id ?? '';
    tiktokForm.access_token = '';
    tiktokError.value = null;
    showTiktokForm.value = true;
}

async function submitTiktok() {
    saving.value = true;
    tiktokError.value = null;
    try {
        if (editingTiktok.value) {
            await axios.patch(
                route('admin.tiktok.update', editingTiktok.value.id),
                {
                    name: tiktokForm.name || null,
                    business_id: tiktokForm.business_id,
                    // Vacío = conservar el token actual.
                    access_token: tiktokForm.access_token || null,
                },
            );
            toast.success('Canal actualizado');
        } else {
            await axios.post(route('admin.tiktok.store'), {
                tenant_id: props.tenant.id,
                name: tiktokForm.name || null,
                business_id: tiktokForm.business_id,
                access_token: tiktokForm.access_token,
            });
            toast.success(
                'TikTok vinculado',
                'Pega la URL del webhook del canal en el panel de la app de TikTok para recibir mensajes.',
            );
        }
        showTiktokForm.value = false;
        reloadChannels();
    } catch (e: any) {
        tiktokError.value =
            e.response?.data?.message ??
            (
                Object.values(e.response?.data?.errors ?? {})[0] as
                    | string[]
                    | undefined
            )?.[0] ??
            'No se pudo guardar.';
    } finally {
        saving.value = false;
    }
}

async function testTiktok(link: TiktokRow) {
    testingChannel.value = `tiktok-${link.id}`;
    try {
        const { data } = await axios.post(route('admin.tiktok.test', link.id));
        if (data.connection?.ok) {
            toast.success(
                'Cuenta conectada',
                data.connection.name ?? link.business_id,
            );
        } else {
            toast.error(
                'TikTok rechazó el token',
                'Revisa el access token y que la app tenga el permiso de mensajería.',
            );
        }
    } catch (e: any) {
        toast.error(
            'No se pudo probar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        testingChannel.value = null;
    }
}
// ── Un solo listado para los cuatro tipos de canal ──
type ChannelKind = 'meta' | 'evolution' | 'telegram' | 'tiktok';
interface ChannelRow {
    key: string;
    kind: ChannelKind;
    id: number;
    title: string;
    badge: string;
    icon: Icon;
    tone: string;
    detail: string;
    active: boolean;
    last_event_at: string | null;
    // Telegram y TikTok: la URL que se pega del lado del proveedor.
    webhook_url?: string;
    raw: MetaLinkRow | EvoRow | TelegramRow | TiktokRow;
}

const ghostButton =
    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary';

const rows = computed<ChannelRow[]>(() => [
    ...props.meta.map((link) => ({
        key: `meta-${link.id}`,
        kind: 'meta' as const,
        id: link.id,
        title: link.name || link.type_label,
        badge: metaTypeMeta[link.type]?.label ?? link.type_label,
        icon: (metaTypeMeta[link.type]?.icon ?? 'MessageCircle') as Icon,
        tone: metaTypeMeta[link.type]?.tone ?? 'bg-primary/10 text-primary',
        detail: `${link.external_id} · ${link.masked_token}`,
        active: link.active,
        last_event_at: link.last_event_at,
        raw: link,
    })),
    ...props.evolution.map((evo) => ({
        key: `evo-${evo.id}`,
        kind: 'evolution' as const,
        id: evo.id,
        title: evo.name || `WhatsApp ${evo.instance}`,
        badge: 'Evolution',
        icon: 'MessageCircle' as Icon,
        tone: 'bg-success/10 text-success',
        detail: `${evo.base_url} · ${evo.instance}`,
        active: evo.active,
        last_event_at: evo.last_event_at,
        raw: evo,
    })),
    ...props.telegram.map((link) => ({
        key: `telegram-${link.id}`,
        kind: 'telegram' as const,
        id: link.id,
        title:
            link.name ||
            (link.bot_username ? `@${link.bot_username}` : 'Telegram'),
        badge: 'Telegram',
        icon: 'Send' as Icon,
        tone: 'bg-info/10 text-info',
        detail: [
            link.bot_username ? `@${link.bot_username}` : null,
            link.masked_token,
        ]
            .filter(Boolean)
            .join(' · '),
        active: link.active,
        last_event_at: link.last_event_at,
        webhook_url: link.webhook_url,
        raw: link,
    })),
    ...props.tiktok.map((link) => ({
        key: `tiktok-${link.id}`,
        kind: 'tiktok' as const,
        id: link.id,
        title: link.name || 'TikTok',
        badge: 'TikTok',
        icon: 'Music2' as Icon,
        tone: 'bg-dark/10 text-dark dark:text-slate-300',
        detail: `${link.business_id} · ${link.masked_token}`,
        active: link.active,
        last_event_at: link.last_event_at,
        webhook_url: link.webhook_url,
        raw: link,
    })),
]);

const activeCount = computed(() => rows.value.filter((r) => r.active).length);
// Activo pero nunca le ha llegado nada: casi siempre un webhook mal puesto.
const silentCount = computed(
    () => rows.value.filter((r) => r.active && !r.last_event_at).length,
);

function editRow(row: ChannelRow) {
    if (row.kind === 'meta') openMetaEdit(row.raw as MetaLinkRow);
    if (row.kind === 'telegram') openTelegramForm(row.raw as TelegramRow);
    if (row.kind === 'tiktok') openTiktokForm(row.raw as TiktokRow);
}

function testRow(row: ChannelRow) {
    if (row.kind === 'meta') openDiagnose(row.raw as MetaLinkRow);
    if (row.kind === 'telegram') testTelegram(row.raw as TelegramRow);
    if (row.kind === 'tiktok') testTiktok(row.raw as TiktokRow);
}

const channelRoutes: Record<
    Exclude<ChannelKind, 'evolution'>,
    { update: string; destroy: string }
> = {
    meta: { update: 'admin.meta.update', destroy: 'admin.meta.destroy' },
    telegram: {
        update: 'admin.telegram.update',
        destroy: 'admin.telegram.destroy',
    },
    tiktok: { update: 'admin.tiktok.update', destroy: 'admin.tiktok.destroy' },
};

// El interruptor no cambia solo (click.prevent): espera al servidor y la
// recarga lo pinta, así un rechazo nunca lo deja mintiendo.
const togglingKey = ref<string | null>(null);

async function toggleRow(row: ChannelRow) {
    if (row.kind === 'evolution' || togglingKey.value) return;
    togglingKey.value = row.key;
    try {
        await axios.patch(route(channelRoutes[row.kind].update, row.id), {
            active: !row.active,
        });
        toast.success(
            row.active ? 'Canal pausado' : 'Canal reactivado',
            row.title,
        );
        reloadChannels();
    } catch (e: any) {
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        togglingKey.value = null;
    }
}

// ── Desvincular: siempre con confirmación (antes era un clic directo) ──
const unlinking = ref<ChannelRow | null>(null);
const unlinkingBusy = ref(false);

async function confirmUnlink() {
    const row = unlinking.value;
    if (!row || row.kind === 'evolution') return;
    unlinkingBusy.value = true;
    try {
        await axios.delete(route(channelRoutes[row.kind].destroy, row.id));
        toast.success('Canal desvinculado', row.title);
        unlinking.value = null;
        reloadChannels();
    } catch (e: any) {
        toast.error(
            'No se pudo desvincular',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        unlinkingBusy.value = false;
    }
}
</script>

<template>
    <RazeLayout :title="`${tenant.name} · Canales`">
        <TenantHeader :tenant="tenant" :plans="plans" active="channels" />

        <!-- Lo que importa primero: qué canales tiene y si reciben -->
        <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
            <div
                class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                >
                    <Lucide icon="MessagesSquare" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <div class="text-sm font-medium">
                        {{ activeCount }} de {{ channelCount() }}
                    </div>
                    <div class="text-xs leading-tight text-slate-500">
                        Canales activos
                    </div>
                    <div
                        v-if="channelLimit && channelLimit.max !== null"
                        class="truncate text-[11px]"
                        :class="
                            channelLimit.used > channelLimit.max
                                ? 'font-medium text-warning'
                                : 'text-slate-400'
                        "
                    >
                        {{ channelLimit.used }} de {{ channelLimit.max }} del
                        plan
                    </div>
                </div>
            </div>
            <div
                class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                    :class="
                        silentCount
                            ? 'border-warning/10 bg-warning/10 text-warning'
                            : 'border-success/10 bg-success/10 text-success'
                    "
                >
                    <Lucide icon="Activity" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <div class="text-sm font-medium">{{ silentCount }}</div>
                    <div class="text-xs leading-tight text-slate-500">
                        Activos sin eventos
                    </div>
                    <div class="truncate text-[11px] text-slate-400">
                        Nunca han recibido nada
                    </div>
                </div>
            </div>
            <div
                class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                    :class="
                        metaConfig.mode === 'production'
                            ? 'border-success/10 bg-success/10 text-success'
                            : 'border-warning/10 bg-warning/10 text-warning'
                    "
                >
                    <Lucide icon="Webhook" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <div class="text-sm font-medium">
                        {{
                            metaConfig.mode === 'production'
                                ? 'Producción'
                                : 'Prueba'
                        }}
                    </div>
                    <div class="text-xs leading-tight text-slate-500">
                        Entorno de Meta
                    </div>
                </div>
            </div>
            <div
                class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                >
                    <Lucide icon="AppWindow" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <div class="truncate text-sm font-medium">
                        {{ metaApp ? 'Propia' : 'De la plataforma' }}
                    </div>
                    <div class="text-xs leading-tight text-slate-500">
                        App de Meta
                    </div>
                </div>
            </div>
        </div>

        <!-- Canales conectados -->
        <div class="box box--stacked mt-4">
            <div
                class="flex items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                >
                    <Lucide icon="MessagesSquare" class="h-4 w-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-sm font-medium">Canales conectados</h2>
                    <p class="text-xs text-slate-500">
                        Lo que llega por aquí entra a la bandeja del hotel.
                    </p>
                </div>
                <Menu>
                    <Menu.Button
                        :as="Button"
                        variant="primary"
                        class="h-9 shrink-0 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Vincular canal
                        <Lucide icon="ChevronDown" class="ml-1.5 h-3.5 w-3.5" />
                    </Menu.Button>
                    <Menu.Items class="w-56">
                        <Menu.Item
                            as="button"
                            type="button"
                            @click="openMetaForm()"
                        >
                            <Lucide icon="MessageCircle" class="mr-2 h-4 w-4" />
                            Canal Meta (WA, FB, IG)
                        </Menu.Item>
                        <Menu.Item
                            as="button"
                            type="button"
                            @click="openTelegramForm()"
                        >
                            <Lucide icon="Send" class="mr-2 h-4 w-4" />
                            Bot de Telegram
                        </Menu.Item>
                        <Menu.Item
                            as="button"
                            type="button"
                            @click="openTiktokForm()"
                        >
                            <Lucide icon="Music2" class="mr-2 h-4 w-4" />
                            Cuenta de TikTok
                        </Menu.Item>
                    </Menu.Items>
                </Menu>
            </div>

            <div
                v-if="channelCount()"
                class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
            >
                <div
                    v-for="row in rows"
                    :key="row.key"
                    class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:px-5"
                >
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                            :class="
                                row.active
                                    ? row.tone
                                    : 'bg-slate-100 text-slate-400 dark:bg-darkmode-400'
                            "
                        >
                            <Lucide :icon="row.icon" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="truncate text-sm font-medium">{{
                                    row.title
                                }}</span>
                                <span
                                    class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                    >{{ row.badge }}</span
                                >
                                <span
                                    class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        row.active
                                            ? 'bg-success/10 text-success'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full"
                                        :class="
                                            row.active
                                                ? 'bg-success'
                                                : 'bg-slate-400'
                                        "
                                    />
                                    {{ row.active ? 'Activo' : 'Pausado' }}
                                </span>
                            </div>
                            <div
                                class="mt-0.5 flex min-w-0 flex-wrap items-center gap-x-3 text-[11px] text-slate-400"
                            >
                                <span class="truncate font-mono">{{
                                    row.detail
                                }}</span>
                                <span
                                    class="inline-flex items-center gap-1"
                                    :class="
                                        row.last_event_at
                                            ? 'text-slate-500'
                                            : row.active
                                              ? 'text-warning'
                                              : ''
                                    "
                                >
                                    <Lucide icon="Activity" class="h-3 w-3" />
                                    {{
                                        row.last_event_at
                                            ? `Último evento hace ${row.last_event_at}`
                                            : 'Sin eventos recibidos'
                                    }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex items-center justify-end gap-0.5 pl-12 sm:pl-0"
                    >
                        <template v-if="row.kind === 'evolution'">
                            <span
                                class="inline-flex items-center gap-1 text-[11px] text-slate-400"
                                title="La conectó el hotel desde su panel de Asistente"
                            >
                                <Lucide icon="Info" class="h-3.5 w-3.5" />
                                La gestiona el hotel
                            </span>
                        </template>
                        <template v-else>
                            <button
                                v-if="row.webhook_url"
                                type="button"
                                :title="
                                    copiedField === row.key
                                        ? 'Copiado'
                                        : 'Copiar la URL del webhook'
                                "
                                :class="ghostButton"
                                @click="copyMeta(row.key, row.webhook_url)"
                            >
                                <Lucide
                                    :icon="
                                        copiedField === row.key
                                            ? 'Check'
                                            : 'Link'
                                    "
                                    class="h-4 w-4"
                                    :class="{
                                        'text-success': copiedField === row.key,
                                    }"
                                />
                            </button>
                            <button
                                type="button"
                                title="Editar canal"
                                :class="ghostButton"
                                @click="editRow(row)"
                            >
                                <Lucide icon="Pencil" class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                :title="
                                    row.kind === 'meta'
                                        ? 'Diagnosticar con Meta'
                                        : 'Probar conexión'
                                "
                                :class="ghostButton"
                                :disabled="testingChannel === row.key"
                                @click="testRow(row)"
                            >
                                <Lucide
                                    :icon="
                                        testingChannel === row.key
                                            ? 'RefreshCw'
                                            : 'Stethoscope'
                                    "
                                    class="h-4 w-4"
                                    :class="{
                                        'animate-spin':
                                            testingChannel === row.key,
                                    }"
                                />
                            </button>
                            <button
                                v-if="row.kind === 'meta'"
                                type="button"
                                title="Reparar la suscripción del webhook"
                                :class="ghostButton"
                                :disabled="resubscribing === row.id"
                                @click="resubscribe(row.raw as MetaLinkRow)"
                            >
                                <Lucide
                                    :icon="
                                        resubscribing === row.id
                                            ? 'RefreshCw'
                                            : 'Wrench'
                                    "
                                    class="h-4 w-4"
                                    :class="{
                                        'animate-spin':
                                            resubscribing === row.id,
                                    }"
                                />
                            </button>
                            <FormSwitch
                                class="mx-1.5 shrink-0"
                                :title="
                                    row.active
                                        ? 'Pausar: deja de recibir mensajes'
                                        : 'Reactivar'
                                "
                            >
                                <FormSwitch.Input
                                    :checked="row.active"
                                    type="checkbox"
                                    :disabled="togglingKey === row.key"
                                    @click.prevent="toggleRow(row)"
                                />
                            </FormSwitch>
                            <button
                                type="button"
                                title="Desvincular"
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                @click="unlinking = row"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <div
                v-else
                class="flex flex-col items-center gap-2 px-4 py-10 text-center"
            >
                <div
                    class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                >
                    <Lucide icon="MessageSquareDashed" class="h-4 w-4" />
                </div>
                <p class="text-xs text-slate-500">
                    Este hotel no tiene canales conectados todavía.
                </p>
                <p class="max-w-sm text-[11px] text-slate-400">
                    WhatsApp por Evolution lo conecta el hotel desde su propio
                    panel; Meta, Telegram y TikTok se vinculan desde aquí.
                </p>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-12 items-stretch gap-5">
            <!-- Qué puede conectar el hotel desde su panel -->
            <div class="col-span-12 flex flex-col xl:col-span-5">
                <div class="box box--stacked flex flex-1 flex-col">
                    <div
                        class="flex items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                        >
                            <Lucide icon="ToggleRight" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-medium">
                                Qué puede conectar el hotel
                            </h2>
                            <p class="text-xs text-slate-500">
                                Solo estos aparecen en su panel de Asistente.
                            </p>
                        </div>
                        <span
                            v-if="savingChannels"
                            class="text-[11px] text-slate-400"
                            >Guardando...</span
                        >
                    </div>
                    <div
                        class="flex-1 divide-y divide-slate-200/60 dark:divide-darkmode-400"
                    >
                        <div
                            v-for="channel in channelCatalog"
                            :key="channel.key"
                            class="flex items-center justify-between gap-3 px-4 py-2.5"
                        >
                            <span class="text-xs font-medium">{{
                                channel.label
                            }}</span>
                            <FormSwitch>
                                <FormSwitch.Input
                                    :checked="
                                        allowedChannels.includes(channel.key)
                                    "
                                    type="checkbox"
                                    :disabled="savingChannels"
                                    @click.prevent="
                                        toggleAllowedChannel(channel.key)
                                    "
                                />
                            </FormSwitch>
                        </div>
                    </div>
                    <p
                        class="border-t border-slate-200/60 px-4 py-2.5 text-[11px] text-slate-400 dark:border-darkmode-400"
                    >
                        Apagar uno no desconecta lo que el hotel ya tenga
                        funcionando.
                    </p>
                </div>
            </div>

            <!-- App de Meta y webhook -->
            <div class="col-span-12 flex flex-col xl:col-span-7">
                <div class="box box--stacked flex flex-1 flex-col">
                    <div
                        class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                        >
                            <Lucide icon="AppWindow" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-medium">
                                App de Meta y webhook
                            </h2>
                            <p class="text-xs text-slate-500">
                                {{
                                    metaApp
                                        ? 'Usa su app propia: firma y canjea por separado.'
                                        : 'Usa la app de la plataforma.'
                                }}
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <Button
                                v-if="metaApp"
                                variant="outline-secondary"
                                class="h-8 rounded-[0.5rem] text-xs"
                                @click="confirmAppRemoval = true"
                            >
                                Usar la de plataforma
                            </Button>
                            <Button
                                variant="outline-primary"
                                class="h-8 rounded-[0.5rem] text-xs"
                                @click="openAppForm"
                            >
                                <Lucide
                                    :icon="metaApp ? 'Pencil' : 'Plus'"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                {{ metaApp ? 'Editar app' : 'App propia' }}
                            </Button>
                        </div>
                    </div>
                    <div class="flex flex-1 flex-col gap-3 px-4 py-3 text-xs">
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg bg-slate-50 px-3 py-2 dark:bg-darkmode-600/40"
                        >
                            <template v-if="metaApp">
                                <span
                                    >App
                                    <span class="font-medium">{{
                                        metaApp.name || metaApp.app_id
                                    }}</span></span
                                >
                                <span class="font-mono text-slate-500"
                                    >ID {{ metaApp.app_id }}</span
                                >
                                <span class="font-mono text-slate-500"
                                    >clave {{ metaApp.masked_app_secret }}</span
                                >
                                <span
                                    v-if="metaApp.login_config_id"
                                    class="font-mono text-slate-500"
                                    >registro
                                    {{ metaApp.login_config_id }}</span
                                >
                            </template>
                            <span v-else class="text-slate-500"
                                >App de la plataforma
                                <span class="font-mono">{{
                                    platformAppId || 'sin configurar'
                                }}</span></span
                            >
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div
                                v-for="field in [
                                    {
                                        key: 'url',
                                        label: 'URL del webhook',
                                        value: metaConfig.webhook_url,
                                    },
                                    {
                                        key: 'token',
                                        label: 'Verify token',
                                        value: metaConfig.verify_token,
                                    },
                                ]"
                                :key="field.key"
                                class="min-w-0"
                            >
                                <div class="mb-1 text-[11px] text-slate-500">
                                    {{ field.label }}
                                </div>
                                <button
                                    type="button"
                                    class="flex h-9 w-full items-center gap-2 rounded-[0.5rem] bg-slate-800 px-3 text-left font-mono text-[11px] text-slate-200 transition hover:bg-slate-700"
                                    title="Copiar"
                                    @click="copyMeta(field.key, field.value)"
                                >
                                    <span class="min-w-0 flex-1 truncate">{{
                                        field.value
                                    }}</span>
                                    <Lucide
                                        :icon="
                                            copiedField === field.key
                                                ? 'Check'
                                                : 'Copy'
                                        "
                                        class="h-3.5 w-3.5 shrink-0"
                                        :class="{
                                            'text-success':
                                                copiedField === field.key,
                                        }"
                                    />
                                </button>
                            </div>
                        </div>
                        <p class="mt-auto text-[11px] text-slate-400">
                            Pega los dos en Webhooks de la app de Meta y
                            suscribe el campo "messages". Es la misma URL para
                            la app propia y la de la plataforma.
                            <span
                                v-if="!metaConfig.app_configured"
                                class="font-medium text-warning"
                                >Falta META_APP_ID y SECRET en el .env: la firma
                                no se valida.</span
                            >
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <!-- Modal vincular canal Meta -->
        <Dialog :open="showMetaForm" @close="showMetaForm = false">
            <Dialog.Panel>
                <form
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                    @submit.prevent="submitMetaLink"
                >
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
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
                                class="h-4 w-4"
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
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>
                    <div
                        class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4"
                    >
                        <div>
                            <label class="mb-1.5 block text-xs font-medium"
                                >Canal</label
                            >
                            <FormSelect
                                class="h-9 text-xs"
                                v-model="metaForm.type"
                            >
                                <option value="whatsapp">WhatsApp</option>
                                <option value="messenger">Messenger</option>
                                <option value="instagram">Instagram</option>
                            </FormSelect>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium">{{
                                metaTypeMeta[metaForm.type]?.idLabel ??
                                'ID externo'
                            }}</label>
                            <FormInput
                                v-model="metaForm.external_id"
                                type="text"
                                class="h-9 font-mono text-xs"
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
                            <label class="mb-1.5 block text-xs font-medium">{{
                                metaForm.type === 'instagram'
                                    ? 'Page ID de la página de Facebook vinculada'
                                    : 'WhatsApp Business Account ID (opcional)'
                            }}</label>
                            <FormInput
                                v-model="metaForm.waba_id"
                                type="text"
                                class="h-9 font-mono text-xs"
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
                            <label class="mb-1.5 block text-xs font-medium"
                                >Access token</label
                            >
                            <FormInput
                                v-model="metaForm.access_token"
                                type="password"
                                class="h-9 font-mono text-xs"
                                placeholder="EAAG…"
                                autocomplete="off"
                            />
                            <p class="mt-1 text-xs text-slate-400">
                                Se guarda cifrado. En prueba sirve el token
                                temporal (24 h) del panel de Meta.
                            </p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium"
                                >Etiqueta (opcional)</label
                            >
                            <FormInput
                                class="h-9 text-xs"
                                v-model="metaForm.name"
                                type="text"
                                placeholder="WhatsApp prueba"
                            />
                        </div>
                        <p
                            v-if="metaError"
                            class="rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                        >
                            {{ metaError }}
                        </p>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            type="button"
                            variant="outline-secondary"
                            @click="showMetaForm = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs shadow-md shadow-primary/20"
                            :disabled="
                                saving ||
                                !metaForm.external_id ||
                                !metaForm.access_token
                            "
                        >
                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
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
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                    @submit.prevent="submitMetaEdit"
                >
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                            :class="
                                metaTypeMeta[editingMetaLink.type]?.tone ??
                                'bg-primary/10 text-primary'
                            "
                        >
                            <Lucide icon="Pencil" class="h-4 w-4" />
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
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>
                    <div
                        class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4"
                    >
                        <div>
                            <label class="mb-1.5 block text-xs font-medium">{{
                                metaTypeMeta[editingMetaLink.type]?.idLabel ??
                                'ID externo'
                            }}</label>
                            <FormInput
                                v-model="metaEditForm.external_id"
                                type="text"
                                class="h-9 font-mono text-xs"
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
                            <label class="mb-1.5 block text-xs font-medium"
                                >Nombre (opcional)</label
                            >
                            <FormInput
                                class="h-9 text-xs"
                                v-model="metaEditForm.name"
                                type="text"
                                placeholder="WhatsApp prueba"
                            />
                        </div>
                        <div v-if="editingMetaLink.type !== 'messenger'">
                            <label class="mb-1.5 block text-xs font-medium">{{
                                editingMetaLink.type === 'instagram'
                                    ? 'Page ID de la página de Facebook vinculada (solo ruta vía página)'
                                    : 'WhatsApp Business Account ID'
                            }}</label>
                            <FormInput
                                v-model="metaEditForm.waba_id"
                                type="text"
                                class="h-9 font-mono text-xs"
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
                            <label class="mb-1.5 block text-xs font-medium"
                                >Identificador de acceso (token)</label
                            >
                            <FormInput
                                v-model="metaEditForm.access_token"
                                type="password"
                                class="h-9 font-mono text-xs"
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
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            type="button"
                            variant="outline-secondary"
                            @click="editingMetaLink = null"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs shadow-md shadow-primary/20"
                            :disabled="saving"
                        >
                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                            {{ saving ? 'Guardando…' : 'Guardar' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal conectar/editar bot de Telegram -->
        <Dialog :open="showTelegramForm" @close="showTelegramForm = false">
            <Dialog.Panel>
                <form
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                    @submit.prevent="submitTelegram"
                >
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-info/10 text-info"
                        >
                            <Lucide icon="Send" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                {{
                                    editingTelegram
                                        ? 'Editar bot de Telegram'
                                        : `Conectar Telegram a ${tenant.name}`
                                }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{
                                    editingTelegram
                                        ? (editingTelegram.bot_username
                                              ? `@${editingTelegram.bot_username}`
                                              : editingTelegram.name) ||
                                          'Bot conectado'
                                        : 'Los mensajes del bot entrarán a la bandeja de este hotel'
                                }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showTelegramForm = false"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>
                    <div
                        class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4"
                    >
                        <div>
                            <label class="mb-1.5 block text-xs font-medium"
                                >Token del bot</label
                            >
                            <FormInput
                                v-model="telegramForm.bot_token"
                                type="password"
                                class="h-9 font-mono text-xs"
                                :placeholder="
                                    editingTelegram
                                        ? 'Dejar vacío para conservar el actual'
                                        : '123456789:AAH…'
                                "
                                autocomplete="off"
                            />
                            <p class="mt-1 text-xs text-slate-400">
                                Se crea con BotFather en Telegram (comando
                                /newbot) y se guarda cifrado. El webhook se
                                registra solo al guardar.
                            </p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium"
                                >Etiqueta (opcional)</label
                            >
                            <FormInput
                                class="h-9 text-xs"
                                v-model="telegramForm.name"
                                type="text"
                                placeholder="Telegram recepción"
                            />
                        </div>
                        <p
                            v-if="telegramError"
                            class="rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                        >
                            {{ telegramError }}
                        </p>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            type="button"
                            variant="outline-secondary"
                            @click="showTelegramForm = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs shadow-md shadow-primary/20"
                            :disabled="
                                saving ||
                                (!editingTelegram && !telegramForm.bot_token)
                            "
                        >
                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                saving
                                    ? 'Guardando…'
                                    : editingTelegram
                                      ? 'Guardar'
                                      : 'Conectar'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal conectar/editar cuenta de TikTok -->
        <Dialog :open="showTiktokForm" @close="showTiktokForm = false">
            <Dialog.Panel>
                <form
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                    @submit.prevent="submitTiktok"
                >
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-dark/10 text-dark dark:text-slate-300"
                        >
                            <Lucide icon="Music2" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                {{
                                    editingTiktok
                                        ? 'Editar cuenta de TikTok'
                                        : `Vincular TikTok a ${tenant.name}`
                                }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Mensajes directos de la cuenta business vía la
                                Business Messaging API
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showTiktokForm = false"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>
                    <div
                        class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4"
                    >
                        <div>
                            <label class="mb-1.5 block text-xs font-medium"
                                >ID de la cuenta business</label
                            >
                            <FormInput
                                v-model="tiktokForm.business_id"
                                type="text"
                                class="h-9 font-mono text-xs"
                                placeholder="74123456789…"
                            />
                            <p class="mt-1 text-xs text-slate-400">
                                El business_id (u open_id) de la cuenta de
                                TikTok en el panel de la app aprobada.
                            </p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium"
                                >Access token</label
                            >
                            <FormInput
                                v-model="tiktokForm.access_token"
                                type="password"
                                class="h-9 font-mono text-xs"
                                :placeholder="
                                    editingTiktok
                                        ? 'Dejar vacío para conservar el actual'
                                        : 'act.…'
                                "
                                autocomplete="off"
                            />
                            <p class="mt-1 text-xs text-slate-400">
                                Token de la app de TikTok con permiso de
                                mensajería. Se guarda cifrado.
                            </p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium"
                                >Etiqueta (opcional)</label
                            >
                            <FormInput
                                class="h-9 text-xs"
                                v-model="tiktokForm.name"
                                type="text"
                                placeholder="TikTok del hotel"
                            />
                        </div>
                        <div
                            v-if="editingTiktok"
                            class="flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <Lucide
                                icon="Webhook"
                                class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                            />
                            <span class="min-w-0">
                                Webhook del canal (pégalo en el panel de la app
                                de TikTok):
                                <span
                                    class="mt-1 block rounded bg-slate-800 px-2 py-1 font-mono text-[11px] break-all text-slate-200"
                                    >{{ editingTiktok.webhook_url }}</span
                                >
                            </span>
                        </div>
                        <p
                            v-if="tiktokError"
                            class="rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                        >
                            {{ tiktokError }}
                        </p>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            type="button"
                            variant="outline-secondary"
                            @click="showTiktokForm = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs shadow-md shadow-primary/20"
                            :disabled="
                                saving ||
                                !tiktokForm.business_id ||
                                (!editingTiktok && !tiktokForm.access_token)
                            "
                        >
                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                saving
                                    ? 'Guardando…'
                                    : editingTiktok
                                      ? 'Guardar'
                                      : 'Vincular'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal diagnóstico de canal -->
        <Dialog :open="diagnosingLink !== null" @close="diagnosingLink = null">
            <Dialog.Panel>
                <div class="flex max-h-[calc(100dvh-6rem)] flex-col">
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                        >
                            <Lucide icon="Stethoscope" class="h-4 w-4" />
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
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
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
                            class="rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
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
                                            class="rounded-full px-2 py-0.5 text-[11px] font-medium"
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
                                                    class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
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
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
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
                            class="h-9 rounded-[0.5rem] px-5 text-xs shadow-md shadow-primary/20"
                            :disabled="
                                resubscribing !== null || diagnoseLoading
                            "
                            @click="
                                diagnosingLink && resubscribe(diagnosingLink)
                            "
                        >
                            <Lucide icon="Wrench" class="mr-1.5 h-3.5 w-3.5" />
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

        <!-- Modal app de Meta propia del hotel -->
        <Dialog :open="showAppForm" @close="showAppForm = false">
            <Dialog.Panel>
                <form class="p-5" @submit.prevent="submitApp">
                    <div class="mb-4 flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="AppWindow" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                {{
                                    metaApp
                                        ? 'Editar app de Meta del hotel'
                                        : 'Conectar app de Meta propia'
                                }}
                            </h2>
                            <!-- El hotel en grande: las claves se han pegado
                                 en la ficha equivocada por no verlo. -->
                            <p class="mt-0.5 text-xs text-slate-500">
                                Para
                                <span
                                    class="font-medium text-slate-600 dark:text-slate-300"
                                    >{{ tenant.name }}</span
                                >
                                — confirma que es el hotel correcto antes de
                                pegar claves.
                            </p>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="mb-1.5 block text-xs font-medium"
                                >Nombre (opcional)</label
                            >
                            <FormInput
                                class="h-9 text-xs"
                                v-model="appForm.name"
                                type="text"
                                placeholder="App Real de la Sierra"
                            />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium"
                                >Identificador de la aplicacion (app_id)</label
                            >
                            <FormInput
                                class="h-9 text-xs"
                                v-model="appForm.app_id"
                                type="text"
                                placeholder="2350925339051747"
                                required
                            />
                            <FormHelp>
                                El numero de arriba del panel de la app en
                                developers.facebook.com.
                            </FormHelp>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium"
                                >Clave secreta de la aplicacion</label
                            >
                            <FormInput
                                class="h-9 text-xs"
                                v-model="appForm.app_secret"
                                type="text"
                                :placeholder="
                                    metaApp
                                        ? 'Dejar vacio para conservar la actual'
                                        : 'Configuracion de la app → Basico → Mostrar'
                                "
                            />
                            <FormHelp>
                                Firma los webhooks del hotel. Se guarda cifrada.
                                <template v-if="metaApp?.masked_app_secret">
                                    Ya hay una guardada ({{
                                        metaApp.masked_app_secret
                                    }}): déjala vacía para conservarla.
                                </template>
                            </FormHelp>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium"
                                >Clave secreta de Instagram (opcional)</label
                            >
                            <FormInput
                                class="h-9 text-xs"
                                v-model="appForm.ig_app_secret"
                                type="text"
                                :placeholder="
                                    metaApp
                                        ? 'Dejar vacio para conservar la actual'
                                        : 'Producto Instagram → cabecera → Mostrar'
                                "
                            />
                            <FormHelp>
                                Es OTRA clave: la del producto Instagram (tokens
                                IGAA). Sin ella, los DMs de Instagram de este
                                hotel se rechazan.
                                <template v-if="metaApp">
                                    {{
                                        metaApp.masked_ig_app_secret
                                            ? `Ya hay una guardada (${metaApp.masked_ig_app_secret}): déjala vacía para conservarla.`
                                            : 'Aún no hay una guardada para este hotel.'
                                    }}
                                </template>
                            </FormHelp>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium"
                                >Configuracion de registro incrustado
                                (opcional)</label
                            >
                            <FormInput
                                class="h-9 text-xs"
                                v-model="appForm.login_config_id"
                                type="text"
                                placeholder="ID de la configuracion de Embedded Signup"
                            />
                            <FormHelp>
                                Del producto "Inicio de sesion con Facebook para
                                empresas". Habilita el boton Conectar con
                                Facebook en el panel del hotel.
                            </FormHelp>
                        </div>
                        <p
                            v-if="appError"
                            class="rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                        >
                            {{ appError }}
                        </p>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            type="button"
                            variant="outline-secondary"
                            :disabled="savingApp"
                            @click="showAppForm = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            type="submit"
                            variant="primary"
                            :disabled="savingApp"
                        >
                            {{ savingApp ? 'Guardando...' : 'Guardar app' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmar volver a la app de la plataforma -->
        <Dialog :open="confirmAppRemoval" @close="confirmAppRemoval = false">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="mb-4 flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-warning/10 text-warning"
                        >
                            <Lucide icon="TriangleAlert" class="h-4 w-4" />
                        </div>
                        <h2 class="text-base font-medium">
                            Volver a la app de la plataforma
                        </h2>
                    </div>
                    <p class="text-xs text-slate-500">
                        Se elimina la app propia de este hotel. Sus canales
                        seguiran funcionando SOLO si sus tokens y suscripciones
                        pertenecen a la app de la plataforma — si fueron
                        emitidos por la app propia, dejaran de validar. Confirma
                        solo si sabes lo que haces.
                    </p>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            variant="outline-secondary"
                            :disabled="savingApp"
                            @click="confirmAppRemoval = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            variant="danger"
                            :disabled="savingApp"
                            @click="removeApp"
                        >
                            {{
                                savingApp ? 'Quitando...' : 'Quitar app propia'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
        <!-- Confirmación: desvincular canal -->
        <Dialog
            :open="unlinking !== null"
            @close="!unlinkingBusy && (unlinking = null)"
        >
            <Dialog.Panel>
                <div v-if="unlinking" class="p-5">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                        >
                            <Lucide icon="Unplug" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-base font-medium">
                                Desvincular {{ unlinking.title }}
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">
                                Los mensajes de este {{ unlinking.badge }} dejan
                                de entrar a la bandeja de {{ tenant.name }} y el
                                bot deja de contestarlos. Para volver hay que
                                vincularlo otra vez con su token. Si solo
                                quieres dejar de recibir por un rato, mejor
                                páusalo con el interruptor.
                            </p>
                            <p
                                v-if="unlinking.active"
                                class="mt-3 flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning"
                            >
                                <Lucide
                                    icon="TriangleAlert"
                                    class="mt-px h-3.5 w-3.5 shrink-0"
                                />
                                Está activo<template
                                    v-if="unlinking.last_event_at"
                                >
                                    y recibió mensajes hace
                                    {{ unlinking.last_event_at }}</template
                                >.
                            </p>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="unlinkingBusy"
                            @click="unlinking = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="unlinkingBusy"
                            @click="confirmUnlink"
                        >
                            <Lucide icon="Unplug" class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                unlinkingBusy
                                    ? 'Desvinculando...'
                                    : 'Sí, desvincular'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
