<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface PendingRow {
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
    walk_in: 'Sin reserva',
    phone: 'Teléfono',
};
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

            <!-- Apartados por confirmar -->
            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-2 border-b border-slate-200/60 px-4 py-3 text-sm font-medium dark:border-darkmode-400"
                >
                    <Lucide icon="Clock" class="h-4 w-4 text-slate-400" />
                    Apartados por confirmar
                    <span
                        class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-darkmode-400"
                    >
                        {{ reservations.total }}
                    </span>
                    <span class="ml-auto text-[11px] text-slate-500">
                        Un apartado sin confirmar se libera solo a los
                        {{ holdMinutes }} minutos.
                    </span>
                </div>

                <!-- Móvil: tarjetas apiladas. Una tabla de seis columnas en
                     un celular se arrastra de lado y no se lee. -->
                <div
                    v-if="reservations.data.length"
                    class="space-y-2 p-4 sm:hidden"
                >
                    <div
                        v-for="r in reservations.data"
                        :key="`card-${r.id}`"
                        class="rounded-lg border border-slate-200/70 bg-white p-3 dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium">
                                    {{ r.guest_name ?? 'Anónimo' }}
                                </div>
                                <div class="mt-0.5 text-xs text-slate-500">
                                    {{ r.code }} ·
                                    {{
                                        channelLabel[r.source_channel] ??
                                        r.source_channel
                                    }}
                                </div>
                            </div>
                            <span
                                v-if="r.hold_expires_at"
                                class="shrink-0 rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                            >
                                Vence {{ r.hold_expires_at }}
                            </span>
                        </div>

                        <dl
                            class="mt-2.5 grid grid-cols-2 gap-x-3 gap-y-2 text-xs"
                        >
                            <div>
                                <dt class="text-[11px] text-slate-500">
                                    Habitación
                                </dt>
                                <dd class="mt-0.5 font-medium">
                                    {{ r.room ?? 'Sin asignar' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[11px] text-slate-500">
                                    Llegada
                                </dt>
                                <dd class="mt-0.5 font-medium">
                                    {{ r.starts_at }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[11px] text-slate-500">
                                    Saldo
                                </dt>
                                <dd class="mt-0.5 font-medium">
                                    {{ money(r.pending_balance) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[11px] text-slate-500">
                                    Total
                                </dt>
                                <dd class="mt-0.5 font-medium">
                                    {{ money(Number(r.total_amount)) }}
                                </dd>
                            </div>
                        </dl>

                        <div
                            class="mt-2.5 flex flex-wrap items-center gap-2 border-t border-dashed border-slate-200/70 pt-2.5 dark:border-darkmode-400"
                        >
                            <span
                                v-if="r.starts_today"
                                class="rounded-full bg-info/10 px-2 py-0.5 text-[11px] font-medium text-info"
                                >Llega hoy</span
                            >
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
                            <Link
                                :href="
                                    route('tenant.reservations.detail', {
                                        reservation: r.id,
                                    })
                                "
                                class="ml-auto inline-flex h-8 items-center rounded-[0.5rem] border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 dark:border-darkmode-400 dark:bg-darkmode-600"
                            >
                                Abrir ficha
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Escritorio: tabla -->
                <div
                    v-if="reservations.data.length"
                    class="hidden overflow-auto p-4 sm:block lg:overflow-visible"
                >
                    <Table>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Huésped</Table.Th>
                                <Table.Th>Habitación</Table.Th>
                                <Table.Th class="whitespace-nowrap"
                                    >Llegada</Table.Th
                                >
                                <Table.Th class="whitespace-nowrap"
                                    >Vence</Table.Th
                                >
                                <Table.Th class="text-right">Saldo</Table.Th>
                                <Table.Th class="text-right">Acciones</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr
                                v-for="r in reservations.data"
                                :key="r.id"
                            >
                                <Table.Td>
                                    <span
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                    >
                                        {{ r.code }}
                                    </span>
                                    <div class="mt-1 text-sm font-medium">
                                        {{ r.guest_name ?? 'Anónimo' }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{
                                            channelLabel[r.source_channel] ??
                                            r.source_channel
                                        }}
                                        <template v-if="r.guest_phone">
                                            · {{ r.guest_phone }}
                                        </template>
                                    </div>
                                </Table.Td>
                                <Table.Td class="text-xs">
                                    <div class="font-medium">
                                        {{ r.room ?? 'Sin asignar' }}
                                    </div>
                                    <div class="text-slate-500">
                                        {{ r.room_type }}
                                    </div>
                                </Table.Td>
                                <Table.Td class="text-xs whitespace-nowrap">
                                    {{ r.starts_at }}
                                    <span
                                        v-if="r.starts_today"
                                        class="mt-1 block text-[11px] font-medium text-info"
                                        >Llega hoy</span
                                    >
                                </Table.Td>
                                <Table.Td class="text-xs whitespace-nowrap">
                                    <span
                                        v-if="r.hold_expires_at"
                                        class="font-medium text-warning"
                                    >
                                        {{ r.hold_expires_at }}
                                    </span>
                                    <span v-else class="text-slate-400">—</span>
                                    <span
                                        v-if="r.pending_transfer_request"
                                        class="mt-1 block text-[11px] text-pending"
                                        >Transferencia por verificar</span
                                    >
                                    <span
                                        v-else-if="r.payment_overdue"
                                        class="mt-1 block text-[11px] text-danger"
                                        >Anticipo vencido</span
                                    >
                                </Table.Td>
                                <Table.Td
                                    class="text-right text-xs whitespace-nowrap"
                                >
                                    <div class="font-medium">
                                        {{ money(r.pending_balance) }}
                                    </div>
                                    <div class="text-slate-500">
                                        de {{ money(Number(r.total_amount)) }}
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
                                            variant="outline-secondary"
                                            class="h-8 rounded-[0.5rem] bg-white text-xs"
                                        >
                                            Abrir ficha
                                        </Button>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </div>
                <div
                    v-else
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
                            v-html="link.label"
                        />
                    </div>
                </div>
            </div>

            <!-- Cuentas por cobrar: el cierre automático no cobra -->
            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-2 border-b border-slate-200/60 px-4 py-3 text-sm font-medium dark:border-darkmode-400"
                >
                    <Lucide icon="ReceiptText" class="h-4 w-4 text-slate-400" />
                    Cuentas por cobrar
                    <span
                        class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-darkmode-400"
                    >
                        <template v-if="settlementsTotal > settlements.length">
                            {{ settlements.length }} de {{ settlementsTotal }}
                        </template>
                        <template v-else>{{ settlementsTotal }}</template>
                    </span>
                    <Link
                        v-if="settlementsTotal"
                        :href="route('tenant.reservations.settlements')"
                        class="ml-auto text-xs font-medium text-primary hover:underline"
                    >
                        Trabajarlas
                    </Link>
                </div>

                <div
                    v-if="settlements.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="stay in settlements"
                        :key="stay.id"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 sm:px-5"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium">
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
                            class="text-sm font-medium whitespace-nowrap text-danger"
                        >
                            {{ money(stay.pending) }}
                        </div>
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
