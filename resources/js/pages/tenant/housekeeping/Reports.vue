<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormDate, FormLabel, FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

/**
 * Rendimiento de limpieza con la misma estructura que el resto del panel:
 * cabecera compacta, contadores en fila, filtros en su propia franja y
 * tablas ordenables — la de agregados por camarista y la del detalle, que va
 * paginada porque crece con la operación.
 */
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

interface DetailRow {
    id: number;
    room: string | null;
    housekeeper: string | null;
    kind_label: string;
    started_day: string | null;
    started_label: string | null;
    ended_label: string | null;
    minutes: number;
    open: boolean;
    source: string;
    linens: LinenTotal[];
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    filters: {
        period: string;
        from: string;
        to: string;
        housekeeper: number | null;
        kind: string;
        orden: string;
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
    detail: Paginated<DetailRow> | null;
    kinds: Record<string, string>;
    housekeepers: { id: number; name: string }[];
}>();

const period = ref(props.filters.period);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const housekeeper = ref<number | string>(props.filters.housekeeper ?? '');
const kind = ref(props.filters.kind);
const orden = ref(props.filters.orden);

const sourceLabels: Record<string, string> = {
    plano: 'Cronómetro',
    manual: 'Captura manual',
};

const filtersActive = computed(
    () =>
        period.value !== 'month' ||
        housekeeper.value !== '' ||
        kind.value !== '' ||
        orden.value !== 'reciente',
);

function apply() {
    router.get(
        route('tenant.housekeeping.reports'),
        {
            period: period.value,
            from: period.value === 'custom' ? from.value : undefined,
            to: period.value === 'custom' ? to.value : undefined,
            housekeeper: housekeeper.value || undefined,
            kind: kind.value || undefined,
            orden: orden.value !== 'reciente' ? orden.value : undefined,
            // Cualquier cambio de filtro reinicia el detalle: la página
            // cuatro del periodo anterior no significa nada en el nuevo.
            detalle: undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

// El rango personalizado se aplica con el botón: mientras se teclean las dos
// fechas, cada tecla dispararía una consulta con un rango a medias.
watch([period, housekeeper, kind, orden], () => {
    if (period.value === 'custom' && (!from.value || !to.value)) return;
    apply();
});

function clearFilters() {
    period.value = 'month';
    housekeeper.value = '';
    kind.value = '';
    orden.value = 'reciente';
}

function pdfUrl() {
    const query = new URLSearchParams({ period: period.value });
    if (period.value === 'custom') {
        query.set('from', from.value);
        query.set('to', to.value);
    }
    if (housekeeper.value) query.set('housekeeper', String(housekeeper.value));
    if (kind.value) query.set('kind', kind.value);

    return `${route('tenant.housekeeping.reports.pdf')}?${query.toString()}`;
}

const hours = (minutes: number) =>
    minutes < 60
        ? `${minutes} min`
        : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;

const linenSummary = (items: LinenTotal[]) =>
    items.map((linen) => `${linen.label} ${linen.total}`).join(' · ');
</script>

<template>
    <RazeLayout title="Reporte de limpieza">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ChartColumn" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Rendimiento de limpieza
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ periodLabel }}
                        </p>
                    </div>
                </div>
                <div
                    class="flex w-full flex-wrap items-center gap-2 md:w-auto md:shrink-0 md:justify-end"
                >
                    <!-- El volver vive con las acciones, no flotando encima
                         de la tarjeta. -->
                    <Link
                        :href="route('tenant.housekeeping')"
                        class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Volver a limpieza
                    </Link>
                    <Button
                        as="a"
                        :href="pdfUrl()"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                    >
                        <Lucide icon="Download" class="mr-1.5 h-3.5 w-3.5" />
                        Descargar PDF
                    </Button>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-12 gap-4">
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="CircleCheck" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">{{ kpis.rooms }}</div>
                        <div class="truncate text-xs text-slate-500">
                            Habitaciones limpiadas
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Clock" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{
                                kpis.avg_minutes !== null
                                    ? `${kpis.avg_minutes} min`
                                    : 'Sin dato'
                            }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Tiempo promedio
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                    >
                        <Lucide icon="Brush" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ kpis.total_hours }} h
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Horas trabajadas
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                    >
                        <Lucide icon="RefreshCw" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{
                                kpis.turnaround.avg_total !== null
                                    ? hours(kpis.turnaround.avg_total)
                                    : 'Sin dato'
                            }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Vuelta a vendible
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtros del reporte completo -->
            <div
                class="box box--stacked mt-4 bg-slate-50/70 px-4 py-3 dark:bg-darkmode-600/40"
            >
                <div class="mb-3 flex items-center gap-2.5">
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Filter" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">Acota el reporte</div>
                        <div class="text-xs text-slate-500">
                            Los filtros aplican a todo: contadores, agregados y
                            detalle.
                        </div>
                    </div>
                    <button
                        v-if="filtersActive"
                        type="button"
                        class="ml-auto text-xs font-medium text-primary hover:underline"
                        @click="clearFilters"
                    >
                        Limpiar filtros
                    </button>
                </div>
                <div
                    class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-6"
                >
                    <div>
                        <FormLabel htmlFor="report-period">Periodo</FormLabel>
                        <FormSelect
                            id="report-period"
                            v-model="period"
                            class="h-9 text-xs"
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
                            <FormLabel htmlFor="report-from">Desde</FormLabel>
                            <FormDate
                                id="report-from"
                                v-model="from"
                                input-class="h-9 text-xs"
                            />
                        </div>
                        <div>
                            <FormLabel htmlFor="report-to">Hasta</FormLabel>
                            <FormDate
                                id="report-to"
                                v-model="to"
                                input-class="h-9 text-xs"
                            />
                        </div>
                    </template>
                    <div>
                        <FormLabel htmlFor="report-housekeeper">
                            Camarista
                        </FormLabel>
                        <FormSelect
                            id="report-housekeeper"
                            v-model="housekeeper"
                            class="h-9 text-xs"
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
                    <div>
                        <FormLabel htmlFor="report-kind">Tipo</FormLabel>
                        <FormSelect
                            id="report-kind"
                            v-model="kind"
                            class="h-9 text-xs"
                        >
                            <option value="">Todos los tipos</option>
                            <option
                                v-for="(label, key) in kinds"
                                :key="key"
                                :value="key"
                            >
                                {{ label }}
                            </option>
                        </FormSelect>
                    </div>
                    <div>
                        <FormLabel htmlFor="report-order">
                            Orden del detalle
                        </FormLabel>
                        <FormSelect
                            id="report-order"
                            v-model="orden"
                            class="h-9 text-xs"
                        >
                            <option value="reciente">Más reciente</option>
                            <option value="duracion">La que más tardó</option>
                            <option value="habitacion">Habitación</option>
                        </FormSelect>
                    </div>
                    <div v-if="period === 'custom'" class="flex items-end">
                        <Button
                            variant="outline-primary"
                            class="h-9 w-full bg-white text-xs whitespace-nowrap xl:w-auto"
                            @click="apply"
                        >
                            <Lucide
                                icon="RefreshCw"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Aplicar
                        </Button>
                    </div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-12 items-start gap-5">
                <!-- Por camarista -->
                <div class="col-span-12 xl:col-span-8">
                    <div class="box box--stacked">
                        <div
                            class="flex flex-wrap items-center gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-primary"
                                >
                                    <Lucide icon="Users" class="h-4 w-4" />
                                </div>
                                <div>
                                    <div class="font-medium">Por camarista</div>
                                    <div class="text-xs text-slate-500">
                                        Solo cuentan las limpiezas cerradas: una
                                        en curso todavía no dice cuánto tardó.
                                    </div>
                                </div>
                            </div>
                            <span
                                class="ml-auto rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                            >
                                {{ byHousekeeper.length }}
                            </span>
                        </div>
                        <div v-if="byHousekeeper.length" class="overflow-auto">
                            <Table sm hover class="text-xs">
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th class="whitespace-nowrap">
                                            Camarista
                                        </Table.Th>
                                        <Table.Th
                                            class="text-right whitespace-nowrap"
                                        >
                                            Habitaciones
                                        </Table.Th>
                                        <Table.Th
                                            class="text-right whitespace-nowrap"
                                        >
                                            Promedio
                                        </Table.Th>
                                        <Table.Th
                                            class="text-right whitespace-nowrap"
                                        >
                                            Más rápida
                                        </Table.Th>
                                        <Table.Th
                                            class="text-right whitespace-nowrap"
                                        >
                                            Más lenta
                                        </Table.Th>
                                        <Table.Th
                                            class="text-right whitespace-nowrap"
                                        >
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
                                            <div class="text-sm font-medium">
                                                {{ row.name }}
                                            </div>
                                            <div
                                                v-if="row.linens.length"
                                                class="truncate text-slate-500"
                                                :title="
                                                    linenSummary(row.linens)
                                                "
                                            >
                                                {{ linenSummary(row.linens) }}
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
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 px-5 py-10 text-center"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                            >
                                <Lucide icon="Users" class="h-7 w-7" />
                            </div>
                            <div class="text-sm font-medium">
                                Sin limpiezas en este periodo
                            </div>
                            <div class="text-xs text-slate-500">
                                Cambia el rango o quita los filtros.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ropa, tipos y vuelta a vendible -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="box box--stacked">
                        <div
                            class="flex items-center gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-info/10 text-info"
                            >
                                <Lucide icon="Layers" class="h-4 w-4" />
                            </div>
                            <div>
                                <div class="font-medium">Ropa consumida</div>
                                <div class="text-xs text-slate-500">
                                    Lo que salió de blancos en el periodo.
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="linens.length"
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="linen in linens"
                                :key="linen.key"
                                class="flex items-center justify-between px-4 py-2.5 text-sm"
                            >
                                <span class="text-slate-500">
                                    {{ linen.label }}
                                </span>
                                <span class="font-medium">
                                    {{ linen.total }}
                                </span>
                            </div>
                        </div>
                        <p
                            v-else
                            class="px-4 py-6 text-center text-xs text-slate-500"
                        >
                            No se registró consumo de ropa en el periodo.
                        </p>
                    </div>

                    <div class="box box--stacked mt-5">
                        <div
                            class="flex items-center gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Lucide icon="Brush" class="h-4 w-4" />
                            </div>
                            <div>
                                <div class="font-medium">Tipo de limpieza</div>
                                <div class="text-xs text-slate-500">
                                    Cuántas de cada clase.
                                </div>
                            </div>
                        </div>
                        <div
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="kindRow in byKind"
                                :key="kindRow.key"
                                class="flex items-center justify-between px-4 py-2.5 text-sm"
                            >
                                <span class="text-slate-500">
                                    {{ kindRow.label }}
                                </span>
                                <span class="font-medium">
                                    {{ kindRow.count }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="box box--stacked mt-5">
                        <div
                            class="flex items-center gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-pending/10 text-pending"
                            >
                                <Lucide icon="RefreshCw" class="h-4 w-4" />
                            </div>
                            <div>
                                <div class="font-medium">Vuelta a vendible</div>
                                <div class="text-xs text-slate-500">
                                    Desde que se desocupa hasta que vuelve a
                                    estar disponible.
                                </div>
                            </div>
                        </div>
                        <div class="p-4">
                            <div
                                class="rounded-xl bg-slate-50 p-3.5 dark:bg-darkmode-700"
                            >
                                <div class="text-xs text-slate-500">
                                    Ciclo completo
                                </div>
                                <div class="mt-1 text-xl font-semibold">
                                    {{
                                        kpis.turnaround.avg_total !== null
                                            ? hours(kpis.turnaround.avg_total)
                                            : 'Sin dato'
                                    }}
                                </div>
                            </div>
                            <div class="mt-3 space-y-2 text-sm">
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <span class="text-slate-500">
                                        Espera antes de limpiar
                                    </span>
                                    <span class="font-medium">
                                        {{
                                            kpis.turnaround.avg_wait !== null
                                                ? hours(
                                                      kpis.turnaround.avg_wait,
                                                  )
                                                : 'Sin dato'
                                        }}
                                    </span>
                                </div>
                                <div
                                    class="flex items-center justify-between gap-4 border-t border-dashed border-slate-300/70 pt-2 dark:border-darkmode-400"
                                >
                                    <span class="text-slate-500">
                                        Ciclos medidos
                                    </span>
                                    <span class="font-medium">
                                        {{ kpis.turnaround.samples }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detalle renglón por renglón -->
            <div v-if="detail" class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <Lucide
                            icon="NotebookPen"
                            class="h-4 w-4 text-slate-400"
                        />
                        Detalle del periodo
                        <span
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-darkmode-400"
                        >
                            {{ detail.total }}
                        </span>
                    </div>
                    <div
                        class="ml-auto text-xs text-slate-500"
                        v-if="detail.data.length"
                    >
                        Mostrando {{ detail.from }}-{{ detail.to }} de
                        {{ detail.total }}
                    </div>
                </div>

                <div v-if="detail.data.length" class="overflow-auto">
                    <Table sm hover class="text-xs">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th class="whitespace-nowrap">
                                    Habitación
                                </Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Camarista
                                </Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Tipo
                                </Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Horario
                                </Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Ropa
                                </Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Origen
                                </Table.Th>
                                <Table.Th class="text-right whitespace-nowrap">
                                    Duración
                                </Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="row in detail.data" :key="row.id">
                                <Table.Td>
                                    <div class="text-sm font-medium">
                                        {{ row.room ?? 'Sin habitación' }}
                                    </div>
                                    <div class="text-slate-500">
                                        {{ row.started_day }}
                                    </div>
                                </Table.Td>
                                <Table.Td>
                                    {{ row.housekeeper ?? 'Sin camarista' }}
                                </Table.Td>
                                <Table.Td class="whitespace-nowrap">
                                    <span
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                    >
                                        {{ row.kind_label }}
                                    </span>
                                </Table.Td>
                                <Table.Td class="whitespace-nowrap">
                                    {{ row.started_label }}
                                    <template v-if="row.ended_label">
                                        – {{ row.ended_label }}
                                    </template>
                                    <span v-else class="text-slate-400">
                                        en curso
                                    </span>
                                </Table.Td>
                                <Table.Td class="text-slate-500">
                                    <span
                                        v-if="row.linens.length"
                                        :title="linenSummary(row.linens)"
                                    >
                                        {{ linenSummary(row.linens) }}
                                    </span>
                                    <span v-else class="text-slate-400">
                                        Sin registro
                                    </span>
                                </Table.Td>
                                <Table.Td
                                    class="whitespace-nowrap text-slate-500"
                                >
                                    {{ sourceLabels[row.source] ?? row.source }}
                                </Table.Td>
                                <Table.Td class="text-right whitespace-nowrap">
                                    <span class="font-medium">
                                        {{ row.minutes }} min
                                    </span>
                                    <span
                                        v-if="row.open"
                                        class="ml-1.5 rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                                    >
                                        Abierta
                                    </span>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="SearchX" class="h-7 w-7" />
                    </div>
                    <div>
                        <p class="text-sm font-medium">
                            Sin limpiezas que mostrar
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Nada cae en el periodo con los filtros actuales.
                        </p>
                    </div>
                    <Button
                        v-if="filtersActive"
                        variant="outline-secondary"
                        @click="clearFilters"
                    >
                        <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                        Limpiar filtros
                    </Button>
                </div>

                <div
                    v-if="detail.links.length > 3"
                    class="flex flex-wrap justify-center gap-1 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <template v-for="(link, i) in detail.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-state
                            preserve-scroll
                            class="rounded-md px-2.5 py-1 text-xs"
                            :class="
                                link.active
                                    ? 'bg-primary text-white'
                                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-darkmode-400'
                            "
                        >
                            <span v-html="link.label" />
                        </Link>
                        <span
                            v-else
                            class="px-2.5 py-1 text-xs text-slate-400"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
