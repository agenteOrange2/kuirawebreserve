<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Button from '@/components/Base/Button';
import { FormDate, FormLabel, FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface Row {
    id: number;
    paid_at: string;
    reservation_code: string | null;
    guest_name: string;
    concept: string;
    source: string;
    mode: string | null;
    reference: string | null;
    verified_by: string | null;
    amount: number;
    amount_label: string;
    fee_label: string | null;
}

const props = defineProps<{
    property: { id: number; name: string };
    filters: { from: string; to: string; source: string };
    stats: {
        count: number;
        total_label: string;
        fees_label: string;
        net_label: string;
    };
    funnel: {
        issued: number;
        paid: number;
        conversion_label: string;
        refunded_label: string;
    };
    rows: Row[];
}>();

const filters = reactive({ ...props.filters });

function applyFilters() {
    router.get(route('tenant.online-payments'), filters, {
        preserveState: true,
        preserveScroll: true,
    });
}

const tiles: {
    key: keyof typeof props.stats;
    label: string;
    icon: Icon;
    tone: string;
}[] = [
    {
        key: 'count',
        label: 'Cobros',
        icon: 'Receipt',
        tone: 'border-primary/10 bg-primary/10 text-primary',
    },
    {
        key: 'total_label',
        label: 'Total cobrado',
        icon: 'Landmark',
        tone: 'border-success/10 bg-success/10 text-success',
    },
    {
        key: 'fees_label',
        label: 'Comisiones reportadas',
        icon: 'Percent',
        tone: 'border-pending/10 bg-pending/10 text-pending',
    },
    {
        key: 'net_label',
        label: 'Neto estimado',
        icon: 'Wallet',
        tone: 'border-info/10 bg-info/10 text-info',
    },
];

// Embudo del periodo (F4): cuántas solicitudes se emiten y cuántas cobran.
const funnelTiles: {
    key: keyof typeof props.funnel;
    label: string;
    icon: Icon;
    tone: string;
}[] = [
    {
        key: 'issued',
        label: 'Solicitudes emitidas',
        icon: 'Send',
        tone: 'border-primary/10 bg-primary/10 text-primary',
    },
    {
        key: 'paid',
        label: 'Solicitudes pagadas',
        icon: 'CircleCheck',
        tone: 'border-success/10 bg-success/10 text-success',
    },
    {
        key: 'conversion_label',
        label: 'Conversión de cobro',
        icon: 'TrendingUp',
        tone: 'border-info/10 bg-info/10 text-info',
    },
    {
        key: 'refunded_label',
        label: 'Reembolsado',
        icon: 'Undo2',
        tone: 'border-pending/10 bg-pending/10 text-pending',
    },
];
</script>

<template>
    <RazeLayout title="Cobros en línea">
        <div class="mt-2">
            <!-- Encabezado -->
            <div class="box box--stacked overflow-hidden">
                <div
                    class="flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Landmark" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-base font-medium">
                                Cobros en línea
                            </h1>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ property.name }} · pasarelas y transferencias
                                verificadas, para conciliar (no entran a cortes
                                de caja).
                            </p>
                        </div>
                    </div>
                    <div
                        class="flex w-full flex-wrap items-center gap-2 md:w-auto md:shrink-0 md:justify-end"
                    >
                        <!-- El volver vive con las acciones, no flotando
                             encima de la tarjeta. -->
                        <Link
                            :href="route('tenant.payments')"
                            class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                        >
                            <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                            Volver a pagos
                        </Link>
                    </div>
                </div>

                <!-- Periodo y origen: franja propia, pegada al encabezado -->
                <div
                    class="flex flex-wrap items-end gap-2.5 border-t border-slate-200/60 bg-slate-50/70 px-4 py-3 sm:px-5 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="w-full sm:w-36">
                        <FormLabel htmlFor="op-from">Desde</FormLabel>
                        <FormDate
                            id="op-from"
                            v-model="filters.from"
                            input-class="h-9 text-xs"
                        />
                    </div>
                    <div class="w-full sm:w-36">
                        <FormLabel htmlFor="op-to">Hasta</FormLabel>
                        <FormDate
                            id="op-to"
                            v-model="filters.to"
                            input-class="h-9 text-xs"
                        />
                    </div>
                    <div class="w-full sm:w-44">
                        <FormLabel htmlFor="op-source">Origen</FormLabel>
                        <FormSelect
                            id="op-source"
                            v-model="filters.source"
                            class="h-9 text-xs"
                        >
                            <option value="all">Todos</option>
                            <option value="stripe">Stripe</option>
                            <option value="mercadopago">Mercado Pago</option>
                            <option value="transfer">Transferencias</option>
                        </FormSelect>
                    </div>
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        @click="applyFilters"
                    >
                        <Lucide icon="Filter" class="mr-1.5 h-3.5 w-3.5" />
                        Aplicar
                    </Button>
                </div>
            </div>

            <!-- Tarjetas -->
            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                <div
                    v-for="tile in tiles"
                    :key="tile.key"
                    class="col-span-6 xl:col-span-3"
                >
                    <div
                        class="box box--stacked flex h-full items-center gap-2.5 p-3"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                            :class="tile.tone"
                        >
                            <Lucide :icon="tile.icon" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium">
                                {{ stats[tile.key] }}
                            </div>
                            <div class="truncate text-xs text-slate-500">
                                {{ tile.label }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Embudo del periodo -->
            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                <div
                    v-for="tile in funnelTiles"
                    :key="tile.key"
                    class="col-span-6 xl:col-span-3"
                >
                    <div
                        class="box box--stacked flex h-full items-center gap-2.5 p-3"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                            :class="tile.tone"
                        >
                            <Lucide :icon="tile.icon" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium">
                                {{ funnel[tile.key] }}
                            </div>
                            <div class="truncate text-xs text-slate-500">
                                {{ tile.label }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabla -->
            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="Receipt" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-sm font-medium">Cobros del periodo</h2>
                        <p class="text-xs text-slate-500">
                            Cada pago en línea con su referencia y su comisión.
                        </p>
                    </div>
                    <span
                        v-if="rows.length"
                        class="ml-auto rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                    >
                        {{ rows.length }}
                    </span>
                </div>
                <div class="overflow-auto lg:overflow-visible">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr
                                class="border-b border-slate-200/60 text-xs tracking-wide text-slate-400 uppercase dark:border-darkmode-400"
                            >
                                <th class="px-4 py-2.5 text-[11px] font-medium">
                                    Fecha
                                </th>
                                <th class="px-4 py-2.5 text-[11px] font-medium">
                                    Reserva
                                </th>
                                <th class="px-4 py-2.5 text-[11px] font-medium">
                                    Huésped
                                </th>
                                <th class="px-4 py-2.5 text-[11px] font-medium">
                                    Concepto
                                </th>
                                <th class="px-4 py-2.5 text-[11px] font-medium">
                                    Origen
                                </th>
                                <th class="px-4 py-2.5 text-[11px] font-medium">
                                    Referencia
                                </th>
                                <th
                                    class="px-4 py-2.5 text-right text-[11px] font-medium"
                                >
                                    Monto
                                </th>
                                <th
                                    class="px-4 py-2.5 text-right text-[11px] font-medium"
                                >
                                    Comisión
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-slate-100 dark:divide-darkmode-400/60"
                        >
                            <tr v-for="row in rows" :key="row.id">
                                <td
                                    class="px-4 py-2.5 whitespace-nowrap text-slate-500"
                                >
                                    {{ row.paid_at }}
                                </td>
                                <td
                                    class="px-4 py-2.5 font-medium whitespace-nowrap"
                                >
                                    {{ row.reservation_code }}
                                </td>
                                <td class="max-w-[180px] truncate px-4 py-2.5">
                                    {{ row.guest_name }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <span
                                        class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                        >{{ row.concept }}</span
                                    >
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    {{ row.source }}
                                    <span
                                        v-if="row.mode === 'test'"
                                        class="ml-1 rounded-full bg-warning/10 px-1.5 py-0.5 text-[11px] font-medium text-warning"
                                        >prueba</span
                                    >
                                    <div
                                        v-if="row.verified_by"
                                        class="text-[11px] text-slate-400"
                                    >
                                        Verificó {{ row.verified_by }}
                                    </div>
                                </td>
                                <td
                                    class="max-w-[160px] truncate px-4 py-2.5 font-mono text-slate-500"
                                    :title="row.reference ?? ''"
                                >
                                    {{ row.reference ?? '—' }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                                >
                                    {{ row.amount_label }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right whitespace-nowrap text-slate-500"
                                >
                                    {{ row.fee_label ?? '—' }}
                                </td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td
                                    colspan="8"
                                    class="px-4 py-10 text-center text-xs text-slate-500"
                                >
                                    Sin cobros en línea en este periodo. Los
                                    pagos con link o transferencia verificada
                                    aparecerán aquí.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
