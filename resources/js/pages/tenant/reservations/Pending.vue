<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';
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
    starts_today: boolean;
    total_amount: string;
    paid_total: number;
    pending_balance: number;
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

defineProps<{
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
        balance_label: string;
        settlement_amount_label: string;
    };
    canManage: boolean;
    holdMinutes: number;
}>();

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n || 0);

const channelLabel: Record<string, string> = {
    web: 'Sitio web',
    agent: 'Asistente',
    counter: 'Mostrador',
    front_desk: 'Mostrador',
    walk_in: 'Sin reserva',
    phone: 'Teléfono',
    whatsapp: 'WhatsApp',
};

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const tableHead =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
const sectionLabel = tableHead;

/** El apartado vencido se pinta rojo; el que está por irse, ámbar. */
const holdTone = (row: PendingRow) =>
    row.hold_state === 'expired'
        ? 'text-danger'
        : row.hold_state === 'urgent'
          ? 'text-warning'
          : 'text-slate-600 dark:text-slate-300';
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
                            {{ property.name }} · lo que falta confirmar y lo
                            que quedó sin cobrar
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        :href="route('tenant.reservations')"
                        class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Volver a reservas
                    </Link>
                </div>
            </div>

            <ReservationsNav current="pending" />

            <!-- Las cifras: cuánto se puede perder y cuánto falta cobrar -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">Lo que está en juego</span>
                <span class="hidden text-[11px] text-slate-400 sm:inline">
                    Cuenta todo lo pendiente, no solo esta página
                </span>
            </div>
            <div class="mt-2 grid auto-rows-fr grid-cols-12 gap-4">
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
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
                            {{ summary.holds }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Apartados por confirmar
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            Esperan anticipo o confirmación
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        :class="[
                            sectionIcon,
                            summary.expiring || summary.expired
                                ? 'border-warning/10 bg-warning/10 text-warning'
                                : 'border-slate-200 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400',
                        ]"
                    >
                        <Lucide icon="TimerReset" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ summary.expiring }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Vencen en menos de 30 min
                        </div>
                        <div
                            class="truncate text-[11px]"
                            :class="
                                summary.expired
                                    ? 'text-danger'
                                    : 'text-slate-400'
                            "
                        >
                            <template v-if="summary.expired">
                                {{ summary.expired }}
                                {{
                                    summary.expired === 1
                                        ? 'ya venció'
                                        : 'ya vencieron'
                                }}
                            </template>
                            <template v-else>Ninguno vencido</template>
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        :class="[
                            sectionIcon,
                            'border-info/10 bg-info/10 text-info',
                        ]"
                    >
                        <Lucide icon="Banknote" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium tabular-nums">
                            {{ summary.balance_label }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Apartado sin pagar
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            Lo que falta de estas reservas
                        </div>
                    </div>
                </div>
                <Link
                    :href="route('tenant.reservations.settlements')"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 transition hover:border-primary/30 xl:col-span-3"
                >
                    <div
                        :class="[
                            sectionIcon,
                            settlementsTotal
                                ? 'border-danger/10 bg-danger/10 text-danger'
                                : 'border-slate-200 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400',
                        ]"
                    >
                        <Lucide icon="ReceiptText" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium tabular-nums">
                            {{ summary.settlement_amount_label }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Cuentas sin cobrar
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            {{ settlementsTotal }}
                            {{
                                settlementsTotal === 1
                                    ? 'estancia cerrada con saldo'
                                    : 'estancias cerradas con saldo'
                            }}
                        </div>
                    </div>
                </Link>
            </div>

            <!-- Apartados por confirmar -->
            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
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
                        Un apartado sin confirmar se libera solo a los
                        {{ holdMinutes }} minutos
                    </span>
                </div>

                <!-- Escritorio: tabla -->
                <div
                    v-if="reservations.data.length"
                    class="hidden overflow-auto lg:block lg:overflow-visible"
                >
                    <Table hover>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th :class="tableHead">Huésped</Table.Th>
                                <Table.Th :class="tableHead"
                                    >Habitación</Table.Th
                                >
                                <Table.Th :class="tableHead">Llegada</Table.Th>
                                <Table.Th :class="tableHead">Vence</Table.Th>
                                <Table.Th :class="[tableHead, 'text-right']"
                                    >Saldo</Table.Th
                                >
                                <Table.Th :class="[tableHead, 'text-right']"
                                    >Acciones</Table.Th
                                >
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr
                                v-for="r in reservations.data"
                                :key="r.id"
                                class="align-top"
                            >
                                <Table.Td class="max-w-[22rem]">
                                    <Link
                                        :href="
                                            route(
                                                'tenant.reservations.detail',
                                                {
                                                    reservation: r.id,
                                                },
                                            )
                                        "
                                        class="block truncate text-sm font-medium transition hover:text-primary"
                                    >
                                        {{ r.guest_name ?? 'Anónimo' }}
                                    </Link>
                                    <div
                                        class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"
                                    >
                                        <span
                                            class="font-medium text-slate-600 dark:text-slate-300"
                                            >{{ r.code }}</span
                                        >
                                        <span
                                            class="text-slate-300 dark:text-darkmode-400"
                                            >·</span
                                        >
                                        <span>{{
                                            channelLabel[r.source_channel] ??
                                            r.source_channel
                                        }}</span>
                                        <template v-if="r.guest_phone">
                                            <span
                                                class="text-slate-300 dark:text-darkmode-400"
                                                >·</span
                                            >
                                            <a
                                                :href="`tel:${r.guest_phone}`"
                                                class="transition hover:text-primary"
                                                >{{ r.guest_phone }}</a
                                            >
                                        </template>
                                    </div>
                                    <div
                                        v-if="
                                            r.pending_transfer_request ||
                                            r.payment_overdue
                                        "
                                        class="mt-1"
                                    >
                                        <span
                                            v-if="r.pending_transfer_request"
                                            class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                        >
                                            Transferencia por verificar
                                        </span>
                                        <span
                                            v-else
                                            class="rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                        >
                                            Anticipo vencido
                                        </span>
                                    </div>
                                </Table.Td>
                                <Table.Td class="whitespace-nowrap">
                                    <div class="text-sm font-medium">
                                        {{ r.room ?? 'Sin asignar' }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ r.room_type }}
                                    </div>
                                </Table.Td>
                                <Table.Td class="whitespace-nowrap">
                                    <div class="text-xs tabular-nums">
                                        {{ r.starts_at }}
                                    </div>
                                    <span
                                        v-if="r.starts_today"
                                        class="mt-1 inline-block rounded-full bg-info/10 px-2 py-0.5 text-[11px] font-medium text-info"
                                    >
                                        Llega hoy
                                    </span>
                                </Table.Td>
                                <!-- El reloj en palabras: "en 18 min" o
                                     "venció 1 h antes". La hora sola no
                                     decía si ya se había pasado. -->
                                <Table.Td class="whitespace-nowrap">
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
                                            {{ r.hold_expires_at }}
                                        </div>
                                    </template>
                                    <span
                                        v-else
                                        class="text-xs text-slate-400"
                                        title="Este apartado no vence solo"
                                        >Sin vencimiento</span
                                    >
                                </Table.Td>
                                <Table.Td class="text-right whitespace-nowrap">
                                    <div
                                        class="text-sm font-medium tabular-nums"
                                    >
                                        {{ money(r.pending_balance) }}
                                    </div>
                                    <div
                                        v-if="r.paid_total > 0"
                                        class="text-[11px] text-slate-400 tabular-nums"
                                    >
                                        de
                                        {{ money(Number(r.total_amount)) }}
                                    </div>
                                </Table.Td>
                                <Table.Td>
                                    <div class="flex justify-end">
                                        <Button
                                            :as="Link"
                                            :href="
                                                route(
                                                    'tenant.reservations.detail',
                                                    { reservation: r.id },
                                                )
                                            "
                                            variant="outline-primary"
                                            class="h-8 rounded-[0.5rem] bg-white text-xs whitespace-nowrap"
                                        >
                                            <Lucide
                                                icon="CircleCheck"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            Confirmar o cobrar
                                        </Button>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </div>

                <!-- Móvil y tablet: los mismos datos apilados -->
                <div
                    v-if="reservations.data.length"
                    class="divide-y divide-slate-200/60 lg:hidden dark:divide-darkmode-400"
                >
                    <div
                        v-for="r in reservations.data"
                        :key="`m-${r.id}`"
                        class="px-4 py-3.5"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <span
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                    >
                                        {{ r.code }}
                                    </span>
                                    <span
                                        v-if="r.starts_today"
                                        class="rounded-full bg-info/10 px-2 py-0.5 text-[11px] font-medium text-info"
                                    >
                                        Llega hoy
                                    </span>
                                </div>
                                <Link
                                    :href="
                                        route('tenant.reservations.detail', {
                                            reservation: r.id,
                                        })
                                    "
                                    class="mt-1.5 block truncate text-sm font-medium transition hover:text-primary"
                                >
                                    {{ r.guest_name ?? 'Anónimo' }}
                                </Link>
                                <div class="text-xs text-slate-500">
                                    {{
                                        channelLabel[r.source_channel] ??
                                        r.source_channel
                                    }}
                                    <template v-if="r.room">
                                        · habitación {{ r.room }}
                                    </template>
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="text-sm font-medium tabular-nums">
                                    {{ money(r.pending_balance) }}
                                </div>
                                <div class="text-[11px] text-slate-400">
                                    por cobrar
                                </div>
                            </div>
                        </div>

                        <div
                            class="mt-2 flex flex-col gap-1 text-xs text-slate-500 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-3"
                        >
                            <span
                                class="inline-flex items-center gap-1.5 tabular-nums"
                            >
                                <Lucide
                                    icon="CalendarDays"
                                    class="h-3.5 w-3.5 shrink-0 stroke-[1.3]"
                                />
                                llega {{ r.starts_at }}
                            </span>
                            <span
                                v-if="r.hold_countdown"
                                class="inline-flex items-center gap-1.5 font-medium"
                                :class="holdTone(r)"
                            >
                                <Lucide
                                    icon="Clock"
                                    class="h-3.5 w-3.5 shrink-0 stroke-[1.3]"
                                />
                                vence {{ r.hold_countdown }}
                            </span>
                        </div>

                        <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                            <span
                                v-if="r.pending_transfer_request"
                                class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                >Transferencia por verificar</span
                            >
                            <span
                                v-else-if="r.payment_overdue"
                                class="rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                >Anticipo vencido</span
                            >
                            <Button
                                :as="Link"
                                :href="
                                    route('tenant.reservations.detail', {
                                        reservation: r.id,
                                    })
                                "
                                variant="outline-primary"
                                class="ml-auto h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                            >
                                <Lucide
                                    icon="CircleCheck"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Confirmar o cobrar
                            </Button>
                        </div>
                    </div>
                </div>

                <div
                    v-if="!reservations.data.length"
                    class="flex flex-col items-center gap-2 px-5 py-10 text-center"
                >
                    <Lucide icon="CircleCheck" class="h-8 w-8 text-slate-300" />
                    <p class="text-sm font-medium text-slate-600">
                        Nada por confirmar
                    </p>
                    <p class="text-xs text-slate-500">
                        Cuando alguien aparte desde el sitio o el asistente,
                        aparecerá aquí hasta que se confirme.
                    </p>
                </div>

                <div
                    v-if="reservations.links.length > 3"
                    class="flex flex-wrap items-center gap-2 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <span class="text-xs text-slate-500">
                        {{ reservations.from }}–{{ reservations.to }} de
                        {{ reservations.total }}
                    </span>
                    <div class="ml-auto flex flex-wrap gap-1">
                        <component
                            :is="link.url ? Link : 'span'"
                            v-for="(link, i) in reservations.links"
                            :key="i"
                            :href="link.url ?? undefined"
                            class="rounded-md px-2.5 py-1 text-xs"
                            :class="
                                link.active
                                    ? 'bg-primary text-white'
                                    : link.url
                                      ? 'text-slate-500 hover:bg-slate-100 dark:hover:bg-darkmode-400'
                                      : 'text-slate-300'
                            "
                        >
                            <!-- El rótulo trae las flechas « » de Laravel. -->
                            <span v-html="link.label" />
                        </component>
                    </div>
                </div>
            </div>

            <!-- Cuentas por cobrar: el cierre automático no cobra -->
            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
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
                        class="ml-auto h-8 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="ChevronRight"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        Trabajarlas
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
                                'flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition',
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
    </RazeLayout>
</template>
