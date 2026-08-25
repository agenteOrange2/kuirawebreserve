<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

/**
 * Ficha de una llegada a pie, hermana de la del vehículo. Quien entra a pie
 * no tiene entidad propia: la visita ES la estancia, así que aquí se junta lo
 * que se le pidió en la caseta (nombre e identificación) con lo que dejó
 * (hospedaje y consumos).
 */
interface OrderRow {
    id: number;
    stay_id: number;
    room: string | null;
    created_at: string;
    total: number;
    items: string[];
    items_total: number;
    payment_method: string;
    charged_to_room: boolean;
    settled: boolean;
}

const props = defineProps<{
    arrival: {
        id: number;
        guest_name: string;
        guest: { id: number; full_name: string } | null;
        id_document_type: string | null;
        documents: string[];
        room: string | null;
        rate_plan: string | null;
        num_people: number;
        check_in_at: string;
        check_out_at: string | null;
        planned_end_at: string | null;
        status: string;
        is_inside: boolean;
        amount: number;
        notes: string | null;
    };
    orders: OrderRow[];
    hasPos: boolean;
    ordersTotal: number;
    ordersPending: number;
    canViewDocuments: boolean;
}>();

const money = (value: number) =>
    `$${Number(value).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

const documentLabels: Record<string, string> = {
    ine: 'INE',
    pasaporte: 'Pasaporte',
    licencia: 'Licencia',
    otro: 'Otro documento',
};

const paymentLabels: Record<string, string> = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
    room: 'A la habitación',
};

// Tiempo real, igual que la ficha del vehículo: si la habitación sigue en uso,
// lo que se le cargue en el POS o su salida aparecen sin recargar a mano.
let timer: number | null = null;

function refresh(): void {
    if (document.hidden) {
        return;
    }

    router.reload({
        only: ['arrival', 'orders', 'ordersTotal', 'ordersPending'],
    });
}

function onVisibility(): void {
    if (!document.hidden) {
        refresh();
    }
}

onMounted(() => {
    timer = window.setInterval(refresh, 45000);
    document.addEventListener('visibilitychange', onVisibility);
});

onBeforeUnmount(() => {
    if (timer) {
        window.clearInterval(timer);
    }
    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
    <RazeLayout :title="`Llegada a pie · ${arrival.guest_name}`">
        <div class="mt-5 grid grid-cols-12 gap-5">
            <!-- Encabezado, misma estructura que la ficha del vehículo -->
            <div class="box box--stacked col-span-12 p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-4">
                        <div
                            class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-white shadow-md"
                        >
                            <Lucide icon="Footprints" class="h-8 w-8" />
                        </div>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-xl font-medium">
                                    {{ arrival.guest_name }}
                                </h1>
                                <span
                                    v-if="arrival.is_inside"
                                    class="inline-flex items-center gap-2 rounded-full bg-success/10 px-3 py-1 text-sm font-medium text-success"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full bg-success"
                                    />
                                    Adentro ahora
                                </span>
                                <span
                                    v-else
                                    class="rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-500 dark:bg-darkmode-400"
                                    >Estancia cerrada</span
                                >
                            </div>
                            <p class="mt-1 text-sm text-slate-500">
                                Llegada a pie · entró {{ arrival.check_in_at }}
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            as="a"
                            :href="`${route('tenant.vehicles')}?tab=pie`"
                            variant="outline-secondary"
                            class="min-h-11 rounded-[0.5rem] bg-white"
                        >
                            <Lucide
                                icon="ArrowLeft"
                                class="mr-2 h-5 w-5 stroke-[1.5]"
                            />
                            Llegadas a pie
                        </Button>
                        <Button
                            v-if="arrival.guest"
                            as="a"
                            :href="
                                route('tenant.guests.show', arrival.guest.id)
                            "
                            variant="primary"
                            class="min-h-11 rounded-[0.5rem] shadow-md shadow-primary/20"
                        >
                            <Lucide
                                icon="User"
                                class="mr-2 h-5 w-5 stroke-[1.5]"
                            />
                            Ver huésped
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Métricas -->
            <div class="box box--stacked col-span-6 p-5 xl:col-span-3">
                <div class="flex items-center justify-between">
                    <div
                        class="flex h-14 w-14 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                    >
                        <Lucide
                            icon="DoorClosed"
                            class="h-7 w-7 text-primary"
                        />
                    </div>
                    <div class="text-2xl font-medium">
                        {{ arrival.room ?? '—' }}
                    </div>
                </div>
                <div class="mt-3 text-sm text-slate-500">Habitación</div>
            </div>
            <div class="box box--stacked col-span-6 p-5 xl:col-span-3">
                <div class="flex items-center justify-between">
                    <div
                        class="flex h-14 w-14 items-center justify-center rounded-full border border-success/10 bg-success/10"
                    >
                        <Lucide
                            icon="CircleDollarSign"
                            class="h-7 w-7 text-success"
                        />
                    </div>
                    <div class="text-2xl font-medium">
                        {{ money(arrival.amount) }}
                    </div>
                </div>
                <div class="mt-3 text-sm text-slate-500">Hospedaje</div>
            </div>
            <div class="box box--stacked col-span-6 p-5 xl:col-span-3">
                <div class="flex items-center justify-between">
                    <div
                        class="flex h-14 w-14 items-center justify-center rounded-full border border-info/10 bg-info/10"
                    >
                        <Lucide icon="Users" class="h-7 w-7 text-info" />
                    </div>
                    <div class="text-2xl font-medium">
                        {{ arrival.num_people }}
                    </div>
                </div>
                <div class="mt-3 text-sm text-slate-500">
                    {{ arrival.num_people === 1 ? 'Persona' : 'Personas' }}
                </div>
            </div>
            <div class="box box--stacked col-span-6 p-5 xl:col-span-3">
                <div class="flex items-center justify-between">
                    <div
                        class="flex h-14 w-14 items-center justify-center rounded-full border border-pending/10 bg-pending/10"
                    >
                        <Lucide
                            icon="ShoppingBag"
                            class="h-7 w-7 text-pending"
                        />
                    </div>
                    <div class="text-2xl font-medium">
                        {{ hasPos ? money(ordersTotal) : '—' }}
                    </div>
                </div>
                <div class="mt-3 text-sm text-slate-500">Consumos</div>
            </div>

            <!-- Quién llegó -->
            <div class="box box--stacked col-span-12 p-5 xl:col-span-5">
                <h2 class="text-base font-medium">Quién llegó</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Lo que se le pidió en la caseta: su nombre y una
                    identificación.
                </p>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-slate-500">Nombre</dt>
                        <dd class="text-right font-medium">
                            {{ arrival.guest_name }}
                        </dd>
                    </div>
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-slate-500">Identificación</dt>
                        <dd>
                            {{
                                documentLabels[
                                    arrival.id_document_type ?? ''
                                ] ?? 'Documento'
                            }}
                        </dd>
                    </div>
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-slate-500">Número</dt>
                        <dd class="text-right text-slate-500">
                            Cifrado, no se muestra
                        </dd>
                    </div>
                </dl>

                <div class="mt-4">
                    <div class="text-sm font-medium">
                        Fotos de la identificación
                    </div>
                    <div
                        v-if="arrival.documents.length"
                        class="mt-2 flex flex-wrap gap-2"
                    >
                        <a
                            v-for="(doc, index) in arrival.documents"
                            :key="doc"
                            :href="doc"
                            target="_blank"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/70 px-3 py-2 text-sm text-primary transition hover:bg-slate-50 dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                        >
                            <Lucide icon="Image" class="h-4 w-4" />
                            {{ index === 0 ? 'Frente' : `Foto ${index + 1}` }}
                        </a>
                    </div>
                    <p v-else class="mt-2 text-sm text-slate-500">
                        {{
                            canViewDocuments
                                ? 'Esta llegada no dejó fotos del documento.'
                                : 'Las fotos solo las ve quien tiene el permiso de documentos.'
                        }}
                    </p>
                </div>

                <div
                    v-if="arrival.notes"
                    class="mt-4 border-t border-slate-200/70 pt-4 text-sm dark:border-darkmode-400"
                >
                    <div class="text-slate-500">Notas</div>
                    <p class="mt-1 text-slate-600 dark:text-slate-300">
                        {{ arrival.notes }}
                    </p>
                </div>
            </div>

            <!-- La estancia -->
            <div class="box box--stacked col-span-12 p-5 xl:col-span-7">
                <h2 class="text-base font-medium">La estancia</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Dónde se quedó y qué dejó de hospedaje.
                </p>

                <div class="mt-4 overflow-auto">
                    <Table class="text-sm">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th class="whitespace-nowrap"
                                    >Habitación</Table.Th
                                >
                                <Table.Th class="whitespace-nowrap"
                                    >Entrada</Table.Th
                                >
                                <Table.Th class="whitespace-nowrap"
                                    >Salida</Table.Th
                                >
                                <Table.Th class="whitespace-nowrap"
                                    >Tarifa</Table.Th
                                >
                                <Table.Th class="text-right whitespace-nowrap"
                                    >Hospedaje</Table.Th
                                >
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr>
                                <Table.Td class="font-medium">
                                    {{ arrival.room ?? '—' }}
                                    <span
                                        v-if="arrival.is_inside"
                                        class="ml-2 rounded-full bg-success/10 px-2 py-0.5 text-xs text-success"
                                        >En curso</span
                                    >
                                </Table.Td>
                                <Table.Td
                                    class="whitespace-nowrap text-slate-500"
                                    >{{ arrival.check_in_at }}</Table.Td
                                >
                                <Table.Td
                                    class="whitespace-nowrap text-slate-500"
                                >
                                    {{
                                        arrival.check_out_at ??
                                        (arrival.planned_end_at
                                            ? `prevista ${arrival.planned_end_at}`
                                            : '—')
                                    }}
                                </Table.Td>
                                <Table.Td class="text-slate-500">{{
                                    arrival.rate_plan ?? '—'
                                }}</Table.Td>
                                <Table.Td class="text-right">{{
                                    money(arrival.amount)
                                }}</Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </div>
            </div>

            <!-- Consumos del POS: solo si el hotel tiene el módulo -->
            <div v-if="hasPos" class="box box--stacked col-span-12 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-medium">Consumos</h2>
                        <p class="mt-1 max-w-2xl text-sm text-slate-500">
                            Lo que se le cargó desde el punto de venta. Cada
                            venta entra al corte de caja del turno: cobrada si
                            se pagó al momento, y como pendiente mientras siga
                            cargada a la habitación.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-sm">
                        <span
                            class="rounded-full bg-slate-100 px-3 py-1 font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-200"
                            >Total {{ money(ordersTotal) }}</span
                        >
                        <span
                            v-if="ordersPending > 0"
                            class="rounded-full bg-pending/10 px-3 py-1 font-medium text-pending"
                            >Por cobrar {{ money(ordersPending) }}</span
                        >
                    </div>
                </div>

                <div class="mt-4 overflow-auto">
                    <Table v-if="orders.length" class="text-sm">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th class="whitespace-nowrap"
                                    >Venta</Table.Th
                                >
                                <Table.Th>Productos</Table.Th>
                                <Table.Th class="whitespace-nowrap"
                                    >Cobro</Table.Th
                                >
                                <Table.Th class="text-right whitespace-nowrap"
                                    >Importe</Table.Th
                                >
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="order in orders" :key="order.id">
                                <Table.Td class="whitespace-nowrap">
                                    <div class="font-medium">
                                        #{{ order.id }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ order.created_at }}
                                    </div>
                                </Table.Td>
                                <Table.Td
                                    class="text-slate-600 dark:text-slate-300"
                                >
                                    {{ order.items.join(' · ') }}
                                    <span
                                        v-if="
                                            order.items_total >
                                            order.items.length
                                        "
                                        class="text-slate-400"
                                        >y
                                        {{
                                            order.items_total -
                                            order.items.length
                                        }}
                                        más</span
                                    >
                                </Table.Td>
                                <Table.Td class="whitespace-nowrap">
                                    <span
                                        v-if="order.settled"
                                        class="rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success"
                                        >Cobrado ·
                                        {{
                                            paymentLabels[
                                                order.payment_method
                                            ] ?? order.payment_method
                                        }}</span
                                    >
                                    <span
                                        v-else
                                        class="rounded-full bg-pending/10 px-2 py-0.5 text-xs font-medium text-pending"
                                        >Cargado a la habitación</span
                                    >
                                </Table.Td>
                                <Table.Td class="text-right">{{
                                    money(order.total)
                                }}</Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <p v-else class="py-6 text-center text-sm text-slate-500">
                        Esta llegada no consumió nada en el punto de venta.
                    </p>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
