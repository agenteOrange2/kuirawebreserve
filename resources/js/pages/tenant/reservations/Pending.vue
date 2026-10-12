<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';
import CancelReservationDialog from './board/CancelReservationDialog.vue';
import type { CancelTarget } from './board/CancelReservationDialog.vue';
import { channelLabel } from './board/chartColors';
import ConfirmReservationDialog from './board/ConfirmReservationDialog.vue';
import type { ConfirmTarget } from './board/ConfirmReservationDialog.vue';
import FilterChips from './board/FilterChips.vue';
import { groupByHold, holdUrgency, urgencyBorder } from './board/grouping';
import GroupRow from './board/GroupRow.vue';
import GuestCell from './board/GuestCell.vue';
import KpiStrip from './board/KpiStrip.vue';
import type { KpiItem } from './board/KpiStrip.vue';
import ListPager from './board/ListPager.vue';
import PayMeter from './board/PayMeter.vue';
import ScreenGuide from './board/ScreenGuide.vue';
import { useScreenGuide } from './board/useScreenGuide';
import ReservationsNav from './ReservationsNav.vue';

interface PendingRow {
    /** Reloj del apartado, calculado en el servidor (zona del hotel). */
    hold_state: 'expired' | 'urgent' | 'live' | null;
    hold_countdown: string | null;
    id: number;
    code: string;
    guest_name: string | null;
    guest_phone: string | null;
    room: string | null;
    room_type: string | null;
    starts_at: string;
    ends_at: string;
    /** "Hoy 15:00", "dom 12 oct 15:00", "2 noches". */
    starts_label: string;
    span_label: string | null;
    starts_today: boolean;
    total_amount: string;
    paid_total: number;
    pending_balance: number;
    deposit_amount: string;
    hold_expires_at: string | null;
    payment_due_at: string | null;
    payment_overdue: boolean;
    pending_transfer_request: boolean;
    source_channel: string;
}

interface SettlementRow {
    id: number;
    room: string | null;
    guest_name: string;
    reservation_code: string | null;
    check_out_at: string | null;
    pending: number;
    auto_closed: boolean;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

type HoldState =
    | ''
    | 'vencido'
    | 'por_vencer'
    | 'en_tiempo'
    | 'sin_vencimiento'
    | 'transferencia';

const props = defineProps<{
    property: { id: number; name: string };
    reservations: {
        data: PendingRow[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    settlements: SettlementRow[];
    settlementsTotal: number;
    /** Las cifras de todo lo pendiente, no las de esta página. */
    summary: {
        holds: number;
        expiring: number;
        expired: number;
        transfers: number;
        states: Record<
            'vencido' | 'por_vencer' | 'en_tiempo' | 'sin_vencimiento',
            number
        >;
        balance_label: string;
        settlement_amount_label: string;
    };
    filters: { q: string; estado: HoldState };
    canManage: boolean;
    holdMinutes: number;
}>();

const guide = useScreenGuide('pendientes');

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n || 0);

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const tableHead =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
const ghost =
    'flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition';

const holdLabel = computed(() =>
    props.holdMinutes >= 60 && props.holdMinutes % 60 === 0
        ? `${props.holdMinutes / 60} h`
        : `${props.holdMinutes} min`,
);

// ── Buscador y filtro (en el servidor, con debounce) ──
const q = ref(props.filters.q);
const estado = ref<HoldState>(props.filters.estado);

let timer: ReturnType<typeof setTimeout> | null = null;
watch([q, estado], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            route('tenant.reservations.pending'),
            {
                q: q.value || undefined,
                estado: estado.value || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['reservations', 'filters'],
            },
        );
    }, 300);
});

const filtersActive = computed(() => !!(q.value || estado.value));

function focus(next: HoldState) {
    estado.value = next;
    document
        .getElementById('lista-apartados')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// La franja de cifras: cada una filtra la lista (las gráficas viven en
// /reservas/pendientes/reporte).
const activeKpi = computed(() =>
    estado.value === 'transferencia'
        ? 'transfers'
        : estado.value === 'vencido' || estado.value === 'por_vencer'
          ? 'clock'
          : '',
);
const kpis = computed<KpiItem[]>(() => [
    {
        key: 'holds',
        icon: 'Clock',
        tone: 'pending',
        value: props.summary.holds,
        label: 'por confirmar',
        hint: `Falta cobrar ${props.summary.balance_label}`,
    },
    {
        key: 'clock',
        icon: 'TimerReset',
        tone: 'danger',
        value: props.summary.expiring + props.summary.expired,
        label: 'vencidos o por vencer',
        hint: props.summary.expired
            ? `${props.summary.expired} ya ${props.summary.expired === 1 ? 'venció' : 'vencieron'}`
            : 'Ninguno vencido',
        alert: props.summary.expired > 0,
    },
    {
        key: 'transfers',
        icon: 'Landmark',
        tone: 'pending',
        value: props.summary.transfers,
        label: 'transferencias',
        hint: 'Por verificar en Pagos',
    },
    {
        key: 'settlements',
        icon: 'ReceiptText',
        tone: 'danger',
        value: props.summary.settlement_amount_label,
        label: 'cuentas sin cobrar',
        hint: `${props.settlementsTotal} ${props.settlementsTotal === 1 ? 'estancia cerrada con saldo' : 'estancias cerradas con saldo'}`,
        alert: props.settlementsTotal > 0,
        href: route('tenant.reservations.settlements'),
    },
]);

function onKpi(key: string) {
    if (key === 'holds') focus('');
    else if (key === 'clock')
        focus(props.summary.expired ? 'vencido' : 'por_vencer');
    else if (key === 'transfers') focus('transferencia');
}

// La tabla agrupada por el reloj; la cuenta del grupo es de todos los
// pendientes, no de la página.
const groups = computed(() =>
    groupByHold(props.reservations.data, props.summary.states),
);

/** El nombre abre la ficha: ahí están los pagos y la conversación. */
const openDetail = (r: PendingRow) => router.visit(detailHref(r));

const chips = computed(() => [
    { key: '', label: 'Todos', count: props.summary.holds },
    {
        key: 'vencido',
        label: 'Vencidos',
        count: props.summary.states.vencido,
        tone: 'danger' as const,
    },
    {
        key: 'por_vencer',
        label: 'Por vencer',
        count: props.summary.states.por_vencer,
        tone: 'warning' as const,
    },
    {
        key: 'transferencia',
        label: 'Transferencia por verificar',
        count: props.summary.transfers,
        tone: 'pending' as const,
    },
    {
        key: 'en_tiempo',
        label: 'En tiempo',
        count: props.summary.states.en_tiempo,
    },
    {
        key: 'sin_vencimiento',
        label: 'Sin vencimiento',
        count: props.summary.states.sin_vencimiento,
    },
]);

/** El apartado vencido se pinta rojo; el que está por irse, ámbar. */
const holdTone = (row: PendingRow) =>
    row.hold_state === 'expired'
        ? 'text-danger'
        : row.hold_state === 'urgent'
          ? 'text-warning'
          : 'text-slate-600 dark:text-slate-300';

const detailHref = (r: PendingRow) =>
    route('tenant.reservations.detail', { reservation: r.id });
const payHref = (r: PendingRow) => `${detailHref(r)}?pagar=1`;

// ── Acciones en la misma pantalla: confirmar y liberar ──
const confirmTarget = ref<ConfirmTarget | null>(null);
const cancelTarget = ref<CancelTarget | null>(null);

const guideSteps = computed(() => [
    {
        icon: 'AlarmClock' as Icon,
        title: 'Atiende primero lo rojo',
        text: `La lista va agrupada por el reloj: arriba lo vencido (raya roja) y lo que vence en menos de 30 min (raya ámbar). Sin confirmar se libera solo a ${holdLabel.value}.`,
    },
    {
        icon: 'CircleCheck' as Icon,
        title: 'Confirma o cobra',
        text: 'Confirmar aparta la habitación en firme y le avisa al huésped. Cobrar registra su anticipo o pago.',
    },
    {
        icon: 'Ban' as Icon,
        title: 'Libera lo que no va',
        text: 'Si el huésped ya no viene, libéralo con el ícono rojo: la habitación vuelve a venderse. Las cuentas sin cobrar van abajo y las gráficas en "Ver reporte".',
    },
]);
</script>

<template>
    <RazeLayout title="Pendientes">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                    >
                        <Lucide icon="AlarmClock" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Pendientes</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ property.name }} · apartados que esperan
                            confirmación y cuentas que quedaron sin cobrar
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
                        :href="route('tenant.reservations.pending.report')"
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
                        v-if="canManage && summary.transfers"
                        :as="Link"
                        :href="route('tenant.payments.verify')"
                        variant="outline-pending"
                        class="col-span-2 h-9 rounded-[0.5rem] bg-white text-xs md:col-auto dark:bg-darkmode-600"
                    >
                        <Lucide icon="Landmark" class="mr-1.5 h-3.5 w-3.5" />
                        Verificar transferencias
                        <span
                            class="ml-1.5 rounded-full bg-pending/10 px-1.5 text-[11px]"
                            >{{ summary.transfers }}</span
                        >
                    </Button>
                </div>
            </div>

            <ReservationsNav current="pending" />

            <ScreenGuide
                v-if="guide.open.value"
                purpose="Lo que se pierde solo si nadie lo atiende: apartados con reloj que esperan confirmación, y dinero que quedó sin cobrar al cerrar una estancia."
                :steps="guideSteps"
                @close="guide.close()"
            />

            <KpiStrip :items="kpis" :active="activeKpi" @select="onKpi" />

            <!-- Apartados por confirmar -->
            <div id="lista-apartados" class="box box--stacked mt-4 scroll-mt-4">
                <div :class="cardHeader">
                    <div
                        :class="[
                            sectionIcon,
                            'border-pending/10 bg-pending/10 text-pending',
                        ]"
                    >
                        <Lucide icon="Clock" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            Apartados por confirmar
                        </div>
                        <div class="text-xs text-slate-500">
                            Primero el que está por vencerse
                        </div>
                    </div>
                    <span
                        class="ml-auto hidden text-[11px] text-slate-400 lg:block"
                    >
                        Sin confirmar se liberan solos a {{ holdLabel }}
                    </span>
                </div>

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
                                placeholder="Huésped, teléfono, folio o habitación"
                                class="h-9 pl-9 text-xs"
                            />
                        </div>
                        <button
                            v-if="filtersActive"
                            type="button"
                            class="text-xs font-medium text-primary hover:underline sm:ml-auto"
                            @click="
                                q = '';
                                estado = '';
                            "
                        >
                            Limpiar filtros
                        </button>
                    </div>
                    <FilterChips v-model="estado" :options="chips" />
                </div>

                <!-- Escritorio: tabla agrupada por el reloj del apartado -->
                <div
                    v-if="reservations.data.length"
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
                                <Table.Th :class="tableHead">Llega</Table.Th>
                                <Table.Th :class="tableHead"
                                    >Se libera</Table.Th
                                >
                                <Table.Th :class="[tableHead, 'text-right']"
                                    >Cobro</Table.Th
                                >
                                <Table.Th :class="[tableHead, 'text-right']"
                                    >Acciones</Table.Th
                                >
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <template v-for="group in groups" :key="group.key">
                                <GroupRow
                                    :label="group.label"
                                    :count="group.count"
                                    :tone="group.tone"
                                    :colspan="6"
                                />
                                <Table.Tr
                                    v-for="r in group.rows"
                                    :key="r.id"
                                    class="align-middle"
                                >
                                    <Table.Td
                                        class="max-w-[20rem] py-3"
                                        :class="urgencyBorder(holdUrgency(r))"
                                    >
                                        <GuestCell
                                            :name="r.guest_name"
                                            :meta="[
                                                r.code,
                                                channelLabel(r.source_channel),
                                            ]"
                                            :phone="r.guest_phone"
                                            @open="openDetail(r)"
                                        />
                                        <span
                                            v-if="r.pending_transfer_request"
                                            class="mt-1 ml-11.5 inline-block rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                        >
                                            Transferencia por verificar
                                        </span>
                                    </Table.Td>
                                    <Table.Td class="py-3 whitespace-nowrap">
                                        <div class="text-sm font-medium">
                                            {{ r.room ?? 'Sin asignar' }}
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            {{ r.room_type }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td class="py-3 whitespace-nowrap">
                                        <div
                                            class="text-xs font-medium tabular-nums"
                                        >
                                            {{ r.starts_label }}
                                        </div>
                                        <div
                                            class="mt-0.5 text-xs text-slate-500"
                                        >
                                            {{ r.span_label }}
                                        </div>
                                    </Table.Td>
                                    <!-- El reloj en palabras: "en 18 min" o
                                         "venció 1 h antes". -->
                                    <Table.Td class="py-3 whitespace-nowrap">
                                        <template v-if="r.hold_countdown">
                                            <div
                                                class="text-xs font-medium"
                                                :class="holdTone(r)"
                                            >
                                                {{ r.hold_countdown }}
                                            </div>
                                            <div
                                                class="text-[11px] text-slate-400 tabular-nums"
                                            >
                                                a las {{ r.hold_expires_at }}
                                            </div>
                                        </template>
                                        <span
                                            v-else
                                            class="text-xs text-slate-400"
                                            title="Este apartado no vence solo"
                                            >No se libera solo</span
                                        >
                                    </Table.Td>
                                    <Table.Td class="py-3">
                                        <PayMeter
                                            :total="Number(r.total_amount)"
                                            :paid="r.paid_total"
                                            :balance="r.pending_balance"
                                            :overdue="r.payment_overdue"
                                        />
                                    </Table.Td>
                                    <Table.Td class="py-3">
                                        <div
                                            class="flex items-center justify-end gap-1.5"
                                        >
                                            <template v-if="canManage">
                                                <Button
                                                    variant="primary"
                                                    class="h-8 w-[7.5rem] justify-center rounded-[0.5rem] text-xs whitespace-nowrap"
                                                    @click="confirmTarget = r"
                                                >
                                                    <Lucide
                                                        icon="CircleCheck"
                                                        class="mr-1.5 h-3.5 w-3.5"
                                                    />
                                                    Confirmar
                                                </Button>
                                                <Link
                                                    v-if="r.pending_balance > 0"
                                                    :href="payHref(r)"
                                                    :class="[
                                                        ghost,
                                                        'hover:bg-primary/10 hover:text-primary',
                                                    ]"
                                                    title="Cobrar o registrar su pago"
                                                >
                                                    <Lucide
                                                        icon="Banknote"
                                                        class="h-4 w-4"
                                                    />
                                                </Link>
                                            </template>
                                            <Link
                                                :href="detailHref(r)"
                                                :class="[
                                                    ghost,
                                                    'hover:bg-primary/10 hover:text-primary',
                                                ]"
                                                title="Abrir la ficha de la reserva"
                                            >
                                                <Lucide
                                                    icon="FileText"
                                                    class="h-4 w-4"
                                                />
                                            </Link>
                                            <button
                                                v-if="canManage"
                                                type="button"
                                                :class="[
                                                    ghost,
                                                    'hover:bg-danger/10 hover:text-danger',
                                                ]"
                                                title="Liberar: ya no viene"
                                                @click="cancelTarget = r"
                                            >
                                                <Lucide
                                                    icon="Ban"
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
                <div v-if="reservations.data.length" class="lg:hidden">
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
                                v-for="r in group.rows"
                                :key="`m-${r.id}`"
                                class="px-4 py-3"
                                :class="urgencyBorder(holdUrgency(r))"
                            >
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <GuestCell
                                        class="min-w-0 flex-1"
                                        :name="r.guest_name"
                                        :meta="[
                                            r.code,
                                            r.room ? `Hab. ${r.room}` : null,
                                        ]"
                                        @open="openDetail(r)"
                                    />
                                    <PayMeter
                                        class="!w-24 shrink-0"
                                        :total="Number(r.total_amount)"
                                        :paid="r.paid_total"
                                        :balance="r.pending_balance"
                                        :overdue="r.payment_overdue"
                                    />
                                </div>
                                <div
                                    class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"
                                >
                                    <span
                                        class="font-medium text-slate-700 tabular-nums dark:text-slate-300"
                                    >
                                        Llega {{ r.starts_label }}
                                    </span>
                                    <span
                                        v-if="r.hold_countdown"
                                        class="font-medium"
                                        :class="holdTone(r)"
                                    >
                                        · se libera {{ r.hold_countdown }}
                                    </span>
                                    <span
                                        v-if="r.pending_transfer_request"
                                        class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                        >Transferencia por verificar</span
                                    >
                                </div>
                                <div
                                    v-if="canManage"
                                    class="mt-2.5 grid grid-cols-2 gap-2"
                                >
                                    <Button
                                        variant="primary"
                                        class="h-8 rounded-[0.5rem] text-xs"
                                        @click="confirmTarget = r"
                                    >
                                        <Lucide
                                            icon="CircleCheck"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Confirmar
                                    </Button>
                                    <Button
                                        :as="Link"
                                        :href="
                                            r.pending_balance > 0
                                                ? payHref(r)
                                                : detailHref(r)
                                        "
                                        variant="outline-primary"
                                        class="h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                                    >
                                        <Lucide
                                            :icon="
                                                r.pending_balance > 0
                                                    ? 'Banknote'
                                                    : 'FileText'
                                            "
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        {{
                                            r.pending_balance > 0
                                                ? 'Cobrar'
                                                : 'Ficha'
                                        }}
                                    </Button>
                                    <button
                                        type="button"
                                        class="col-span-2 text-center text-xs font-medium text-danger hover:underline"
                                        @click="cancelTarget = r"
                                    >
                                        Liberar: ya no viene
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <div
                    v-if="!reservations.data.length"
                    class="flex flex-col items-center gap-2 px-5 py-10 text-center"
                >
                    <Lucide
                        :icon="filtersActive ? 'SearchX' : 'CircleCheck'"
                        class="h-8 w-8 text-slate-300"
                    />
                    <p class="text-sm font-medium text-slate-600">
                        {{
                            filtersActive
                                ? 'Nada coincide con ese filtro'
                                : 'Nada por confirmar'
                        }}
                    </p>
                    <p class="text-xs text-slate-500">
                        {{
                            filtersActive
                                ? 'Prueba con el folio o el teléfono, o quita el filtro.'
                                : 'Cuando alguien aparte desde el sitio o el asistente, aparecerá aquí hasta que se confirme.'
                        }}
                    </p>
                </div>

                <ListPager
                    :links="reservations.links"
                    :from="reservations.from"
                    :to="reservations.to"
                    :total="reservations.total"
                />
            </div>

            <!-- Cuentas por cobrar: el cierre automático no cobra -->
            <div class="box box--stacked mt-4">
                <div :class="cardHeader">
                    <div
                        :class="[
                            sectionIcon,
                            'border-danger/10 bg-danger/10 text-danger',
                        ]"
                    >
                        <Lucide icon="ReceiptText" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            Cuentas por cobrar
                        </div>
                        <div class="text-xs text-slate-500">
                            <template
                                v-if="settlementsTotal > settlements.length"
                            >
                                Se asoman {{ settlements.length }} de
                                {{ settlementsTotal }} ·
                                {{ summary.settlement_amount_label }} en total
                            </template>
                            <template v-else-if="settlementsTotal">
                                {{ summary.settlement_amount_label }} en total
                            </template>
                            <template v-else>
                                Estancias que se cerraron con saldo
                            </template>
                        </div>
                    </div>
                    <Button
                        v-if="settlementsTotal"
                        :as="Link"
                        :href="route('tenant.reservations.settlements')"
                        variant="outline-primary"
                        class="ml-auto h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                    >
                        <Lucide
                            icon="ChevronRight"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        Cobrarlas o cerrarlas
                    </Button>
                </div>
                <div
                    v-if="settlements.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="stay in settlements"
                        :key="stay.id"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-3 sm:px-5"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium">
                                {{ stay.guest_name }}
                                <span
                                    v-if="stay.room"
                                    class="text-xs font-normal text-slate-500"
                                >
                                    · habitación {{ stay.room }}
                                </span>
                            </div>
                            <div class="mt-0.5 text-xs text-slate-500">
                                Salió {{ stay.check_out_at ?? 'sin registrar' }}
                                <template v-if="stay.reservation_code">
                                    · {{ stay.reservation_code }}
                                </template>
                            </div>
                        </div>
                        <span
                            v-if="stay.auto_closed"
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                            title="Nadie registró la salida: la cerró el sistema a la hora prevista"
                        >
                            La cerró el reloj
                        </span>
                        <div
                            class="text-sm font-medium whitespace-nowrap text-danger tabular-nums"
                        >
                            {{ money(stay.pending) }}
                        </div>
                        <Link
                            :href="route('tenant.reservations.settlements')"
                            :class="[
                                ghost,
                                'hover:bg-primary/10 hover:text-primary',
                            ]"
                            title="Cobrarla o cerrarla con un motivo"
                        >
                            <Lucide icon="ChevronRight" class="h-4 w-4" />
                        </Link>
                    </div>
                </div>
                <div
                    v-else
                    class="flex flex-col items-center gap-2 px-5 py-10 text-center"
                >
                    <Lucide icon="CircleCheck" class="h-8 w-8 text-slate-300" />
                    <p class="text-sm font-medium text-slate-600">
                        Ninguna cuenta quedó sin cobrar
                    </p>
                    <p class="text-xs text-slate-500">
                        Aquí aparecen las estancias que se cerraron con saldo,
                        casi siempre las que cerró el reloj.
                    </p>
                </div>
            </div>
        </div>

        <ConfirmReservationDialog
            :target="confirmTarget"
            :hold-label="holdLabel"
            @close="confirmTarget = null"
        />
        <CancelReservationDialog
            :target="cancelTarget"
            kind="cancel"
            @close="cancelTarget = null"
        />
    </RazeLayout>
</template>
