<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface TokenRow {
    id: number;
    name: string;
    last_used_at: string | null;
    created_at: string;
}
interface AiProviderRow {
    id: number;
    provider: string;
    label: string;
    model: string;
    masked_key: string;
    active: boolean;
    replies: number;
    avg_ms: number | null;
    tokens: number;
}
interface CatalogModel {
    id: string;
    tier: 'new' | 'mid' | 'cheap';
}
interface CatalogEntry {
    key: string;
    label: string;
    placeholder_model: string;
    key_hint: string;
    models: CatalogModel[];
}
interface EvolutionChannelRow {
    id: number;
    name: string | null;
    base_url: string;
    instance: string;
    masked_key: string;
    webhook_url: string;
    active: boolean;
    created_at: string | null;
}

interface MetaChannelRow {
    id: number;
    name: string | null;
    external_id: string;
    waba_id: string | null;
    masked_token: string;
    active: boolean;
    last_event_at: string | null;
    created_at: string | null;
}

interface MetaDiagnose {
    token_ok: boolean;
    phone: string | null;
    quality: string | null;
    callback_url: string | null;
    callback_ok: boolean | null;
    subscribed: unknown;
    last_event_at: string | null;
}

const props = defineProps<{
    property: { id: number; name: string };
    tokens: TokenRow[];
    aiProviders: AiProviderRow[];
    aiCatalog: CatalogEntry[];
    llmReady: boolean;
    aiPlan: {
        plan_label: string;
        included: boolean;
        enabled: boolean;
        byok_allowed: boolean;
        api_allowed: boolean;
        limit: number | null;
        used: number;
        blocked_reason: string | null;
    };
    stats: {
        active: boolean;
        policies_set: boolean;
        holds_total: number;
        holds_confirmed: number;
        last_activity: string | null;
    };
    baseUrl: string;
    ratePlansCount: number;
    evolutionChannels: EvolutionChannelRow[];
    metaChannels: MetaChannelRow[];
    metaConfig: {
        mode: string;
        webhook_url: string;
        verify_token: string;
        app_configured: boolean;
    };
    channelLimit: { max: number | null; used: number };
    contextEditable: boolean;
    guidelinesEditable: boolean;
}>();

const toast = useToasts();
const saving = ref(false);

// ── Herramientas (tools) del agente ──
interface Tool {
    key: string;
    fn: string;
    method: 'GET' | 'POST';
    path: string;
    title: string;
    description: string;
    icon: Icon;
    tone: string;
}
const tools: Tool[] = [
    {
        key: 'policies',
        fn: 'get_policies',
        method: 'GET',
        path: '/policies',
        title: 'Políticas del hotel',
        description:
            'Identidad, horarios, contacto y las políticas escritas en Ajustes. La única fuente de verdad del bot.',
        icon: 'ScrollText',
        tone: 'bg-primary/10 text-primary',
    },
    {
        key: 'rate_plans',
        fn: 'get_rate_plans',
        method: 'GET',
        path: '/rate-plans',
        title: 'Tarifas',
        description:
            'Tarifas activas con precio, duración, anticipo y antelación mínima para cotizar.',
        icon: 'Tag',
        tone: 'bg-info/10 text-info',
    },
    {
        key: 'availability',
        fn: 'check_availability',
        method: 'GET',
        path: '/availability',
        title: 'Disponibilidad',
        description:
            'Habitaciones libres y total oficial para una tarifa y rango de fechas.',
        icon: 'CalendarSearch',
        tone: 'bg-success/10 text-success',
    },
    {
        key: 'hold',
        fn: 'create_hold',
        method: 'POST',
        path: '/holds',
        title: 'Crear apartado (hold)',
        description:
            'Aparta habitación como reserva pendiente. Nunca confirma ni cobra; expira sola si nadie la confirma. Idempotente.',
        icon: 'CalendarPlus',
        tone: 'bg-warning/10 text-warning',
    },
    {
        key: 'reservation',
        fn: 'get_reservation',
        method: 'GET',
        path: '/reservations/{código}',
        title: 'Estado de reserva',
        description:
            'Consulta una reserva por su código (RES-2026-0001) sin exponer datos sensibles.',
        icon: 'SearchCheck',
        tone: 'bg-pending/10 text-pending',
    },
];

// ── Playground ──
const playing = ref<Tool | null>(null);
const playResult = ref<string | null>(null);
const playStatus = ref<number | null>(null);
const playBusy = ref(false);
const playParams = reactive({
    rate_plan_id: '' as string | number,
    starts_at: '',
    ends_at: '',
    code: '',
    guest_name: 'Huésped de prueba',
    guest_phone: '',
});

function openPlay(tool: Tool) {
    playing.value = tool;
    playResult.value = null;
    playStatus.value = null;
    if (!playParams.starts_at) {
        const d = new Date();
        d.setDate(d.getDate() + 1);
        d.setHours(15, 0, 0, 0);
        playParams.starts_at = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}T15:00`;
    }
}

async function runPlay() {
    if (!playing.value) return;
    playBusy.value = true;
    playResult.value = null;
    try {
        const params: Record<string, unknown> = {};
        if (
            playing.value.key === 'availability' ||
            playing.value.key === 'hold'
        ) {
            params.rate_plan_id = playParams.rate_plan_id || undefined;
            params.starts_at = playParams.starts_at || undefined;
            params.ends_at = playParams.ends_at || undefined;
        }
        if (playing.value.key === 'hold') {
            params.guest_name = playParams.guest_name || undefined;
            params.guest_phone = playParams.guest_phone || undefined;
        }
        if (playing.value.key === 'reservation') {
            params.code = playParams.code || undefined;
        }
        const { data, status } = await axios.post(
            route('tenant.agent-playground'),
            { tool: playing.value.key, params },
        );
        playStatus.value = status;
        playResult.value = JSON.stringify(data, null, 2);
        if (playing.value.key === 'hold') router.reload({ only: ['stats'] });
    } catch (e: any) {
        playStatus.value = e.response?.status ?? 500;
        playResult.value = JSON.stringify(
            e.response?.data ?? { message: 'Error' },
            null,
            2,
        );
    } finally {
        playBusy.value = false;
    }
}

const needsParams = computed(
    () =>
        playing.value &&
        ['availability', 'hold', 'reservation'].includes(playing.value.key),
);

// ── Tokens ──
const showCreateToken = ref(false);
const tokenName = ref('');
const newToken = ref<string | null>(null);
const copied = ref(false);

async function createToken() {
    saving.value = true;
    try {
        const { data } = await axios.post(route('tenant.agent-tokens.store'), {
            name: tokenName.value || 'Agente',
        });
        newToken.value = data.token;
        router.reload({ only: ['tokens', 'stats'] });
    } catch (e: any) {
        toast.error(
            'No se pudo crear',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        saving.value = false;
    }
}

async function copyToken() {
    if (!newToken.value) return;
    await navigator.clipboard.writeText(newToken.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

function closeTokenModal() {
    showCreateToken.value = false;
    tokenName.value = '';
    newToken.value = null;
}

const revoking = ref<TokenRow | null>(null);
async function submitRevoke() {
    if (!revoking.value) return;
    saving.value = true;
    try {
        await axios.delete(
            route('tenant.agent-tokens.destroy', revoking.value.id),
        );
        toast.success(
            'Token revocado',
            'El agente que lo usaba perdió el acceso.',
        );
        revoking.value = null;
        router.reload({ only: ['tokens', 'stats'] });
    } catch (e: any) {
        toast.error(
            'No se pudo revocar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        saving.value = false;
    }
}

const curlExample = computed(
    () => `curl ${props.baseUrl}/availability \\
  -H "Authorization: Bearer <TOKEN>" \\
  -G -d rate_plan_id=1 -d starts_at="2026-07-10 15:00"`,
);

// ── Proveedores de IA del hotel ──
const providerTone: Record<string, string> = {
    anthropic: 'bg-pending/10 text-pending',
    openai: 'bg-success/10 text-success',
    deepseek: 'bg-info/10 text-info',
    kimi: 'bg-primary/10 text-primary',
    minimax: 'bg-warning/10 text-warning',
};

const showProviderForm = ref(false);
const editingProvider = ref<AiProviderRow | null>(null);
const providerForm = reactive({
    provider: 'anthropic',
    model: '',
    api_key: '',
    active: true,
});
const providerError = ref<string | null>(null);

const catalogFor = (key: string) => props.aiCatalog.find((c) => c.key === key);

// Modelos sugeridos por nivel; "__custom" libera el campo manual.
const tierLabels: Record<string, string> = {
    new: 'Los más nuevos',
    mid: 'Intermedios',
    cheap: 'Económicos',
};
const modelChoice = ref('');
const modelGroups = computed(() => {
    const models = catalogFor(providerForm.provider)?.models ?? [];
    return (['new', 'mid', 'cheap'] as const)
        .map((tier) => ({
            tier,
            label: tierLabels[tier],
            models: models.filter((m) => m.tier === tier),
        }))
        .filter((g) => g.models.length);
});
watch(modelChoice, (v) => {
    if (v !== '__custom') providerForm.model = v;
});
watch(
    () => providerForm.provider,
    (key) => {
        if (!editingProvider.value)
            modelChoice.value = catalogFor(key)?.models[0]?.id ?? '__custom';
    },
);

function openProviderCreate() {
    editingProvider.value = null;
    providerForm.provider = 'anthropic';
    providerForm.api_key = '';
    providerForm.active = true;
    providerError.value = null;
    modelChoice.value = catalogFor('anthropic')?.models[0]?.id ?? '__custom';
    providerForm.model =
        modelChoice.value === '__custom' ? '' : modelChoice.value;
    showProviderForm.value = true;
}

function openProviderEdit(p: AiProviderRow) {
    editingProvider.value = p;
    providerForm.provider = p.provider;
    providerForm.model = p.model;
    providerForm.api_key = '';
    providerForm.active = p.active;
    providerError.value = null;
    const known = catalogFor(p.provider)?.models.some((m) => m.id === p.model);
    modelChoice.value = known ? p.model : '__custom';
    showProviderForm.value = true;
}

async function submitProvider() {
    saving.value = true;
    providerError.value = null;
    try {
        const model =
            providerForm.model ||
            catalogFor(providerForm.provider)?.placeholder_model ||
            '';
        if (editingProvider.value) {
            await axios.patch(
                route('tenant.ai-providers.update', editingProvider.value.id),
                {
                    model,
                    api_key: providerForm.api_key || null,
                    active: providerForm.active,
                },
            );
        } else {
            await axios.post(route('tenant.ai-providers.store'), {
                provider: providerForm.provider,
                model,
                api_key: providerForm.api_key,
                active: providerForm.active,
            });
        }
        showProviderForm.value = false;
        toast.success(
            'Proveedor guardado',
            'Ya forma parte de la cadena del bot.',
        );
        router.reload({ only: ['aiProviders', 'llmReady'] });
    } catch (e: any) {
        providerError.value =
            e.response?.data?.message ??
            (
                Object.values(e.response?.data?.errors ?? {})[0] as
                    string[] | undefined
            )?.[0] ??
            'No se pudo guardar.';
    } finally {
        saving.value = false;
    }
}

async function toggleProvider(p: AiProviderRow) {
    try {
        await axios.patch(route('tenant.ai-providers.update', p.id), {
            active: !p.active,
        });
        p.active = !p.active;
        router.reload({ only: ['llmReady'] });
    } catch (e: any) {
        toast.error(
            'No se pudo cambiar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    }
}

const deletingProvider = ref<AiProviderRow | null>(null);
async function submitDeleteProvider() {
    if (!deletingProvider.value) return;
    saving.value = true;
    try {
        await axios.delete(
            route('tenant.ai-providers.destroy', deletingProvider.value.id),
        );
        deletingProvider.value = null;
        toast.success('Proveedor eliminado');
        router.reload({ only: ['aiProviders', 'llmReady'] });
    } finally {
        saving.value = false;
    }
}

// Prueba de conexión real (latencia + respuesta).
const testResults = reactive<
    Record<number, { ok: boolean; ms: number; text: string } | 'loading'>
>({});
async function testProvider(p: AiProviderRow) {
    testResults[p.id] = 'loading';
    try {
        const { data } = await axios.post(
            route('tenant.ai-providers.test', p.id),
        );
        testResults[p.id] = {
            ok: true,
            ms: data.ms,
            text: `"${data.reply}" · ${data.tokens} tokens`,
        };
    } catch (e: any) {
        const d = e.response?.data;
        testResults[p.id] = {
            ok: false,
            ms: d?.ms ?? 0,
            text: d?.error ?? 'Error de conexión',
        };
    }
}

// ── Canales WhatsApp (Evolution API) ──
const channelLimitReached = computed(
    () =>
        props.channelLimit.max !== null &&
        props.channelLimit.used >= props.channelLimit.max,
);
const channelCountLabel = computed(() =>
    props.channelLimit.max !== null
        ? `${props.channelLimit.used} de ${props.channelLimit.max} canales`
        : `${props.channelLimit.used} conectados`,
);

function channelName(ch: EvolutionChannelRow): string {
    return ch.name || `WhatsApp ${ch.instance}`;
}

const showChannelForm = ref(false);
const editingChannel = ref<EvolutionChannelRow | null>(null);
const channelForm = reactive({
    name: '',
    base_url: '',
    instance: '',
    api_key: '',
    active: true,
});
const channelError = ref<string | null>(null);

function openChannelCreate() {
    if (channelLimitReached.value) return;
    editingChannel.value = null;
    channelForm.name = '';
    channelForm.base_url = '';
    channelForm.instance = '';
    channelForm.api_key = '';
    channelForm.active = true;
    channelError.value = null;
    showChannelForm.value = true;
}

function openChannelEdit(ch: EvolutionChannelRow) {
    editingChannel.value = ch;
    channelForm.name = ch.name ?? '';
    channelForm.base_url = ch.base_url;
    channelForm.instance = ch.instance;
    channelForm.api_key = '';
    channelForm.active = ch.active;
    channelError.value = null;
    showChannelForm.value = true;
}

async function submitChannel() {
    saving.value = true;
    channelError.value = null;
    try {
        if (editingChannel.value) {
            await axios.patch(
                route(
                    'tenant.evolution-channels.update',
                    editingChannel.value.id,
                ),
                {
                    name: channelForm.name || null,
                    api_key: channelForm.api_key || null,
                    active: channelForm.active,
                },
            );
            toast.success(
                'Canal actualizado',
                'Los cambios ya están aplicados.',
            );
        } else {
            const { data } = await axios.post(
                route('tenant.evolution-channels.store'),
                {
                    name: channelForm.name || null,
                    base_url: channelForm.base_url,
                    instance: channelForm.instance,
                    api_key: channelForm.api_key,
                },
            );
            if (data.webhook_configured) {
                toast.success(
                    'Instancia conectada',
                    'Webhook configurado automáticamente.',
                );
            } else {
                toast.success('Instancia conectada');
                toast.error(
                    'Configura el webhook manualmente en Evolution',
                    'La URL del webhook queda visible en la tabla.',
                );
            }
        }
        showChannelForm.value = false;
        router.reload({ only: ['evolutionChannels', 'channelLimit'] });
    } catch (e: any) {
        const msg =
            e.response?.data?.message ??
            (
                Object.values(e.response?.data?.errors ?? {})[0] as
                    string[] | undefined
            )?.[0] ??
            'No se pudo guardar.';
        channelError.value = msg;
        toast.error('No se pudo guardar el canal', msg);
    } finally {
        saving.value = false;
    }
}

const deletingChannel = ref<EvolutionChannelRow | null>(null);
async function submitDeleteChannel() {
    if (!deletingChannel.value) return;
    saving.value = true;
    try {
        await axios.delete(
            route(
                'tenant.evolution-channels.destroy',
                deletingChannel.value.id,
            ),
        );
        deletingChannel.value = null;
        toast.success(
            'Número desconectado',
            'Las conversaciones y su historial se conservan en la bandeja.',
        );
        router.reload({ only: ['evolutionChannels', 'channelLimit'] });
    } catch (e: any) {
        toast.error(
            'No se pudo desconectar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        saving.value = false;
    }
}

const channelTests = reactive<
    Record<number, { ok: boolean; state: string } | 'loading'>
>({});
async function testChannel(ch: EvolutionChannelRow) {
    channelTests[ch.id] = 'loading';
    try {
        const { data } = await axios.post(
            route('tenant.evolution-channels.test', ch.id),
        );
        const state: string = data.connection?.state ?? 'desconocido';
        channelTests[ch.id] = { ok: state === 'open', state };
        if (state === 'open') {
            toast.success(
                'Instancia conectada',
                'El número de WhatsApp está en línea.',
            );
        } else {
            toast.error(
                `Instancia sin conexión (${state})`,
                'Escanea el QR de la instancia en Evolution para vincular el número.',
            );
        }
        if (data.webhook_configured === false) {
            toast.error(
                'Webhook sin configurar',
                `Configúralo manualmente en Evolution con la URL: ${data.webhook_url}`,
            );
        }
    } catch (e: any) {
        channelTests[ch.id] = { ok: false, state: 'error' };
        toast.error(
            'No se pudo probar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    }
}

const copiedWebhookId = ref<number | null>(null);
async function copyWebhook(ch: EvolutionChannelRow) {
    await navigator.clipboard.writeText(ch.webhook_url);
    copiedWebhookId.value = ch.id;
    setTimeout(() => {
        if (copiedWebhookId.value === ch.id) copiedWebhookId.value = null;
    }, 2000);
}

// ── Canales WhatsApp (Cloud API oficial de Meta) ──
const showMetaForm = ref(false);
const editingMeta = ref<MetaChannelRow | null>(null);
const metaForm = reactive({
    name: '',
    external_id: '',
    waba_id: '',
    access_token: '',
    active: true,
});
const metaError = ref<string | null>(null);
const metaSaving = ref(false);
const metaTests = reactive<Record<number, MetaDiagnose>>({});
const testingMeta = ref<number | null>(null);
const deletingMeta = ref<MetaChannelRow | null>(null);

function openMetaCreate() {
    if (channelLimitReached.value) return;
    editingMeta.value = null;
    metaForm.name = '';
    metaForm.external_id = '';
    metaForm.waba_id = '';
    metaForm.access_token = '';
    metaForm.active = true;
    metaError.value = null;
    showMetaForm.value = true;
}

function openMetaEdit(ch: MetaChannelRow) {
    editingMeta.value = ch;
    metaForm.name = ch.name ?? '';
    metaForm.external_id = ch.external_id;
    metaForm.waba_id = ch.waba_id ?? '';
    metaForm.access_token = '';
    metaForm.active = ch.active;
    metaError.value = null;
    showMetaForm.value = true;
}

async function submitMeta() {
    metaSaving.value = true;
    metaError.value = null;
    try {
        if (editingMeta.value) {
            await axios.patch(
                route('tenant.meta-channels.update', editingMeta.value.id),
                {
                    name: metaForm.name || null,
                    external_id: metaForm.external_id,
                    waba_id: metaForm.waba_id || null,
                    access_token: metaForm.access_token || null,
                    active: metaForm.active,
                },
            );
            toast.success('WhatsApp actualizado');
        } else {
            await axios.post(route('tenant.meta-channels.store'), {
                name: metaForm.name || null,
                external_id: metaForm.external_id,
                waba_id: metaForm.waba_id || null,
                access_token: metaForm.access_token,
            });
            toast.success(
                'WhatsApp conectado',
                'Prueba la conexión para validar el número y el webhook.',
            );
        }
        showMetaForm.value = false;
        router.reload({ only: ['metaChannels', 'channelLimit'] });
    } catch (e: any) {
        const data = e.response?.data;
        const firstError = data?.errors
            ? (Object.values(data.errors)[0] as string[])?.[0]
            : null;
        metaError.value = data?.message ?? firstError ?? 'No se pudo guardar.';
    } finally {
        metaSaving.value = false;
    }
}

async function submitDeleteMeta() {
    if (!deletingMeta.value) return;
    metaSaving.value = true;
    try {
        await axios.delete(
            route('tenant.meta-channels.destroy', deletingMeta.value.id),
        );
        toast.success('WhatsApp desconectado');
        deletingMeta.value = null;
        router.reload({ only: ['metaChannels', 'channelLimit'] });
    } catch (e: any) {
        toast.error(
            'No se pudo desconectar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        metaSaving.value = false;
    }
}

async function testMeta(ch: MetaChannelRow) {
    testingMeta.value = ch.id;
    try {
        const { data } = await axios.post(
            route('tenant.meta-channels.test', ch.id),
        );
        metaTests[ch.id] = data.diagnose;
        if (data.diagnose.token_ok) {
            toast.success(
                'Conexión válida',
                `Número: ${data.diagnose.phone ?? 'sin nombre'}` +
                    (data.diagnose.callback_ok === false
                        ? ' · el webhook en Meta apunta a otra URL'
                        : ''),
            );
        } else {
            toast.error(
                'Token inválido o expirado',
                'Revisa el access token en tu app de Meta.',
            );
        }
    } catch (e: any) {
        toast.error(
            'No se pudo probar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        testingMeta.value = null;
    }
}

const copiedMeta = ref<string | null>(null);
async function copyMeta(key: string, value: string) {
    await navigator.clipboard.writeText(value);
    copiedMeta.value = key;
    setTimeout(() => {
        if (copiedMeta.value === key) copiedMeta.value = null;
    }, 2000);
}
</script>

<template>
    <RazeLayout title="Asistente IA">
        <div class="grid grid-cols-12 gap-x-6 gap-y-8">
            <!-- Encabezado -->
            <div class="col-span-12">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3.5">
                        <div
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-white"
                        >
                            <Lucide icon="Bot" class="h-6 w-6" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h1 class="text-lg font-medium">
                                    Asistente IA
                                </h1>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        stats.active
                                            ? 'bg-success/10 text-success'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full"
                                        :class="
                                            stats.active
                                                ? 'bg-success'
                                                : 'bg-slate-400'
                                        "
                                    />
                                    {{
                                        stats.active
                                            ? 'Conectado'
                                            : 'Sin conectar'
                                    }}
                                </span>
                            </div>
                            <p class="text-sm text-slate-500">
                                {{ property.name }} · herramientas, accesos y
                                pruebas del bot
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
                                icon="ScrollText"
                                class="mr-2 h-4 w-4 stroke-[1.3]"
                            />
                            Políticas
                        </Button>
                        <Button
                            v-if="guidelinesEditable"
                            as="a"
                            :href="route('tenant.agent-learnings')"
                            variant="outline-primary"
                            class="rounded-[0.5rem] bg-white"
                        >
                            <Lucide
                                icon="GraduationCap"
                                class="mr-2 h-4 w-4 stroke-[1.3]"
                            />
                            Aprendizajes
                        </Button>
                        <Button
                            v-if="contextEditable"
                            as="a"
                            :href="route('tenant.agent-context')"
                            variant="outline-primary"
                            class="rounded-[0.5rem] bg-white"
                        >
                            <Lucide
                                icon="BookOpenText"
                                class="mr-2 h-4 w-4 stroke-[1.3]"
                            />
                            Contexto del bot
                        </Button>
                        <Button
                            v-if="aiPlan.api_allowed"
                            variant="primary"
                            class="rounded-[0.5rem] shadow-md shadow-primary/20"
                            @click="showCreateToken = true"
                        >
                            <Lucide
                                icon="KeyRound"
                                class="mr-2 h-4 w-4 stroke-[1.3]"
                            />
                            Nuevo token
                        </Button>
                    </div>
                </div>

                <!-- Aviso de prerequisito -->
                <div
                    v-if="!stats.policies_set"
                    class="mt-4 flex items-center gap-2 rounded-lg border-l-4 border-l-warning bg-warning/5 px-4 py-3 text-sm"
                >
                    <Lucide
                        icon="TriangleAlert"
                        class="h-4 w-4 shrink-0 text-warning"
                    />
                    <span>
                        Aún no escribes las
                        <span class="font-medium">políticas del hotel</span> —
                        el bot no inventará respuestas: dirá que no tiene esa
                        información.
                        <a
                            :href="route('tenant.hotel-settings')"
                            class="font-medium text-primary hover:underline"
                            >Escríbelas en Ajustes →</a
                        >
                    </span>
                </div>

                <!-- Stats -->
                <div class="mt-5 grid grid-cols-12 gap-5">
                    <div
                        v-if="aiPlan.api_allowed"
                        class="box box--stacked col-span-6 p-5 xl:col-span-3"
                    >
                        <div class="flex items-center justify-between">
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                            >
                                <Lucide
                                    icon="KeyRound"
                                    class="h-5 w-5 text-primary"
                                />
                            </div>
                            <div class="text-2xl font-medium">
                                {{ tokens.length }}
                            </div>
                        </div>
                        <div class="mt-4 text-sm font-medium">
                            Tokens activos
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            {{
                                stats.last_activity
                                    ? `Última actividad ${stats.last_activity}`
                                    : 'Sin actividad aún'
                            }}
                        </div>
                    </div>
                    <div
                        v-else
                        class="box box--stacked col-span-6 p-5 xl:col-span-3"
                    >
                        <div class="flex items-center justify-between">
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                            >
                                <Lucide
                                    icon="Globe"
                                    class="h-5 w-5 text-primary"
                                />
                            </div>
                            <div class="text-2xl font-medium">1</div>
                        </div>
                        <div class="mt-4 text-sm font-medium">
                            Canales activos
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            Webchat · WhatsApp próximamente
                        </div>
                    </div>
                    <div class="box box--stacked col-span-6 p-5 xl:col-span-3">
                        <div class="flex items-center justify-between">
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-full border border-warning/10 bg-warning/10"
                            >
                                <Lucide
                                    icon="CalendarPlus"
                                    class="h-5 w-5 text-warning"
                                />
                            </div>
                            <div class="text-2xl font-medium">
                                {{ stats.holds_total }}
                            </div>
                        </div>
                        <div class="mt-4 text-sm font-medium">
                            Apartados creados
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            Reservas iniciadas por el bot
                        </div>
                    </div>
                    <div class="box box--stacked col-span-6 p-5 xl:col-span-3">
                        <div class="flex items-center justify-between">
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-full border border-success/10 bg-success/10"
                            >
                                <Lucide
                                    icon="CircleCheck"
                                    class="h-5 w-5 text-success"
                                />
                            </div>
                            <div class="text-2xl font-medium">
                                {{ stats.holds_confirmed }}
                            </div>
                        </div>
                        <div class="mt-4 text-sm font-medium">Convertidos</div>
                        <div class="mt-1 text-xs text-slate-500">
                            Apartados que el hotel confirmó
                        </div>
                    </div>
                    <div class="box box--stacked col-span-6 p-5 xl:col-span-3">
                        <div class="flex items-center justify-between">
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-full border"
                                :class="
                                    stats.policies_set
                                        ? 'border-success/10 bg-success/10'
                                        : 'border-danger/10 bg-danger/10'
                                "
                            >
                                <Lucide
                                    icon="ScrollText"
                                    class="h-5 w-5"
                                    :class="
                                        stats.policies_set
                                            ? 'text-success'
                                            : 'text-danger'
                                    "
                                />
                            </div>
                            <div class="text-2xl font-medium">
                                {{ stats.policies_set ? 'Sí' : 'No' }}
                            </div>
                        </div>
                        <div class="mt-4 text-sm font-medium">
                            Políticas escritas
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            {{ ratePlansCount }} tarifa(s) para cotizar
                        </div>
                    </div>
                </div>
            </div>

            <!-- IA incluida en el plan (gestionada por la plataforma) -->
            <div class="col-span-12">
                <div class="flex items-center md:h-10">
                    <div class="flex items-center gap-2 text-base font-medium">
                        Inteligencia del asistente
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                llmReady
                                    ? 'bg-success/10 text-success'
                                    : 'bg-danger/10 text-danger'
                            "
                        >
                            <span
                                class="h-1.5 w-1.5 rounded-full"
                                :class="llmReady ? 'bg-success' : 'bg-danger'"
                            />
                            {{
                                llmReady
                                    ? 'Bot con IA listo'
                                    : 'Sin IA disponible'
                            }}
                        </span>
                    </div>
                </div>

                <div class="box box--stacked mt-3.5 p-5">
                    <div class="flex flex-wrap items-center gap-4">
                        <div
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full"
                            :class="
                                aiPlan.included
                                    ? 'bg-success/10 text-success'
                                    : 'bg-slate-100 text-slate-400 dark:bg-darkmode-400'
                            "
                        >
                            <Lucide
                                :icon="aiPlan.included ? 'Sparkles' : 'Lock'"
                                class="h-6 w-6"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium">
                                    {{
                                        aiPlan.included
                                            ? `IA incluida en tu plan ${aiPlan.plan_label}`
                                            : `Tu plan ${aiPlan.plan_label} no incluye IA`
                                    }}
                                </span>
                                <span
                                    v-if="
                                        aiPlan.included &&
                                        aiPlan.blocked_reason === 'quota'
                                    "
                                    class="rounded-full bg-danger/10 px-2 py-0.5 text-xs font-medium text-danger"
                                    >Cuota agotada</span
                                >
                                <span
                                    v-else-if="
                                        aiPlan.included && !aiPlan.enabled
                                    "
                                    class="rounded-full bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning"
                                    >Pausado por la plataforma</span
                                >
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{
                                    aiPlan.included
                                        ? 'La plataforma gestiona los modelos de IA; tú solo escribes tus políticas y el bot atiende.'
                                        : 'Mejora a Pro para que el asistente responda solo a tus huéspedes 24/7.'
                                }}
                            </p>
                        </div>
                        <div
                            v-if="aiPlan.included && aiPlan.limit"
                            class="w-full sm:w-56"
                        >
                            <div
                                class="flex items-center justify-between text-xs text-slate-500"
                            >
                                <span>Respuestas este mes</span>
                                <span class="font-medium"
                                    >{{ aiPlan.used }} /
                                    {{ aiPlan.limit }}</span
                                >
                            </div>
                            <div
                                class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                            >
                                <div
                                    class="h-full rounded-full transition-all"
                                    :class="
                                        aiPlan.used / aiPlan.limit >= 0.9
                                            ? 'bg-danger'
                                            : 'bg-primary/70'
                                    "
                                    :style="{
                                        width: `${Math.min(100, Math.round((aiPlan.used / aiPlan.limit) * 100))}%`,
                                    }"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- BYOK: llaves propias (solo si la plataforma lo permite) -->
            <div v-if="aiPlan.byok_allowed" class="col-span-12">
                <div class="flex items-center md:h-10">
                    <div class="flex items-center gap-2 text-base font-medium">
                        Llaves propias (BYOK)
                        <span
                            class="rounded-full bg-info/10 px-2 py-0.5 text-xs font-medium text-info"
                            >Enterprise</span
                        >
                    </div>
                    <Button
                        variant="outline-primary"
                        size="sm"
                        class="ml-auto rounded-[0.5rem] bg-white"
                        @click="openProviderCreate"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Agregar proveedor
                    </Button>
                </div>
                <p class="mt-1 text-xs text-slate-500">
                    Con llaves propias el consumo
                    <span class="font-medium">no cuenta contra tu cuota</span>.
                    Los activos forman la cadena: se intentan en orden y el
                    primero que responde gana.
                </p>

                <div
                    v-if="aiProviders.length"
                    class="mt-3.5 grid grid-cols-12 gap-5"
                >
                    <div
                        v-for="p in aiProviders"
                        :key="p.id"
                        class="box box--stacked col-span-12 p-5 md:col-span-6 2xl:col-span-4"
                        :class="{ 'opacity-60': !p.active }"
                    >
                        <div class="flex items-start gap-3">
                            <div
                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full"
                                :class="
                                    providerTone[p.provider] ??
                                    'bg-slate-100 text-slate-500'
                                "
                            >
                                <Lucide icon="Sparkles" class="h-5 w-5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="truncate text-sm font-medium"
                                        >{{ p.label }}</span
                                    >
                                    <span
                                        v-if="p.active"
                                        class="rounded-full bg-success/10 px-1.5 py-0.5 text-[10px] font-medium text-success"
                                        >Activo</span
                                    >
                                    <span
                                        v-else
                                        class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400"
                                        >Pausado</span
                                    >
                                </div>
                                <div
                                    class="mt-0.5 flex items-center gap-2 text-xs text-slate-500"
                                >
                                    <span
                                        class="rounded bg-slate-100 px-1.5 py-0.5 font-mono dark:bg-darkmode-400"
                                        >{{ p.model }}</span
                                    >
                                    <span class="font-mono">{{
                                        p.masked_key
                                    }}</span>
                                </div>
                            </div>
                            <FormSwitch class="shrink-0">
                                <FormSwitch.Input
                                    :checked="p.active"
                                    type="checkbox"
                                    @change="toggleProvider(p)"
                                />
                            </FormSwitch>
                        </div>

                        <!-- Uso (costo-beneficio) -->
                        <div
                            class="mt-4 grid grid-cols-3 gap-2 border-t border-dashed border-slate-300/70 pt-3.5 text-center dark:border-darkmode-400"
                        >
                            <div>
                                <div class="text-sm font-medium">
                                    {{ p.replies }}
                                </div>
                                <div class="text-[10px] text-slate-400">
                                    respuestas
                                </div>
                            </div>
                            <div>
                                <div class="text-sm font-medium">
                                    {{
                                        p.avg_ms !== null
                                            ? `${(p.avg_ms / 1000).toFixed(1)}s`
                                            : '—'
                                    }}
                                </div>
                                <div class="text-[10px] text-slate-400">
                                    latencia prom.
                                </div>
                            </div>
                            <div>
                                <div class="text-sm font-medium">
                                    {{ p.tokens.toLocaleString() }}
                                </div>
                                <div class="text-[10px] text-slate-400">
                                    tokens
                                </div>
                            </div>
                        </div>

                        <!-- Resultado de prueba -->
                        <div
                            v-if="testResults[p.id]"
                            class="mt-3 flex items-start gap-2 rounded-lg px-3 py-2 text-xs"
                            :class="
                                testResults[p.id] === 'loading'
                                    ? 'bg-slate-50 text-slate-500 dark:bg-darkmode-700'
                                    : (testResults[p.id] as any).ok
                                      ? 'bg-success/10 text-success'
                                      : 'bg-danger/10 text-danger'
                            "
                        >
                            <Lucide
                                :icon="
                                    testResults[p.id] === 'loading'
                                        ? 'RefreshCw'
                                        : (testResults[p.id] as any).ok
                                          ? 'CircleCheck'
                                          : 'TriangleAlert'
                                "
                                class="mt-0.5 h-3.5 w-3.5 shrink-0"
                                :class="{
                                    'animate-spin':
                                        testResults[p.id] === 'loading',
                                }"
                            />
                            <span v-if="testResults[p.id] === 'loading'"
                                >Probando conexión…</span
                            >
                            <span v-else class="min-w-0 break-words"
                                >{{ (testResults[p.id] as any).ms }} ms ·
                                {{ (testResults[p.id] as any).text }}</span
                            >
                        </div>

                        <div class="mt-3.5 flex gap-2">
                            <Button
                                variant="outline-primary"
                                size="sm"
                                class="flex-1 rounded-[0.5rem] bg-white"
                                :disabled="testResults[p.id] === 'loading'"
                                @click="testProvider(p)"
                            >
                                <Lucide icon="Zap" class="mr-1.5 h-3.5 w-3.5" />
                                Probar
                            </Button>
                            <button
                                type="button"
                                title="Editar"
                                class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                @click="openProviderEdit(p)"
                            >
                                <Lucide icon="Pencil" class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                title="Eliminar"
                                class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                @click="deletingProvider = p"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
                <div
                    v-else
                    class="box box--stacked mt-3.5 flex flex-col items-center gap-3 py-10 text-center"
                >
                    <div
                        class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"
                    >
                        <Lucide icon="Sparkles" class="h-6 w-6" />
                    </div>
                    <p class="max-w-md px-6 text-sm text-slate-500">
                        Da de alta el proveedor de IA de este hotel (Anthropic,
                        ChatGPT, DeepSeek, Kimi o MiniMax) con su propia llave.
                        Puedes registrar varios y comparar costo-beneficio.
                    </p>
                    <Button
                        variant="primary"
                        size="sm"
                        class="rounded-[0.5rem]"
                        @click="openProviderCreate"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-4 w-4" /> Agregar
                        proveedor
                    </Button>
                </div>
            </div>

            <!-- WhatsApp (Evolution API) -->
            <div class="col-span-12">
                <div class="box box--stacked">
                    <div
                        class="flex flex-wrap items-center gap-4 border-b border-slate-200/60 p-5 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                        >
                            <Lucide icon="MessageCircle" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                WhatsApp (Evolution API)
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Conecta tu propio servidor Evolution como canal
                                de WhatsApp, alternativa a la Cloud API de Meta.
                                Cada instancia es un número con su propio modo
                                en la bandeja.
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <span
                                class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500 dark:bg-darkmode-400"
                                >{{ channelCountLabel }}</span
                            >
                            <Button
                                variant="primary"
                                size="sm"
                                class="rounded-[0.5rem] shadow-md shadow-primary/20"
                                :disabled="channelLimitReached"
                                :title="
                                    channelLimitReached
                                        ? 'Alcanzaste el límite de canales de mensajería de tu plan. Mejora tu plan para conectar más números.'
                                        : undefined
                                "
                                @click="openChannelCreate"
                            >
                                <Lucide
                                    icon="Plus"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Conectar instancia
                            </Button>
                        </div>
                    </div>
                    <div class="p-5">
                        <div
                            v-if="evolutionChannels.length"
                            class="overflow-auto lg:overflow-visible"
                        >
                            <Table>
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th class="whitespace-nowrap"
                                            >Nombre</Table.Th
                                        >
                                        <Table.Th class="whitespace-nowrap"
                                            >Servidor</Table.Th
                                        >
                                        <Table.Th class="whitespace-nowrap"
                                            >API key</Table.Th
                                        >
                                        <Table.Th class="whitespace-nowrap"
                                            >Estado</Table.Th
                                        >
                                        <Table.Th class="whitespace-nowrap"
                                            >Webhook</Table.Th
                                        >
                                        <Table.Th
                                            class="text-right whitespace-nowrap"
                                            >Acciones</Table.Th
                                        >
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    <Table.Tr
                                        v-for="ch in evolutionChannels"
                                        :key="ch.id"
                                    >
                                        <Table.Td class="font-medium">{{
                                            channelName(ch)
                                        }}</Table.Td>
                                        <Table.Td>
                                            <div
                                                class="max-w-[16rem] truncate text-sm"
                                                :title="ch.base_url"
                                            >
                                                {{ ch.base_url }}
                                            </div>
                                            <div
                                                class="font-mono text-xs text-slate-500"
                                            >
                                                {{ ch.instance }}
                                            </div>
                                        </Table.Td>
                                        <Table.Td
                                            class="font-mono text-xs text-slate-500"
                                            >{{ ch.masked_key }}</Table.Td
                                        >
                                        <Table.Td>
                                            <span
                                                v-if="ch.active"
                                                class="rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success"
                                                >Activo</span
                                            >
                                            <span
                                                v-else
                                                class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                                                >Inactivo</span
                                            >
                                            <div
                                                v-if="channelTests[ch.id]"
                                                class="mt-1 flex items-center gap-1 text-xs"
                                                :class="
                                                    channelTests[ch.id] ===
                                                    'loading'
                                                        ? 'text-slate-400'
                                                        : (
                                                                channelTests[
                                                                    ch.id
                                                                ] as any
                                                            ).ok
                                                          ? 'text-success'
                                                          : 'text-danger'
                                                "
                                            >
                                                <Lucide
                                                    :icon="
                                                        channelTests[ch.id] ===
                                                        'loading'
                                                            ? 'RefreshCw'
                                                            : (
                                                                    channelTests[
                                                                        ch.id
                                                                    ] as any
                                                                ).ok
                                                              ? 'CircleCheck'
                                                              : 'TriangleAlert'
                                                    "
                                                    class="h-3 w-3 shrink-0"
                                                    :class="{
                                                        'animate-spin':
                                                            channelTests[
                                                                ch.id
                                                            ] === 'loading',
                                                    }"
                                                />
                                                <span
                                                    v-if="
                                                        channelTests[ch.id] ===
                                                        'loading'
                                                    "
                                                    >Probando…</span
                                                >
                                                <span v-else>{{
                                                    (channelTests[ch.id] as any)
                                                        .ok
                                                        ? 'Conectada'
                                                        : `Sin conexión (${(channelTests[ch.id] as any).state})`
                                                }}</span>
                                            </div>
                                        </Table.Td>
                                        <Table.Td>
                                            <div
                                                class="flex items-center gap-1.5"
                                            >
                                                <span
                                                    class="max-w-[11rem] truncate font-mono text-xs text-slate-500"
                                                    :title="ch.webhook_url"
                                                    >{{ ch.webhook_url }}</span
                                                >
                                                <button
                                                    type="button"
                                                    title="Copiar URL"
                                                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                                    @click="copyWebhook(ch)"
                                                >
                                                    <Lucide
                                                        :icon="
                                                            copiedWebhookId ===
                                                            ch.id
                                                                ? 'Check'
                                                                : 'Copy'
                                                        "
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </button>
                                            </div>
                                        </Table.Td>
                                        <Table.Td>
                                            <div class="flex justify-end gap-1">
                                                <button
                                                    type="button"
                                                    title="Probar conexión"
                                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-success/10 hover:text-success"
                                                    :disabled="
                                                        channelTests[ch.id] ===
                                                        'loading'
                                                    "
                                                    @click="testChannel(ch)"
                                                >
                                                    <Lucide
                                                        icon="PlugZap"
                                                        class="h-4 w-4"
                                                    />
                                                </button>
                                                <button
                                                    type="button"
                                                    title="Editar"
                                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                                    @click="openChannelEdit(ch)"
                                                >
                                                    <Lucide
                                                        icon="Pencil"
                                                        class="h-4 w-4"
                                                    />
                                                </button>
                                                <button
                                                    type="button"
                                                    title="Desconectar"
                                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                                    @click="
                                                        deletingChannel = ch
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Trash2"
                                                        class="h-4 w-4"
                                                    />
                                                </button>
                                            </div>
                                        </Table.Td>
                                    </Table.Tr>
                                </Table.Tbody>
                            </Table>
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center gap-3 py-8 text-center"
                        >
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-full bg-success/10 text-success"
                            >
                                <Lucide icon="MessageCircle" class="h-6 w-6" />
                            </div>
                            <p class="max-w-md px-6 text-sm text-slate-500">
                                Sin instancias conectadas. Conecta tu servidor
                                Evolution para que el bot atienda WhatsApp con
                                tu propio número.
                            </p>
                            <Button
                                variant="primary"
                                size="sm"
                                class="rounded-[0.5rem]"
                                :disabled="channelLimitReached"
                                :title="
                                    channelLimitReached
                                        ? 'Alcanzaste el límite de canales de mensajería de tu plan. Mejora tu plan para conectar más números.'
                                        : undefined
                                "
                                @click="openChannelCreate"
                            >
                                <Lucide icon="Plus" class="mr-1.5 h-4 w-4" />
                                Conectar instancia
                            </Button>
                        </div>
                        <p
                            class="mt-4 flex items-start gap-2 text-xs text-slate-500"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0"
                            />
                            <span
                                >El bot, la bandeja y el historial funcionan
                                igual que con Meta: las conversaciones se
                                guardan con su huésped y reserva ligados.</span
                            >
                        </p>
                    </div>
                </div>
            </div>

            <!-- WhatsApp (Cloud API oficial de Meta) -->
            <div class="col-span-12">
                <div class="box box--stacked">
                    <div
                        class="flex flex-wrap items-center gap-4 border-b border-slate-200/60 p-5 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                        >
                            <Lucide icon="BadgeCheck" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                WhatsApp (Cloud API de Meta)
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                El WhatsApp oficial: conecta tu número con su
                                phone_number_id y token de Meta. Más estable que
                                Evolution — no se cae solo.
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <span
                                class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500 dark:bg-darkmode-400"
                                >{{ channelCountLabel }}</span
                            >
                            <Button
                                variant="primary"
                                size="sm"
                                class="rounded-[0.5rem] shadow-md shadow-primary/20"
                                :disabled="channelLimitReached"
                                :title="
                                    channelLimitReached
                                        ? 'Alcanzaste el límite de canales de mensajería de tu plan.'
                                        : undefined
                                "
                                @click="openMetaCreate"
                            >
                                <Lucide
                                    icon="Plus"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Conectar número
                            </Button>
                        </div>
                    </div>

                    <div class="p-5">
                        <!-- Datos para configurar el webhook en la app de Meta -->
                        <div
                            class="grid grid-cols-1 gap-3 rounded-xl border border-dashed border-slate-300/70 bg-slate-50 p-4 sm:grid-cols-2 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <div>
                                <div
                                    class="text-xs font-medium text-slate-500"
                                >
                                    URL del webhook (Callback URL)
                                </div>
                                <button
                                    type="button"
                                    class="mt-1 flex w-full items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-left font-mono text-xs text-slate-600 transition hover:border-primary/40 dark:border-darkmode-400 dark:bg-darkmode-600"
                                    @click="
                                        copyMeta('url', metaConfig.webhook_url)
                                    "
                                >
                                    <span class="min-w-0 flex-1 truncate">{{
                                        metaConfig.webhook_url
                                    }}</span>
                                    <Lucide
                                        :icon="
                                            copiedMeta === 'url'
                                                ? 'Check'
                                                : 'Copy'
                                        "
                                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                    />
                                </button>
                            </div>
                            <div>
                                <div
                                    class="text-xs font-medium text-slate-500"
                                >
                                    Token de verificación
                                </div>
                                <button
                                    type="button"
                                    class="mt-1 flex w-full items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-left font-mono text-xs text-slate-600 transition hover:border-primary/40 dark:border-darkmode-400 dark:bg-darkmode-600"
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
                                            copiedMeta === 'token'
                                                ? 'Check'
                                                : 'Copy'
                                        "
                                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                    />
                                </button>
                            </div>
                            <p
                                class="flex items-start gap-1.5 text-xs text-slate-400 sm:col-span-2"
                            >
                                <Lucide
                                    icon="Info"
                                    class="mt-0.5 h-3.5 w-3.5 shrink-0"
                                />
                                <span
                                    >Pega ambos en tu app de Meta (Webhooks de
                                    WhatsApp) y suscribe el campo
                                    <span class="font-medium">messages</span>.
                                    Luego conecta tu número aquí.</span
                                >
                            </p>
                        </div>

                        <!-- Números conectados -->
                        <div
                            v-if="metaChannels.length"
                            class="mt-4 overflow-auto lg:overflow-visible"
                        >
                            <Table>
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th>Nombre</Table.Th>
                                        <Table.Th>phone_number_id</Table.Th>
                                        <Table.Th>Token</Table.Th>
                                        <Table.Th>Estado</Table.Th>
                                        <Table.Th class="text-right"
                                            >Acciones</Table.Th
                                        >
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    <Table.Tr
                                        v-for="ch in metaChannels"
                                        :key="ch.id"
                                    >
                                        <Table.Td class="font-medium">{{
                                            ch.name || 'WhatsApp'
                                        }}</Table.Td>
                                        <Table.Td
                                            class="font-mono text-xs text-slate-500"
                                            >{{ ch.external_id }}</Table.Td
                                        >
                                        <Table.Td
                                            class="font-mono text-xs text-slate-400"
                                            >{{ ch.masked_token }}</Table.Td
                                        >
                                        <Table.Td>
                                            <div
                                                class="flex flex-wrap items-center gap-1.5"
                                            >
                                                <span
                                                    class="rounded-full px-2 py-0.5 text-[10px] font-medium"
                                                    :class="
                                                        ch.active
                                                            ? 'bg-success/10 text-success'
                                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                                    "
                                                    >{{
                                                        ch.active
                                                            ? 'Activo'
                                                            : 'Inactivo'
                                                    }}</span
                                                >
                                                <span
                                                    v-if="metaTests[ch.id]"
                                                    class="rounded-full px-2 py-0.5 text-[10px] font-medium"
                                                    :class="
                                                        metaTests[ch.id]
                                                            .token_ok
                                                            ? 'bg-success/10 text-success'
                                                            : 'bg-danger/10 text-danger'
                                                    "
                                                    >{{
                                                        metaTests[ch.id].token_ok
                                                            ? metaTests[ch.id]
                                                                  .phone ||
                                                              'token OK'
                                                            : 'token inválido'
                                                    }}</span
                                                >
                                            </div>
                                        </Table.Td>
                                        <Table.Td class="text-right">
                                            <div
                                                class="flex items-center justify-end gap-1.5"
                                            >
                                                <button
                                                    type="button"
                                                    title="Probar conexión"
                                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary disabled:opacity-40"
                                                    :disabled="
                                                        testingMeta === ch.id
                                                    "
                                                    @click="testMeta(ch)"
                                                >
                                                    <Lucide
                                                        icon="PlugZap"
                                                        class="h-4 w-4"
                                                    />
                                                </button>
                                                <button
                                                    type="button"
                                                    title="Editar"
                                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                                    @click="openMetaEdit(ch)"
                                                >
                                                    <Lucide
                                                        icon="Pencil"
                                                        class="h-4 w-4"
                                                    />
                                                </button>
                                                <button
                                                    type="button"
                                                    title="Desconectar"
                                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                                    @click="deletingMeta = ch"
                                                >
                                                    <Lucide
                                                        icon="Trash2"
                                                        class="h-4 w-4"
                                                    />
                                                </button>
                                            </div>
                                        </Table.Td>
                                    </Table.Tr>
                                </Table.Tbody>
                            </Table>
                        </div>
                        <div
                            v-else
                            class="mt-4 rounded-xl border border-dashed border-slate-300/70 px-4 py-8 text-center text-sm text-slate-500 dark:border-darkmode-400"
                        >
                            Aún no conectas ningún número de WhatsApp por Meta.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Herramientas -->
            <div
                v-if="aiPlan.api_allowed"
                class="col-span-12 flex flex-col xl:col-span-7"
            >
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">
                        Herramientas del agente
                    </div>
                    <span
                        class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                        >{{ tools.length }}</span
                    >
                </div>
                <div
                    class="box box--stacked mt-3.5 flex-1 divide-y divide-slate-100 dark:divide-darkmode-400/60"
                >
                    <div
                        v-for="tool in tools"
                        :key="tool.key"
                        class="flex items-center gap-4 p-4"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full"
                            :class="tool.tone"
                        >
                            <Lucide :icon="tool.icon" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium">{{
                                    tool.title
                                }}</span>
                                <span
                                    class="rounded-md px-1.5 py-0.5 font-mono text-[10px] font-semibold"
                                    :class="
                                        tool.method === 'GET'
                                            ? 'bg-info/10 text-info'
                                            : 'bg-warning/10 text-warning'
                                    "
                                    >{{ tool.method }}</span
                                >
                                <span
                                    class="font-mono text-xs text-slate-400"
                                    >{{ tool.fn }}</span
                                >
                            </div>
                            <p
                                class="mt-0.5 text-xs leading-relaxed text-slate-500"
                            >
                                {{ tool.description }}
                            </p>
                        </div>
                        <Button
                            variant="outline-primary"
                            size="sm"
                            class="shrink-0 rounded-[0.5rem] bg-white"
                            @click="openPlay(tool)"
                        >
                            <Lucide icon="Play" class="mr-1.5 h-3.5 w-3.5" />
                            Probar
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Tokens + conexión -->
            <div
                v-if="aiPlan.api_allowed"
                class="col-span-12 flex flex-col gap-6 xl:col-span-5"
            >
                <div class="box box--stacked">
                    <div
                        class="flex items-center justify-between border-b border-slate-200/60 p-5 dark:border-darkmode-400"
                    >
                        <div
                            class="flex items-center gap-2 text-base font-medium"
                        >
                            <Lucide
                                icon="KeyRound"
                                class="h-4 w-4 text-slate-400"
                            />
                            Tokens de acceso
                        </div>
                        <Button
                            variant="outline-primary"
                            size="sm"
                            class="rounded-[0.5rem] bg-white"
                            @click="showCreateToken = true"
                        >
                            <Lucide icon="Plus" class="mr-1 h-3.5 w-3.5" />
                            Emitir
                        </Button>
                    </div>
                    <div class="p-5">
                        <div v-if="tokens.length" class="space-y-2.5">
                            <div
                                v-for="t in tokens"
                                :key="t.id"
                                class="flex items-center gap-3 rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400"
                            >
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 dark:bg-darkmode-400"
                                >
                                    <Lucide icon="KeyRound" class="h-4 w-4" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium">
                                        {{ t.name }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        Creado {{ t.created_at }} ·
                                        {{
                                            t.last_used_at
                                                ? `usado ${t.last_used_at}`
                                                : 'sin usar'
                                        }}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    title="Revocar"
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                    @click="revoking = t"
                                >
                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center gap-3 py-8 text-center"
                        >
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Lucide icon="KeyRound" class="h-6 w-6" />
                            </div>
                            <p class="text-sm text-slate-500">
                                Sin tokens. Emite uno para conectar tu agente.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="box box--stacked p-5">
                    <div
                        class="mb-3 flex items-center gap-2 text-base font-medium"
                    >
                        <Lucide icon="Plug" class="h-4 w-4 text-slate-400" />
                        Cómo conectar
                    </div>
                    <div class="space-y-3 text-sm">
                        <div>
                            <div
                                class="mb-1 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Base URL
                            </div>
                            <code
                                class="block overflow-x-auto rounded-lg bg-slate-800 px-3 py-2 font-mono text-xs text-slate-100"
                                >{{ baseUrl }}</code
                            >
                        </div>
                        <div>
                            <div
                                class="mb-1 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Ejemplo
                            </div>
                            <pre
                                class="overflow-x-auto rounded-lg bg-slate-800 px-3 py-2 font-mono text-xs leading-relaxed text-slate-100"
                                >{{ curlExample }}</pre>
                        </div>
                        <div
                            class="flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <Lucide
                                icon="RefreshCw"
                                class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                            />
                            <span
                                >En
                                <span class="font-mono">POST /holds</span> manda
                                el header
                                <span class="font-mono font-medium"
                                    >Idempotency-Key</span
                                >: si el agente reintenta, no se duplican
                                apartados.</span
                            >
                        </div>
                        <div
                            class="flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <Lucide
                                icon="ShieldCheck"
                                class="mt-0.5 h-4 w-4 shrink-0 text-success"
                            />
                            <span
                                >El bot
                                <span class="font-medium"
                                    >nunca confirma ni cobra</span
                                >: solo consulta y crea apartados que expiran
                                solos. Todo queda auditado como "Asistente
                                IA".</span
                            >
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal playground -->
        <Dialog size="lg" :open="playing !== null" @close="playing = null">
            <Dialog.Panel>
                <div v-if="playing" class="flex max-h-[85vh] flex-col">
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full"
                            :class="playing.tone"
                        >
                            <Lucide :icon="playing.icon" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2
                                class="flex items-center gap-2 text-base font-medium"
                            >
                                {{ playing.title }}
                                <span
                                    class="rounded-md px-1.5 py-0.5 font-mono text-[10px] font-semibold"
                                    :class="
                                        playing.method === 'GET'
                                            ? 'bg-info/10 text-info'
                                            : 'bg-warning/10 text-warning'
                                    "
                                    >{{ playing.method }}</span
                                >
                            </h2>
                            <p class="mt-0.5 font-mono text-xs text-slate-500">
                                {{ baseUrl }}{{ playing.path }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="playing = null"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                        <template v-if="needsParams">
                            <div
                                v-if="playing.key !== 'reservation'"
                                class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-3"
                            >
                                <div>
                                    <label class="mb-1 block text-sm"
                                        >Tarifa (ID)</label
                                    >
                                    <FormInput
                                        v-model="playParams.rate_plan_id"
                                        type="number"
                                        min="1"
                                        placeholder="1"
                                    />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm"
                                        >Llegada</label
                                    >
                                    <FormInput
                                        v-model="playParams.starts_at"
                                        type="datetime-local"
                                    />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm"
                                        >Salida
                                        <span class="text-slate-400"
                                            >(auto)</span
                                        ></label
                                    >
                                    <FormInput
                                        v-model="playParams.ends_at"
                                        type="datetime-local"
                                    />
                                </div>
                            </div>
                            <div
                                v-if="playing.key === 'hold'"
                                class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2"
                            >
                                <div>
                                    <label class="mb-1 block text-sm"
                                        >Nombre del huésped</label
                                    >
                                    <FormInput
                                        v-model="playParams.guest_name"
                                        type="text"
                                    />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm"
                                        >Teléfono</label
                                    >
                                    <FormInput
                                        v-model="playParams.guest_phone"
                                        type="text"
                                        placeholder="+52…"
                                    />
                                </div>
                            </div>
                            <div v-if="playing.key === 'reservation'">
                                <label class="mb-1 block text-sm"
                                    >Código de reserva</label
                                >
                                <FormInput
                                    v-model="playParams.code"
                                    type="text"
                                    placeholder="RES-2026-0001"
                                />
                            </div>
                            <p
                                v-if="playing.key === 'hold'"
                                class="flex items-center gap-2 rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning"
                            >
                                <Lucide
                                    icon="TriangleAlert"
                                    class="h-4 w-4 shrink-0"
                                />
                                Crea un apartado real (expira solo en 30 min si
                                nadie lo confirma).
                            </p>
                        </template>
                        <p v-else class="text-sm text-slate-500">
                            Esta herramienta no requiere parámetros.
                        </p>

                        <div v-if="playResult !== null">
                            <div class="mb-1.5 flex items-center gap-2">
                                <span
                                    class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                                    >Respuesta</span
                                >
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        playStatus && playStatus < 300
                                            ? 'bg-success/10 text-success'
                                            : 'bg-danger/10 text-danger'
                                    "
                                    >HTTP {{ playStatus }}</span
                                >
                            </div>
                            <pre
                                class="max-h-72 overflow-auto rounded-lg bg-slate-800 px-4 py-3 font-mono text-xs leading-relaxed text-slate-200"
                                >{{ playResult }}</pre>
                        </div>
                    </div>

                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            variant="outline-secondary"
                            @click="playing = null"
                            >Cerrar</Button
                        >
                        <Button
                            variant="primary"
                            class="shadow-md shadow-primary/20"
                            :disabled="playBusy"
                            @click="runPlay"
                        >
                            <Lucide icon="Play" class="mr-2 h-4 w-4" />
                            {{ playBusy ? 'Ejecutando…' : 'Ejecutar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal crear token -->
        <Dialog :open="showCreateToken" @close="closeTokenModal">
            <Dialog.Panel>
                <div class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="KeyRound" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                {{
                                    newToken
                                        ? 'Token creado'
                                        : 'Emitir token de acceso'
                                }}
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                {{
                                    newToken
                                        ? 'Cópialo ahora: no se volverá a mostrar.'
                                        : 'Para conectar tu agente (WhatsApp, webchat, n8n…)'
                                }}
                            </p>
                        </div>
                    </div>

                    <template v-if="!newToken">
                        <div class="mt-5">
                            <label class="mb-1 block text-sm"
                                >Nombre del token</label
                            >
                            <FormInput
                                v-model="tokenName"
                                type="text"
                                placeholder="Bot WhatsApp producción"
                                @keydown.enter="createToken"
                            />
                        </div>
                        <div class="mt-6 flex justify-end gap-2">
                            <Button
                                variant="outline-secondary"
                                @click="closeTokenModal"
                                >Cancelar</Button
                            >
                            <Button
                                variant="primary"
                                class="shadow-md shadow-primary/20"
                                :disabled="saving"
                                @click="createToken"
                            >
                                <Lucide icon="Check" class="mr-2 h-4 w-4" />
                                {{ saving ? 'Creando…' : 'Crear token' }}
                            </Button>
                        </div>
                    </template>

                    <template v-else>
                        <div class="mt-5 flex items-center gap-2">
                            <code
                                class="flex-1 overflow-x-auto rounded-lg bg-slate-800 px-3 py-2.5 font-mono text-xs text-slate-200"
                                >{{ newToken }}</code
                            >
                            <Button
                                variant="outline-primary"
                                class="shrink-0 rounded-[0.5rem] bg-white"
                                @click="copyToken"
                            >
                                <Lucide
                                    :icon="copied ? 'Check' : 'Copy'"
                                    class="mr-1.5 h-4 w-4"
                                />
                                {{ copied ? 'Copiado' : 'Copiar' }}
                            </Button>
                        </div>
                        <p
                            class="mt-3 flex items-center gap-2 rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning"
                        >
                            <Lucide
                                icon="TriangleAlert"
                                class="h-4 w-4 shrink-0"
                            />
                            Guárdalo en un lugar seguro; por seguridad no
                            podremos mostrártelo de nuevo.
                        </p>
                        <div class="mt-6 flex justify-end">
                            <Button variant="primary" @click="closeTokenModal"
                                >Listo</Button
                            >
                        </div>
                    </template>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal proveedor de IA -->
        <Dialog
            size="lg"
            :open="showProviderForm"
            @close="showProviderForm = false"
        >
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="submitProvider">
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full"
                            :class="
                                providerTone[providerForm.provider] ??
                                'bg-primary/10 text-primary'
                            "
                        >
                            <Lucide icon="Sparkles" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                {{
                                    editingProvider
                                        ? `Editar ${editingProvider.label}`
                                        : 'Agregar proveedor de IA'
                                }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                La llave se guarda cifrada y es solo de este
                                hotel
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showProviderForm = false"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>
                    <div class="space-y-4 px-6 py-5">
                        <div v-if="!editingProvider">
                            <label class="mb-2 block text-sm">Proveedor</label>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                <label
                                    v-for="c in aiCatalog"
                                    :key="c.key"
                                    class="flex cursor-pointer items-center gap-2.5 rounded-lg border p-3 transition"
                                    :class="
                                        providerForm.provider === c.key
                                            ? 'border-primary/40 bg-primary/5'
                                            : 'border-slate-200/70 hover:bg-slate-50 dark:border-darkmode-400'
                                    "
                                >
                                    <input
                                        v-model="providerForm.provider"
                                        type="radio"
                                        :value="c.key"
                                        class="h-4 w-4 border-slate-300 text-primary focus:ring-primary/30"
                                    />
                                    <span class="min-w-0">
                                        <span
                                            class="block truncate text-sm font-medium"
                                            >{{ c.label }}</span
                                        >
                                        <span
                                            class="block truncate font-mono text-[10px] text-slate-400"
                                            >{{
                                                c.models[0]?.id ??
                                                c.placeholder_model
                                            }}</span
                                        >
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Modelo</label>
                            <FormSelect v-model="modelChoice" class="font-mono">
                                <optgroup
                                    v-for="g in modelGroups"
                                    :key="g.tier"
                                    :label="g.label"
                                >
                                    <option
                                        v-for="m in g.models"
                                        :key="m.id"
                                        :value="m.id"
                                    >
                                        {{ m.id }}
                                    </option>
                                </optgroup>
                                <option value="__custom">
                                    Otro (escribir manual)…
                                </option>
                            </FormSelect>
                            <div
                                v-if="modelChoice === '__custom'"
                                class="relative mt-2"
                            >
                                <Lucide
                                    icon="Cpu"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                                />
                                <FormInput
                                    v-model="providerForm.model"
                                    type="text"
                                    class="pl-9 font-mono"
                                    :placeholder="
                                        catalogFor(providerForm.provider)
                                            ?.placeholder_model
                                    "
                                />
                            </div>
                            <p class="mt-1 text-xs text-slate-400">
                                Puedes registrar el mismo proveedor con otro
                                modelo para compararlos.
                            </p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm"
                                >API key
                                {{
                                    editingProvider
                                        ? '(vacía = conservar la actual)'
                                        : ''
                                }}</label
                            >
                            <div class="relative">
                                <Lucide
                                    icon="KeyRound"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                                />
                                <FormInput
                                    v-model="providerForm.api_key"
                                    type="password"
                                    class="pl-9 font-mono"
                                    :placeholder="
                                        catalogFor(providerForm.provider)
                                            ?.key_hint
                                    "
                                    autocomplete="off"
                                />
                            </div>
                        </div>
                        <label
                            class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400"
                        >
                            <input
                                v-model="providerForm.active"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300 text-primary focus:ring-primary/30"
                            />
                            <span class="text-sm"
                                >Activo (entra a la cadena del bot)</span
                            >
                        </label>
                        <p
                            v-if="providerError"
                            class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                        >
                            {{ providerError }}
                        </p>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="showProviderForm = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="shadow-md shadow-primary/20"
                            :disabled="
                                saving ||
                                (!editingProvider && !providerForm.api_key)
                            "
                        >
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{ saving ? 'Guardando…' : 'Guardar proveedor' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal eliminar proveedor -->
        <Dialog
            :open="deletingProvider !== null"
            @close="deletingProvider = null"
        >
            <Dialog.Panel>
                <div v-if="deletingProvider" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                ¿Eliminar {{ deletingProvider.label }}?
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                Se borra su llave; el bot usará los proveedores
                                restantes.
                            </p>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="deletingProvider = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="saving"
                            @click="submitDeleteProvider"
                        >
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" /> Sí,
                            eliminar
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal conectar/editar canal Evolution -->
        <Dialog :open="showChannelForm" @close="showChannelForm = false">
            <Dialog.Panel>
                <form
                    class="flex max-h-[85vh] flex-col"
                    @submit.prevent="submitChannel"
                >
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                        >
                            <Lucide icon="MessageCircle" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                {{
                                    editingChannel
                                        ? `Editar ${channelName(editingChannel)}`
                                        : 'Conectar instancia de Evolution'
                                }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                La API key se guarda cifrada y es solo de este
                                hotel
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showChannelForm = false"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>
                    <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                        <div>
                            <label class="mb-1 block text-sm"
                                >Nombre
                                <span class="text-slate-400"
                                    >(opcional)</span
                                ></label
                            >
                            <FormInput
                                v-model="channelForm.name"
                                type="text"
                                placeholder="Recepción, Ventas..."
                            />
                        </div>
                        <template v-if="!editingChannel">
                            <div>
                                <label class="mb-1 block text-sm"
                                    >URL del servidor</label
                                >
                                <div class="relative">
                                    <Lucide
                                        icon="Server"
                                        class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                                    />
                                    <FormInput
                                        v-model="channelForm.base_url"
                                        type="url"
                                        class="pl-9"
                                        placeholder="https://evolution.midominio.com"
                                        required
                                    />
                                </div>
                                <FormHelp
                                    >La URL de tu instalación de Evolution API
                                    v2 en tu VPS</FormHelp
                                >
                            </div>
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Instancia</label
                                >
                                <FormInput
                                    v-model="channelForm.instance"
                                    type="text"
                                    class="font-mono"
                                    placeholder="hotel-demo"
                                    required
                                />
                                <FormHelp
                                    >El nombre exacto de la instancia en
                                    Evolution</FormHelp
                                >
                            </div>
                        </template>
                        <div>
                            <label class="mb-1 block text-sm">API key</label>
                            <div class="relative">
                                <Lucide
                                    icon="KeyRound"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                                />
                                <FormInput
                                    v-model="channelForm.api_key"
                                    type="password"
                                    class="pl-9 font-mono"
                                    :placeholder="
                                        editingChannel
                                            ? 'Dejar vacío para conservar la actual'
                                            : 'API key de tu servidor Evolution'
                                    "
                                    autocomplete="off"
                                />
                            </div>
                        </div>
                        <FormSwitch v-if="editingChannel">
                            <FormSwitch.Input
                                id="channel-active"
                                v-model="channelForm.active"
                                type="checkbox"
                            />
                            <FormSwitch.Label htmlFor="channel-active"
                                >Activo</FormSwitch.Label
                            >
                        </FormSwitch>
                        <p
                            v-if="channelError"
                            class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                        >
                            {{ channelError }}
                        </p>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="showChannelForm = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="shadow-md shadow-primary/20"
                            :disabled="
                                saving ||
                                (!editingChannel &&
                                    (!channelForm.base_url ||
                                        !channelForm.instance ||
                                        !channelForm.api_key))
                            "
                        >
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{
                                saving
                                    ? 'Guardando…'
                                    : editingChannel
                                      ? 'Guardar cambios'
                                      : 'Conectar'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal desconectar canal Evolution -->
        <Dialog
            :open="deletingChannel !== null"
            @close="deletingChannel = null"
        >
            <Dialog.Panel>
                <div v-if="deletingChannel" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                ¿Desconectar {{ channelName(deletingChannel) }}?
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                Las conversaciones y su historial se conservan
                                en la bandeja; solo se desconecta el número.
                            </p>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="deletingChannel = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="saving"
                            @click="submitDeleteChannel"
                        >
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" />
                            {{ saving ? 'Desconectando…' : 'Sí, desconectar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal conectar/editar WhatsApp Meta -->
        <Dialog :open="showMetaForm" @close="showMetaForm = false">
            <Dialog.Panel>
                <form class="p-6" @submit.prevent="submitMeta">
                    <div class="mb-4 flex items-center gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-info/10 text-info"
                        >
                            <Lucide icon="BadgeCheck" class="h-5 w-5" />
                        </div>
                        <h2 class="text-base font-medium">
                            {{
                                editingMeta
                                    ? 'Editar número de WhatsApp'
                                    : 'Conectar número por Meta'
                            }}
                        </h2>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm"
                                >Nombre (opcional)</label
                            >
                            <FormInput
                                v-model="metaForm.name"
                                type="text"
                                placeholder="WhatsApp del hotel"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm"
                                >phone_number_id</label
                            >
                            <FormInput
                                v-model="metaForm.external_id"
                                type="text"
                                placeholder="1055XXXXXXXXXXX"
                            />
                            <FormHelp
                                >El ID del número, en tu app de Meta →
                                WhatsApp → Configuración de la API.</FormHelp
                            >
                        </div>
                        <div>
                            <label class="mb-1 block text-sm"
                                >WhatsApp Business Account ID (opcional)</label
                            >
                            <FormInput
                                v-model="metaForm.waba_id"
                                type="text"
                                placeholder="WABA ID"
                            />
                            <FormHelp
                                >Recomendado: sin él no se puede reparar la
                                suscripción del webhook.</FormHelp
                            >
                        </div>
                        <div>
                            <label class="mb-1 block text-sm"
                                >Access token</label
                            >
                            <FormInput
                                v-model="metaForm.access_token"
                                type="password"
                                :placeholder="
                                    editingMeta
                                        ? 'Déjalo vacío para conservar el actual'
                                        : 'EAAG…'
                                "
                            />
                            <FormHelp
                                >Se guarda cifrado. En pruebas sirve el token
                                temporal (24 h) del panel de Meta.</FormHelp
                            >
                        </div>
                        <div
                            v-if="editingMeta"
                            class="flex items-center justify-between rounded-lg border border-dashed border-slate-300/70 px-3 py-2.5 dark:border-darkmode-400"
                        >
                            <span class="text-sm">Canal activo</span>
                            <FormSwitch>
                                <FormSwitch.Input
                                    :checked="metaForm.active"
                                    type="checkbox"
                                    @change="metaForm.active = !metaForm.active"
                                />
                            </FormSwitch>
                        </div>
                        <p
                            v-if="metaError"
                            class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                        >
                            {{ metaError }}
                        </p>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="showMetaForm = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            :disabled="
                                metaSaving ||
                                !metaForm.external_id ||
                                (!editingMeta && !metaForm.access_token)
                            "
                        >
                            {{
                                metaSaving
                                    ? 'Guardando…'
                                    : editingMeta
                                      ? 'Guardar'
                                      : 'Conectar'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal desconectar WhatsApp Meta -->
        <Dialog :open="deletingMeta !== null" @close="deletingMeta = null">
            <Dialog.Panel>
                <div v-if="deletingMeta" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                ¿Desconectar
                                {{ deletingMeta.name || 'este WhatsApp' }}?
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                El número deja de recibir y enviar por el bot;
                                el historial de conversaciones se conserva.
                            </p>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="deletingMeta = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="metaSaving"
                            @click="submitDeleteMeta"
                        >
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" />
                            {{
                                metaSaving ? 'Desconectando…' : 'Sí, desconectar'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal revocar token -->
        <Dialog :open="revoking !== null" @close="revoking = null">
            <Dialog.Panel>
                <div v-if="revoking" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                ¿Revocar "{{ revoking.name }}"?
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                El agente que use este token perderá acceso de
                                inmediato.
                            </p>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="revoking = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="saving"
                            @click="submitRevoke"
                        >
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" />
                            {{ saving ? 'Revocando…' : 'Sí, revocar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
