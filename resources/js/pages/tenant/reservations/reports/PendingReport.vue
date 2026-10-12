<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import RazeLayout from '@/layouts/RazeLayout.vue';
import BarsPanel from '../board/BarsPanel.vue';
import { channelColor, channelLabel, tone } from '../board/chartColors';
import DonutPanel from '../board/DonutPanel.vue';
import KpiStrip from '../board/KpiStrip.vue';
import type { KpiItem } from '../board/KpiStrip.vue';
import { holdMeta, slicesOf } from '../board/meta';
import type { HoldKey } from '../board/meta';
import ReportHeader from '../board/ReportHeader.vue';
import ReservationsNav from '../ReservationsNav.vue';

/**
 * Reporte de pendientes (/reservas/pendientes/reporte): en qué punto del
 * reloj va cada apartado y de qué canal salen los que se quedan sin
 * confirmar. Tocar la dona regresa a la lista filtrada por el reloj.
 */
const props = defineProps<{
    property: { id: number; name: string };
    summary: {
        holds: number;
        expiring: number;
        expired: number;
        transfers: number;
        states: Record<HoldKey, number>;
        channels: { channel: string; count: number }[];
        balance_label: string;
        settlements: number;
        settlement_amount_label: string;
    };
    holdMinutes: number;
}>();

const list = (query: Record<string, string>) =>
    `${route('tenant.reservations.pending')}?${new URLSearchParams(query)}`;

const kpis = computed<KpiItem[]>(() => [
    {
        key: 'holds',
        icon: 'Clock',
        tone: 'pending',
        value: props.summary.holds,
        label: 'por confirmar',
        hint: `Falta cobrar ${props.summary.balance_label}`,
        href: route('tenant.reservations.pending'),
    },
    {
        key: 'clock',
        icon: 'TimerReset',
        tone: 'danger',
        value: props.summary.expiring + props.summary.expired,
        label: 'vencidos o por vencer',
        hint: props.summary.expired
            ? `${props.summary.expired} ya vencidos`
            : 'Ninguno vencido',
        alert: props.summary.expired > 0,
        href: list({
            estado: props.summary.expired ? 'vencido' : 'por_vencer',
        }),
    },
    {
        key: 'transfers',
        icon: 'Landmark',
        tone: 'pending',
        value: props.summary.transfers,
        label: 'transferencias',
        hint: 'Por verificar en Pagos',
        href: list({ estado: 'transferencia' }),
    },
    {
        key: 'settlements',
        icon: 'ReceiptText',
        tone: 'danger',
        value: props.summary.settlement_amount_label,
        label: 'cuentas sin cobrar',
        hint: `${props.summary.settlements} ${props.summary.settlements === 1 ? 'estancia cerrada con saldo' : 'estancias cerradas con saldo'}`,
        alert: props.summary.settlements > 0,
        href: route('tenant.reservations.settlements'),
    },
]);

const stateSlices = computed(() => slicesOf(holdMeta, props.summary.states));

const channelBars = computed(() =>
    props.summary.channels.map((c, i) => ({
        key: c.channel,
        label: channelLabel(c.channel),
        count: c.count,
        color: channelColor(c.channel, i),
    })),
);

const holdLabel = computed(() =>
    props.holdMinutes >= 60 && props.holdMinutes % 60 === 0
        ? `${props.holdMinutes / 60} h`
        : `${props.holdMinutes} min`,
);
</script>

<template>
    <RazeLayout title="Reporte de pendientes">
        <div class="mt-2">
            <ReportHeader
                title="Reporte de pendientes"
                :subtitle="`${property.name} · el reloj de los apartados (se liberan solos a ${holdLabel}) y de dónde vienen`"
                icon="ChartPie"
                :back-href="route('tenant.reservations.pending')"
                back-label="Volver a pendientes"
            />

            <ReservationsNav current="pending" />

            <KpiStrip :items="kpis" />

            <div class="mt-4 grid grid-cols-12 items-stretch gap-4">
                <div class="col-span-12 flex flex-col xl:col-span-7">
                    <DonutPanel
                        title="El reloj de los apartados"
                        subtitle="Toca una parte para ver esos apartados"
                        icon="ChartPie"
                        icon-tone="border-pending/10 bg-pending/10 text-pending"
                        :slices="stateSlices"
                        :center-value="summary.holds"
                        center-label="por confirmar"
                        empty-text="Nada por confirmar"
                        @select="
                            (key) => key && router.visit(list({ estado: key }))
                        "
                    />
                </div>
                <div class="col-span-12 flex flex-col xl:col-span-5">
                    <BarsPanel
                        title="De dónde vienen"
                        subtitle="Por qué canal se apartaron"
                        icon="ChartBar"
                        icon-tone="border-info/10 bg-info/10 text-info"
                        :bars="channelBars"
                        :color="tone.primary()"
                        horizontal
                        :selectable="false"
                        :unit="['apartado', 'apartados']"
                        empty-text="Nada por confirmar"
                    />
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
