<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';
import DonutPanel from '../board/DonutPanel.vue';
import KpiStrip from '../board/KpiStrip.vue';
import type { KpiItem } from '../board/KpiStrip.vue';
import ReportHeader from '../board/ReportHeader.vue';
import { usePanelCan } from '../board/usePanelCan';
import ReservationsNav from '../ReservationsNav.vue';

/**
 * Reporte de la operación del día (/reservas/operacion/reporte): el avance
 * de llegadas y salidas de hoy y el semáforo de habitaciones. Antes estaba
 * encima de las listas y estorbaba para trabajar.
 */
const props = defineProps<{
    property: { id: number; name: string };
    day: {
        arrivals_expected: number;
        arrivals_done: number;
        walk_ins: number;
        departures_done: number;
        departures_left: number;
    };
    roomStatuses: { value: string; label: string; count: number }[];
    inHouse: {
        rooms: number;
        overdue: number;
        balance: number;
        balance_label: string;
        with_balance: number;
    };
    upcoming: { today: number; tomorrow: number };
    pending: { holds: number; expiring: number; expired: number };
}>();

const can = usePanelCan();

const arrivalsTotal = computed(
    () => props.day.arrivals_expected + props.day.walk_ins,
);
const arrivalsDone = computed(
    () => props.day.arrivals_done + props.day.walk_ins,
);
const departuresTotal = computed(
    () => props.day.departures_done + props.day.departures_left,
);
const pct = (done: number, total: number) =>
    total ? Math.min(100, Math.round((done / total) * 100)) : 0;

const kpis = computed<KpiItem[]>(() => [
    {
        key: 'arrivals',
        icon: 'LogIn',
        tone: 'info',
        value: `${arrivalsDone.value} de ${arrivalsTotal.value}`,
        label: 'llegadas',
        hint: `${props.upcoming.tomorrow} llegan mañana`,
        href: `${route('tenant.reservations.upcoming')}?cuando=hoy`,
    },
    {
        key: 'pending',
        icon: 'AlarmClock',
        tone: 'pending',
        value: props.pending.holds,
        label: 'por confirmar',
        hint:
            props.pending.expiring + props.pending.expired
                ? `${props.pending.expiring + props.pending.expired} vencidos o por vencer`
                : 'Ninguno por vencer',
        alert: props.pending.expiring + props.pending.expired > 0,
        href: route('tenant.reservations.pending'),
    },
    {
        key: 'stays',
        icon: 'DoorOpen',
        tone: 'primary',
        value: props.inHouse.rooms,
        label: 'en casa',
        hint: props.inHouse.overdue
            ? `${props.inHouse.overdue} con salida vencida`
            : 'Nadie pasado de hora',
        alert: props.inHouse.overdue > 0,
        href: route('tenant.reservations.in-house'),
    },
    {
        key: 'balance',
        icon: 'Banknote',
        tone: 'pending',
        value: props.inHouse.balance_label,
        label: 'por cobrar en casa',
        hint: `${props.inHouse.with_balance} con saldo`,
        alert: props.inHouse.balance > 0,
        href: `${route('tenant.reservations.in-house')}?saldo=1`,
    },
]);

const roomStatusHex: Record<string, string> = {
    available: '#0d9488',
    reserved: '#0891b2',
    occupied: '#b91c1c',
    dirty: '#78350f',
    cleaning: '#ca8a04',
    maintenance: '#1e293b',
};
const roomsTotal = computed(() =>
    props.roomStatuses.reduce((sum, s) => sum + s.count, 0),
);
const roomSlices = computed(() =>
    props.roomStatuses.map((s) => ({
        key: s.value,
        label: s.label,
        count: s.count,
        color: roomStatusHex[s.value] ?? '#94a3b8',
    })),
);
const freeRooms = computed(
    () => props.roomStatuses.find((s) => s.value === 'available')?.count ?? 0,
);

const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
</script>

<template>
    <RazeLayout title="Reporte de la operación">
        <div class="mt-2">
            <ReportHeader
                title="Reporte de la operación del día"
                :subtitle="`${property.name} · cuánto va del día y cómo están las habitaciones ahora`"
                icon="ChartColumn"
                :back-href="route('tenant.reservations.operation')"
                back-label="Volver a la operación"
            >
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
                    Reportes completos
                </Button>
            </ReportHeader>

            <ReservationsNav current="operation" />

            <KpiStrip :items="kpis" />

            <div class="mt-4 grid grid-cols-12 items-stretch gap-4">
                <div class="col-span-12 flex flex-col xl:col-span-7">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                            >
                                <Lucide icon="ListChecks" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Avance de hoy
                                </div>
                                <div class="text-xs text-slate-500">
                                    Lo que ya se hizo de lo que toca hoy
                                </div>
                            </div>
                        </div>
                        <div
                            class="flex flex-1 flex-col justify-center gap-5 px-4 py-4"
                        >
                            <Link
                                :href="`${route('tenant.reservations.upcoming')}?cuando=hoy`"
                                class="group block"
                            >
                                <div
                                    class="flex items-center justify-between gap-3 text-xs"
                                >
                                    <span
                                        class="inline-flex items-center gap-1.5 font-medium text-slate-700 group-hover:text-primary dark:text-slate-200"
                                    >
                                        <Lucide
                                            icon="LogIn"
                                            class="h-3.5 w-3.5 text-info"
                                        />
                                        Llegadas recibidas
                                    </span>
                                    <span class="text-slate-500 tabular-nums">
                                        <span
                                            class="font-medium text-slate-700 dark:text-slate-200"
                                            >{{ arrivalsDone }}</span
                                        >
                                        de {{ arrivalsTotal }}
                                    </span>
                                </div>
                                <div
                                    class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                                >
                                    <div
                                        class="h-full rounded-full bg-info"
                                        :style="{
                                            width: `${pct(arrivalsDone, arrivalsTotal)}%`,
                                        }"
                                    />
                                </div>
                                <div class="mt-1 text-[11px] text-slate-400">
                                    <template v-if="arrivalsTotal === 0"
                                        >Nadie tiene entrada hoy</template
                                    >
                                    <template
                                        v-else-if="
                                            arrivalsDone >= arrivalsTotal
                                        "
                                        >Ya llegaron todos</template
                                    >
                                    <template v-else>
                                        Faltan
                                        {{ arrivalsTotal - arrivalsDone }} por
                                        recibir
                                    </template>
                                    <template v-if="day.walk_ins">
                                        · {{ day.walk_ins }} sin reserva
                                    </template>
                                </div>
                            </Link>
                            <Link
                                :href="route('tenant.reservations.in-house')"
                                class="group block"
                            >
                                <div
                                    class="flex items-center justify-between gap-3 text-xs"
                                >
                                    <span
                                        class="inline-flex items-center gap-1.5 font-medium text-slate-700 group-hover:text-primary dark:text-slate-200"
                                    >
                                        <Lucide
                                            icon="LogOut"
                                            class="h-3.5 w-3.5 text-success"
                                        />
                                        Salidas registradas
                                    </span>
                                    <span class="text-slate-500 tabular-nums">
                                        <span
                                            class="font-medium text-slate-700 dark:text-slate-200"
                                            >{{ day.departures_done }}</span
                                        >
                                        de {{ departuresTotal }}
                                    </span>
                                </div>
                                <div
                                    class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                                >
                                    <div
                                        class="h-full rounded-full"
                                        :class="
                                            inHouse.overdue
                                                ? 'bg-danger'
                                                : 'bg-success'
                                        "
                                        :style="{
                                            width: `${pct(day.departures_done, departuresTotal)}%`,
                                        }"
                                    />
                                </div>
                                <div
                                    class="mt-1 text-[11px]"
                                    :class="
                                        inHouse.overdue
                                            ? 'text-danger'
                                            : 'text-slate-400'
                                    "
                                >
                                    <template v-if="departuresTotal === 0"
                                        >Nadie sale hoy</template
                                    >
                                    <template
                                        v-else-if="day.departures_left === 0"
                                        >Ya salieron todos los de hoy</template
                                    >
                                    <template v-else>
                                        Faltan {{ day.departures_left }} por
                                        salir<template v-if="inHouse.overdue">
                                            · {{ inHouse.overdue }} ya pasaron
                                            de hora</template
                                        >
                                    </template>
                                </div>
                            </Link>
                        </div>
                    </div>
                </div>
                <div class="col-span-12 flex flex-col xl:col-span-5">
                    <DonutPanel
                        title="Habitaciones ahora"
                        :subtitle="`${freeRooms} ${freeRooms === 1 ? 'libre' : 'libres'} para vender en este momento`"
                        icon="ChartPie"
                        icon-tone="border-success/10 bg-success/10 text-success"
                        :slices="roomSlices"
                        :center-value="roomsTotal"
                        center-label="habitaciones"
                        :selectable="false"
                        empty-text="Aún no hay habitaciones dadas de alta"
                    >
                        <template
                            v-if="can('rooms.view') && roomsTotal"
                            #footer
                        >
                            <Link
                                :href="route('tenant.plano')"
                                class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                            >
                                Abrir el plano para cambiar estados
                                <Lucide
                                    icon="ChevronRight"
                                    class="h-3.5 w-3.5"
                                />
                            </Link>
                        </template>
                    </DonutPanel>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
