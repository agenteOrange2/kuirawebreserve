<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormLabel,
    FormSwitch,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

/**
 * Ficha de un vehículo, con la misma estructura de la ficha de huésped:
 * encabezado con identidad y acciones, tarjetas de métricas, datos, historial
 * de entradas y —si el hotel tiene POS— lo que consumió cada visita.
 *
 * La placa no se edita: es la identidad de la ficha; corregir una mal tecleada
 * sería fusionar dos fichas, y eso es otra herramienta.
 */
interface StayRow {
    id: number;
    room: string | null;
    rate_plan: string | null;
    guest: string | null;
    guest_id: number | null;
    plate_used: string | null;
    check_in_at: string;
    check_out_at: string | null;
    status: string;
    amount: number;
    consumos: number;
    id_document_type: string | null;
    documents: string[];
}

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
    vehicle: {
        id: number;
        plate: string;
        brand: string | null;
        model: string | null;
        color: string | null;
        year: number | null;
        label: string | null;
        notes: string | null;
        is_blacklisted: boolean;
        blacklist_reason: string | null;
        is_archived: boolean;
        guest: { id: number; full_name: string } | null;
        created_at: string;
    };
    metrics: {
        visits: number;
        is_inside: boolean;
        total_spent: number;
        first_visit: string | null;
        last_visit: string | null;
    };
    stays: StayRow[];
    orders: OrderRow[];
    hasPos: boolean;
    ordersTotal: number;
    ordersPending: number;
    canManage: boolean;
    canViewDocuments: boolean;
}>();

const toast = useToasts();
const money = (value: number) =>
    `$${Number(value).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

const paymentLabels: Record<string, string> = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
    room: 'A la habitación',
};

// Tiempo real: si el carro está adentro, lo que se le cargue en el POS o su
// salida tienen que verse sin recargar a mano — y si alguien abre la ficha
// mucho después, al volver a la pestaña se refresca sola.
let timer: number | null = null;

function refresh(): void {
    if (document.hidden) {
        return;
    }

    router.reload({
        only: ['metrics', 'stays', 'orders', 'ordersTotal', 'ordersPending'],
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

// ── Editar ──
const showEdit = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string>>({});

const form = reactive({
    brand: props.vehicle.brand ?? '',
    model: props.vehicle.model ?? '',
    color: props.vehicle.color ?? '',
    year: props.vehicle.year ?? ('' as number | string),
    notes: props.vehicle.notes ?? '',
    is_blacklisted: props.vehicle.is_blacklisted,
    blacklist_reason: props.vehicle.blacklist_reason ?? '',
});

async function save(): Promise<void> {
    saving.value = true;
    errors.value = {};

    try {
        await axios.patch(`/api/vehicles/${props.vehicle.id}`, {
            brand: form.brand || null,
            model: form.model || null,
            color: form.color || null,
            year: form.year === '' ? null : Number(form.year),
            notes: form.notes || null,
            is_blacklisted: form.is_blacklisted,
            blacklist_reason: form.is_blacklisted
                ? form.blacklist_reason || null
                : null,
        });
        showEdit.value = false;
        toast.success('Vehículo actualizado', `Placa ${props.vehicle.plate}`);
        router.reload({ only: ['vehicle'] });
    } catch (error: any) {
        errors.value = error.response?.data?.errors ?? {};
        toast.error(
            'No se pudo guardar',
            error.response?.data?.message ?? 'Revisa los datos.',
        );
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <RazeLayout :title="`Vehículo ${vehicle.plate}`">
        <div class="mt-5 grid grid-cols-12 gap-5">
            <!-- Encabezado, con la misma estructura de la ficha de huésped -->
            <div class="box box--stacked col-span-12 p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-4">
                        <div
                            class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-white shadow-md"
                        >
                            <Lucide icon="Car" class="h-8 w-8" />
                        </div>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-xl font-medium tracking-wide">
                                    {{ vehicle.plate }}
                                </h1>
                                <span
                                    v-if="metrics.is_inside"
                                    class="inline-flex items-center gap-2 rounded-full bg-success/10 px-3 py-1 text-sm font-medium text-success"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full bg-success"
                                    />
                                    Adentro ahora
                                </span>
                                <span
                                    v-if="vehicle.is_blacklisted"
                                    class="rounded-full bg-danger/10 px-3 py-1 text-sm font-medium text-danger"
                                    >Vetada</span
                                >
                                <span
                                    v-if="vehicle.is_archived"
                                    class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-500 dark:bg-darkmode-400"
                                >
                                    <Lucide icon="Archive" class="h-4 w-4" />
                                    Archivada
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ vehicle.label ?? 'Sin datos del vehículo' }}
                                · registrada desde {{ vehicle.created_at }}
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            as="a"
                            :href="route('tenant.vehicles')"
                            variant="outline-secondary"
                            class="min-h-11 rounded-[0.5rem] bg-white"
                        >
                            <Lucide
                                icon="ArrowLeft"
                                class="mr-2 h-5 w-5 stroke-[1.5]"
                            />
                            Vehículos
                        </Button>
                        <Button
                            v-if="canManage && !vehicle.is_archived"
                            variant="primary"
                            class="min-h-11 rounded-[0.5rem] shadow-md shadow-primary/20"
                            @click="showEdit = true"
                        >
                            <Lucide
                                icon="Pencil"
                                class="mr-2 h-5 w-5 stroke-[1.5]"
                            />
                            Editar datos
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Aviso de veto, a lo ancho para que no se pase por alto -->
            <div
                v-if="vehicle.is_blacklisted"
                class="box box--stacked col-span-12 flex items-start gap-3 border-l-4 border-danger p-5"
            >
                <Lucide
                    icon="ShieldAlert"
                    class="mt-0.5 h-5 w-5 shrink-0 text-danger"
                />
                <div>
                    <div class="font-medium">Placa vetada</div>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                        {{
                            vehicle.blacklist_reason ??
                            'Sin motivo capturado; revisa con el encargado.'
                        }}
                    </p>
                </div>
            </div>

            <!-- Métricas -->
            <div class="box box--stacked col-span-6 p-5 xl:col-span-3">
                <div class="flex items-center justify-between">
                    <div
                        class="flex h-14 w-14 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                    >
                        <Lucide icon="BedDouble" class="h-7 w-7 text-primary" />
                    </div>
                    <div class="text-2xl font-medium">{{ metrics.visits }}</div>
                </div>
                <div class="mt-3 text-sm text-slate-500">
                    Entradas completadas
                </div>
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
                        {{ money(metrics.total_spent) }}
                    </div>
                </div>
                <div class="mt-3 text-sm text-slate-500">
                    Hospedaje y consumos
                </div>
            </div>
            <div class="box box--stacked col-span-6 p-5 xl:col-span-3">
                <div class="flex items-center justify-between">
                    <div
                        class="flex h-14 w-14 items-center justify-center rounded-full border border-info/10 bg-info/10"
                    >
                        <Lucide
                            icon="CalendarClock"
                            class="h-7 w-7 text-info"
                        />
                    </div>
                    <div class="text-lg font-medium">
                        {{ metrics.first_visit ?? '—' }}
                    </div>
                </div>
                <div class="mt-3 text-sm text-slate-500">Primera visita</div>
            </div>
            <div class="box box--stacked col-span-6 p-5 xl:col-span-3">
                <div class="flex items-center justify-between">
                    <div
                        class="flex h-14 w-14 items-center justify-center rounded-full border border-pending/10 bg-pending/10"
                    >
                        <Lucide icon="History" class="h-7 w-7 text-pending" />
                    </div>
                    <div class="text-lg font-medium">
                        {{ metrics.last_visit ?? '—' }}
                    </div>
                </div>
                <div class="mt-3 text-sm text-slate-500">Última visita</div>
            </div>

            <!-- Datos del vehículo -->
            <div class="box box--stacked col-span-12 p-5 xl:col-span-5">
                <h2 class="text-base font-medium">Datos del vehículo</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Lo que se capturó de este carro en la caseta.
                </p>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-slate-500">Placa</dt>
                        <dd class="font-semibold tracking-wide">
                            {{ vehicle.plate }}
                        </dd>
                    </div>
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-slate-500">Marca</dt>
                        <dd>{{ vehicle.brand ?? 'Sin capturar' }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-slate-500">Modelo</dt>
                        <dd>{{ vehicle.model ?? 'Sin capturar' }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-slate-500">Color</dt>
                        <dd>{{ vehicle.color ?? 'Sin capturar' }}</dd>
                    </div>
                    <div
                        v-if="vehicle.year"
                        class="flex items-start justify-between gap-4"
                    >
                        <dt class="text-slate-500">Año</dt>
                        <dd>{{ vehicle.year }}</dd>
                    </div>
                    <div
                        v-if="vehicle.notes"
                        class="border-t border-slate-200/70 pt-3 dark:border-darkmode-400"
                    >
                        <dt class="text-slate-500">Notas</dt>
                        <dd class="mt-1 text-slate-600 dark:text-slate-300">
                            {{ vehicle.notes }}
                        </dd>
                    </div>
                </dl>

                <div
                    v-if="vehicle.guest"
                    class="mt-4 flex items-center justify-between gap-3 rounded-xl border border-slate-200/70 p-3.5 dark:border-darkmode-400"
                >
                    <div class="min-w-0">
                        <div class="text-xs text-slate-500 uppercase">
                            Ficha de huésped ligada
                        </div>
                        <div class="truncate font-medium">
                            {{ vehicle.guest.full_name }}
                        </div>
                    </div>
                    <Button
                        as="a"
                        :href="route('tenant.guests.show', vehicle.guest.id)"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] whitespace-nowrap"
                        >Ver</Button
                    >
                </div>
            </div>

            <!-- Historial de entradas -->
            <div class="box box--stacked col-span-12 p-5 xl:col-span-7">
                <h2 class="text-base font-medium">Historial de entradas</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Cada vez que esta placa entró, con lo que dejó de hospedaje.
                </p>

                <div class="mt-4 overflow-auto">
                    <Table v-if="stays.length" class="text-sm">
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
                            <Table.Tr v-for="stay in stays" :key="stay.id">
                                <Table.Td class="font-medium">
                                    {{ stay.room ?? '—' }}
                                    <span
                                        v-if="stay.status === 'active'"
                                        class="ml-2 rounded-full bg-success/10 px-2 py-0.5 text-xs text-success"
                                        >En curso</span
                                    >
                                </Table.Td>
                                <Table.Td
                                    class="whitespace-nowrap text-slate-500"
                                    >{{ stay.check_in_at }}</Table.Td
                                >
                                <Table.Td
                                    class="whitespace-nowrap text-slate-500"
                                    >{{ stay.check_out_at ?? '—' }}</Table.Td
                                >
                                <Table.Td class="text-slate-500">{{
                                    stay.rate_plan ?? '—'
                                }}</Table.Td>
                                <Table.Td class="text-right">{{
                                    money(stay.amount)
                                }}</Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <p v-else class="py-6 text-center text-sm text-slate-500">
                        Esta placa todavía no tiene entradas registradas.
                    </p>
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
                                <Table.Th class="whitespace-nowrap"
                                    >Habitación</Table.Th
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
                                <Table.Td class="whitespace-nowrap">{{
                                    order.room ?? '—'
                                }}</Table.Td>
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
                        Este vehículo no ha consumido nada en el punto de venta.
                    </p>
                </div>
            </div>
        </div>

        <!-- Editar datos -->
        <Dialog :open="showEdit" size="lg" @close="showEdit = false">
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="save">
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                        >
                            <Lucide icon="Car" class="h-5 w-5 text-primary" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                Vehículo {{ vehicle.plate }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                La placa no se edita: es la identidad de la
                                ficha.
                            </p>
                        </div>
                    </div>

                    <div class="space-y-4 px-6 py-5">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <FormLabel htmlFor="vehicle-brand"
                                    >Marca</FormLabel
                                >
                                <FormInput
                                    id="vehicle-brand"
                                    v-model="form.brand"
                                    type="text"
                                    placeholder="Nissan"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="vehicle-model"
                                    >Modelo</FormLabel
                                >
                                <FormInput
                                    id="vehicle-model"
                                    v-model="form.model"
                                    type="text"
                                    placeholder="Versa"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="vehicle-color"
                                    >Color</FormLabel
                                >
                                <FormInput
                                    id="vehicle-color"
                                    v-model="form.color"
                                    type="text"
                                    placeholder="Gris"
                                />
                            </div>
                        </div>
                        <div>
                            <FormLabel htmlFor="vehicle-notes">Notas</FormLabel>
                            <FormTextarea
                                id="vehicle-notes"
                                v-model="form.notes"
                                placeholder="Lo que convenga recordar de este vehículo"
                            />
                        </div>
                        <div
                            class="rounded-lg border border-slate-200/70 p-4 dark:border-darkmode-400"
                        >
                            <FormSwitch>
                                <FormSwitch.Input
                                    v-model="form.is_blacklisted"
                                    type="checkbox"
                                />
                                <FormSwitch.Label class="ml-2 text-sm"
                                    >Vetar esta placa</FormSwitch.Label
                                >
                            </FormSwitch>
                            <div v-if="form.is_blacklisted" class="mt-3">
                                <FormLabel htmlFor="vehicle-reason"
                                    >Motivo</FormLabel
                                >
                                <FormInput
                                    id="vehicle-reason"
                                    v-model="form.blacklist_reason"
                                    type="text"
                                    placeholder="Por qué no se le vuelve a rentar"
                                />
                                <FormHelp
                                    v-if="errors.blacklist_reason"
                                    class="text-danger"
                                    >{{ errors.blacklist_reason }}</FormHelp
                                >
                                <FormHelp v-else
                                    >Al registrar una llegada con esta placa, el
                                    plano avisará.</FormHelp
                                >
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="rounded-[0.5rem]"
                            @click="showEdit = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="rounded-[0.5rem]"
                            :disabled="saving"
                            >{{ saving ? 'Guardando…' : 'Guardar' }}</Button
                        >
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
