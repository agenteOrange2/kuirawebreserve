<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect, FormSwitch } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ProviderRow {
    id: number;
    provider: string;
    label: string;
    model: string;
    masked_key: string;
    active: boolean;
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
interface TenantRow {
    id: string;
    name: string;
    domain: string | null;
    plan: string;
    plan_label: string;
    plan_ai_enabled: boolean;
    plan_ai_limit: number | null;
    enabled: boolean;
    provider_id: number | null;
    monthly_reply_limit: number | null;
    byok_allowed: boolean;
    api_allowed: boolean;
    used_replies: number;
    used_tokens: number;
    suspended: boolean;
    channels: {
        type: string;
        label: string;
        active: boolean;
        last_event_at: string | null;
    }[];
}

const props = defineProps<{
    providers: ProviderRow[];
    catalog: CatalogEntry[];
    tenants: TenantRow[];
}>();

const toast = useToasts();

const providerTone: Record<string, string> = {
    anthropic: 'bg-pending/10 text-pending',
    openai: 'bg-success/10 text-success',
    deepseek: 'bg-info/10 text-info',
    kimi: 'bg-primary/10 text-primary',
    minimax: 'bg-warning/10 text-warning',
};

const saving = ref(false);

// ── Proveedores de plataforma ──
const showForm = ref(false);
const editing = ref<ProviderRow | null>(null);
const form = reactive({
    provider: 'anthropic',
    model: '',
    api_key: '',
    active: true,
});
const formError = ref<string | null>(null);
const catalogFor = (key: string) => props.catalog.find((c) => c.key === key);

// Modelos sugeridos del proveedor elegido, agrupados por nivel; el modelo
// se elige de la lista y "__custom" libera el campo de texto manual.
const tierLabels: Record<string, string> = {
    new: 'Los más nuevos',
    mid: 'Intermedios',
    cheap: 'Económicos',
};
const modelChoice = ref('');
const modelGroups = computed(() => {
    const models = catalogFor(form.provider)?.models ?? [];
    return (['new', 'mid', 'cheap'] as const)
        .map((tier) => ({
            tier,
            label: tierLabels[tier],
            models: models.filter((m) => m.tier === tier),
        }))
        .filter((g) => g.models.length);
});

watch(modelChoice, (v) => {
    // "__custom" no toca el campo: conserva lo escrito (o lo guardado al editar).
    if (v !== '__custom') form.model = v;
});
watch(
    () => form.provider,
    (key) => {
        // Al cambiar de proveedor, proponer su primer modelo (el más nuevo).
        if (!editing.value)
            modelChoice.value = catalogFor(key)?.models[0]?.id ?? '__custom';
    },
);

function openCreate() {
    editing.value = null;
    form.provider = 'anthropic';
    form.api_key = '';
    form.active = true;
    formError.value = null;
    modelChoice.value = catalogFor('anthropic')?.models[0]?.id ?? '__custom';
    form.model = modelChoice.value === '__custom' ? '' : modelChoice.value;
    showForm.value = true;
}
function openEdit(p: ProviderRow) {
    editing.value = p;
    form.provider = p.provider;
    form.model = p.model;
    form.api_key = '';
    form.active = p.active;
    formError.value = null;
    // Si el modelo guardado está en el catálogo se selecciona; si no, manual.
    const known = catalogFor(p.provider)?.models.some((m) => m.id === p.model);
    modelChoice.value = known ? p.model : '__custom';
    showForm.value = true;
}

async function submitProvider() {
    saving.value = true;
    formError.value = null;
    try {
        const model =
            form.model || catalogFor(form.provider)?.placeholder_model || '';
        if (editing.value) {
            await axios.patch(
                route('admin.ai.providers.update', editing.value.id),
                { model, api_key: form.api_key || null, active: form.active },
            );
        } else {
            await axios.post(route('admin.ai.providers.store'), {
                provider: form.provider,
                model,
                api_key: form.api_key,
                active: form.active,
            });
        }
        showForm.value = false;
        toast.success('Proveedor guardado');
        router.reload({ only: ['providers'] });
    } catch (e: any) {
        formError.value =
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

async function toggleProvider(p: ProviderRow) {
    await axios.patch(route('admin.ai.providers.update', p.id), {
        active: !p.active,
    });
    p.active = !p.active;
}

const deleting = ref<ProviderRow | null>(null);
async function submitDelete() {
    if (!deleting.value) return;
    saving.value = true;
    try {
        await axios.delete(
            route('admin.ai.providers.destroy', deleting.value.id),
        );
        deleting.value = null;
        toast.success('Proveedor eliminado');
        router.reload({ only: ['providers', 'tenants'] });
    } finally {
        saving.value = false;
    }
}

const testResults = reactive<
    Record<number, { ok: boolean; ms: number; text: string } | 'loading'>
>({});
async function testProvider(p: ProviderRow) {
    testResults[p.id] = 'loading';
    try {
        const { data } = await axios.post(
            route('admin.ai.providers.test', p.id),
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

// ── Canales conectados por hotel (iconos; la gestión vive en admin.ai.channels) ──
const channelIcon: Record<string, string> = {
    whatsapp: 'MessageCircle',
    whatsapp_evo: 'MessageCircle',
    messenger: 'Facebook',
    instagram: 'Instagram',
};

// ── Configuración por tenant ──
async function patchTenant(t: TenantRow, payload: Record<string, unknown>) {
    try {
        await axios.patch(route('admin.ai.tenants.update', t.id), payload);
        Object.assign(t, payload);
        toast.success('Configuración guardada', t.name);
    } catch (e: any) {
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    }
}

const effectiveLimit = (t: TenantRow) =>
    t.monthly_reply_limit ?? t.plan_ai_limit;
const usagePercent = (t: TenantRow) => {
    const limit = effectiveLimit(t);
    if (!limit) return 0;
    return Math.min(100, Math.round((t.used_replies / limit) * 100));
};

const cellClass =
    'box shadow-[5px_3px_5px_#00000005] first:border-l last:border-r first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] rounded-l-none rounded-r-none border-x-0 dark:bg-darkmode-600';
</script>

<template>
    <RazeLayout title="Agentes IA">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-lg font-medium">
                            Agentes IA de la plataforma
                        </h1>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                providers.some((p) => p.active)
                                    ? 'bg-success/10 text-success'
                                    : 'bg-danger/10 text-danger'
                            "
                        >
                            <span
                                class="h-1.5 w-1.5 rounded-full"
                                :class="
                                    providers.some((p) => p.active)
                                        ? 'bg-success'
                                        : 'bg-danger'
                                "
                            />
                            {{
                                providers.some((p) => p.active)
                                    ? 'Operando'
                                    : 'Sin keys maestras'
                            }}
                        </span>
                    </div>
                    <p class="text-sm text-slate-500">
                        Keys maestras, asignación por hotel, cuotas y consumo —
                        la IA es producto de la plataforma
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        variant="primary"
                        class="rounded-[0.5rem] shadow-md shadow-primary/20"
                        @click="openCreate"
                    >
                        <Lucide icon="Plus" class="mr-2 h-4 w-4 stroke-[1.3]" />
                        Key maestra
                    </Button>
                </div>
            </div>

            <!-- Proveedores de plataforma -->
            <div class="mt-5">
                <div class="flex items-center gap-2 md:h-10">
                    <Lucide
                        icon="KeyRound"
                        class="h-4 w-4 stroke-[1.5] text-primary"
                    />
                    <div class="text-base font-medium">Keys maestras</div>
                </div>
                <div
                    v-if="providers.length"
                    class="mt-2 grid grid-cols-12 gap-5"
                >
                    <div
                        v-for="p in providers"
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
                                @click="openEdit(p)"
                            >
                                <Lucide icon="Pencil" class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                title="Eliminar"
                                class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                @click="deleting = p"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
                <div
                    v-else
                    class="box box--stacked mt-2 flex flex-col items-center gap-3 py-10 text-center"
                >
                    <div
                        class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"
                    >
                        <Lucide icon="Sparkles" class="h-6 w-6" />
                    </div>
                    <p class="max-w-md text-sm text-slate-500">
                        Da de alta tus keys maestras (Anthropic, ChatGPT,
                        DeepSeek, Kimi, MiniMax). Con ellas operan los bots de
                        todos los hoteles con plan que incluya IA.
                    </p>
                    <Button
                        variant="primary"
                        size="sm"
                        class="rounded-[0.5rem]"
                        @click="openCreate"
                        ><Lucide icon="Plus" class="mr-1.5 h-4 w-4" /> Agregar
                        key</Button
                    >
                </div>
            </div>

            <!-- Tenants -->
            <div class="mt-8">
                <div class="flex items-center gap-2 md:h-10">
                    <Lucide
                        icon="Building2"
                        class="h-4 w-4 stroke-[1.5] text-primary"
                    />
                    <div class="text-base font-medium">Hoteles</div>
                    <span
                        class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                        >{{ tenants.length }}</span
                    >
                </div>
                <div class="mt-2 overflow-auto lg:overflow-visible">
                    <table
                        v-if="tenants.length"
                        class="w-full min-w-[1100px] border-separate border-spacing-y-[8px] text-sm"
                    >
                        <thead>
                            <tr>
                                <th
                                    class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500"
                                >
                                    Hotel
                                </th>
                                <th
                                    class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500"
                                >
                                    Plan
                                </th>
                                <th
                                    class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500"
                                >
                                    Canales
                                </th>
                                <th
                                    class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500"
                                >
                                    Bot
                                </th>
                                <th
                                    class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500"
                                >
                                    Proveedor asignado
                                </th>
                                <th
                                    class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500"
                                >
                                    Cuota / mes
                                </th>
                                <th
                                    class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500"
                                >
                                    Uso del mes
                                </th>
                                <th
                                    class="border-b-0 px-5 pb-1 text-center text-xs font-medium text-slate-500"
                                >
                                    BYOK
                                </th>
                                <th
                                    class="border-b-0 px-5 pb-1 text-center text-xs font-medium text-slate-500"
                                    title="Tokens y playground de la Agent API en el panel del hotel"
                                >
                                    API
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="t in tenants" :key="t.id">
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <div
                                        class="font-medium"
                                        :class="{
                                            'text-slate-400 line-through':
                                                t.suspended,
                                        }"
                                    >
                                        {{ t.name }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ t.domain ?? '—' }}
                                    </div>
                                </td>
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <span
                                        class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary capitalize"
                                        >{{ t.plan_label }}</span
                                    >
                                    <div
                                        class="mt-1 text-[10px] text-slate-400"
                                    >
                                        {{
                                            t.plan_ai_enabled
                                                ? `IA incluida · ${t.plan_ai_limit ?? '∞'} resp/mes`
                                                : 'Plan sin IA'
                                        }}
                                    </div>
                                </td>
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <Link
                                        :href="route('admin.ai.channels', t.id)"
                                        title="Gestionar canales de este hotel"
                                        class="inline-flex items-center gap-1.5"
                                    >
                                        <template v-if="t.channels.length">
                                            <Lucide
                                                v-for="(c, i) in t.channels"
                                                :key="i"
                                                :icon="
                                                    (channelIcon[
                                                        c.type
                                                    ] as any) ?? 'MessageCircle'
                                                "
                                                class="h-4 w-4 stroke-[1.5]"
                                                :class="
                                                    c.active
                                                        ? 'text-success'
                                                        : 'text-slate-300'
                                                "
                                                :title="`${c.label}${c.last_event_at ? ' · último evento hace ' + c.last_event_at : ' · sin eventos'}`"
                                            />
                                        </template>
                                        <span
                                            v-else
                                            class="inline-flex items-center gap-1 text-xs text-primary"
                                        >
                                            <Lucide
                                                icon="Plus"
                                                class="h-3.5 w-3.5"
                                            />
                                            Conectar
                                        </span>
                                    </Link>
                                </td>
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <div class="flex items-center gap-1.5">
                                        <FormSwitch>
                                            <FormSwitch.Input
                                                :checked="t.enabled"
                                                :disabled="!t.plan_ai_enabled"
                                                type="checkbox"
                                                @change="
                                                    patchTenant(t, {
                                                        enabled: !t.enabled,
                                                    })
                                                "
                                            />
                                        </FormSwitch>
                                        <button
                                            type="button"
                                            title="Ver contexto del bot"
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                                            @click="
                                                router.visit(
                                                    route(
                                                        'admin.ai.tenants.context',
                                                        t.id,
                                                    ),
                                                )
                                            "
                                        >
                                            <Lucide
                                                icon="Eye"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                    </div>
                                </td>
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <FormSelect
                                        :model-value="t.provider_id ?? ''"
                                        :disabled="!t.plan_ai_enabled"
                                        class="!w-44 !py-1.5 text-xs"
                                        @update:model-value="
                                            (v: string) =>
                                                patchTenant(t, {
                                                    platform_ai_provider_id:
                                                        v || null,
                                                })
                                        "
                                    >
                                        <option value="">Auto (cadena)</option>
                                        <option
                                            v-for="p in providers"
                                            :key="p.id"
                                            :value="p.id"
                                        >
                                            {{ p.label }} · {{ p.model }}
                                        </option>
                                    </FormSelect>
                                </td>
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <FormInput
                                        :model-value="
                                            t.monthly_reply_limit ?? ''
                                        "
                                        :disabled="!t.plan_ai_enabled"
                                        type="number"
                                        min="0"
                                        class="!w-24 !py-1.5 text-xs"
                                        :placeholder="
                                            String(t.plan_ai_limit ?? '∞')
                                        "
                                        @change="
                                            (e: Event) =>
                                                patchTenant(t, {
                                                    monthly_reply_limit:
                                                        (
                                                            e.target as HTMLInputElement
                                                        ).value === ''
                                                            ? null
                                                            : Number(
                                                                  (
                                                                      e.target as HTMLInputElement
                                                                  ).value,
                                                              ),
                                                })
                                        "
                                    />
                                </td>
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <div
                                        class="flex items-center gap-2 text-sm"
                                    >
                                        <span class="font-medium">{{
                                            t.used_replies
                                        }}</span>
                                        <span class="text-xs text-slate-400"
                                            >/
                                            {{ effectiveLimit(t) ?? '∞' }}</span
                                        >
                                    </div>
                                    <div
                                        class="mt-1 h-1.5 w-24 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                                    >
                                        <div
                                            class="h-full rounded-full"
                                            :class="
                                                usagePercent(t) >= 90
                                                    ? 'bg-danger'
                                                    : 'bg-primary/70'
                                            "
                                            :style="{
                                                width: `${usagePercent(t)}%`,
                                            }"
                                        />
                                    </div>
                                    <div
                                        class="mt-0.5 text-[10px] text-slate-400"
                                    >
                                        {{ t.used_tokens.toLocaleString() }}
                                        tokens
                                    </div>
                                </td>
                                <td
                                    :class="cellClass"
                                    class="px-5 py-3.5 text-center"
                                >
                                    <FormSwitch class="justify-center">
                                        <FormSwitch.Input
                                            :checked="t.byok_allowed"
                                            type="checkbox"
                                            @change="
                                                patchTenant(t, {
                                                    byok_allowed:
                                                        !t.byok_allowed,
                                                })
                                            "
                                        />
                                    </FormSwitch>
                                </td>
                                <td
                                    :class="cellClass"
                                    class="px-5 py-3.5 text-center"
                                >
                                    <FormSwitch class="justify-center">
                                        <FormSwitch.Input
                                            :checked="t.api_allowed"
                                            type="checkbox"
                                            @change="
                                                patchTenant(t, {
                                                    api_allowed: !t.api_allowed,
                                                })
                                            "
                                        />
                                    </FormSwitch>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div
                        v-else
                        class="box box--stacked py-10 text-center text-sm text-slate-500"
                    >
                        Sin hoteles registrados.
                    </div>
                </div>
                <p class="mt-2 flex items-center gap-2 text-xs text-slate-400">
                    <Lucide icon="Info" class="h-3.5 w-3.5" />
                    Cuota vacía = la del plan. "Auto" prueba las keys activas en
                    orden (fallback). BYOK = el hotel usa sus propias llaves (no
                    consume cuota). API = ve tokens y playground de
                    integraciones en su panel.
                </p>
            </div>
        </div>

        <!-- Modal key maestra -->
        <Dialog size="lg" :open="showForm" @close="showForm = false">
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="submitProvider">
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full"
                            :class="
                                providerTone[form.provider] ??
                                'bg-primary/10 text-primary'
                            "
                        >
                            <Lucide icon="Sparkles" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                {{
                                    editing
                                        ? `Editar ${editing.label}`
                                        : 'Nueva key maestra'
                                }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Con esta llave operan los bots de los hoteles
                                (se guarda cifrada)
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showForm = false"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>
                    <div class="space-y-4 px-6 py-5">
                        <div v-if="!editing">
                            <label class="mb-2 block text-sm">Proveedor</label>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                <label
                                    v-for="c in catalog"
                                    :key="c.key"
                                    class="flex cursor-pointer items-center gap-2.5 rounded-lg border p-3 transition"
                                    :class="
                                        form.provider === c.key
                                            ? 'border-primary/40 bg-primary/5'
                                            : 'border-slate-200/70 hover:bg-slate-50 dark:border-darkmode-400'
                                    "
                                >
                                    <input
                                        v-model="form.provider"
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
                                    v-model="form.model"
                                    type="text"
                                    class="pl-9 font-mono"
                                    :placeholder="
                                        catalogFor(form.provider)
                                            ?.placeholder_model
                                    "
                                />
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm"
                                >API key
                                {{
                                    editing
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
                                    v-model="form.api_key"
                                    type="password"
                                    class="pl-9 font-mono"
                                    :placeholder="
                                        catalogFor(form.provider)?.key_hint
                                    "
                                    autocomplete="off"
                                />
                            </div>
                        </div>
                        <p
                            v-if="formError"
                            class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                        >
                            {{ formError }}
                        </p>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="showForm = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="shadow-md shadow-primary/20"
                            :disabled="saving || (!editing && !form.api_key)"
                        >
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{ saving ? 'Guardando…' : 'Guardar' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal eliminar -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div v-if="deleting" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                ¿Eliminar {{ deleting.label }}?
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                Los hoteles que lo tengan asignado pasarán a la
                                cadena automática.
                            </p>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="deleting = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="saving"
                            @click="submitDelete"
                            ><Lucide icon="Trash2" class="mr-2 h-4 w-4" /> Sí,
                            eliminar</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
