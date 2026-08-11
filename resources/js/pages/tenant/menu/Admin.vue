<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import QRCode from 'qrcode';
import { computed, onMounted, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSwitch } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface MenuProduct {
    id: number;
    name: string;
    category: string;
    price: number;
    available_in_menu: boolean;
    photo_url: string | null;
}

interface RequestItem {
    product_id: number;
    name: string;
    qty: number;
    price: number;
}

type RequestStatus = 'pending' | 'preparing' | 'attended' | 'cancelled';

interface MenuRequestRow {
    id: number;
    guest_name: string;
    room_label: string | null;
    notes: string | null;
    items: RequestItem[];
    total: number;
    payment_mode: 'room_charge' | 'on_delivery';
    payment_label: string;
    status: RequestStatus;
    preparing_by: string | null;
    attended_by: string | null;
    order_id: number | null;
    order_total: number | null;
    created_date: string;
    created_time: string;
}

const props = defineProps<{
    products: MenuProduct[];
    requests: MenuRequestRow[];
    stats: {
        today_count: number;
        today_total: number;
        waiting_now: number;
        avg_dispatch_minutes: number | null;
    };
    topProducts: Array<{ name: string; qty: number }>;
    billingMode: 'hotel' | 'motel';
    hours: { from: string | null; to: string | null };
    etaMinutes: number | null;
    menuUrl: string;
    qrRooms: Array<{ id: number; label: string; url: string }>;
}>();

const toast = useToasts();
const money = (n: number) =>
    `$${n.toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

// ── Pestañas de la página ──
const tab = ref<'solicitudes' | 'carta' | 'ajustes'>('solicitudes');

const waitingCount = computed(
    () =>
        props.requests.filter(
            (r) => r.status === 'pending' || r.status === 'preparing',
        ).length,
);
const inMenuCount = computed(
    () => props.products.filter((p) => p.available_in_menu).length,
);

// ── Solicitudes: filtro por estado ──
const statusFilter = ref<'all' | RequestStatus>('all');

const statusMeta: Record<RequestStatus, { label: string; tone: string }> = {
    pending: { label: 'Nuevo', tone: 'bg-warning/10 text-warning' },
    preparing: { label: 'Preparando', tone: 'bg-info/10 text-info' },
    attended: { label: 'Despachada', tone: 'bg-success/10 text-success' },
    cancelled: {
        label: 'Cancelada',
        tone: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    },
};

const statusFilters = computed(() => [
    { key: 'all' as const, label: 'Todas', count: props.requests.length },
    ...(
        ['pending', 'preparing', 'attended', 'cancelled'] as RequestStatus[]
    ).map((key) => ({
        key,
        label: statusMeta[key].label,
        count: props.requests.filter((r) => r.status === key).length,
    })),
]);

const filteredRequests = computed(() =>
    statusFilter.value === 'all'
        ? props.requests
        : props.requests.filter((r) => r.status === statusFilter.value),
);

const itemsSummary = (request: MenuRequestRow) =>
    request.items.map((item) => `${item.qty}x ${item.name}`).join(', ');

// ── Acciones sobre solicitudes (axios: el resultado del despacho importa) ──
const busyId = ref<number | null>(null);
// Tras un 422 al despachar (sin estancia activa, producto inactivo...) el
// botón cambia a "sin venta" para destrabar sin perder el pedido.
const saleFailed = reactive<Record<number, boolean>>({});

async function setStatus(
    request: MenuRequestRow,
    status: RequestStatus,
    withSale = true,
) {
    busyId.value = request.id;
    try {
        const { data } = await axios.patch(
            route('tenant.menu-requests.update', request.id),
            { status, with_sale: withSale },
        );
        if (status === 'attended') {
            if (data.order) {
                toast.success(
                    'Pedido despachado',
                    data.order.to_room
                        ? `Venta ${money(data.order.total)} cargada a la habitación; se cobra en el check-out.`
                        : `Venta ${money(data.order.total)} registrada (${request.payment_label.toLowerCase()}).`,
                );
            } else {
                toast.success('Pedido despachado', data.warning ?? undefined);
            }
        } else {
            toast.success(
                'Solicitud actualizada',
                `Pedido de ${request.guest_name}: ${statusMeta[status].label.toLowerCase()}`,
            );
        }
        delete saleFailed[request.id];
        router.reload({ only: ['requests', 'stats', 'topProducts'] });
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

// ── La carta: búsqueda + categorías ──
const search = ref('');
const categoryFilter = ref<string | null>(null);

const categories = computed(() => [
    ...new Set(props.products.map((p) => p.category)),
]);

const filteredProducts = computed(() =>
    props.products.filter(
        (p) =>
            (categoryFilter.value === null ||
                p.category === categoryFilter.value) &&
            (search.value.trim() === '' ||
                p.name
                    .toLowerCase()
                    .includes(search.value.trim().toLowerCase())),
    ),
);

function toggleProduct(product: MenuProduct) {
    router.patch(
        route('tenant.menu-products.update', product.id),
        { available_in_menu: !product.available_in_menu },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    'Carta actualizada',
                    `${product.name}: ${product.available_in_menu ? 'fuera del' : 'visible en el'} menú`,
                ),
        },
    );
}

// ── Ajustes ──
const shared = usePage().props.panelTenant as {
    permissions?: string[];
} | null;
const canManageProperty = (shared?.permissions ?? []).includes(
    'properties.manage',
);

const billingMode = ref(props.billingMode);

function setBillingMode(mode: 'hotel' | 'motel') {
    if (mode === billingMode.value) return;
    const previous = billingMode.value;
    billingMode.value = mode;
    router.patch(
        route('tenant.menu-settings.update'),
        { billing_mode: mode },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    'Modo actualizado',
                    mode === 'hotel'
                        ? 'Hotel: el pedido puede cargarse a la habitación y pagarse al final.'
                        : 'Motel: el pedido se paga siempre al recibirlo.',
                ),
            onError: () => {
                billingMode.value = previous;
                toast.error('No se pudo cambiar', 'Intenta de nuevo.');
            },
        },
    );
}

const hoursForm = reactive({
    from: props.hours.from ?? '',
    to: props.hours.to ?? '',
    eta: props.etaMinutes != null ? String(props.etaMinutes) : '',
});
const savingHours = ref(false);

function saveHours() {
    savingHours.value = true;
    router.patch(
        route('tenant.menu-settings.update'),
        {
            hours_from: hoursForm.from || null,
            hours_to: hoursForm.to || null,
            eta_minutes: hoursForm.eta === '' ? null : Number(hoursForm.eta),
        },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    'Ajustes guardados',
                    'Horario y tiempo de entrega actualizados.',
                ),
            onError: () =>
                toast.error(
                    'Revisa los datos',
                    'Horario en formato HH:MM y minutos entre 5 y 240.',
                ),
            onFinish: () => (savingHours.value = false),
        },
    );
}

// ── Liga y QR ──
const qrDataUrl = ref<string | null>(null);

onMounted(async () => {
    qrDataUrl.value = await QRCode.toDataURL(props.menuUrl, {
        width: 320,
        margin: 1,
    });
});

async function copyLink() {
    try {
        await navigator.clipboard.writeText(props.menuUrl);
        toast.success('Liga copiada', 'Pégala en redes, WhatsApp o tu sitio.');
    } catch {
        toast.error('No se pudo copiar', 'Copia la liga manualmente.');
    }
}

const printingQr = ref(false);

async function printQrCodes() {
    if (!props.qrRooms.length || printingQr.value) return;
    printingQr.value = true;
    try {
        const cards = await Promise.all(
            props.qrRooms.map(async (room) => {
                const dataUrl = await QRCode.toDataURL(room.url, {
                    width: 220,
                    margin: 1,
                });
                return `<div class="card">
                    <div class="hotel">Menú y servicios a tu habitación</div>
                    <img src="${dataUrl}" alt="QR" />
                    <div class="room">Habitación ${room.label}</div>
                    <div class="hint">Escanea, elige y te lo llevamos</div>
                </div>`;
            }),
        );

        const win = window.open('', '_blank');
        if (!win) {
            toast.error(
                'No se pudo abrir la ventana',
                'Permite las ventanas emergentes para imprimir los códigos.',
            );
            return;
        }

        win.document.write(`<!doctype html><html lang="es"><head>
            <meta charset="utf-8" />
            <title>Códigos QR del menú digital</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: system-ui, sans-serif; padding: 24px; }
                .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
                .card { border: 1px dashed #94a3b8; border-radius: 12px; padding: 16px; text-align: center; break-inside: avoid; }
                .hotel { font-size: 11px; color: #64748b; }
                img { width: 160px; height: 160px; margin: 8px auto; display: block; }
                .room { font-weight: 600; font-size: 14px; }
                .hint { font-size: 11px; color: #64748b; margin-top: 4px; }
                @media print { body { padding: 0; } }
            </style>
        </head><body><div class="grid">${cards.join('')}</div></body></html>`);
        win.document.close();
        win.focus();
        setTimeout(() => win.print(), 300);
    } finally {
        printingQr.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Menú digital">
        <!-- Header hero -->
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary sm:h-14 sm:w-14"
                    >
                        <Lucide
                            icon="UtensilsCrossed"
                            class="h-5 w-5 sm:h-7 sm:w-7"
                        />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-lg font-medium sm:text-xl">
                            Menú digital
                        </h1>
                        <p class="mt-1 text-sm text-slate-500">
                            La carta pública y los pedidos de tus huéspedes
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:gap-2.5"
                >
                    <Button
                        :as="Link"
                        :href="route('tenant.menu-kitchen')"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="ChefHat"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Cocina
                    </Button>
                    <Button
                        as="a"
                        :href="menuUrl"
                        target="_blank"
                        rel="noopener"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="ExternalLink"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Ver la carta
                    </Button>
                </div>
            </div>
        </div>

        <!-- KPIs -->
        <div class="mt-5 grid auto-rows-fr grid-cols-12 gap-5">
            <div
                class="box box--stacked col-span-12 flex items-center gap-3.5 p-5 sm:col-span-6 xl:col-span-3"
            >
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                >
                    <Lucide
                        icon="ClipboardList"
                        class="h-5 w-5 text-primary"
                    />
                </div>
                <div class="min-w-0">
                    <div class="text-xl font-medium">
                        {{ stats.today_count }}
                    </div>
                    <div class="truncate text-xs text-slate-500">
                        Pedidos hoy
                    </div>
                </div>
            </div>
            <div
                class="box box--stacked col-span-12 flex items-center gap-3.5 p-5 sm:col-span-6 xl:col-span-3"
            >
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10"
                >
                    <Lucide
                        icon="BadgeDollarSign"
                        class="h-5 w-5 text-success"
                    />
                </div>
                <div class="min-w-0">
                    <div class="text-xl font-medium">
                        {{ money(stats.today_total) }}
                    </div>
                    <div class="truncate text-xs text-slate-500">
                        Vendido hoy por el menú
                    </div>
                </div>
            </div>
            <div
                class="box box--stacked col-span-12 flex items-center gap-3.5 p-5 sm:col-span-6 xl:col-span-3"
            >
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10"
                >
                    <Lucide icon="BellRing" class="h-5 w-5 text-warning" />
                </div>
                <div class="min-w-0">
                    <div class="text-xl font-medium">
                        {{ stats.waiting_now }}
                    </div>
                    <div class="truncate text-xs text-slate-500">
                        En espera ahora
                    </div>
                </div>
            </div>
            <div
                class="box box--stacked col-span-12 flex items-center gap-3.5 p-5 sm:col-span-6 xl:col-span-3"
            >
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10"
                >
                    <Lucide icon="Timer" class="h-5 w-5 text-info" />
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
                        Tiempo promedio de despacho (7 días)
                    </div>
                </div>
            </div>
        </div>

        <!-- Pestañas -->
        <div
            class="mt-5 flex w-full gap-1 rounded-[0.6rem] bg-slate-100 p-1 sm:w-fit dark:bg-darkmode-400"
        >
            <button
                type="button"
                class="flex flex-1 items-center justify-center gap-2 rounded-[0.5rem] px-4 py-2 text-sm transition sm:flex-none"
                :class="
                    tab === 'solicitudes'
                        ? 'bg-white font-medium text-primary shadow-sm dark:bg-darkmode-600'
                        : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                "
                @click="tab = 'solicitudes'"
            >
                <Lucide icon="ClipboardList" class="h-4 w-4" />
                Solicitudes
                <span
                    v-if="waitingCount"
                    class="rounded-full bg-warning/10 px-1.5 py-0.5 text-[11px] font-medium text-warning"
                    >{{ waitingCount }}</span
                >
            </button>
            <button
                type="button"
                class="flex flex-1 items-center justify-center gap-2 rounded-[0.5rem] px-4 py-2 text-sm transition sm:flex-none"
                :class="
                    tab === 'carta'
                        ? 'bg-white font-medium text-primary shadow-sm dark:bg-darkmode-600'
                        : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                "
                @click="tab = 'carta'"
            >
                <Lucide icon="UtensilsCrossed" class="h-4 w-4" />
                La carta
                <span
                    class="rounded-full bg-slate-200/70 px-1.5 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-300"
                    >{{ inMenuCount }}</span
                >
            </button>
            <button
                type="button"
                class="flex flex-1 items-center justify-center gap-2 rounded-[0.5rem] px-4 py-2 text-sm transition sm:flex-none"
                :class="
                    tab === 'ajustes'
                        ? 'bg-white font-medium text-primary shadow-sm dark:bg-darkmode-600'
                        : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                "
                @click="tab = 'ajustes'"
            >
                <Lucide icon="Share2" class="h-4 w-4" />
                Compartir y ajustes
            </button>
        </div>

        <!-- ═══ Pestaña: Solicitudes ═══ -->
        <template v-if="tab === 'solicitudes'">
            <div class="box box--stacked mt-5">
                <div
                    class="flex flex-col gap-3 border-b border-slate-200/60 p-5 sm:flex-row sm:items-center dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2 font-medium">
                        <Lucide
                            icon="ClipboardList"
                            class="h-4 w-4 text-slate-400"
                        />
                        Pedidos del menú
                    </div>
                    <div
                        class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1 sm:ml-auto sm:pb-0"
                    >
                        <button
                            v-for="f in statusFilters"
                            :key="f.key"
                            type="button"
                            class="shrink-0 rounded-full px-3 py-1.5 text-xs font-medium whitespace-nowrap transition"
                            :class="
                                statusFilter === f.key
                                    ? 'bg-primary text-white shadow-md shadow-primary/20'
                                    : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-darkmode-400'
                            "
                            @click="statusFilter = f.key"
                        >
                            {{ f.label }} ({{ f.count }})
                        </button>
                    </div>
                </div>

                <!-- Móvil: cards -->
                <div class="space-y-2.5 p-5 xl:hidden">
                    <div
                        v-for="request in filteredRequests"
                        :key="request.id"
                        class="rounded-lg border border-slate-200/70 bg-white p-3.5 dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-medium">{{
                                request.guest_name
                            }}</span>
                            <span
                                v-if="request.room_label"
                                class="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary"
                                >Hab. {{ request.room_label }}</span
                            >
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="statusMeta[request.status].tone"
                                >{{ statusMeta[request.status].label }}</span
                            >
                        </div>
                        <div class="mt-1.5 text-sm text-slate-600 dark:text-slate-300">
                            {{ itemsSummary(request) }}
                        </div>
                        <div
                            v-if="request.notes"
                            class="mt-1 text-xs text-slate-500"
                        >
                            "{{ request.notes }}"
                        </div>
                        <div
                            class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500"
                        >
                            <span>{{ request.created_date }}</span>
                            <span
                                class="rounded-full px-2 py-0.5 font-medium"
                                :class="
                                    request.payment_mode === 'room_charge'
                                        ? 'bg-info/10 text-info'
                                        : 'bg-success/10 text-success'
                                "
                                >{{ request.payment_label }}</span
                            >
                            <span class="ml-auto font-medium text-slate-700 dark:text-slate-200">{{
                                money(request.total)
                            }}</span>
                        </div>
                        <div
                            class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-dashed border-slate-300/70 pt-3"
                        >
                            <template
                                v-if="
                                    request.status === 'pending' ||
                                    request.status === 'preparing'
                                "
                            >
                                <Button
                                    variant="primary"
                                    size="sm"
                                    class="shadow-md shadow-primary/20"
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
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    {{
                                        saleFailed[request.id]
                                            ? 'Despachar sin venta'
                                            : 'Despachar'
                                    }}
                                </Button>
                                <Button
                                    variant="outline-secondary"
                                    size="sm"
                                    :disabled="busyId === request.id"
                                    @click="setStatus(request, 'cancelled')"
                                >
                                    <Lucide
                                        icon="Ban"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Cancelar
                                </Button>
                            </template>
                            <template v-else>
                                <span
                                    v-if="request.order_id"
                                    class="rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success"
                                    title="Venta POS generada al despachar"
                                    >Venta
                                    {{
                                        money(
                                            request.order_total ??
                                                request.total,
                                        )
                                    }}</span
                                >
                                <Button
                                    v-else
                                    variant="outline-secondary"
                                    size="sm"
                                    :disabled="busyId === request.id"
                                    @click="setStatus(request, 'pending')"
                                >
                                    <Lucide
                                        icon="Undo2"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Reabrir
                                </Button>
                            </template>
                            <a
                                :href="
                                    route('tenant.menu-comanda', request.id)
                                "
                                target="_blank"
                                rel="noopener"
                                title="Imprimir comanda de cocina"
                                class="ml-auto flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                            >
                                <Lucide icon="Printer" class="h-4 w-4" />
                            </a>
                        </div>
                    </div>
                    <div
                        v-if="!filteredRequests.length"
                        class="py-8 text-center text-sm text-slate-400"
                    >
                        No hay pedidos con este filtro.
                    </div>
                </div>

                <!-- Escritorio: tabla -->
                <div class="hidden overflow-auto p-5 xl:block lg:overflow-visible">
                    <Table v-if="filteredRequests.length" striped>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Pedido</Table.Th>
                                <Table.Th>Artículos</Table.Th>
                                <Table.Th>Pago</Table.Th>
                                <Table.Th class="text-right">Total</Table.Th>
                                <Table.Th>Estado</Table.Th>
                                <Table.Th class="text-right">Acciones</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr
                                v-for="request in filteredRequests"
                                :key="request.id"
                            >
                                <Table.Td>
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">{{
                                            request.guest_name
                                        }}</span>
                                        <span
                                            v-if="request.room_label"
                                            class="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary"
                                            >Hab.
                                            {{ request.room_label }}</span
                                        >
                                    </div>
                                    <div class="mt-0.5 text-xs text-slate-500">
                                        {{ request.created_date }}
                                    </div>
                                </Table.Td>
                                <Table.Td>
                                    <div
                                        class="max-w-xs truncate text-sm"
                                        :title="itemsSummary(request)"
                                    >
                                        {{ itemsSummary(request) }}
                                    </div>
                                    <div
                                        v-if="request.notes"
                                        class="max-w-xs truncate text-xs text-slate-500"
                                        :title="request.notes"
                                    >
                                        "{{ request.notes }}"
                                    </div>
                                </Table.Td>
                                <Table.Td>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
                                        :class="
                                            request.payment_mode ===
                                            'room_charge'
                                                ? 'bg-info/10 text-info'
                                                : 'bg-success/10 text-success'
                                        "
                                        >{{ request.payment_label }}</span
                                    >
                                </Table.Td>
                                <Table.Td class="text-right font-medium">
                                    {{ money(request.total) }}
                                </Table.Td>
                                <Table.Td>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="statusMeta[request.status].tone"
                                        >{{
                                            statusMeta[request.status].label
                                        }}</span
                                    >
                                    <div
                                        v-if="request.order_id"
                                        class="mt-1 text-[11px] text-slate-400"
                                        title="Venta POS generada al despachar"
                                    >
                                        Venta
                                        {{
                                            money(
                                                request.order_total ??
                                                    request.total,
                                            )
                                        }}
                                    </div>
                                    <div
                                        v-else-if="request.attended_by"
                                        class="mt-1 text-[11px] text-slate-400"
                                    >
                                        Atendió: {{ request.attended_by }}
                                    </div>
                                </Table.Td>
                                <Table.Td>
                                    <div
                                        class="flex items-center justify-end gap-1.5"
                                    >
                                        <template
                                            v-if="
                                                request.status === 'pending' ||
                                                request.status === 'preparing'
                                            "
                                        >
                                            <Button
                                                variant="primary"
                                                size="sm"
                                                class="whitespace-nowrap shadow-md shadow-primary/20"
                                                :disabled="
                                                    busyId === request.id
                                                "
                                                @click="
                                                    setStatus(
                                                        request,
                                                        'attended',
                                                        !saleFailed[request.id],
                                                    )
                                                "
                                            >
                                                {{
                                                    saleFailed[request.id]
                                                        ? 'Sin venta'
                                                        : 'Despachar'
                                                }}
                                            </Button>
                                            <button
                                                type="button"
                                                title="Cancelar la solicitud"
                                                class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                                :disabled="
                                                    busyId === request.id
                                                "
                                                @click="
                                                    setStatus(
                                                        request,
                                                        'cancelled',
                                                    )
                                                "
                                            >
                                                <Lucide
                                                    icon="Ban"
                                                    class="h-4 w-4"
                                                />
                                            </button>
                                        </template>
                                        <button
                                            v-else-if="!request.order_id"
                                            type="button"
                                            title="Regresarla a pendientes"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                            :disabled="busyId === request.id"
                                            @click="
                                                setStatus(request, 'pending')
                                            "
                                        >
                                            <Lucide
                                                icon="Undo2"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                        <a
                                            :href="
                                                route(
                                                    'tenant.menu-comanda',
                                                    request.id,
                                                )
                                            "
                                            target="_blank"
                                            rel="noopener"
                                            title="Imprimir comanda de cocina"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                        >
                                            <Lucide
                                                icon="Printer"
                                                class="h-4 w-4"
                                            />
                                        </a>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div
                        v-else
                        class="py-8 text-center text-sm text-slate-400"
                    >
                        No hay pedidos con este filtro.
                    </div>
                </div>

                <div
                    class="border-t border-dashed border-slate-300/70 px-5 py-3.5"
                >
                    <FormHelp>
                        Despachar genera la venta en el punto de venta: el
                        cargo a habitación entra al folio y se cobra en el
                        check-out; efectivo y tarjeta quedan como venta del
                        turno. El inventario se descuenta solo.
                    </FormHelp>
                </div>
            </div>
        </template>

        <!-- ═══ Pestaña: La carta ═══ -->
        <template v-if="tab === 'carta'">
            <div class="mt-5 grid grid-cols-12 gap-5">
                <div class="col-span-12 xl:col-span-8">
                    <div class="box box--stacked">
                        <div
                            class="flex flex-col gap-3 border-b border-slate-200/60 p-5 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2 font-medium">
                                <Lucide
                                    icon="UtensilsCrossed"
                                    class="h-4 w-4 text-slate-400"
                                />
                                Qué ve el huésped
                                <span
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-darkmode-400"
                                    >{{ inMenuCount }} de
                                    {{ products.length }}</span
                                >
                            </div>
                            <div class="relative">
                                <Lucide
                                    icon="Search"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                                />
                                <FormInput
                                    v-model="search"
                                    type="text"
                                    class="pl-9"
                                    placeholder="Buscar producto…"
                                />
                            </div>
                            <div
                                v-if="categories.length > 1"
                                class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1"
                            >
                                <button
                                    type="button"
                                    class="shrink-0 rounded-full px-3 py-1.5 text-xs font-medium whitespace-nowrap transition"
                                    :class="
                                        categoryFilter === null
                                            ? 'bg-primary text-white shadow-md shadow-primary/20'
                                            : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-darkmode-400'
                                    "
                                    @click="categoryFilter = null"
                                >
                                    Todas
                                </button>
                                <button
                                    v-for="category in categories"
                                    :key="category"
                                    type="button"
                                    class="shrink-0 rounded-full px-3 py-1.5 text-xs font-medium whitespace-nowrap transition"
                                    :class="
                                        categoryFilter === category
                                            ? 'bg-primary text-white shadow-md shadow-primary/20'
                                            : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-darkmode-400'
                                    "
                                    @click="categoryFilter = category"
                                >
                                    {{ category }}
                                </button>
                            </div>
                        </div>
                        <div class="grid grid-cols-12 gap-4 p-5">
                            <div
                                v-for="product in filteredProducts"
                                :key="product.id"
                                class="col-span-12 md:col-span-6"
                            >
                                <label
                                    class="flex h-full cursor-pointer items-center gap-3.5 rounded-lg border border-slate-200/70 p-3.5 dark:border-darkmode-400"
                                >
                                    <img
                                        v-if="product.photo_url"
                                        :src="product.photo_url"
                                        :alt="product.name"
                                        class="h-12 w-12 shrink-0 rounded-lg object-cover"
                                        loading="lazy"
                                    />
                                    <div
                                        v-else
                                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-300 dark:bg-darkmode-400"
                                    >
                                        <Lucide
                                            icon="UtensilsCrossed"
                                            class="h-5 w-5"
                                        />
                                    </div>
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate text-sm font-medium"
                                            >{{ product.name }}</span
                                        >
                                        <span
                                            class="block text-xs text-slate-500"
                                            >{{ product.category }} ·
                                            {{ money(product.price) }}</span
                                        >
                                    </span>
                                    <FormSwitch class="shrink-0">
                                        <FormSwitch.Input
                                            :checked="
                                                product.available_in_menu
                                            "
                                            type="checkbox"
                                            @change="toggleProduct(product)"
                                        />
                                    </FormSwitch>
                                </label>
                            </div>
                            <p
                                v-if="!filteredProducts.length"
                                class="col-span-12 py-8 text-center text-sm text-slate-400"
                            >
                                {{
                                    products.length
                                        ? 'Ningún producto coincide con la búsqueda.'
                                        : 'No hay productos activos. Créalos en Inventario y vuelve aquí.'
                                }}
                            </p>
                        </div>
                        <div
                            class="border-t border-dashed border-slate-300/70 px-5 py-3.5"
                        >
                            <FormHelp>
                                Aquí solo eliges qué se ofrece al huésped; los
                                productos, precios y fotos se administran en
                                Inventario.
                            </FormHelp>
                        </div>
                    </div>
                </div>

                <!-- Top productos -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="box box--stacked flex h-full flex-col">
                        <div
                            class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                        >
                            <Lucide
                                icon="TrendingUp"
                                class="h-4 w-4 stroke-[1.5] text-primary"
                            />
                            <h2 class="text-base font-medium">
                                Más pedidos (7 días)
                            </h2>
                        </div>
                        <div
                            class="flex flex-1 flex-col divide-y divide-dashed divide-slate-300/70 px-5"
                        >
                            <div
                                v-for="(top, index) in topProducts"
                                :key="top.name"
                                class="flex items-center gap-3 py-3"
                            >
                                <span
                                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-medium text-primary"
                                    >{{ index + 1 }}</span
                                >
                                <span class="min-w-0 flex-1 truncate text-sm">{{
                                    top.name
                                }}</span>
                                <span class="text-sm font-medium"
                                    >{{ top.qty }} uds</span
                                >
                            </div>
                            <div
                                v-if="!topProducts.length"
                                class="flex flex-1 flex-col items-center justify-center gap-2 py-10 text-center text-slate-400"
                            >
                                <Lucide icon="TrendingUp" class="h-8 w-8" />
                                <p class="text-sm">
                                    Cuando lleguen pedidos verás aquí lo que
                                    más se antoja.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══ Pestaña: Compartir y ajustes ═══ -->
        <template v-if="tab === 'ajustes'">
            <div class="mt-5 grid grid-cols-12 gap-5">
                <!-- Liga y QR -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="box box--stacked flex h-full flex-col">
                        <div
                            class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                        >
                            <Lucide
                                icon="QrCode"
                                class="h-4 w-4 stroke-[1.5] text-primary"
                            />
                            <h2 class="text-base font-medium">
                                Liga y códigos QR
                            </h2>
                        </div>
                        <div
                            class="flex flex-1 flex-col items-center gap-4 p-5"
                        >
                            <img
                                v-if="qrDataUrl"
                                :src="qrDataUrl"
                                alt="QR del menú"
                                class="h-44 w-44 rounded-xl border border-slate-200/70 p-2 dark:border-darkmode-400"
                            />
                            <div
                                class="w-full truncate rounded-lg bg-slate-50 px-3 py-2 text-center font-mono text-xs text-slate-500 dark:bg-darkmode-400"
                                :title="menuUrl"
                            >
                                {{ menuUrl }}
                            </div>
                            <div
                                class="flex w-full flex-col gap-2 sm:flex-row"
                            >
                                <Button
                                    variant="outline-secondary"
                                    class="flex-1"
                                    @click="copyLink"
                                >
                                    <Lucide
                                        icon="Copy"
                                        class="mr-2 h-4 w-4"
                                    />
                                    Copiar liga
                                </Button>
                                <Button
                                    variant="outline-secondary"
                                    class="flex-1"
                                    :disabled="printingQr || !qrRooms.length"
                                    @click="printQrCodes"
                                >
                                    <Lucide
                                        icon="Printer"
                                        class="mr-2 h-4 w-4"
                                    />
                                    QR por habitación
                                </Button>
                            </div>
                            <FormHelp class="mt-auto">
                                El QR por habitación llega con el cuarto ya
                                puesto: imprime un cartel por habitación y
                                pégalo junto al teléfono o el minibar.
                            </FormHelp>
                        </div>
                    </div>
                </div>

                <!-- Cómo se paga -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="box box--stacked flex h-full flex-col">
                        <div
                            class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                        >
                            <Lucide
                                icon="Wallet"
                                class="h-4 w-4 stroke-[1.5] text-primary"
                            />
                            <h2 class="text-base font-medium">
                                Cómo se paga el pedido
                            </h2>
                        </div>
                        <div class="flex flex-1 flex-col gap-2.5 p-5">
                            <button
                                type="button"
                                class="flex w-full items-start gap-3 rounded-lg border p-3.5 text-left transition"
                                :class="[
                                    billingMode === 'hotel'
                                        ? 'border-primary bg-primary/5'
                                        : 'border-slate-200/70 hover:border-slate-300 dark:border-darkmode-400',
                                    !canManageProperty &&
                                        'cursor-not-allowed opacity-60',
                                ]"
                                :disabled="!canManageProperty"
                                :title="
                                    canManageProperty
                                        ? undefined
                                        : 'Solo el dueño puede cambiar el modo'
                                "
                                @click="setBillingMode('hotel')"
                            >
                                <Lucide
                                    icon="BedDouble"
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                    :class="
                                        billingMode === 'hotel'
                                            ? 'text-primary'
                                            : 'text-slate-400'
                                    "
                                />
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium"
                                        >Hotel</span
                                    >
                                    <span
                                        class="mt-0.5 block text-xs text-slate-500"
                                        >El pedido puede cargarse a la
                                        habitación y se paga al final, en el
                                        check-out (o al recibir, si el huésped
                                        prefiere).</span
                                    >
                                </span>
                            </button>
                            <button
                                type="button"
                                class="flex w-full items-start gap-3 rounded-lg border p-3.5 text-left transition"
                                :class="[
                                    billingMode === 'motel'
                                        ? 'border-primary bg-primary/5'
                                        : 'border-slate-200/70 hover:border-slate-300 dark:border-darkmode-400',
                                    !canManageProperty &&
                                        'cursor-not-allowed opacity-60',
                                ]"
                                :disabled="!canManageProperty"
                                :title="
                                    canManageProperty
                                        ? undefined
                                        : 'Solo el dueño puede cambiar el modo'
                                "
                                @click="setBillingMode('motel')"
                            >
                                <Lucide
                                    icon="Banknote"
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                    :class="
                                        billingMode === 'motel'
                                            ? 'text-primary'
                                            : 'text-slate-400'
                                    "
                                />
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium"
                                        >Motel</span
                                    >
                                    <span
                                        class="mt-0.5 block text-xs text-slate-500"
                                        >El pedido se paga SIEMPRE al
                                        recibirlo (efectivo o tarjeta); no hay
                                        cargo a la habitación.</span
                                    >
                                </span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Horario y entrega -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="box box--stacked flex h-full flex-col">
                        <div
                            class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                        >
                            <Lucide
                                icon="Clock"
                                class="h-4 w-4 stroke-[1.5] text-primary"
                            />
                            <h2 class="text-base font-medium">
                                Horario y entrega
                            </h2>
                        </div>
                        <div class="flex flex-1 flex-col gap-4 p-5">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label
                                        class="mb-1.5 block text-sm font-medium"
                                        >La cocina abre</label
                                    >
                                    <FormInput
                                        v-model="hoursForm.from"
                                        type="time"
                                        :disabled="!canManageProperty"
                                    />
                                </div>
                                <div>
                                    <label
                                        class="mb-1.5 block text-sm font-medium"
                                        >Cierra</label
                                    >
                                    <FormInput
                                        v-model="hoursForm.to"
                                        type="time"
                                        :disabled="!canManageProperty"
                                    />
                                </div>
                            </div>
                            <FormHelp>
                                Fuera de este horario la carta avisa y no
                                acepta pedidos. Déjalo vacío si la cocina
                                atiende siempre; un rango como 22:00 a 02:00
                                también vale.
                            </FormHelp>
                            <div>
                                <label
                                    class="mb-1.5 block text-sm font-medium"
                                    >Tiempo estimado de entrega
                                    (minutos)</label
                                >
                                <FormInput
                                    v-model="hoursForm.eta"
                                    type="number"
                                    min="5"
                                    max="240"
                                    placeholder="Sin estimado"
                                    :disabled="!canManageProperty"
                                />
                                <FormHelp
                                    >El huésped lo ve al confirmar su
                                    pedido.</FormHelp
                                >
                            </div>
                            <Button
                                variant="primary"
                                class="mt-auto w-full shadow-md shadow-primary/20"
                                :disabled="savingHours || !canManageProperty"
                                :title="
                                    canManageProperty
                                        ? undefined
                                        : 'Solo el dueño puede cambiar estos ajustes'
                                "
                                @click="saveHours"
                            >
                                <Lucide icon="Check" class="mr-2 h-4 w-4" />
                                {{
                                    savingHours
                                        ? 'Guardando…'
                                        : 'Guardar horario y entrega'
                                }}
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </RazeLayout>
</template>
