<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';
import { modeOption, modeOptions, tenantUrl } from './modes';
import TenantEditModal from './TenantEditModal.vue';
import TenantSuspendDialog from './TenantSuspendDialog.vue';
import type { TenantShell } from './types';
import { useImpersonate } from './useImpersonate';

interface TenantRow {
    id: string;
    name: string;
    plan: string;
    plan_label: string;
    price_monthly: number;
    addons: number;
    ai_in_plan: boolean;
    suspended: boolean;
    domain: string | null;
    created_at: string | null;
    users: number | null;
    rooms: number | null;
    reservations_month: number | null;
    mode: TenantShell['mode'];
    ai_replies: number;
    reachable: boolean;
}

interface PlanInfo {
    value: string;
    label: string;
    max_properties: number | null;
    max_rooms: number | null;
    max_users: number | null;
    price_monthly: number;
    active: boolean;
}

const props = defineProps<{
    tenants: TenantRow[];
    stats: {
        total: number;
        active: number;
        suspended: number;
        new_month: number;
        mrr: number;
        ai_replies_month: number;
    };
    monthLabel: string;
    domainSuffix: string;
    plans: PlanInfo[];
}>();

const money = (n: number) => `$${n.toLocaleString('es-MX')}`;
const ghostButton =
    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition';
const sectionLabel =
    'mb-3 text-[11px] font-medium tracking-wide text-slate-400 uppercase';

const initials = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join('') || '?';

// ── Búsqueda, filtros y orden (en cliente: el listado carga completo) ──
const search = ref('');
const statusFilter = ref<'all' | 'active' | 'suspended'>('all');
// ?plan=clave llega desde las tarjetas de /admin/planes ("N hoteles").
const planFilter = ref(
    new URLSearchParams(usePage().url.split('?')[1] ?? '').get('plan') ?? 'all',
);
const modeFilter = ref<'all' | TenantShell['mode']>('all');
const sort = ref<'recent' | 'name' | 'price' | 'reservations'>('recent');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    const rows = props.tenants
        .filter(
            (t) =>
                statusFilter.value === 'all' ||
                (statusFilter.value === 'suspended') === t.suspended,
        )
        .filter(
            (t) => planFilter.value === 'all' || t.plan === planFilter.value,
        )
        .filter(
            (t) => modeFilter.value === 'all' || t.mode === modeFilter.value,
        )
        .filter(
            (t) =>
                !q ||
                t.name.toLowerCase().includes(q) ||
                t.id.toLowerCase().includes(q) ||
                (t.domain ?? '').toLowerCase().includes(q),
        );

    // El servidor ya los manda del más reciente al más viejo.
    if (sort.value === 'name') {
        return [...rows].sort((a, b) => a.name.localeCompare(b.name, 'es'));
    }
    if (sort.value === 'price') {
        return [...rows].sort((a, b) => b.price_monthly - a.price_monthly);
    }
    if (sort.value === 'reservations') {
        return [...rows].sort(
            (a, b) =>
                (b.reservations_month ?? -1) - (a.reservations_month ?? -1),
        );
    }
    return rows;
});

const hasFilters = computed(
    () =>
        !!search.value.trim() ||
        statusFilter.value !== 'all' ||
        planFilter.value !== 'all' ||
        modeFilter.value !== 'all',
);

function clearFilters() {
    search.value = '';
    statusFilter.value = 'all';
    planFilter.value = 'all';
    modeFilter.value = 'all';
}

// Tope + paginación: el listado no debe crecer sin fin con la cartera.
const perPage = 20;
const page = ref(1);
const pages = computed(() =>
    Math.max(1, Math.ceil(filtered.value.length / perPage)),
);
const visible = computed(() =>
    filtered.value.slice((page.value - 1) * perPage, page.value * perPage),
);
watch([search, statusFilter, planFilter, modeFilter, sort], () => {
    page.value = 1;
});

// ── Crear ──
const showCreate = ref(false);
const activePlans = computed(() => props.plans.filter((p) => p.active));
const createForm = useForm({
    name: '',
    subdomain: '',
    plan: activePlans.value[0]?.value ?? props.plans[0]?.value ?? '',
    // Motel y ambos encienden el registro exprés del plano; motel puro
    // además siembra wizard solo-adultos + menú pagado al recibir.
    mode: 'hotel' as string,
    owner_name: '',
    owner_email: '',
    owner_password: '',
});
const createPlanInfo = computed(() =>
    props.plans.find((p) => p.value === createForm.plan),
);

// El subdominio se sugiere del nombre mientras no lo toquen a mano.
const subdomainTouched = ref(false);
const slugify = (text: string) =>
    text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/^(hotel|motel)\s+/, '')
        .replace(/[^a-z0-9]+/g, '')
        .slice(0, 40);
watch(
    () => createForm.name,
    (name) => {
        if (!subdomainTouched.value) createForm.subdomain = slugify(name);
    },
);
function onSubdomainInput() {
    subdomainTouched.value = true;
    createForm.subdomain = createForm.subdomain
        .toLowerCase()
        .replace(/[^a-z0-9-]/g, '');
}
const subdomainTaken = computed(() =>
    props.tenants.some((t) => t.id === createForm.subdomain),
);

const showPassword = ref(false);
function generatePassword() {
    // Sin caracteres que se confunden al dictarla (0/O, 1/l/I).
    const alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const bytes = new Uint32Array(12);
    crypto.getRandomValues(bytes);
    createForm.owner_password = Array.from(
        bytes,
        (b) => alphabet[b % alphabet.length],
    ).join('');
    showPassword.value = true;
}

function openCreate() {
    createForm.reset();
    createForm.clearErrors();
    subdomainTouched.value = false;
    showPassword.value = false;
    showCreate.value = true;
}

function closeCreate() {
    if (createForm.processing) return;
    showCreate.value = false;
}

function submitCreate() {
    createForm.post(route('admin.tenants.store'), {
        // Al terminar se abre la ficha del hotel nuevo.
        onSuccess: () => (showCreate.value = false),
    });
}

// ── Editar / suspender / entrar como ──
const editing = ref<TenantRow | null>(null);
const suspending = ref<TenantRow | null>(null);
const { impersonating, impersonateError, impersonate } = useImpersonate();

// ── Eliminar: hay que teclear el subdominio ──
const deleting = ref<TenantRow | null>(null);
const deleteForm = useForm({ confirm: '' });

function openDelete(tenant: TenantRow) {
    deleteForm.reset();
    deleteForm.clearErrors();
    deleting.value = tenant;
}

function closeDelete() {
    if (deleteForm.processing) return;
    deleting.value = null;
}

function submitDelete() {
    if (!deleting.value || deleteForm.confirm !== deleting.value.id) return;
    deleteForm.delete(route('admin.tenants.destroy', deleting.value.id), {
        preserveScroll: true,
        onSuccess: () => (deleting.value = null),
    });
}
</script>

<template>
    <RazeLayout title="Hoteles">
        <div class="mt-2">
            <!-- Encabezado -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Building2" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Hoteles</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            La cartera de clientes: plan, cómo operan y acceso
                            de soporte a su panel.
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Link
                        :href="route('admin.plans')"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-[0.5rem] border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-600 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600 dark:text-slate-300"
                    >
                        <Lucide icon="Layers" class="h-3.5 w-3.5" />
                        Planes
                    </Link>
                    <Link
                        :href="route('admin.services')"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-[0.5rem] border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-600 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600 dark:text-slate-300"
                    >
                        <Lucide icon="PackagePlus" class="h-3.5 w-3.5" />
                        Servicios
                    </Link>
                    <Button
                        variant="primary"
                        class="col-span-2 h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        @click="openCreate"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Nuevo hotel
                    </Button>
                </div>
            </div>

            <!-- Cifras -->
            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Building2" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.active }} de {{ stats.total }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Hoteles activos
                        </div>
                        <div
                            class="hidden truncate text-[11px] sm:block"
                            :class="
                                stats.suspended
                                    ? 'font-medium text-danger'
                                    : 'text-slate-400'
                            "
                        >
                            {{
                                stats.suspended
                                    ? `${stats.suspended} suspendido${stats.suspended === 1 ? '' : 's'}`
                                    : 'Ninguno suspendido'
                            }}
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="Banknote" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ money(stats.mrr) }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Ingreso mensual
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Plan + servicios, hoteles activos
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                    >
                        <Lucide icon="TrendingUp" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.new_month }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Altas del mes
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 first-letter:uppercase sm:block"
                        >
                            {{ monthLabel }}
                        </div>
                    </div>
                </div>
                <Link
                    :href="route('admin.ai')"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 transition hover:ring-1 hover:ring-primary/20 xl:col-span-3"
                    title="Ver consumo por hotel"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                    >
                        <Lucide icon="Bot" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.ai_replies_month.toLocaleString('es-MX') }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Respuestas IA del mes
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-primary sm:block"
                        >
                            Ver consumo por hotel
                        </div>
                    </div>
                </Link>
            </div>

            <!-- Listado -->
            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-col gap-2 rounded-t-[0.6rem] border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 lg:flex-row lg:flex-wrap lg:items-center dark:border-darkmode-400 dark:bg-darkmode-600/40"
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
                            placeholder="Buscar hotel o subdominio"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:flex">
                        <FormSelect
                            v-model="statusFilter"
                            class="h-9 text-xs lg:w-40"
                        >
                            <option value="all">
                                Todos ({{ stats.total }})
                            </option>
                            <option value="active">
                                Activos ({{ stats.active }})
                            </option>
                            <option value="suspended">
                                Suspendidos ({{ stats.suspended }})
                            </option>
                        </FormSelect>
                        <FormSelect
                            v-model="planFilter"
                            class="h-9 text-xs lg:w-40"
                        >
                            <option value="all">Todos los planes</option>
                            <option
                                v-for="p in plans"
                                :key="p.value"
                                :value="p.value"
                            >
                                {{ p.label }}
                            </option>
                        </FormSelect>
                        <FormSelect
                            v-model="modeFilter"
                            class="h-9 text-xs lg:w-40"
                        >
                            <option value="all">Todos los modos</option>
                            <option
                                v-for="m in modeOptions"
                                :key="m.value"
                                :value="m.value"
                            >
                                {{ m.label }}
                            </option>
                        </FormSelect>
                        <FormSelect v-model="sort" class="h-9 text-xs lg:w-44">
                            <option value="recent">Más recientes</option>
                            <option value="name">Por nombre</option>
                            <option value="price">Pagan más al mes</option>
                            <option value="reservations">
                                Más reservas del mes
                            </option>
                        </FormSelect>
                    </div>
                    <div class="flex items-center gap-3 text-xs lg:ml-auto">
                        <button
                            v-if="hasFilters"
                            type="button"
                            class="font-medium text-primary"
                            @click="clearFilters"
                        >
                            Quitar filtros
                        </button>
                        <span class="text-slate-500">
                            {{ filtered.length }}
                            {{ filtered.length === 1 ? 'hotel' : 'hoteles' }}
                        </span>
                    </div>
                </div>

                <div
                    v-if="impersonateError"
                    class="flex items-start gap-2 border-b border-danger/15 bg-danger/5 px-4 py-3 text-xs text-danger"
                >
                    <Lucide
                        icon="TriangleAlert"
                        class="mt-px h-3.5 w-3.5 shrink-0"
                    />
                    {{ impersonateError }}
                </div>

                <div
                    v-if="visible.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="t in visible"
                        :key="t.id"
                        class="flex flex-col gap-3 px-4 py-3 sm:px-5 lg:flex-row lg:items-center"
                    >
                        <!-- Quién es -->
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold text-white"
                                :class="
                                    t.suspended
                                        ? 'bg-slate-300 dark:bg-darkmode-400'
                                        : 'bg-linear-to-br from-theme-1 to-theme-2'
                                "
                            >
                                {{ initials(t.name) }}
                            </div>
                            <div class="min-w-0">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <Link
                                        :href="
                                            route('admin.tenants.show', t.id)
                                        "
                                        class="truncate text-sm font-medium hover:text-primary"
                                        :class="{
                                            'text-slate-400': t.suspended,
                                        }"
                                        >{{ t.name }}</Link
                                    >
                                    <span
                                        v-if="t.suspended"
                                        class="rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                        >Suspendido</span
                                    >
                                    <span
                                        v-if="!t.reachable"
                                        class="inline-flex items-center gap-1 rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                                        title="Su base de datos no respondió al cargar el listado"
                                    >
                                        <Lucide
                                            icon="TriangleAlert"
                                            class="h-3 w-3"
                                        />
                                        Base sin respuesta
                                    </span>
                                </div>
                                <a
                                    v-if="t.domain"
                                    :href="tenantUrl(t.domain)"
                                    target="_blank"
                                    rel="noopener"
                                    class="block truncate text-xs text-slate-500 hover:text-primary"
                                    >{{ t.domain }}</a
                                >
                            </div>
                        </div>

                        <!-- Datos duros -->
                        <div
                            class="flex flex-wrap items-center gap-x-5 gap-y-2 pl-12 lg:contents"
                        >
                            <div class="lg:w-40 lg:shrink-0">
                                <span
                                    class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                    >{{ t.plan_label }}</span
                                >
                                <div
                                    class="mt-1 text-[11px] text-slate-500"
                                    :title="
                                        t.addons
                                            ? 'Plan base + servicios adicionales contratados'
                                            : 'Plan base'
                                    "
                                >
                                    {{ money(t.price_monthly) }}/mes<template
                                        v-if="t.addons"
                                    >
                                        · {{ t.addons }}
                                        {{
                                            t.addons === 1
                                                ? 'servicio'
                                                : 'servicios'
                                        }}</template
                                    >
                                </div>
                            </div>
                            <div class="lg:w-44 lg:shrink-0">
                                <span
                                    class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                    title="Modo de operación"
                                >
                                    <Lucide
                                        :icon="modeOption(t.mode).icon"
                                        class="h-3 w-3"
                                    />
                                    {{ modeOption(t.mode).label }}
                                </span>
                                <div
                                    v-if="t.reachable"
                                    class="mt-1 flex items-center gap-3 text-[11px] text-slate-500"
                                >
                                    <span
                                        class="inline-flex items-center gap-1"
                                        title="Usuarios del equipo"
                                    >
                                        <Lucide
                                            icon="Users"
                                            class="h-3 w-3"
                                        />{{ t.users }}
                                    </span>
                                    <span
                                        class="inline-flex items-center gap-1"
                                        title="Habitaciones"
                                    >
                                        <Lucide
                                            icon="BedDouble"
                                            class="h-3 w-3"
                                        />{{ t.rooms }}
                                    </span>
                                    <span
                                        class="inline-flex items-center gap-1"
                                        title="Reservas creadas este mes"
                                    >
                                        <Lucide
                                            icon="CalendarCheck"
                                            class="h-3 w-3"
                                        />{{ t.reservations_month }}
                                    </span>
                                </div>
                            </div>
                            <div class="text-[11px] lg:w-24 lg:shrink-0">
                                <span
                                    v-if="t.ai_in_plan"
                                    class="inline-flex items-center gap-1 rounded-full bg-info/10 px-2 py-0.5 font-medium text-info"
                                    title="Respuestas del asistente este mes"
                                >
                                    <Lucide icon="Bot" class="h-3 w-3" />
                                    {{ t.ai_replies.toLocaleString('es-MX') }}
                                </span>
                                <span v-else class="text-slate-400"
                                    >Sin IA</span
                                >
                            </div>
                            <div
                                class="text-[11px] text-slate-500 lg:w-20 lg:shrink-0"
                                title="Fecha de alta"
                            >
                                {{ t.created_at ?? '' }}
                            </div>
                        </div>

                        <!-- Acciones -->
                        <div
                            class="flex items-center justify-end gap-0.5 border-t border-dashed border-slate-200/70 pt-2 lg:border-0 lg:pt-0 dark:border-darkmode-400"
                        >
                            <button
                                v-if="!t.suspended"
                                type="button"
                                :class="[
                                    ghostButton,
                                    'hover:bg-primary/10 hover:text-primary',
                                ]"
                                :disabled="impersonating === t.id"
                                title="Entrar como el dueño (acceso de soporte, un solo uso)"
                                @click="impersonate(t.id)"
                            >
                                <Lucide
                                    :icon="
                                        impersonating === t.id
                                            ? 'LoaderCircle'
                                            : 'LogIn'
                                    "
                                    class="h-4 w-4"
                                    :class="{
                                        'animate-spin': impersonating === t.id,
                                    }"
                                />
                            </button>
                            <button
                                type="button"
                                :class="[
                                    ghostButton,
                                    'hover:bg-primary/10 hover:text-primary',
                                ]"
                                title="Editar nombre, plan y modo"
                                @click="editing = t"
                            >
                                <Lucide icon="Pencil" class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                :class="[
                                    ghostButton,
                                    t.suspended
                                        ? 'hover:bg-success/10 hover:text-success'
                                        : 'hover:bg-warning/10 hover:text-warning',
                                ]"
                                :title="t.suspended ? 'Reactivar' : 'Suspender'"
                                @click="suspending = t"
                            >
                                <Lucide
                                    :icon="t.suspended ? 'Play' : 'Pause'"
                                    class="h-4 w-4"
                                />
                            </button>
                            <button
                                type="button"
                                :class="[
                                    ghostButton,
                                    'hover:bg-danger/10 hover:text-danger',
                                ]"
                                title="Eliminar hotel y su base de datos"
                                @click="openDelete(t)"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </button>
                            <Link
                                :href="route('admin.tenants.show', t.id)"
                                :class="[
                                    ghostButton,
                                    'hover:bg-primary/10 hover:text-primary',
                                ]"
                                title="Abrir la ficha"
                            >
                                <Lucide icon="ChevronRight" class="h-4 w-4" />
                            </Link>
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
                        <Lucide
                            :icon="tenants.length ? 'SearchX' : 'Building2'"
                            class="h-4 w-4"
                        />
                    </div>
                    <p class="text-xs text-slate-500">
                        {{
                            tenants.length
                                ? 'Ningún hotel coincide con los filtros.'
                                : 'Aún no hay hoteles. Crea el primero con "Nuevo hotel".'
                        }}
                    </p>
                    <button
                        v-if="hasFilters"
                        type="button"
                        class="text-xs font-medium text-primary"
                        @click="clearFilters"
                    >
                        Quitar filtros
                    </button>
                </div>

                <div
                    v-if="pages > 1"
                    class="flex items-center justify-between gap-2 border-t border-slate-200/60 px-4 py-3 text-xs dark:border-darkmode-400"
                >
                    <span class="text-slate-500"
                        >Página {{ page }} de {{ pages }}</span
                    >
                    <div class="flex gap-1">
                        <button
                            v-for="n in pages"
                            :key="n"
                            type="button"
                            class="rounded-md px-2.5 py-1"
                            :class="
                                n === page
                                    ? 'bg-primary text-white'
                                    : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-darkmode-400'
                            "
                            @click="page = n"
                        >
                            {{ n }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: crear -->
        <Dialog :open="showCreate" size="lg" @close="closeCreate">
            <Dialog.Panel class="sm:w-[94vw] lg:w-[720px]">
                <form
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                    @submit.prevent="submitCreate"
                >
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Building2" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">Nuevo hotel</h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Se le crea su base de datos con roles, dueño y
                                primera propiedad. Tarda unos segundos.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            title="Cerrar"
                            @click="closeCreate"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>

                    <div
                        class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4"
                    >
                        <section>
                            <div :class="sectionLabel">El hotel</div>
                            <div class="grid grid-cols-12 gap-4">
                                <div class="col-span-12">
                                    <label
                                        for="create-name"
                                        class="mb-1.5 block text-xs font-medium"
                                        >Nombre del hotel</label
                                    >
                                    <FormInput
                                        id="create-name"
                                        v-model="createForm.name"
                                        type="text"
                                        maxlength="255"
                                        class="h-9 text-xs"
                                        placeholder="Hotel Las Palmas"
                                    />
                                    <FormHelp
                                        v-if="createForm.errors.name"
                                        class="text-danger"
                                        >{{ createForm.errors.name }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-7">
                                    <label
                                        for="create-subdomain"
                                        class="mb-1.5 block text-xs font-medium"
                                        >Subdominio</label
                                    >
                                    <div class="flex items-center">
                                        <FormInput
                                            id="create-subdomain"
                                            v-model="createForm.subdomain"
                                            type="text"
                                            maxlength="40"
                                            class="h-9 rounded-r-none text-xs"
                                            placeholder="laspalmas"
                                            @input="onSubdomainInput"
                                        />
                                        <span
                                            class="flex h-9 items-center rounded-r-md border border-l-0 border-slate-200 bg-slate-50 px-3 text-xs whitespace-nowrap text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-600"
                                        >
                                            .{{ domainSuffix }}
                                        </span>
                                    </div>
                                    <FormHelp
                                        v-if="createForm.errors.subdomain"
                                        class="text-danger"
                                        >{{
                                            createForm.errors.subdomain
                                        }}</FormHelp
                                    >
                                    <FormHelp
                                        v-else-if="subdomainTaken"
                                        class="text-danger"
                                        >Ese subdominio ya lo usa otro
                                        hotel.</FormHelp
                                    >
                                    <FormHelp v-else
                                        >Es su dirección y la llave de su base:
                                        no se puede cambiar después.</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-5">
                                    <label
                                        for="create-plan"
                                        class="mb-1.5 block text-xs font-medium"
                                        >Plan</label
                                    >
                                    <FormSelect
                                        id="create-plan"
                                        v-model="createForm.plan"
                                        class="h-9 text-xs"
                                    >
                                        <option
                                            v-for="plan in activePlans"
                                            :key="plan.value"
                                            :value="plan.value"
                                        >
                                            {{ plan.label }}
                                        </option>
                                    </FormSelect>
                                    <FormHelp
                                        v-if="createForm.errors.plan"
                                        class="text-danger"
                                        >{{ createForm.errors.plan }}</FormHelp
                                    >
                                    <FormHelp v-else-if="createPlanInfo">
                                        {{
                                            money(createPlanInfo.price_monthly)
                                        }}
                                        al mes · hasta
                                        {{
                                            createPlanInfo.max_rooms ??
                                            'ilimitadas'
                                        }}
                                        habitaciones y
                                        {{
                                            createPlanInfo.max_users ??
                                            'ilimitados'
                                        }}
                                        usuarios.
                                    </FormHelp>
                                </div>
                            </div>
                        </section>

                        <section
                            class="border-t border-dashed border-slate-200/70 pt-5 dark:border-darkmode-400"
                        >
                            <div :class="sectionLabel">Modo de operación</div>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <button
                                    v-for="option in modeOptions"
                                    :key="option.value"
                                    type="button"
                                    class="flex h-full w-full items-start gap-2.5 rounded-lg border p-3 text-left transition"
                                    :class="
                                        createForm.mode === option.value
                                            ? 'border-primary bg-primary/5'
                                            : 'border-slate-200/70 hover:border-slate-300 dark:border-darkmode-400'
                                    "
                                    @click="createForm.mode = option.value"
                                >
                                    <Lucide
                                        :icon="option.icon"
                                        class="mt-0.5 h-4 w-4 shrink-0"
                                        :class="
                                            createForm.mode === option.value
                                                ? 'text-primary'
                                                : 'text-slate-400'
                                        "
                                    />
                                    <span class="min-w-0">
                                        <span
                                            class="block text-xs font-medium"
                                            >{{ option.label }}</span
                                        >
                                        <span
                                            class="mt-0.5 block text-[11px] leading-snug text-slate-500"
                                            >{{ option.description }}</span
                                        >
                                    </span>
                                </button>
                            </div>
                            <FormHelp
                                v-if="createForm.errors.mode"
                                class="text-danger"
                                >{{ createForm.errors.mode }}</FormHelp
                            >
                            <FormHelp v-else
                                >Lo administra la plataforma: el hotel no lo ve
                                en sus ajustes. Se puede cambiar
                                después.</FormHelp
                            >
                        </section>

                        <section
                            class="border-t border-dashed border-slate-200/70 pt-5 dark:border-darkmode-400"
                        >
                            <div :class="sectionLabel">Dueño</div>
                            <div class="grid grid-cols-12 gap-4">
                                <div class="col-span-12">
                                    <label
                                        for="owner-name"
                                        class="mb-1.5 block text-xs font-medium"
                                        >Nombre</label
                                    >
                                    <FormInput
                                        id="owner-name"
                                        v-model="createForm.owner_name"
                                        type="text"
                                        maxlength="255"
                                        class="h-9 text-xs"
                                        placeholder="Juan Pérez"
                                    />
                                    <FormHelp
                                        v-if="createForm.errors.owner_name"
                                        class="text-danger"
                                        >{{
                                            createForm.errors.owner_name
                                        }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <label
                                        for="owner-email"
                                        class="mb-1.5 block text-xs font-medium"
                                        >Correo</label
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="Mail"
                                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                        />
                                        <FormInput
                                            id="owner-email"
                                            v-model="createForm.owner_email"
                                            type="email"
                                            class="h-9 pl-9 text-xs"
                                            placeholder="dueno@hotel.com"
                                        />
                                    </div>
                                    <FormHelp
                                        v-if="createForm.errors.owner_email"
                                        class="text-danger"
                                        >{{
                                            createForm.errors.owner_email
                                        }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <label
                                        for="owner-password"
                                        class="mb-1.5 block text-xs font-medium"
                                        >Contraseña</label
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="KeyRound"
                                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                        />
                                        <FormInput
                                            id="owner-password"
                                            v-model="createForm.owner_password"
                                            :type="
                                                showPassword
                                                    ? 'text'
                                                    : 'password'
                                            "
                                            autocomplete="new-password"
                                            class="h-9 pr-16 pl-9 text-xs"
                                            placeholder="Mínimo 8 caracteres"
                                        />
                                        <div
                                            class="absolute inset-y-0 right-1 z-10 my-auto flex items-center"
                                        >
                                            <button
                                                type="button"
                                                class="flex h-7 w-7 items-center justify-center rounded-full text-slate-400 hover:text-primary"
                                                :title="
                                                    showPassword
                                                        ? 'Ocultar'
                                                        : 'Mostrar'
                                                "
                                                @click="
                                                    showPassword = !showPassword
                                                "
                                            >
                                                <Lucide
                                                    :icon="
                                                        showPassword
                                                            ? 'EyeOff'
                                                            : 'Eye'
                                                    "
                                                    class="h-3.5 w-3.5"
                                                />
                                            </button>
                                            <button
                                                type="button"
                                                class="flex h-7 w-7 items-center justify-center rounded-full text-slate-400 hover:text-primary"
                                                title="Generar una contraseña segura"
                                                @click="generatePassword"
                                            >
                                                <Lucide
                                                    icon="Shuffle"
                                                    class="h-3.5 w-3.5"
                                                />
                                            </button>
                                        </div>
                                    </div>
                                    <FormHelp
                                        v-if="createForm.errors.owner_password"
                                        class="text-danger"
                                        >{{
                                            createForm.errors.owner_password
                                        }}</FormHelp
                                    >
                                    <FormHelp v-else
                                        >Pásasela al dueño: la puede cambiar en
                                        su perfil.</FormHelp
                                    >
                                </div>
                            </div>
                        </section>
                    </div>

                    <div
                        class="flex items-center gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <span
                            v-if="createForm.subdomain"
                            class="mr-auto hidden truncate text-xs text-slate-500 sm:block"
                        >
                            Quedará en
                            <span
                                class="font-medium text-slate-700 dark:text-slate-300"
                                >{{ createForm.subdomain }}.{{
                                    domainSuffix
                                }}</span
                            >
                        </span>
                        <div class="ml-auto flex gap-2">
                            <Button
                                type="button"
                                variant="outline-secondary"
                                class="h-9 rounded-[0.5rem] px-5 text-xs"
                                :disabled="createForm.processing"
                                @click="closeCreate"
                                >Cancelar</Button
                            >
                            <Button
                                type="submit"
                                variant="primary"
                                class="h-9 rounded-[0.5rem] px-5 text-xs shadow-md shadow-primary/20"
                                :disabled="
                                    createForm.processing || subdomainTaken
                                "
                            >
                                <Lucide
                                    :icon="
                                        createForm.processing
                                            ? 'LoaderCircle'
                                            : 'Check'
                                    "
                                    class="mr-1.5 h-3.5 w-3.5"
                                    :class="{
                                        'animate-spin': createForm.processing,
                                    }"
                                />
                                {{
                                    createForm.processing
                                        ? 'Creando...'
                                        : 'Crear hotel'
                                }}
                            </Button>
                        </div>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <TenantEditModal
            :tenant="editing"
            :plans="plans"
            @close="editing = null"
        />
        <TenantSuspendDialog :tenant="suspending" @close="suspending = null" />

        <!-- Confirmación: eliminar -->
        <Dialog :open="deleting !== null" @close="closeDelete">
            <Dialog.Panel>
                <form
                    v-if="deleting"
                    class="p-5"
                    @submit.prevent="submitDelete"
                >
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                Eliminar {{ deleting.name }}
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">
                                Se borra el hotel y
                                <span class="font-medium text-danger"
                                    >toda su base de datos</span
                                >: habitaciones, reservas, huéspedes, pagos y
                                usuarios. También sus canales, pasarelas e
                                integraciones. No se puede deshacer. Si solo
                                quieres cortarle el acceso, suspéndelo.
                            </p>
                            <label
                                for="delete-confirm"
                                class="mt-3 mb-1.5 block text-xs"
                            >
                                Para confirmar, escribe
                                <span class="font-mono font-medium">{{
                                    deleting.id
                                }}</span>
                            </label>
                            <FormInput
                                id="delete-confirm"
                                v-model="deleteForm.confirm"
                                type="text"
                                autocomplete="off"
                                class="h-9 font-mono text-xs"
                            />
                            <FormHelp
                                v-if="deleteForm.errors.confirm"
                                class="text-danger"
                                >{{ deleteForm.errors.confirm }}</FormHelp
                            >
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="deleteForm.processing"
                            @click="closeDelete"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="danger"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="
                                deleteForm.processing ||
                                deleteForm.confirm !== deleting.id
                            "
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                deleteForm.processing
                                    ? 'Eliminando...'
                                    : 'Sí, eliminar'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
