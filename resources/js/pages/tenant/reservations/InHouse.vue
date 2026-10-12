<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';
import { channelLabel } from './board/chartColors';
import FilterChips from './board/FilterChips.vue';
import { groupByDeparture, stayUrgency, urgencyBorder } from './board/grouping';
import GroupRow from './board/GroupRow.vue';
import GuestCell from './board/GuestCell.vue';
import KpiStrip from './board/KpiStrip.vue';
import type { KpiItem } from './board/KpiStrip.vue';
import ListPager from './board/ListPager.vue';
import ScreenGuide from './board/ScreenGuide.vue';
import { usePanelCan } from './board/usePanelCan';
import { useScreenGuide } from './board/useScreenGuide';
import ReservationsNav from './ReservationsNav.vue';

interface StayRow {
    id: number;
    room: string | null;
    room_id: number | null;
    reservation_id: number | null;
    guest_name: string | null;
    guest_phone: string | null;
    num_people: number;
    vehicle_plate: string | null;
    vehicle_desc: string | null;
    rate_plan: string | null;
    check_in_at: string;
    planned_end_at: string;
    /** "Ayer 16:37", "Hoy 12:00", "2 noches". */
    check_in_label: string;
    planned_end_label: string;
    span_label: string | null;
    planned_end_at_iso: string;
    overdue: boolean;
    departs_today: boolean;
    departs_tomorrow: boolean;
    balance: number | null;
    amount: string;
    channel: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

type Departure = '' | 'vencida' | 'hoy' | 'manana' | 'despues';

const props = defineProps<{
    property: { id: number; name: string };
    stays: {
        data: StayRow[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    /** El estado de la casa entera, no el de la página que se ve. */
    summary: {
        rooms: number;
        guests: number;
        departures_today: number;
        overdue: number;
        with_balance: number;
        balance: number;
        balance_label: string;
        departures: Record<Exclude<Departure, ''>, number>;
    };
    filters: { q: string; salida: Departure; saldo: boolean };
    canManage: boolean;
}>();

const can = usePanelCan();
const guide = useScreenGuide('alojados');

const money = (value: number | string | null) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value) || 0);

const tableHead =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
const rowAction =
    'flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary';

// ── Buscador y filtros (en el servidor, con debounce) ──
const q = ref(props.filters.q);
const salida = ref<Departure>(props.filters.salida);
const saldo = ref(props.filters.saldo);

let timer: ReturnType<typeof setTimeout> | null = null;
watch([q, salida, saldo], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            route('tenant.reservations.in-house'),
            {
                q: q.value || undefined,
                salida: salida.value || undefined,
                saldo: saldo.value ? 1 : undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['stays', 'filters'],
            },
        );
    }, 300);
});

const filtersActive = computed(
    () => !!(q.value || salida.value || saldo.value),
);

function clearFilters() {
    q.value = '';
    salida.value = '';
    saldo.value = false;
}

/** Las cifras y la dona filtran la lista y bajan a verla. */
function focus(next: Departure, withBalance = false) {
    salida.value = next;
    saldo.value = withBalance;
    document
        .getElementById('lista-en-casa')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// La franja de cifras: cada una filtra la lista (las gráficas viven en
// /reservas/alojados/reporte).
const activeKpi = computed(() =>
    saldo.value
        ? 'balance'
        : salida.value === 'hoy'
          ? 'today'
          : salida.value === 'vencida'
            ? 'overdue'
            : '',
);
const kpis = computed<KpiItem[]>(() => [
    {
        key: 'rooms',
        icon: 'BedDouble',
        tone: 'primary',
        value: props.summary.rooms,
        label:
            props.summary.rooms === 1
                ? 'habitación en uso'
                : 'habitaciones en uso',
        hint: `${props.summary.guests} ${props.summary.guests === 1 ? 'persona dentro' : 'personas dentro'}`,
    },
    {
        key: 'today',
        icon: 'LogOut',
        tone: 'warning',
        value: props.summary.departures_today,
        label: 'salen hoy',
        hint: props.summary.departures.manana
            ? `${props.summary.departures.manana} mañana`
            : 'Nadie mañana',
    },
    {
        key: 'overdue',
        icon: 'TriangleAlert',
        tone: 'danger',
        value: props.summary.overdue,
        label: 'salida vencida',
        hint: props.summary.overdue
            ? 'Ya debían haber salido'
            : 'Nadie pasado de hora',
        alert: props.summary.overdue > 0,
    },
    {
        key: 'balance',
        icon: 'Banknote',
        tone: 'pending',
        value: props.summary.balance_label,
        label: 'por cobrar',
        hint: `${props.summary.with_balance} ${props.summary.with_balance === 1 ? 'estancia con saldo' : 'estancias con saldo'}`,
        alert: props.summary.balance > 0,
    },
]);

function onKpi(key: string) {
    if (key === 'rooms') focus('');
    else if (key === 'today') focus('hoy');
    else if (key === 'overdue') focus('vencida');
    else focus('', true);
}

// La tabla agrupada por cuándo salen; la cuenta de cada grupo es de toda
// la casa, no de la página.
const groups = computed(() =>
    groupByDeparture(props.stays.data, props.summary.departures),
);

const chips = computed(() => [
    { key: '', label: 'Todos', count: props.summary.rooms },
    {
        key: 'vencida',
        label: 'Salida vencida',
        count: props.summary.departures.vencida,
        tone: 'danger' as const,
    },
    {
        key: 'hoy',
        label: 'Salen hoy',
        count: props.summary.departures.hoy,
        tone: 'warning' as const,
    },
    {
        key: 'manana',
        label: 'Mañana',
        count: props.summary.departures.manana,
    },
    {
        key: 'despues',
        label: 'Después',
        count: props.summary.departures.despues,
    },
]);

// La salida se registra en la operación del día: ahí vive el folio con sus
// consumos, el saldo y la fianza. El botón manda allá con la estancia
// enfocada y el modal de salida abierto.
const checkOutHref = (s: StayRow) =>
    `${route('tenant.reservations.operation')}?stay=${s.id}`;

// ── Detalle rápido ──
const detail = ref<StayRow | null>(null);

const guideSteps = [
    {
        icon: 'ListOrdered' as Icon,
        title: 'Lee la lista por salida',
        text: 'Arriba van las salidas vencidas (raya roja), luego las de hoy (raya ámbar), mañana y después.',
    },
    {
        icon: 'Banknote' as Icon,
        title: 'Revisa cuánto debe',
        text: '"Debe" en rojo es hospedaje o consumos cargados a la habitación que faltan por cobrar. "Solo los que deben" deja únicamente esos.',
    },
    {
        icon: 'LogOut' as Icon,
        title: 'Registra la salida',
        text: 'El botón abre la cuenta final: cobras lo que falte, devuelves la fianza y el cuarto queda por limpiar. Las gráficas están en "Ver reporte".',
    },
];
</script>

<template>
    <RazeLayout title="Huéspedes en casa">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="DoorOpen" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Huéspedes en casa</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ property.name }} · quién está dentro, a qué hora
                            sale y cuánto debe
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Link
                        :href="route('tenant.reservations')"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        <span class="sm:hidden">Volver</span>
                        <span class="hidden sm:inline">Volver a reservas</span>
                    </Link>
                    <button
                        type="button"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-info/30 hover:text-info dark:border-darkmode-400 dark:bg-darkmode-600"
                        :class="
                            guide.open.value ? 'border-info/30 text-info' : ''
                        "
                        @click="guide.toggle()"
                    >
                        <Lucide icon="Lightbulb" class="h-3.5 w-3.5" />
                        Cómo se usa
                    </button>
                    <Button
                        :as="Link"
                        :href="route('tenant.reservations.in-house.report')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="ChartColumn"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.3]"
                        />
                        Ver reporte
                    </Button>
                    <Button
                        v-if="can('rooms.view')"
                        :as="Link"
                        :href="route('tenant.plano')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="LayoutGrid"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.3]"
                        />
                        Ver el plano
                    </Button>
                    <Button
                        v-if="canManage"
                        :as="Link"
                        :href="`${route('tenant.reservations.operation')}?intent=walkin`"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs"
                    >
                        <Lucide icon="Zap" class="mr-1.5 h-3.5 w-3.5" />
                        Llegó sin reserva
                    </Button>
                </div>
            </div>

            <ReservationsNav current="in-house" />

            <ScreenGuide
                v-if="guide.open.value"
                purpose="Cada habitación ocupada ahora mismo, agrupada por cuándo sale: primero lo vencido y lo de hoy. De aquí sales a cobrar y a registrar salidas."
                :steps="guideSteps"
                @close="guide.close()"
            />

            <KpiStrip :items="kpis" :active="activeKpi" @select="onKpi" />

            <div id="lista-en-casa" class="box box--stacked mt-4 scroll-mt-4">
                <!-- Filtros, en franja gris dentro del mismo box -->
                <div
                    class="space-y-2.5 border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div
                        class="flex flex-col gap-2.5 sm:flex-row sm:flex-wrap sm:items-center"
                    >
                        <div class="relative w-full min-w-0 sm:w-80">
                            <Lucide
                                icon="Search"
                                class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                            />
                            <FormInput
                                v-model="q"
                                type="search"
                                placeholder="Huésped, habitación o placa"
                                class="h-9 pl-9 text-xs"
                            />
                        </div>
                        <button
                            type="button"
                            class="inline-flex h-9 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition"
                            :class="
                                saldo
                                    ? 'border-pending/30 bg-pending/10 text-pending'
                                    : 'border-slate-200 bg-white text-slate-500 hover:border-pending/30 hover:text-pending dark:border-darkmode-400 dark:bg-darkmode-600'
                            "
                            :aria-pressed="saldo"
                            @click="saldo = !saldo"
                        >
                            <Lucide icon="Banknote" class="h-3.5 w-3.5" />
                            Solo los que deben
                        </button>
                        <button
                            v-if="filtersActive"
                            type="button"
                            class="text-xs font-medium text-primary hover:underline sm:ml-auto"
                            @click="clearFilters"
                        >
                            Limpiar filtros
                        </button>
                    </div>
                    <FilterChips
                        v-model="salida"
                        :options="chips"
                        label="Salida"
                    />
                </div>

                <!-- Escritorio: tabla agrupada por cuándo salen -->
                <div
                    v-if="stays.data.length"
                    class="hidden overflow-auto lg:block lg:overflow-visible"
                >
                    <Table hover>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th :class="[tableHead, 'pl-5']"
                                    >Huésped</Table.Th
                                >
                                <Table.Th :class="tableHead"
                                    >Habitación</Table.Th
                                >
                                <Table.Th :class="tableHead">Estancia</Table.Th>
                                <Table.Th :class="[tableHead, 'text-right']"
                                    >Debe</Table.Th
                                >
                                <Table.Th :class="[tableHead, 'text-right']">
                                    Acciones
                                </Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <template v-for="group in groups" :key="group.key">
                                <GroupRow
                                    :label="group.label"
                                    :count="group.count"
                                    :tone="group.tone"
                                    :colspan="5"
                                />
                                <Table.Tr
                                    v-for="s in group.rows"
                                    :key="s.id"
                                    class="align-middle"
                                >
                                    <Table.Td
                                        class="max-w-[20rem] py-3"
                                        :class="urgencyBorder(stayUrgency(s))"
                                    >
                                        <GuestCell
                                            :name="s.guest_name"
                                            :meta="[
                                                s.reservation_id
                                                    ? channelLabel(s.channel)
                                                    : 'Llegó sin reserva',
                                                s.vehicle_plate,
                                            ]"
                                            :phone="s.guest_phone"
                                            @open="detail = s"
                                        />
                                    </Table.Td>
                                    <Table.Td class="py-3 whitespace-nowrap">
                                        <component
                                            :is="
                                                s.room_id && can('rooms.view')
                                                    ? Link
                                                    : 'div'
                                            "
                                            :href="
                                                s.room_id
                                                    ? route(
                                                          'tenant.rooms.show',
                                                          {
                                                              room: s.room_id,
                                                          },
                                                      )
                                                    : undefined
                                            "
                                            class="text-sm font-medium transition hover:text-primary"
                                        >
                                            {{ s.room ?? 'Sin asignar' }}
                                        </component>
                                        <div class="text-xs text-slate-500">
                                            {{ s.rate_plan }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td class="py-3 whitespace-nowrap">
                                        <div
                                            class="text-xs font-medium tabular-nums"
                                        >
                                            {{ s.check_in_label }}
                                            <span
                                                class="font-normal text-slate-400"
                                                >→</span
                                            >
                                            <span
                                                :class="
                                                    s.overdue
                                                        ? 'text-danger'
                                                        : ''
                                                "
                                                >{{ s.planned_end_label }}</span
                                            >
                                        </div>
                                        <div
                                            class="mt-0.5 text-xs text-slate-500"
                                        >
                                            {{ s.span_label }} ·
                                            {{ s.num_people }}
                                            {{
                                                s.num_people === 1
                                                    ? 'persona'
                                                    : 'personas'
                                            }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td
                                        class="py-3 text-right whitespace-nowrap"
                                    >
                                        <div
                                            class="text-sm font-medium tabular-nums"
                                            :class="
                                                (s.balance ?? 0) > 0
                                                    ? 'text-danger'
                                                    : 'text-success'
                                            "
                                        >
                                            {{
                                                (s.balance ?? 0) > 0
                                                    ? money(s.balance)
                                                    : 'Al corriente'
                                            }}
                                        </div>
                                        <div
                                            class="text-[11px] text-slate-400 tabular-nums"
                                        >
                                            estancia {{ money(s.amount) }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td class="py-3">
                                        <div
                                            class="flex items-center justify-end gap-1.5"
                                        >
                                            <Button
                                                v-if="canManage"
                                                :as="Link"
                                                :href="checkOutHref(s)"
                                                :variant="
                                                    s.overdue || s.departs_today
                                                        ? 'primary'
                                                        : 'outline-primary'
                                                "
                                                class="h-8 w-[9.5rem] justify-center rounded-[0.5rem] text-xs whitespace-nowrap"
                                                :class="
                                                    s.overdue || s.departs_today
                                                        ? ''
                                                        : 'bg-white dark:bg-darkmode-600'
                                                "
                                            >
                                                <Lucide
                                                    icon="LogOut"
                                                    class="mr-1.5 h-3.5 w-3.5"
                                                />
                                                Registrar salida
                                            </Button>
                                            <button
                                                type="button"
                                                :class="rowAction"
                                                title="Ver el resumen de la estancia"
                                                @click="detail = s"
                                            >
                                                <Lucide
                                                    icon="Eye"
                                                    class="h-4 w-4"
                                                />
                                            </button>
                                        </div>
                                    </Table.Td>
                                </Table.Tr>
                            </template>
                        </Table.Tbody>
                    </Table>
                </div>

                <!-- Móvil y tablet: los mismos grupos, apilados -->
                <div v-if="stays.data.length" class="lg:hidden">
                    <template v-for="group in groups" :key="`m-${group.key}`">
                        <GroupRow
                            as="div"
                            :label="group.label"
                            :count="group.count"
                            :tone="group.tone"
                        />
                        <div
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="s in group.rows"
                                :key="`m-${s.id}`"
                                class="px-4 py-3"
                                :class="urgencyBorder(stayUrgency(s))"
                            >
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <GuestCell
                                        class="min-w-0 flex-1"
                                        :name="s.guest_name"
                                        :meta="[
                                            s.room ? `Hab. ${s.room}` : null,
                                            s.vehicle_plate,
                                        ]"
                                        @open="detail = s"
                                    />
                                    <div class="shrink-0 text-right">
                                        <div
                                            class="text-sm font-medium tabular-nums"
                                            :class="
                                                (s.balance ?? 0) > 0
                                                    ? 'text-danger'
                                                    : 'text-success'
                                            "
                                        >
                                            {{
                                                (s.balance ?? 0) > 0
                                                    ? money(s.balance)
                                                    : 'Al corriente'
                                            }}
                                        </div>
                                        <div
                                            v-if="(s.balance ?? 0) > 0"
                                            class="text-[11px] text-slate-400"
                                        >
                                            debe
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-2 text-xs text-slate-500">
                                    <span
                                        class="font-medium text-slate-700 tabular-nums dark:text-slate-300"
                                    >
                                        {{ s.check_in_label }} →
                                        <span
                                            :class="
                                                s.overdue ? 'text-danger' : ''
                                            "
                                            >{{ s.planned_end_label }}</span
                                        >
                                    </span>
                                    · {{ s.span_label }} · {{ s.num_people }}
                                    {{
                                        s.num_people === 1
                                            ? 'persona'
                                            : 'personas'
                                    }}
                                </div>
                                <div
                                    class="mt-2.5 flex items-center justify-end gap-1.5"
                                >
                                    <button
                                        type="button"
                                        :class="rowAction"
                                        title="Ver el resumen de la estancia"
                                        @click="detail = s"
                                    >
                                        <Lucide icon="Eye" class="h-4 w-4" />
                                    </button>
                                    <Button
                                        v-if="canManage"
                                        :as="Link"
                                        :href="checkOutHref(s)"
                                        variant="outline-primary"
                                        class="h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                                    >
                                        <Lucide
                                            icon="LogOut"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Registrar salida
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- El vacío vive fuera del bloque de escritorio para que
                     también se vea en el celular. -->
                <div
                    v-if="!stays.data.length"
                    class="flex flex-col items-center gap-2 px-5 py-10 text-center"
                >
                    <Lucide
                        :icon="filtersActive ? 'SearchX' : 'DoorOpen'"
                        class="h-8 w-8 text-slate-300"
                    />
                    <p class="text-sm font-medium text-slate-600">
                        {{
                            filtersActive
                                ? 'Nadie coincide con ese filtro'
                                : 'Ninguna habitación en uso ahora mismo'
                        }}
                    </p>
                    <p class="text-xs text-slate-500">
                        {{
                            filtersActive
                                ? 'Prueba con el número de habitación o quita el filtro.'
                                : 'Cuando registres una llegada, el huésped aparece aquí hasta que se le dé la salida.'
                        }}
                    </p>
                    <button
                        v-if="filtersActive"
                        type="button"
                        class="mt-1 text-xs font-medium text-primary hover:underline"
                        @click="clearFilters"
                    >
                        Ver a todos
                    </button>
                </div>

                <ListPager
                    :links="stays.links"
                    :from="stays.from"
                    :to="stays.to"
                    :total="stays.total"
                />
            </div>
        </div>

        <!-- Resumen de la estancia, sin salir de la lista -->
        <Dialog :open="detail !== null" size="lg" @close="detail = null">
            <Dialog.Panel class="sm:w-[94vw] lg:w-[560px]">
                <div
                    v-if="detail"
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                >
                    <div
                        class="flex items-start gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="BedDouble" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                {{ detail.guest_name ?? 'Anónimo' }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Habitación {{ detail.room ?? 'sin asignar' }} ·
                                {{ detail.rate_plan }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-darkmode-400"
                            title="Cerrar"
                            @click="detail = null"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>
                    <div
                        class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4"
                    >
                        <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-xs">
                            <div>
                                <div class="text-[11px] text-slate-400">
                                    Entró
                                </div>
                                <div class="font-medium tabular-nums">
                                    {{ detail.check_in_at }}
                                </div>
                            </div>
                            <div>
                                <div class="text-[11px] text-slate-400">
                                    Sale
                                </div>
                                <div
                                    class="font-medium tabular-nums"
                                    :class="detail.overdue ? 'text-danger' : ''"
                                >
                                    {{ detail.planned_end_at }}
                                </div>
                            </div>
                            <div>
                                <div class="text-[11px] text-slate-400">
                                    Personas
                                </div>
                                <div class="font-medium">
                                    {{ detail.num_people }}
                                </div>
                            </div>
                            <div>
                                <div class="text-[11px] text-slate-400">
                                    Cómo llegó
                                </div>
                                <div class="font-medium">
                                    {{
                                        detail.reservation_id
                                            ? channelLabel(detail.channel)
                                            : 'Sin reserva'
                                    }}
                                </div>
                            </div>
                            <div v-if="detail.guest_phone">
                                <div class="text-[11px] text-slate-400">
                                    Teléfono
                                </div>
                                <a
                                    :href="`tel:${detail.guest_phone}`"
                                    class="font-medium text-primary hover:underline"
                                    >{{ detail.guest_phone }}</a
                                >
                            </div>
                            <div v-if="detail.vehicle_plate">
                                <div class="text-[11px] text-slate-400">
                                    Vehículo
                                </div>
                                <div class="font-medium">
                                    {{ detail.vehicle_plate }}
                                    <span
                                        v-if="detail.vehicle_desc"
                                        class="font-normal text-slate-500"
                                        >· {{ detail.vehicle_desc }}</span
                                    >
                                </div>
                            </div>
                        </div>
                        <div
                            class="flex items-center justify-between gap-3 rounded-lg border px-3.5 py-2.5 text-xs"
                            :class="
                                (detail.balance ?? 0) > 0
                                    ? 'border-danger/30 bg-danger/5'
                                    : 'border-success/30 bg-success/5'
                            "
                        >
                            <span class="text-slate-600 dark:text-slate-300">
                                {{
                                    (detail.balance ?? 0) > 0
                                        ? 'Debe (hospedaje y consumos a la habitación)'
                                        : 'Va al corriente'
                                }}
                            </span>
                            <span
                                class="text-sm font-medium tabular-nums"
                                :class="
                                    (detail.balance ?? 0) > 0
                                        ? 'text-danger'
                                        : 'text-success'
                                "
                                >{{ money(detail.balance) }}</span
                            >
                        </div>
                    </div>
                    <div
                        class="flex flex-col-reverse gap-2 border-t border-slate-200/70 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-end dark:border-darkmode-400"
                    >
                        <Button
                            v-if="detail.reservation_id"
                            :as="Link"
                            :href="`/reservas/${detail.reservation_id}`"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-4 text-xs"
                        >
                            <Lucide
                                icon="FileText"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Ficha de la reserva
                        </Button>
                        <Button
                            v-if="detail.room_id && can('rooms.view')"
                            :as="Link"
                            :href="
                                route('tenant.rooms.show', {
                                    room: detail.room_id,
                                })
                            "
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-4 text-xs"
                        >
                            <Lucide
                                icon="BedDouble"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Ver la habitación
                        </Button>
                        <Button
                            v-if="canManage"
                            :as="Link"
                            :href="checkOutHref(detail)"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-4 text-xs"
                        >
                            <Lucide icon="LogOut" class="mr-1.5 h-3.5 w-3.5" />
                            Registrar salida
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
