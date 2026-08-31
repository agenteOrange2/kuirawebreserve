<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

/**
 * Ficha de una llegada a pie, hermana de la del vehículo y con su misma
 * estructura. Quien entra a pie no tiene entidad propia: la visita ES la
 * estancia, así que aquí se junta lo que se le pidió en la caseta (nombre e
 * identificación) con lo que dejó (hospedaje y consumos).
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

defineProps<{
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
        <div class="mt-2">
            <div class="box box--stacked overflow-hidden">
                <div
                    class="flex flex-col gap-4 p-5 md:flex-row md:items-start md:justify-between"
                >
                    <div class="flex min-w-0 gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                        >
                            <Lucide icon="Footprints" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-base font-medium">
                                    {{ arrival.guest_name }}
                                </h1>
                                <span
                                    v-if="arrival.is_inside"
                                    class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2.5 py-1 text-[11px] font-medium text-success"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full bg-success"
                                    />
                                    Adentro ahora
                                </span>
                                <span
                                    v-else
                                    class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                >
                                    Estancia cerrada
                                </span>
                            </div>
                            <div
                                class="mt-2 flex flex-wrap items-center gap-1.5"
                            >
                                <span
                                    class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                    title="Identificación que dejó"
                                >
                                    <Lucide
                                        icon="IdCard"
                                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                    />
                                    <span class="truncate">
                                        {{
                                            documentLabels[
                                                arrival.id_document_type ?? ''
                                            ] ?? 'Documento'
                                        }}
                                    </span>
                                </span>
                                <span
                                    class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                >
                                    <Lucide
                                        icon="UsersRound"
                                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                    />
                                    <span class="truncate">
                                        {{ arrival.num_people }}
                                        {{
                                            arrival.num_people === 1
                                                ? 'persona'
                                                : 'personas'
                                        }}
                                    </span>
                                </span>
                                <Link
                                    v-if="arrival.guest"
                                    :href="
                                        route(
                                            'tenant.guests.show',
                                            arrival.guest.id,
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
                                        {{ arrival.guest.full_name }}
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
                            :href="`${route('tenant.vehicles')}?tab=pie`"
                            class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                        >
                            <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                            Volver a llegadas a pie
                        </Link>
                        <Button
                            v-if="arrival.guest"
                            :as="Link"
                            :href="
                                route('tenant.guests.show', arrival.guest.id)
                            "
                            variant="primary"
                            class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        >
                            <Lucide
                                icon="UserRound"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Ver huésped
                        </Button>
                    </div>
                </div>

                <div
                    v-if="arrival.notes"
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
                                NOTAS DE LA LLEGADA
                            </div>
                            <p
                                class="mt-1 text-xs leading-relaxed whitespace-pre-line text-slate-600 dark:text-slate-300"
                            >
                                {{ arrival.notes }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-slate-200/60 bg-slate-50/70 px-5 py-3 text-xs dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <span
                        class="inline-flex items-center gap-1.5 text-slate-500"
                        title="Entrada y salida"
                    >
                        <Lucide
                            icon="CalendarDays"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                        >
                            {{ arrival.check_in_at }}
                        </span>
                        <span class="text-slate-400">→</span>
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                        >
                            {{
                                arrival.check_out_at ??
                                (arrival.planned_end_at
                                    ? `prevista ${arrival.planned_end_at}`
                                    : 'sin salida')
                            }}
                        </span>
                    </span>
                    <span
                        class="hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400"
                    />
                    <span
                        class="inline-flex items-center gap-1.5 text-slate-500"
                    >
                        <Lucide
                            icon="DoorClosed"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                        >
                            {{
                                arrival.room
                                    ? `Habitación ${arrival.room}`
                                    : 'Sin habitación'
                            }}
                        </span>
                    </span>
                    <span
                        class="hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400"
                    />
                    <span
                        class="inline-flex items-center gap-1.5 text-slate-500"
                    >
                        <Lucide
                            icon="Wallet"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                        >
                            {{ money(arrival.amount) }}
                        </span>
                        de hospedaje
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
                <div class="col-span-12 xl:col-span-8">
                    <div class="box box--stacked">
                        <div
                            class="flex items-center gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-info/10 text-info"
                            >
                                <Lucide icon="BedDouble" class="h-4 w-4" />
                            </div>
                            <div>
                                <div class="font-medium">La estancia</div>
                                <div class="text-xs text-slate-500">
                                    Dónde se quedó y qué dejó de hospedaje.
                                </div>
                            </div>
                        </div>
                        <article
                            class="grid gap-3 px-4 py-3 sm:px-5 md:grid-cols-[minmax(11rem,1.2fr)_minmax(10rem,1fr)_minmax(8rem,0.6fr)] md:items-center"
                        >
                            <div class="flex min-w-0 items-center gap-2.5">
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-info/10 text-info"
                                >
                                    <Lucide icon="DoorClosed" class="h-4 w-4" />
                                </div>
                                <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <span class="text-sm font-medium">
                                            {{
                                                arrival.room
                                                    ? `Habitación ${arrival.room}`
                                                    : 'Sin habitación'
                                            }}
                                        </span>
                                        <span
                                            v-if="arrival.is_inside"
                                            class="rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success"
                                        >
                                            En curso
                                        </span>
                                    </div>
                                    <div
                                        class="truncate text-xs text-slate-500"
                                    >
                                        {{ arrival.rate_plan ?? 'Sin tarifa' }}
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
                                    {{ arrival.check_in_at }}
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{
                                        arrival.check_out_at
                                            ? `Salió ${arrival.check_out_at}`
                                            : arrival.planned_end_at
                                              ? `Salida prevista ${arrival.planned_end_at}`
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
                                    {{ money(arrival.amount) }}
                                </div>
                            </div>
                        </article>
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
                                Esta llegada no compró nada en el POS.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quién llegó -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="box box--stacked overflow-hidden">
                        <div
                            class="flex items-center gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Lucide icon="IdCard" class="h-4 w-4" />
                            </div>
                            <div>
                                <div class="font-medium">Quién llegó</div>
                                <div class="text-xs text-slate-500">
                                    Lo que se le pidió en la caseta.
                                </div>
                            </div>
                        </div>
                        <div class="p-4">
                            <div
                                class="rounded-xl bg-slate-50 p-3.5 dark:bg-darkmode-700"
                            >
                                <div class="text-xs text-slate-500">Nombre</div>
                                <div class="mt-1 text-base font-semibold">
                                    {{ arrival.guest_name }}
                                </div>
                            </div>

                            <dl class="mt-4 space-y-2 text-sm">
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt class="text-slate-500">
                                        Identificación
                                    </dt>
                                    <dd class="truncate">
                                        {{
                                            documentLabels[
                                                arrival.id_document_type ?? ''
                                            ] ?? 'Documento'
                                        }}
                                    </dd>
                                </div>
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt class="text-slate-500">Número</dt>
                                    <dd class="text-slate-500">
                                        Cifrado, no se muestra
                                    </dd>
                                </div>
                                <div
                                    class="flex items-center justify-between gap-4 border-t border-dashed border-slate-300/70 pt-2 dark:border-darkmode-400"
                                >
                                    <dt class="text-slate-500">Personas</dt>
                                    <dd>{{ arrival.num_people }}</dd>
                                </div>
                            </dl>

                            <div class="mt-4">
                                <div
                                    class="text-[11px] font-medium text-slate-400"
                                >
                                    FOTOS DE LA IDENTIFICACIÓN
                                </div>
                                <div
                                    v-if="arrival.documents.length"
                                    class="mt-2 flex flex-wrap gap-1.5"
                                >
                                    <a
                                        v-for="(
                                            doc, index
                                        ) in arrival.documents"
                                        :key="doc"
                                        :href="doc"
                                        target="_blank"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/80 px-2.5 py-1.5 text-xs font-medium text-slate-600 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:text-slate-300"
                                    >
                                        <Lucide
                                            icon="Image"
                                            class="h-3.5 w-3.5"
                                        />
                                        {{
                                            index === 0
                                                ? 'Frente'
                                                : `Foto ${index + 1}`
                                        }}
                                    </a>
                                </div>
                                <p v-else class="mt-1.5 text-xs text-slate-500">
                                    {{
                                        canViewDocuments
                                            ? 'Esta llegada no dejó fotos del documento.'
                                            : 'Las fotos solo las ve quien tiene el permiso de documentos.'
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
