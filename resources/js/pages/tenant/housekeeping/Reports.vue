<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface LinenTotal {
    key: string;
    label: string;
    total: number;
}

interface HousekeeperRow {
    name: string;
    rooms: number;
    avg_minutes: number;
    total_minutes: number;
    fastest: number;
    slowest: number;
    linens: LinenTotal[];
}

const props = defineProps<{
    filters: {
        period: string;
        from: string;
        to: string;
        housekeeper: number | null;
    };
    periodLabel: string;
    kpis: {
        rooms: number;
        in_progress: number;
        avg_minutes: number | null;
        total_hours: number;
        turnaround: {
            samples: number;
            avg_wait: number | null;
            avg_total: number | null;
        };
    };
    byHousekeeper: HousekeeperRow[];
    linens: LinenTotal[];
    byKind: { key: string; label: string; count: number }[];
    housekeepers: { id: number; name: string }[];
}>();

const period = ref(props.filters.period);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const housekeeper = ref(props.filters.housekeeper ?? '');

function apply() {
    router.get(
        route('tenant.housekeeping.reports'),
        {
            period: period.value,
            from: period.value === 'custom' ? from.value : undefined,
            to: period.value === 'custom' ? to.value : undefined,
            housekeeper: housekeeper.value || undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function pdfUrl() {
    const params = new URLSearchParams({ period: period.value });
    if (period.value === 'custom') {
        params.set('from', from.value);
        params.set('to', to.value);
    }
    if (housekeeper.value) params.set('housekeeper', String(housekeeper.value));

    return `${route('tenant.housekeeping.reports.pdf')}?${params.toString()}`;
}

const hours = (minutes: number) =>
    minutes < 60
        ? `${minutes} min`
        : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;

const cards = computed(() => [
    {
        label: 'Habitaciones limpiadas',
        value: String(props.kpis.rooms),
        icon: 'CircleCheck' as const,
        tone: 'border-success/20 bg-success/10 text-success',
    },
    {
        label: 'Tiempo promedio',
        value:
            props.kpis.avg_minutes !== null
                ? `${props.kpis.avg_minutes} min`
                : '—',
        icon: 'Clock' as const,
        tone: 'border-primary/20 bg-primary/10 text-primary',
    },
    {
        label: 'Horas trabajadas',
        value: `${props.kpis.total_hours} h`,
        icon: 'Brush' as const,
        tone: 'border-info/20 bg-info/10 text-info',
    },
    {
        label: 'Vuelta a vendible',
        value:
            props.kpis.turnaround.avg_total !== null
                ? hours(props.kpis.turnaround.avg_total)
                : '—',
        icon: 'RefreshCw' as const,
        tone: 'border-pending/20 bg-pending/10 text-pending',
    },
]);
</script>

<template>
    <RazeLayout title="Reporte de limpieza">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-wrap items-center justify-between gap-4 p-5"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <div
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ChartColumn" class="h-7 w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl font-medium">
                            Rendimiento de limpieza
                        </h1>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ periodLabel }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        as="a"
                        :href="route('tenant.housekeeping')"
                        variant="outline-secondary"
                        class="min-h-11 rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="ArrowLeft"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Volver
                    </Button>
                    <Button
                        as="a"
                        :href="pdfUrl()"
                        variant="outline-primary"
                        class="min-h-11 rounded-[0.5rem] bg-white"
                    >
                        <Lucide icon="Download" class="mr-2 h-4 w-4" />
                        PDF
                    </Button>
                </div>
            </div>

            <!-- Filtros -->
            <div
                class="box box--stacked mt-5 flex flex-wrap items-end gap-3 p-4"
            >
                <div>
                    <label class="mb-1 block text-xs text-slate-500">
                        Periodo
                    </label>
                    <FormSelect
                        v-model="period"
                        class="w-full sm:w-48"
                        @change="apply"
                    >
                        <option value="day">Hoy</option>
                        <option value="week">Esta semana</option>
                        <option value="month">Este mes</option>
                        <option value="year">Este año</option>
                        <option value="custom">Personalizado</option>
                    </FormSelect>
                </div>
                <template v-if="period === 'custom'">
                    <div>
                        <label class="mb-1 block text-xs text-slate-500">
                            Desde
                        </label>
                        <FormInput v-model="from" type="date" class="w-40" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500">
                            Hasta
                        </label>
                        <FormInput v-model="to" type="date" class="w-40" />
                    </div>
                </template>
                <div>
                    <label class="mb-1 block text-xs text-slate-500">
                        Camarista
                    </label>
                    <FormSelect
                        v-model="housekeeper"
                        class="w-full sm:w-52"
                        @change="apply"
                    >
                        <option value="">Todas</option>
                        <option
                            v-for="h in housekeepers"
                            :key="h.id"
                            :value="h.id"
                        >
                            {{ h.name }}
                        </option>
                    </FormSelect>
                </div>
                <Button
                    v-if="period === 'custom'"
                    variant="outline-primary"
                    class="min-h-11 rounded-[0.5rem] bg-white"
                    @click="apply"
                >
                    <Lucide icon="RefreshCw" class="mr-2 h-4 w-4" />
                    Aplicar
                </Button>
            </div>

            <!-- KPIs -->
            <div class="mt-5 grid grid-cols-12 gap-5">
                <div
                    v-for="card in cards"
                    :key="card.label"
                    class="col-span-6 xl:col-span-3"
                >
                    <div
                        class="box box--stacked flex h-full items-center gap-4 p-5"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border"
                            :class="card.tone"
                        >
                            <Lucide :icon="card.icon" class="h-5 w-5" />
                        </div>
                        <div>
                            <div class="text-xl font-medium">
                                {{ card.value }}
                            </div>
                            <div class="mt-0.5 text-xs text-slate-500">
                                {{ card.label }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-12 items-start gap-6">
                <!-- Por camarista -->
                <div class="col-span-12 xl:col-span-8">
                    <div class="box box--stacked">
                        <div
                            class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="Users"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-base font-medium">
                                    Por camarista
                                </h2>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                Solo cuentan las limpiezas cerradas: una en
                                curso todavía no dice cuánto tardó.
                            </p>
                        </div>
                        <div class="p-5">
                            <p
                                v-if="byHousekeeper.length === 0"
                                class="rounded-lg border border-dashed border-slate-300/70 py-8 text-center text-sm text-slate-500 dark:border-darkmode-400"
                            >
                                Sin limpiezas registradas en este periodo.
                            </p>
                            <div v-else class="overflow-auto">
                                <Table>
                                    <Table.Thead>
                                        <Table.Tr>
                                            <Table.Th>Camarista</Table.Th>
                                            <Table.Th class="text-right">
                                                Habitaciones
                                            </Table.Th>
                                            <Table.Th class="text-right">
                                                Promedio
                                            </Table.Th>
                                            <Table.Th class="text-right">
                                                Más rápida
                                            </Table.Th>
                                            <Table.Th class="text-right">
                                                Más lenta
                                            </Table.Th>
                                            <Table.Th class="text-right">
                                                Total
                                            </Table.Th>
                                        </Table.Tr>
                                    </Table.Thead>
                                    <Table.Tbody>
                                        <Table.Tr
                                            v-for="row in byHousekeeper"
                                            :key="row.name"
                                        >
                                            <Table.Td>
                                                <div class="font-medium">
                                                    {{ row.name }}
                                                </div>
                                                <div
                                                    v-if="row.linens.length"
                                                    class="mt-0.5 text-xs text-slate-500"
                                                >
                                                    <span
                                                        v-for="linen in row.linens"
                                                        :key="linen.key"
                                                        class="mr-2"
                                                    >
                                                        {{ linen.label }}:
                                                        {{ linen.total }}
                                                    </span>
                                                </div>
                                            </Table.Td>
                                            <Table.Td
                                                class="text-right font-medium"
                                            >
                                                {{ row.rooms }}
                                            </Table.Td>
                                            <Table.Td class="text-right">
                                                {{ row.avg_minutes }} min
                                            </Table.Td>
                                            <Table.Td
                                                class="text-right text-slate-500"
                                            >
                                                {{ row.fastest }} min
                                            </Table.Td>
                                            <Table.Td
                                                class="text-right text-slate-500"
                                            >
                                                {{ row.slowest }} min
                                            </Table.Td>
                                            <Table.Td class="text-right">
                                                {{ hours(row.total_minutes) }}
                                            </Table.Td>
                                        </Table.Tr>
                                    </Table.Tbody>
                                </Table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ropa y tipos -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="box box--stacked">
                        <div
                            class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="Layers"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-base font-medium">
                                    Ropa consumida
                                </h2>
                            </div>
                        </div>
                        <div class="p-5">
                            <p
                                v-if="linens.length === 0"
                                class="text-sm text-slate-500"
                            >
                                No se registró consumo de ropa en el periodo.
                            </p>
                            <div v-else class="flex flex-col gap-2">
                                <div
                                    v-for="linen in linens"
                                    :key="linen.key"
                                    class="flex items-center justify-between rounded-lg border border-slate-200/70 p-3 text-sm dark:border-darkmode-400"
                                >
                                    <span>{{ linen.label }}</span>
                                    <span class="font-medium">{{
                                        linen.total
                                    }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="box box--stacked mt-6">
                        <div
                            class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="Brush"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-base font-medium">
                                    Tipo de limpieza
                                </h2>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2 p-5">
                            <div
                                v-for="kind in byKind"
                                :key="kind.key"
                                class="flex items-center justify-between rounded-lg border border-slate-200/70 p-3 text-sm dark:border-darkmode-400"
                            >
                                <span>{{ kind.label }}</span>
                                <span class="font-medium">{{
                                    kind.count
                                }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="box box--stacked mt-6 p-5">
                        <div class="flex items-center gap-2">
                            <Lucide
                                icon="RefreshCw"
                                class="h-4 w-4 stroke-[1.5] text-primary"
                            />
                            <h2 class="text-base font-medium">
                                Vuelta a vendible
                            </h2>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            Desde que el huésped desocupa hasta que la
                            habitación vuelve a estar disponible, incluida la
                            espera antes de que alguien la tome.
                        </p>
                        <div class="mt-3 flex flex-col gap-2 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500">
                                    Espera antes de limpiar
                                </span>
                                <span class="font-medium">
                                    {{
                                        kpis.turnaround.avg_wait !== null
                                            ? hours(kpis.turnaround.avg_wait)
                                            : '—'
                                    }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500"
                                    >Ciclo completo</span
                                >
                                <span class="font-medium">
                                    {{
                                        kpis.turnaround.avg_total !== null
                                            ? hours(kpis.turnaround.avg_total)
                                            : '—'
                                    }}
                                </span>
                            </div>
                            <div
                                class="flex items-center justify-between text-xs text-slate-400"
                            >
                                <span>Ciclos medidos</span>
                                <span>{{ kpis.turnaround.samples }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
