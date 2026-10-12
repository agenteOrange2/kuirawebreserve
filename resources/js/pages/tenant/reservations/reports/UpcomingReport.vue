<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import RazeLayout from '@/layouts/RazeLayout.vue';
import BarsPanel from '../board/BarsPanel.vue';
import { tone } from '../board/chartColors';
import DonutPanel from '../board/DonutPanel.vue';
import KpiStrip from '../board/KpiStrip.vue';
import type { KpiItem } from '../board/KpiStrip.vue';
import { paymentMeta, slicesOf } from '../board/meta';
import type { PaymentKey } from '../board/meta';
import ReportHeader from '../board/ReportHeader.vue';
import ReservationsNav from '../ReservationsNav.vue';

/**
 * Reporte de próximas (/reservas/proximas/reporte): las gráficas que antes
 * estaban encima de la lista. Cada barra o rebanada regresa a la lista ya
 * filtrada; la lista sigue siendo donde se trabaja.
 */
const props = defineProps<{
    property: { id: number; name: string };
    summary: {
        total: number;
        today: number;
        tomorrow: number;
        pending: number;
        overdue_payments: number;
        balance_label: string;
        days: { date: string; label: string; count: number }[];
        payments: Record<PaymentKey, number>;
    };
}>();

const list = (query: Record<string, string>) =>
    `${route('tenant.reservations.upcoming')}?${new URLSearchParams(query)}`;

const kpis = computed<KpiItem[]>(() => [
    {
        key: 'total',
        icon: 'CalendarDays',
        tone: 'primary',
        value: props.summary.total,
        label: props.summary.total === 1 ? 'apartada' : 'apartadas',
        hint: 'De hoy en adelante',
        href: route('tenant.reservations.upcoming'),
    },
    {
        key: 'today',
        icon: 'LogIn',
        tone: 'info',
        value: props.summary.today,
        label: 'llegan hoy',
        hint: `${props.summary.tomorrow} mañana`,
        href: list({ cuando: 'hoy' }),
    },
    {
        key: 'pending',
        icon: 'AlarmClock',
        tone: 'pending',
        value: props.summary.pending,
        label: 'sin confirmar',
        hint: 'Se trabajan en Pendientes',
        href: route('tenant.reservations.pending'),
    },
    {
        key: 'balance',
        icon: 'Banknote',
        tone: props.summary.overdue_payments ? 'danger' : 'pending',
        value: props.summary.balance_label,
        label: 'por cobrar',
        hint: props.summary.overdue_payments
            ? `${props.summary.overdue_payments} con pago vencido`
            : 'Sin contar fianzas',
        alert: props.summary.overdue_payments > 0,
        href: list({
            pago: props.summary.overdue_payments ? 'vencido' : 'sin_pago',
        }),
    },
]);

const dayBars = computed(() =>
    props.summary.days.map((d) => ({
        key: d.date,
        label: d.label,
        count: d.count,
    })),
);

/** Hoy y mañana usan su atajo; los demás días, el filtro de día exacto. */
function openDay(date: string) {
    if (!date) return;
    const index = props.summary.days.findIndex((d) => d.date === date);
    router.visit(
        index === 0
            ? list({ cuando: 'hoy' })
            : index === 1
              ? list({ cuando: 'manana' })
              : list({ date }),
    );
}

const paymentSlices = computed(() =>
    slicesOf(paymentMeta, props.summary.payments),
);
</script>

<template>
    <RazeLayout title="Reporte de próximas">
        <div class="mt-2">
            <ReportHeader
                title="Reporte de próximas"
                :subtitle="`${property.name} · cuándo llegan y cómo vienen pagadas; toca una gráfica para ver esas reservas`"
                icon="ChartColumn"
                :back-href="route('tenant.reservations.upcoming')"
                back-label="Volver a próximas"
            />

            <ReservationsNav current="upcoming" />

            <KpiStrip :items="kpis" />

            <div class="mt-4 grid grid-cols-12 items-stretch gap-4">
                <div class="col-span-12 flex flex-col xl:col-span-7">
                    <BarsPanel
                        title="Llegadas de las próximas dos semanas"
                        subtitle="Cuántas reservas entran cada día"
                        icon="ChartColumn"
                        :bars="dayBars"
                        :color="tone.primary()"
                        :unit="['llegada', 'llegadas']"
                        empty-text="Nadie llega en las próximas dos semanas"
                        @select="openDay"
                    />
                </div>
                <div class="col-span-12 flex flex-col xl:col-span-5">
                    <DonutPanel
                        title="Cómo vienen pagadas"
                        subtitle="Toca una parte para ver esas reservas"
                        icon="ChartPie"
                        icon-tone="border-success/10 bg-success/10 text-success"
                        :slices="paymentSlices"
                        :center-value="summary.total"
                        center-label="apartadas"
                        @select="
                            (key) => key && router.visit(list({ pago: key }))
                        "
                    />
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
