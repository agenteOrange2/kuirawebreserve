<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface RequestItem {
    product_id: number;
    name: string;
    qty: number;
    price: number;
}

interface KitchenRequest {
    id: number;
    guest_name: string;
    room_label: string | null;
    notes: string | null;
    items: RequestItem[];
    total: number;
    payment_mode: 'room_charge' | 'on_delivery';
    payment_label: string;
    status: string;
    preparing_by: string | null;
    attended_by: string | null;
    order_id: number | null;
    order_total: number | null;
    created_at: string;
    created_time: string;
    preparing_time: string | null;
    attended_time: string | null;
}

const props = defineProps<{
    pending: KitchenRequest[];
    preparing: KitchenRequest[];
    dispatched: KitchenRequest[];
    stats: {
        today_count: number;
        today_total: number;
        waiting_now: number;
        avg_dispatch_minutes: number | null;
    };
}>();

const toast = useToasts();
const money = (n: number) =>
    `$${n.toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

// ── Reloj y tiempos de espera ──
const now = ref(Date.now());

const minutesWaiting = (request: KitchenRequest) =>
    Math.max(
        0,
        Math.floor((now.value - new Date(request.created_at).getTime()) / 60000),
    );

const waitTone = (request: KitchenRequest) => {
    const minutes = minutesWaiting(request);
    if (minutes >= 30) return 'bg-danger/10 text-danger';
    if (minutes >= 15) return 'bg-pending/10 text-pending';
    return 'bg-success/10 text-success';
};

const waitLabel = (request: KitchenRequest) => {
    const minutes = minutesWaiting(request);
    if (minutes < 1) return 'Recién llegado';
    if (minutes === 1) return 'Hace 1 min';
    return `Hace ${minutes} min`;
};

// ── Auto-actualización (la cocina no recarga a mano) ──
let pollTimer: ReturnType<typeof setInterval> | null = null;
let clockTimer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    pollTimer = setInterval(() => {
        router.reload({ only: ['pending', 'preparing', 'dispatched', 'stats'] });
    }, 15000);
    clockTimer = setInterval(() => (now.value = Date.now()), 30000);
});

onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer);
    if (clockTimer) clearInterval(clockTimer);
});

// Timbre corto al entrar pedido nuevo (tras la primera interacción, que es
// cuando el navegador permite audio).
const seenIds = ref(new Set(props.pending.map((r) => r.id)));

function chime() {
    try {
        const ctx = new AudioContext();
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.connect(gain);
        gain.connect(ctx.destination);
        oscillator.frequency.value = 880;
        gain.gain.setValueAtTime(0.08, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.6);
        oscillator.start();
        oscillator.stop(ctx.currentTime + 0.6);
    } catch {
        // Sin permiso de audio todavía: el aviso visual basta.
    }
}

watch(
    () => props.pending,
    (pending) => {
        const fresh = pending.some((r) => !seenIds.value.has(r.id));
        seenIds.value = new Set(pending.map((r) => r.id));
        if (fresh) chime();
    },
);

// ── Tomar y despachar ──
const busyId = ref<number | null>(null);
// Tras un 422 al despachar con venta, el botón ofrece "sin venta".
const saleFailed = reactive<Record<number, boolean>>({});

async function setStatus(
    request: KitchenRequest,
    status: 'preparing' | 'attended',
    withSale = true,
) {
    busyId.value = request.id;
    try {
        const { data } = await axios.patch(
            route('tenant.menu-requests.update', request.id),
            { status, with_sale: withSale },
        );
        if (status === 'preparing') {
            toast.success(
                'Pedido en preparación',
                request.room_label
                    ? `Hab. ${request.room_label}: ${request.guest_name}`
                    : request.guest_name,
            );
        } else if (data.order) {
            toast.success(
                'Pedido despachado',
                data.order.to_room
                    ? `Venta ${money(data.order.total)} cargada a la habitación; se cobra en el check-out.`
                    : `Venta ${money(data.order.total)} registrada (${request.payment_label.toLowerCase()}).`,
            );
        } else {
            toast.success('Pedido despachado', data.warning ?? undefined);
        }
        delete saleFailed[request.id];
        router.reload({ only: ['pending', 'preparing', 'dispatched', 'stats'] });
    } catch (e: any) {
        const message = e.response?.data?.message ?? 'Ocurrió un error.';
        if (status === 'attended' && withSale && e.response?.status === 422) {
            saleFailed[request.id] = true;
        }
        toast.error('No se pudo', message);
    } finally {
        busyId.value = null;
    }
}
</script>

<template>
    <RazeLayout title="Cocina">
        <!-- Header hero -->
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary sm:h-14 sm:w-14"
                    >
                        <Lucide icon="ChefHat" class="h-5 w-5 sm:h-7 sm:w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-lg font-medium sm:text-xl">Cocina</h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Se actualiza sola cada 15 segundos y suena al
                            entrar un pedido
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-1 gap-2 md:flex md:w-auto md:gap-2.5"
                >
                    <Button
                        :as="Link"
                        :href="route('tenant.menu-digital')"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="UtensilsCrossed"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Administrar menú
                    </Button>
                </div>
            </div>
        </div>

        <!-- KPIs de cocina -->
        <div class="mt-5 grid auto-rows-fr grid-cols-12 gap-4 sm:gap-5">
            <div
                class="box box--stacked col-span-6 flex items-center gap-3.5 p-4 sm:p-5 xl:col-span-3"
            >
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10"
                >
                    <Lucide icon="Soup" class="h-5 w-5 text-warning" />
                </div>
                <div class="min-w-0">
                    <div class="text-xl font-medium">{{ pending.length }}</div>
                    <div class="truncate text-xs text-slate-500">Nuevos</div>
                </div>
            </div>
            <div
                class="box box--stacked col-span-6 flex items-center gap-3.5 p-4 sm:p-5 xl:col-span-3"
            >
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10"
                >
                    <Lucide icon="ChefHat" class="h-5 w-5 text-info" />
                </div>
                <div class="min-w-0">
                    <div class="text-xl font-medium">
                        {{ preparing.length }}
                    </div>
                    <div class="truncate text-xs text-slate-500">
                        En preparación
                    </div>
                </div>
            </div>
            <div
                class="box box--stacked col-span-6 flex items-center gap-3.5 p-4 sm:p-5 xl:col-span-3"
            >
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10"
                >
                    <Lucide icon="CheckCheck" class="h-5 w-5 text-success" />
                </div>
                <div class="min-w-0">
                    <div class="text-xl font-medium">
                        {{ dispatched.length }}
                    </div>
                    <div class="truncate text-xs text-slate-500">
                        Despachados hoy
                    </div>
                </div>
            </div>
            <div
                class="box box--stacked col-span-6 flex items-center gap-3.5 p-4 sm:p-5 xl:col-span-3"
            >
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                >
                    <Lucide icon="Timer" class="h-5 w-5 text-primary" />
                </div>
                <div class="min-w-0">
                    <div class="text-xl font-medium">
                        {{
                            stats.avg_dispatch_minutes === null
                                ? 'Sin datos'
                                : `${stats.avg_dispatch_minutes} min`
                        }}
                    </div>
                    <div class="truncate text-xs text-slate-500">
                        Promedio de despacho
                    </div>
                </div>
            </div>
        </div>

        <!-- Todo tranquilo -->
        <div v-if="!pending.length && !preparing.length" class="mt-5">
            <div
                class="box box--stacked flex flex-col items-center justify-center gap-3 px-5 py-16 text-center"
            >
                <div
                    class="flex h-14 w-14 items-center justify-center rounded-full bg-success/10 text-success"
                >
                    <Lucide icon="ChefHat" class="h-7 w-7" />
                </div>
                <div class="text-base font-medium">Todo despachado</div>
                <p class="max-w-md text-sm text-slate-500">
                    No hay pedidos en espera. Cuando un huésped ordene desde el
                    menú, aparecerá aquí solo y sonará un aviso.
                </p>
            </div>
        </div>

        <!-- Tablero: Nuevos | En preparación -->
        <div v-else class="mt-5 grid grid-cols-12 gap-5">
            <!-- Columna: nuevos -->
            <div class="col-span-12 xl:col-span-6">
                <div
                    class="flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center"
                >
                    <div
                        class="flex items-center gap-2 text-base font-medium group-[.mode--light]:text-white"
                    >
                        <Lucide icon="Soup" class="h-4 w-4 text-warning" />
                        Nuevos
                        <span
                            class="rounded-full bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning"
                            >{{ pending.length }}</span
                        >
                    </div>
                </div>
                <div class="mt-3.5 space-y-5">
                    <div
                        v-for="request in pending"
                        :key="request.id"
                        class="box box--stacked flex flex-col"
                    >
                        <div
                            class="flex items-center justify-between gap-3 border-b border-dashed border-slate-300/70 px-5 py-4"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                                >
                                    <Lucide
                                        :icon="
                                            request.room_label
                                                ? 'BedDouble'
                                                : 'ChefHat'
                                        "
                                        class="h-5 w-5"
                                    />
                                </div>
                                <div>
                                    <div class="text-lg font-medium">
                                        {{
                                            request.room_label
                                                ? `Hab. ${request.room_label}`
                                                : 'Sin habitación'
                                        }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ request.guest_name }} ·
                                        {{ request.created_time }}
                                    </div>
                                </div>
                            </div>
                            <span
                                class="flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="waitTone(request)"
                            >
                                <Lucide icon="Timer" class="h-3 w-3" />
                                {{ waitLabel(request) }}
                            </span>
                        </div>

                        <div class="flex flex-col gap-3 p-5">
                            <div class="space-y-2">
                                <div
                                    v-for="item in request.items"
                                    :key="item.product_id"
                                    class="flex items-center gap-3"
                                >
                                    <span
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-sm font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                        >{{ item.qty }}</span
                                    >
                                    <span class="text-base">{{
                                        item.name
                                    }}</span>
                                </div>
                            </div>
                            <div
                                v-if="request.notes"
                                class="rounded-lg bg-pending/10 px-3 py-2 text-sm text-pending"
                            >
                                "{{ request.notes }}"
                            </div>
                            <div
                                class="flex items-center justify-between border-t border-dashed border-slate-300/70 pt-3 text-sm"
                            >
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        request.payment_mode === 'room_charge'
                                            ? 'bg-info/10 text-info'
                                            : 'bg-success/10 text-success'
                                    "
                                    >{{ request.payment_label }}</span
                                >
                                <span class="font-medium">{{
                                    money(request.total)
                                }}</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 px-5 pb-5">
                            <Button
                                variant="primary"
                                class="flex-1 shadow-md shadow-primary/20"
                                :disabled="busyId === request.id"
                                @click="setStatus(request, 'preparing')"
                            >
                                <Lucide icon="ChefHat" class="mr-2 h-4 w-4" />
                                Tomar pedido
                            </Button>
                            <a
                                :href="route('tenant.menu-comanda', request.id)"
                                target="_blank"
                                rel="noopener"
                                title="Imprimir comanda"
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-300/70 text-slate-500 transition hover:bg-primary/10 hover:text-primary dark:border-darkmode-400"
                            >
                                <Lucide icon="Printer" class="h-4 w-4" />
                            </a>
                        </div>
                    </div>
                    <div
                        v-if="!pending.length"
                        class="box box--stacked flex flex-col items-center gap-2 px-5 py-10 text-center text-slate-400"
                    >
                        <Lucide icon="Soup" class="h-8 w-8" />
                        <p class="text-sm">Sin pedidos nuevos.</p>
                    </div>
                </div>
            </div>

            <!-- Columna: en preparación -->
            <div class="col-span-12 xl:col-span-6">
                <div
                    class="flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center"
                >
                    <div
                        class="flex items-center gap-2 text-base font-medium group-[.mode--light]:text-white"
                    >
                        <Lucide icon="ChefHat" class="h-4 w-4 text-info" />
                        En preparación
                        <span
                            class="rounded-full bg-info/10 px-2 py-0.5 text-xs font-medium text-info"
                            >{{ preparing.length }}</span
                        >
                    </div>
                </div>
                <div class="mt-3.5 space-y-5">
                    <div
                        v-for="request in preparing"
                        :key="request.id"
                        class="box box--stacked flex flex-col"
                    >
                        <div
                            class="flex items-center justify-between gap-3 border-b border-dashed border-slate-300/70 px-5 py-4"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                                >
                                    <Lucide
                                        :icon="
                                            request.room_label
                                                ? 'BedDouble'
                                                : 'ChefHat'
                                        "
                                        class="h-5 w-5"
                                    />
                                </div>
                                <div>
                                    <div class="text-lg font-medium">
                                        {{
                                            request.room_label
                                                ? `Hab. ${request.room_label}`
                                                : 'Sin habitación'
                                        }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ request.guest_name }} ·
                                        {{ request.created_time }}
                                        <template v-if="request.preparing_by">
                                            · Prepara:
                                            {{ request.preparing_by }}</template
                                        >
                                    </div>
                                </div>
                            </div>
                            <span
                                class="flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="waitTone(request)"
                            >
                                <Lucide icon="Timer" class="h-3 w-3" />
                                {{ waitLabel(request) }}
                            </span>
                        </div>

                        <div class="flex flex-col gap-3 p-5">
                            <div class="space-y-2">
                                <div
                                    v-for="item in request.items"
                                    :key="item.product_id"
                                    class="flex items-center gap-3"
                                >
                                    <span
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-sm font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                        >{{ item.qty }}</span
                                    >
                                    <span class="text-base">{{
                                        item.name
                                    }}</span>
                                </div>
                            </div>
                            <div
                                v-if="request.notes"
                                class="rounded-lg bg-pending/10 px-3 py-2 text-sm text-pending"
                            >
                                "{{ request.notes }}"
                            </div>
                            <div
                                class="flex items-center justify-between border-t border-dashed border-slate-300/70 pt-3 text-sm"
                            >
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        request.payment_mode === 'room_charge'
                                            ? 'bg-info/10 text-info'
                                            : 'bg-success/10 text-success'
                                    "
                                    >{{ request.payment_label }}</span
                                >
                                <span class="font-medium">{{
                                    money(request.total)
                                }}</span>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2 px-5 pb-5">
                            <div class="flex items-center gap-2">
                                <Button
                                    variant="primary"
                                    class="flex-1 shadow-md shadow-primary/20"
                                    :disabled="busyId === request.id"
                                    @click="
                                        setStatus(
                                            request,
                                            'attended',
                                            !saleFailed[request.id],
                                        )
                                    "
                                >
                                    <Lucide
                                        icon="CheckCheck"
                                        class="mr-2 h-4 w-4"
                                    />
                                    {{
                                        saleFailed[request.id]
                                            ? 'Despachar sin venta'
                                            : 'Despachar'
                                    }}
                                </Button>
                                <a
                                    :href="
                                        route('tenant.menu-comanda', request.id)
                                    "
                                    target="_blank"
                                    rel="noopener"
                                    title="Imprimir comanda"
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-300/70 text-slate-500 transition hover:bg-primary/10 hover:text-primary dark:border-darkmode-400"
                                >
                                    <Lucide icon="Printer" class="h-4 w-4" />
                                </a>
                            </div>
                            <p
                                v-if="saleFailed[request.id]"
                                class="text-xs text-slate-400"
                            >
                                No se pudo generar la venta; al despachar sin
                                venta, registra el cobro en el punto de venta.
                            </p>
                        </div>
                    </div>
                    <div
                        v-if="!preparing.length"
                        class="box box--stacked flex flex-col items-center gap-2 px-5 py-10 text-center text-slate-400"
                    >
                        <Lucide icon="ChefHat" class="h-8 w-8" />
                        <p class="text-sm">
                            Toma un pedido de la columna de nuevos para
                            empezar.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Despachados hoy -->
        <div v-if="dispatched.length" class="mt-5">
            <div class="box box--stacked">
                <div
                    class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                >
                    <Lucide
                        icon="History"
                        class="h-4 w-4 stroke-[1.5] text-primary"
                    />
                    <h2 class="text-base font-medium">Despachados hoy</h2>
                </div>
                <div
                    class="flex flex-col divide-y divide-dashed divide-slate-300/70 px-5"
                >
                    <div
                        v-for="request in dispatched"
                        :key="request.id"
                        class="flex flex-col gap-1 py-3 text-sm sm:flex-row sm:items-center sm:gap-3"
                    >
                        <span class="font-medium sm:w-24 sm:shrink-0">
                            {{
                                request.room_label
                                    ? `Hab. ${request.room_label}`
                                    : 'Sin hab.'
                            }}
                        </span>
                        <span class="min-w-0 flex-1 truncate text-slate-500">
                            {{
                                request.items
                                    .map((i) => `${i.qty}x ${i.name}`)
                                    .join(', ')
                            }}
                        </span>
                        <span
                            v-if="request.order_id"
                            class="shrink-0 rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success"
                            title="Venta POS generada al despachar"
                            >Venta
                            {{
                                money(request.order_total ?? request.total)
                            }}</span
                        >
                        <span
                            class="shrink-0 text-xs text-slate-400 sm:text-right"
                        >
                            {{ request.guest_name }} · despachado
                            {{ request.attended_time }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
