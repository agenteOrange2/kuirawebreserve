<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import RazeLayout from '@/layouts/RazeLayout.vue';
import TenantHeader from './TenantHeader.vue';
import type { PlanOption, TenantShell } from './types';

interface ReservationRow {
    code: string;
    guest: string | null;
    status: string;
    status_label: string;
    starts_at: string;
    total: number;
}

interface ActivityRow {
    id: number;
    label: string;
    icon: Icon;
    tone: string;
    details: string[];
    at: string | null;
    ago: string | null;
    user: { id: number; name: string } | null;
}

const props = defineProps<{
    tenant: TenantShell;
    plans: PlanOption[];
    ops: {
        owner: { name: string; email: string } | null;
        users: number;
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
    contract: {
        price_monthly: number;
        addons: number;
        ai_in_plan: boolean;
        max_properties: number | null;
        max_rooms: number | null;
        max_users: number | null;
    };
    activity: ActivityRow[];
}>();

const money = (n: number) =>
    `$${n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';

const statusTone: Record<string, string> = {
    pending: 'bg-warning/10 text-warning',
    confirmed: 'bg-info/10 text-info',
    checked_in: 'bg-primary/10 text-primary',
    completed: 'bg-success/10 text-success',
    cancelled: 'bg-danger/10 text-danger',
    no_show: 'bg-pending/10 text-pending',
};

const toneClass: Record<string, string> = {
    primary: 'border-primary/10 bg-primary/10 text-primary',
    success: 'border-success/10 bg-success/10 text-success',
    warning: 'border-warning/10 bg-warning/10 text-warning',
    danger: 'border-danger/10 bg-danger/10 text-danger',
    info: 'border-info/10 bg-info/10 text-info',
    pending: 'border-pending/10 bg-pending/10 text-pending',
};

// Al tope (o pasado) de lo que permite su plan: lo primero que hay que
// platicar con el cliente.
const atCap = (value: number, cap: number | null) =>
    cap !== null && cap > 0 && value >= cap;

// Cómo va el hotel: lo que se mira de un vistazo. Lo que se administra
// vive en las otras pestañas.
const kpis: Array<{
    icon: Icon;
    tone: string;
    value: string;
    cap: number | null;
    label: string;
    hint?: string;
    alert?: boolean;
}> = [
    {
        icon: 'Users',
        tone: 'primary',
        value: String(props.ops.users),
        cap: props.contract.max_users,
        label: 'Usuarios',
        hint: atCap(props.ops.users, props.contract.max_users)
            ? 'Al tope de su plan'
            : 'Sin el asistente',
        alert: atCap(props.ops.users, props.contract.max_users),
    },
    {
        icon: 'BedDouble',
        tone: 'info',
        value: String(props.ops.rooms),
        cap: props.contract.max_rooms,
        label: 'Habitaciones',
        hint: atCap(props.ops.rooms, props.contract.max_rooms)
            ? 'Al tope de su plan'
            : `${props.ops.properties} ${props.ops.properties === 1 ? 'propiedad' : 'propiedades'}`,
        alert: atCap(props.ops.rooms, props.contract.max_rooms),
    },
    {
        icon: 'CalendarCheck',
        tone: 'success',
        value: String(props.ops.reservations_month),
        cap: null,
        label: 'Reservas del mes',
        hint: 'Creadas',
    },
    {
        icon: 'Banknote',
        tone: 'warning',
        value: money(props.ops.revenue_month),
        cap: null,
        label: 'Cobrado este mes',
        hint: 'Sin fianzas',
    },
    {
        icon: 'DoorOpen',
        tone: 'pending',
        value: String(props.ops.active_stays),
        cap: null,
        label: 'Estancias activas',
        hint: 'Dentro ahora',
    },
    {
        icon: 'MessagesSquare',
        tone: 'info',
        value: String(props.ops.conversations),
        cap: null,
        label: 'Conversaciones',
        hint:
            props.ops.conversations_pending > 0
                ? `${props.ops.conversations_pending} esperando`
                : 'Ninguna esperando',
        alert: props.ops.conversations_pending > 0,
    },
];

const facts: Array<{ label: string; value: string }> = [
    {
        label: 'Propiedades',
        value: `${props.ops.properties} de ${props.contract.max_properties ?? 'sin límite'}`,
    },
    {
        label: 'Servicios adicionales',
        value: props.contract.addons
            ? `${props.contract.addons} contratado${props.contract.addons === 1 ? '' : 's'}`
            : 'Ninguno',
    },
    {
        label: 'Huéspedes en su CRM',
        value: props.ops.guests.toLocaleString('es-MX'),
    },
];
</script>

<template>
    <RazeLayout :title="tenant.name">
        <TenantHeader :tenant="tenant" :plans="plans" active="overview" />

        <!-- Cómo va la operación -->
        <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
            <div
                v-for="kpi in kpis"
                :key="kpi.label"
                class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 sm:col-span-4 2xl:col-span-2"
            >
                <div :class="[sectionIcon, toneClass[kpi.tone]]">
                    <Lucide :icon="kpi.icon" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <div class="truncate text-sm font-medium">
                        {{ kpi.value
                        }}<span
                            v-if="kpi.cap"
                            class="text-[11px] font-normal text-slate-400"
                        >
                            / {{ kpi.cap }}</span
                        >
                    </div>
                    <div class="text-xs leading-tight text-slate-500">
                        {{ kpi.label }}
                    </div>
                    <div
                        v-if="kpi.hint"
                        class="truncate text-[11px]"
                        :title="kpi.hint"
                        :class="
                            kpi.alert
                                ? 'font-medium text-warning'
                                : 'text-slate-400'
                        "
                    >
                        {{ kpi.hint }}
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-12 items-stretch gap-5">
            <!-- Lo que está pasando: sus reservas más nuevas -->
            <div class="col-span-12 flex flex-col xl:col-span-8">
                <div class="box box--stacked flex flex-1 flex-col">
                    <div :class="cardHeader">
                        <div :class="[sectionIcon, toneClass.primary]">
                            <Lucide icon="CalendarClock" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-sm font-medium">
                                Reservas recientes
                            </h2>
                            <p class="text-xs text-slate-500">
                                Las últimas que se crearon, por cualquier canal.
                            </p>
                        </div>
                    </div>
                    <div
                        v-if="ops.recent_reservations.length"
                        class="flex-1 divide-y divide-slate-200/60 dark:divide-darkmode-400"
                    >
                        <div
                            v-for="r in ops.recent_reservations"
                            :key="r.code"
                            class="flex items-start gap-3 px-4 py-3 sm:items-center sm:px-5"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">
                                    {{ r.guest ?? 'Sin nombre' }}
                                </div>
                                <div class="text-xs text-slate-500">
                                    <span class="font-mono">{{ r.code }}</span>
                                    <span class="hidden sm:inline"> · </span
                                    ><br class="sm:hidden" />llega
                                    {{ r.starts_at }}
                                </div>
                            </div>
                            <div
                                class="flex shrink-0 flex-col items-end gap-1 sm:flex-row sm:items-center sm:gap-3"
                            >
                                <span
                                    class="order-2 rounded-full px-2 py-0.5 text-[11px] font-medium sm:order-1"
                                    :class="
                                        statusTone[r.status] ??
                                        'bg-slate-100 text-slate-500'
                                    "
                                >
                                    {{ r.status_label }}
                                </span>
                                <div
                                    class="order-1 text-sm font-medium tabular-nums sm:order-2 sm:w-28 sm:text-right"
                                >
                                    {{ money(r.total) }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div
                        v-else
                        class="flex flex-1 flex-col items-center justify-center gap-2 px-4 py-10 text-center"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                        >
                            <Lucide icon="CalendarX2" class="h-4 w-4" />
                        </div>
                        <p class="text-xs text-slate-500">
                            Aún no tiene reservas.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Ficha corta: quién es y qué paga. El detalle, en su pestaña -->
            <div class="col-span-12 flex flex-col xl:col-span-4">
                <div class="box box--stacked flex flex-1 flex-col">
                    <div :class="cardHeader">
                        <div :class="[sectionIcon, toneClass.success]">
                            <Lucide icon="FileText" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-sm font-medium">El cliente</h2>
                            <p class="text-xs text-slate-500">
                                Quién es y qué paga.
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-1 flex-col px-4 py-3">
                        <div class="flex items-end justify-between gap-3">
                            <div>
                                <div
                                    class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    Paga al mes
                                </div>
                                <div class="text-sm font-medium text-primary">
                                    ${{
                                        contract.price_monthly.toLocaleString(
                                            'es-MX',
                                        )
                                    }}
                                    MXN
                                </div>
                            </div>
                            <span
                                class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                >{{ tenant.plan_label }}</span
                            >
                        </div>

                        <dl
                            class="mt-3 divide-y divide-dashed divide-slate-200/70 border-y border-dashed border-slate-200/70 text-xs dark:divide-darkmode-400 dark:border-darkmode-400"
                        >
                            <div
                                v-for="fact in facts"
                                :key="fact.label"
                                class="flex items-center justify-between gap-3 py-2"
                            >
                                <dt class="text-slate-500">{{ fact.label }}</dt>
                                <dd class="font-medium">{{ fact.value }}</dd>
                            </div>
                            <div
                                class="flex items-center justify-between gap-3 py-2"
                            >
                                <dt class="text-slate-500">Asistente IA</dt>
                                <dd>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="
                                            contract.ai_in_plan
                                                ? 'bg-success/10 text-success'
                                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                        "
                                    >
                                        {{
                                            contract.ai_in_plan
                                                ? 'En su plan'
                                                : 'Fuera del plan'
                                        }}
                                    </span>
                                </dd>
                            </div>
                        </dl>

                        <div class="mt-3">
                            <div
                                class="mb-1.5 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Dueño
                            </div>
                            <template v-if="ops.owner">
                                <div class="text-sm font-medium">
                                    {{ ops.owner.name }}
                                </div>
                                <a
                                    :href="`mailto:${ops.owner.email}`"
                                    class="mt-1 inline-flex max-w-full items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 transition hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                >
                                    <Lucide
                                        icon="Mail"
                                        class="h-3 w-3 shrink-0"
                                    />
                                    <span class="truncate">{{
                                        ops.owner.email
                                    }}</span>
                                </a>
                            </template>
                            <div
                                v-else
                                class="flex items-start gap-2 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                            >
                                <Lucide
                                    icon="TriangleAlert"
                                    class="mt-px h-3.5 w-3.5 shrink-0"
                                />
                                Sin usuario propietario: "Entrar como" no va a
                                funcionar. Dale el rol de dueño a alguien en
                                Equipo.
                            </div>
                        </div>

                        <div class="mt-auto flex flex-wrap gap-2 pt-4">
                            <Link
                                :href="route('admin.tenants.plan', tenant.id)"
                                class="inline-flex h-8 items-center gap-1.5 rounded-[0.5rem] border border-slate-200 px-3 text-xs font-medium text-slate-600 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:text-slate-300"
                            >
                                <Lucide icon="Layers" class="h-3.5 w-3.5" />
                                Plan y facturación
                            </Link>
                            <Link
                                :href="route('admin.tenants.team', tenant.id)"
                                class="inline-flex h-8 items-center gap-1.5 rounded-[0.5rem] border border-slate-200 px-3 text-xs font-medium text-slate-600 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:text-slate-300"
                            >
                                <Lucide icon="UserCog" class="h-3.5 w-3.5" />
                                Equipo
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lo que ya pasó: qué le hizo la plataforma a este hotel -->
            <div class="col-span-12">
                <div class="box box--stacked">
                    <div :class="cardHeader">
                        <div :class="[sectionIcon, toneClass.info]">
                            <Lucide icon="History" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-sm font-medium">
                                Cambios de la plataforma
                            </h2>
                            <p class="text-xs text-slate-500">
                                Lo último que el equipo de Kuiraweb le hizo a
                                este hotel desde el admin.
                            </p>
                        </div>
                    </div>
                    <div
                        v-if="activity.length"
                        class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                    >
                        <div
                            v-for="a in activity"
                            :key="a.id"
                            class="flex items-start gap-3 px-4 py-3 sm:px-5"
                        >
                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border"
                                :class="
                                    toneClass[a.tone] ??
                                    'border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400'
                                "
                            >
                                <Lucide :icon="a.icon" class="h-3.5 w-3.5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium">
                                    {{ a.label }}
                                </div>
                                <div
                                    v-if="a.details.length"
                                    class="truncate text-xs text-slate-500"
                                    :title="a.details.join(' · ')"
                                >
                                    {{ a.details.join(' · ') }}
                                </div>
                            </div>
                            <div
                                class="shrink-0 text-right text-[11px] text-slate-400"
                            >
                                <div :title="a.at ?? undefined">
                                    {{ a.ago }}
                                </div>
                                <Link
                                    v-if="a.user"
                                    :href="route('admin.users.show', a.user.id)"
                                    class="text-primary"
                                    >{{ a.user.name }}</Link
                                >
                            </div>
                        </div>
                    </div>
                    <p
                        v-else
                        class="px-4 py-6 text-center text-xs text-slate-500"
                    >
                        Todavía no hay cambios registrados para este hotel.
                    </p>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
