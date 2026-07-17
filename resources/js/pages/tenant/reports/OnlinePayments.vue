<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
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
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Cobros en línea</h1>
                    <p class="text-sm text-slate-500">
                        {{ property.name }} · pasarelas y transferencias
                        verificadas, para conciliar (no entran a cortes de caja)
                    </p>
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <div>
                        <label class="mb-1 block text-xs text-slate-500"
                            >Desde</label
                        >
                        <FormInput
                            v-model="filters.from"
                            type="date"
                            class="!py-1.5"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500"
                            >Hasta</label
                        >
                        <FormInput
                            v-model="filters.to"
                            type="date"
                            class="!py-1.5"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500"
                            >Origen</label
                        >
                        <FormSelect
                            v-model="filters.source"
                            class="!py-1.5 text-sm"
                        >
                            <option value="all">Todos</option>
                            <option value="stripe">Stripe</option>
                            <option value="mercadopago">Mercado Pago</option>
                            <option value="transfer">Transferencias</option>
                        </FormSelect>
                    </div>
                    <Button
                        variant="primary"
                        class="rounded-[0.5rem]"
                        @click="applyFilters"
                    >
                        <Lucide icon="Filter" class="mr-2 h-4 w-4" /> Aplicar
                    </Button>
                </div>
            </div>

            <!-- Tarjetas -->
            <div class="mt-5 grid grid-cols-12 gap-5">
                <div
                    v-for="tile in tiles"
                    :key="tile.key"
                    class="col-span-12 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="box box--stacked flex h-full items-center gap-3.5 p-5"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border"
                            :class="tile.tone"
                        >
                            <Lucide :icon="tile.icon" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-xs text-slate-500">
                                {{ tile.label }}
                            </div>
                            <div class="mt-0.5 text-lg font-medium">
                                {{ stats[tile.key] }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Embudo del periodo -->
            <div class="mt-5 grid grid-cols-12 gap-5">
                <div
                    v-for="tile in funnelTiles"
                    :key="tile.key"
                    class="col-span-12 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="box box--stacked flex h-full items-center gap-3.5 p-5"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border"
                            :class="tile.tone"
                        >
                            <Lucide :icon="tile.icon" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-xs text-slate-500">
                                {{ tile.label }}
                            </div>
                            <div class="mt-0.5 text-lg font-medium">
                                {{ funnel[tile.key] }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabla -->
            <div
                class="box box--stacked mt-5 overflow-auto lg:overflow-visible"
            >
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr
                            class="border-b border-slate-200/60 text-xs tracking-wide text-slate-400 uppercase dark:border-darkmode-400"
                        >
                            <th class="px-5 py-3.5 font-medium">Fecha</th>
                            <th class="px-5 py-3.5 font-medium">Reserva</th>
                            <th class="px-5 py-3.5 font-medium">Huésped</th>
                            <th class="px-5 py-3.5 font-medium">Concepto</th>
                            <th class="px-5 py-3.5 font-medium">Origen</th>
                            <th class="px-5 py-3.5 font-medium">Referencia</th>
                            <th class="px-5 py-3.5 text-right font-medium">
                                Monto
                            </th>
                            <th class="px-5 py-3.5 text-right font-medium">
                                Comisión
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-slate-100 dark:divide-darkmode-400/60"
                    >
                        <tr v-for="row in rows" :key="row.id">
                            <td
                                class="px-5 py-3 whitespace-nowrap text-slate-500"
                            >
                                {{ row.paid_at }}
                            </td>
                            <td class="px-5 py-3 font-medium whitespace-nowrap">
                                {{ row.reservation_code }}
                            </td>
                            <td class="max-w-[180px] truncate px-5 py-3">
                                {{ row.guest_name }}
                            </td>
                            <td class="px-5 py-3">
                                <span
                                    class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary"
                                    >{{ row.concept }}</span
                                >
                            </td>
                            <td class="px-5 py-3 whitespace-nowrap">
                                {{ row.source }}
                                <span
                                    v-if="row.mode === 'test'"
                                    class="ml-1 rounded-full bg-warning/10 px-1.5 py-0.5 text-[10px] font-medium text-warning"
                                    >prueba</span
                                >
                                <div
                                    v-if="row.verified_by"
                                    class="text-[10px] text-slate-400"
                                >
                                    Verificó {{ row.verified_by }}
                                </div>
                            </td>
                            <td
                                class="max-w-[160px] truncate px-5 py-3 font-mono text-xs text-slate-500"
                                :title="row.reference ?? ''"
                            >
                                {{ row.reference ?? '—' }}
                            </td>
                            <td
                                class="px-5 py-3 text-right font-medium whitespace-nowrap"
                            >
                                {{ row.amount_label }}
                            </td>
                            <td
                                class="px-5 py-3 text-right whitespace-nowrap text-slate-500"
                            >
                                {{ row.fee_label ?? '—' }}
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td
                                colspan="8"
                                class="px-5 py-12 text-center text-sm text-slate-500"
                            >
                                Sin cobros en línea en este periodo. Los pagos
                                con link o transferencia verificada aparecerán
                                aquí.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </RazeLayout>
</template>
