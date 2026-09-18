<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';
import ReservationsNav from './ReservationsNav.vue';

/** Reserva recién capturada: hoy sale como "Nuevo", mañana como "Ayer". */
interface FreshRow {
    id: number;
    code: string;
    guest_name: string | null;
    room: string | null;
    room_type: string | null;
    starts_at: string;
    ends_at: string;
    created_at: string;
    total_amount: string;
    status: string;
    status_label: string;
    source_channel: string;
    freshness: 'today' | 'yesterday';
    area: 'upcoming' | 'in-house' | 'pending' | 'history';
    area_label: string;
}

/** Un día del pulso de la semana. */
interface WeekDay {
    date: string;
    label: string;
    weekend: boolean;
    arrivals: number;
    departures: number;
}

const props = defineProps<{
    property: { id: number; name: string };
    upcoming: { total: number; today: number; arrival_pending: number };
    inHouse: { total: number; departures_today: number; overdue: number };
    pending: { total: number; expiring: number; settlements: number };
    history: { total: number; last_week: number };
    week: { days: WeekDay[]; arrivals: number; departures: number };
    fresh: { rows: FreshRow[]; total: number; today: number };
    canManage: boolean;
}>();

// Cada renglón lleva al área donde esa reserva se trabaja.
const areaRoutes: Record<FreshRow['area'], string> = {
    upcoming: 'tenant.reservations.upcoming',
    'in-house': 'tenant.reservations.in-house',
    pending: 'tenant.reservations.pending',
    history: 'tenant.reservations.history',
};

const statusTone: Record<string, string> = {
    pending: 'bg-warning/10 text-warning',
    confirmed: 'bg-primary/10 text-primary',
    checked_in: 'bg-success/10 text-success',
    completed: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    cancelled: 'bg-danger/10 text-danger',
    no_show: 'bg-pending/10 text-pending',
};

const channelLabel: Record<string, string> = {
    web: 'Sitio web',
    agent: 'Asistente',
    front_desk: 'Mostrador',
    counter: 'Mostrador',
    walk_in: 'Sin reserva',
    phone: 'Teléfono',
    whatsapp: 'WhatsApp',
};

const money = (value: number | string) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value) || 0);

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const sectionLabel =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
// La cabecera de tabla es rótulo, no contenido.
const tableHead = sectionLabel;

/**
 * El día más cargado de la semana marca la altura de las barras: así se ve
 * de un vistazo cuál es el puente, sin leer siete números.
 */
const weekPeak = computed(() =>
    Math.max(
        1,
        ...props.week.days.map((d) => Math.max(d.arrivals, d.departures)),
    ),
);

const barHeight = (value: number) =>
    value
        ? `${Math.max(12, Math.round((value / weekPeak.value) * 100))}%`
        : '0%';

interface HubCard {
    key: string;
    title: string;
    icon: Icon;
    tone: string;
    href: string;
    count: number;
    unit: string;
    detail: string;
    /** Lo que está atorado: se pinta aparte para que salte a la vista. */
    alert: string | null;
    alertTone: string;
}

const count = (n: number, one: string, many: string) =>
    `${n} ${n === 1 ? one : many}`;

const cards = computed<HubCard[]>(() => [
    {
        key: 'upcoming',
        title: 'Próximas reservas',
        icon: 'CalendarDays',
        tone: 'border-primary/10 bg-primary/10 text-primary',
        href: route('tenant.reservations.upcoming'),
        count: props.upcoming.total,
        unit: props.upcoming.total === 1 ? 'apartada' : 'apartadas',
        detail: props.upcoming.today
            ? `${count(props.upcoming.today, 'llegada', 'llegadas')} hoy`
            : 'Ninguna llega hoy',
        alert: props.upcoming.arrival_pending
            ? `${count(props.upcoming.arrival_pending, 'llegada', 'llegadas')} sin registrar`
            : null,
        alertTone: 'bg-warning/10 text-warning',
    },
    {
        key: 'in-house',
        title: 'Reservas en casa',
        icon: 'DoorOpen',
        tone: 'border-info/10 bg-info/10 text-info',
        href: route('tenant.reservations.in-house'),
        count: props.inHouse.total,
        unit: props.inHouse.total === 1 ? 'alojado' : 'alojados',
        detail: props.inHouse.departures_today
            ? `${count(props.inHouse.departures_today, 'salida', 'salidas')} hoy`
            : 'Nadie sale hoy',
        alert: props.inHouse.overdue
            ? `${count(props.inHouse.overdue, 'salida vencida', 'salidas vencidas')}`
            : null,
        alertTone: 'bg-danger/10 text-danger',
    },
    {
        key: 'pending',
        title: 'Pendientes',
        icon: 'AlarmClock',
        tone: 'border-pending/10 bg-pending/10 text-pending',
        href: route('tenant.reservations.pending'),
        count: props.pending.total,
        unit: props.pending.total === 1 ? 'por confirmar' : 'por confirmar',
        detail: props.pending.settlements
            ? `${count(props.pending.settlements, 'cuenta', 'cuentas')} sin cobrar`
            : 'Sin cuentas por cobrar',
        alert: props.pending.expiring
            ? `${count(props.pending.expiring, 'apartado', 'apartados')} por vencer`
            : null,
        alertTone: 'bg-warning/10 text-warning',
    },
    {
        key: 'history',
        title: 'Historial',
        icon: 'History',
        tone: 'border-success/10 bg-success/10 text-success',
        href: route('tenant.reservations.history'),
        count: props.history.total,
        unit: props.history.total === 1 ? 'cerrada' : 'cerradas',
        detail: props.history.last_week
            ? `${count(props.history.last_week, 'movimiento', 'movimientos')} esta semana`
            : 'Sin movimientos esta semana',
        alert: null,
        alertTone: '',
    },
]);
</script>

<template>
    <RazeLayout title="Reservas">
        <div class="mt-2">
            <!-- Encabezado -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="CalendarDays" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Reservas</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ property.name }} · cómo viene el día y dónde está
                            el trabajo pendiente
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Button
                        v-if="canManage"
                        :as="Link"
                        :href="`${route('tenant.reservations.operation')}?intent=walkin`"
                        variant="outline-primary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide icon="Zap" class="mr-1.5 h-3.5 w-3.5" />
                        Llegó sin reserva
                    </Button>
                    <Button
                        as="a"
                        :href="route('tenant.reservations.reports')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="ChartColumn"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.5]"
                        />
                        Reportes
                    </Button>
                    <Button
                        v-if="canManage"
                        :as="Link"
                        :href="`${route('tenant.reservations.operation')}?intent=reserve`"
                        variant="primary"
                        class="col-span-2 h-9 text-xs md:col-auto"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Nueva reserva
                    </Button>
                </div>
            </div>

            <ReservationsNav
                current="hub"
                :badges="{
                    pending: pending.total,
                    settlements: pending.settlements,
                }"
            />

            <!-- Los cuatro accesos de la sección -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">Dónde está el trabajo</span>
                <span class="hidden text-[11px] text-slate-400 sm:inline">
                    Cada tarjeta abre su pantalla completa
                </span>
            </div>
            <div class="mt-2 grid auto-rows-fr grid-cols-12 gap-4">
                <Link
                    v-for="card in cards"
                    :key="card.key"
                    :href="card.href"
                    class="box box--stacked col-span-12 flex flex-col gap-3 p-4 transition hover:border-primary/30 sm:col-span-6 xl:col-span-3"
                >
                    <div class="flex items-center gap-2.5">
                        <div :class="[sectionIcon, card.tone]">
                            <Lucide :icon="card.icon" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium">
                                {{ card.title }}
                            </div>
                        </div>
                        <Lucide
                            icon="ChevronRight"
                            class="h-4 w-4 shrink-0 text-slate-300"
                        />
                    </div>
                    <div class="mt-auto">
                        <div class="text-base font-medium">
                            {{ card.count }}
                            <span class="text-xs font-normal text-slate-500">
                                {{ card.unit }}
                            </span>
                        </div>
                        <div class="mt-0.5 truncate text-xs text-slate-500">
                            {{ card.detail }}
                        </div>
                        <span
                            v-if="card.alert"
                            class="mt-2 inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="card.alertTone"
                        >
                            {{ card.alert }}
                        </span>
                    </div>
                </Link>
            </div>

            <!-- Cómo viene la semana y dónde se trabaja el día: dos columnas
                 parejas para no dejar el pulso solo a lo ancho. -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">Los próximos siete días</span>
                <span class="hidden text-[11px] text-slate-400 sm:inline">
                    Llegadas y salidas ya apartadas
                </span>
            </div>
            <div class="mt-2 grid grid-cols-12 items-stretch gap-4">
                <div class="col-span-12 flex flex-col xl:col-span-8">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-primary/10 bg-primary/10 text-primary',
                                ]"
                            >
                                <Lucide icon="CalendarRange" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Movimiento de la semana
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ week.arrivals }}
                                    {{
                                        week.arrivals === 1
                                            ? 'llegada'
                                            : 'llegadas'
                                    }}
                                    y {{ week.departures }}
                                    {{
                                        week.departures === 1
                                            ? 'salida'
                                            : 'salidas'
                                    }}
                                    en total
                                </div>
                            </div>
                            <div
                                class="ml-auto flex items-center gap-3 text-[11px] text-slate-500"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <span
                                        class="h-2 w-2 rounded-full bg-primary"
                                    />
                                    Llegan
                                </span>
                                <span class="inline-flex items-center gap-1.5">
                                    <span
                                        class="h-2 w-2 rounded-full bg-slate-300"
                                    />
                                    Salen
                                </span>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col px-4 py-3">
                            <div
                                class="grid grid-cols-4 gap-2 sm:grid-cols-7 sm:gap-3"
                            >
                                <Link
                                    v-for="(day, index) in week.days"
                                    :key="day.date"
                                    :href="`${route('tenant.reservations.upcoming')}?date=${day.date}`"
                                    class="flex flex-col items-center gap-2 rounded-[0.6rem] border px-2 py-2.5 transition hover:border-primary/30"
                                    :class="
                                        index === 0
                                            ? 'border-primary/30 bg-primary/5'
                                            : day.weekend
                                              ? 'border-slate-200/70 bg-slate-50/70 dark:border-darkmode-400 dark:bg-darkmode-600/40'
                                              : 'border-slate-200/70 dark:border-darkmode-400'
                                    "
                                    :title="`${day.arrivals} llegan y ${day.departures} salen`"
                                >
                                    <span
                                        class="truncate text-[11px] font-medium"
                                        :class="
                                            index === 0
                                                ? 'text-primary'
                                                : 'text-slate-500'
                                        "
                                    >
                                        {{ day.label }}
                                    </span>
                                    <!-- Dos barras a la misma escala: el día
                                         más cargado marca el tope. -->
                                    <div
                                        class="flex h-14 items-end justify-center gap-1.5"
                                    >
                                        <span
                                            class="min-h-[2px] w-2.5 rounded-t bg-primary/70"
                                            :style="{
                                                height: barHeight(day.arrivals),
                                            }"
                                        />
                                        <span
                                            class="min-h-[2px] w-2.5 rounded-t bg-slate-300 dark:bg-darkmode-400"
                                            :style="{
                                                height: barHeight(
                                                    day.departures,
                                                ),
                                            }"
                                        />
                                    </div>
                                    <span
                                        class="text-xs font-medium text-slate-600 dark:text-slate-300"
                                    >
                                        {{ day.arrivals }}
                                        <span class="text-slate-400"
                                            >/ {{ day.departures }}</span
                                        >
                                    </span>
                                </Link>
                            </div>
                            <p class="mt-3 text-[11px] text-slate-400">
                                Cuentan las reservas vivas: apartadas,
                                confirmadas y las que ya están en casa.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- La pantalla de siempre: aquí se vende, se registra la
                     llegada y se cobra la salida. El tablero solo reparte. -->
                <div class="col-span-12 flex flex-col xl:col-span-4">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-info/10 bg-info/10 text-info',
                                ]"
                            >
                                <Lucide icon="ClipboardList" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Operación del día
                                </div>
                                <div class="text-xs text-slate-500">
                                    Todo junto en una pantalla
                                </div>
                            </div>
                        </div>
                        <div
                            class="flex flex-1 flex-col justify-between gap-3 px-4 py-3"
                        >
                            <p class="text-xs text-slate-500">
                                Llegadas, huéspedes alojados, registrar salida,
                                cobros y llegada exprés, sin cambiar de
                                pantalla.
                            </p>
                            <div
                                class="grid gap-2 sm:grid-cols-2 xl:grid-cols-1"
                            >
                                <Button
                                    :as="Link"
                                    :href="
                                        route('tenant.reservations.operation')
                                    "
                                    variant="outline-primary"
                                    class="h-9 justify-center rounded-[0.5rem] bg-white text-xs"
                                >
                                    <Lucide
                                        icon="ArrowRight"
                                        class="mr-1.5 h-3.5 w-3.5 stroke-[1.5]"
                                    />
                                    Abrir operación
                                </Button>
                                <Button
                                    :as="Link"
                                    :href="
                                        route('tenant.reservations.calendar')
                                    "
                                    variant="outline-secondary"
                                    class="h-9 justify-center rounded-[0.5rem] bg-white text-xs"
                                >
                                    <Lucide
                                        icon="CalendarRange"
                                        class="mr-1.5 h-3.5 w-3.5 stroke-[1.5]"
                                    />
                                    Ver calendario
                                </Button>
                            </div>
                            <div
                                v-if="pending.settlements"
                                class="rounded-[0.6rem] border border-danger/20 bg-danger/5 px-3 py-2.5"
                            >
                                <div
                                    class="flex items-center gap-1.5 text-xs font-medium text-danger"
                                >
                                    <Lucide
                                        icon="ReceiptText"
                                        class="h-3.5 w-3.5"
                                    />
                                    {{ pending.settlements }}
                                    {{
                                        pending.settlements === 1
                                            ? 'cuenta quedó sin cobrar'
                                            : 'cuentas quedaron sin cobrar'
                                    }}
                                </div>
                                <Link
                                    :href="
                                        route('tenant.reservations.settlements')
                                    "
                                    class="mt-1 inline-flex items-center gap-1 text-[11px] font-medium text-danger hover:underline"
                                >
                                    Revisarlas
                                    <Lucide
                                        icon="ChevronRight"
                                        class="h-3 w-3"
                                    />
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lo que entró hoy y ayer. Al tercer día se cae solo: para
                 entonces la reserva ya vive en su área. -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">Movimiento reciente</span>
                <span class="hidden text-[11px] text-slate-400 sm:inline">
                    Lo capturado hoy y ayer, venga de donde venga
                </span>
            </div>
            <div class="box box--stacked mt-2">
                <div :class="cardHeader">
                    <div
                        :class="[
                            sectionIcon,
                            'border-success/10 bg-success/10 text-success',
                        ]"
                    >
                        <Lucide icon="Sparkles" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            Reservaciones nuevas
                        </div>
                        <div class="text-xs text-slate-500">
                            {{ fresh.today }}
                            {{ fresh.today === 1 ? 'entró' : 'entraron' }} hoy
                        </div>
                    </div>
                    <span
                        v-if="fresh.total > fresh.rows.length"
                        class="ml-auto text-[11px] text-slate-400"
                    >
                        Se muestran las {{ fresh.rows.length }} más recientes de
                        {{ fresh.total }}
                    </span>
                </div>

                <template v-if="fresh.rows.length">
                    <!-- Móvil: renglones a ras, no tarjetas dentro de la caja -->
                    <div
                        class="divide-y divide-slate-200/60 sm:hidden dark:divide-darkmode-400"
                    >
                        <div
                            v-for="row in fresh.rows"
                            :key="`card-${row.id}`"
                            class="px-4 py-3"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <Link
                                    :href="
                                        route('tenant.reservations.detail', {
                                            reservation: row.id,
                                        })
                                    "
                                    class="min-w-0 truncate text-sm font-medium hover:text-primary"
                                >
                                    {{ row.guest_name ?? 'Anónimo' }}
                                </Link>
                                <span
                                    class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        row.freshness === 'today'
                                            ? 'bg-success/10 text-success'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                >
                                    {{
                                        row.freshness === 'today'
                                            ? 'Nuevo'
                                            : 'Ayer'
                                    }}
                                </span>
                            </div>
                            <div
                                class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"
                            >
                                <span>{{ row.code }}</span>
                                <span>{{ row.starts_at }}</span>
                                <span class="font-medium text-slate-600">{{
                                    money(row.total_amount)
                                }}</span>
                            </div>
                            <div class="mt-2 flex items-center gap-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        statusTone[row.status] ??
                                        statusTone.pending
                                    "
                                >
                                    {{ row.status_label }}
                                </span>
                                <Link
                                    :href="
                                        route('tenant.reservations.detail', {
                                            reservation: row.id,
                                        })
                                    "
                                    class="ml-auto inline-flex items-center gap-1 text-[11px] font-medium text-primary hover:underline"
                                >
                                    Ver reserva
                                    <Lucide
                                        icon="ChevronRight"
                                        class="h-3 w-3"
                                    />
                                </Link>
                            </div>
                        </div>
                    </div>

                    <!-- Escritorio: tabla -->
                    <div
                        class="hidden overflow-auto sm:block lg:overflow-visible"
                    >
                        <Table hover>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th :class="tableHead"
                                        >Huésped</Table.Th
                                    >
                                    <Table.Th :class="tableHead"
                                        >Habitación</Table.Th
                                    >
                                    <Table.Th
                                        :class="[
                                            tableHead,
                                            'whitespace-nowrap',
                                        ]"
                                        >Llegada</Table.Th
                                    >
                                    <Table.Th
                                        :class="[
                                            tableHead,
                                            'whitespace-nowrap',
                                        ]"
                                        >Se creó</Table.Th
                                    >
                                    <Table.Th :class="[tableHead, 'text-right']"
                                        >Total</Table.Th
                                    >
                                    <Table.Th :class="tableHead"
                                        >Estado</Table.Th
                                    >
                                    <Table.Th :class="[tableHead, 'text-right']"
                                        >Reserva</Table.Th
                                    >
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr
                                    v-for="row in fresh.rows"
                                    :key="row.id"
                                >
                                    <Table.Td>
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                :class="
                                                    row.freshness === 'today'
                                                        ? 'bg-success/10 text-success'
                                                        : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                                "
                                            >
                                                {{
                                                    row.freshness === 'today'
                                                        ? 'Nuevo'
                                                        : 'Ayer'
                                                }}
                                            </span>
                                            <Link
                                                :href="
                                                    route(
                                                        'tenant.reservations.detail',
                                                        { reservation: row.id },
                                                    )
                                                "
                                                class="min-w-0 truncate text-sm font-medium hover:text-primary"
                                            >
                                                {{
                                                    row.guest_name ?? 'Anónimo'
                                                }}
                                            </Link>
                                        </div>
                                        <div
                                            class="mt-0.5 text-xs text-slate-500"
                                        >
                                            {{ row.code }} ·
                                            {{
                                                channelLabel[
                                                    row.source_channel
                                                ] ?? row.source_channel
                                            }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td class="text-xs">
                                        <div class="font-medium">
                                            {{ row.room ?? 'Sin asignar' }}
                                        </div>
                                        <div class="text-slate-500">
                                            {{ row.room_type }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td
                                        class="text-xs whitespace-nowrap tabular-nums"
                                    >
                                        {{ row.starts_at }}
                                        <div class="text-slate-500">
                                            sale {{ row.ends_at }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td
                                        class="text-xs whitespace-nowrap text-slate-500 tabular-nums"
                                    >
                                        {{ row.created_at }}
                                    </Table.Td>
                                    <Table.Td
                                        class="text-right text-xs font-medium whitespace-nowrap tabular-nums"
                                    >
                                        {{ money(row.total_amount) }}
                                    </Table.Td>
                                    <Table.Td>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                            :class="
                                                statusTone[row.status] ??
                                                statusTone.pending
                                            "
                                        >
                                            {{ row.status_label }}
                                        </span>
                                    </Table.Td>
                                    <!-- Abre LA reserva: antes llevaba a la
                                         lista del área (Próximas, Historial) y
                                         había que buscarla otra vez. -->
                                    <Table.Td class="text-right">
                                        <Link
                                            :href="
                                                route(
                                                    'tenant.reservations.detail',
                                                    { reservation: row.id },
                                                )
                                            "
                                            class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                        >
                                            Ver reserva
                                            <Lucide
                                                icon="ChevronRight"
                                                class="h-3.5 w-3.5"
                                            />
                                        </Link>
                                        <Link
                                            :href="route(areaRoutes[row.area])"
                                            class="mt-0.5 block text-[11px] text-slate-400 hover:text-primary"
                                            title="Ir a la lista donde vive esta reserva"
                                        >
                                            en {{ row.area_label }}
                                        </Link>
                                    </Table.Td>
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>
                    </div>
                </template>
                <div
                    v-else
                    class="flex flex-col items-center gap-2 px-5 py-10 text-center"
                >
                    <Lucide icon="Sparkles" class="h-8 w-8 text-slate-300" />
                    <p class="text-sm font-medium text-slate-600">
                        Hoy no ha entrado ninguna reserva
                    </p>
                    <p class="text-xs text-slate-500">
                        Aquí aparecen las que se capturen hoy y ayer, vengan del
                        sitio, del asistente o del mostrador.
                    </p>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
