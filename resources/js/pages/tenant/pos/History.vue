<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
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

const form = reactive({
    from: props.filters.from,
    to: props.filters.to,
    user: props.filters.user ?? '',
    method: props.filters.method ?? '',
    status: props.filters.status ?? '',
});

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
            <div
                class="box box--stacked flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary sm:h-14 sm:w-14"
                    >
                        <Lucide icon="Receipt" class="h-5 w-5 sm:h-7 sm:w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-lg font-medium sm:text-xl">
                            Historial de ventas
                        </h1>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ property.name }}
                        </p>
                    </div>
                </div>
                <Button
                    as="a"
                    :href="route('tenant.pos')"
                    variant="outline-secondary"
                    class="w-full rounded-[0.5rem] bg-white md:w-auto"
                >
                    <Lucide
                        icon="ShoppingCart"
                        class="mr-2 h-4 w-4 stroke-[1.3]"
                    />
                    Volver al punto de venta
                </Button>
            </div>

            <!-- Totales del periodo filtrado, no de la página a la vista -->
            <div class="mt-5 grid auto-rows-fr grid-cols-12 gap-4 sm:gap-5">
                <div
                    class="box box--stacked col-span-6 p-4 sm:p-5 xl:col-span-3"
                >
                    <div class="text-sm text-slate-500">Vendido</div>
                    <div class="mt-1.5 text-2xl font-medium">
                        {{ money(totals.total) }}
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 p-4 sm:p-5 xl:col-span-3"
                >
                    <div class="text-sm text-slate-500">Ventas</div>
                    <div class="mt-1.5 text-2xl font-medium">
                        {{ totals.orders_count }}
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 p-4 sm:p-5 xl:col-span-3"
                >
                    <div class="text-sm text-slate-500">Propinas</div>
                    <div class="mt-1.5 text-2xl font-medium text-success">
                        {{ money(totals.tip) }}
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 p-4 sm:p-5 xl:col-span-3"
                >
                    <div class="text-sm text-slate-500">Descuentos</div>
                    <div class="mt-1.5 text-2xl font-medium">
                        {{ money(totals.discount) }}
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="box box--stacked mt-5 p-4 sm:p-5">
                <div
                    class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5"
                >
                    <div>
                        <label
                            for="f-from"
                            class="mb-1.5 block text-sm text-slate-500"
                            >Desde</label
                        >
                        <FormInput
                            id="f-from"
                            v-model="form.from"
                            type="date"
                        />
                    </div>
                    <div>
                        <label
                            for="f-to"
                            class="mb-1.5 block text-sm text-slate-500"
                            >Hasta</label
                        >
                        <FormInput id="f-to" v-model="form.to" type="date" />
                    </div>
                    <div>
                        <label
                            for="f-user"
                            class="mb-1.5 block text-sm text-slate-500"
                            >Encargado</label
                        >
                        <FormSelect id="f-user" v-model="form.user">
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
                        <label
                            for="f-method"
                            class="mb-1.5 block text-sm text-slate-500"
                            >Método</label
                        >
                        <FormSelect id="f-method" v-model="form.method">
                            <option value="">Todos</option>
                            <option value="cash">Efectivo</option>
                            <option value="card">Tarjeta</option>
                            <option value="transfer">Transferencia</option>
                            <option value="room">Cargo a habitación</option>
                        </FormSelect>
                    </div>
                    <div>
                        <label
                            for="f-status"
                            class="mb-1.5 block text-sm text-slate-500"
                            >Estado</label
                        >
                        <FormSelect id="f-status" v-model="form.status">
                            <option value="">Todas</option>
                            <option value="completed">Completadas</option>
                            <option value="void">Canceladas</option>
                        </FormSelect>
                    </div>
                </div>
            </div>

            <!-- Ventas -->
            <div
                v-if="orders.data.length"
                class="box box--stacked mt-5 divide-y divide-slate-100 dark:divide-darkmode-400"
            >
                <div
                    v-for="o in orders.data"
                    :key="o.id"
                    class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 p-4 sm:p-5"
                    :class="o.is_void ? 'opacity-60' : ''"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="text-sm text-slate-400"
                                >#{{ o.id }}</span
                            >
                            <span
                                class="text-sm"
                                :class="o.is_void ? 'line-through' : ''"
                                >{{ o.summary }}</span
                            >
                            <span
                                v-if="o.room"
                                class="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary"
                                >Hab. {{ o.room }}</span
                            >
                            <span
                                v-if="o.is_void"
                                class="rounded-full bg-danger/10 px-2 py-0.5 text-xs text-danger"
                                >Cancelada</span
                            >
                        </div>
                        <div
                            class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-xs text-slate-500"
                        >
                            <span>{{ o.created_at }}</span>
                            <span>{{
                                methodLabels[o.payment_method] ??
                                o.payment_method
                            }}</span>
                            <span v-if="o.by">{{ o.by }}</span>
                            <span v-if="o.tip > 0" class="text-success"
                                >Propina {{ money(o.tip) }}</span
                            >
                            <span v-if="o.discount > 0"
                                >Descuento {{ money(o.discount) }}</span
                            >
                        </div>
                        <p
                            v-if="o.is_void && o.void_reason"
                            class="mt-1 text-xs text-slate-400"
                        >
                            {{ o.void_reason }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <span
                            class="text-base font-medium"
                            :class="o.is_void ? 'line-through' : ''"
                            >{{ money(o.total) }}</span
                        >
                        <button
                            type="button"
                            title="Imprimir ticket"
                            class="text-slate-400 transition hover:text-primary"
                            @click="openTicket(o.id)"
                        >
                            <Lucide icon="Printer" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-else
                class="box box--stacked mt-5 flex flex-col items-center gap-3 px-5 py-14 text-center"
            >
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-full bg-info/10 text-info"
                >
                    <Lucide icon="Receipt" class="h-6 w-6" />
                </div>
                <p class="text-sm text-slate-500">
                    Sin ventas en este periodo.
                </p>
            </div>

            <div
                v-if="orders.links.length > 3"
                class="mt-5 flex flex-wrap items-center justify-between gap-4"
            >
                <p class="text-sm text-slate-500">
                    {{ orders.total }}
                    {{ orders.total === 1 ? 'venta' : 'ventas' }}
                </p>
                <nav class="flex flex-wrap items-center gap-1.5">
                    <template v-for="(link, i) in orders.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="flex h-10 min-w-10 items-center justify-center rounded-lg border px-3 text-sm font-medium transition"
                            :class="
                                link.active
                                    ? 'border-primary bg-primary text-white'
                                    : 'border-slate-200 text-slate-500 hover:border-primary/40 hover:text-primary dark:border-darkmode-400'
                            "
                        >
                            <span v-html="link.label" />
                        </Link>
                    </template>
                </nav>
            </div>
        </div>
    </RazeLayout>
</template>
