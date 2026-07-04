<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormLabel, FormSelect } from '@/components/Base/Form';
import { Dialog, Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface TenantRow {
    id: string;
    name: string;
    plan: string;
    plan_label: string;
    price_monthly: number;
    ai_in_plan: boolean;
    suspended: boolean;
    domain: string | null;
    created_at: string | null;
    users: number;
    rooms: number;
    reservations_month: number;
    ai_replies: number;
}

interface PlanInfo {
    value: string;
    label: string;
    max_properties: number | null;
    max_rooms: number | null;
    max_users: number | null;
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

const initials = (name: string) =>
    name.trim().split(/\s+/).slice(0, 2).map((p) => p.charAt(0).toUpperCase()).join('') || '?';

const cellClass =
    'box shadow-[5px_3px_5px_#00000005] first:border-l last:border-r first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] rounded-l-none rounded-r-none border-x-0 dark:bg-darkmode-600';

// ── Búsqueda y filtros (en cliente: el listado carga completo) ──
const search = ref('');
const statusFilter = ref<'all' | 'active' | 'suspended'>('all');
const planFilter = ref('all');

const filtered = computed(() =>
    props.tenants
        .filter((t) => statusFilter.value === 'all' || (statusFilter.value === 'suspended') === t.suspended)
        .filter((t) => planFilter.value === 'all' || t.plan === planFilter.value)
        .filter((t) => {
            const q = search.value.trim().toLowerCase();
            if (!q) return true;
            return t.name.toLowerCase().includes(q) || (t.domain ?? '').toLowerCase().includes(q);
        }),
);

// ── Crear ──
const showCreate = ref(false);
const createForm = useForm({
    name: '',
    subdomain: '',
    plan: props.plans.find((p) => p.active)?.value ?? 'basic',
    owner_name: '',
    owner_email: '',
    owner_password: '',
});

const activePlans = computed(() => props.plans.filter((p) => p.active));
const createPlanInfo = computed(() => props.plans.find((p) => p.value === createForm.plan));

function submitCreate() {
    createForm.post(route('admin.tenants.store'), {
        onSuccess: () => {
            showCreate.value = false;
            createForm.reset();
        },
    });
}

// ── Editar ──
const editing = ref<TenantRow | null>(null);
const editForm = useForm({ name: '', plan: '' });

function openEdit(tenant: TenantRow) {
    editing.value = tenant;
    editForm.name = tenant.name;
    editForm.plan = tenant.plan;
}

function submitEdit() {
    if (!editing.value) return;
    editForm.put(route('admin.tenants.update', editing.value.id), {
        onSuccess: () => (editing.value = null),
    });
}

// ── Suspender / reactivar ──
function toggleSuspend(tenant: TenantRow) {
    router.patch(route('admin.tenants.suspend', tenant.id), {}, { preserveScroll: true });
}

// ── Entrar como (impersonación de soporte) ──
const impersonating = ref<string | null>(null);
const impersonateError = ref<string | null>(null);

async function impersonate(tenant: TenantRow) {
    impersonating.value = tenant.id;
    impersonateError.value = null;
    try {
        const { data } = await axios.post<{ url: string }>(route('admin.tenants.impersonate', tenant.id));
        window.open(data.url, '_blank');
    } catch (error: any) {
        impersonateError.value = error?.response?.data?.message ?? 'No se pudo generar el acceso.';
    } finally {
        impersonating.value = null;
    }
}

// ── Eliminar ──
const deleting = ref<TenantRow | null>(null);
const deleteForm = useForm({});

function submitDelete() {
    if (!deleting.value) return;
    deleteForm.delete(route('admin.tenants.destroy', deleting.value.id), {
        onSuccess: () => (deleting.value = null),
    });
}
</script>

<template>
    <RazeLayout title="Hoteles">
        <!-- Encabezado -->
        <div class="mt-2 flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center">
            <div class="text-base font-medium group-[.mode--light]:text-white">
                Hoteles
            </div>
            <div class="flex flex-col gap-x-3 gap-y-2 sm:flex-row md:ml-auto">
                <Button
                    :as="Link"
                    :href="route('admin.plans')"
                    variant="outline-secondary"
                    class="bg-white/80 dark:bg-darkmode-400/80"
                >
                    <Lucide icon="Layers" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    Planes
                </Button>
                <Button variant="primary" class="shadow-md shadow-primary/20" @click="showCreate = true">
                    <Lucide icon="Plus" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    Nuevo hotel
                </Button>
            </div>
        </div>

        <div class="mt-3.5 grid grid-cols-12 gap-5">
            <!-- KPIs -->
            <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                <div class="box box--stacked h-full p-5">
                    <div class="flex items-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="Building2" class="h-6 w-6 text-primary fill-primary/10" />
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl font-medium">{{ stats.total }}</div>
                            <div class="mt-0.5 text-xs text-slate-500">Hoteles registrados</div>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-3 border-t border-dashed border-slate-300/70 pt-3 text-xs">
                        <span class="flex items-center gap-1.5 text-success">
                            <span class="h-1.5 w-1.5 rounded-full bg-success" /> {{ stats.active }} activos
                        </span>
                        <span class="flex items-center gap-1.5" :class="stats.suspended ? 'text-danger' : 'text-slate-400'">
                            <span class="h-1.5 w-1.5 rounded-full" :class="stats.suspended ? 'bg-danger' : 'bg-slate-300'" />
                            {{ stats.suspended }} suspendidos
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                <div class="box box--stacked h-full p-5">
                    <div class="flex items-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full border border-success/10 bg-success/10">
                            <Lucide icon="Banknote" class="h-6 w-6 text-success fill-success/10" />
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl font-medium">${{ stats.mrr.toLocaleString('es-MX') }}</div>
                            <div class="mt-0.5 text-xs text-slate-500">Ingreso mensual (lista)</div>
                        </div>
                    </div>
                    <div class="mt-4 border-t border-dashed border-slate-300/70 pt-3 text-xs text-slate-500">
                        Suma de precios de plan de los hoteles activos.
                    </div>
                </div>
            </div>
            <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                <div class="box box--stacked h-full p-5">
                    <div class="flex items-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full border border-info/10 bg-info/10">
                            <Lucide icon="TrendingUp" class="h-6 w-6 text-info fill-info/10" />
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl font-medium">{{ stats.new_month }}</div>
                            <div class="mt-0.5 text-xs text-slate-500">Altas en {{ monthLabel }}</div>
                        </div>
                    </div>
                    <div class="mt-4 border-t border-dashed border-slate-300/70 pt-3 text-xs text-slate-500">
                        Hoteles nuevos este mes.
                    </div>
                </div>
            </div>
            <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                <div class="box box--stacked h-full p-5">
                    <div class="flex items-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full border border-warning/10 bg-warning/10">
                            <Lucide icon="MessagesSquare" class="h-6 w-6 text-warning fill-warning/10" />
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl font-medium">{{ stats.ai_replies_month }}</div>
                            <div class="mt-0.5 text-xs text-slate-500">Respuestas IA del mes</div>
                        </div>
                    </div>
                    <div class="mt-4 border-t border-dashed border-slate-300/70 pt-3 text-xs">
                        <Link :href="route('admin.ai')" class="flex items-center text-primary">
                            Ver consumo por hotel <Lucide icon="ArrowRight" class="ml-1 h-3.5 w-3.5" />
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Listado -->
            <div class="col-span-12">
                <div
                    v-if="impersonateError"
                    class="mb-1 flex items-center rounded-md border border-danger/20 bg-danger/5 px-4 py-3 text-sm text-danger"
                >
                    <Lucide icon="TriangleAlert" class="mr-2 h-4 w-4 shrink-0" />
                    {{ impersonateError }}
                </div>

                <!-- Filtros -->
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <div class="relative lg:w-72">
                        <Lucide icon="Search" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                        <FormInput v-model="search" type="text" class="pl-9" placeholder="Buscar hotel o dominio…" />
                    </div>
                    <div class="inline-flex gap-1 rounded-[0.6rem] bg-slate-100/80 p-1 dark:bg-darkmode-700">
                        <button
                            v-for="f in [
                                { key: 'all', label: `Todos (${stats.total})` },
                                { key: 'active', label: `Activos (${stats.active})` },
                                { key: 'suspended', label: `Suspendidos (${stats.suspended})` },
                            ]"
                            :key="f.key"
                            type="button"
                            class="rounded-[0.5rem] px-3 py-1.5 text-xs font-medium transition"
                            :class="statusFilter === f.key ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600' : 'text-slate-500 hover:text-slate-700'"
                            @click="statusFilter = f.key as typeof statusFilter"
                        >
                            {{ f.label }}
                        </button>
                    </div>
                    <FormSelect v-model="planFilter" class="!w-auto !py-1.5 text-xs lg:ml-auto">
                        <option value="all">Plan: todos</option>
                        <option v-for="p in plans" :key="p.value" :value="p.value">{{ p.label }}</option>
                    </FormSelect>
                </div>

                <!-- Tabla card-row -->
                <div class="mt-2 overflow-auto lg:overflow-visible">
                    <table v-if="filtered.length" class="w-full min-w-[1000px] border-separate border-spacing-y-[8px] text-sm">
                        <thead>
                            <tr>
                                <th class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500">Hotel</th>
                                <th class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500">Plan</th>
                                <th class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500">Operación</th>
                                <th class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500">IA (mes)</th>
                                <th class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500">Estado</th>
                                <th class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500">Alta</th>
                                <th class="border-b-0 px-5 pb-1 text-right text-xs font-medium text-slate-500">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="t in filtered" :key="t.id">
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-xs font-semibold text-white">
                                            {{ initials(t.name) }}
                                        </div>
                                        <div class="min-w-0">
                                            <Link
                                                :href="route('admin.tenants.show', t.id)"
                                                class="block truncate font-medium text-primary hover:underline"
                                                :class="{ '!text-slate-400 line-through': t.suspended }"
                                            >
                                                {{ t.name }}
                                            </Link>
                                            <a
                                                v-if="t.domain"
                                                :href="`http://${t.domain}`"
                                                target="_blank"
                                                class="block truncate text-xs text-slate-500 hover:text-primary"
                                            >
                                                {{ t.domain }}
                                            </a>
                                        </div>
                                    </div>
                                </td>
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">{{ t.plan_label }}</span>
                                    <div class="mt-1 text-[10px] text-slate-400">${{ t.price_monthly.toLocaleString('es-MX') }} MXN/mes</div>
                                </td>
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <div class="flex items-center gap-3 text-xs text-slate-500">
                                        <span class="flex items-center gap-1" title="Usuarios">
                                            <Lucide icon="Users" class="h-3.5 w-3.5 stroke-[1.5]" /> {{ t.users }}
                                        </span>
                                        <span class="flex items-center gap-1" title="Habitaciones">
                                            <Lucide icon="BedDouble" class="h-3.5 w-3.5 stroke-[1.5]" /> {{ t.rooms }}
                                        </span>
                                        <span class="flex items-center gap-1" title="Reservas del mes">
                                            <Lucide icon="CalendarCheck" class="h-3.5 w-3.5 stroke-[1.5]" /> {{ t.reservations_month }}
                                        </span>
                                    </div>
                                </td>
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <span v-if="t.ai_in_plan" class="flex w-fit items-center gap-1.5 rounded-full bg-info/10 px-2 py-0.5 text-xs text-info">
                                        <Lucide icon="Bot" class="h-3 w-3" /> {{ t.ai_replies }} resp.
                                    </span>
                                    <span v-else class="text-xs text-slate-400">Sin IA</span>
                                </td>
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <span
                                        class="flex w-fit items-center gap-1.5 rounded-full px-2 py-0.5 text-xs"
                                        :class="t.suspended ? 'bg-danger/10 text-danger' : 'bg-success/10 text-success'"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full" :class="t.suspended ? 'bg-danger' : 'bg-success'" />
                                        {{ t.suspended ? 'Suspendido' : 'Activo' }}
                                    </span>
                                </td>
                                <td :class="cellClass" class="px-5 py-3.5 text-xs text-slate-500">{{ t.created_at ?? '—' }}</td>
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <div class="flex items-center justify-end gap-2">
                                        <Button
                                            v-if="!t.suspended"
                                            variant="outline-primary"
                                            size="sm"
                                            class="whitespace-nowrap rounded-[0.5rem] bg-white"
                                            :disabled="impersonating === t.id"
                                            title="Abre el panel del hotel como su dueño (acceso de soporte, un solo uso)"
                                            @click="impersonate(t)"
                                        >
                                            <Lucide icon="LogIn" class="mr-1.5 h-3.5 w-3.5" />
                                            {{ impersonating === t.id ? 'Abriendo…' : 'Entrar como' }}
                                        </Button>
                                        <Menu>
                                            <Menu.Button
                                                class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-100 dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                                            >
                                                <Lucide icon="MoreVertical" class="h-4 w-4" />
                                            </Menu.Button>
                                            <Menu.Items class="w-48">
                                                <Menu.Item :as="Link" :href="route('admin.tenants.show', t.id)">
                                                    <Lucide icon="Eye" class="mr-2 h-4 w-4" /> Ver ficha
                                                </Menu.Item>
                                                <Menu.Item as="button" type="button" @click="openEdit(t)">
                                                    <Lucide icon="Pencil" class="mr-2 h-4 w-4" /> Editar
                                                </Menu.Item>
                                                <Menu.Item
                                                    as="button"
                                                    type="button"
                                                    :class="t.suspended ? 'text-success' : 'text-warning'"
                                                    @click="toggleSuspend(t)"
                                                >
                                                    <Lucide :icon="t.suspended ? 'Play' : 'Pause'" class="mr-2 h-4 w-4" />
                                                    {{ t.suspended ? 'Reactivar' : 'Suspender' }}
                                                </Menu.Item>
                                                <Menu.Item as="button" type="button" class="text-danger" @click="deleting = t">
                                                    <Lucide icon="Trash2" class="mr-2 h-4 w-4" /> Eliminar
                                                </Menu.Item>
                                            </Menu.Items>
                                        </Menu>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="box box--stacked flex flex-col items-center gap-3 py-14 text-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Lucide icon="Building2" class="h-6 w-6" />
                        </div>
                        <p class="max-w-md px-6 text-sm text-slate-500">
                            {{ tenants.length ? 'Ningún hotel coincide con los filtros.' : 'Aún no hay hoteles. Crea el primero con "Nuevo hotel".' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: crear -->
        <Dialog :open="showCreate" size="lg" @close="showCreate = false">
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="submitCreate">
                    <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="Building2" class="h-5 w-5 text-primary" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">Nuevo hotel</h2>
                            <p class="mt-0.5 text-xs text-slate-500">Se aprovisiona su base de datos con roles, dueño y primera propiedad (tarda unos segundos)</p>
                        </div>
                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400" @click="showCreate = false">
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="max-h-[70vh] space-y-4 overflow-y-auto px-6 py-5">
                        <div>
                            <FormLabel htmlFor="create-name">Nombre del hotel</FormLabel>
                            <FormInput id="create-name" v-model="createForm.name" type="text" placeholder="Hotel Las Palmas" />
                            <FormHelp v-if="createForm.errors.name" class="text-danger">{{ createForm.errors.name }}</FormHelp>
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <FormLabel htmlFor="create-subdomain">Subdominio</FormLabel>
                                <div class="flex items-center">
                                    <FormInput
                                        id="create-subdomain"
                                        v-model="createForm.subdomain"
                                        type="text"
                                        placeholder="laspalmas"
                                        class="rounded-r-none"
                                    />
                                    <span class="rounded-r-md border border-l-0 border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500 dark:border-transparent dark:bg-darkmode-700">
                                        .{{ domainSuffix }}
                                    </span>
                                </div>
                                <FormHelp v-if="createForm.errors.subdomain" class="text-danger">{{ createForm.errors.subdomain }}</FormHelp>
                            </div>
                            <div>
                                <FormLabel htmlFor="create-plan">Plan</FormLabel>
                                <FormSelect id="create-plan" v-model="createForm.plan">
                                    <option v-for="plan in activePlans" :key="plan.value" :value="plan.value">{{ plan.label }}</option>
                                </FormSelect>
                                <FormHelp v-if="createPlanInfo">
                                    Hasta {{ createPlanInfo.max_properties ?? 'ilimitadas' }} propiedad(es),
                                    {{ createPlanInfo.max_rooms ?? 'ilimitadas' }} habitaciones y {{ createPlanInfo.max_users ?? 'ilimitados' }} usuarios.
                                </FormHelp>
                            </div>
                        </div>

                        <div class="border-t border-dashed border-slate-300/70 pt-4">
                            <p class="mb-3 text-xs font-medium uppercase tracking-wide text-slate-400">Dueño (owner)</p>
                            <div class="space-y-4">
                                <div>
                                    <FormLabel htmlFor="owner-name">Nombre</FormLabel>
                                    <FormInput id="owner-name" v-model="createForm.owner_name" type="text" placeholder="Juan Pérez" />
                                    <FormHelp v-if="createForm.errors.owner_name" class="text-danger">{{ createForm.errors.owner_name }}</FormHelp>
                                </div>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <FormLabel htmlFor="owner-email">Email</FormLabel>
                                        <FormInput id="owner-email" v-model="createForm.owner_email" type="email" placeholder="dueno@hotel.com" />
                                        <FormHelp v-if="createForm.errors.owner_email" class="text-danger">{{ createForm.errors.owner_email }}</FormHelp>
                                    </div>
                                    <div>
                                        <FormLabel htmlFor="owner-password">Contraseña</FormLabel>
                                        <FormInput id="owner-password" v-model="createForm.owner_password" type="password" placeholder="Mínimo 8 caracteres" />
                                        <FormHelp v-if="createForm.errors.owner_password" class="text-danger">{{ createForm.errors.owner_password }}</FormHelp>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <Button type="button" variant="outline-secondary" @click="showCreate = false">Cancelar</Button>
                        <Button type="submit" variant="primary" class="shadow-md shadow-primary/20" :disabled="createForm.processing">
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{ createForm.processing ? 'Creando…' : 'Crear hotel' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal: editar -->
        <Dialog :open="editing !== null" @close="editing = null">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="Pencil" class="h-5 w-5 text-primary" />
                        </div>
                        <h2 class="text-base font-medium">Editar hotel</h2>
                    </div>
                    <form class="space-y-4" @submit.prevent="submitEdit">
                        <div>
                            <FormLabel htmlFor="edit-name">Nombre</FormLabel>
                            <FormInput id="edit-name" v-model="editForm.name" type="text" />
                            <FormHelp v-if="editForm.errors.name" class="text-danger">{{ editForm.errors.name }}</FormHelp>
                        </div>
                        <div>
                            <FormLabel htmlFor="edit-plan">Plan</FormLabel>
                            <FormSelect id="edit-plan" v-model="editForm.plan">
                                <option v-for="plan in plans" :key="plan.value" :value="plan.value">{{ plan.label }}</option>
                            </FormSelect>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline-secondary" @click="editing = null">Cancelar</Button>
                            <Button type="submit" variant="primary" :disabled="editForm.processing">Guardar</Button>
                        </div>
                    </form>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal: eliminar -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide icon="TriangleAlert" class="mx-auto mb-3 h-12 w-12 text-danger" />
                    <h2 class="text-base font-medium">¿Eliminar {{ deleting?.name }}?</h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Se eliminará el hotel <strong>y toda su base de datos</strong> (habitaciones,
                        reservas, usuarios). Esta acción no se puede deshacer. Si solo quieres
                        cortar el acceso, usa "Suspender".
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button variant="outline-secondary" @click="deleting = null">Cancelar</Button>
                        <Button variant="danger" :disabled="deleteForm.processing" @click="submitDelete">
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" />
                            {{ deleteForm.processing ? 'Eliminando…' : 'Sí, eliminar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
