<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import RazeLayout from '@/layouts/RazeLayout.vue';
import { durationLabel } from '@/lib/utils';

interface ActivityRow {
    id: string;
    at: string | null;
    by: string;
    type: string;
    type_label: string;
    subject: string;
    message: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface ShiftRow {
    id: number;
    started_at: string | null;
    ended_at: string | null;
    open: boolean;
    minutes: number | null;
    opening_cash: number;
}

const props = defineProps<{
    user: {
        id: number;
        name: string;
        email: string;
        phone: string | null;
        roles: string[];
        role_labels: string[];
        role_descriptions: string[];
        is_self: boolean;
        created_at: string | null;
        email_verified: boolean;
    };
    stats: {
        actions_total: number;
        actions_30d: number;
        last_activity_at: string | null;
        last_session_at: string | null;
        on_shift: boolean;
        shift_since: string | null;
        by_type: { key: string; label: string; count: number }[];
    };
    shifts: ShiftRow[];
    activities: {
        data: ActivityRow[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { type: string };
    types: { value: string; label: string }[];
    canManage: boolean;
}>();

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', { minimumFractionDigits: 2 }).format(n ?? 0);

const initials = (name: string) =>
    name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');

/** Mismos colores de rol que el listado, para que se reconozcan igual. */
const roleBadge: Record<string, string> = {
    owner: 'bg-primary/10 text-primary',
    manager: 'bg-info/10 text-info',
    'front-desk': 'bg-success/10 text-success',
    housekeeping: 'bg-warning/10 text-warning',
    kitchen: 'bg-pending/10 text-pending',
    agent: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
};
const roleIcon: Record<string, Icon> = {
    owner: 'Crown',
    manager: 'Briefcase',
    'front-desk': 'ConciergeBell',
    housekeeping: 'SprayCan',
    kitchen: 'ChefHat',
    agent: 'Bot',
};

/** Tono e icono por tipo de movimiento en la bitácora. */
const typeMeta: Record<string, { icon: Icon; tone: string }> = {
    reservation: { icon: 'CalendarDays', tone: 'bg-primary/10 text-primary' },
    stay: { icon: 'DoorOpen', tone: 'bg-success/10 text-success' },
    room: { icon: 'BedDouble', tone: 'bg-info/10 text-info' },
    incident: { icon: 'Wrench', tone: 'bg-danger/10 text-danger' },
    payment: { icon: 'Wallet', tone: 'bg-pending/10 text-pending' },
    other: {
        icon: 'History',
        tone: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    },
};
const metaFor = (type: string) => typeMeta[type] ?? typeMeta.other;

// Filtro por tipo: recarga solo la bitácora, sin perder el lugar.
const typeFilter = ref(props.filters.type);
watch(typeFilter, (value) => {
    router.get(
        route('tenant.users.show', props.user.id),
        { type: value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
});

/** Lo que más toca esta persona, para el resumen por área. */
const areasWithActivity = computed(() =>
    props.stats.by_type.filter((area) => area.count > 0),
);

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const factRow =
    'flex items-center justify-between gap-3 border-b border-dashed border-slate-200/70 pb-2.5 text-xs dark:border-darkmode-400';
const stripItem = 'inline-flex items-center gap-1.5 text-slate-500';
const stripValue = 'font-medium text-slate-700 dark:text-slate-300';
const stripDivider =
    'hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400';
</script>

<template>
    <RazeLayout :title="user.name">
        <div class="mt-2">
            <!-- Encabezado en franjas: quién es y cómo contactarlo, y abajo
                 los datos duros de su cuenta. -->
            <div class="box box--stacked overflow-hidden">
                <div
                    class="flex flex-col gap-4 p-5 md:flex-row md:items-start md:justify-between"
                >
                    <div class="flex min-w-0 gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-sm font-semibold text-white shadow-md"
                        >
                            {{ initials(user.name) }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-base font-medium">
                                    {{ user.name }}
                                </h1>
                                <span
                                    v-if="user.is_self"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                >
                                    tú
                                </span>
                                <span
                                    v-for="(role, i) in user.roles"
                                    :key="role"
                                    class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-medium"
                                    :class="
                                        roleBadge[role] ??
                                        'bg-slate-100 text-slate-500'
                                    "
                                >
                                    <Lucide
                                        :icon="roleIcon[role] ?? 'UserRound'"
                                        class="h-3.5 w-3.5"
                                    />
                                    {{ user.role_labels[i] ?? role }}
                                </span>
                                <span
                                    v-if="stats.on_shift"
                                    class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-[11px] font-medium text-success"
                                    :title="`Turno abierto desde ${stats.shift_since}`"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full bg-success"
                                    />
                                    En turno
                                </span>
                            </div>
                            <!-- Contacto en pastillas: se puede llamar o
                                 escribir desde aquí. -->
                            <div
                                class="mt-2 flex flex-wrap items-center gap-1.5"
                            >
                                <a
                                    :href="`mailto:${user.email}`"
                                    class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 transition hover:bg-primary/10 hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                    title="Escribir al usuario"
                                >
                                    <Lucide
                                        icon="Mail"
                                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                    />
                                    <span class="truncate">
                                        {{ user.email }}
                                    </span>
                                </a>
                                <a
                                    v-if="user.phone"
                                    :href="`tel:${user.phone}`"
                                    class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 transition hover:bg-primary/10 hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                    title="Llamar al usuario"
                                >
                                    <Lucide
                                        icon="Phone"
                                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                    />
                                    <span class="truncate">
                                        {{ user.phone }}
                                    </span>
                                </a>
                                <span v-else class="text-xs text-slate-400">
                                    Sin teléfono registrado
                                </span>
                            </div>
                        </div>
                    </div>
                    <div
                        class="flex w-full flex-wrap items-center gap-2 md:w-auto md:shrink-0 md:justify-end"
                    >
                        <!-- El volver vive con las acciones, no flotando
                             encima de la tarjeta. -->
                        <Link
                            :href="route('tenant.users')"
                            class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                        >
                            <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                            Volver a usuarios
                        </Link>
                        <Button
                            :as="Link"
                            :href="`${route('tenant.activity')}?user=${user.id}`"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs"
                            title="Ver sus movimientos en la bitácora global, con más filtros"
                        >
                            <Lucide icon="History" class="mr-1.5 h-3.5 w-3.5" />
                            Ver en bitácora
                        </Button>
                    </div>
                </div>

                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-slate-200/60 bg-slate-50/70 px-5 py-3 text-xs dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <span :class="stripItem">
                        <Lucide
                            icon="CalendarPlus"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        Alta
                        <span :class="stripValue">
                            {{ user.created_at ?? '—' }}
                        </span>
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="LogIn"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        Última sesión
                        <span :class="stripValue">
                            {{ stats.last_session_at ?? 'Nunca ha entrado' }}
                        </span>
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="History"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        Último movimiento
                        <span :class="stripValue">
                            {{ stats.last_activity_at ?? 'Sin movimientos' }}
                        </span>
                    </span>
                    <span
                        v-if="stats.on_shift"
                        class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-[11px] font-medium text-success md:ml-auto"
                    >
                        <Lucide icon="Clock" class="h-3.5 w-3.5" />
                        Turno abierto desde {{ stats.shift_since }}
                    </span>
                </div>
            </div>

            <!-- Cifras de la persona -->
            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="History" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.actions_total }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Movimientos registrados
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                    >
                        <Lucide icon="CalendarDays" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.actions_30d }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            En los últimos 30 días
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                        :class="
                            stats.on_shift
                                ? 'border-success/10 bg-success/10 text-success'
                                : 'border-slate-200 bg-slate-100 text-slate-400 dark:bg-darkmode-400'
                        "
                    >
                        <Lucide icon="Clock" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium">
                            {{ stats.on_shift ? 'En turno' : 'Fuera de turno' }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            {{
                                stats.on_shift
                                    ? `Abrió ${stats.shift_since}`
                                    : 'Sin caja abierta'
                            }}
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                        :class="
                            user.email_verified
                                ? 'border-success/10 bg-success/10 text-success'
                                : 'border-pending/10 bg-pending/10 text-pending'
                        "
                    >
                        <Lucide
                            :icon="
                                user.email_verified
                                    ? 'ShieldCheck'
                                    : 'ShieldAlert'
                            "
                            class="h-4 w-4"
                        />
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium">
                            {{
                                user.email_verified
                                    ? 'Correo verificado'
                                    : 'Sin verificar'
                            }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Acceso al sistema
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-12 items-stretch gap-5">
                <!-- Bitácora de la persona -->
                <div class="col-span-12 flex flex-col xl:col-span-8">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-primary/10 bg-primary/10 text-primary"
                            >
                                <Lucide icon="History" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Qué ha hecho
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Cada movimiento suyo, del más reciente al
                                    más viejo.
                                </p>
                            </div>
                            <FormSelect
                                v-model="typeFilter"
                                class="ml-auto h-9 w-full text-xs sm:w-52"
                                aria-label="Filtrar por tipo de movimiento"
                            >
                                <option value="">Todo tipo</option>
                                <option
                                    v-for="t in types"
                                    :key="t.value"
                                    :value="t.value"
                                >
                                    {{ t.label }}
                                </option>
                            </FormSelect>
                        </div>

                        <div
                            v-if="activities.data.length"
                            class="flex-1 divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="entry in activities.data"
                                :key="entry.id"
                                class="flex items-start gap-2.5 px-4 py-3 sm:px-5"
                            >
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                    :class="metaFor(entry.type).tone"
                                >
                                    <Lucide
                                        :icon="metaFor(entry.type).icon"
                                        class="h-4 w-4"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <span class="text-xs font-medium">
                                            {{ entry.message }}
                                        </span>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                            :class="metaFor(entry.type).tone"
                                        >
                                            {{ entry.type_label }}
                                        </span>
                                    </div>
                                    <div
                                        class="truncate text-xs text-slate-500"
                                        :title="entry.subject"
                                    >
                                        {{ entry.subject }}
                                    </div>
                                </div>
                                <span
                                    class="shrink-0 text-[11px] whitespace-nowrap text-slate-400"
                                >
                                    {{ entry.at }}
                                </span>
                            </div>
                        </div>
                        <div
                            v-else
                            class="flex flex-1 flex-col items-center justify-center gap-2.5 px-4 py-10 text-center"
                        >
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                            >
                                <Lucide icon="History" class="h-5 w-5" />
                            </div>
                            <p class="text-xs text-slate-500">
                                {{
                                    filters.type
                                        ? 'No hay movimientos de ese tipo.'
                                        : 'Todavía no registra movimientos en el sistema.'
                                }}
                            </p>
                        </div>

                        <div
                            v-if="activities.links.length > 3"
                            class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <p class="text-xs text-slate-500">
                                Mostrando {{ activities.from ?? 0 }}-{{
                                    activities.to ?? 0
                                }}
                                de {{ activities.total }}
                            </p>
                            <div class="flex flex-wrap gap-1">
                                <template
                                    v-for="(link, i) in activities.links"
                                    :key="i"
                                >
                                    <Link
                                        v-if="link.url"
                                        :href="link.url"
                                        preserve-state
                                        preserve-scroll
                                        class="rounded-md px-2.5 py-1 text-xs transition"
                                        :class="
                                            link.active
                                                ? 'bg-primary text-white'
                                                : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-darkmode-400'
                                        "
                                    >
                                        <span v-html="link.label" />
                                    </Link>
                                    <span
                                        v-else
                                        class="px-2.5 py-1 text-xs text-slate-400"
                                        v-html="link.label"
                                    />
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 space-y-5 xl:col-span-4">
                    <!-- Qué puede hacer -->
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-info/10 bg-info/10 text-info"
                            >
                                <Lucide icon="ShieldCheck" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Qué puede hacer
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Su rol y hasta dónde llega.
                                </p>
                            </div>
                        </div>
                        <div class="space-y-2.5 px-4 py-3">
                            <div
                                v-for="(role, i) in user.roles"
                                :key="role"
                                class="rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400"
                            >
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-medium"
                                    :class="
                                        roleBadge[role] ??
                                        'bg-slate-100 text-slate-500'
                                    "
                                >
                                    <Lucide
                                        :icon="roleIcon[role] ?? 'UserRound'"
                                        class="h-3.5 w-3.5"
                                    />
                                    {{ user.role_labels[i] ?? role }}
                                </span>
                                <p
                                    v-if="user.role_descriptions[i]"
                                    class="mt-1.5 text-xs leading-relaxed text-slate-500"
                                >
                                    {{ user.role_descriptions[i] }}
                                </p>
                            </div>
                            <p
                                v-if="!user.roles.length"
                                class="rounded-lg border border-dashed border-slate-300/70 px-3 py-2.5 text-xs text-slate-400 dark:border-darkmode-400"
                            >
                                Sin rol asignado: no puede hacer nada en el
                                panel hasta que se le dé uno.
                            </p>
                        </div>
                    </div>

                    <!-- Dónde trabaja -->
                    <div
                        v-if="areasWithActivity.length"
                        class="box box--stacked"
                    >
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-success/10 bg-success/10 text-success"
                            >
                                <Lucide icon="ChartColumn" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Dónde trabaja
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Sus movimientos por área.
                                </p>
                            </div>
                        </div>
                        <div class="space-y-2.5 px-4 py-3">
                            <div
                                v-for="area in areasWithActivity"
                                :key="area.key"
                                :class="factRow"
                                class="last:!border-b-0 last:!pb-0"
                            >
                                <span
                                    class="inline-flex items-center gap-1.5 text-slate-500"
                                >
                                    <Lucide
                                        :icon="metaFor(area.key).icon"
                                        class="h-3.5 w-3.5 text-slate-400"
                                    />
                                    {{ area.label }}
                                </span>
                                <span class="font-medium">
                                    {{ area.count }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Turnos de caja -->
                    <div v-if="shifts.length" class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-pending/10 bg-pending/10 text-pending"
                            >
                                <Lucide icon="Clock" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Últimos turnos
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Cuándo abrió y cerró caja.
                                </p>
                            </div>
                        </div>
                        <div
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="shift in shifts"
                                :key="shift.id"
                                class="flex items-center gap-2.5 px-4 py-2.5"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="text-xs font-medium">
                                        {{ shift.started_at }}
                                    </div>
                                    <div class="text-[11px] text-slate-500">
                                        <template v-if="shift.open">
                                            Abierto · fondo
                                            {{ money(shift.opening_cash) }}
                                        </template>
                                        <template v-else>
                                            Cerró {{ shift.ended_at }}
                                        </template>
                                    </div>
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        shift.open
                                            ? 'bg-success/10 text-success'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                >
                                    {{ durationLabel(shift.minutes, '—') }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
