<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
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
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

/**
 * Ficha de un vehículo, con la misma estructura que la ficha de un grupo:
 * pastilla para volver, encabezado en franjas (identidad, aviso, nota y datos
 * duros de la operación) y abajo dos columnas — el historial a la izquierda y
 * los datos del carro a la derecha.
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

/** Marca, modelo, color y año en pastillas sueltas: cada dato se lee solo. */
const specs = computed(() =>
    [
        { label: 'Marca', value: props.vehicle.brand },
        { label: 'Modelo', value: props.vehicle.model },
        { label: 'Color', value: props.vehicle.color },
        {
            label: 'Año',
            value: props.vehicle.year ? String(props.vehicle.year) : null,
        },
    ].filter((spec): spec is { label: string; value: string } => !!spec.value),
);

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
        <div class="mt-2">
            <!-- Encabezado en franjas separadas para que nada se amontone:
                 identidad y datos del carro, el aviso de veto, la nota, y los
                 datos duros de la operación. -->
            <div class="box box--stacked overflow-hidden">
                <div
                    class="flex flex-col gap-4 p-5 md:flex-row md:items-start md:justify-between"
                >
                    <div class="flex min-w-0 gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Car" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-base font-medium tracking-wide">
                                    {{ vehicle.plate }}
                                </h1>
                                <span
                                    v-if="metrics.is_inside"
                                    class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2.5 py-1 text-[11px] font-medium text-success"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full bg-success"
                                    />
                                    Adentro ahora
                                </span>
                                <span
                                    v-if="vehicle.is_blacklisted"
                                    class="inline-flex items-center gap-1 rounded-full bg-danger/10 px-2.5 py-1 text-[11px] font-medium text-danger"
                                >
                                    <Lucide
                                        icon="ShieldAlert"
                                        class="h-3.5 w-3.5"
                                    />
                                    Vetada
                                </span>
                                <span
                                    v-if="vehicle.is_archived"
                                    class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                >
                                    <Lucide
                                        icon="Archive"
                                        class="h-3.5 w-3.5"
                                    />
                                    Archivada
                                </span>
                            </div>
                            <!-- Datos del carro en pastillas: cada uno se lee
                                 solo y el huésped ligado se puede abrir. -->
                            <div
                                class="mt-2 flex flex-wrap items-center gap-1.5"
                            >
                                <span
                                    v-for="spec in specs"
                                    :key="spec.label"
                                    class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                    :title="spec.label"
                                >
                                    <span class="truncate">
                                        {{ spec.value }}
                                    </span>
                                </span>
                                <span
                                    v-if="!specs.length"
                                    class="text-xs text-slate-400"
                                >
                                    Sin datos del vehículo capturados
                                </span>
                                <Link
                                    v-if="vehicle.guest"
                                    :href="
                                        route(
                                            'tenant.guests.show',
                                            vehicle.guest.id,
                                        )
                                    "
                                    class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 transition hover:bg-primary/10 hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                    title="Ficha de huésped ligada"
                                >
                                    <Lucide
                                        icon="UserRound"
                                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                    />
                                    <span class="truncate">
                                        {{ vehicle.guest.full_name }}
                                    </span>
                                </Link>
                            </div>
                        </div>
                    </div>
                    <div
                        class="flex w-full flex-wrap items-center gap-2 md:w-auto md:shrink-0 md:justify-end"
                    >
                        <!-- El volver vive con las acciones, no flotando
                             encima de la tarjeta. -->
                        <Link
                            :href="route('tenant.vehicles')"
                            class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                        >
                            <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                            Volver a vehículos
                        </Link>
                        <Button
                            v-if="canManage && !vehicle.is_archived"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                            @click="showEdit = true"
                        >
                            <Lucide icon="Pencil" class="mr-1.5 h-3.5 w-3.5" />
                            Editar datos
                        </Button>
                    </div>
                </div>

                <!-- El veto tiene su propio renglón: quien abre la ficha en la
                     caseta no puede pasarlo por alto. -->
                <div
                    v-if="vehicle.is_blacklisted"
                    class="border-t border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex gap-2.5 rounded-lg border-l-4 border-danger bg-danger/5 px-3.5 py-3"
                    >
                        <Lucide
                            icon="ShieldAlert"
                            class="mt-0.5 h-4 w-4 shrink-0 text-danger"
                        />
                        <div class="min-w-0 flex-1">
                            <div class="text-[11px] font-medium text-danger">
                                PLACA VETADA
                            </div>
                            <p
                                class="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-300"
                            >
                                {{
                                    vehicle.blacklist_reason ??
                                    'Sin motivo capturado; revisa con el encargado.'
                                }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-if="vehicle.notes"
                    class="border-t border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex gap-2.5 rounded-lg bg-slate-50 px-3.5 py-3 dark:bg-darkmode-700"
                    >
                        <Lucide
                            icon="StickyNote"
                            class="mt-0.5 h-4 w-4 shrink-0 text-slate-400"
                        />
                        <div class="min-w-0 flex-1">
                            <div class="text-[11px] font-medium text-slate-400">
                                NOTAS DEL VEHÍCULO
                            </div>
                            <p
                                class="mt-1 text-xs leading-relaxed whitespace-pre-line text-slate-600 dark:text-slate-300"
                            >
                                {{ vehicle.notes }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Datos duros de la operación, con separadores para que no se
                     lean como un renglón corrido. -->
                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-slate-200/60 bg-slate-50/70 px-5 py-3 text-xs dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <span
                        class="inline-flex items-center gap-1.5 text-slate-500"
                    >
                        <Lucide
                            icon="BedDouble"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                        >
                            {{ metrics.visits }}
                        </span>
                        {{ metrics.visits === 1 ? 'entrada' : 'entradas' }}
                    </span>
                    <span
                        class="hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400"
                    />
                    <span
                        class="inline-flex items-center gap-1.5 text-slate-500"
                        title="Hospedaje y consumos de todas sus visitas"
                    >
                        <Lucide
                            icon="Wallet"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                        >
                            {{ money(metrics.total_spent) }}
                        </span>
                        dejados
                    </span>
                    <span
                        class="hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400"
                    />
                    <span
                        class="inline-flex items-center gap-1.5 text-slate-500"
                    >
                        <Lucide
                            icon="CalendarClock"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <template v-if="metrics.first_visit">
                            <span
                                class="font-medium text-slate-700 dark:text-slate-300"
                            >
                                {{ metrics.first_visit }}
                            </span>
                            <template v-if="metrics.last_visit">
                                <span class="text-slate-400">→</span>
                                <span
                                    class="font-medium text-slate-700 dark:text-slate-300"
                                >
                                    {{ metrics.last_visit }}
                                </span>
                            </template>
                        </template>
                        <template v-else>Sin visitas registradas</template>
                    </span>
                    <span
                        class="hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400"
                    />
                    <span
                        class="inline-flex items-center gap-1.5 text-slate-500"
                    >
                        <Lucide
                            icon="Clock"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        Registrada desde {{ vehicle.created_at }}
                    </span>
                    <span
                        v-if="hasPos"
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium md:ml-auto"
                        :class="
                            ordersPending > 0
                                ? 'bg-pending/10 text-pending'
                                : 'bg-success/10 text-success'
                        "
                    >
                        <Lucide
                            :icon="
                                ordersPending > 0
                                    ? 'ReceiptText'
                                    : 'CircleCheck'
                            "
                            class="h-3.5 w-3.5"
                        />
                        {{
                            ordersPending > 0
                                ? `Consumos por cobrar ${money(ordersPending)}`
                                : 'Sin consumos por cobrar'
                        }}
                    </span>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-12 gap-5">
                <!-- Historial y consumos -->
                <div class="col-span-12 xl:col-span-8">
                    <div class="box box--stacked">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-info/10 text-info"
                                >
                                    <Lucide icon="BedDouble" class="h-4 w-4" />
                                </div>
                                <div>
                                    <div class="font-medium">
                                        Historial de entradas
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        Cada vez que esta placa entró, con lo
                                        que dejó de hospedaje.
                                    </div>
                                </div>
                            </div>
                            <span
                                class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                            >
                                {{ stays.length }}
                            </span>
                        </div>
                        <div
                            v-if="stays.length"
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <article
                                v-for="stay in stays"
                                :key="stay.id"
                                class="grid gap-3 px-4 py-3 sm:px-5 md:grid-cols-[minmax(11rem,1.2fr)_minmax(10rem,1fr)_minmax(8rem,0.6fr)] md:items-center"
                            >
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-info/10 text-info"
                                    >
                                        <Lucide
                                            icon="DoorClosed"
                                            class="h-4 w-4"
                                        />
                                    </div>
                                    <div class="min-w-0">
                                        <div
                                            class="flex flex-wrap items-center gap-1.5"
                                        >
                                            <span class="text-sm font-medium">
                                                {{
                                                    stay.room
                                                        ? `Habitación ${stay.room}`
                                                        : 'Sin habitación'
                                                }}
                                            </span>
                                            <span
                                                v-if="stay.status === 'active'"
                                                class="rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success"
                                            >
                                                En curso
                                            </span>
                                        </div>
                                        <div
                                            class="truncate text-xs text-slate-500"
                                        >
                                            {{ stay.rate_plan ?? 'Sin tarifa' }}
                                            <template v-if="stay.guest">
                                                · {{ stay.guest }}
                                            </template>
                                        </div>
                                        <div
                                            v-if="
                                                canViewDocuments &&
                                                stay.documents.length
                                            "
                                            class="mt-1 flex flex-wrap gap-1"
                                        >
                                            <a
                                                v-for="(
                                                    doc, index
                                                ) in stay.documents"
                                                :key="doc"
                                                :href="doc"
                                                target="_blank"
                                                class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 transition hover:bg-primary/10 hover:text-primary dark:bg-darkmode-400"
                                            >
                                                <Lucide
                                                    icon="Image"
                                                    class="h-3 w-3"
                                                />
                                                {{
                                                    index === 0
                                                        ? 'Identificación'
                                                        : `Foto ${index + 1}`
                                                }}
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <div
                                        class="flex items-center gap-1.5 text-[11px] font-medium text-slate-400"
                                    >
                                        <Lucide
                                            icon="CalendarDays"
                                            class="h-3.5 w-3.5"
                                        />
                                        ESTANCIA
                                    </div>
                                    <div class="mt-0.5 text-xs font-medium">
                                        {{ stay.check_in_at }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{
                                            stay.check_out_at
                                                ? `Salió ${stay.check_out_at}`
                                                : 'Sin salida registrada'
                                        }}
                                    </div>
                                </div>

                                <div class="md:text-right">
                                    <div
                                        class="text-[11px] font-medium text-slate-400"
                                    >
                                        HOSPEDAJE
                                    </div>
                                    <div class="mt-0.5 text-sm font-semibold">
                                        {{ money(stay.amount) }}
                                    </div>
                                    <div
                                        v-if="stay.consumos > 0"
                                        class="text-xs text-slate-500"
                                    >
                                        + {{ money(stay.consumos) }} en consumos
                                    </div>
                                </div>
                            </article>
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 px-5 py-10 text-center"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                            >
                                <Lucide icon="BedDouble" class="h-7 w-7" />
                            </div>
                            <div class="text-sm font-medium">
                                Sin entradas registradas
                            </div>
                            <div class="text-xs text-slate-500">
                                Esta placa aparecerá aquí en cuanto entre por
                                primera vez.
                            </div>
                        </div>
                    </div>

                    <!-- Consumos del POS: solo si el hotel tiene el módulo -->
                    <div v-if="hasPos" class="box box--stacked mt-5">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-success/10 text-success"
                                >
                                    <Lucide
                                        icon="ShoppingBag"
                                        class="h-4 w-4"
                                    />
                                </div>
                                <div>
                                    <div class="font-medium">Consumos</div>
                                    <div class="text-xs text-slate-500">
                                        Lo que se le cargó desde el punto de
                                        venta; cada venta ya entra al corte del
                                        turno.
                                    </div>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-1.5 text-[11px]">
                                <span
                                    class="rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-200"
                                >
                                    Total {{ money(ordersTotal) }}
                                </span>
                                <span
                                    v-if="ordersPending > 0"
                                    class="rounded-full bg-pending/10 px-2.5 py-1 font-medium text-pending"
                                >
                                    Por cobrar {{ money(ordersPending) }}
                                </span>
                            </div>
                        </div>
                        <div
                            v-if="orders.length"
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <article
                                v-for="order in orders"
                                :key="order.id"
                                class="grid gap-3 px-4 py-3 sm:px-5 md:grid-cols-[minmax(9rem,0.8fr)_minmax(11rem,1.3fr)_minmax(8rem,0.6fr)] md:items-center"
                            >
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                                    >
                                        <Lucide
                                            icon="ShoppingBag"
                                            class="h-4 w-4"
                                        />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium">
                                            Venta #{{ order.id }}
                                        </div>
                                        <div
                                            class="truncate text-xs text-slate-500"
                                        >
                                            {{ order.created_at }}
                                            <template v-if="order.room">
                                                · Habitación {{ order.room }}
                                            </template>
                                        </div>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <div
                                        class="text-[11px] font-medium text-slate-400"
                                    >
                                        PRODUCTOS
                                    </div>
                                    <div
                                        class="mt-0.5 text-xs text-slate-600 dark:text-slate-300"
                                    >
                                        {{ order.items.join(' · ') }}
                                        <span
                                            v-if="
                                                order.items_total >
                                                order.items.length
                                            "
                                            class="text-slate-400"
                                        >
                                            y
                                            {{
                                                order.items_total -
                                                order.items.length
                                            }}
                                            más
                                        </span>
                                    </div>
                                </div>

                                <div class="md:text-right">
                                    <div class="text-sm font-semibold">
                                        {{ money(order.total) }}
                                    </div>
                                    <span
                                        v-if="order.settled"
                                        class="mt-1 inline-flex rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success"
                                    >
                                        Cobrado ·
                                        {{
                                            paymentLabels[
                                                order.payment_method
                                            ] ?? order.payment_method
                                        }}
                                    </span>
                                    <span
                                        v-else
                                        class="mt-1 inline-flex rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                    >
                                        Cargado a la habitación
                                    </span>
                                </div>
                            </article>
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 px-5 py-10 text-center"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                            >
                                <Lucide icon="ShoppingBag" class="h-7 w-7" />
                            </div>
                            <div class="text-sm font-medium">
                                Sin consumos en el punto de venta
                            </div>
                            <div class="text-xs text-slate-500">
                                Este vehículo no ha comprado nada en el POS.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Datos del vehículo -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="box box--stacked overflow-hidden">
                        <div
                            class="flex items-center gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Lucide icon="Car" class="h-4 w-4" />
                            </div>
                            <div>
                                <div class="font-medium">
                                    Datos del vehículo
                                </div>
                                <div class="text-xs text-slate-500">
                                    Lo que se capturó en la caseta.
                                </div>
                            </div>
                        </div>
                        <div class="p-4">
                            <div
                                class="rounded-xl bg-slate-50 p-3.5 dark:bg-darkmode-700"
                            >
                                <div class="text-xs text-slate-500">Placa</div>
                                <div
                                    class="mt-1 text-xl font-semibold tracking-wide"
                                >
                                    {{ vehicle.plate }}
                                </div>
                                <div class="mt-1 text-xs text-slate-500">
                                    {{
                                        vehicle.label ??
                                        'Sin marca ni modelo capturados'
                                    }}
                                </div>
                            </div>

                            <dl class="mt-4 space-y-2 text-sm">
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt class="text-slate-500">Marca</dt>
                                    <dd class="truncate">
                                        {{ vehicle.brand ?? 'Sin capturar' }}
                                    </dd>
                                </div>
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt class="text-slate-500">Modelo</dt>
                                    <dd class="truncate">
                                        {{ vehicle.model ?? 'Sin capturar' }}
                                    </dd>
                                </div>
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt class="text-slate-500">Color</dt>
                                    <dd class="truncate">
                                        {{ vehicle.color ?? 'Sin capturar' }}
                                    </dd>
                                </div>
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt class="text-slate-500">Año</dt>
                                    <dd>
                                        {{ vehicle.year ?? 'Sin capturar' }}
                                    </dd>
                                </div>
                                <div
                                    class="flex items-center justify-between gap-4 border-t border-dashed border-slate-300/70 pt-2 dark:border-darkmode-400"
                                >
                                    <dt class="text-slate-500">Registrada</dt>
                                    <dd>{{ vehicle.created_at }}</dd>
                                </div>
                            </dl>

                            <div
                                v-if="vehicle.guest"
                                class="mt-4 flex items-center justify-between gap-3 rounded-lg border border-slate-200/80 px-3 py-2.5 dark:border-darkmode-400"
                            >
                                <div class="min-w-0">
                                    <div
                                        class="text-[11px] font-medium text-slate-400"
                                    >
                                        FICHA DE HUÉSPED LIGADA
                                    </div>
                                    <div class="truncate text-sm font-medium">
                                        {{ vehicle.guest.full_name }}
                                    </div>
                                </div>
                                <Button
                                    :as="Link"
                                    :href="
                                        route(
                                            'tenant.guests.show',
                                            vehicle.guest.id,
                                        )
                                    "
                                    variant="outline-secondary"
                                    class="h-9 shrink-0 rounded-[0.5rem] bg-white text-xs whitespace-nowrap"
                                >
                                    Ver
                                    <Lucide
                                        icon="ArrowRight"
                                        class="ml-1.5 h-3.5 w-3.5"
                                    />
                                </Button>
                            </div>

                            <Button
                                v-if="canManage && !vehicle.is_archived"
                                variant="outline-secondary"
                                class="mt-4 h-9 w-full rounded-[0.5rem] bg-white text-xs"
                                @click="showEdit = true"
                            >
                                <Lucide
                                    icon="Pencil"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Editar datos del vehículo
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Editar datos -->
        <Dialog :open="showEdit" size="lg" @close="showEdit = false">
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="save">
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Car" class="h-4 w-4" />
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
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="!h-9 !w-9 shrink-0 rounded-full !p-0"
                            title="Cerrar"
                            @click="showEdit = false"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </Button>
                    </div>

                    <div class="space-y-4 px-5 py-4">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <FormLabel htmlFor="vehicle-brand">
                                    Marca
                                </FormLabel>
                                <FormInput
                                    id="vehicle-brand"
                                    v-model="form.brand"
                                    type="text"
                                    class="h-9 text-xs"
                                    placeholder="Nissan"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="vehicle-model">
                                    Modelo
                                </FormLabel>
                                <FormInput
                                    id="vehicle-model"
                                    v-model="form.model"
                                    type="text"
                                    class="h-9 text-xs"
                                    placeholder="Versa"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="vehicle-color">
                                    Color
                                </FormLabel>
                                <FormInput
                                    id="vehicle-color"
                                    v-model="form.color"
                                    type="text"
                                    class="h-9 text-xs"
                                    placeholder="Gris"
                                />
                            </div>
                        </div>
                        <div>
                            <FormLabel htmlFor="vehicle-notes">Notas</FormLabel>
                            <FormTextarea
                                id="vehicle-notes"
                                v-model="form.notes"
                                rows="3"
                                placeholder="Lo que convenga recordar de este vehículo"
                            />
                        </div>
                        <div
                            class="rounded-lg border border-slate-200/70 p-3.5 dark:border-darkmode-400"
                        >
                            <FormSwitch>
                                <FormSwitch.Input
                                    v-model="form.is_blacklisted"
                                    type="checkbox"
                                />
                                <FormSwitch.Label class="ml-2 text-sm">
                                    Vetar esta placa
                                </FormSwitch.Label>
                            </FormSwitch>
                            <div v-if="form.is_blacklisted" class="mt-3">
                                <FormLabel htmlFor="vehicle-reason">
                                    Motivo
                                </FormLabel>
                                <FormInput
                                    id="vehicle-reason"
                                    v-model="form.blacklist_reason"
                                    type="text"
                                    class="h-9 text-xs"
                                    placeholder="Por qué no se le vuelve a rentar"
                                />
                                <FormHelp
                                    v-if="errors.blacklist_reason"
                                    class="text-danger"
                                >
                                    {{ errors.blacklist_reason }}
                                </FormHelp>
                                <FormHelp v-else>
                                    Al registrar una llegada con esta placa, el
                                    plano avisará.
                                </FormHelp>
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-4 text-xs"
                            @click="showEdit = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-4 text-xs"
                            :disabled="saving"
                        >
                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                            {{ saving ? 'Guardando...' : 'Guardar' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
