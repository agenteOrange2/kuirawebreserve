<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import { FormDate, FormLabel, FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface OrderRow {
    id: number;
    created_at: string;
    total: number;
    subtotal: number;
    discount: number;
    tip: number;
    payment_method: string;
    payment_reference: string | null;
    room: string | null;
    by: string | null;
    is_void: boolean;
    void_reason: string | null;
    summary: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    property: { id: number; name: string };
    orders: { data: OrderRow[]; links: PaginationLink[]; total: number };
    totals: {
        orders_count: number;
        total: number;
        tip: number;
        discount: number;
    };
    filters: {
        from: string;
        to: string;
        user: number | null;
        method: string | null;
        status: string | null;
    };
    staff: { id: number; name: string }[];
}>();

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n || 0);

const methodLabels: Record<string, string> = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
    room: 'Cargo a habitación',
};

const stripItem = 'inline-flex items-center gap-1.5 text-slate-500';
const stripValue = 'font-medium text-slate-700 dark:text-slate-300';
const stripDivider =
    'hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400';

const form = reactive({
    from: props.filters.from,
    to: props.filters.to,
    user: props.filters.user ?? '',
    method: props.filters.method ?? '',
    status: props.filters.status ?? '',
});

/** Quién y cómo se está filtrando, para la franja del encabezado. */
const staffLabel = computed(
    () =>
        props.staff.find((u) => String(u.id) === String(form.user))?.name ??
        'Todos los encargados',
);
const methodLabel = computed(
    () => methodLabels[String(form.method)] ?? 'Todos los métodos',
);

/** El periodo llega siempre; lo demás es lo que el usuario acotó. */
const filtersActive = computed(
    () => form.user !== '' || form.method !== '' || form.status !== '',
);

function clearFilters() {
    form.user = '';
    form.method = '';
    form.status = '';
}

// Filtrar recarga solo los datos, sin perder el lugar en la página.
watch(form, () => {
    router.get(
        '/pos/historial',
        { ...form },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
});

function openTicket(id: number) {
    window.open(`/pos/ticket/${id}`, '_blank', 'noopener');
}
</script>

<template>
    <RazeLayout title="Historial de ventas">
        <div class="mt-2">
            <div class="box box--stacked overflow-hidden">
                <div
                    class="flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Receipt" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-base font-medium">
                                Historial de ventas
                            </h1>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ property.name }} · lo que se cobró en el
                                punto de venta.
                            </p>
                        </div>
                    </div>
                    <div
                        class="flex w-full flex-wrap items-center gap-2 md:w-auto md:shrink-0 md:justify-end"
                    >
                        <!-- El volver vive con las acciones, no flotando
                             encima de la tarjeta. -->
                        <Link
                            :href="route('tenant.pos')"
                            class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                        >
                            <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                            Volver al punto de venta
                        </Link>
                    </div>
                </div>

                <!-- Qué se está viendo: el periodo y los filtros activos -->
                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-slate-200/60 bg-slate-50/70 px-4 py-3 text-xs sm:px-5 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <span :class="stripItem">
                        <Lucide
                            icon="CalendarRange"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">{{ form.from }}</span>
                        <span class="text-slate-400">→</span>
                        <span :class="stripValue">{{ form.to }}</span>
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="UserRound"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">{{ staffLabel }}</span>
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="CreditCard"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">{{ methodLabel }}</span>
                    </span>
                    <span
                        v-if="form.status === 'void'"
                        class="inline-flex items-center gap-1.5 rounded-full bg-danger/10 px-2.5 py-1 text-[11px] font-medium text-danger md:ml-auto"
                    >
                        <Lucide icon="Ban" class="h-3.5 w-3.5" />
                        Solo canceladas
                    </span>
                    <span
                        v-else-if="form.status === 'completed'"
                        class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-[11px] font-medium text-success md:ml-auto"
                    >
                        <Lucide icon="CircleCheck" class="h-3.5 w-3.5" />
                        Solo completadas
                    </span>
                </div>
            </div>

            <!-- Totales del periodo filtrado, no de la página a la vista -->
            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Wallet" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium">
                            {{ money(totals.total) }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Vendido en el periodo
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                    >
                        <Lucide icon="Receipt" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ totals.orders_count }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            {{ totals.orders_count === 1 ? 'Venta' : 'Ventas' }}
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="HandCoins" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-success">
                            {{ money(totals.tip) }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Propinas
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                    >
                        <Lucide icon="TicketPercent" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium">
                            {{ money(totals.discount) }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Descuentos
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtros y ventas en la misma caja: el filtro pegado a lo que
                 filtra. -->
            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <Lucide icon="Receipt" class="h-4 w-4 text-slate-400" />
                        Ventas
                        <span
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-darkmode-400"
                        >
                            {{ orders.total }}
                        </span>
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
                    class="border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="mb-3 flex items-center gap-2.5">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Filter" class="h-4 w-4" />
                        </div>
                        <div>
                            <div class="text-sm font-medium">
                                Acota el historial
                            </div>
                            <div class="text-xs text-slate-500">
                                Por fecha, encargado, método de pago o estado.
                            </div>
                        </div>
                    </div>
                    <div
                        class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 xl:grid-cols-5"
                    >
                        <div>
                            <FormLabel htmlFor="f-from">Desde</FormLabel>
                            <FormDate
                                id="f-from"
                                v-model="form.from"
                                input-class="h-9 text-xs"
                            />
                        </div>
                        <div>
                            <FormLabel htmlFor="f-to">Hasta</FormLabel>
                            <FormDate
                                id="f-to"
                                v-model="form.to"
                                input-class="h-9 text-xs"
                            />
                        </div>
                        <div>
                            <FormLabel htmlFor="f-user">Encargado</FormLabel>
                            <FormSelect
                                id="f-user"
                                v-model="form.user"
                                class="h-9 text-xs"
                            >
                                <option value="">Todos</option>
                                <option
                                    v-for="u in staff"
                                    :key="u.id"
                                    :value="u.id"
                                >
                                    {{ u.name }}
                                </option>
                            </FormSelect>
                        </div>
                        <div>
                            <FormLabel htmlFor="f-method">Método</FormLabel>
                            <FormSelect
                                id="f-method"
                                v-model="form.method"
                                class="h-9 text-xs"
                            >
                                <option value="">Todos</option>
                                <option value="cash">Efectivo</option>
                                <option value="card">Tarjeta</option>
                                <option value="transfer">Transferencia</option>
                                <option value="room">Cargo a habitación</option>
                            </FormSelect>
                        </div>
                        <div>
                            <FormLabel htmlFor="f-status">Estado</FormLabel>
                            <FormSelect
                                id="f-status"
                                v-model="form.status"
                                class="h-9 text-xs"
                            >
                                <option value="">Todas</option>
                                <option value="completed">Completadas</option>
                                <option value="void">Canceladas</option>
                            </FormSelect>
                        </div>
                    </div>
                </div>

                <div
                    v-if="orders.data.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="o in orders.data"
                        :key="o.id"
                        class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1.5 px-4 py-3 transition hover:bg-slate-50/70 sm:px-5 dark:hover:bg-darkmode-700/30"
                        :class="o.is_void ? 'opacity-60' : ''"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-darkmode-400"
                                >
                                    #{{ o.id }}
                                </span>
                                <span
                                    class="text-xs font-medium"
                                    :class="o.is_void ? 'line-through' : ''"
                                >
                                    {{ o.summary }}
                                </span>
                                <span
                                    v-if="o.room"
                                    class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                >
                                    Hab. {{ o.room }}
                                </span>
                                <span
                                    v-if="o.is_void"
                                    class="rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                >
                                    Cancelada
                                </span>
                            </div>
                            <div
                                class="mt-0.5 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-xs text-slate-500"
                            >
                                <span>{{ o.created_at }}</span>
                                <span>
                                    {{
                                        methodLabels[o.payment_method] ??
                                        o.payment_method
                                    }}
                                </span>
                                <span v-if="o.by">{{ o.by }}</span>
                                <span v-if="o.tip > 0" class="text-success">
                                    Propina {{ money(o.tip) }}
                                </span>
                                <span v-if="o.discount > 0">
                                    Descuento {{ money(o.discount) }}
                                </span>
                            </div>
                            <p
                                v-if="o.is_void && o.void_reason"
                                class="text-xs text-slate-400"
                            >
                                {{ o.void_reason }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5">
                            <span
                                class="text-sm font-medium"
                                :class="o.is_void ? 'line-through' : ''"
                            >
                                {{ money(o.total) }}
                            </span>
                            <button
                                type="button"
                                title="Imprimir ticket"
                                class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                @click="openTicket(o.id)"
                            >
                                <Lucide icon="Printer" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-2.5 px-4 py-10 text-center"
                >
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-info/10 text-info"
                    >
                        <Lucide icon="Receipt" class="h-5 w-5" />
                    </div>
                    <p class="text-xs text-slate-500">
                        Sin ventas en este periodo.
                    </p>
                    <button
                        v-if="filtersActive"
                        type="button"
                        class="text-xs font-medium text-primary hover:underline"
                        @click="clearFilters"
                    >
                        Limpiar filtros
                    </button>
                </div>

                <div
                    v-if="orders.links.length > 3"
                    class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <p class="text-xs text-slate-500">
                        {{ orders.total }}
                        {{ orders.total === 1 ? 'venta' : 'ventas' }}
                        en el periodo
                    </p>
                    <nav class="flex flex-wrap items-center gap-1">
                        <template v-for="(link, i) in orders.links" :key="i">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-scroll
                                class="rounded-md px-2.5 py-1 text-xs transition"
                                :class="
                                    link.active
                                        ? 'bg-primary text-white'
                                        : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-darkmode-400'
                                "
                            >
                                <span v-html="link.label" />
                            </Link>
                        </template>
                    </nav>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
