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
interface StatusRow {
    id: number;
    from: string | null;
    to: string;
    to_color: string;
    by: string;
    at: string;
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
    recentStays: StayRow[];
    statusHistory: StatusRow[];
    totals: { stays: number; revenue: number };
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
const channelLabels: Record<string, string> = {
    front_desk: 'Mostrador',
    phone: 'Teléfono',
    web: 'Web',
    whatsapp: 'WhatsApp',
    walk_in: 'Walk-in',
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
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <h1 class="text-lg font-medium">
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
                        <p class="text-sm text-slate-500">
                            {{ room.name ? `${room.name} · ` : ''
                            }}{{ room.room_type
                            }}<span v-if="room.zone"> · {{ room.zone }}</span>
                        </p>
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

            <!-- Chart 12 meses -->
            <div class="col-span-12">
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
                <div class="box box--stacked mt-3.5 p-5">
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

            <!-- Estancias recientes -->
            <div class="col-span-12 xl:col-span-7">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">Estancias recientes</div>
                </div>
                <div
                    class="box box--stacked mt-3.5 overflow-auto p-5 lg:overflow-visible"
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
                                <Table.Td class="text-sm text-slate-500">{{
                                    channelLabels[stay.channel ?? ''] ??
                                    stay.channel ??
                                    '—'
                                }}</Table.Td>
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

            <!-- Historial del semáforo -->
            <div class="col-span-12 xl:col-span-5">
                <div class="flex items-center md:h-10">
                    <div class="text-base font-medium">Historial de estado</div>
                </div>
                <div class="box box--stacked mt-3.5 p-5">
                    <div v-if="statusHistory.length" class="flow-root">
                        <ul class="-mb-4">
                            <li
                                v-for="(log, i) in statusHistory"
                                :key="log.id"
                                class="relative pb-4"
                            >
                                <span
                                    v-if="i !== statusHistory.length - 1"
                                    class="absolute top-8 left-[15px] h-full w-px bg-slate-200 dark:bg-darkmode-400"
                                />
                                <div class="flex items-start gap-3">
                                    <div
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border"
                                        :class="tint[log.to_color]"
                                    >
                                        <span
                                            class="h-2 w-2 rounded-full"
                                            :class="dotColor[log.to_color]"
                                        />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm">
                                            <span
                                                v-if="log.from"
                                                class="text-slate-500"
                                                >{{ log.from }} →</span
                                            >
                                            <span class="font-medium">{{
                                                log.to
                                            }}</span>
                                        </div>
                                        <div class="text-xs text-slate-400">
                                            {{ log.by }} · {{ log.at }}
                                        </div>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <div v-else class="py-8 text-center text-sm text-slate-500">
                        Sin cambios de estado registrados.
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
