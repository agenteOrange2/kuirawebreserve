<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface PriceLine {
    concept: string;
    amount: number;
}

interface FrozenLine {
    name: string;
    qty: number;
    total: number;
}

interface ExperienceLine {
    name: string;
    starts_at: string;
    people: number;
    total: number;
}

interface UpcomingRow {
    id: number;
    code: string;
    guest_name: string | null;
    guest_phone: string | null;
    guest_email: string | null;
    num_people: number;
    room: string | null;
    room_type: string | null;
    rate_plan: string | null;
    starts_at: string;
    ends_at: string;
    starts_today: boolean;
    status: string;
    status_label: string;
    total_amount: string;
    extra_charges: PriceLine[];
    products: FrozenLine[];
    extras: FrozenLine[];
    experiences: ExperienceLine[];
    source_channel: string;
    notes: string | null;
    guest_notes: string | null;
    deposit_amount: string;
    payment_status: string;
    payment_status_label: string;
    payment_due_at: string | null;
    payment_overdue: boolean;
    paid_total: number;
    pending_balance: number;
    updated_at: string | null;
    timeline: {
        id: string;
        message: string;
        by: string | null;
        at: string | null;
    }[];
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    property: { id: number; name: string };
    reservations: {
        data: UpcomingRow[];
        links: PaginationLink[];
        total: number;
    };
    filters: { q: string; status: string };
    statusOptions: { value: string; label: string }[];
    canManage: boolean;
}>();

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n || 0);

// ── Buscador y filtro (reactivos, con debounce) ──
const q = ref(props.filters.q);
const status = ref(props.filters.status);

let timer: ReturnType<typeof setTimeout> | null = null;
watch([q, status], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            route('tenant.reservations.upcoming'),
            {
                q: q.value || undefined,
                status: status.value || undefined,
            },
            {
                preserveState: true,
                replace: true,
                only: ['reservations', 'filters'],
            },
        );
    }, 350);
});

// Mismo semáforo de estados que /reservas.
const statusMeta: Record<string, { class: string; icon: Icon }> = {
    pending: { class: 'bg-warning/10 text-warning', icon: 'Clock' },
    confirmed: { class: 'bg-primary/10 text-primary', icon: 'CircleCheck' },
};
const statusFor = (s: string) =>
    statusMeta[s] ?? {
        class: 'bg-slate-100 text-slate-600',
        icon: 'CircleHelp' as Icon,
    };

const sourceChannelLabel: Record<string, string> = {
    front_desk: 'Recepción',
    phone: 'Teléfono',
    web: 'Sitio web',
    whatsapp: 'WhatsApp',
    walk_in: 'Llegó sin reserva',
    agent: 'Asistente',
};

function paymentBadge(r: UpcomingRow): string {
    if (r.payment_overdue) return 'bg-danger/10 text-danger';
    if (r.payment_status === 'paid') return 'bg-success/10 text-success';
    // Abonó algo pero no llegó al anticipo: que se note, no "sin pago".
    if (r.payment_status === 'partial') return 'bg-pending/10 text-pending';
    if (r.payment_status === 'deposit_paid') return 'bg-info/10 text-info';
    return 'bg-slate-100 text-slate-500 dark:bg-darkmode-400';
}

// Las acciones (confirmar, llegada, cancelar, cobrar) viven en /reservas,
// donde está el modal completo; aquí se abre esa reserva enfocada.
const openInList = (r: UpcomingRow) =>
    `${route('tenant.reservations.operation')}?reservation=${r.id}`;

// ── Detalle ──
const toast = useToasts();

const detail = ref<UpcomingRow | null>(null);

// ── Acciones desde el modal: llegada, no llegó y cancelar ──
// Antes solo se podía "atender en reservas"; quien revisa las próximas ya
// tiene la reserva enfrente y necesita resolverla ahí (pedido del hotel de
// cabañas 2026-09-11).
const cancelKind = ref<'cancel' | 'no_show' | null>(null);
const cancelTarget = ref<UpcomingRow | null>(null);
const cancelReason = ref('');
const cancelBusy = ref(false);

function askCancel(row: UpcomingRow, kind: 'cancel' | 'no_show') {
    cancelTarget.value = row;
    cancelKind.value = kind;
    cancelReason.value = '';
}

async function submitCancel() {
    const row = cancelTarget.value;
    if (!row || !cancelKind.value || cancelBusy.value) return;

    const noShow = cancelKind.value === 'no_show';
    cancelBusy.value = true;
    try {
        await axios.patch(`/api/reservations/${row.id}/cancel`, {
            no_show: noShow,
            reason: cancelReason.value.trim() || null,
        });
        toast.success(
            noShow
                ? 'Se registró que el huésped no llegó'
                : 'Reserva cancelada',
            `${row.code} pasó al historial y la habitación quedó libre.`,
        );
        cancelKind.value = null;
        cancelTarget.value = null;
        detail.value = null;
        router.reload({ preserveScroll: true } as any);
    } catch (e: any) {
        toast.error(
            'No se pudo completar la acción',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        cancelBusy.value = false;
    }
}

const detailLines = computed(() => {
    if (!detail.value) return [];
    const r = detail.value;
    return [
        ...r.products.map((l) => ({
            key: `p-${l.name}`,
            label: `${l.qty} × ${l.name}`,
            amount: l.total,
        })),
        ...r.extras.map((l) => ({
            key: `e-${l.name}`,
            label: `${l.qty} × ${l.name}`,
            amount: l.total,
        })),
        ...r.experiences.map((l) => ({
            key: `x-${l.name}`,
            label: `${l.name} (${l.people} pers.)`,
            amount: l.total,
        })),
        ...r.extra_charges.map((l) => ({
            key: `c-${l.concept}`,
            label: l.concept,
            amount: l.amount,
        })),
    ];
});
</script>

<template>
    <RazeLayout title="Próximas reservas">
        <div class="mt-2">
            <!-- Encabezado estándar del panel: icono, título y subtítulo a la
                 izquierda, acciones a la derecha, todo dentro del box. -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="CalendarDays" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Próximas reservas</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ property.name }} · {{ reservations.total }}
                            apartadas de hoy en adelante
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center"
                >
                    <Button
                        :as="Link"
                        :href="route('tenant.reservations.calendar')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="CalendarRange"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.3]"
                        />
                        Calendario
                    </Button>
                    <Button
                        :as="Link"
                        :href="route('tenant.reservations')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="ArrowLeft"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.3]"
                        />
                        Volver a reservas
                    </Button>
                </div>
            </div>

            <div class="box box--stacked mt-5">
                <!-- Filtros, en franja gris dentro del mismo box -->
                <div
                    class="flex flex-col gap-2.5 border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center dark:border-darkmode-400 dark:bg-darkmode-700/40"
                >
                    <div class="relative w-full min-w-0 sm:w-72">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                        />
                        <FormInput
                            v-model="q"
                            type="text"
                            placeholder="Buscar huésped, teléfono, código o habitación"
                            class="h-9 pl-9 text-xs"
                        />
                    </div>
                    <FormSelect
                        v-model="status"
                        class="h-9 w-full text-xs sm:w-48"
                    >
                        <option value="">Todos los estados</option>
                        <option
                            v-for="option in statusOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </FormSelect>
                    <span
                        class="ml-auto hidden text-xs text-slate-500 lg:block"
                    >
                        Las llegadas más cercanas también están en
                        <Link
                            :href="route('tenant.reservations.operation')"
                            class="font-medium text-primary hover:underline"
                            >la operación del día</Link
                        >.
                    </span>
                </div>

                <!-- Tabla completa: solo desde lg, que es donde caben las
                     siete columnas sin arrastrar la pantalla de lado. -->
                <div
                    v-if="reservations.data.length"
                    class="hidden overflow-auto p-4 lg:block lg:overflow-visible"
                >
                    <Table striped>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Huésped</Table.Th>
                                <Table.Th>Habitación</Table.Th>
                                <Table.Th>Llegada → Salida</Table.Th>
                                <Table.Th>Total</Table.Th>
                                <Table.Th>Pago</Table.Th>
                                <Table.Th>Estado</Table.Th>
                                <Table.Th class="text-right">Acciones</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr
                                v-for="r in reservations.data"
                                :key="r.id"
                            >
                                <Table.Td>
                                    <span
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                    >
                                        {{ r.code }}
                                    </span>
                                    <div class="mt-1 text-sm font-medium">
                                        {{ r.guest_name ?? 'Anónimo' }}
                                    </div>
                                    <div
                                        v-if="r.guest_phone"
                                        class="text-xs text-slate-500"
                                    >
                                        {{ r.guest_phone }}
                                    </div>
                                </Table.Td>
                                <Table.Td>
                                    <span class="font-medium">{{
                                        r.room ?? '—'
                                    }}</span>
                                    <span
                                        class="block text-xs text-slate-500"
                                        >{{ r.room_type }}</span
                                    >
                                </Table.Td>
                                <Table.Td class="text-sm">
                                    {{ r.starts_at }}
                                    <span class="text-slate-400">→</span>
                                    {{ r.ends_at }}
                                    <span
                                        v-if="r.starts_today"
                                        class="ml-1 rounded-full bg-success/10 px-1.5 text-[11px] text-success"
                                        >llega hoy</span
                                    >
                                </Table.Td>
                                <Table.Td>${{ r.total_amount }}</Table.Td>
                                <Table.Td>
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="paymentBadge(r)"
                                    >
                                        {{ r.payment_status_label }}
                                    </span>
                                    <span
                                        v-if="r.pending_balance > 0"
                                        class="block text-xs text-slate-500"
                                        >Pendiente
                                        {{ money(r.pending_balance) }}</span
                                    >
                                </Table.Td>
                                <Table.Td>
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="statusFor(r.status).class"
                                    >
                                        <Lucide
                                            :icon="statusFor(r.status).icon"
                                            class="h-3 w-3"
                                        />
                                        {{ r.status_label }}
                                    </span>
                                </Table.Td>
                                <Table.Td>
                                    <div class="flex justify-end gap-1">
                                        <button
                                            type="button"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                            title="Ver detalle"
                                            @click="detail = r"
                                        >
                                            <Lucide
                                                icon="Eye"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                        <Link
                                            v-if="canManage"
                                            :href="openInList(r)"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                            title="Abrir en la lista de reservas para atenderla"
                                        >
                                            <Lucide
                                                icon="SquareArrowOutUpRight"
                                                class="h-4 w-4"
                                            />
                                        </Link>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </div>

                <!-- Renglones en móvil y tablet: los mismos datos, apilados,
                     sin scroll horizontal. -->
                <div
                    v-if="reservations.data.length"
                    class="divide-y divide-slate-200/60 lg:hidden dark:divide-darkmode-400"
                >
                    <div
                        v-for="r in reservations.data"
                        :key="r.id"
                        class="px-4 py-3.5"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <span
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                        >{{ r.code }}</span
                                    >
                                    <span
                                        v-if="r.starts_today"
                                        class="rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success"
                                        >Llega hoy</span
                                    >
                                </div>
                                <div
                                    class="mt-1.5 truncate text-sm font-medium"
                                >
                                    {{ r.guest_name ?? 'Anónimo' }}
                                </div>
                                <a
                                    v-if="r.guest_phone"
                                    :href="`tel:${r.guest_phone}`"
                                    class="text-xs text-slate-500 transition hover:text-primary"
                                    >{{ r.guest_phone }}</a
                                >
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="text-sm font-medium">
                                    ${{ r.total_amount }}
                                </div>
                                <div
                                    v-if="r.pending_balance > 0"
                                    class="mt-0.5 text-[11px] text-slate-500"
                                >
                                    Pendiente {{ money(r.pending_balance) }}
                                </div>
                            </div>
                        </div>

                        <div
                            class="mt-2 flex flex-col gap-1 text-xs text-slate-500 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-3"
                        >
                            <span
                                class="inline-flex min-w-0 items-center gap-1.5"
                            >
                                <Lucide
                                    icon="BedDouble"
                                    class="h-3.5 w-3.5 shrink-0 stroke-[1.3]"
                                />
                                <span class="truncate">
                                    {{ r.room ?? 'Sin asignar' }}
                                    <span
                                        v-if="r.room_type"
                                        class="text-slate-400"
                                        >· {{ r.room_type }}</span
                                    >
                                </span>
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <Lucide
                                    icon="CalendarDays"
                                    class="h-3.5 w-3.5 shrink-0 stroke-[1.3]"
                                />
                                {{ r.starts_at }}
                                <span class="text-slate-400">→</span>
                                {{ r.ends_at }}
                            </span>
                        </div>

                        <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="statusFor(r.status).class"
                            >
                                <Lucide
                                    :icon="statusFor(r.status).icon"
                                    class="h-3 w-3"
                                />
                                {{ r.status_label }}
                            </span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="paymentBadge(r)"
                            >
                                {{ r.payment_status_label }}
                            </span>
                            <button
                                type="button"
                                class="ml-auto flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                title="Ver detalle"
                                @click="detail = r"
                            >
                                <Lucide icon="Eye" class="h-4 w-4" />
                            </button>
                            <Link
                                v-if="canManage"
                                :href="openInList(r)"
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                title="Abrir en la lista de reservas para atenderla"
                            >
                                <Lucide
                                    icon="SquareArrowOutUpRight"
                                    class="h-4 w-4"
                                />
                            </Link>
                        </div>
                    </div>
                </div>

                <div
                    v-if="!reservations.data.length"
                    class="flex flex-col items-center gap-3 px-6 py-12 text-center"
                >
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 text-primary"
                    >
                        <Lucide icon="CalendarDays" class="h-4 w-4" />
                    </div>
                    <p class="text-sm text-slate-500">
                        {{
                            filters.q || filters.status
                                ? 'Nada coincide con la búsqueda.'
                                : 'No hay reservas apartadas a futuro.'
                        }}
                    </p>
                </div>

                <!-- Paginación, en franja propia -->
                <div
                    v-if="reservations.links.length > 3"
                    class="flex flex-wrap justify-center gap-1 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <template v-for="(link, i) in reservations.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-state
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

        <!-- Detalle de la reserva -->
        <Dialog :open="detail !== null" size="lg" @close="detail = null">
            <Dialog.Panel v-if="detail">
                <div class="flex max-h-[calc(100dvh-6rem)] flex-col">
                    <div class="flex items-start gap-3.5 p-5 pb-4">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="CalendarDays" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                {{ detail.guest_name ?? 'Anónimo' }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ detail.code }} · actualizada
                                {{ detail.updated_at ?? '—' }}
                            </p>
                        </div>
                        <span
                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs"
                            :class="statusFor(detail.status).class"
                        >
                            <Lucide
                                :icon="statusFor(detail.status).icon"
                                class="h-3 w-3"
                            />
                            {{ detail.status_label }}
                        </span>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-5 pb-2">
                        <div
                            class="grid grid-cols-1 gap-x-4 gap-y-3 text-sm sm:grid-cols-2"
                        >
                            <div>
                                <div class="text-xs text-slate-400">
                                    Habitación
                                </div>
                                <div class="font-medium">
                                    {{ detail.room ?? 'Sin asignar' }}
                                    <span
                                        v-if="detail.room_type"
                                        class="font-normal text-slate-500"
                                    >
                                        · {{ detail.room_type }}</span
                                    >
                                </div>
                                <div
                                    v-if="detail.rate_plan"
                                    class="text-xs text-slate-500"
                                >
                                    Tipo de estancia: {{ detail.rate_plan }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-slate-400">
                                    Estancia
                                </div>
                                <div class="font-medium">
                                    {{ detail.starts_at }}
                                    <span class="text-slate-400">→</span>
                                    {{ detail.ends_at }}
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ detail.num_people }}
                                    {{
                                        detail.num_people === 1
                                            ? 'persona'
                                            : 'personas'
                                    }}
                                    ·
                                    {{
                                        sourceChannelLabel[
                                            detail.source_channel
                                        ] ?? detail.source_channel
                                    }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-slate-400">
                                    Contacto
                                </div>
                                <div>{{ detail.guest_phone ?? '—' }}</div>
                                <div class="text-xs text-slate-500">
                                    {{ detail.guest_email ?? '—' }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-slate-400">Pago</div>
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs"
                                    :class="paymentBadge(detail)"
                                >
                                    {{ detail.payment_status_label }}
                                </span>
                                <div class="mt-0.5 text-xs text-slate-500">
                                    Pagado {{ money(detail.paid_total) }} ·
                                    pendiente
                                    {{ money(detail.pending_balance) }}
                                </div>
                                <div
                                    v-if="detail.payment_due_at"
                                    class="text-xs"
                                    :class="
                                        detail.payment_overdue
                                            ? 'text-danger'
                                            : 'text-slate-500'
                                    "
                                >
                                    Saldo para el {{ detail.payment_due_at }}
                                </div>
                            </div>
                        </div>

                        <!-- Desglose -->
                        <div
                            class="mt-4 rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                        >
                            <div
                                v-for="line in detailLines"
                                :key="line.key"
                                class="flex items-center justify-between gap-3 border-b border-dashed border-slate-200/80 px-3.5 py-2 text-sm last:border-0 dark:border-darkmode-400"
                            >
                                <span class="min-w-0 truncate text-slate-600">{{
                                    line.label
                                }}</span>
                                <span>{{ money(line.amount) }}</span>
                            </div>
                            <div
                                class="flex items-center justify-between gap-3 px-3.5 py-2.5 text-sm font-medium"
                                :class="
                                    detailLines.length > 0 &&
                                    'border-t border-slate-200/70 dark:border-darkmode-400'
                                "
                            >
                                <span>Total</span>
                                <span>${{ detail.total_amount }}</span>
                            </div>
                        </div>

                        <p
                            v-if="detail.guest_notes"
                            class="mt-3 text-xs text-slate-500"
                        >
                            Nota del huésped: {{ detail.guest_notes }}
                        </p>
                        <p
                            v-if="detail.notes"
                            class="mt-2 text-xs whitespace-pre-line text-slate-500"
                        >
                            Nota interna: {{ detail.notes }}
                        </p>

                        <!-- Línea de tiempo -->
                        <div v-if="detail.timeline.length" class="mt-4">
                            <div
                                class="mb-2 text-xs font-medium text-slate-400"
                            >
                                Línea de tiempo
                            </div>
                            <div
                                v-for="item in detail.timeline"
                                :key="item.id"
                                class="flex items-start gap-2.5 border-l-2 border-slate-200 py-1.5 pl-3 text-sm dark:border-darkmode-400"
                            >
                                <div class="min-w-0">
                                    <div>{{ item.message }}</div>
                                    <div class="text-xs text-slate-400">
                                        {{ item.at }}
                                        <template v-if="item.by">
                                            · {{ item.by }}</template
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div
                        class="flex flex-col-reverse gap-2 border-t border-slate-200/60 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5 dark:border-darkmode-400"
                    >
                        <div
                            class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap"
                        >
                            <Button
                                v-if="canManage"
                                :as="Link"
                                :href="`/reservas/${detail.id}`"
                                variant="outline-primary"
                                class="h-9 rounded-[0.5rem] text-xs"
                            >
                                <Lucide
                                    icon="SquareArrowOutUpRight"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Abrir ficha
                            </Button>
                            <Button
                                v-if="canManage"
                                :as="Link"
                                :href="`/reservas/${detail.id}?checkin=1`"
                                variant="primary"
                                class="h-9 rounded-[0.5rem] text-xs"
                            >
                                <Lucide
                                    icon="LogIn"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Registrar llegada
                            </Button>
                            <Button
                                v-if="canManage"
                                variant="outline-warning"
                                class="h-9 rounded-[0.5rem] text-xs"
                                @click="askCancel(detail, 'no_show')"
                            >
                                <Lucide
                                    icon="UserX"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                No llegó
                            </Button>
                            <Button
                                v-if="canManage"
                                variant="outline-danger"
                                class="h-9 rounded-[0.5rem] text-xs"
                                @click="askCancel(detail, 'cancel')"
                            >
                                <Lucide icon="Ban" class="mr-1.5 h-3.5 w-3.5" />
                                Cancelar
                            </Button>
                        </div>
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] text-xs sm:ml-auto"
                            @click="detail = null"
                        >
                            Cerrar
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
        <!-- No llegó / Cancelar desde el modal -->
        <Dialog
            size="lg"
            :open="cancelKind !== null"
            @close="cancelKind = null"
        >
            <Dialog.Panel class="sm:w-[94vw] lg:w-[520px]">
                <form
                    v-if="cancelKind && cancelTarget"
                    class="flex flex-col"
                    @submit.prevent="submitCancel"
                >
                    <div class="flex items-start gap-3 px-5 pt-5">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border"
                            :class="
                                cancelKind === 'no_show'
                                    ? 'border-warning/10 bg-warning/10 text-warning'
                                    : 'border-danger/10 bg-danger/10 text-danger'
                            "
                        >
                            <Lucide
                                :icon="
                                    cancelKind === 'no_show' ? 'UserX' : 'Ban'
                                "
                                class="h-4 w-4"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                {{
                                    cancelKind === 'no_show'
                                        ? 'El huésped no llegó'
                                        : 'Cancelar reserva'
                                }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ cancelTarget.code }} ·
                                {{ cancelTarget.guest_name ?? 'Anónimo' }} ·
                                Hab. {{ cancelTarget.room ?? 'por asignar' }}.
                                Pasa al historial y la habitación queda libre;
                                se puede reabrir desde su ficha.
                            </p>
                        </div>
                    </div>
                    <div class="px-5 py-4">
                        <FormInput
                            v-model="cancelReason"
                            type="text"
                            maxlength="255"
                            class="h-9 text-xs"
                            placeholder="Motivo (queda en el historial)"
                        />
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            @click="cancelKind = null"
                            >Volver</Button
                        >
                        <Button
                            type="submit"
                            :variant="
                                cancelKind === 'no_show' ? 'warning' : 'danger'
                            "
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="cancelBusy"
                        >
                            {{
                                cancelBusy
                                    ? 'Guardando…'
                                    : cancelKind === 'no_show'
                                      ? 'Confirmar que no llegó'
                                      : 'Cancelar reserva'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
