<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface UsageStat {
    key: string;
    label: string;
    count: number;
    revenue: number;
}

interface UpcomingRow {
    id: number;
    code: string;
    guest_name: string;
    status: string;
    status_label: string;
    starts_at: string;
    ends_at: string;
    starts_today: boolean;
    total_amount: number;
    pending: number;
}

interface RecentRow {
    id: number;
    guest_id: number | null;
    guest_name: string;
    check_in_at: string | null;
    check_out_at: string | null;
    nights: number | null;
    amount: number;
}

interface IncidentRow {
    id: number;
    title: string;
    status_label: string;
    priority: string;
    priority_label: string;
    category_label: string | null;
    overdue: boolean;
    age_hours: number;
}

const props = defineProps<{
    room: {
        id: number;
        number: string;
        name: string | null;
        description: string | null;
        room_type: string;
        price_from: number | null;
        zone: string | null;
        zone_color: string | null;
        status: string;
        status_label: string;
        status_color: string;
        beds_label: string | null;
        capacity: number | null;
        size_m2: number | null;
        view: string | null;
        amenities: string[];
        smoking: boolean;
        accessible: boolean;
        price_modifier: number | null;
        notes: string | null;
        maintenance_notes: string | null;
        usage_count: number;
        usage_limit: number | null;
        usage_locked: boolean;
    };
    /** Estancia activa: quién está dentro ahora mismo. */
    current: {
        id: number;
        guest_id: number | null;
        guest_name: string;
        num_people: number | null;
        rate_plan: string | null;
        check_in_at: string | null;
        planned_end_at: string | null;
        is_overdue: boolean;
        vehicle_plate: string | null;
        amount: number;
        pending: number;
        consumption_pending: number;
    } | null;
    upcoming: UpcomingRow[];
    recent: RecentRow[];
    incidents: IncidentRow[];
    cleaning: {
        open: boolean;
        kind_label: string;
        housekeeper: string | null;
        minutes: number;
        started_at: string | null;
        ended_at: string | null;
    } | null;
    usage: UsageStat[];
    totals: { stays: number; revenue: number; last_stay_at: string | null };
    canManage: boolean;
    canReserve: boolean;
}>();

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 }).format(n ?? 0);

const dotColor: Record<string, string> = {
    green: 'bg-success',
    cyan: 'bg-info',
    red: 'bg-primary',
    orange: 'bg-pending',
    blue: 'bg-warning',
    gray: 'bg-dark',
};
const tint: Record<string, string> = {
    green: 'border-success/10 bg-success/10 text-success',
    cyan: 'border-info/10 bg-info/10 text-info',
    red: 'border-primary/10 bg-primary/10 text-primary',
    orange: 'border-pending/10 bg-pending/10 text-pending',
    blue: 'border-warning/10 bg-warning/10 text-warning',
    gray: 'border-dark/10 bg-dark/10 text-dark',
};
const usageIcons: Record<string, Icon> = {
    week: 'CalendarDays',
    month: 'Calendar',
    quarter: 'CalendarRange',
    year: 'CalendarClock',
};
const reservationStatusClass: Record<string, string> = {
    pending: 'bg-pending/10 text-pending',
    confirmed: 'bg-success/10 text-success',
    checked_in: 'bg-primary/10 text-primary',
};
const priorityClass: Record<string, string> = {
    high: 'bg-danger/10 text-danger',
    medium: 'bg-warning/10 text-warning',
    low: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
};

const priceModifierLabel = computed(() => {
    const m = props.room.price_modifier;
    if (!m) return null;
    return (m > 0 ? '+$' : '−$') + Math.round(Math.abs(m));
});

/** Usos del cuarto: "12 de 30" cuando hay tope, si no el conteo pelón. */
const usageCountLabel = computed(() =>
    props.room.usage_limit
        ? `${props.room.usage_count} de ${props.room.usage_limit} usos`
        : `${props.room.usage_count} ${props.room.usage_count === 1 ? 'uso' : 'usos'}`,
);

/** La siguiente llegada, que es lo que se consulta cuando está libre. */
const nextArrival = computed(() => props.upcoming[0] ?? null);

const reserveHref = computed(
    () =>
        `${route('tenant.reservations')}?intent=reserve&room=${props.room.id}`,
);

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const factRow =
    'flex items-center justify-between gap-3 border-b border-dashed border-slate-200/70 pb-2.5 dark:border-darkmode-400';
const stripItem = 'inline-flex items-center gap-1.5 text-slate-500';
const stripValue = 'font-medium text-slate-700 dark:text-slate-300';
const stripDivider =
    'hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400';
</script>

<template>
    <RazeLayout :title="`Habitación ${room.number}`">
        <div class="mt-2">
            <!-- Encabezado: qué cuarto es, en qué estado está y sus datos
                 duros en una franja; antes eran cuatro cajas de "uso" y la
                 ficha no decía ni la capacidad de un vistazo. -->
            <div class="box box--stacked overflow-hidden">
                <div
                    class="flex flex-col gap-4 p-5 md:flex-row md:items-start md:justify-between"
                >
                    <div class="flex min-w-0 gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border"
                            :class="tint[room.status_color]"
                        >
                            <Lucide icon="BedDouble" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-base font-medium">
                                    Habitación {{ room.number }}
                                </h1>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-medium"
                                    :class="tint[room.status_color]"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full"
                                        :class="dotColor[room.status_color]"
                                    />
                                    {{ room.status_label }}
                                </span>
                                <span
                                    v-if="room.usage_locked"
                                    class="inline-flex items-center gap-1 rounded-full bg-danger/10 px-2.5 py-1 text-[11px] font-medium text-danger"
                                    title="Bloqueada por el contador de usos"
                                >
                                    <Lucide icon="Lock" class="h-3.5 w-3.5" />
                                    Bloqueada
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                <span v-if="room.name" class="font-medium">
                                    {{ room.name }} ·
                                </span>
                                {{ room.room_type }}
                                <template v-if="room.zone">
                                    · {{ room.zone }}
                                </template>
                            </p>
                            <p
                                v-if="room.description"
                                class="mt-0.5 line-clamp-2 text-xs text-slate-500"
                                :title="room.description"
                            >
                                {{ room.description }}
                            </p>
                        </div>
                    </div>
                    <div
                        class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:shrink-0 md:flex-wrap md:items-center md:gap-2"
                    >
                        <!-- El volver vive con las acciones, no flotando
                             encima de la tarjeta. -->
                        <Link
                            :href="route('tenant.rooms')"
                            class="col-span-2 inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary md:col-auto dark:border-darkmode-400 dark:bg-darkmode-600"
                        >
                            <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                            Volver a habitaciones
                        </Link>
                        <Button
                            as="a"
                            :href="route('tenant.rooms.history', room.id)"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs"
                        >
                            <Lucide icon="History" class="mr-1.5 h-3.5 w-3.5" />
                            Historial
                        </Button>
                        <Button
                            v-if="canManage"
                            as="a"
                            :href="`${route('tenant.rooms')}?edit=${room.id}`"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs"
                        >
                            <Lucide icon="Pencil" class="mr-1.5 h-3.5 w-3.5" />
                            Editar
                        </Button>
                        <Button
                            v-if="canReserve && !current"
                            as="a"
                            :href="reserveHref"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                            title="Abre una reserva con esta habitación ya elegida"
                        >
                            <Lucide
                                icon="CalendarPlus"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Reservar
                        </Button>
                    </div>
                </div>

                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-slate-200/60 bg-slate-50/70 px-5 py-3 text-xs dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <span :class="stripItem">
                        <Lucide
                            icon="Users"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">
                            {{ room.capacity ?? '—' }}
                        </span>
                        personas
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="BedDouble"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">
                            {{ room.beds_label ?? 'Sin camas' }}
                        </span>
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="Ruler"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">
                            {{ room.size_m2 ? `${room.size_m2} m²` : '—' }}
                        </span>
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="DollarSign"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">
                            {{
                                room.price_from !== null
                                    ? money(room.price_from)
                                    : 'Sin tarifa'
                            }}
                        </span>
                        <span
                            v-if="priceModifierLabel"
                            class="rounded-full px-1.5 py-0.5 text-[11px] font-medium"
                            :class="
                                room.price_modifier! > 0
                                    ? 'bg-warning/10 text-warning'
                                    : 'bg-success/10 text-success'
                            "
                        >
                            {{ priceModifierLabel }}
                        </span>
                    </span>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium md:ml-auto"
                        :class="
                            room.usage_locked
                                ? 'bg-danger/10 text-danger'
                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                        "
                        title="Veces que se ha ocupado esta habitación"
                    >
                        <Lucide icon="Zap" class="h-3.5 w-3.5" />
                        {{ usageCountLabel }}
                    </span>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-12 gap-5">
                <!-- Izquierda: la operación del cuarto -->
                <div class="col-span-12 space-y-5 xl:col-span-8">
                    <!-- Ahora mismo -->
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-primary/10 bg-primary/10 text-primary"
                            >
                                <Lucide icon="DoorOpen" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">Ahora mismo</h2>
                                <p class="text-xs text-slate-500">
                                    Quién está dentro y qué trae pendiente.
                                </p>
                            </div>
                        </div>

                        <!-- Ocupada -->
                        <div
                            v-if="current"
                            class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:px-5"
                        >
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Lucide icon="UserRound" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <Link
                                        v-if="current.guest_id"
                                        :href="
                                            route(
                                                'tenant.guests.show',
                                                current.guest_id,
                                            )
                                        "
                                        class="truncate text-sm font-medium text-primary hover:underline"
                                    >
                                        {{ current.guest_name }}
                                    </Link>
                                    <span
                                        v-else
                                        class="truncate text-sm font-medium"
                                    >
                                        {{ current.guest_name }}
                                    </span>
                                    <span
                                        v-if="current.is_overdue"
                                        class="inline-flex items-center gap-1 rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                    >
                                        <Lucide
                                            icon="TriangleAlert"
                                            class="h-3.5 w-3.5"
                                        />
                                        Salida vencida
                                    </span>
                                    <span
                                        v-if="current.vehicle_plate"
                                        class="rounded-full bg-slate-100 px-2 py-0.5 font-mono text-[11px] font-medium uppercase dark:bg-darkmode-400"
                                    >
                                        {{ current.vehicle_plate }}
                                    </span>
                                </div>
                                <div class="mt-0.5 text-xs text-slate-500">
                                    {{ current.check_in_at }}
                                    <span class="text-slate-400">→</span>
                                    {{ current.planned_end_at ?? 'sin salida' }}
                                    <template v-if="current.num_people">
                                        · {{ current.num_people }}
                                        {{
                                            current.num_people === 1
                                                ? 'persona'
                                                : 'personas'
                                        }}
                                    </template>
                                    <template v-if="current.rate_plan">
                                        · {{ current.rate_plan }}
                                    </template>
                                </div>
                            </div>
                            <div class="shrink-0 sm:text-right">
                                <div class="text-sm font-medium">
                                    {{ money(current.amount) }}
                                </div>
                                <span
                                    class="mt-0.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        current.pending > 0
                                            ? 'bg-pending/10 text-pending'
                                            : 'bg-success/10 text-success'
                                    "
                                >
                                    <Lucide
                                        :icon="
                                            current.pending > 0
                                                ? 'ReceiptText'
                                                : 'CircleCheck'
                                        "
                                        class="h-3.5 w-3.5"
                                    />
                                    {{
                                        current.pending > 0
                                            ? `Debe ${money(current.pending)}`
                                            : 'Sin saldo'
                                    }}
                                </span>
                            </div>
                        </div>

                        <!-- Libre, con la siguiente llegada al frente -->
                        <div
                            v-else
                            class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:px-5"
                        >
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                            >
                                <Lucide icon="CircleCheck" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium">
                                    Sin nadie dentro
                                </div>
                                <div
                                    v-if="nextArrival"
                                    class="mt-0.5 text-xs text-slate-500"
                                >
                                    Próxima llegada:
                                    <span
                                        class="font-medium text-slate-700 dark:text-slate-300"
                                    >
                                        {{ nextArrival.guest_name }}
                                    </span>
                                    el {{ nextArrival.starts_at }}
                                    <span
                                        v-if="nextArrival.starts_today"
                                        class="ml-1 rounded-full bg-success/10 px-1.5 text-[11px] font-medium text-success"
                                    >
                                        hoy
                                    </span>
                                </div>
                                <div
                                    v-else
                                    class="mt-0.5 text-xs text-slate-500"
                                >
                                    No tiene reservas próximas.
                                </div>
                            </div>
                            <Button
                                v-if="canReserve"
                                as="a"
                                :href="reserveHref"
                                variant="outline-primary"
                                class="h-9 shrink-0 rounded-[0.5rem] text-xs"
                            >
                                <Lucide
                                    icon="CalendarPlus"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Reservar
                            </Button>
                        </div>

                        <!-- Fallas abiertas y limpieza: lo que impide vender
                             el cuarto vivía solo en el plano. -->
                        <div
                            v-if="incidents.length || cleaning"
                            class="flex flex-wrap items-center gap-2 border-t border-slate-200/60 bg-slate-50/70 px-4 py-2.5 sm:px-5 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                        >
                            <span
                                v-if="incidents.length"
                                class="inline-flex items-center gap-1.5 rounded-full bg-danger/10 px-2.5 py-1 text-[11px] font-medium text-danger"
                            >
                                <Lucide
                                    icon="TriangleAlert"
                                    class="h-3.5 w-3.5"
                                />
                                {{ incidents.length }}
                                {{
                                    incidents.length === 1
                                        ? 'falla abierta'
                                        : 'fallas abiertas'
                                }}
                            </span>
                            <span
                                v-if="cleaning?.open"
                                class="inline-flex items-center gap-1.5 rounded-full bg-warning/10 px-2.5 py-1 text-[11px] font-medium text-warning"
                            >
                                <Lucide icon="SprayCan" class="h-3.5 w-3.5" />
                                Limpieza en curso
                                <template v-if="cleaning.housekeeper">
                                    · {{ cleaning.housekeeper }}
                                </template>
                                · {{ cleaning.minutes }} min
                            </span>
                            <span
                                v-else-if="cleaning"
                                class="inline-flex items-center gap-1.5 text-[11px] text-slate-500"
                            >
                                <Lucide
                                    icon="SprayCan"
                                    class="h-3.5 w-3.5 text-slate-400"
                                />
                                Última limpieza {{ cleaning.ended_at }}
                                <template v-if="cleaning.housekeeper">
                                    · {{ cleaning.housekeeper }}
                                </template>
                            </span>
                        </div>
                    </div>

                    <!-- Próximas reservas -->
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-info/10 bg-info/10 text-info"
                            >
                                <Lucide icon="CalendarDays" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Próximas reservas
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Lo que ya está apartado para este cuarto.
                                </p>
                            </div>
                            <Button
                                as="a"
                                :href="route('tenant.reservations')"
                                variant="outline-secondary"
                                class="ml-auto h-8 rounded-[0.5rem] bg-white text-xs"
                            >
                                Ver reservas
                                <Lucide
                                    icon="ChevronRight"
                                    class="ml-1 h-3.5 w-3.5"
                                />
                            </Button>
                        </div>
                        <div
                            v-if="upcoming.length"
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="row in upcoming"
                                :key="row.id"
                                class="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-2.5 sm:px-5"
                            >
                                <span
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-darkmode-400"
                                >
                                    {{ row.code }}
                                </span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        reservationStatusClass[row.status] ??
                                        'bg-slate-100 text-slate-500'
                                    "
                                >
                                    {{ row.status_label }}
                                </span>
                                <span
                                    class="min-w-0 flex-1 truncate text-xs font-medium"
                                >
                                    {{ row.guest_name }}
                                </span>
                                <span class="text-xs text-slate-500">
                                    {{ row.starts_at }}
                                    <span class="text-slate-400">→</span>
                                    {{ row.ends_at }}
                                    <span
                                        v-if="row.starts_today"
                                        class="ml-1 rounded-full bg-success/10 px-1.5 text-[11px] font-medium text-success"
                                    >
                                        hoy
                                    </span>
                                </span>
                                <span class="text-xs font-medium">
                                    {{ money(row.total_amount) }}
                                </span>
                                <span
                                    v-if="row.pending > 0"
                                    class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                    title="Saldo por cobrar"
                                >
                                    debe {{ money(row.pending) }}
                                </span>
                            </div>
                        </div>
                        <div
                            v-else
                            class="px-4 py-6 text-center text-xs text-slate-500 sm:px-5"
                        >
                            Sin reservas próximas para esta habitación.
                        </div>
                    </div>

                    <!-- Últimas estancias -->
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-primary/10 bg-primary/10 text-primary"
                            >
                                <Lucide icon="History" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Últimas estancias
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Quién se hospedó aquí y cuánto dejó.
                                </p>
                            </div>
                            <Button
                                as="a"
                                :href="route('tenant.rooms.history', room.id)"
                                variant="outline-secondary"
                                class="ml-auto h-8 rounded-[0.5rem] bg-white text-xs"
                            >
                                Ver todo
                                <Lucide
                                    icon="ChevronRight"
                                    class="ml-1 h-3.5 w-3.5"
                                />
                            </Button>
                        </div>
                        <div
                            v-if="recent.length"
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="row in recent"
                                :key="row.id"
                                class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 sm:px-5"
                            >
                                <Link
                                    v-if="row.guest_id"
                                    :href="
                                        route(
                                            'tenant.guests.show',
                                            row.guest_id,
                                        )
                                    "
                                    class="min-w-0 flex-1 truncate text-xs font-medium text-primary hover:underline"
                                >
                                    {{ row.guest_name }}
                                </Link>
                                <span
                                    v-else
                                    class="min-w-0 flex-1 truncate text-xs font-medium"
                                >
                                    {{ row.guest_name }}
                                </span>
                                <span class="text-xs text-slate-500">
                                    {{ row.check_in_at }}
                                    <span class="text-slate-400">→</span>
                                    {{ row.check_out_at }}
                                </span>
                                <span
                                    v-if="row.nights"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                                >
                                    {{ row.nights }}
                                    {{ row.nights === 1 ? 'noche' : 'noches' }}
                                </span>
                                <span class="text-xs font-medium">
                                    {{ money(row.amount) }}
                                </span>
                            </div>
                        </div>
                        <div
                            v-else
                            class="px-4 py-6 text-center text-xs text-slate-500 sm:px-5"
                        >
                            Todavía no se registra ninguna salida en este
                            cuarto.
                        </div>
                    </div>
                </div>

                <!-- Derecha: cifras y ficha técnica -->
                <div class="col-span-12 space-y-5 xl:col-span-4">
                    <!-- Uso -->
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-success/10 bg-success/10 text-success"
                            >
                                <Lucide icon="ChartColumn" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Uso e ingresos
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Estancias que arrancaron en cada periodo.
                                </p>
                            </div>
                        </div>
                        <div
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <Link
                                v-for="stat in usage"
                                :key="stat.key"
                                :href="route('tenant.rooms.history', room.id)"
                                class="flex items-center gap-2.5 px-4 py-2.5 transition hover:bg-slate-50/70 dark:hover:bg-darkmode-700/30"
                            >
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border"
                                    :class="tint[room.status_color]"
                                >
                                    <Lucide
                                        :icon="
                                            usageIcons[stat.key] ?? 'Calendar'
                                        "
                                        class="h-4 w-4"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-xs font-medium">
                                        {{ stat.label }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ money(stat.revenue) }} generados
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div class="text-sm font-medium">
                                        {{ stat.count }}
                                    </div>
                                    <div class="text-[11px] text-slate-500">
                                        {{ stat.count === 1 ? 'uso' : 'usos' }}
                                    </div>
                                </div>
                            </Link>
                        </div>
                        <div
                            class="space-y-2 border-t border-slate-200/60 bg-slate-50/70 px-4 py-3 text-xs dark:border-darkmode-400 dark:bg-darkmode-600/40"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500">
                                    Estancias totales
                                </span>
                                <span class="font-medium">
                                    {{ totals.stays }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500">
                                    Ingresos generados
                                </span>
                                <span class="font-medium text-success">
                                    {{ money(totals.revenue) }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500">
                                    Última estancia
                                </span>
                                <span class="font-medium">
                                    {{ totals.last_stay_at ?? '—' }}
                                </span>
                            </div>
                            <Button
                                as="a"
                                :href="route('tenant.rooms.history', room.id)"
                                variant="outline-primary"
                                class="mt-1 h-9 w-full rounded-[0.5rem] bg-white text-xs"
                            >
                                <Lucide
                                    icon="History"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Ver historial completo
                            </Button>
                        </div>
                    </div>

                    <!-- Fallas abiertas -->
                    <div v-if="incidents.length" class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-danger/10 bg-danger/10 text-danger"
                            >
                                <Lucide icon="Wrench" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Fallas abiertas
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Pendientes de mantenimiento en el cuarto.
                                </p>
                            </div>
                        </div>
                        <div
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <Link
                                v-for="incident in incidents"
                                :key="incident.id"
                                :href="
                                    route('tenant.incidents.show', incident.id)
                                "
                                class="block px-4 py-2.5 transition hover:bg-slate-50/70 dark:hover:bg-darkmode-700/30"
                            >
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <span class="truncate text-xs font-medium">
                                        {{ incident.title }}
                                    </span>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="
                                            priorityClass[incident.priority] ??
                                            'bg-slate-100 text-slate-500'
                                        "
                                    >
                                        {{ incident.priority_label }}
                                    </span>
                                    <span
                                        v-if="incident.overdue"
                                        class="inline-flex items-center gap-1 rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                    >
                                        <Lucide
                                            icon="Clock"
                                            class="h-3.5 w-3.5"
                                        />
                                        Fuera de tiempo
                                    </span>
                                </div>
                                <div class="mt-0.5 text-xs text-slate-500">
                                    {{ incident.status_label }}
                                    <template v-if="incident.category_label">
                                        · {{ incident.category_label }}
                                    </template>
                                    · {{ incident.age_hours }} h abierta
                                </div>
                            </Link>
                        </div>
                    </div>

                    <!-- Ficha técnica -->
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-info/10 bg-info/10 text-info"
                            >
                                <Lucide icon="Tag" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Ficha de la habitación
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Tipo, ubicación y equipamiento.
                                </p>
                            </div>
                        </div>
                        <div class="space-y-3 px-4 py-3">
                            <dl class="space-y-2.5 text-xs">
                                <div :class="factRow">
                                    <dt
                                        class="flex items-center gap-1.5 text-slate-500"
                                    >
                                        <Lucide
                                            icon="Tag"
                                            class="h-3.5 w-3.5"
                                        />
                                        Tipo
                                    </dt>
                                    <dd class="font-medium">
                                        {{ room.room_type }}
                                    </dd>
                                </div>
                                <div :class="factRow">
                                    <dt
                                        class="flex items-center gap-1.5 text-slate-500"
                                    >
                                        <Lucide
                                            icon="Map"
                                            class="h-3.5 w-3.5"
                                        />
                                        Zona
                                    </dt>
                                    <dd
                                        class="flex items-center gap-1.5 font-medium"
                                    >
                                        <span
                                            v-if="room.zone_color"
                                            class="h-2 w-2 rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    room.zone_color,
                                            }"
                                        />
                                        {{ room.zone ?? '—' }}
                                    </dd>
                                </div>
                                <div :class="factRow" class="!border-b-0 !pb-0">
                                    <dt
                                        class="flex items-center gap-1.5 text-slate-500"
                                    >
                                        <Lucide
                                            icon="Eye"
                                            class="h-3.5 w-3.5"
                                        />
                                        Vista
                                    </dt>
                                    <dd class="font-medium capitalize">
                                        {{ room.view ?? '—' }}
                                    </dd>
                                </div>
                            </dl>

                            <div class="flex flex-wrap gap-1.5">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium"
                                    :class="
                                        room.smoking
                                            ? 'bg-warning/10 text-warning'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                >
                                    <Lucide
                                        icon="Cigarette"
                                        class="h-3.5 w-3.5"
                                    />
                                    {{
                                        room.smoking
                                            ? 'Se permite fumar'
                                            : 'No fumar'
                                    }}
                                </span>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium"
                                    :class="
                                        room.accessible
                                            ? 'bg-success/10 text-success'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                >
                                    <Lucide
                                        icon="Accessibility"
                                        class="h-3.5 w-3.5"
                                    />
                                    {{
                                        room.accessible
                                            ? 'Accesible'
                                            : 'No accesible'
                                    }}
                                </span>
                            </div>

                            <div v-if="room.amenities.length">
                                <div
                                    class="mb-1.5 flex items-center gap-1.5 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    <Lucide
                                        icon="Sparkles"
                                        class="h-3.5 w-3.5"
                                    />
                                    Amenidades
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <span
                                        v-for="a in room.amenities"
                                        :key="a"
                                        class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] dark:bg-darkmode-400"
                                    >
                                        {{ a }}
                                    </span>
                                </div>
                            </div>

                            <div v-if="room.notes">
                                <div
                                    class="mb-1.5 flex items-center gap-1.5 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    <Lucide
                                        icon="StickyNote"
                                        class="h-3.5 w-3.5"
                                    />
                                    Notas internas
                                </div>
                                <p
                                    class="rounded-lg bg-slate-50 px-3 py-2 text-xs leading-relaxed whitespace-pre-line text-slate-600 dark:bg-darkmode-700 dark:text-slate-300"
                                >
                                    {{ room.notes }}
                                </p>
                            </div>

                            <p
                                v-if="room.maintenance_notes"
                                class="flex items-start gap-2 rounded-lg border border-pending/20 bg-pending/5 px-3 py-2 text-xs text-pending"
                            >
                                <Lucide
                                    icon="Wrench"
                                    class="mt-0.5 h-3.5 w-3.5 shrink-0"
                                />
                                {{ room.maintenance_notes }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
