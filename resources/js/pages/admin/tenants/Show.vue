<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormLabel, FormSelect, FormSwitch } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ReservationRow {
    code: string;
    guest: string | null;
    status: string;
    status_label: string;
    starts_at: string;
    total: number;
}

interface TeamUser {
    id: number;
    name: string;
    email: string;
    role: string | null;
}

interface ModuleRow {
    key: string;
    label: string;
    description: string;
    available: boolean;
    in_plan: boolean;
    override: boolean | null;
    enabled: boolean;
    requested_at: string | null;
}

const props = defineProps<{
    tenant: {
        id: string;
        name: string;
        plan: string;
        plan_label: string;
        suspended: boolean;
        domain: string | null;
        created_at: string | null;
    };
    plan: {
        max_properties: number | null;
        max_rooms: number | null;
        max_users: number | null;
        price_monthly: number;
        ai_enabled: boolean;
    };
    ops: {
        owner: { name: string; email: string } | null;
        users: number;
        users_list: TeamUser[];
        assignable_roles: string[];
        properties: number;
        rooms: number;
        guests: number;
        active_stays: number;
        reservations_month: number;
        revenue_month: number;
        conversations: number;
        conversations_pending: number;
        recent_reservations: ReservationRow[];
    };
    ai: {
        enabled: boolean;
        limit: number | null;
        used: number;
        tokens: number;
        byok_allowed: boolean;
    };
    plans: { value: string; label: string }[];
    paymentMethods: { method: string; label: string; platform_enabled: boolean; tenant_enabled: boolean }[];
    modules: ModuleRow[];
}>();

const toast = useToasts();

// Métodos de pago del hotel (override sobre los interruptores de plataforma).
const payLocal = reactive<Record<string, boolean>>(
    Object.fromEntries(props.paymentMethods.map((m) => [m.method, m.tenant_enabled])),
);

async function togglePayment(m: { method: string; label: string; platform_enabled: boolean }) {
    const next = !payLocal[m.method];
    payLocal[m.method] = next;
    try {
        await axios.patch(route('admin.payments.tenant', props.tenant.id), { method: m.method, enabled: next });
        toast.success('Método actualizado', `${m.label}: ${next ? 'habilitado' : 'apagado'} para ${props.tenant.name}`);
    } catch (e: any) {
        payLocal[m.method] = !next;
        toast.error('No se pudo actualizar', e.response?.data?.message ?? 'Ocurrió un error.');
    }
}

const money = (n: number) => `$${n.toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

// Módulos: heredar del plan o forzar on/off para este hotel.
const moduleMode = (mod: ModuleRow) => (mod.override === null ? 'inherit' : mod.override ? 'on' : 'off');

function setModule(mod: ModuleRow, mode: 'inherit' | 'on' | 'off') {
    router.patch(
        route('admin.tenants.modules', props.tenant.id),
        { module: mod.key, mode },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    'Módulo actualizado',
                    mode === 'inherit' ? `${mod.label}: hereda del plan` : `${mod.label}: forzado ${mode === 'on' ? 'activado' : 'apagado'}`,
                ),
        },
    );
}

function dismissModuleRequest(mod: ModuleRow) {
    router.delete(route('admin.tenants.module-requests.dismiss', [props.tenant.id, mod.key]), {
        preserveScroll: true,
        onSuccess: () => toast.success('Solicitud descartada', mod.label),
    });
}

const statusTone: Record<string, string> = {
    pending: 'bg-warning/10 text-warning',
    confirmed: 'bg-info/10 text-info',
    checked_in: 'bg-primary/10 text-primary',
    completed: 'bg-success/10 text-success',
    cancelled: 'bg-danger/10 text-danger',
    no_show: 'bg-pending/10 text-pending',
};

// Entrar como (misma mecánica que el listado).
const impersonating = ref(false);
const actionError = ref<string | null>(null);

async function impersonate() {
    impersonating.value = true;
    actionError.value = null;
    // Abrir la pestaña ANTES del await: tras una respuesta asíncrona el
    // navegador ya no lo trata como gesto del usuario y bloquea el popup
    // (y el token de impersonación solo vive 60 s, no admite copiar/pegar).
    const win = window.open('', '_blank');
    try {
        const { data } = await axios.post<{ url: string }>(route('admin.tenants.impersonate', props.tenant.id));
        if (win) {
            win.location.href = data.url;
        } else {
            window.location.href = data.url;
        }
    } catch (e: any) {
        win?.close();
        actionError.value = e?.response?.data?.message ?? 'No se pudo generar el acceso.';
    } finally {
        impersonating.value = false;
    }
}

function toggleSuspend() {
    router.patch(route('admin.tenants.suspend', props.tenant.id), {}, { preserveScroll: true });
}

// Editar nombre / plan.
const showEdit = ref(false);
const editForm = useForm({ name: props.tenant.name, plan: props.tenant.plan });

function submitEdit() {
    editForm.put(route('admin.tenants.update', props.tenant.id), {
        onSuccess: () => (showEdit.value = false),
    });
}

const quotaPercent = () => {
    if (!props.ai.limit) return null;
    return Math.min(100, Math.round((props.ai.used / props.ai.limit) * 100));
};

// ── Equipo: CRUD de usuarios del hotel ──
const roleLabels: Record<string, string> = {
    owner: 'Propietario',
    manager: 'Gerente',
    'front-desk': 'Recepción',
    housekeeping: 'Limpieza',
    kitchen: 'Cocina',
};
const roleLabel = (r: string | null) => (r ? (roleLabels[r] ?? r) : '—');
const initialsOf = (name: string) =>
    name.trim().split(/\s+/).slice(0, 2).map((p) => p.charAt(0).toUpperCase()).join('') || '?';

const users = ref<TeamUser[]>([...props.ops.users_list]);
const userModal = ref(false);
const userEditing = ref<TeamUser | null>(null);
const userSaving = ref(false);
const userDeleting = ref<TeamUser | null>(null);
const userErrors = reactive<Record<string, string>>({});
const userForm = reactive({ name: '', email: '', password: '', role: props.ops.assignable_roles[0] ?? 'front-desk' });

function openUserModal(u: TeamUser | null = null) {
    userEditing.value = u;
    userForm.name = u?.name ?? '';
    userForm.email = u?.email ?? '';
    userForm.password = '';
    userForm.role = u?.role ?? props.ops.assignable_roles[0] ?? 'front-desk';
    Object.keys(userErrors).forEach((k) => delete userErrors[k]);
    userModal.value = true;
}

async function submitUser() {
    userSaving.value = true;
    Object.keys(userErrors).forEach((k) => delete userErrors[k]);
    try {
        if (userEditing.value) {
            const payload: Record<string, unknown> = { name: userForm.name, email: userForm.email, role: userForm.role };
            if (userForm.password) payload.password = userForm.password;
            const { data } = await axios.patch<TeamUser>(
                route('admin.tenants.users.update', [props.tenant.id, userEditing.value.id]),
                payload,
            );
            users.value = users.value.map((u) => (u.id === data.id ? data : u));
        } else {
            const { data } = await axios.post<TeamUser>(route('admin.tenants.users.store', props.tenant.id), { ...userForm });
            users.value = [...users.value, data];
        }
        userModal.value = false;
    } catch (e: any) {
        const d = e.response?.data;
        if (d?.errors) {
            Object.entries(d.errors).forEach(([k, msgs]) => (userErrors[k] = (msgs as string[])[0]));
        } else {
            userErrors._ = d?.message ?? 'No se pudo guardar el usuario.';
        }
    } finally {
        userSaving.value = false;
    }
}

async function deleteUser() {
    if (!userDeleting.value) return;
    userSaving.value = true;
    try {
        await axios.delete(route('admin.tenants.users.destroy', [props.tenant.id, userDeleting.value.id]));
        users.value = users.value.filter((u) => u.id !== userDeleting.value!.id);
        userDeleting.value = null;
    } catch (e: any) {
        actionError.value = e.response?.data?.message ?? 'No se pudo eliminar el usuario.';
        userDeleting.value = null;
    } finally {
        userSaving.value = false;
    }
}
</script>

<template>
    <RazeLayout :title="tenant.name">
        <!-- Encabezado -->
        <div class="mt-2 flex flex-col gap-y-3 md:flex-row md:items-center">
            <div class="flex min-w-0 items-center gap-3.5">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                    <Lucide icon="Building2" class="h-6 w-6 text-primary fill-primary/10" />
                </div>
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h1 class="truncate text-lg font-medium group-[.mode--light]:text-white">{{ tenant.name }}</h1>
                        <span
                            class="flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-xs"
                            :class="tenant.suspended ? 'bg-danger/10 text-danger' : 'bg-success/10 text-success'"
                        >
                            <span class="h-1.5 w-1.5 rounded-full" :class="tenant.suspended ? 'bg-danger' : 'bg-success'" />
                            {{ tenant.suspended ? 'Suspendido' : 'Activo' }}
                        </span>
                        <span class="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">{{ tenant.plan_label }}</span>
                    </div>
                    <div class="mt-0.5 flex items-center gap-2 text-sm text-slate-500">
                        <a v-if="tenant.domain" :href="`http://${tenant.domain}`" target="_blank" class="flex items-center gap-1 text-primary hover:underline">
                            {{ tenant.domain }} <Lucide icon="ExternalLink" class="h-3 w-3" />
                        </a>
                        <span v-if="tenant.created_at">· cliente desde {{ tenant.created_at }}</span>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 md:ml-auto">
                <Button :as="Link" :href="route('admin.tenants.index')" variant="outline-secondary" class="rounded-[0.5rem] bg-white/80 dark:bg-darkmode-400/80">
                    <Lucide icon="ArrowLeft" class="mr-2 h-4 w-4 stroke-[1.3]" /> Hoteles
                </Button>
                <Button variant="outline-secondary" class="rounded-[0.5rem] bg-white/80 dark:bg-darkmode-400/80" @click="showEdit = true">
                    <Lucide icon="Pencil" class="mr-2 h-4 w-4 stroke-[1.3]" /> Editar
                </Button>
                <Button
                    variant="outline-secondary"
                    class="rounded-[0.5rem] bg-white/80 dark:bg-darkmode-400/80"
                    :class="tenant.suspended ? '!text-success' : '!text-warning'"
                    @click="toggleSuspend"
                >
                    <Lucide :icon="tenant.suspended ? 'Play' : 'Pause'" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    {{ tenant.suspended ? 'Reactivar' : 'Suspender' }}
                </Button>
                <Button v-if="!tenant.suspended" variant="primary" class="rounded-[0.5rem] shadow-md shadow-primary/20" :disabled="impersonating" @click="impersonate">
                    <Lucide icon="LogIn" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    {{ impersonating ? 'Abriendo…' : 'Entrar como' }}
                </Button>
            </div>
        </div>

        <div v-if="actionError" class="mt-4 flex items-center rounded-md border border-danger/20 bg-danger/5 px-4 py-3 text-sm text-danger">
            <Lucide icon="TriangleAlert" class="mr-2 h-4 w-4 shrink-0" /> {{ actionError }}
        </div>

        <div class="mt-3.5 grid grid-cols-12 gap-5">
            <!-- KPIs de operación -->
            <div class="col-span-6 sm:col-span-4 xl:col-span-2">
                <div class="box box--stacked h-full p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="Users" class="h-5 w-5 text-primary" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-lg font-medium">{{ ops.users }}<span v-if="plan.max_users" class="text-xs text-slate-400">/{{ plan.max_users }}</span></div>
                            <div class="truncate text-xs text-slate-500">Usuarios</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-span-6 sm:col-span-4 xl:col-span-2">
                <div class="box box--stacked h-full p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10">
                            <Lucide icon="BedDouble" class="h-5 w-5 text-info" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-lg font-medium">{{ ops.rooms }}<span v-if="plan.max_rooms" class="text-xs text-slate-400">/{{ plan.max_rooms }}</span></div>
                            <div class="truncate text-xs text-slate-500">Habitaciones</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-span-6 sm:col-span-4 xl:col-span-2">
                <div class="box box--stacked h-full p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10">
                            <Lucide icon="CalendarCheck" class="h-5 w-5 text-success" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-lg font-medium">{{ ops.reservations_month }}</div>
                            <div class="truncate text-xs text-slate-500">Reservas del mes</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-span-6 sm:col-span-4 xl:col-span-2">
                <div class="box box--stacked h-full p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10">
                            <Lucide icon="Banknote" class="h-5 w-5 text-warning" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-lg font-medium">{{ money(ops.revenue_month) }}</div>
                            <div class="truncate text-xs text-slate-500">Cobrado este mes</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-span-6 sm:col-span-4 xl:col-span-2">
                <div class="box box--stacked h-full p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10">
                            <Lucide icon="DoorOpen" class="h-5 w-5 text-pending" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-lg font-medium">{{ ops.active_stays }}</div>
                            <div class="truncate text-xs text-slate-500">Estancias activas</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-span-6 sm:col-span-4 xl:col-span-2">
                <div class="box box--stacked h-full p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-dark/10 bg-dark/10">
                            <Lucide icon="MessagesSquare" class="h-5 w-5 text-dark dark:text-slate-300" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-lg font-medium">{{ ops.conversations }}</div>
                            <div class="truncate text-xs text-slate-500">Conversaciones</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contrato + dueño -->
            <div class="col-span-12 xl:col-span-4">
                <div class="box box--stacked flex h-full flex-col">
                    <div class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4">
                        <Lucide icon="FileText" class="h-4 w-4 stroke-[1.5] text-primary" />
                        <h2 class="text-base font-medium">Contrato</h2>
                    </div>
                    <div class="flex flex-1 flex-col gap-3 p-5 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Plan</span>
                            <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">{{ tenant.plan_label }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Precio de lista</span>
                            <span class="font-medium">${{ plan.price_monthly.toLocaleString('es-MX') }} MXN/mes</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Propiedades</span>
                            <span class="font-medium">{{ ops.properties }}<span class="text-slate-400"> / {{ plan.max_properties ?? 'sin límite' }}</span></span>
                        </div>
                        <div class="border-t border-dashed border-slate-300/70 pt-3">
                            <div class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-400">Dueño (owner)</div>
                            <template v-if="ops.owner">
                                <div class="font-medium">{{ ops.owner.name }}</div>
                                <div class="text-slate-500">{{ ops.owner.email }}</div>
                            </template>
                            <div v-else class="text-slate-400">Sin usuario propietario</div>
                        </div>
                        <div class="mt-auto border-t border-dashed border-slate-300/70 pt-3 text-xs text-slate-500">
                            {{ ops.guests }} huésped(es) en su CRM · {{ ops.conversations_pending }} conversación(es) esperando humano
                        </div>
                    </div>
                </div>
            </div>

            <!-- IA -->
            <div class="col-span-12 xl:col-span-4">
                <div class="box box--stacked flex h-full flex-col">
                    <div class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4">
                        <Lucide icon="Bot" class="h-4 w-4 stroke-[1.5] text-primary" />
                        <h2 class="text-base font-medium">Asistente IA</h2>
                        <Link :href="route('admin.ai')" class="ml-auto flex items-center text-xs text-primary">
                            Administrar <Lucide icon="ArrowRight" class="ml-1 h-3 w-3" />
                        </Link>
                    </div>
                    <div class="flex flex-1 flex-col gap-3 p-5 text-sm">
                        <template v-if="plan.ai_enabled">
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500">Bot</span>
                                <span
                                    class="flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs"
                                    :class="ai.enabled ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full" :class="ai.enabled ? 'bg-success' : 'bg-danger'" />
                                    {{ ai.enabled ? 'Activo' : 'Apagado' }}
                                </span>
                            </div>
                            <div>
                                <div class="mb-1.5 flex items-center justify-between">
                                    <span class="text-slate-500">Respuestas del mes</span>
                                    <span class="text-xs text-slate-500">{{ ai.used }}{{ ai.limit ? ` / ${ai.limit}` : ' · sin límite' }}</span>
                                </div>
                                <div v-if="ai.limit" class="h-2 rounded-full bg-slate-200/70 dark:bg-darkmode-400">
                                    <div
                                        class="h-2 rounded-full"
                                        :class="(quotaPercent() ?? 0) >= 90 ? 'bg-danger' : (quotaPercent() ?? 0) >= 70 ? 'bg-warning' : 'bg-success'"
                                        :style="{ width: `${quotaPercent()}%` }"
                                    />
                                </div>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500">Tokens del mes</span>
                                <span class="font-medium">{{ ai.tokens.toLocaleString('es-MX') }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500">BYOK (keys propias)</span>
                                <span class="text-xs" :class="ai.byok_allowed ? 'text-success' : 'text-slate-400'">{{ ai.byok_allowed ? 'Permitido' : 'No' }}</span>
                            </div>
                        </template>
                        <div v-else class="flex flex-1 flex-col items-center justify-center gap-2 py-4 text-center">
                            <Lucide icon="BotOff" class="h-8 w-8 text-slate-300" />
                            <p class="text-xs text-slate-500">Su plan no incluye IA — palanca de upsell.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Métodos de pago: override del hotel sobre la plataforma -->
            <div class="col-span-12 xl:col-span-4">
                <div class="box box--stacked flex h-full flex-col">
                    <div class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4">
                        <Lucide icon="CreditCard" class="h-4 w-4 stroke-[1.5] text-primary" />
                        <h2 class="text-base font-medium">Métodos de pago</h2>
                        <Link :href="route('admin.payments')" class="ml-auto flex items-center text-xs text-primary">
                            Plataforma <Lucide icon="ArrowRight" class="ml-1 h-3 w-3" />
                        </Link>
                    </div>
                    <div class="flex flex-1 flex-col gap-3.5 p-5 text-sm">
                        <div v-for="m in paymentMethods" :key="m.method" class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-sm">{{ m.label }}</div>
                                <div v-if="!m.platform_enabled" class="text-[11px] text-slate-400">Apagado a nivel plataforma</div>
                            </div>
                            <FormSwitch
                                class="shrink-0"
                                :title="!m.platform_enabled ? 'Método apagado globalmente en Pagos; el toggle del hotel no aplica hasta reencenderlo' : undefined"
                            >
                                <FormSwitch.Input
                                    :checked="payLocal[m.method]"
                                    type="checkbox"
                                    :disabled="!m.platform_enabled"
                                    @change="togglePayment(m)"
                                />
                            </FormSwitch>
                        </div>
                        <FormHelp class="mt-auto">
                            Control de la plataforma: lo que apagues aquí no se ofrece a los huéspedes de este hotel ni aparece en su panel.
                        </FormHelp>
                    </div>
                </div>
            </div>

            <!-- Módulos: heredados del plan + overrides de este hotel -->
            <div class="col-span-12 xl:col-span-8">
                <div class="box box--stacked flex h-full flex-col">
                    <div class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4">
                        <Lucide icon="Blocks" class="h-4 w-4 stroke-[1.5] text-primary" />
                        <h2 class="text-base font-medium">Módulos</h2>
                        <Link :href="route('admin.plans')" class="ml-auto flex items-center text-xs text-primary">
                            Planes <Lucide icon="ArrowRight" class="ml-1 h-3 w-3" />
                        </Link>
                    </div>
                    <div class="flex flex-1 flex-col divide-y divide-dashed divide-slate-300/70 px-5">
                        <div v-for="mod in modules" :key="mod.key" class="flex flex-col gap-2 py-3.5 sm:flex-row sm:items-center sm:gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium">{{ mod.label }}</span>
                                    <span
                                        class="flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs"
                                        :class="mod.enabled ? 'bg-success/10 text-success' : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full" :class="mod.enabled ? 'bg-success' : 'bg-slate-400'" />
                                        {{ mod.enabled ? 'Activo' : 'Apagado' }}
                                    </span>
                                    <span
                                        v-if="!mod.available"
                                        class="rounded-full bg-pending/10 px-2 py-0.5 text-[10px] font-medium text-pending"
                                        title="Se puede dejar activo desde ya; su área aparecerá sola cuando esté lista"
                                    >
                                        En desarrollo
                                    </span>
                                </div>
                                <div class="mt-0.5 text-xs text-slate-500" :title="mod.description">
                                    {{
                                        mod.override === null
                                            ? mod.in_plan
                                                ? `Incluido en el plan ${tenant.plan_label}`
                                                : `No incluido en el plan ${tenant.plan_label}`
                                            : mod.override
                                              ? 'Forzado: activado para este hotel'
                                              : 'Forzado: desactivado para este hotel'
                                    }}
                                </div>
                                <div v-if="mod.requested_at" class="mt-1.5 flex flex-wrap items-center gap-2">
                                    <span class="flex items-center gap-1 rounded-full bg-warning/10 px-2 py-0.5 text-xs text-warning">
                                        <Lucide icon="BellRing" class="h-3 w-3" /> El hotel solicitó activarlo el {{ mod.requested_at }}
                                    </span>
                                    <button
                                        type="button"
                                        class="text-xs text-slate-400 underline transition hover:text-slate-600 dark:hover:text-slate-300"
                                        @click="dismissModuleRequest(mod)"
                                    >
                                        Descartar
                                    </button>
                                </div>
                            </div>
                            <FormSelect
                                class="!w-full shrink-0 sm:!w-48"
                                :value="moduleMode(mod)"
                                @change="setModule(mod, ($event.target as HTMLSelectElement).value as 'inherit' | 'on' | 'off')"
                            >
                                <option value="inherit">Heredar del plan</option>
                                <option value="on">Forzar activado</option>
                                <option value="off">Forzar apagado</option>
                            </FormSelect>
                        </div>
                    </div>
                    <div class="border-t border-dashed border-slate-300/70 px-5 py-3.5">
                        <FormHelp>
                            Heredar sigue lo que diga el plan (cambia solo si el hotel cambia de plan); forzar fija el módulo para este
                            hotel sin importar el plan. Apagar un módulo oculta su área pero no borra datos.
                        </FormHelp>
                    </div>
                </div>
            </div>

            <!-- Equipo: usuarios / accesos del hotel -->
            <div class="col-span-12 xl:col-span-4">
                <div class="box box--stacked flex h-full flex-col">
                    <div class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4">
                        <Lucide icon="Users" class="h-4 w-4 stroke-[1.5] text-primary" />
                        <h2 class="text-base font-medium">Equipo y accesos</h2>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400">
                            {{ users.length }}<template v-if="plan.max_users"> / {{ plan.max_users }}</template>
                        </span>
                        <Button
                            variant="outline-primary"
                            size="sm"
                            class="ml-auto rounded-[0.5rem] bg-white"
                            :disabled="plan.max_users !== null && users.length >= plan.max_users"
                            :title="plan.max_users !== null && users.length >= plan.max_users ? 'Límite del plan alcanzado' : 'Agregar usuario'"
                            @click="openUserModal()"
                        >
                            <Lucide icon="Plus" class="mr-1 h-3.5 w-3.5" /> Nuevo
                        </Button>
                    </div>
                    <div class="max-h-72 flex-1 divide-y divide-dashed divide-slate-300/70 overflow-y-auto px-5 py-2">
                        <div v-for="u in users" :key="u.id" class="group flex items-center gap-3 py-2.5">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-[10px] font-semibold text-white">
                                {{ initialsOf(u.name) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">{{ u.name }}</div>
                                <div class="truncate text-xs text-slate-500">{{ u.email }}</div>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium"
                                :class="u.role === 'owner' ? 'bg-primary/10 text-primary' : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'"
                            >
                                {{ roleLabel(u.role) }}
                            </span>
                            <div class="flex shrink-0 items-center gap-1">
                                <button type="button" title="Editar acceso" class="flex h-7 w-7 items-center justify-center rounded-full text-slate-400 transition hover:bg-primary/10 hover:text-primary" @click="openUserModal(u)">
                                    <Lucide icon="Pencil" class="h-3.5 w-3.5" />
                                </button>
                                <button type="button" title="Eliminar" class="flex h-7 w-7 items-center justify-center rounded-full text-slate-400 transition hover:bg-danger/10 hover:text-danger" @click="userDeleting = u">
                                    <Lucide icon="Trash2" class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>
                        <div v-if="!users.length" class="py-6 text-center text-sm text-slate-400">Sin usuarios. Agrega el primero con "Nuevo".</div>
                    </div>
                </div>
            </div>

            <!-- Reservas recientes -->
            <div class="col-span-12">
                <div class="box box--stacked">
                    <div class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4">
                        <Lucide icon="CalendarClock" class="h-4 w-4 stroke-[1.5] text-primary" />
                        <h2 class="text-base font-medium">Reservas recientes</h2>
                    </div>
                    <div class="overflow-auto p-5 lg:overflow-visible">
                        <Table v-if="ops.recent_reservations.length" striped>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>Código</Table.Th>
                                    <Table.Th>Huésped</Table.Th>
                                    <Table.Th>Llegada</Table.Th>
                                    <Table.Th>Estado</Table.Th>
                                    <Table.Th class="text-right">Total</Table.Th>
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr v-for="r in ops.recent_reservations" :key="r.code">
                                    <Table.Td class="font-mono text-xs">{{ r.code }}</Table.Td>
                                    <Table.Td class="font-medium">{{ r.guest ?? '—' }}</Table.Td>
                                    <Table.Td>{{ r.starts_at }}</Table.Td>
                                    <Table.Td>
                                        <span class="rounded-full px-2 py-0.5 text-xs" :class="statusTone[r.status] ?? 'bg-slate-100 text-slate-500'">
                                            {{ r.status_label }}
                                        </span>
                                    </Table.Td>
                                    <Table.Td class="text-right font-medium">{{ money(r.total) }}</Table.Td>
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>
                        <div v-else class="py-8 text-center text-slate-500">Aún no hay reservas.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal editar -->
        <Dialog :open="showEdit" @close="showEdit = false">
            <Dialog.Panel>
                <div class="p-5">
                    <h2 class="mb-4 text-base font-medium">Editar hotel</h2>
                    <form class="space-y-4" @submit.prevent="submitEdit">
                        <div>
                            <FormLabel htmlFor="edit-name">Nombre</FormLabel>
                            <FormInput id="edit-name" v-model="editForm.name" type="text" />
                            <FormHelp v-if="editForm.errors.name" class="text-danger">{{ editForm.errors.name }}</FormHelp>
                        </div>
                        <div>
                            <FormLabel htmlFor="edit-plan">Plan</FormLabel>
                            <FormSelect id="edit-plan" v-model="editForm.plan">
                                <option v-for="p in plans" :key="p.value" :value="p.value">{{ p.label }}</option>
                            </FormSelect>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline-secondary" @click="showEdit = false">Cancelar</Button>
                            <Button type="submit" variant="primary" :disabled="editForm.processing">Guardar</Button>
                        </div>
                    </form>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal crear / editar usuario -->
        <Dialog :open="userModal" @close="userModal = false">
            <Dialog.Panel>
                <form class="p-5" @submit.prevent="submitUser">
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide :icon="userEditing ? 'UserCog' : 'UserPlus'" class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">{{ userEditing ? 'Editar acceso' : 'Nuevo usuario' }}</h2>
                            <p class="text-xs text-slate-500">{{ tenant.name }}</p>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <FormLabel htmlFor="user-name">Nombre</FormLabel>
                            <FormInput id="user-name" v-model="userForm.name" type="text" placeholder="Ana López" />
                            <FormHelp v-if="userErrors.name" class="text-danger">{{ userErrors.name }}</FormHelp>
                        </div>
                        <div>
                            <FormLabel htmlFor="user-email">Correo (usuario de acceso)</FormLabel>
                            <FormInput id="user-email" v-model="userForm.email" type="email" placeholder="ana@hotel.com" />
                            <FormHelp v-if="userErrors.email" class="text-danger">{{ userErrors.email }}</FormHelp>
                        </div>
                        <div>
                            <FormLabel htmlFor="user-password">
                                Contraseña <span v-if="userEditing" class="text-slate-400">(vacío = conservar la actual)</span>
                            </FormLabel>
                            <FormInput id="user-password" v-model="userForm.password" type="password" :placeholder="userEditing ? '••••••••' : 'Mínimo 8 caracteres'" autocomplete="new-password" />
                            <FormHelp v-if="userErrors.password" class="text-danger">{{ userErrors.password }}</FormHelp>
                        </div>
                        <div>
                            <FormLabel htmlFor="user-role">Rol</FormLabel>
                            <FormSelect id="user-role" v-model="userForm.role">
                                <option v-for="r in ops.assignable_roles" :key="r" :value="r">{{ roleLabel(r) }}</option>
                            </FormSelect>
                            <FormHelp v-if="userErrors.role" class="text-danger">{{ userErrors.role }}</FormHelp>
                        </div>
                        <p v-if="userErrors._" class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ userErrors._ }}</p>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="outline-secondary" @click="userModal = false">Cancelar</Button>
                        <Button type="submit" variant="primary" :disabled="userSaving">
                            <Lucide icon="Check" class="mr-2 h-4 w-4" /> {{ userSaving ? 'Guardando…' : (userEditing ? 'Guardar cambios' : 'Crear usuario') }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal eliminar usuario -->
        <Dialog :open="userDeleting !== null" @close="userDeleting = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide icon="TriangleAlert" class="mx-auto mb-3 h-12 w-12 text-danger" />
                    <h2 class="text-base font-medium">¿Eliminar a {{ userDeleting?.name }}?</h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Perderá el acceso al panel de {{ tenant.name }}. Si tiene ventas, turnos o cortes
                        registrados, se conservará por auditoría (no se podrá borrar).
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button variant="outline-secondary" @click="userDeleting = null">Cancelar</Button>
                        <Button variant="danger" :disabled="userSaving" @click="deleteUser">
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" /> Sí, eliminar
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
