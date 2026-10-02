<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ServiceRow {
    key: string;
    name: string;
    summary: string | null;
    objective: string | null;
    recommendation: string | null;
    price_monthly: number;
    activation_fee: number;
    includes: { label: string; available: boolean }[];
    ai_monthly_replies: number | null;
    requires: string | null;
    active: boolean;
    tenants: string[];
    last_change: {
        ago: string | null;
        at: string | null;
        by: string | null;
        by_id: number | null;
    } | null;
}

interface TenantRow {
    id: string;
    name: string;
    plan: string;
    plan_label: string;
    suspended: boolean;
}

const props = defineProps<{
    services: ServiceRow[];
    tenants: TenantRow[];
    stats: {
        mrr_addons: number;
        contracts: number;
        tenants_with_addons: number;
    };
}>();

const money = (n: number) => `$${n.toLocaleString('es-MX')}`;
const hotels = (n: number) => `${n} ${n === 1 ? 'hotel' : 'hoteles'}`;

const sectionLabel =
    'mb-3 flex items-center gap-1.5 text-[11px] font-medium tracking-wide text-slate-400 uppercase';
const ghostButton =
    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary';

// Los nombres largos del documento ("Servicios Digitales… – Modalidad N:
// Título") se parten: el prefijo va como overline y el título manda.
function displayTitle(service: ServiceRow) {
    const parts = service.name.split(' – ');
    return parts.length > 1 ? parts[parts.length - 1] : service.name;
}
function displayGroup(service: ServiceRow) {
    const parts = service.name.split(' – ');
    return parts.length > 1 ? parts.slice(0, -1).join(' – ') : null;
}

const serviceIcons: Record<string, Icon> = {
    'reservas-m1-motor': 'Globe',
    'reservas-m2-ia-mensajes': 'Bot',
    'reservas-m3-ia-redes': 'Share2',
    'menu-digital': 'UtensilsCrossed',
    'inventario-costos': 'Boxes',
    'crm-frecuentes': 'HeartHandshake',
};
const serviceIcon = (key: string): Icon => serviceIcons[key] ?? 'PackagePlus';

const serviceByKey = (key: string | null) =>
    key ? props.services.find((s) => s.key === key) : undefined;
const requiredTitle = (service: ServiceRow) => {
    const required = serviceByKey(service.requires);
    return required ? displayTitle(required) : (service.requires ?? '');
};
// Servicios que amplían a este (al retirarlo caen con él).
const dependentsOf = (service: ServiceRow) =>
    props.services.filter((s) => s.requires === service.key);

const monthlyRevenue = (service: ServiceRow) =>
    service.active ? service.price_monthly * service.tenants.length : 0;

const activeCount = computed(
    () => props.services.filter((s) => s.active).length,
);

// ── Filtros (en cliente: el catálogo es corto) ──────────────────────────
const search = ref('');
const status = ref<'' | 'active' | 'inactive'>('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.services.filter((s) => {
        if (status.value === 'active' && !s.active) return false;
        if (status.value === 'inactive' && s.active) return false;
        if (!q) return true;
        return [
            s.name,
            s.summary,
            s.recommendation,
            ...s.includes.map((i) => i.label),
        ]
            .filter(Boolean)
            .some((text) => (text as string).toLowerCase().includes(q));
    });
});

function clearFilters() {
    search.value = '';
    status.value = '';
}

// ── Editar servicio ─────────────────────────────────────────────────────
const editingKey = ref<string | null>(null);
const editing = computed(() => serviceByKey(editingKey.value) ?? null);
const form = useForm({
    name: '',
    summary: '',
    objective: '',
    recommendation: '',
    price_monthly: 0 as number | string,
    activation_fee: 0 as number | string,
    active: true,
});

function openEdit(service: ServiceRow) {
    form.clearErrors();
    form.name = service.name;
    form.summary = service.summary ?? '';
    form.objective = service.objective ?? '';
    form.recommendation = service.recommendation ?? '';
    form.price_monthly = service.price_monthly;
    form.activation_fee = service.activation_fee;
    form.active = service.active;
    form.defaults();
    editingKey.value = service.key;
}

function closeEdit() {
    if (form.processing) return;
    editingKey.value = null;
}

// Lo que el cambio de precio o de estado le mueve al cobro mensual, antes
// de guardar: los precios aplican de inmediato a quien ya lo tiene.
const editImpact = computed(() => {
    const service = editing.value;
    if (!service || !service.tenants.length) return null;
    const before = service.active
        ? service.price_monthly * service.tenants.length
        : 0;
    const after = form.active
        ? Number(form.price_monthly || 0) * service.tenants.length
        : 0;
    return before === after ? null : { before, after };
});

function submit() {
    if (!editing.value) return;
    form.transform((data) => ({
        ...data,
        summary: data.summary === '' ? null : data.summary,
        objective: data.objective === '' ? null : data.objective,
        recommendation: data.recommendation === '' ? null : data.recommendation,
        price_monthly: Number(data.price_monthly || 0),
        activation_fee: Number(data.activation_fee || 0),
    })).patch(route('admin.services.update', editing.value.key), {
        preserveScroll: true,
        onSuccess: () => (editingKey.value = null),
    });
}

// ── Activar / sacar del catálogo desde el renglón ───────────────────────
// El interruptor no cambia solo (click.prevent): refleja lo que dice el
// servidor, así un rechazo nunca lo deja mintiendo.
const deactivating = ref<ServiceRow | null>(null);
const activeForm = useForm({});
const togglingKey = ref<string | null>(null);

function requestToggleActive(service: ServiceRow) {
    if (activeForm.processing) return;
    if (service.active && service.tenants.length) {
        deactivating.value = service;
        return;
    }
    setActive(service, !service.active);
}

function setActive(service: ServiceRow, active: boolean) {
    togglingKey.value = service.key;
    activeForm
        .transform(() => ({
            name: service.name,
            summary: service.summary,
            objective: service.objective,
            recommendation: service.recommendation,
            price_monthly: service.price_monthly,
            activation_fee: service.activation_fee,
            active,
        }))
        .patch(route('admin.services.update', service.key), {
            preserveScroll: true,
            onSuccess: () => (deactivating.value = null),
            onFinish: () => (togglingKey.value = null),
        });
}

// ── Contratación por hotel ──────────────────────────────────────────────
const managingKey = ref<string | null>(null);
const managing = computed(() => serviceByKey(managingKey.value) ?? null);
const tenantSearch = ref('');
const tenantScope = ref<'all' | 'contracted'>('all');
const contractForm = useForm({ contracted: false });
const pendingTenant = ref<string | null>(null);

function openManaging(service: ServiceRow) {
    contractForm.clearErrors();
    tenantSearch.value = '';
    tenantScope.value = service.tenants.length ? 'contracted' : 'all';
    managingKey.value = service.key;
}

function closeManaging() {
    managingKey.value = null;
    contractForm.clearErrors();
}

const managingTenants = computed(() => {
    const service = managing.value;
    if (!service) return [];
    const q = tenantSearch.value.trim().toLowerCase();
    return (
        props.tenants
            .filter((t) =>
                tenantScope.value === 'contracted'
                    ? service.tenants.includes(t.id)
                    : true,
            )
            .filter(
                (t) =>
                    !q ||
                    t.name.toLowerCase().includes(q) ||
                    t.id.toLowerCase().includes(q),
            )
            // Contratados primero: es lo que se viene a revisar.
            .sort(
                (a, b) =>
                    Number(service.tenants.includes(b.id)) -
                    Number(service.tenants.includes(a.id)),
            )
    );
});

// El hotel no tiene el servicio que este amplía: no se puede contratar.
function missingRequirement(service: ServiceRow, tenantId: string) {
    const required = serviceByKey(service.requires);
    return !!required && !required.tenants.includes(tenantId);
}

const retiring = ref<{ service: ServiceRow; tenant: TenantRow } | null>(null);
const retiringAlso = computed(() =>
    retiring.value
        ? dependentsOf(retiring.value.service).filter((d) =>
              d.tenants.includes(retiring.value!.tenant.id),
          )
        : [],
);

function requestToggleTenant(tenant: TenantRow) {
    const service = managing.value;
    if (!service || contractForm.processing) return;
    if (service.tenants.includes(tenant.id)) {
        retiring.value = { service, tenant };
        return;
    }
    if (!service.active || missingRequirement(service, tenant.id)) return;
    setContract(service, tenant, true);
}

function setContract(
    service: ServiceRow,
    tenant: TenantRow,
    contracted: boolean,
) {
    pendingTenant.value = tenant.id;
    contractForm.contracted = contracted;
    contractForm.patch(
        route('admin.tenants.addon-services', {
            tenant: tenant.id,
            addonService: service.key,
        }),
        {
            preserveScroll: true,
            onSuccess: () => (retiring.value = null),
            onFinish: () => (pendingTenant.value = null),
        },
    );
}
</script>

<template>
    <RazeLayout title="Servicios adicionales">
        <div class="mt-2">
            <!-- Encabezado -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="PackagePlus" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Servicios adicionales
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Se cobran aparte del plan base; lo que incluyen se
                            le enciende al hotel en cuanto se contratan.
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
                        :href="route('admin.tenants.index')"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-[0.5rem] border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-600 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600 dark:text-slate-300"
                    >
                        <Lucide icon="Building2" class="h-3.5 w-3.5" />
                        Hoteles
                    </Link>
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
                        <Lucide icon="PackagePlus" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ activeCount }} de {{ services.length }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            En catálogo
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Servicios que se pueden vender
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                    >
                        <Lucide icon="Receipt" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.contracts }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Contrataciones vigentes
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Solo de servicios en catálogo
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="BadgeDollarSign" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ money(stats.mrr_addons) }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Ingreso mensual
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            MXN/mes encima de los planes
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                    >
                        <Lucide icon="Building2" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.tenants_with_addons }} de
                            {{ tenants.length }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Hoteles con servicios
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Al menos uno contratado
                        </div>
                    </div>
                </div>
            </div>

            <!-- Listado -->
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
                            placeholder="Buscar servicio o lo que incluye"
                        />
                    </div>
                    <FormSelect v-model="status" class="h-9 text-xs sm:w-48">
                        <option value="">Todos los estados</option>
                        <option value="active">En catálogo</option>
                        <option value="inactive">Fuera del catálogo</option>
                    </FormSelect>
                    <span class="text-xs text-slate-500 sm:ml-auto">
                        {{ filtered.length }}
                        {{ filtered.length === 1 ? 'servicio' : 'servicios' }}
                    </span>
                </div>

                <div
                    v-if="filtered.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="service in filtered"
                        :key="service.key"
                        class="flex flex-col gap-3 px-4 py-3 sm:px-5 lg:flex-row lg:items-center"
                    >
                        <!-- Qué es -->
                        <div class="flex min-w-0 flex-1 items-start gap-3">
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                                :class="
                                    service.active
                                        ? 'border-primary/10 bg-primary/10 text-primary'
                                        : 'border-slate-200 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400'
                                "
                            >
                                <Lucide
                                    :icon="serviceIcon(service.key)"
                                    class="h-4 w-4"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    v-if="displayGroup(service)"
                                    class="truncate text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    {{ displayGroup(service) }}
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        class="text-left text-sm font-medium hover:text-primary"
                                        @click="openEdit(service)"
                                    >
                                        {{ displayTitle(service) }}
                                    </button>
                                    <span
                                        v-if="!service.active"
                                        class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                    >
                                        <Lucide
                                            icon="CirclePause"
                                            class="h-3 w-3"
                                        />
                                        Fuera del catálogo
                                    </span>
                                </div>
                                <p
                                    v-if="service.summary"
                                    class="mt-0.5 line-clamp-2 text-xs text-slate-500"
                                    :title="service.summary"
                                >
                                    {{ service.summary }}
                                </p>
                                <div
                                    class="mt-2 flex flex-wrap items-center gap-1.5"
                                >
                                    <span
                                        v-for="item in service.includes"
                                        :key="item.label"
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="
                                            item.available
                                                ? 'bg-primary/10 text-primary'
                                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                        "
                                        :title="
                                            item.available
                                                ? 'Se le enciende al hotel al contratarlo'
                                                : 'En desarrollo: se puede vender desde ya y su área aparecerá sola cuando esté lista'
                                        "
                                    >
                                        {{ item.label
                                        }}<template v-if="!item.available">
                                            (en desarrollo)</template
                                        >
                                    </span>
                                    <span
                                        v-if="service.ai_monthly_replies"
                                        class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                        title="Cuota mensual de respuestas del bot que aporta este servicio"
                                    >
                                        {{
                                            service.ai_monthly_replies.toLocaleString(
                                                'es-MX',
                                            )
                                        }}
                                        respuestas IA/mes
                                    </span>
                                    <span
                                        v-if="service.requires"
                                        class="inline-flex items-center gap-1 rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                        title="Solo se puede contratar a un hotel que ya tenga este otro servicio; si se lo retiran, este cae con él"
                                    >
                                        <Lucide icon="Link" class="h-3 w-3" />
                                        Amplía «{{ requiredTitle(service) }}»
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Precio y hoteles -->
                        <div
                            class="flex items-center justify-between gap-3 pl-12 lg:contents"
                        >
                            <div class="text-xs lg:w-36 lg:shrink-0">
                                <div class="text-sm font-medium">
                                    {{ money(service.price_monthly) }}
                                    <span
                                        class="text-[11px] font-normal text-slate-500"
                                        >/mes</span
                                    >
                                </div>
                                <div class="text-[11px] text-slate-400">
                                    {{ money(service.activation_fee) }}
                                    de activación
                                </div>
                            </div>
                            <div class="text-xs lg:w-40 lg:shrink-0">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium transition hover:bg-primary/10 hover:text-primary"
                                    :class="
                                        service.tenants.length
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                    title="Ver y cambiar qué hoteles lo tienen contratado"
                                    @click="openManaging(service)"
                                >
                                    <Lucide icon="Building2" class="h-3 w-3" />
                                    {{ hotels(service.tenants.length) }}
                                </button>
                                <div
                                    v-if="service.tenants.length"
                                    class="mt-1 text-[11px] text-slate-400"
                                >
                                    <template v-if="service.active"
                                        >{{ money(monthlyRevenue(service)) }} al
                                        mes</template
                                    >
                                    <template v-else
                                        >Sin cobro: pausado</template
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- Acciones -->
                        <div
                            class="flex items-center justify-end gap-1 border-t border-dashed border-slate-200/70 pt-2 lg:w-36 lg:shrink-0 lg:border-0 lg:pt-0 dark:border-darkmode-400"
                        >
                            <span
                                v-if="service.last_change"
                                class="mr-auto truncate text-[11px] text-slate-400 lg:hidden"
                                :title="service.last_change.at ?? undefined"
                            >
                                Editado {{ service.last_change.ago }}
                            </span>
                            <FormSwitch
                                class="mr-1"
                                :title="
                                    service.active
                                        ? 'En catálogo: apágalo para dejar de venderlo'
                                        : 'Fuera del catálogo: enciéndelo para volver a venderlo'
                                "
                            >
                                <FormSwitch.Input
                                    type="checkbox"
                                    :checked="service.active"
                                    :disabled="togglingKey === service.key"
                                    @click.prevent="
                                        requestToggleActive(service)
                                    "
                                />
                            </FormSwitch>
                            <button
                                type="button"
                                :class="ghostButton"
                                title="Hoteles que lo tienen"
                                @click="openManaging(service)"
                            >
                                <Lucide icon="Building2" class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                :class="ghostButton"
                                :title="
                                    service.last_change
                                        ? `Editar servicio (último cambio ${service.last_change.ago}${service.last_change.by ? ' por ' + service.last_change.by : ''})`
                                        : 'Editar servicio'
                                "
                                @click="openEdit(service)"
                            >
                                <Lucide icon="Pencil" class="h-4 w-4" />
                            </button>
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
                        <Lucide icon="SearchX" class="h-4 w-4" />
                    </div>
                    <p class="text-xs text-slate-500">
                        <template v-if="services.length"
                            >Ningún servicio coincide con la búsqueda.</template
                        >
                        <template v-else
                            >Todavía no hay servicios adicionales en el
                            catálogo.</template
                        >
                    </p>
                    <button
                        v-if="services.length"
                        type="button"
                        class="text-xs font-medium text-primary"
                        @click="clearFilters"
                    >
                        Quitar filtros
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal: hoteles que lo tienen contratado -->
        <Dialog :open="managing !== null" size="lg" @close="closeManaging">
            <Dialog.Panel class="sm:w-[94vw] lg:w-[640px]">
                <div
                    v-if="managing"
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                >
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide
                                :icon="serviceIcon(managing.key)"
                                class="h-4 w-4"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-base font-medium">
                                {{ displayTitle(managing) }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ money(managing.price_monthly) }} MXN/mes por
                                hotel, encima de su plan base.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            title="Cerrar"
                            @click="closeManaging"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>

                    <div
                        class="flex flex-col gap-2 border-b border-slate-200/60 bg-slate-50/70 px-5 py-3 sm:flex-row sm:items-center dark:border-darkmode-400 dark:bg-darkmode-600/40"
                    >
                        <div class="relative flex-1">
                            <Lucide
                                icon="Search"
                                class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                            />
                            <FormInput
                                v-model="tenantSearch"
                                type="text"
                                class="h-9 pl-9 text-xs"
                                placeholder="Buscar hotel"
                            />
                        </div>
                        <div
                            class="inline-flex h-9 shrink-0 rounded-[0.5rem] border border-slate-200 bg-white p-0.5 text-xs dark:border-darkmode-400 dark:bg-darkmode-600"
                        >
                            <button
                                v-for="option in [
                                    {
                                        value: 'contracted',
                                        label: `Contratados (${managing.tenants.length})`,
                                    },
                                    {
                                        value: 'all',
                                        label: `Todos (${tenants.length})`,
                                    },
                                ] as const"
                                :key="option.value"
                                type="button"
                                class="flex-1 rounded-md px-3 font-medium whitespace-nowrap transition"
                                :class="
                                    tenantScope === option.value
                                        ? 'bg-primary/10 text-primary'
                                        : 'text-slate-500 hover:text-primary'
                                "
                                @click="tenantScope = option.value"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <div
                            v-if="contractForm.errors.service"
                            class="mx-5 mt-4 flex items-start gap-2 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                        >
                            <Lucide
                                icon="TriangleAlert"
                                class="mt-px h-3.5 w-3.5 shrink-0"
                            />
                            {{ contractForm.errors.service }}
                        </div>
                        <div
                            v-if="!managing.active"
                            class="mx-5 mt-4 flex items-start gap-2 rounded-lg bg-slate-100 px-3 py-2 text-xs text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                        >
                            <Lucide
                                icon="CirclePause"
                                class="mt-px h-3.5 w-3.5 shrink-0"
                            />
                            Está fuera del catálogo: no se cobra ni enciende
                            nada, y no se puede contratar a hoteles nuevos. Se
                            puede retirar a quien lo tenía.
                        </div>
                        <div
                            v-else-if="managing.requires"
                            class="mx-5 mt-4 flex items-start gap-2 rounded-lg bg-pending/10 px-3 py-2 text-xs text-pending"
                        >
                            <Lucide
                                icon="Link"
                                class="mt-px h-3.5 w-3.5 shrink-0"
                            />
                            Amplía «{{ requiredTitle(managing) }}»: solo se
                            contrata a hoteles que ya lo tengan.
                        </div>

                        <div
                            v-if="managingTenants.length"
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="tenant in managingTenants"
                                :key="tenant.id"
                                class="flex items-center gap-3 px-5 py-3"
                            >
                                <FormSwitch>
                                    <FormSwitch.Input
                                        type="checkbox"
                                        :checked="
                                            managing.tenants.includes(tenant.id)
                                        "
                                        :disabled="
                                            pendingTenant === tenant.id ||
                                            (!managing.tenants.includes(
                                                tenant.id,
                                            ) &&
                                                (!managing.active ||
                                                    missingRequirement(
                                                        managing,
                                                        tenant.id,
                                                    )))
                                        "
                                        @click.prevent="
                                            requestToggleTenant(tenant)
                                        "
                                    />
                                </FormSwitch>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="truncate text-sm font-medium"
                                            >{{ tenant.name }}</span
                                        >
                                        <span
                                            v-if="tenant.suspended"
                                            class="shrink-0 rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                            >Suspendido</span
                                        >
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        Plan {{ tenant.plan_label }}
                                        <template
                                            v-if="
                                                !managing.tenants.includes(
                                                    tenant.id,
                                                ) &&
                                                managing.active &&
                                                missingRequirement(
                                                    managing,
                                                    tenant.id,
                                                )
                                            "
                                        >
                                            ·
                                            <span class="text-pending"
                                                >Necesita primero «{{
                                                    requiredTitle(managing)
                                                }}»</span
                                            >
                                        </template>
                                    </div>
                                </div>
                                <Link
                                    :href="
                                        route('admin.tenants.plan', tenant.id)
                                    "
                                    :class="ghostButton"
                                    title="Ver el plan y los servicios de este hotel"
                                >
                                    <Lucide
                                        icon="ArrowUpRight"
                                        class="h-4 w-4"
                                    />
                                </Link>
                            </div>
                        </div>
                        <p
                            v-else
                            class="px-5 py-10 text-center text-xs text-slate-400"
                        >
                            <template v-if="tenantSearch"
                                >Ningún hotel coincide con la
                                búsqueda.</template
                            >
                            <template v-else-if="tenantScope === 'contracted'"
                                >Ningún hotel lo tiene contratado
                                todavía.</template
                            >
                            <template v-else
                                >Todavía no hay hoteles en la
                                plataforma.</template
                            >
                        </p>
                    </div>

                    <div
                        class="flex items-center gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <span class="mr-auto text-xs text-slate-500">
                            {{ hotels(managing.tenants.length) }}
                            <template v-if="managing.active">
                                ·
                                <span
                                    class="font-medium text-slate-700 dark:text-slate-300"
                                    >{{ money(monthlyRevenue(managing)) }} al
                                    mes</span
                                >
                            </template>
                        </span>
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            @click="closeManaging"
                            >Cerrar</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal: editar servicio -->
        <Dialog :open="editing !== null" size="lg" @close="closeEdit">
            <Dialog.Panel class="sm:w-[94vw] lg:w-[720px]">
                <form
                    v-if="editing"
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                    @submit.prevent="submit"
                >
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide
                                :icon="serviceIcon(editing.key)"
                                class="h-4 w-4"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-base font-medium">
                                Editar «{{ displayTitle(editing) }}»
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                <template v-if="editing.last_change"
                                    >Último cambio {{ editing.last_change.ago
                                    }}<template v-if="editing.last_change.by">
                                        por
                                        <Link
                                            v-if="editing.last_change.by_id"
                                            :href="
                                                route(
                                                    'admin.users.show',
                                                    editing.last_change.by_id,
                                                )
                                            "
                                            class="text-primary"
                                            >{{ editing.last_change.by }}</Link
                                        ><template v-else>{{
                                            editing.last_change.by
                                        }}</template></template
                                    >.</template
                                >
                                Los precios aplican de inmediato a quien ya lo
                                tiene.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            title="Cerrar"
                            @click="closeEdit"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>

                    <div
                        class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4"
                    >
                        <section>
                            <div :class="sectionLabel">
                                <Lucide icon="BadgeCheck" class="h-3.5 w-3.5" />
                                Qué es
                            </div>
                            <div class="grid grid-cols-12 gap-4">
                                <div class="col-span-12">
                                    <label
                                        class="mb-1.5 block text-xs font-medium"
                                        >Nombre</label
                                    >
                                    <FormInput
                                        v-model="form.name"
                                        type="text"
                                        class="h-9 text-xs"
                                        maxlength="255"
                                    />
                                    <FormHelp
                                        v-if="form.errors.name"
                                        class="text-danger"
                                        >{{ form.errors.name }}</FormHelp
                                    >
                                    <FormHelp v-else
                                        >Lo que va antes de « – » se muestra
                                        como rótulo de familia.</FormHelp
                                    >
                                </div>
                                <div class="col-span-12">
                                    <label
                                        class="mb-1.5 block text-xs font-medium"
                                        >Breve resumen</label
                                    >
                                    <FormTextarea
                                        v-model="form.summary"
                                        rows="2"
                                        maxlength="500"
                                        class="text-xs"
                                    />
                                    <FormHelp
                                        v-if="form.errors.summary"
                                        class="text-danger"
                                        >{{ form.errors.summary }}</FormHelp
                                    >
                                    <FormHelp
                                        v-else
                                        class="flex justify-between"
                                        ><span
                                            >Como en la tabla de inversión del
                                            documento comercial.</span
                                        ><span
                                            >{{ form.summary.length }}/500</span
                                        ></FormHelp
                                    >
                                </div>
                            </div>
                        </section>

                        <section
                            class="border-t border-dashed border-slate-200/70 pt-5 dark:border-darkmode-400"
                        >
                            <div :class="sectionLabel">
                                <Lucide icon="Wallet" class="h-3.5 w-3.5" />
                                Precio
                            </div>
                            <div class="grid grid-cols-12 gap-4">
                                <div class="col-span-12 sm:col-span-6">
                                    <label
                                        class="mb-1.5 block text-xs font-medium"
                                        >Inversión mensual (MXN)</label
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="DollarSign"
                                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                        />
                                        <FormInput
                                            v-model="form.price_monthly"
                                            type="number"
                                            min="0"
                                            step="1"
                                            class="h-9 pl-9 text-xs"
                                        />
                                    </div>
                                    <FormHelp
                                        v-if="form.errors.price_monthly"
                                        class="text-danger"
                                        >{{
                                            form.errors.price_monthly
                                        }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <label
                                        class="mb-1.5 block text-xs font-medium"
                                        >Cuota única de activación (MXN)</label
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="DollarSign"
                                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                        />
                                        <FormInput
                                            v-model="form.activation_fee"
                                            type="number"
                                            min="0"
                                            step="1"
                                            class="h-9 pl-9 text-xs"
                                        />
                                    </div>
                                    <FormHelp
                                        v-if="form.errors.activation_fee"
                                        class="text-danger"
                                        >{{
                                            form.errors.activation_fee
                                        }}</FormHelp
                                    >
                                </div>
                                <div
                                    v-if="editImpact"
                                    class="col-span-12 flex items-start gap-2 rounded-lg bg-pending/10 px-3 py-2 text-xs text-pending"
                                >
                                    <Lucide
                                        icon="TriangleAlert"
                                        class="mt-px h-3.5 w-3.5 shrink-0"
                                    />
                                    <span>
                                        Afecta a
                                        {{ hotels(editing.tenants.length) }}: el
                                        cobro mensual por este servicio pasa de
                                        {{ money(editImpact.before) }} a
                                        <span class="font-medium">{{
                                            money(editImpact.after)
                                        }}</span
                                        >.
                                    </span>
                                </div>
                            </div>
                        </section>

                        <section
                            class="border-t border-dashed border-slate-200/70 pt-5 dark:border-darkmode-400"
                        >
                            <div :class="sectionLabel">
                                <Lucide icon="Target" class="h-3.5 w-3.5" />
                                Para la venta
                            </div>
                            <div class="grid grid-cols-12 gap-4">
                                <div class="col-span-12 sm:col-span-6">
                                    <label
                                        class="mb-1.5 block text-xs font-medium"
                                        >Objetivo</label
                                    >
                                    <FormTextarea
                                        v-model="form.objective"
                                        rows="4"
                                        maxlength="1000"
                                        class="text-xs"
                                    />
                                    <FormHelp
                                        v-if="form.errors.objective"
                                        class="text-danger"
                                        >{{ form.errors.objective }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <label
                                        class="mb-1.5 block text-xs font-medium"
                                        >Recomendado para</label
                                    >
                                    <FormTextarea
                                        v-model="form.recommendation"
                                        rows="4"
                                        maxlength="1000"
                                        class="text-xs"
                                    />
                                    <FormHelp
                                        v-if="form.errors.recommendation"
                                        class="text-danger"
                                        >{{
                                            form.errors.recommendation
                                        }}</FormHelp
                                    >
                                </div>
                            </div>
                        </section>

                        <section
                            v-if="
                                editing.includes.length ||
                                editing.ai_monthly_replies ||
                                editing.requires
                            "
                            class="border-t border-dashed border-slate-200/70 pt-5 dark:border-darkmode-400"
                        >
                            <div :class="sectionLabel">
                                <Lucide icon="Sparkles" class="h-3.5 w-3.5" />
                                Al contratarlo, el hotel recibe
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <span
                                    v-for="item in editing.includes"
                                    :key="item.label"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        item.available
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                >
                                    {{ item.label
                                    }}<template v-if="!item.available">
                                        (en desarrollo)</template
                                    >
                                </span>
                                <span
                                    v-if="editing.ai_monthly_replies"
                                    class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                >
                                    {{
                                        editing.ai_monthly_replies.toLocaleString(
                                            'es-MX',
                                        )
                                    }}
                                    respuestas IA/mes
                                </span>
                                <span
                                    v-if="editing.requires"
                                    class="inline-flex items-center gap-1 rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                >
                                    <Lucide icon="Link" class="h-3 w-3" />
                                    Amplía «{{ requiredTitle(editing) }}»
                                </span>
                            </div>
                            <FormHelp class="mt-2">
                                Es fijo por servicio; qué tiene cada hotel se ve
                                en su ficha.
                            </FormHelp>
                        </section>
                    </div>

                    <div
                        class="flex flex-col gap-3 border-t border-slate-200/70 px-5 py-3.5 sm:flex-row sm:items-center dark:border-darkmode-400"
                    >
                        <label
                            class="flex cursor-pointer items-center gap-2.5 sm:mr-auto"
                            title="Fuera del catálogo deja de venderse, de cobrarse y de encender lo que incluye"
                        >
                            <FormSwitch>
                                <FormSwitch.Input
                                    v-model="form.active"
                                    type="checkbox"
                                />
                            </FormSwitch>
                            <span class="text-xs">
                                <span class="font-medium">{{
                                    form.active
                                        ? 'En catálogo'
                                        : 'Fuera del catálogo'
                                }}</span>
                                <span
                                    v-if="
                                        !form.active && editing.tenants.length
                                    "
                                    class="block text-[11px] text-danger"
                                    >{{ hotels(editing.tenants.length) }} lo
                                    pierden al guardar</span
                                >
                            </span>
                        </label>
                        <div class="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline-secondary"
                                class="h-9 rounded-[0.5rem] px-5 text-xs"
                                @click="closeEdit"
                                >Cancelar</Button
                            >
                            <Button
                                type="submit"
                                variant="primary"
                                class="h-9 rounded-[0.5rem] px-5 text-xs shadow-md shadow-primary/20"
                                :disabled="form.processing || !form.isDirty"
                            >
                                <Lucide
                                    icon="Check"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                {{
                                    form.processing ? 'Guardando...' : 'Guardar'
                                }}
                            </Button>
                        </div>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmación: sacar del catálogo -->
        <Dialog
            :open="deactivating !== null"
            @close="!activeForm.processing && (deactivating = null)"
        >
            <Dialog.Panel>
                <div v-if="deactivating" class="p-5">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                        >
                            <Lucide icon="CirclePause" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-base font-medium">
                                Sacar «{{ displayTitle(deactivating) }}» del
                                catálogo
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ hotels(deactivating.tenants.length) }} lo
                                tienen contratado: dejan de recibir lo que
                                incluye y deja de cobrárseles ({{
                                    money(monthlyRevenue(deactivating))
                                }}
                                al mes en total). Las contrataciones se guardan
                                y vuelven a aplicar si lo reactivas.
                            </p>
                            <div
                                v-if="deactivating.includes.length"
                                class="mt-3 flex flex-wrap gap-1.5"
                            >
                                <span
                                    v-for="item in deactivating.includes"
                                    :key="item.label"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                    >{{ item.label }}</span
                                >
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="activeForm.processing"
                            @click="deactivating = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="activeForm.processing"
                            @click="setActive(deactivating, false)"
                        >
                            <Lucide
                                icon="CirclePause"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            {{
                                activeForm.processing
                                    ? 'Guardando...'
                                    : 'Sí, sacarlo'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmación: retirar el servicio a un hotel -->
        <Dialog
            :open="retiring !== null"
            @close="!contractForm.processing && (retiring = null)"
        >
            <Dialog.Panel>
                <div v-if="retiring" class="p-5">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                        >
                            <Lucide icon="Unplug" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-base font-medium">
                                Retirar «{{ displayTitle(retiring.service) }}» a
                                {{ retiring.tenant.name }}
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">
                                Deja de cobrársele y pierde al instante lo que
                                incluye. Se puede volver a contratar cuando
                                quieras.
                            </p>
                            <div
                                v-if="retiringAlso.length"
                                class="mt-3 rounded-lg border border-dashed border-danger/30 px-3 py-2 text-xs"
                            >
                                <div class="font-medium text-danger">
                                    También se le retira, porque lo amplía:
                                </div>
                                <ul
                                    class="mt-1 list-disc pl-4 text-slate-600 dark:text-slate-300"
                                >
                                    <li v-for="d in retiringAlso" :key="d.key">
                                        {{ displayTitle(d) }}
                                    </li>
                                </ul>
                            </div>
                            <p
                                v-if="contractForm.errors.service"
                                class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                            >
                                {{ contractForm.errors.service }}
                            </p>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="contractForm.processing"
                            @click="retiring = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="contractForm.processing"
                            @click="
                                setContract(
                                    retiring.service,
                                    retiring.tenant,
                                    false,
                                )
                            "
                        >
                            <Lucide icon="Unplug" class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                contractForm.processing
                                    ? 'Retirando...'
                                    : 'Sí, retirar'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
