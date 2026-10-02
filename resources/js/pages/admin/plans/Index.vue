<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormLabel,
    FormSwitch,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface PlanRow {
    key: string;
    label: string;
    description: string | null;
    price_monthly: number;
    activation_fee: number;
    max_properties: number | null;
    max_rooms: number | null;
    max_users: number | null;
    max_channels: number | null;
    max_gateways: number | null;
    modules: string[];
    ai_enabled: boolean;
    ai_monthly_replies: number | null;
    active: boolean;
    public: boolean;
    tenants: number;
    last_change: {
        ago: string | null;
        at: string | null;
        by: string | null;
        by_id: number | null;
    } | null;
}

interface ModuleDef {
    label: string;
    description: string;
    available: boolean;
    group?: string;
}

interface GroupDef {
    label: string;
    description: string;
    icon: string;
}

const props = defineProps<{
    plans: PlanRow[];
    moduleCatalog: Record<string, ModuleDef>;
    moduleGroups: Record<string, GroupDef>;
    addonModules: Record<string, string[]>;
}>();

const money = (n: number) => `$${n.toLocaleString('es-MX')}`;

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex items-start gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const sectionLabel =
    'mb-2.5 text-[11px] font-medium tracking-wide text-slate-400 uppercase';

// Cifras del encabezado: lo que el catálogo le deja a la plataforma.
const stats = computed(() => {
    const active = props.plans.filter((p) => p.active);
    return {
        active: active.length,
        total: props.plans.length,
        tenants: props.plans.reduce((sum, p) => sum + p.tenants, 0),
        monthly: props.plans.reduce(
            (sum, p) => sum + p.tenants * p.price_monthly,
            0,
        ),
        custom: props.plans.filter((p) => !p.public).length,
    };
});

// Los límites de la tarjeta, en el orden en que se venden.
const limitRows = (plan: PlanRow) =>
    [
        {
            icon: 'Building2',
            label: 'Propiedades',
            value: limit(plan.max_properties),
        },
        {
            icon: 'BedDouble',
            label: 'Habitaciones',
            value: limit(plan.max_rooms),
        },
        { icon: 'Users', label: 'Usuarios', value: limit(plan.max_users) },
        {
            icon: 'MessageCircle',
            label: 'Canales de mensajería',
            value: limit(plan.max_channels),
        },
        {
            icon: 'CreditCard',
            label: 'Pasarelas de pago',
            value:
                plan.max_gateways === 0
                    ? 'Solo transferencias'
                    : limit(plan.max_gateways),
        },
    ] as const;
const limit = (n: number | null) => (n === null ? 'Sin límite' : String(n));

// Catálogo como lista ordenada (config/modules.php define el orden). Los
// módulos que vende algún servicio adicional se agrupan aparte para no
// confundirlos con los incluidos del plan.
const moduleList = Object.entries(props.moduleCatalog).map(([key, def]) => ({
    key,
    ...def,
}));
const planModuleList = moduleList.filter((m) => !props.addonModules[m.key]);
const addonModuleList = moduleList.filter((m) => props.addonModules[m.key]);
const moduleLabel = (key: string) => props.moduleCatalog[key]?.label ?? key;
const soldBy = (key: string) =>
    props.addonModules[key]?.length
        ? `Lo vende: ${props.addonModules[key].join(' · ')}`
        : undefined;
const planOwnModules = (plan: PlanRow) =>
    plan.modules.filter((k) => !props.addonModules[k]);
const planAddonModules = (plan: PlanRow) =>
    plan.modules.filter((k) => props.addonModules[k]);

// Las tarjetas van de 3 a 25 módulos: sin tope, la más llena estiraba a
// sus vecinas y les dejaba un hueco enorme. Se enseñan los primeros y el
// resto se abre a pedido, tarjeta por tarjeta.
const OWN_CAP = 6;
const ADDON_CAP = 4;
const expanded = ref<string[]>([]);
const isExpanded = (plan: PlanRow) => expanded.value.includes(plan.key);
const toggleExpanded = (plan: PlanRow) =>
    (expanded.value = isExpanded(plan)
        ? expanded.value.filter((k) => k !== plan.key)
        : [...expanded.value, plan.key]);
const shown = (plan: PlanRow, list: string[], cap: number) =>
    isExpanded(plan) ? list : list.slice(0, cap);
const hiddenCount = (plan: PlanRow) =>
    Math.max(0, planOwnModules(plan).length - OWN_CAP) +
    Math.max(0, planAddonModules(plan).length - ADDON_CAP);

// Los módulos del plan van por familia (config/module_groups.php): 25
// interruptores seguidos no se leen. Las familias vacías no se pintan y un
// módulo sin familia cae en la última ('otros'), nunca se pierde.
const groupedPlanModules = computed(() =>
    Object.entries(props.moduleGroups)
        .map(([key, group]) => ({
            key,
            ...group,
            modules: planModuleList.filter((m) => (m.group ?? 'otros') === key),
        }))
        .filter((group) => group.modules.length > 0),
);

const groupedAddonModules = computed(() =>
    Object.entries(props.moduleGroups)
        .map(([key, group]) => ({
            key,
            ...group,
            modules: addonModuleList.filter(
                (m) => (m.group ?? 'otros') === key,
            ),
        }))
        .filter((group) => group.modules.length > 0),
);

const groupActiveCount = (keys: string[]) =>
    keys.filter((k) => form.modules.includes(k)).length;

/** Prende o apaga de un golpe toda una familia. */
function toggleGroup(keys: string[]) {
    const allOn = keys.every((k) => form.modules.includes(k));

    form.modules = allOn
        ? form.modules.filter((k) => !keys.includes(k))
        : [...new Set([...form.modules, ...keys])];
}

// Crear / editar comparten formulario; editing !== null distingue.
const showForm = ref(false);
const editing = ref<PlanRow | null>(null);
const form = useForm({
    key: '',
    label: '',
    description: '',
    price_monthly: 0 as number | string,
    activation_fee: 0 as number | string,
    max_properties: '' as number | string,
    max_rooms: '' as number | string,
    max_users: '' as number | string,
    max_channels: '' as number | string,
    max_gateways: '' as number | string,
    modules: [] as string[],
    ai_monthly_replies: '' as number | string,
    active: true,
    public: true,
});

function toggleModule(key: string) {
    form.modules = form.modules.includes(key)
        ? form.modules.filter((m) => m !== key)
        : [...form.modules, key];
}

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}

function openEdit(plan: PlanRow) {
    editing.value = plan;
    form.clearErrors();
    form.key = plan.key;
    form.label = plan.label;
    form.description = plan.description ?? '';
    form.price_monthly = plan.price_monthly;
    form.activation_fee = plan.activation_fee;
    form.max_properties = plan.max_properties ?? '';
    form.max_rooms = plan.max_rooms ?? '';
    form.max_users = plan.max_users ?? '';
    form.max_channels = plan.max_channels ?? '';
    form.max_gateways = plan.max_gateways ?? '';
    form.modules = [...plan.modules];
    // 0 heredado del seed se muestra como vacío (placeholder "Sin límite").
    form.ai_monthly_replies =
        plan.ai_monthly_replies && plan.ai_monthly_replies > 0
            ? plan.ai_monthly_replies
            : '';
    form.active = plan.active;
    form.public = plan.public;
    showForm.value = true;
}

function submit() {
    const transform = (data: Record<string, unknown>) => ({
        ...data,
        description: data.description === '' ? null : data.description,
        max_properties:
            data.max_properties === '' ? null : Number(data.max_properties),
        max_rooms: data.max_rooms === '' ? null : Number(data.max_rooms),
        max_users: data.max_users === '' ? null : Number(data.max_users),
        max_channels:
            data.max_channels === '' ? null : Number(data.max_channels),
        max_gateways:
            data.max_gateways === '' ? null : Number(data.max_gateways),
        // Sin módulo agente-ia o valor vacío/inválido = sin límite (null).
        ai_monthly_replies:
            !(data.modules as string[]).includes('agente-ia') ||
            data.ai_monthly_replies === '' ||
            Number(data.ai_monthly_replies) < 1
                ? null
                : Number(data.ai_monthly_replies),
        price_monthly: Number(data.price_monthly || 0),
        activation_fee: Number(data.activation_fee || 0),
    });

    if (editing.value) {
        form.transform(transform).patch(
            route('admin.plans.update', editing.value.key),
            {
                onSuccess: () => (showForm.value = false),
            },
        );
    } else {
        form.transform(transform).post(route('admin.plans.store'), {
            onSuccess: () => (showForm.value = false),
        });
    }
}

// Activar/desactivar rápido (sin abrir el modal).
function toggleActive(plan: PlanRow) {
    useForm({
        label: plan.label,
        description: plan.description,
        price_monthly: plan.price_monthly,
        activation_fee: plan.activation_fee,
        max_properties: plan.max_properties,
        max_rooms: plan.max_rooms,
        max_users: plan.max_users,
        max_channels: plan.max_channels,
        max_gateways: plan.max_gateways,
        modules: plan.modules,
        ai_monthly_replies: plan.ai_monthly_replies,
        active: !plan.active,
    }).patch(route('admin.plans.update', plan.key), { preserveScroll: true });
}

const deleting = ref<PlanRow | null>(null);
const deleteForm = useForm({});

function submitDelete() {
    if (!deleting.value) return;
    deleteForm.delete(route('admin.plans.destroy', deleting.value.key), {
        onSuccess: () => (deleting.value = null),
        onError: () => (deleting.value = null),
    });
}
</script>

<template>
    <RazeLayout title="Planes">
        <div class="mt-2">
            <!-- Encabezado -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Layers" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Planes de la plataforma
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Límites, módulos y precio por plan. Los cambios
                            aplican de inmediato a los hoteles del plan.
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Button
                        :as="Link"
                        :href="route('admin.services')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide icon="PackagePlus" class="mr-1.5 h-3.5 w-3.5" />
                        Servicios adicionales
                    </Button>
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        @click="openCreate"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Nuevo plan
                    </Button>
                </div>
            </div>

            <div
                v-if="$page.props.errors?.plan"
                class="box box--stacked mt-4 flex items-start gap-2 border-danger/20 bg-danger/5 px-4 py-3 text-xs text-danger"
            >
                <Lucide
                    icon="TriangleAlert"
                    class="mt-0.5 h-3.5 w-3.5 shrink-0"
                />
                {{ $page.props.errors.plan }}
            </div>

            <!-- Cifras -->
            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Layers" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.active }} de {{ stats.total }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Planes activos
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Los que se ofrecen a hoteles nuevos
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="Building2" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.tenants }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Hoteles con plan
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Repartidos en todo el catálogo
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                    >
                        <Lucide icon="Wallet" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ money(stats.monthly) }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Al mes por planes
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Precio de lista, sin servicios adicionales
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                    >
                        <Lucide icon="EyeOff" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.custom }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            A la medida
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            No salen en la página de inicio
                        </div>
                    </div>
                </div>
            </div>

            <!-- Catálogo -->
            <div class="mt-4 grid grid-cols-12 items-stretch gap-5">
                <div
                    v-for="plan in plans"
                    :key="plan.key"
                    class="col-span-12 flex flex-col md:col-span-6 xl:col-span-4"
                >
                    <div
                        class="box box--stacked flex flex-1 flex-col overflow-hidden"
                    >
                        <!-- Identidad, precio y el interruptor de activo -->
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    plan.active
                                        ? 'border-primary/10 bg-primary/10 text-primary'
                                        : 'border-slate-200 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400',
                                ]"
                            >
                                <Lucide icon="Layers" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">
                                    {{ plan.label }}
                                </div>
                                <div
                                    class="mt-1 flex flex-wrap items-center gap-1.5"
                                >
                                    <span
                                        class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-500 dark:bg-darkmode-400"
                                        >{{ plan.key }}</span
                                    >
                                    <span
                                        v-if="!plan.active"
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                        >Inactivo</span
                                    >
                                    <span
                                        v-if="!plan.public"
                                        class="inline-flex items-center gap-1 rounded-full bg-info/10 px-2 py-0.5 text-[11px] font-medium text-info"
                                        title="No se anuncia en la página de inicio; solo lo asignas tú"
                                    >
                                        <Lucide icon="EyeOff" class="h-3 w-3" />
                                        A la medida
                                    </span>
                                </div>
                            </div>
                            <FormSwitch
                                class="mt-1 shrink-0"
                                :title="
                                    plan.active
                                        ? 'Activo: se ofrece a hoteles nuevos'
                                        : 'Inactivo: no se ofrece a hoteles nuevos (los existentes conservan el plan)'
                                "
                            >
                                <FormSwitch.Input
                                    :checked="plan.active"
                                    type="checkbox"
                                    @change="toggleActive(plan)"
                                />
                            </FormSwitch>
                        </div>

                        <div class="px-4 py-3">
                            <div class="flex items-baseline gap-1.5 text-xs">
                                <span
                                    class="text-sm font-medium text-slate-700 dark:text-slate-200"
                                    >{{ money(plan.price_monthly) }}</span
                                >
                                <span class="text-slate-500">MXN al mes</span>
                                <template v-if="plan.activation_fee">
                                    <span class="text-slate-300">·</span>
                                    <span class="text-slate-500"
                                        >{{ money(plan.activation_fee) }} de
                                        activación</span
                                    >
                                </template>
                            </div>
                            <p
                                v-if="plan.description"
                                class="mt-1 line-clamp-2 text-xs text-slate-500"
                            >
                                {{ plan.description }}
                            </p>
                        </div>

                        <!-- Límites -->
                        <div
                            class="divide-y divide-slate-200/60 border-t border-slate-200/60 dark:divide-darkmode-400 dark:border-darkmode-400"
                        >
                            <div
                                v-for="row in limitRows(plan)"
                                :key="row.label"
                                class="flex items-center gap-2.5 px-4 py-2 text-xs"
                            >
                                <Lucide
                                    :icon="row.icon"
                                    class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                />
                                <span class="text-slate-500">{{
                                    row.label
                                }}</span>
                                <span
                                    class="ml-auto font-medium text-slate-700 dark:text-slate-300"
                                    >{{ row.value }}</span
                                >
                            </div>
                        </div>

                        <!-- Módulos: crece lo que haga falta y empuja el pie
                             hacia abajo para que las tarjetas queden parejas -->
                        <div
                            class="flex-1 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div :class="sectionLabel">
                                Módulos incluidos ·
                                {{ planOwnModules(plan).length }}
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <span
                                    v-for="key in shown(
                                        plan,
                                        planOwnModules(plan),
                                        OWN_CAP,
                                    )"
                                    :key="key"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        moduleCatalog[key]?.available === false
                                            ? 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                            : 'bg-primary/10 text-primary'
                                    "
                                    :title="
                                        moduleCatalog[key]?.available === false
                                            ? 'En desarrollo: su área aparecerá cuando esté lista'
                                            : moduleCatalog[key]?.description
                                    "
                                >
                                    {{ moduleLabel(key) }}
                                </span>
                                <span
                                    v-if="!planOwnModules(plan).length"
                                    class="text-xs text-slate-400"
                                    >Solo el núcleo hotelero</span
                                >
                            </div>
                            <template v-if="planAddonModules(plan).length">
                                <div
                                    :class="sectionLabel"
                                    class="mt-3"
                                    title="Normalmente se venden como servicio adicional; este plan los trae de fábrica"
                                >
                                    De servicios adicionales ·
                                    {{ planAddonModules(plan).length }}
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <span
                                        v-for="key in shown(
                                            plan,
                                            planAddonModules(plan),
                                            ADDON_CAP,
                                        )"
                                        :key="key"
                                        class="rounded-full bg-info/10 px-2 py-0.5 text-[11px] font-medium text-info"
                                        :title="soldBy(key)"
                                    >
                                        {{ moduleLabel(key)
                                        }}<template v-if="key === 'agente-ia'">
                                            ·
                                            {{
                                                plan.ai_monthly_replies === null
                                                    ? 'sin límite'
                                                    : `${plan.ai_monthly_replies}/mes`
                                            }}</template
                                        >
                                    </span>
                                </div>
                            </template>
                            <button
                                v-if="hiddenCount(plan) || isExpanded(plan)"
                                type="button"
                                class="mt-2.5 text-xs font-medium text-primary hover:underline"
                                @click="toggleExpanded(plan)"
                            >
                                {{
                                    isExpanded(plan)
                                        ? 'Ver menos'
                                        : hiddenCount(plan) === 1
                                          ? '+1 módulo más'
                                          : `+${hiddenCount(plan)} módulos más`
                                }}
                            </button>
                        </div>

                        <!-- Pie: hoteles, último cambio y acciones -->
                        <div
                            class="flex items-center gap-2 border-t border-slate-200/60 bg-slate-50/70 px-4 py-2.5 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                        >
                            <div class="min-w-0 flex-1">
                                <Link
                                    v-if="plan.tenants"
                                    :href="`${route('admin.tenants.index')}?plan=${plan.key}`"
                                    class="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline"
                                    title="Ver los hoteles de este plan"
                                >
                                    <Lucide
                                        icon="Building2"
                                        class="h-3.5 w-3.5"
                                    />
                                    {{ plan.tenants }}
                                    {{
                                        plan.tenants === 1 ? 'hotel' : 'hoteles'
                                    }}
                                </Link>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1.5 text-xs text-slate-400"
                                >
                                    <Lucide
                                        icon="Building2"
                                        class="h-3.5 w-3.5"
                                    />
                                    Sin hoteles
                                </span>
                                <div
                                    v-if="plan.last_change"
                                    class="truncate text-[11px] text-slate-400"
                                    :title="plan.last_change.at ?? undefined"
                                >
                                    Editado {{ plan.last_change.ago }}
                                    <template v-if="plan.last_change.by"
                                        >por
                                        <Link
                                            v-if="plan.last_change.by_id"
                                            :href="
                                                route(
                                                    'admin.users.show',
                                                    plan.last_change.by_id,
                                                )
                                            "
                                            class="hover:text-primary"
                                            >{{ plan.last_change.by }}</Link
                                        ></template
                                    >
                                </div>
                            </div>
                            <button
                                type="button"
                                title="Editar plan"
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                @click="openEdit(plan)"
                            >
                                <Lucide icon="Pencil" class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                :title="
                                    plan.tenants
                                        ? 'Hay hoteles en este plan: desactívalo en su lugar'
                                        : 'Eliminar plan'
                                "
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full transition"
                                :class="
                                    plan.tenants
                                        ? 'cursor-not-allowed text-slate-300 dark:text-darkmode-400'
                                        : 'text-slate-500 hover:bg-danger/10 hover:text-danger'
                                "
                                @click="!plan.tenants && (deleting = plan)"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal crear / editar -->
        <Dialog :open="showForm" size="xl" @close="showForm = false">
            <Dialog.Panel class="sm:w-[94vw] lg:w-[820px]">
                <form
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                    @submit.prevent="submit"
                >
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Layers" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                {{
                                    editing
                                        ? `Editar plan ${editing.label}`
                                        : 'Nuevo plan'
                                }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Aplica de inmediato a los hoteles del plan.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            title="Cerrar"
                            @click="showForm = false"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>

                    <div
                        class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4"
                    >
                        <!-- Qué es -->
                        <section>
                            <div :class="sectionLabel">Qué es</div>
                            <div class="grid grid-cols-12 gap-4">
                                <div
                                    v-if="!editing"
                                    class="col-span-12 sm:col-span-4"
                                >
                                    <FormLabel class="text-xs"
                                        >Clave interna</FormLabel
                                    >
                                    <FormInput
                                        v-model="form.key"
                                        type="text"
                                        class="h-9 font-mono text-xs"
                                        placeholder="premium"
                                    />
                                    <FormHelp
                                        v-if="form.errors.key"
                                        class="text-danger"
                                        >{{ form.errors.key }}</FormHelp
                                    >
                                    <FormHelp v-else
                                        >No se puede cambiar después.</FormHelp
                                    >
                                </div>
                                <div
                                    class="col-span-12"
                                    :class="
                                        editing
                                            ? 'sm:col-span-12'
                                            : 'sm:col-span-8'
                                    "
                                >
                                    <FormLabel class="text-xs"
                                        >Nombre del plan</FormLabel
                                    >
                                    <FormInput
                                        v-model="form.label"
                                        type="text"
                                        class="h-9 text-xs"
                                        placeholder="Premium"
                                    />
                                    <FormHelp
                                        v-if="form.errors.label"
                                        class="text-danger"
                                        >{{ form.errors.label }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12">
                                    <FormLabel class="text-xs"
                                        >Descripción</FormLabel
                                    >
                                    <FormInput
                                        v-model="form.description"
                                        type="text"
                                        maxlength="160"
                                        class="h-9 text-xs"
                                        placeholder="Para hoteles y moteles que empiezan: lo esencial para operar en línea."
                                    />
                                    <FormHelp
                                        v-if="form.errors.description"
                                        class="text-danger"
                                        >{{ form.errors.description }}</FormHelp
                                    >
                                    <FormHelp v-else
                                        >Una línea de venta; se ve al asignar
                                        plan a un hotel.</FormHelp
                                    >
                                </div>
                            </div>
                        </section>

                        <!-- Precio -->
                        <section>
                            <div :class="sectionLabel">Precio</div>
                            <div class="grid grid-cols-12 gap-4">
                                <div class="col-span-12 sm:col-span-6">
                                    <FormLabel class="text-xs"
                                        >Mensualidad (MXN)</FormLabel
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
                                    <FormHelp v-else
                                        >Informativo; el cobro llega con la fase
                                        de facturación.</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <FormLabel class="text-xs"
                                        >Activación, pago único (MXN)</FormLabel
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
                            </div>
                        </section>

                        <!-- Límites -->
                        <section>
                            <div :class="sectionLabel">Límites</div>
                            <div class="grid grid-cols-12 gap-4">
                                <div class="col-span-6 sm:col-span-4">
                                    <FormLabel class="text-xs"
                                        >Propiedades</FormLabel
                                    >
                                    <FormInput
                                        v-model="form.max_properties"
                                        type="number"
                                        min="1"
                                        class="h-9 text-xs"
                                        placeholder="Sin límite"
                                    />
                                    <FormHelp
                                        v-if="form.errors.max_properties"
                                        class="text-danger"
                                        >{{
                                            form.errors.max_properties
                                        }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-6 sm:col-span-4">
                                    <FormLabel class="text-xs"
                                        >Habitaciones</FormLabel
                                    >
                                    <FormInput
                                        v-model="form.max_rooms"
                                        type="number"
                                        min="1"
                                        class="h-9 text-xs"
                                        placeholder="Sin límite"
                                    />
                                    <FormHelp
                                        v-if="form.errors.max_rooms"
                                        class="text-danger"
                                        >{{ form.errors.max_rooms }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-6 sm:col-span-4">
                                    <FormLabel class="text-xs"
                                        >Usuarios</FormLabel
                                    >
                                    <FormInput
                                        v-model="form.max_users"
                                        type="number"
                                        min="1"
                                        class="h-9 text-xs"
                                        placeholder="Sin límite"
                                    />
                                    <FormHelp
                                        v-if="form.errors.max_users"
                                        class="text-danger"
                                        >{{ form.errors.max_users }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-6 sm:col-span-6">
                                    <FormLabel class="text-xs"
                                        >Canales de mensajería</FormLabel
                                    >
                                    <FormInput
                                        v-model="form.max_channels"
                                        type="number"
                                        min="0"
                                        class="h-9 text-xs"
                                        placeholder="Sin límite"
                                    />
                                    <FormHelp
                                        v-if="form.errors.max_channels"
                                        class="text-danger"
                                        >{{
                                            form.errors.max_channels
                                        }}</FormHelp
                                    >
                                    <FormHelp v-else
                                        >WhatsApp y páginas; el webchat no
                                        cuenta.</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <FormLabel class="text-xs"
                                        >Pasarelas de pago</FormLabel
                                    >
                                    <FormInput
                                        v-model="form.max_gateways"
                                        type="number"
                                        min="0"
                                        class="h-9 text-xs"
                                        placeholder="Sin límite"
                                    />
                                    <FormHelp
                                        v-if="form.errors.max_gateways"
                                        class="text-danger"
                                        >{{
                                            form.errors.max_gateways
                                        }}</FormHelp
                                    >
                                    <FormHelp v-else
                                        >Stripe o Mercado Pago; 0 = solo
                                        transferencias.</FormHelp
                                    >
                                </div>
                            </div>
                            <p class="mt-2 text-xs text-slate-400">
                                Un límite vacío significa "sin límite".
                            </p>
                        </section>

                        <!-- Módulos incluidos -->
                        <section>
                            <div :class="sectionLabel">Módulos incluidos</div>
                            <div class="space-y-4">
                                <div
                                    v-for="group in groupedPlanModules"
                                    :key="group.key"
                                    class="rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                                >
                                    <!-- Cabecera de familia: cuántos van
                                         encendidos y el atajo para prender o
                                         apagar la familia completa. -->
                                    <div
                                        class="flex flex-wrap items-center gap-x-2.5 gap-y-1 border-b border-slate-200/60 px-3 py-2.5 dark:border-darkmode-400"
                                    >
                                        <div
                                            class="flex h-8 w-8 flex-none items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                                        >
                                            <Lucide
                                                :icon="group.icon as never"
                                                class="h-4 w-4"
                                            />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm font-medium">
                                                {{ group.label }}
                                            </div>
                                            <div
                                                class="truncate text-xs text-slate-500"
                                            >
                                                {{ group.description }}
                                            </div>
                                        </div>
                                        <span
                                            class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                                        >
                                            {{
                                                groupActiveCount(
                                                    group.modules.map(
                                                        (m) => m.key,
                                                    ),
                                                )
                                            }}
                                            de {{ group.modules.length }}
                                        </span>
                                        <button
                                            type="button"
                                            class="text-xs font-medium text-primary hover:underline"
                                            @click="
                                                toggleGroup(
                                                    group.modules.map(
                                                        (m) => m.key,
                                                    ),
                                                )
                                            "
                                        >
                                            {{
                                                groupActiveCount(
                                                    group.modules.map(
                                                        (m) => m.key,
                                                    ),
                                                ) === group.modules.length
                                                    ? 'Quitar todos'
                                                    : 'Incluir todos'
                                            }}
                                        </button>
                                    </div>
                                    <div
                                        class="grid divide-y divide-slate-200/60 sm:grid-cols-2 sm:divide-y-0 dark:divide-darkmode-400"
                                    >
                                        <label
                                            v-for="mod in group.modules"
                                            :key="mod.key"
                                            class="flex cursor-pointer items-start gap-3 px-3 py-2.5 transition hover:bg-slate-50/70 dark:hover:bg-darkmode-400/30"
                                        >
                                            <FormSwitch class="mt-0.5 shrink-0">
                                                <FormSwitch.Input
                                                    type="checkbox"
                                                    :checked="
                                                        form.modules.includes(
                                                            mod.key,
                                                        )
                                                    "
                                                    @change="
                                                        toggleModule(mod.key)
                                                    "
                                                />
                                            </FormSwitch>
                                            <span class="min-w-0 flex-1">
                                                <span
                                                    class="flex flex-wrap items-center gap-1.5 text-xs font-medium"
                                                >
                                                    {{ mod.label }}
                                                    <span
                                                        v-if="!mod.available"
                                                        class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                                        title="Se puede incluir desde ya; su área aparecerá sola cuando esté lista"
                                                        >En desarrollo</span
                                                    >
                                                </span>
                                                <span
                                                    class="mt-0.5 block text-[11px] leading-snug text-slate-500"
                                                    >{{ mod.description }}</span
                                                >
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <p class="mt-2 text-xs text-slate-400">
                                El núcleo hotelero (plano, reservas,
                                habitaciones, huéspedes) va en todos los planes.
                            </p>
                        </section>

                        <!-- Módulos que se venden como servicios adicionales -->
                        <section>
                            <div :class="sectionLabel">
                                De servicios adicionales
                            </div>
                            <p class="-mt-1 mb-3 text-xs text-slate-500">
                                Normalmente el hotel los obtiene contratando un
                                servicio adicional y se cobran aparte.
                                Enciéndelos aquí solo si este plan los trae de
                                fábrica.
                            </p>
                            <div
                                class="divide-y divide-slate-200/60 rounded-lg border border-slate-200/70 dark:divide-darkmode-400 dark:border-darkmode-400"
                            >
                                <template
                                    v-for="group in groupedAddonModules"
                                    :key="group.key"
                                >
                                    <div
                                        v-for="mod in group.modules"
                                        :key="mod.key"
                                    >
                                        <label
                                            class="flex cursor-pointer items-start gap-3 px-3 py-2.5 transition hover:bg-slate-50/70 dark:hover:bg-darkmode-400/30"
                                        >
                                            <FormSwitch class="mt-0.5 shrink-0">
                                                <FormSwitch.Input
                                                    type="checkbox"
                                                    :checked="
                                                        form.modules.includes(
                                                            mod.key,
                                                        )
                                                    "
                                                    @change="
                                                        toggleModule(mod.key)
                                                    "
                                                />
                                            </FormSwitch>
                                            <span class="min-w-0 flex-1">
                                                <span
                                                    class="flex flex-wrap items-center gap-1.5 text-xs font-medium"
                                                >
                                                    {{ mod.label }}
                                                    <span
                                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-normal text-slate-500 dark:bg-darkmode-400"
                                                        >{{ group.label }}</span
                                                    >
                                                    <span
                                                        v-if="!mod.available"
                                                        class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                                        >En desarrollo</span
                                                    >
                                                </span>
                                                <span
                                                    class="mt-0.5 block text-[11px] leading-snug text-slate-500"
                                                    >{{ mod.description }}</span
                                                >
                                                <span
                                                    v-if="soldBy(mod.key)"
                                                    class="mt-0.5 block text-[11px] text-slate-400"
                                                    >{{ soldBy(mod.key) }}</span
                                                >
                                            </span>
                                        </label>
                                        <div
                                            v-if="
                                                mod.key === 'agente-ia' &&
                                                form.modules.includes(
                                                    'agente-ia',
                                                )
                                            "
                                            class="flex flex-wrap items-center gap-3 border-t border-dashed border-slate-200/70 bg-slate-50/70 px-3 py-2.5 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                                        >
                                            <span class="text-xs text-slate-500"
                                                >Respuestas del bot al mes</span
                                            >
                                            <FormInput
                                                v-model="
                                                    form.ai_monthly_replies
                                                "
                                                type="number"
                                                min="1"
                                                class="h-9 !w-32 text-xs"
                                                placeholder="Sin límite"
                                            />
                                            <span
                                                class="text-[11px] text-slate-400"
                                                >Se reinicia cada mes; al
                                                agotarse, las conversaciones
                                                pasan al personal.</span
                                            >
                                        </div>
                                    </div>
                                </template>
                            </div>
                            <FormHelp
                                v-if="form.errors.ai_monthly_replies"
                                class="text-danger"
                                >{{ form.errors.ai_monthly_replies }}</FormHelp
                            >
                            <p v-else class="mt-2 text-xs text-slate-400">
                                La llave propia del hotel y la API de
                                integraciones se habilitan por hotel en Agentes
                                IA.
                            </p>
                        </section>

                        <!-- Página de inicio -->
                        <section>
                            <div :class="sectionLabel">Página de inicio</div>
                            <label
                                class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200/70 px-3 py-2.5 dark:border-darkmode-400"
                            >
                                <FormSwitch class="mt-0.5 shrink-0">
                                    <FormSwitch.Input
                                        v-model="form.public"
                                        type="checkbox"
                                        :checked="form.public"
                                    />
                                </FormSwitch>
                                <span class="min-w-0">
                                    <span
                                        class="flex flex-wrap items-center gap-1.5 text-xs font-medium"
                                    >
                                        Anunciar en kuirawebreserve.com
                                        <span
                                            v-if="!form.public"
                                            class="rounded-full bg-info/10 px-2 py-0.5 text-[11px] font-medium text-info"
                                            >Plan a la medida</span
                                        >
                                    </span>
                                    <span
                                        class="mt-0.5 block text-[11px] leading-snug text-slate-500"
                                    >
                                        Apagado, no sale en la página de inicio
                                        ni se puede pedir desde ahí: solo tú lo
                                        asignas. Sirve para planes hechos para
                                        un hotel en particular.
                                    </span>
                                </span>
                            </label>
                        </section>
                    </div>

                    <!-- Pie fijo: el interruptor de publicación a la
                         izquierda, frente a los botones -->
                    <div
                        class="flex flex-wrap items-center gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <label
                            class="mr-auto flex cursor-pointer items-center gap-2 text-xs"
                            title="Inactivo: no se ofrece a hoteles nuevos; los existentes conservan su plan"
                        >
                            <FormSwitch>
                                <FormSwitch.Input
                                    v-model="form.active"
                                    type="checkbox"
                                    :checked="form.active"
                                />
                            </FormSwitch>
                            <span>
                                <span class="font-medium">{{
                                    form.active ? 'Activo' : 'Inactivo'
                                }}</span>
                                <span class="hidden text-slate-500 sm:inline">
                                    · se ofrece a hoteles nuevos</span
                                >
                            </span>
                        </label>
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            @click="showForm = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs shadow-md shadow-primary/20"
                            :disabled="form.processing"
                        >
                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                form.processing
                                    ? 'Guardando...'
                                    : 'Guardar plan'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmación de borrado -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-base font-medium">
                                Eliminar el plan {{ deleting?.label }}
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">
                                Se puede borrar porque ningún hotel lo usa. Si
                                solo quieres retirarlo del catálogo,
                                desactívalo.
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
                            :disabled="deleteForm.processing"
                            @click="submitDelete"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                deleteForm.processing
                                    ? 'Eliminando...'
                                    : 'Sí, eliminar'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
