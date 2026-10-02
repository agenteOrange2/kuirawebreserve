<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormCheck, FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import RazeLayout from '@/layouts/RazeLayout.vue';

type Severity = 'danger' | 'warning' | 'info';
type State = 'open' | 'snoozed' | 'dismissed' | 'resolved';

interface AlertRow {
    id: number;
    type: string;
    type_label: string;
    severity: Severity;
    title: string;
    body: string | null;
    url: string | null;
    tenant: { id: string; name: string; exists: boolean } | null;
    read: boolean;
    first_seen_ago: string | null;
    first_seen_at: string | null;
    last_seen_ago: string | null;
    resolved_ago: string | null;
    snoozed_until: string | null;
    dismissed_ago: string | null;
    dismissed_by: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    alerts: {
        data: AlertRow[];
        links: PaginationLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        state: State;
        severity?: Severity | null;
        type?: string | null;
        tenant?: string | null;
        q?: string | null;
    };
    counts: {
        danger: number;
        warning: number;
        info: number;
        unread: number;
        open: number;
        snoozed: number;
        dismissed: number;
        resolved_week: number;
    };
    types: { value: string; label: string }[];
    tenants: { value: string; label: string }[];
    lastScan: string | null;
}>();

const ghostButton =
    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition';

const severityMeta: Record<
    Severity,
    { label: string; tone: string; dot: string }
> = {
    danger: {
        label: 'Urgente',
        tone: 'border-danger/10 bg-danger/10 text-danger',
        dot: 'bg-danger',
    },
    warning: {
        label: 'Por atender',
        tone: 'border-warning/10 bg-warning/10 text-warning',
        dot: 'bg-warning',
    },
    info: {
        label: 'Informativa',
        tone: 'border-info/10 bg-info/10 text-info',
        dot: 'bg-info',
    },
};

const typeIcon: Record<string, Icon> = {
    ai_quota: 'Gauge',
    ai_providers: 'BotOff',
    new_tenant: 'Building2',
    new_prospect: 'UserPlus',
    module_request: 'PackageOpen',
    gateway_test: 'FlaskConical',
    orphan_gateway: 'Unlink',
    channel_silent: 'Radio',
    tenant_unreachable: 'DatabaseZap',
    no_owner: 'UserX',
    plan_cap: 'Gauge',
    undelivered: 'MessageSquareWarning',
    guests_waiting: 'Hourglass',
};

const stateTabs = computed(() => [
    { value: 'open' as State, label: 'Abiertas', count: props.counts.open },
    {
        value: 'snoozed' as State,
        label: 'Pospuestas',
        count: props.counts.snoozed,
    },
    {
        value: 'dismissed' as State,
        label: 'Descartadas',
        count: props.counts.dismissed,
    },
    { value: 'resolved' as State, label: 'Resueltas', count: null },
]);

// ── Filtros: viven en la URL, el servidor pagina ──
const search = ref(props.filters.q ?? '');
const type = ref(props.filters.type ?? '');
const tenant = ref(props.filters.tenant ?? '');
const severity = ref(props.filters.severity ?? '');

function visit(overrides: Record<string, string | null> = {}) {
    const params: Record<string, string> = {};
    const merged: Record<string, string | null> = {
        state: props.filters.state,
        q: search.value.trim() || null,
        type: type.value || null,
        tenant: tenant.value || null,
        severity: severity.value || null,
        ...overrides,
    };
    Object.entries(merged).forEach(([k, v]) => {
        if (v && !(k === 'state' && v === 'open')) params[k] = v;
    });
    selected.value = [];
    router.get(route('admin.alerts'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => visit(), 350);
});
onBeforeUnmount(() => clearTimeout(searchTimer));
watch([type, tenant, severity], () => visit());

const hasFilters = computed(
    () => !!(search.value || type.value || tenant.value || severity.value),
);

function clearFilters() {
    clearTimeout(searchTimer);
    search.value = '';
    type.value = '';
    tenant.value = '';
    severity.value = '';
}

function pickSeverity(value: Severity) {
    if (props.filters.state !== 'open') {
        severity.value = value;
        visit({ state: 'open', severity: value });
        return;
    }
    severity.value = severity.value === value ? '' : value;
}

const pageLabel = (label: string) =>
    label.includes('&laquo;') || label.toLowerCase().includes('previous')
        ? 'prev'
        : label.includes('&raquo;') || label.toLowerCase().includes('next')
          ? 'next'
          : label;

// ── Selección y acciones ──
const selected = ref<number[]>([]);
const allSelected = computed(
    () =>
        props.alerts.data.length > 0 &&
        props.alerts.data.every((a) => selected.value.includes(a.id)),
);
function toggleAll() {
    selected.value = allSelected.value
        ? []
        : props.alerts.data.map((a) => a.id);
}
function toggleOne(id: number) {
    selected.value = selected.value.includes(id)
        ? selected.value.filter((x) => x !== id)
        : [...selected.value, id];
}

const busy = ref(false);

function act(
    ids: number[],
    action: 'read' | 'unread' | 'snooze' | 'dismiss' | 'restore',
    hours?: number,
) {
    if (!ids.length || busy.value) return;
    busy.value = true;
    router.patch(
        route('admin.alerts.update'),
        { ids, action, hours: hours ?? null },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = selected.value.filter(
                    (id) => !ids.includes(id),
                );
                snoozing.value = null;
            },
            onFinish: () => (busy.value = false),
        },
    );
}

// Abrir el aviso lo da por leído y lleva a donde se atiende.
async function open(alert: AlertRow) {
    if (!alert.url) return;
    if (!alert.read) {
        try {
            await axios.patch(route('admin.alerts.update'), {
                ids: [alert.id],
                action: 'read',
            });
        } catch {
            /* abrir no debe depender de marcar */
        }
    }
    router.visit(alert.url);
}

const scanning = ref(false);
function scanNow() {
    scanning.value = true;
    router.post(
        route('admin.alerts.scan'),
        {},
        {
            preserveScroll: true,
            onFinish: () => (scanning.value = false),
        },
    );
}

function readAll() {
    busy.value = true;
    router.post(
        route('admin.alerts.read-all'),
        {},
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}

// ── Posponer ──
const snoozing = ref<number[] | null>(null);
const snoozeOptions = [
    { hours: 1, label: '1 hora' },
    { hours: 4, label: '4 horas' },
    { hours: 24, label: '1 día' },
    { hours: 72, label: '3 días' },
    { hours: 168, label: '1 semana' },
];

const emptyText = computed(() => {
    if (hasFilters.value) return 'Ningún aviso coincide con los filtros.';
    return {
        open: 'Todo en orden: no hay nada que pida tu atención.',
        snoozed: 'No hay avisos pospuestos.',
        dismissed: 'No hay avisos descartados.',
        resolved: 'Todavía no se ha resuelto ningún aviso.',
    }[props.filters.state];
});
</script>

<template>
    <RazeLayout title="Notificaciones">
        <div class="mt-2">
            <!-- Encabezado -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border"
                        :class="
                            counts.danger
                                ? 'border-danger/10 bg-danger/10 text-danger'
                                : 'border-primary/10 bg-primary/10 text-primary'
                        "
                    >
                        <Lucide icon="Bell" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Notificaciones</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Lo que pasa en los hoteles y necesita a la
                            plataforma. Se revisa sola cada 10 minutos<template
                                v-if="lastScan"
                                >; la última, {{ lastScan }}</template
                            >.
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Button
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] text-xs"
                        :disabled="busy || !counts.unread"
                        @click="readAll"
                    >
                        <Lucide icon="CheckCheck" class="mr-1.5 h-3.5 w-3.5" />
                        Marcar todo leído
                    </Button>
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        :disabled="scanning"
                        @click="scanNow"
                    >
                        <Lucide
                            icon="RefreshCw"
                            class="mr-1.5 h-3.5 w-3.5"
                            :class="{ 'animate-spin': scanning }"
                        />
                        {{ scanning ? 'Revisando...' : 'Revisar ahora' }}
                    </Button>
                </div>
            </div>

            <!-- Cifras: también son filtros -->
            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                <button
                    v-for="sev in ['danger', 'warning', 'info'] as Severity[]"
                    :key="sev"
                    type="button"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 text-left transition xl:col-span-3"
                    :class="{
                        'ring-1 ring-primary/30':
                            filters.state === 'open' && severity === sev,
                    }"
                    @click="pickSeverity(sev)"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                        :class="severityMeta[sev].tone"
                    >
                        <Lucide
                            :icon="
                                sev === 'danger'
                                    ? 'OctagonAlert'
                                    : sev === 'warning'
                                      ? 'TriangleAlert'
                                      : 'Info'
                            "
                            class="h-4 w-4"
                        />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">{{ counts[sev] }}</div>
                        <div class="text-xs leading-tight text-slate-500">
                            {{
                                sev === 'danger'
                                    ? 'Urgentes'
                                    : sev === 'warning'
                                      ? 'Por atender'
                                      : 'Informativas'
                            }}
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            {{
                                sev === 'danger'
                                    ? 'Algo ya dejó de funcionar'
                                    : sev === 'warning'
                                      ? 'Va a fallar o se está ignorando'
                                      : 'Para que estés enterado'
                            }}
                        </div>
                    </div>
                </button>
                <button
                    type="button"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 text-left transition xl:col-span-3"
                    :class="{
                        'ring-1 ring-primary/30': filters.state === 'resolved',
                    }"
                    @click="visit({ state: 'resolved', severity: null })"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="CircleCheckBig" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ counts.resolved_week }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Resueltas
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            En los últimos 7 días
                        </div>
                    </div>
                </button>
            </div>

            <!-- Listado -->
            <div class="box box--stacked mt-4">
                <!-- Estado -->
                <div
                    class="flex gap-1 overflow-x-auto border-b border-slate-200/60 px-3 dark:border-darkmode-400"
                >
                    <button
                        v-for="tab in stateTabs"
                        :key="tab.value"
                        type="button"
                        class="flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-xs whitespace-nowrap transition"
                        :class="
                            filters.state === tab.value
                                ? 'border-primary font-medium text-primary'
                                : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                        "
                        @click="visit({ state: tab.value })"
                    >
                        {{ tab.label }}
                        <span
                            v-if="tab.count"
                            class="rounded-full bg-slate-100 px-1.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                            >{{ tab.count }}</span
                        >
                    </button>
                </div>

                <!-- Filtros -->
                <div
                    class="flex flex-col gap-2 border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 lg:flex-row lg:flex-wrap lg:items-center dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="relative lg:w-64">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                        />
                        <FormInput
                            v-model="search"
                            type="text"
                            class="h-9 pl-9 text-xs"
                            placeholder="Buscar en los avisos"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:flex">
                        <FormSelect v-model="type" class="h-9 text-xs lg:w-48">
                            <option value="">Todos los tipos</option>
                            <option
                                v-for="t in types"
                                :key="t.value"
                                :value="t.value"
                            >
                                {{ t.label }}
                            </option>
                        </FormSelect>
                        <FormSelect
                            v-model="tenant"
                            class="h-9 text-xs lg:w-48"
                        >
                            <option value="">Todos los hoteles</option>
                            <option
                                v-for="t in tenants"
                                :key="t.value"
                                :value="t.value"
                            >
                                {{ t.label }}
                            </option>
                        </FormSelect>
                        <FormSelect
                            v-model="severity"
                            class="col-span-2 h-9 text-xs sm:col-span-1 lg:w-40"
                        >
                            <option value="">Toda importancia</option>
                            <option value="danger">Urgentes</option>
                            <option value="warning">Por atender</option>
                            <option value="info">Informativas</option>
                        </FormSelect>
                    </div>

                    <!-- Selección múltiple: en línea, al final de los filtros -->
                    <div
                        class="flex flex-wrap items-center gap-2 text-xs lg:ml-auto"
                    >
                        <template v-if="selected.length">
                            <span class="text-slate-500"
                                >{{ selected.length }} seleccionadas</span
                            >
                            <button
                                type="button"
                                class="font-medium text-primary"
                                @click="selected = []"
                            >
                                Quitar selección
                            </button>
                            <template v-if="filters.state === 'open'">
                                <Button
                                    variant="outline-secondary"
                                    class="h-8 rounded-[0.5rem] text-xs"
                                    :disabled="busy"
                                    @click="act(selected, 'read')"
                                >
                                    <Lucide
                                        icon="Check"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Leídas
                                </Button>
                                <Button
                                    variant="outline-secondary"
                                    class="h-8 rounded-[0.5rem] text-xs"
                                    :disabled="busy"
                                    @click="snoozing = [...selected]"
                                >
                                    <Lucide
                                        icon="AlarmClock"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Posponer
                                </Button>
                                <Button
                                    variant="danger"
                                    class="h-8 rounded-[0.5rem] text-xs"
                                    :disabled="busy"
                                    @click="act(selected, 'dismiss')"
                                >
                                    <Lucide
                                        icon="BellOff"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Descartar
                                </Button>
                            </template>
                            <Button
                                v-else-if="filters.state !== 'resolved'"
                                variant="outline-primary"
                                class="h-8 rounded-[0.5rem] text-xs"
                                :disabled="busy"
                                @click="act(selected, 'restore')"
                            >
                                <Lucide
                                    icon="Undo2"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Regresar a abiertas
                            </Button>
                        </template>
                        <template v-else>
                            <button
                                v-if="hasFilters"
                                type="button"
                                class="font-medium text-primary"
                                @click="clearFilters"
                            >
                                Quitar filtros
                            </button>
                            <span class="text-slate-500">
                                {{ alerts.total }}
                                {{ alerts.total === 1 ? 'aviso' : 'avisos' }}
                            </span>
                        </template>
                    </div>
                </div>

                <div
                    v-if="alerts.data.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <!-- Seleccionar la página -->
                    <label
                        v-if="filters.state !== 'resolved'"
                        class="flex cursor-pointer items-center gap-3 px-4 py-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase sm:px-5"
                    >
                        <FormCheck.Input
                            type="checkbox"
                            :checked="allSelected"
                            @change="toggleAll"
                        />
                        Seleccionar la página
                    </label>

                    <div
                        v-for="alert in alerts.data"
                        :key="alert.id"
                        class="flex flex-wrap gap-3 px-4 py-3 transition sm:flex-nowrap sm:px-5"
                        :class="{
                            'bg-primary/[0.03]':
                                !alert.read && filters.state === 'open',
                        }"
                    >
                        <FormCheck.Input
                            v-if="filters.state !== 'resolved'"
                            type="checkbox"
                            class="mt-2.5"
                            :checked="selected.includes(alert.id)"
                            @change="toggleOne(alert.id)"
                        />
                        <div
                            class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                            :class="
                                filters.state === 'resolved'
                                    ? 'border-success/10 bg-success/10 text-success'
                                    : severityMeta[alert.severity].tone
                            "
                        >
                            <Lucide
                                :icon="typeIcon[alert.type] ?? 'Bell'"
                                class="h-4 w-4"
                            />
                            <span
                                v-if="!alert.read && filters.state === 'open'"
                                class="absolute -top-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white dark:border-darkmode-600"
                                :class="severityMeta[alert.severity].dot"
                                title="Sin leer"
                            />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <button
                                    v-if="alert.url"
                                    type="button"
                                    class="text-left text-sm hover:text-primary"
                                    :class="
                                        alert.read
                                            ? 'text-slate-600 dark:text-slate-300'
                                            : 'font-medium'
                                    "
                                    @click="open(alert)"
                                >
                                    {{ alert.title }}
                                </button>
                                <span
                                    v-else
                                    class="text-sm"
                                    :class="{ 'font-medium': !alert.read }"
                                    >{{ alert.title }}</span
                                >
                                <span
                                    v-if="filters.state !== 'resolved'"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="severityMeta[alert.severity].tone"
                                    >{{
                                        severityMeta[alert.severity].label
                                    }}</span
                                >
                            </div>
                            <p
                                v-if="alert.body"
                                class="mt-0.5 text-xs text-slate-500"
                            >
                                {{ alert.body }}
                            </p>
                            <div
                                class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[11px]"
                            >
                                <Link
                                    v-if="alert.tenant?.exists"
                                    :href="
                                        route(
                                            'admin.tenants.show',
                                            alert.tenant.id,
                                        )
                                    "
                                    class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-600 hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                >
                                    <Lucide icon="Building2" class="h-3 w-3" />
                                    {{ alert.tenant.name }}
                                </Link>
                                <span
                                    v-else-if="alert.tenant"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-400 line-through dark:bg-darkmode-400"
                                    >{{ alert.tenant.name }}</span
                                >
                                <span
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-500 dark:bg-darkmode-400"
                                    >{{ alert.type_label }}</span
                                >
                                <span
                                    class="text-slate-400"
                                    :title="alert.first_seen_at ?? undefined"
                                >
                                    <template
                                        v-if="filters.state === 'resolved'"
                                        >Se resolvió sola
                                        {{ alert.resolved_ago }}</template
                                    >
                                    <template
                                        v-else-if="filters.state === 'snoozed'"
                                        >Vuelve el
                                        {{ alert.snoozed_until }}</template
                                    >
                                    <template
                                        v-else-if="
                                            filters.state === 'dismissed'
                                        "
                                        >Descartado {{ alert.dismissed_ago
                                        }}<template v-if="alert.dismissed_by">
                                            por
                                            {{ alert.dismissed_by }}</template
                                        ></template
                                    >
                                    <template v-else
                                        >Desde
                                        {{ alert.first_seen_ago }}</template
                                    >
                                </span>
                            </div>
                        </div>

                        <!-- Acciones -->
                        <div
                            class="flex shrink-0 basis-full items-start justify-end gap-0.5 border-t border-dashed border-slate-200/70 pt-2 sm:basis-auto sm:border-0 sm:pt-0 dark:border-darkmode-400"
                        >
                            <button
                                v-if="alert.url"
                                type="button"
                                :class="[
                                    ghostButton,
                                    'hover:bg-primary/10 hover:text-primary',
                                ]"
                                title="Ir a atenderlo"
                                @click="open(alert)"
                            >
                                <Lucide icon="ArrowUpRight" class="h-4 w-4" />
                            </button>
                            <template v-if="filters.state === 'open'">
                                <button
                                    type="button"
                                    :class="[
                                        ghostButton,
                                        'hover:bg-primary/10 hover:text-primary',
                                    ]"
                                    :title="
                                        alert.read
                                            ? 'Marcar como no leído'
                                            : 'Marcar como leído'
                                    "
                                    :disabled="busy"
                                    @click="
                                        act(
                                            [alert.id],
                                            alert.read ? 'unread' : 'read',
                                        )
                                    "
                                >
                                    <Lucide
                                        :icon="alert.read ? 'Mail' : 'Check'"
                                        class="h-4 w-4"
                                    />
                                </button>
                                <button
                                    type="button"
                                    :class="[
                                        ghostButton,
                                        'hover:bg-warning/10 hover:text-warning',
                                    ]"
                                    title="Posponer"
                                    :disabled="busy"
                                    @click="snoozing = [alert.id]"
                                >
                                    <Lucide icon="AlarmClock" class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    :class="[
                                        ghostButton,
                                        'hover:bg-danger/10 hover:text-danger',
                                    ]"
                                    title="Descartar: ya lo sé. Vuelve si empeora."
                                    :disabled="busy"
                                    @click="act([alert.id], 'dismiss')"
                                >
                                    <Lucide icon="BellOff" class="h-4 w-4" />
                                </button>
                            </template>
                            <button
                                v-else-if="filters.state !== 'resolved'"
                                type="button"
                                :class="[
                                    ghostButton,
                                    'hover:bg-primary/10 hover:text-primary',
                                ]"
                                title="Regresar a abiertas"
                                :disabled="busy"
                                @click="act([alert.id], 'restore')"
                            >
                                <Lucide icon="Undo2" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-2 px-4 py-12 text-center"
                >
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full"
                        :class="
                            filters.state === 'open' && !hasFilters
                                ? 'bg-success/10 text-success'
                                : 'bg-slate-100 text-slate-400 dark:bg-darkmode-400'
                        "
                    >
                        <Lucide
                            :icon="
                                filters.state === 'open' && !hasFilters
                                    ? 'CircleCheckBig'
                                    : 'SearchX'
                            "
                            class="h-4 w-4"
                        />
                    </div>
                    <p class="text-xs text-slate-500">{{ emptyText }}</p>
                    <button
                        v-if="hasFilters"
                        type="button"
                        class="text-xs font-medium text-primary"
                        @click="clearFilters"
                    >
                        Quitar filtros
                    </button>
                </div>

                <!-- Paginación -->
                <div
                    v-if="alerts.links.length > 3"
                    class="flex flex-wrap items-center gap-2 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <span class="text-xs text-slate-500">
                        {{ alerts.from }}–{{ alerts.to }} de {{ alerts.total }}
                    </span>
                    <div class="ml-auto flex flex-wrap gap-1">
                        <component
                            :is="link.url ? Link : 'span'"
                            v-for="(link, i) in alerts.links"
                            :key="i"
                            :href="link.url ?? undefined"
                            preserve-state
                            preserve-scroll
                            class="flex items-center rounded-md px-2.5 py-1 text-xs"
                            :class="
                                link.active
                                    ? 'bg-primary text-white'
                                    : link.url
                                      ? 'text-slate-500 hover:bg-slate-100 dark:hover:bg-darkmode-400'
                                      : 'text-slate-300'
                            "
                        >
                            <Lucide
                                v-if="pageLabel(link.label) === 'prev'"
                                icon="ChevronLeft"
                                class="h-3.5 w-3.5"
                            />
                            <Lucide
                                v-else-if="pageLabel(link.label) === 'next'"
                                icon="ChevronRight"
                                class="h-3.5 w-3.5"
                            />
                            <span v-else>{{ link.label }}</span>
                        </component>
                    </div>
                </div>

                <p
                    class="border-t border-slate-200/60 px-4 py-2.5 text-[11px] text-slate-400 dark:border-darkmode-400"
                >
                    Un aviso se resuelve solo cuando su causa desaparece.
                    Descartado o pospuesto, vuelve a abrirse si empeora (por
                    ejemplo, de 80 % a 100 % de la cuota).
                </p>
            </div>
        </div>

        <!-- Posponer -->
        <Dialog :open="snoozing !== null" @close="!busy && (snoozing = null)">
            <Dialog.Panel>
                <div v-if="snoozing" class="p-5">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                        >
                            <Lucide icon="AlarmClock" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-base font-medium">
                                Posponer
                                {{
                                    snoozing.length === 1
                                        ? 'el aviso'
                                        : `${snoozing.length} avisos`
                                }}
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">
                                Sale de abiertas y regresa sola al cumplirse el
                                plazo, si la causa sigue ahí. Si empeora antes,
                                vuelve de inmediato.
                            </p>
                        </div>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-5">
                        <button
                            v-for="opt in snoozeOptions"
                            :key="opt.hours"
                            type="button"
                            class="flex h-9 items-center justify-center rounded-[0.5rem] border border-slate-200 text-xs font-medium text-slate-600 transition hover:border-warning/40 hover:bg-warning/5 hover:text-warning disabled:opacity-50 dark:border-darkmode-400 dark:text-slate-300"
                            :disabled="busy"
                            @click="act(snoozing, 'snooze', opt.hours)"
                        >
                            {{ opt.label }}
                        </button>
                    </div>
                    <div class="mt-5 flex justify-end">
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="busy"
                            @click="snoozing = null"
                            >Cancelar</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
