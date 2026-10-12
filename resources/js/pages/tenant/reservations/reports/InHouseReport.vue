<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import RazeLayout from '@/layouts/RazeLayout.vue';
import BarsPanel from '../board/BarsPanel.vue';
import { channelColor, channelLabel, tone } from '../board/chartColors';
import DonutPanel from '../board/DonutPanel.vue';
import KpiStrip from '../board/KpiStrip.vue';
import type { KpiItem } from '../board/KpiStrip.vue';
import { departureMeta, slicesOf } from '../board/meta';
import type { DepartureKey } from '../board/meta';
import ReportHeader from '../board/ReportHeader.vue';
import ReservationsNav from '../ReservationsNav.vue';

/**
 * Reporte de en casa (/reservas/alojados/reporte): cuándo se va la gente y
 * por dónde llegó. Tocar la dona regresa a la lista filtrada por salida.
 */
const props = defineProps<{
    property: { id: number; name: string };
    summary: {
        rooms: number;
        guests: number;
        departures_today: number;
        overdue: number;
        with_balance: number;
        balance: number;
        balance_label: string;
        departures: Record<DepartureKey, number>;
        channels: { channel: string; count: number }[];
    };
}>();

const list = (query: Record<string, string>) =>
    `${route('tenant.reservations.in-house')}?${new URLSearchParams(query)}`;

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
        href: route('tenant.reservations.in-house'),
    },
    {
        key: 'today',
        icon: 'LogOut',
        tone: 'warning',
        value: props.summary.departures_today,
        label: 'salen hoy',
        hint: `${props.summary.departures.manana} mañana`,
        href: list({ salida: 'hoy' }),
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
        href: list({ salida: 'vencida' }),
    },
    {
        key: 'balance',
        icon: 'Banknote',
        tone: 'pending',
        value: props.summary.balance_label,
        label: 'por cobrar',
        hint: `${props.summary.with_balance} ${props.summary.with_balance === 1 ? 'estancia con saldo' : 'estancias con saldo'}`,
        alert: props.summary.balance > 0,
        href: list({ saldo: '1' }),
    },
]);

const departureSlices = computed(() =>
    slicesOf(departureMeta, props.summary.departures),
);

const channelBars = computed(() =>
    props.summary.channels.map((c, i) => ({
        key: c.channel,
        label: channelLabel(c.channel),
        count: c.count,
        color: channelColor(c.channel, i),
    })),
);
</script>

<template>
    <RazeLayout title="Reporte de en casa">
        <div class="mt-2">
            <ReportHeader
                title="Reporte de en casa"
                :subtitle="`${property.name} · cuándo salen y cómo llegaron; toca la dona para ver esas estancias`"
                icon="ChartPie"
                :back-href="route('tenant.reservations.in-house')"
                back-label="Volver a en casa"
            />

            <ReservationsNav current="in-house" />

            <KpiStrip :items="kpis" />

            <div class="mt-4 grid grid-cols-12 items-stretch gap-4">
                <div class="col-span-12 flex flex-col xl:col-span-7">
                    <DonutPanel
                        title="Cuándo salen"
                        subtitle="Toca una parte para ver esas estancias"
                        icon="ChartPie"
                        icon-tone="border-warning/10 bg-warning/10 text-warning"
                        :slices="departureSlices"
                        :center-value="summary.rooms"
                        center-label="en casa"
                        empty-text="Nadie en casa ahora mismo"
                        @select="
                            (key) => key && router.visit(list({ salida: key }))
                        "
                    />
                </div>
                <div class="col-span-12 flex flex-col xl:col-span-5">
                    <BarsPanel
                        title="Cómo llegaron"
                        subtitle="Con reserva, por canal, o sin reserva"
                        icon="ChartBar"
                        icon-tone="border-info/10 bg-info/10 text-info"
                        :bars="channelBars"
                        :color="tone.primary()"
                        horizontal
                        :selectable="false"
                        :unit="['estancia', 'estancias']"
                        empty-text="Nadie en casa ahora mismo"
                    />
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
