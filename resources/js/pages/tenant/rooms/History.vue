<script setup lang="ts">
import { computed } from 'vue';
import Button from '@/components/Base/Button';
import Chart from '@/components/Base/Chart';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface UsageStat {
    key: string;
    label: string;
    count: number;
    revenue: number;
}
interface MonthlyPoint {
    label: string;
    count: number;
    revenue: number;
}
interface StayRow {
    id: number;
    guest_name: string;
    check_in_at: string | null;
    check_out_at: string | null;
    active: boolean;
    amount: number;
    channel: string | null;
    nights: number | null;
}
interface UpcomingReservationRow {
    id: number;
    code: string;
    guest_name: string;
    rate_plan: string | null;
    status: string;
    status_label: string;
    payment_status_label: string | null;
    starts_at: string;
    ends_at: string;
    starts_today: boolean;
    total_amount: number;
}
interface StatusRow {
    id: number;
    from: string | null;
    to: string;
    to_color: string;
    by: string;
    auto: boolean;
    day: string;
    date: string;
    time: string;
}
interface CleaningCycle {
    id: number;
    day: string;
    started_at: string;
    by: string;
    auto: boolean;
    dirty_minutes: number | null;
    duration_minutes: number | null;
    ongoing: boolean;
    ended_status: string | null;
}
interface MaintenancePeriod {
    id: number;
    started_at: string;
    ended_at: string | null;
    by: string;
    duration_minutes: number;
    ongoing: boolean;
}

const props = defineProps<{
    room: {
        id: number;
        number: string;
        name: string | null;
        room_type: string;
        zone: string | null;
        status_label: string;
        status_color: string;
    };
    usage: UsageStat[];
    monthly: MonthlyPoint[];
    upcomingReservations: UpcomingReservationRow[];
    recentStays: StayRow[];
    statusHistory: StatusRow[];
    cleaningCycles: CleaningCycle[];
    cleaningStats: {
        last30: number;
        avg_duration: number | null;
        avg_dirty_wait: number | null;
    };
    maintenancePeriods: MaintenancePeriod[];
    totals: { stays: number; revenue: number };
}>();

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 }).format(n ?? 0);

// Minutos legibles: "45 min", "2 h 10 min", "3 d 4 h".
const fmtDuration = (minutes: number | null) => {
    if (minutes === null) return '—';
    if (minutes < 1) return 'menos de 1 min';
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    if (hours < 24) return rest ? `${hours} h ${rest} min` : `${hours} h`;
    const days = Math.floor(hours / 24);
    const restHours = hours % 24;
    return restHours ? `${days} d ${restHours} h` : `${days} d`;
};

// Línea de tiempo del semáforo agrupada por día: el seguimiento se lee
// como jornada, no como lista plana de fechas repetidas.
const statusByDay = computed(() => {
    const groups: { date: string; day: string; items: StatusRow[] }[] = [];
    props.statusHistory.forEach((log) => {
        const last = groups[groups.length - 1];
        if (last && last.date === log.date) {
            last.items.push(log);
        } else {
            groups.push({ date: log.date, day: log.day, items: [log] });
        }
    });
    return groups;
});

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
const channelLabels: Record<string, string> = {
    front_desk: 'Mostrador',
    phone: 'Teléfono',
    web: 'Web',
    whatsapp: 'WhatsApp',
    walk_in: 'Walk-in',
};
const reservationTint: Record<string, string> = {
    confirmed: 'border-success/10 bg-success/10 text-success',
    pending: 'border-pending/10 bg-pending/10 text-pending',
};

const monthlyChart = computed(() => ({
    labels: props.monthly.map((m) => m.label),
    datasets: [
        {
            type: 'bar' as const,
            label: 'Usos',
            data: props.monthly.map((m) => m.count),
            backgroundColor: 'rgba(3,4,94,0.75)',
            borderRadius: 4,
            yAxisID: 'y',
            order: 2,
        },
        {
            type: 'line' as const,
            label: 'Ingresos',
            data: props.monthly.map((m) => m.revenue),
            borderColor: '#0d9488',
            backgroundColor: 'rgba(13,148,136,0.12)',
            fill: true,
            tension: 0.4,
            borderWidth: 2,
            pointRadius: 0,
            yAxisID: 'y1',
            order: 1,
        },
    ],
}));

const monthlyOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: true,
            position: 'bottom' as const,
            labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true },
        },
    },
    scales: {
        x: { grid: { display: false } },
        y: {
            position: 'left' as const,
            beginAtZero: true,
            ticks: { precision: 0 },
            title: { display: true, text: 'Usos' },
        },
        y1: {
            position: 'right' as const,
            beginAtZero: true,
            grid: { drawOnChartArea: false },
            title: { display: true, text: 'Ingresos' },
        },
    },
};
</script>

<template>
    <RazeLayout :title="`Historial · Habitación ${room.number}`">
        <div class="grid grid-cols-12 gap-x-6 gap-y-8">
            <!-- Encabezado estilo reportes -->
            <div class="col-span-12">
                <div
                    class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="History" class="h-4 w-4" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2.5">
                                <h1 class="text-base font-medium">
                                    Historial · Habitación {{ room.number }}
                                </h1>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium"
                                    :class="tint[room.status_color]"
                                >
                                    <span
                                        class="h-2 w-2 rounded-full"
                                        :class="dotColor[room.status_color]"
                                    />
                                    {{ room.status_label }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ room.name ? `${room.name} · ` : ''
                                }}{{ room.room_type
                                }}<span v-if="room.zone">
                                    · {{ room.zone }}</span
                                >
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            as="a"
                            :href="route('tenant.rooms.show', room.id)"
                            variant="outline-secondary"
                            class="rounded-[0.5rem] bg-white"
                        >
                            <Lucide
                                icon="ArrowLeft"
                                class="mr-2 h-4 w-4 stroke-[1.3]"
                            />
                            Volver a la ficha
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Uso por periodo -->
            <div class="col-span-12">
                <div class="grid grid-cols-12 gap-5">
                    <div
                        v-for="stat in usage"
                        :key="stat.key"
                        class="box box--stacked col-span-6 p-5 xl:col-span-3"
                    >
                        <div class="flex items-center justify-between">
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-full border"
                                :class="tint[room.status_color]"
                            >
                                <Lucide
                                    :icon="usageIcons[stat.key] ?? 'Calendar'"
                                    class="h-5 w-5"
                                />
                            </div>
                            <div class="text-right">
                                <div class="text-2xl leading-none font-medium">
                                    {{ stat.count }}
                                </div>
                                <div class="mt-1 text-xs text-slate-500">
                                    {{ stat.count === 1 ? 'uso' : 'usos' }}
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 text-sm font-medium">
                            {{ stat.label }}
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            {{ money(stat.revenue) }} generados
                        </div>
                    </div>
                </div>
            </div>

            <!-- Próximas reservas -->
            <div class="col-span-12">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">Próximas reservas</div>
                    <span
                        class="ml-3 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-darkmode-500 dark:text-slate-300"
                    >
                        {{ upcomingReservations.length }}
                    </span>
                </div>
                <div
                    class="box box--stacked mt-3.5 overflow-auto p-5 lg:overflow-visible"
                >
                    <Table v-if="upcomingReservations.length">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Folio</Table.Th>
                                <Table.Th>Huésped</Table.Th>
                                <Table.Th>Entrada → Salida</Table.Th>
                                <Table.Th>Estado</Table.Th>
                                <Table.Th class="text-right">Monto</Table.Th>
                                <Table.Th class="text-right">Acciones</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr
                                v-for="reservation in upcomingReservations"
                                :key="reservation.id"
                            >
                                <Table.Td
                                    class="font-medium whitespace-nowrap"
                                    >{{ reservation.code }}</Table.Td
                                >
                                <Table.Td>
                                    <div class="font-medium">
                                        {{ reservation.guest_name }}
                                    </div>
                                    <div
                                        v-if="reservation.rate_plan"
                                        class="text-xs text-slate-400"
                                    >
                                        {{ reservation.rate_plan }}
                                    </div>
                                </Table.Td>
                                <Table.Td
                                    class="text-sm whitespace-nowrap text-slate-500"
                                >
                                    {{ reservation.starts_at }}
                                    <span class="text-slate-400">→</span>
                                    {{ reservation.ends_at }}
                                    <span
                                        v-if="reservation.starts_today"
                                        class="ml-1.5 rounded-full bg-info/10 px-1.5 py-0.5 text-xs font-medium text-info"
                                        >Llega hoy</span
                                    >
                                </Table.Td>
                                <Table.Td>
                                    <span
                                        class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            reservationTint[
                                                reservation.status
                                            ] ??
                                            'border-dark/10 bg-dark/10 text-dark'
                                        "
                                    >
                                        {{ reservation.status_label }}
                                    </span>
                                    <div
                                        v-if="reservation.payment_status_label"
                                        class="mt-1 text-xs text-slate-400"
                                    >
                                        {{ reservation.payment_status_label }}
                                    </div>
                                </Table.Td>
                                <Table.Td class="text-right font-medium">{{
                                    money(reservation.total_amount)
                                }}</Table.Td>
                                <Table.Td class="text-right">
                                    <Button
                                        as="a"
                                        :href="
                                            route('tenant.reservations', {
                                                reservation: reservation.id,
                                            })
                                        "
                                        variant="outline-secondary"
                                        class="rounded-[0.5rem] bg-white"
                                    >
                                        <Lucide
                                            icon="CalendarSearch"
                                            class="mr-2 h-4 w-4 stroke-[1.3]"
                                        />
                                        Ver reserva
                                    </Button>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div
                        v-else
                        class="flex flex-col items-center gap-2 py-10 text-center text-slate-400"
                    >
                        <Lucide icon="CalendarClock" class="h-8 w-8" />
                        <p class="text-sm">
                            Sin reservas próximas para esta habitación.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Estancias recientes -->
            <div class="col-span-12 flex flex-col xl:col-span-7">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">Estancias recientes</div>
                </div>
                <div
                    class="box box--stacked mt-3.5 flex-1 overflow-auto p-5 lg:overflow-visible"
                >
                    <Table v-if="recentStays.length">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Huésped</Table.Th>
                                <Table.Th>Entrada → Salida</Table.Th>
                                <Table.Th>Canal</Table.Th>
                                <Table.Th class="text-right">Monto</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr
                                v-for="stay in recentStays"
                                :key="stay.id"
                            >
                                <Table.Td>
                                    <div class="font-medium">
                                        {{ stay.guest_name }}
                                    </div>
                                    <span
                                        v-if="stay.active"
                                        class="rounded-full bg-primary/10 px-1.5 py-0.5 text-xs text-primary"
                                        >En uso ahora</span
                                    >
                                    <span
                                        v-else-if="stay.nights"
                                        class="text-xs text-slate-400"
                                        >{{ stay.nights }}
                                        {{
                                            stay.nights === 1
                                                ? 'noche'
                                                : 'noches'
                                        }}</span
                                    >
                                </Table.Td>
                                <Table.Td
                                    class="text-sm whitespace-nowrap text-slate-500"
                                >
                                    {{ stay.check_in_at }}
                                    <span class="text-slate-400">→</span>
                                    {{ stay.check_out_at ?? '—' }}
                                </Table.Td>
                                <Table.Td
                                    class="mt-0.5 text-xs text-slate-500"
                                    >{{
                                        channelLabels[stay.channel ?? ''] ??
                                        stay.channel ??
                                        '—'
                                    }}</Table.Td
                                >
                                <Table.Td class="text-right font-medium">{{
                                    money(stay.amount)
                                }}</Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div
                        v-else
                        class="flex flex-col items-center gap-2 py-10 text-center text-slate-400"
                    >
                        <Lucide icon="BedDouble" class="h-8 w-8" />
                        <p class="text-sm">
                            Esta habitación aún no se ha usado.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Limpieza: ciclos con espera y duración -->
            <div class="col-span-12 flex flex-col xl:col-span-5">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">
                        Historial de limpieza
                    </div>
                    <span
                        class="ml-3 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-darkmode-500 dark:text-slate-300"
                    >
                        {{ cleaningStats.last30 }} en 30 días
                    </span>
                </div>
                <div class="box box--stacked mt-3.5 flex-1 p-5">
                    <div class="grid grid-cols-2 gap-3">
                        <div
                            class="rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <div
                                class="text-[11px] tracking-wide text-slate-500 uppercase"
                            >
                                Limpieza promedio
                            </div>
                            <div class="mt-1 text-sm font-medium">
                                {{ fmtDuration(cleaningStats.avg_duration) }}
                            </div>
                        </div>
                        <div
                            class="rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <div
                                class="text-[11px] tracking-wide text-slate-500 uppercase"
                            >
                                Espera por limpiar
                            </div>
                            <div class="mt-1 text-sm font-medium">
                                {{ fmtDuration(cleaningStats.avg_dirty_wait) }}
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="cleaningCycles.length"
                        class="mt-4 divide-y divide-slate-100 dark:divide-darkmode-400"
                    >
                        <div
                            v-for="cycle in cleaningCycles"
                            :key="cycle.id"
                            class="flex items-start gap-3 py-2.5"
                        >
                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                            >
                                <Lucide icon="Brush" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm"
                                >
                                    <span class="font-medium">{{
                                        cycle.day
                                    }}</span>
                                    <span class="text-slate-400">·</span>
                                    <span class="text-slate-500">{{
                                        cycle.by
                                    }}</span>
                                    <span
                                        v-if="cycle.auto"
                                        class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-darkmode-500 dark:text-slate-300"
                                        >Automático</span
                                    >
                                    <span
                                        v-if="cycle.ongoing"
                                        class="rounded-full bg-warning/10 px-1.5 py-0.5 text-[10px] font-medium text-warning"
                                        >En curso</span
                                    >
                                </div>
                                <div class="mt-0.5 text-xs text-slate-400">
                                    <template
                                        v-if="cycle.dirty_minutes !== null"
                                    >
                                        Esperó por limpiar
                                        {{ fmtDuration(cycle.dirty_minutes) }} ·
                                    </template>
                                    <template v-if="!cycle.ongoing">
                                        Limpieza de
                                        {{ fmtDuration(cycle.duration_minutes)
                                        }}<template v-if="cycle.ended_status">
                                            → {{ cycle.ended_status }}</template
                                        >
                                    </template>
                                    <template v-else>
                                        Inició {{ cycle.started_at }}
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div
                        v-else
                        class="flex flex-col items-center gap-2 py-8 text-center text-slate-400"
                    >
                        <Lucide icon="Brush" class="h-8 w-8" />
                        <p class="text-sm">
                            Sin limpiezas registradas en los últimos 4 meses.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Chart 12 meses -->
            <div class="col-span-12 flex flex-col xl:col-span-7">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">
                        Uso e ingresos · últimos 12 meses
                    </div>
                    <div
                        class="ml-auto flex items-center gap-3 text-xs text-slate-500"
                    >
                        <span>{{ totals.stays }} estancias en total</span>
                        <span class="font-medium text-success">{{
                            money(totals.revenue)
                        }}</span>
                    </div>
                </div>
                <div class="box box--stacked mt-3.5 flex-1 p-5">
                    <div class="h-[320px]">
                        <Chart
                            type="bar"
                            :data="monthlyChart"
                            :options="monthlyOptions"
                            class="!h-[320px]"
                        />
                    </div>
                </div>
            </div>

            <!-- Mantenimiento + línea de tiempo del semáforo -->
            <div class="col-span-12 flex flex-col gap-6 xl:col-span-5">
                <div>
                    <div class="flex items-center md:h-10">
                        <div class="text-base font-medium">Mantenimiento</div>
                    </div>
                    <div class="box box--stacked mt-3.5 p-5">
                        <div
                            v-if="maintenancePeriods.length"
                            class="divide-y divide-slate-100 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="period in maintenancePeriods"
                                :key="period.id"
                                class="flex items-start gap-3 py-2.5"
                            >
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-dark/10 bg-dark/10 text-dark"
                                >
                                    <Lucide icon="Wrench" class="h-4 w-4" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm"
                                    >
                                        <span class="font-medium">{{
                                            fmtDuration(period.duration_minutes)
                                        }}</span>
                                        <span
                                            v-if="period.ongoing"
                                            class="rounded-full bg-dark/10 px-1.5 py-0.5 text-[10px] font-medium text-dark"
                                            >En curso</span
                                        >
                                    </div>
                                    <div class="mt-0.5 text-xs text-slate-400">
                                        {{ period.started_at }}
                                        <template v-if="period.ended_at">
                                            → {{ period.ended_at }}</template
                                        >
                                        · {{ period.by }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 py-6 text-center text-slate-400"
                        >
                            <Lucide icon="Wrench" class="h-7 w-7" />
                            <p class="text-sm">
                                Sin mantenimientos en los últimos 4 meses.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-1 flex-col">
                    <div class="flex items-center md:h-10">
                        <div class="text-base font-medium">
                            Línea de tiempo del semáforo
                        </div>
                        <span
                            class="ml-3 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-darkmode-500 dark:text-slate-300"
                        >
                            {{ statusHistory.length }} movimientos
                        </span>
                    </div>
                    <div
                        class="box box--stacked mt-3.5 max-h-[420px] flex-1 overflow-y-auto p-5"
                    >
                        <template v-if="statusByDay.length">
                            <div
                                v-for="(group, gi) in statusByDay"
                                :key="group.date"
                                :class="gi > 0 ? 'mt-5' : ''"
                            >
                                <div
                                    class="mb-2 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    <Lucide
                                        icon="Calendar"
                                        class="h-3.5 w-3.5"
                                    />
                                    {{ group.day }}
                                </div>
                                <ul class="flow-root">
                                    <li
                                        v-for="(log, i) in group.items"
                                        :key="log.id"
                                        class="relative pb-3.5 last:pb-0"
                                    >
                                        <span
                                            v-if="i !== group.items.length - 1"
                                            class="absolute top-8 left-[15px] h-full w-px bg-slate-200 dark:bg-darkmode-400"
                                        />
                                        <div class="flex items-start gap-3">
                                            <div
                                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border"
                                                :class="tint[log.to_color]"
                                            >
                                                <span
                                                    class="h-2 w-2 rounded-full"
                                                    :class="
                                                        dotColor[log.to_color]
                                                    "
                                                />
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div
                                                    class="flex flex-wrap items-center gap-x-1.5 text-sm"
                                                >
                                                    <span
                                                        v-if="log.from"
                                                        class="text-slate-500"
                                                        >{{ log.from }} →</span
                                                    >
                                                    <span class="font-medium">{{
                                                        log.to
                                                    }}</span>
                                                    <span
                                                        v-if="log.auto"
                                                        class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-darkmode-500 dark:text-slate-300"
                                                        >Automático</span
                                                    >
                                                </div>
                                                <div
                                                    class="text-xs text-slate-400"
                                                >
                                                    {{ log.time }} ·
                                                    {{ log.by }}
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </template>
                        <div
                            v-else
                            class="py-8 text-center text-sm text-slate-500"
                        >
                            Sin cambios de estado registrados.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
