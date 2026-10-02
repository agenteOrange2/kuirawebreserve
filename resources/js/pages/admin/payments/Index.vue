<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect, FormSwitch } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface MethodRow {
    method: string;
    label: string;
    enabled: boolean;
    tenants_enabled: number;
    charging: string[];
}

interface TenantMethod {
    enabled: boolean;
    platform: boolean;
    own: boolean | null;
}

interface GatewayRow {
    id: number;
    provider: string;
    provider_label: string;
    mode: string;
    active: boolean;
    in_use: boolean;
    blocked_by: 'platform' | 'tenant' | null;
    last_event_at: string | null;
    stale: boolean;
}

interface TenantRow {
    id: string;
    name: string;
    suspended: boolean;
    methods: Record<string, TenantMethod>;
    has_overrides: boolean;
    charging: {
        id: number;
        provider: string;
        provider_label: string;
        mode: string;
    } | null;
    gateways: GatewayRow[];
}

interface OrphanRow {
    id: number;
    tenant_id: string;
    provider_label: string;
    mode: string;
    last_event_at: string | null;
}

const props = defineProps<{
    methods: MethodRow[];
    tenants: TenantRow[];
    orphans: OrphanRow[];
    staleDays: number;
}>();

const toast = useToasts();

// Copia local para revertir el interruptor si el PATCH falla.
const methods = ref<MethodRow[]>([]);
watch(
    () => props.methods,
    (rows) => (methods.value = rows.map((m) => ({ ...m }))),
    { immediate: true },
);

const rowAction =
    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition';
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';

const methodMeta: Record<
    string,
    { icon: Icon; tone: string; description: string; short: string }
> = {
    transfer: {
        icon: 'Landmark',
        tone: 'border-primary/10 bg-primary/10 text-primary',
        description:
            'Cuentas bancarias del hotel; el personal verifica el comprobante.',
        short: 'Transferencia',
    },
    stripe: {
        icon: 'CreditCard',
        tone: 'border-info/10 bg-info/10 text-info',
        description: 'Checkout hospedado; se confirma solo por webhook.',
        short: 'Stripe',
    },
    mercadopago: {
        icon: 'Wallet',
        tone: 'border-success/10 bg-success/10 text-success',
        description: 'Checkout hospedado; se confirma solo por webhook.',
        short: 'Mercado Pago',
    },
    paypal: {
        icon: 'BadgeDollarSign',
        tone: 'border-pending/10 bg-pending/10 text-pending',
        description: 'Checkout de PayPal; se confirma solo por webhook.',
        short: 'PayPal',
    },
    cash: {
        icon: 'Banknote',
        tone: 'border-warning/10 bg-warning/10 text-warning',
        description:
            'El huésped aparta sin pagar en línea y paga al llegar; cada hotel lo activa en sus ajustes.',
        short: 'En el hotel',
    },
};
const fallbackMeta = {
    icon: 'CreditCard' as Icon,
    tone: 'border-slate-200 bg-slate-100 text-slate-500',
    description: 'Método de cobro en línea.',
    short: 'Otro',
};
const metaFor = (method: string) => methodMeta[method] ?? fallbackMeta;

// ── Cifras ──
const stats = computed(() => {
    const allGateways = props.tenants.flatMap((t) => t.gateways);
    return {
        methodsOn: methods.value.filter((m) => m.enabled).length,
        charging: props.tenants.filter((t) => t.charging).length,
        testMode: props.tenants.filter((t) => t.charging?.mode === 'test')
            .length,
        stale: allGateways.filter((g) => g.stale).length,
    };
});

// ── Interruptores globales ──
// Encender es inmediato; apagar le pega a todos los hoteles y se confirma.
const confirmingOff = ref<MethodRow | null>(null);
const toggling = ref(false);

function onToggle(m: MethodRow): void {
    if (m.enabled) {
        confirmingOff.value = m;
        return;
    }
    void setMethod(m, true);
}

async function setMethod(m: MethodRow, enabled: boolean): Promise<void> {
    toggling.value = true;
    m.enabled = enabled;
    try {
        await axios.patch(route('admin.payments.methods'), {
            method: m.method,
            enabled,
        });
        toast.success(
            enabled ? 'Método encendido' : 'Método apagado',
            `${m.label} para toda la plataforma.`,
        );
        confirmingOff.value = null;
        router.reload({ only: ['methods', 'tenants'] });
    } catch (e: any) {
        m.enabled = !enabled;
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        toggling.value = false;
    }
}

// ── Hoteles ──
const search = ref('');
type TenantFilter = '' | 'test' | 'none' | 'own' | 'stale';
const filter = ref<TenantFilter>('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.tenants.filter((t) => {
        if (filter.value === 'test' && t.charging?.mode !== 'test')
            return false;
        if (filter.value === 'none' && t.charging) return false;
        if (filter.value === 'own' && !t.has_overrides) return false;
        if (filter.value === 'stale' && !t.gateways.some((g) => g.stale))
            return false;
        if (!q) return true;
        return (
            t.name.toLowerCase().includes(q) || t.id.toLowerCase().includes(q)
        );
    });
});

function shortcut(value: TenantFilter): void {
    filter.value = filter.value === value ? '' : value;
}

function methodTitle(key: string, m: TenantMethod): string {
    const label = metaFor(key).short;
    if (m.enabled) return `${label}: encendido`;
    if (!m.platform) return `${label}: apagado para toda la plataforma`;
    return `${label}: apagado por este hotel`;
}

function gatewayState(g: GatewayRow): { label: string; tone: string } {
    if (!g.active)
        return {
            label: 'Pausada',
            tone: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
        };
    if (g.blocked_by === 'platform')
        return { label: 'Método apagado', tone: 'bg-danger/10 text-danger' };
    if (g.blocked_by === 'tenant')
        return {
            label: 'Apagada por el hotel',
            tone: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
        };
    if (g.in_use)
        return { label: 'En uso', tone: 'bg-success/10 text-success' };
    return {
        label: 'De respaldo',
        tone: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    };
}

// ── Huérfanas ──
const removingOrphan = ref<OrphanRow | null>(null);
const removingBusy = ref(false);

async function confirmRemoveOrphan(): Promise<void> {
    if (!removingOrphan.value) return;
    removingBusy.value = true;
    try {
        await axios.delete(
            route('admin.payments.gateways.destroy', removingOrphan.value.id),
        );
        toast.success('Pasarela quitada');
        removingOrphan.value = null;
        router.reload({ only: ['orphans'] });
    } catch (e: any) {
        toast.error(
            'No se pudo quitar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        removingBusy.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Pagos">
        <div class="mt-2">
            <!-- Encabezado -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="CreditCard" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Pagos</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Qué métodos existen en la plataforma y con qué cobra
                            de verdad cada hotel.
                        </p>
                    </div>
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
                        <Lucide icon="ToggleRight" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.methodsOn }} de {{ methods.length }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Métodos encendidos
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Para toda la plataforma
                        </div>
                    </div>
                </div>
                <button
                    type="button"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 text-left transition hover:border-slate-300 xl:col-span-3"
                    :class="filter === 'none' ? 'ring-2 ring-success/40' : ''"
                    title="Ver los que no cobran en línea"
                    @click="shortcut('none')"
                >
                    <div
                        :class="sectionIcon"
                        class="border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="PlugZap" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.charging }} de {{ tenants.length }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Cobran en línea
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Con una pasarela lista para cobrar
                        </div>
                    </div>
                </button>
                <button
                    type="button"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 text-left transition hover:border-slate-300 xl:col-span-3"
                    :class="filter === 'test' ? 'ring-2 ring-danger/40' : ''"
                    title="Ver los que cobran en modo prueba"
                    @click="shortcut('test')"
                >
                    <div
                        :class="sectionIcon"
                        class="border-danger/10 bg-danger/10 text-danger"
                    >
                        <Lucide icon="FlaskConical" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div
                            class="text-sm font-medium"
                            :class="stats.testMode ? 'text-danger' : ''"
                        >
                            {{ stats.testMode }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Cobrando en modo prueba
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Sus ligas no cobran dinero real
                        </div>
                    </div>
                </button>
                <button
                    type="button"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 text-left transition hover:border-slate-300 xl:col-span-3"
                    :class="filter === 'stale' ? 'ring-2 ring-warning/40' : ''"
                    title="Ver los que tienen pasarelas sin latido"
                    @click="shortcut('stale')"
                >
                    <div
                        :class="sectionIcon"
                        class="border-warning/10 bg-warning/10 text-warning"
                    >
                        <Lucide icon="Activity" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">{{ stats.stale }}</div>
                        <div class="text-xs leading-tight text-slate-500">
                            Pasarelas sin latido
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Activas y sin eventos en {{ staleDays }} días
                        </div>
                    </div>
                </button>
            </div>

            <!-- Métodos de la plataforma -->
            <div class="box box--stacked mt-4 overflow-hidden">
                <div :class="cardHeader">
                    <div
                        :class="sectionIcon"
                        class="border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ToggleRight" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-medium">
                            Métodos de la plataforma
                        </h2>
                        <p class="text-xs text-slate-500">
                            Apagado aquí, el método desaparece para todos los
                            hoteles: el bot no lo ofrece y lo conectado queda
                            sin cobrar.
                        </p>
                    </div>
                </div>
                <div
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="m in methods"
                        :key="m.method"
                        class="flex items-center gap-3 px-4 py-3 sm:px-5"
                    >
                        <div
                            :class="[
                                sectionIcon,
                                metaFor(m.method).tone,
                                m.enabled ? '' : 'opacity-50',
                            ]"
                        >
                            <Lucide
                                :icon="metaFor(m.method).icon"
                                class="h-4 w-4"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div
                                class="flex min-w-0 flex-wrap items-center gap-2"
                            >
                                <span
                                    class="text-sm font-medium"
                                    :class="m.enabled ? '' : 'text-slate-400'"
                                    >{{ m.label }}</span
                                >
                                <span
                                    v-if="!m.enabled"
                                    class="rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                    >Apagado para todos</span
                                >
                            </div>
                            <p class="text-xs text-slate-500">
                                {{ metaFor(m.method).description }}
                            </p>
                        </div>
                        <div
                            class="hidden w-48 shrink-0 text-right text-xs text-slate-500 md:block"
                        >
                            <div>
                                {{ m.tenants_enabled }}
                                {{
                                    m.tenants_enabled === 1
                                        ? 'hotel lo tiene'
                                        : 'hoteles lo tienen'
                                }}
                            </div>
                            <div
                                v-if="m.charging.length"
                                class="truncate text-[11px] text-slate-400"
                                :title="m.charging.join(', ')"
                            >
                                Cobra{{
                                    m.charging.length === 1 ? '' : 'n'
                                }}
                                con él: {{ m.charging.join(', ') }}
                            </div>
                        </div>
                        <FormSwitch
                            class="shrink-0"
                            :title="
                                m.enabled ? 'Apagar para todos' : 'Encender'
                            "
                        >
                            <FormSwitch.Input
                                :checked="m.enabled"
                                type="checkbox"
                                :disabled="toggling"
                                @click.prevent="onToggle(m)"
                            />
                        </FormSwitch>
                    </div>
                </div>
            </div>

            <!-- Huérfanas -->
            <div
                v-if="orphans.length"
                class="box box--stacked mt-4 overflow-hidden"
            >
                <div :class="cardHeader">
                    <div
                        :class="sectionIcon"
                        class="border-pending/10 bg-pending/10 text-pending"
                    >
                        <Lucide icon="TriangleAlert" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-medium">
                            Pasarelas de hoteles que ya no existen
                        </h2>
                        <p class="text-xs text-slate-500">
                            Nadie cobra con ellas, pero guardan llaves cifradas.
                            Conviene quitarlas.
                        </p>
                    </div>
                </div>
                <div
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="o in orphans"
                        :key="o.id"
                        class="flex items-center gap-3 px-4 py-3 sm:px-5"
                    >
                        <div class="min-w-0 flex-1 text-xs">
                            <div
                                class="text-sm font-medium text-slate-600 dark:text-slate-300"
                            >
                                {{ o.provider_label }}
                                <span
                                    class="ml-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        o.mode === 'test'
                                            ? 'bg-warning/10 text-warning'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                    >{{
                                        o.mode === 'test' ? 'Prueba' : 'Real'
                                    }}</span
                                >
                            </div>
                            <div class="text-slate-500">
                                Del hotel "{{ o.tenant_id }}" ·
                                {{
                                    o.last_event_at
                                        ? `último evento ${o.last_event_at}`
                                        : 'sin eventos'
                                }}
                            </div>
                        </div>
                        <button
                            type="button"
                            :class="rowAction"
                            class="hover:bg-danger/10 hover:text-danger"
                            title="Quitar pasarela"
                            @click="removingOrphan = o"
                        >
                            <Lucide icon="Trash2" class="h-4 w-4" />
                        </button>
                    </div>
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
                    <FormSelect v-model="filter" class="h-9 text-xs sm:w-60">
                        <option value="">Todos los hoteles</option>
                        <option value="test">Cobrando en modo prueba</option>
                        <option value="none">Sin pasarela para cobrar</option>
                        <option value="stale">Con pasarelas sin latido</option>
                        <option value="own">Con métodos apagados por él</option>
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
                        class="flex flex-col gap-3 px-4 py-3 transition hover:bg-slate-50/70 sm:px-5 lg:flex-row lg:items-start lg:gap-4 dark:hover:bg-darkmode-400/30"
                    >
                        <!-- Hotel y con qué cobra -->
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <div
                                :class="sectionIcon"
                                class="border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400"
                            >
                                <Lucide icon="Building2" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div
                                    class="flex min-w-0 flex-wrap items-center gap-2"
                                >
                                    <Link
                                        :href="
                                            route(
                                                'admin.tenants.payments',
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
                                        v-if="t.suspended"
                                        class="rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                        >Suspendido</span
                                    >
                                </div>
                                <div
                                    v-if="t.charging?.mode === 'test'"
                                    class="flex items-center gap-1 text-xs text-danger"
                                >
                                    <Lucide
                                        icon="FlaskConical"
                                        class="h-3.5 w-3.5 shrink-0"
                                    />
                                    Cobra con
                                    {{ t.charging.provider_label }} en modo
                                    prueba: no entra dinero real
                                </div>
                                <div
                                    v-else-if="t.charging"
                                    class="flex items-center gap-1 text-xs text-slate-500"
                                >
                                    <Lucide
                                        icon="CircleCheck"
                                        class="h-3.5 w-3.5 shrink-0 text-success"
                                    />
                                    Cobra con {{ t.charging.provider_label }}
                                </div>
                                <div
                                    v-else
                                    class="flex items-center gap-1 text-xs text-slate-400"
                                >
                                    <Lucide
                                        icon="CircleMinus"
                                        class="h-3.5 w-3.5 shrink-0"
                                    />
                                    {{
                                        t.gateways.length
                                            ? 'Tiene pasarela, pero ninguna puede cobrar'
                                            : 'Sin pasarela en línea'
                                    }}
                                </div>
                            </div>
                        </div>

                        <!-- Métodos efectivos -->
                        <div
                            class="flex items-center gap-1 pl-12 lg:w-40 lg:shrink-0 lg:pt-1.5 lg:pl-0"
                        >
                            <span
                                v-for="(m, key) in t.methods"
                                :key="key"
                                class="relative flex h-7 w-7 items-center justify-center rounded-full border"
                                :class="
                                    m.enabled
                                        ? metaFor(String(key)).tone
                                        : 'border-slate-200 bg-white text-slate-300 dark:border-darkmode-400 dark:bg-darkmode-600'
                                "
                                :title="methodTitle(String(key), m)"
                            >
                                <Lucide
                                    :icon="metaFor(String(key)).icon"
                                    class="h-3.5 w-3.5"
                                />
                                <span
                                    v-if="!m.enabled && m.platform"
                                    class="absolute h-px w-5 rotate-45 bg-slate-300"
                                />
                            </span>
                        </div>

                        <!-- Pasarelas -->
                        <div class="min-w-0 pl-12 lg:w-80 lg:shrink-0 lg:pl-0">
                            <div
                                v-for="g in t.gateways"
                                :key="g.id"
                                class="flex min-w-0 flex-wrap items-center gap-1.5 py-0.5 text-xs"
                            >
                                <span class="font-medium">{{
                                    g.provider_label
                                }}</span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        g.mode === 'test'
                                            ? 'bg-warning/10 text-warning'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                    >{{
                                        g.mode === 'test' ? 'Prueba' : 'Real'
                                    }}</span
                                >
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="gatewayState(g).tone"
                                    >{{ gatewayState(g).label }}</span
                                >
                                <span
                                    class="text-[11px]"
                                    :class="
                                        g.stale
                                            ? 'text-warning'
                                            : 'text-slate-400'
                                    "
                                    :title="
                                        g.stale
                                            ? `Sin eventos en ${staleDays} días: revisa el webhook`
                                            : undefined
                                    "
                                    >{{
                                        g.last_event_at
                                            ? `latido ${g.last_event_at}`
                                            : 'sin eventos'
                                    }}</span
                                >
                            </div>
                            <span
                                v-if="!t.gateways.length"
                                class="text-xs text-slate-400"
                                >Ninguna conectada</span
                            >
                        </div>

                        <div class="hidden shrink-0 lg:flex lg:items-center">
                            <Link
                                :href="route('admin.tenants.payments', t.id)"
                                :class="rowAction"
                                class="hover:bg-primary/10 hover:text-primary"
                                title="Ver los cobros de este hotel"
                            >
                                <Lucide icon="ArrowRight" class="h-4 w-4" />
                            </Link>
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

        <!-- Confirmar apagado global -->
        <Dialog :open="confirmingOff !== null" @close="confirmingOff = null">
            <Dialog.Panel>
                <div v-if="confirmingOff" class="p-5">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                        >
                            <Lucide icon="PowerOff" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <Dialog.Title
                                class="block border-0 p-0 text-base font-medium"
                                >Apagar {{ confirmingOff.label }} para
                                todos</Dialog.Title
                            >
                            <p class="mt-0.5 text-xs text-slate-500">
                                Desaparece del bot, de los wizards y de los
                                paneles de los
                                {{ confirmingOff.tenants_enabled }}
                                {{
                                    confirmingOff.tenants_enabled === 1
                                        ? 'hotel que lo tiene'
                                        : 'hoteles que lo tienen'
                                }}
                                encendido. Se puede volver a encender.
                            </p>
                        </div>
                    </div>
                    <div
                        v-if="confirmingOff.charging.length"
                        class="mt-4 rounded-lg border border-dashed border-danger/30 bg-danger/5 px-3 py-2.5 text-xs text-danger"
                    >
                        Dejan de poder cobrar en línea:
                        <span class="font-medium">{{
                            confirmingOff.charging.join(', ')
                        }}</span>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            @click="confirmingOff = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="toggling"
                            @click="setMethod(confirmingOff, false)"
                        >
                            <Lucide
                                icon="PowerOff"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            {{ toggling ? 'Apagando...' : 'Sí, apagar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmar quitar huérfana -->
        <Dialog :open="removingOrphan !== null" @close="removingOrphan = null">
            <Dialog.Panel>
                <div v-if="removingOrphan" class="p-5">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <Dialog.Title
                                class="block border-0 p-0 text-base font-medium"
                                >Quitar {{ removingOrphan.provider_label }} de
                                "{{ removingOrphan.tenant_id }}"</Dialog.Title
                            >
                            <p class="mt-0.5 text-xs text-slate-500">
                                Ese hotel ya no existe. Se borran la pasarela y
                                sus llaves; no se puede deshacer.
                            </p>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            @click="removingOrphan = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="removingBusy"
                            @click="confirmRemoveOrphan"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            {{ removingBusy ? 'Quitando...' : 'Sí, quitar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
