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
import ProviderFormModal from './ai/ProviderFormModal.vue';
import TenantAiModal from './ai/TenantAiModal.vue';
import type { CatalogEntry, ProviderRow, TenantAiRow } from './ai/types';
import {
    axiosMessage,
    channelIcon,
    effectiveLimit,
    providerTone,
    usagePercent,
} from './ai/types';

const props = defineProps<{
    providers: ProviderRow[];
    catalog: CatalogEntry[];
    tenants: TenantAiRow[];
}>();

const toast = useToasts();

// Copias locales: los interruptores y el orden se ven al instante y se
// revierten si el servidor dice que no. Cada recarga las vuelve a alinear.
const providers = ref<ProviderRow[]>([]);
const tenants = ref<TenantAiRow[]>([]);
watch(
    () => props.providers,
    (rows) => (providers.value = rows.map((r) => ({ ...r }))),
    { immediate: true },
);
watch(
    () => props.tenants,
    (rows) => (tenants.value = rows.map((r) => ({ ...r }))),
    { immediate: true },
);

const rowAction =
    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition';
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';

// ── Cifras ──
const activeProviders = computed(() => providers.value.filter((p) => p.active));
const stats = computed(() => {
    const withAi = tenants.value.filter((t) => t.ai_available);
    return {
        withAi: withAi.length,
        botOn: withAi.filter((t) => t.enabled && !t.suspended).length,
        replies: tenants.value.reduce((s, t) => s + t.used_replies, 0),
        tokens: tenants.value.reduce((s, t) => s + t.used_tokens, 0),
        nearLimit: withAi.filter((t) => usagePercent(t) >= 80).length,
    };
});
const fmt = (n: number) => n.toLocaleString('es-MX');

// ── Keys maestras ──
const formOpen = ref(false);
const editing = ref<ProviderRow | null>(null);

function openForm(p: ProviderRow | null = null): void {
    editing.value = p;
    formOpen.value = true;
}

function onProviderSaved(): void {
    formOpen.value = false;
    toast.success('Key guardada');
    router.reload({ only: ['providers'] });
}

async function toggleProvider(p: ProviderRow): Promise<void> {
    const next = !p.active;
    p.active = next;
    try {
        await axios.patch(route('admin.ai.providers.update', p.id), {
            active: next,
        });
        toast.success(next ? 'Key activada' : 'Key pausada', p.label);
    } catch (e) {
        p.active = !next;
        toast.error('No se pudo cambiar', axiosMessage(e, 'Ocurrió un error.'));
    }
}

const reordering = ref(false);
async function move(index: number, delta: -1 | 1): Promise<void> {
    const target = index + delta;
    if (target < 0 || target >= providers.value.length) return;
    const before = [...providers.value];
    const next = [...providers.value];
    [next[index], next[target]] = [next[target], next[index]];
    providers.value = next;
    reordering.value = true;
    try {
        await axios.post(route('admin.ai.providers.reorder'), {
            ids: next.map((p) => p.id),
        });
    } catch (e) {
        providers.value = before;
        toast.error(
            'No se pudo reordenar',
            axiosMessage(e, 'Ocurrió un error.'),
        );
    } finally {
        reordering.value = false;
    }
}

const testResults = reactive<
    Record<number, { ok: boolean; ms: number; text: string } | 'loading'>
>({});
async function testProvider(p: ProviderRow): Promise<void> {
    testResults[p.id] = 'loading';
    try {
        const { data } = await axios.post(
            route('admin.ai.providers.test', p.id),
        );
        testResults[p.id] = {
            ok: true,
            ms: data.ms,
            text: `Respondió "${data.reply}" · ${data.tokens} tokens`,
        };
    } catch (e: any) {
        const d = e.response?.data;
        testResults[p.id] = {
            ok: false,
            ms: d?.ms ?? 0,
            text: d?.error ?? axiosMessage(e, 'Error de conexión'),
        };
    }
}
const testOf = (id: number) => {
    const r = testResults[id];
    return r && r !== 'loading' ? r : null;
};

const deleting = ref<ProviderRow | null>(null);
const deletingBusy = ref(false);
const assignedTo = (id: number) =>
    tenants.value.filter((t) => t.provider_id === id);

async function confirmDelete(): Promise<void> {
    if (!deleting.value) return;
    deletingBusy.value = true;
    try {
        await axios.delete(
            route('admin.ai.providers.destroy', deleting.value.id),
        );
        deleting.value = null;
        toast.success('Key eliminada');
        router.reload({ only: ['providers', 'tenants'] });
    } catch (e) {
        toast.error(
            'No se pudo eliminar',
            axiosMessage(e, 'Ocurrió un error.'),
        );
    } finally {
        deletingBusy.value = false;
    }
}

// ── Hoteles ──
const search = ref('');
type TenantFilter = '' | 'on' | 'off' | 'no_ai' | 'near';
const filter = ref<TenantFilter>('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return tenants.value.filter((t) => {
        if (filter.value === 'on' && !(t.ai_available && t.enabled))
            return false;
        if (filter.value === 'off' && !(t.ai_available && !t.enabled))
            return false;
        if (filter.value === 'no_ai' && t.ai_available) return false;
        if (
            filter.value === 'near' &&
            !(t.ai_available && usagePercent(t) >= 80)
        )
            return false;
        if (!q) return true;
        return (
            t.name.toLowerCase().includes(q) ||
            t.id.toLowerCase().includes(q) ||
            (t.domain ?? '').toLowerCase().includes(q)
        );
    });
});

function shortcut(value: TenantFilter): void {
    filter.value = filter.value === value ? '' : value;
}

const providerName = (t: TenantAiRow) => {
    if (!t.provider_id) return 'Automático';
    const p = providers.value.find((x) => x.id === t.provider_id);
    return p ? `${p.label} · ${p.model}` : 'Automático';
};

async function toggleBot(t: TenantAiRow): Promise<void> {
    const next = !t.enabled;
    t.enabled = next;
    try {
        await axios.patch(route('admin.ai.tenants.update', t.id), {
            enabled: next,
        });
        toast.success(next ? 'Bot encendido' : 'Bot apagado', t.name);
    } catch (e) {
        t.enabled = !next;
        toast.error('No se pudo guardar', axiosMessage(e, 'Ocurrió un error.'));
    }
}

const configuringId = ref<string | null>(null);
const configuring = computed(
    () => tenants.value.find((t) => t.id === configuringId.value) ?? null,
);

function onTenantSaved(payload: Partial<TenantAiRow>): void {
    const t = configuring.value;
    if (t) {
        Object.assign(t, payload);
        toast.success('Asistente guardado', t.name);
    }
    configuringId.value = null;
}
</script>

<template>
    <RazeLayout title="Agentes IA">
        <div class="mt-2">
            <!-- Encabezado -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Bot" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-base font-medium">Agentes IA</h1>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="
                                    activeProviders.length
                                        ? 'bg-success/10 text-success'
                                        : 'bg-danger/10 text-danger'
                                "
                            >
                                <span
                                    class="h-1.5 w-1.5 rounded-full"
                                    :class="
                                        activeProviders.length
                                            ? 'bg-success'
                                            : 'bg-danger'
                                    "
                                />
                                {{
                                    activeProviders.length
                                        ? 'Operando'
                                        : 'Sin keys activas'
                                }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Keys maestras de la plataforma y el asistente de
                            cada hotel.
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:shrink-0 md:items-center md:gap-2"
                >
                    <Button
                        variant="primary"
                        class="col-span-2 h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        @click="openForm()"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Nueva key maestra
                    </Button>
                </div>
            </div>

            <!-- Cifras -->
            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        :class="sectionIcon"
                        class="border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="KeyRound" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ activeProviders.length }} de
                            {{ providers.length }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Keys activas
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            {{
                                activeProviders[0]
                                    ? `Primera: ${activeProviders[0].label}`
                                    : 'Ningún bot puede contestar'
                            }}
                        </div>
                    </div>
                </div>
                <button
                    type="button"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 text-left transition hover:border-slate-300 xl:col-span-3"
                    :class="filter === 'on' ? 'ring-2 ring-success/40' : ''"
                    @click="shortcut('on')"
                >
                    <div
                        :class="sectionIcon"
                        class="border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="Bot" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.botOn }} de {{ stats.withAi }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Bots encendidos
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Hoteles con IA y bot activo
                        </div>
                    </div>
                </button>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        :class="sectionIcon"
                        class="border-info/10 bg-info/10 text-info"
                    >
                        <Lucide icon="MessagesSquare" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ fmt(stats.replies) }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Respuestas del mes
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            {{ fmt(stats.tokens) }} tokens
                        </div>
                    </div>
                </div>
                <button
                    type="button"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 text-left transition hover:border-slate-300 xl:col-span-3"
                    :class="filter === 'near' ? 'ring-2 ring-warning/40' : ''"
                    @click="shortcut('near')"
                >
                    <div
                        :class="sectionIcon"
                        class="border-warning/10 bg-warning/10 text-warning"
                    >
                        <Lucide icon="Gauge" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.nearLimit }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Cerca del tope
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            80% o más de su cuota
                        </div>
                    </div>
                </button>
            </div>

            <!-- Keys maestras -->
            <div class="box box--stacked mt-4 overflow-hidden">
                <div :class="cardHeader">
                    <div
                        :class="sectionIcon"
                        class="border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="KeyRound" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-medium">Keys maestras</h2>
                        <p class="text-xs text-slate-500">
                            Los hoteles en automático las prueban de arriba
                            abajo hasta que una responde.
                        </p>
                    </div>
                </div>

                <div
                    v-if="providers.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="(p, index) in providers"
                        :key="p.id"
                        class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:px-5"
                    >
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <span
                                class="w-5 shrink-0 text-center text-xs font-medium text-slate-400"
                                :title="
                                    p.active
                                        ? `Lugar ${index + 1} de la cadena`
                                        : 'Pausada: la cadena la salta'
                                "
                                >{{ index + 1 }}</span
                            >
                            <div
                                :class="[
                                    sectionIcon,
                                    providerTone[p.provider] ??
                                        'border-slate-200 bg-slate-100 text-slate-500',
                                    p.active ? '' : 'opacity-50',
                                ]"
                            >
                                <Lucide icon="Sparkles" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="truncate text-sm font-medium"
                                        :class="
                                            p.active ? '' : 'text-slate-400'
                                        "
                                        >{{ p.label }}</span
                                    >
                                    <span
                                        class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="
                                            p.active
                                                ? 'bg-success/10 text-success'
                                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                        "
                                        >{{
                                            p.active ? 'Activa' : 'Pausada'
                                        }}</span
                                    >
                                    <span
                                        v-if="assignedTo(p.id).length"
                                        class="hidden shrink-0 text-[11px] text-slate-400 sm:inline"
                                        >·
                                        {{ assignedTo(p.id).length }}
                                        {{
                                            assignedTo(p.id).length === 1
                                                ? 'hotel fijo'
                                                : 'hoteles fijos'
                                        }}</span
                                    >
                                </div>
                                <div
                                    class="flex min-w-0 items-center gap-2 text-xs text-slate-500"
                                >
                                    <span class="truncate font-mono">{{
                                        p.model
                                    }}</span>
                                    <span
                                        class="shrink-0 font-mono text-slate-400"
                                        >{{ p.masked_key }}</span
                                    >
                                </div>
                                <div
                                    v-if="testResults[p.id] === 'loading'"
                                    class="mt-0.5 flex items-center gap-1 text-[11px] text-slate-500"
                                >
                                    <Lucide
                                        icon="LoaderCircle"
                                        class="h-3 w-3 animate-spin"
                                    />
                                    Probando conexión...
                                </div>
                                <div
                                    v-else-if="testOf(p.id)"
                                    class="mt-0.5 flex min-w-0 items-start gap-1 text-[11px]"
                                    :class="
                                        testOf(p.id)!.ok
                                            ? 'text-success'
                                            : 'text-danger'
                                    "
                                >
                                    <Lucide
                                        :icon="
                                            testOf(p.id)!.ok
                                                ? 'CircleCheck'
                                                : 'TriangleAlert'
                                        "
                                        class="mt-px h-3 w-3 shrink-0"
                                    />
                                    <span class="min-w-0 break-words"
                                        >{{ testOf(p.id)!.ms }} ms ·
                                        {{ testOf(p.id)!.text }}</span
                                    >
                                </div>
                            </div>
                        </div>

                        <div
                            class="flex items-center justify-end gap-1 pl-8 sm:shrink-0 sm:pl-0"
                        >
                            <button
                                type="button"
                                :class="rowAction"
                                class="hover:bg-slate-100 disabled:pointer-events-none disabled:opacity-30 dark:hover:bg-darkmode-400"
                                title="Subir en la cadena"
                                :disabled="index === 0 || reordering"
                                @click="move(index, -1)"
                            >
                                <Lucide icon="ArrowUp" class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                :class="rowAction"
                                class="hover:bg-slate-100 disabled:pointer-events-none disabled:opacity-30 dark:hover:bg-darkmode-400"
                                title="Bajar en la cadena"
                                :disabled="
                                    index === providers.length - 1 || reordering
                                "
                                @click="move(index, 1)"
                            >
                                <Lucide icon="ArrowDown" class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                :class="rowAction"
                                class="hover:bg-primary/10 hover:text-primary disabled:pointer-events-none disabled:opacity-40"
                                title="Probar la key con una pregunta real"
                                :disabled="testResults[p.id] === 'loading'"
                                @click="testProvider(p)"
                            >
                                <Lucide icon="Zap" class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                :class="rowAction"
                                class="hover:bg-primary/10 hover:text-primary"
                                title="Editar modelo o llave"
                                @click="openForm(p)"
                            >
                                <Lucide icon="Pencil" class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                :class="rowAction"
                                class="hover:bg-danger/10 hover:text-danger"
                                title="Eliminar"
                                @click="deleting = p"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </button>
                            <FormSwitch
                                class="ml-1 shrink-0"
                                :title="p.active ? 'Pausar' : 'Activar'"
                            >
                                <FormSwitch.Input
                                    :checked="p.active"
                                    type="checkbox"
                                    @change="toggleProvider(p)"
                                />
                            </FormSwitch>
                        </div>
                    </div>
                </div>
                <div
                    v-else
                    class="flex flex-col items-center gap-2 px-6 py-10 text-center"
                >
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="KeyRound" class="h-4 w-4" />
                    </div>
                    <p class="max-w-md text-xs text-slate-500">
                        Da de alta una key maestra (Anthropic, ChatGPT,
                        DeepSeek, Kimi o MiniMax). Con ellas contestan los bots
                        de todos los hoteles que tengan IA.
                    </p>
                    <Button
                        variant="outline-primary"
                        class="mt-1 h-9 rounded-[0.5rem] text-xs"
                        @click="openForm()"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Nueva key maestra
                    </Button>
                </div>
            </div>

            <!-- Hoteles -->
            <div class="box box--stacked mt-4 overflow-hidden">
                <div
                    class="flex flex-col gap-2 border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 sm:flex-row sm:items-center dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="relative sm:w-72">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                        />
                        <FormInput
                            v-model="search"
                            type="text"
                            class="h-9 pl-9 text-xs"
                            placeholder="Buscar hotel o subdominio"
                        />
                    </div>
                    <FormSelect v-model="filter" class="h-9 text-xs sm:w-52">
                        <option value="">Todos los hoteles</option>
                        <option value="on">Bot encendido</option>
                        <option value="off">Bot apagado</option>
                        <option value="near">Cerca del tope</option>
                        <option value="no_ai">Sin IA</option>
                    </FormSelect>
                    <span class="text-xs text-slate-500 sm:ml-auto">
                        {{ filtered.length }}
                        {{ filtered.length === 1 ? 'hotel' : 'hoteles' }}
                    </span>
                </div>

                <div
                    v-if="filtered.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="t in filtered"
                        :key="t.id"
                        class="flex flex-col gap-3 px-4 py-3 transition hover:bg-slate-50/70 sm:px-5 lg:flex-row lg:items-center lg:gap-4 dark:hover:bg-darkmode-400/30"
                    >
                        <!-- Hotel -->
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <div
                                :class="sectionIcon"
                                class="border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400"
                            >
                                <Lucide icon="Building2" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="flex min-w-0 items-center gap-2">
                                    <Link
                                        :href="
                                            route(
                                                'admin.tenants.assistant',
                                                t.id,
                                            )
                                        "
                                        class="truncate text-sm font-medium hover:text-primary"
                                        :class="
                                            t.suspended
                                                ? 'text-slate-400 line-through'
                                                : ''
                                        "
                                        >{{ t.name }}</Link
                                    >
                                    <span
                                        class="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                        >{{ t.plan_label }}</span
                                    >
                                    <span
                                        v-if="t.ai_from_addon"
                                        class="hidden shrink-0 rounded-full bg-info/10 px-2 py-0.5 text-[11px] font-medium text-info sm:inline"
                                        title="La IA llega por un servicio adicional, no por el plan"
                                        >IA por servicio</span
                                    >
                                    <span
                                        v-if="t.suspended"
                                        class="shrink-0 rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                        >Suspendido</span
                                    >
                                </div>
                                <div
                                    class="flex min-w-0 items-center gap-2 text-xs text-slate-500"
                                >
                                    <span class="truncate">{{
                                        t.domain ?? t.id
                                    }}</span>
                                    <Link
                                        :href="
                                            route(
                                                'admin.tenants.channels',
                                                t.id,
                                            )
                                        "
                                        class="inline-flex shrink-0 items-center gap-1 hover:text-primary"
                                        title="Canales del hotel"
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
                                                class="h-3.5 w-3.5"
                                                :class="
                                                    c.active
                                                        ? 'text-success'
                                                        : 'text-slate-300'
                                                "
                                                :title="`${c.label}${c.last_event_at ? ' · último evento hace ' + c.last_event_at : ' · sin eventos'}`"
                                            />
                                        </template>
                                        <span v-else class="text-primary"
                                            >Sin canales</span
                                        >
                                    </Link>
                                </div>
                            </div>
                        </div>

                        <template v-if="t.ai_available">
                            <!-- Uso -->
                            <div
                                class="pl-12 text-xs lg:w-44 lg:shrink-0 lg:pl-0"
                            >
                                <div class="flex items-baseline gap-1">
                                    <span class="font-medium">{{
                                        fmt(t.used_replies)
                                    }}</span>
                                    <span class="text-slate-400"
                                        >/
                                        {{
                                            effectiveLimit(t) === null
                                                ? 'sin límite'
                                                : fmt(effectiveLimit(t)!)
                                        }}</span
                                    >
                                    <span
                                        v-if="t.monthly_reply_limit !== null"
                                        class="text-[11px] text-slate-400"
                                        title="Cuota fijada a mano para este hotel"
                                        >· a mano</span
                                    >
                                </div>
                                <div
                                    class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 lg:w-36 dark:bg-darkmode-400"
                                >
                                    <div
                                        class="h-full rounded-full"
                                        :class="
                                            usagePercent(t) >= 90
                                                ? 'bg-danger'
                                                : usagePercent(t) >= 75
                                                  ? 'bg-warning'
                                                  : 'bg-primary/70'
                                        "
                                        :style="{
                                            width: `${usagePercent(t)}%`,
                                        }"
                                    />
                                </div>
                                <div class="mt-0.5 text-[11px] text-slate-400">
                                    {{ fmt(t.used_tokens) }} tokens
                                </div>
                            </div>

                            <!-- Proveedor y permisos -->
                            <div
                                class="flex min-w-0 flex-wrap items-center gap-1 pl-12 lg:w-56 lg:shrink-0 lg:pl-0"
                            >
                                <span
                                    class="max-w-full truncate text-xs text-slate-600 dark:text-slate-300"
                                    :title="providerName(t)"
                                    >{{ providerName(t) }}</span
                                >
                                <span
                                    v-if="t.byok_allowed"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                    title="Puede usar sus propias keys"
                                    >BYOK</span
                                >
                                <span
                                    v-if="t.api_allowed"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                    title="Ve la Agent API en su panel"
                                    >API</span
                                >
                            </div>

                            <!-- Acciones -->
                            <div
                                class="flex items-center justify-end gap-1 lg:w-28 lg:shrink-0"
                            >
                                <FormSwitch
                                    class="shrink-0"
                                    :title="
                                        t.enabled
                                            ? 'Apagar el bot'
                                            : 'Encender el bot'
                                    "
                                >
                                    <FormSwitch.Input
                                        :checked="t.enabled"
                                        type="checkbox"
                                        @change="toggleBot(t)"
                                    />
                                </FormSwitch>
                                <button
                                    type="button"
                                    :class="rowAction"
                                    class="ml-1 hover:bg-primary/10 hover:text-primary"
                                    title="Configurar key, cuota y permisos"
                                    @click="configuringId = t.id"
                                >
                                    <Lucide
                                        icon="SlidersHorizontal"
                                        class="h-4 w-4"
                                    />
                                </button>
                                <Link
                                    :href="
                                        route('admin.tenants.assistant', t.id)
                                    "
                                    :class="rowAction"
                                    class="hover:bg-primary/10 hover:text-primary"
                                    title="Instrucciones y prompt del bot"
                                >
                                    <Lucide icon="Eye" class="h-4 w-4" />
                                </Link>
                            </div>
                        </template>

                        <!-- Sin IA: ni plan, ni servicio, ni ajuste -->
                        <div
                            v-else
                            class="flex items-center justify-between gap-3 pl-12 lg:w-[30rem] lg:shrink-0 lg:pl-0"
                        >
                            <span class="text-xs text-slate-400">
                                Sin IA: su plan no la incluye ni tiene el
                                servicio contratado.
                            </span>
                            <Link
                                :href="route('admin.tenants.modules', t.id)"
                                class="shrink-0 text-xs font-medium text-primary hover:underline"
                                >Ver módulos</Link
                            >
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
                        <Lucide icon="Building2" class="h-4 w-4" />
                    </div>
                    <p class="text-xs text-slate-500">
                        {{
                            tenants.length
                                ? 'Ningún hotel coincide con el filtro.'
                                : 'Aún no hay hoteles registrados.'
                        }}
                    </p>
                </div>
            </div>
        </div>

        <ProviderFormModal
            :open="formOpen"
            :provider="editing"
            :catalog="catalog"
            @close="formOpen = false"
            @saved="onProviderSaved"
        />

        <TenantAiModal
            :tenant="configuring"
            :providers="providers"
            @close="configuringId = null"
            @saved="onTenantSaved"
        />

        <!-- Confirmar borrado de key -->
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
                                >Eliminar {{ deleting.label }}</Dialog.Title
                            >
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{
                                    assignedTo(deleting.id).length
                                        ? `${assignedTo(deleting.id).length} ${assignedTo(deleting.id).length === 1 ? 'hotel la tiene fija y pasa' : 'hoteles la tienen fija y pasan'} a la cadena automática.`
                                        : 'Ningún hotel la tiene fija.'
                                }}
                                {{
                                    deleting.active &&
                                    activeProviders.length === 1
                                        ? 'Es la única key activa: los bots dejarán de contestar.'
                                        : ''
                                }}
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
