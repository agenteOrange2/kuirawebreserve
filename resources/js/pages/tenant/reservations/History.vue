<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormCheck, FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import ReopenDialog from './ReopenDialog.vue';

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

interface HistoryRow {
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
    starts_at_input: string;
    ends_at: string;
    ends_at_input: string;
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
    cancellation_reason: string | null;
    deposit_amount: string;
    payment_status: string;
    payment_status_label: string;
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
        data: HistoryRow[];
        links: PaginationLink[];
        total: number;
    };
    filters: { q: string; status: string; guest?: number | null };
    /** Con huésped a la vista el archivo muestra TODAS sus reservas. */
    guest?: { id: number; full_name: string; is_archived: boolean } | null;
    statusOptions: { value: string; label: string }[];
    canManage: boolean;
    /** Plazo del apartado del hotel (para el modal de reabrir). */
    holdMinutes: number;
}>();

const toast = useToasts();
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
            route('tenant.reservations.history'),
            {
                q: q.value || undefined,
                status: status.value || undefined,
                // Buscar dentro del historial de un huésped no lo saca de él.
                guest: props.guest?.id || undefined,
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
    completed: {
        class: 'bg-slate-100 text-slate-600 dark:bg-darkmode-400 dark:text-slate-300',
        icon: 'CircleCheckBig',
    },
    cancelled: { class: 'bg-danger/10 text-danger', icon: 'Ban' },
    no_show: { class: 'bg-pending/10 text-pending', icon: 'UserX' },
    // Con un huésped a la vista también salen sus reservas vigentes.
    pending: { class: 'bg-pending/10 text-pending', icon: 'Clock' },
    confirmed: { class: 'bg-success/10 text-success', icon: 'CircleCheck' },
    checked_in: { class: 'bg-info/10 text-info', icon: 'DoorOpen' },
};
const statusFor = (s: string) =>
    statusMeta[s] ?? {
        class: 'bg-slate-100 text-slate-600',
        icon: 'CircleHelp' as Icon,
    };
const friendlyStatusLabel = (status: string, label: string) =>
    status === 'no_show' ? 'No llegó' : label;

const sourceChannelLabel: Record<string, string> = {
    front_desk: 'Recepción',
    phone: 'Teléfono',
    web: 'Sitio web',
    whatsapp: 'WhatsApp',
    walk_in: 'Llegó sin reserva',
    agent: 'Asistente',
};

function paymentBadge(r: HistoryRow): string {
    if (r.payment_status === 'paid') return 'bg-success/10 text-success';
    if (r.payment_status === 'deposit_paid') return 'bg-info/10 text-info';
    return 'bg-slate-100 text-slate-500 dark:bg-darkmode-400';
}

// ── Detalle ──
const detail = ref<HistoryRow | null>(null);

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

// ── Eliminar (individual y en masa; el backend solo acepta historial) ──
const selectedIds = ref<number[]>([]);
const deleteIds = ref<number[]>([]);
const deleteOpen = ref(false);
const deleteBusy = ref(false);

// ── Reabrir / reagendar (mismo modal que /reservas) ──
const reopenTarget = ref<HistoryRow | null>(null);

const isReopenable = (r: HistoryRow) =>
    ['cancelled', 'no_show'].includes(r.status);

// Borrar es solo para lo que ya salió del flujo. Con un huésped a la vista
// la lista trae también sus reservas vigentes, y esas no se tocan desde
// aquí (el backend tampoco las acepta): se administran en /reservas.
const isDeletable = (r: HistoryRow) =>
    ['completed', 'cancelled', 'no_show'].includes(r.status);

function openReopen(r: HistoryRow) {
    reopenTarget.value = r;
    detail.value = null;
}

function onReopened() {
    reopenTarget.value = null;
    router.reload({ preserveScroll: true } as any);
}

const deletableRows = computed(() =>
    props.reservations.data.filter((r) => isDeletable(r)),
);
const allSelected = computed(
    () =>
        deletableRows.value.length > 0 &&
        deletableRows.value.every((r) => selectedIds.value.includes(r.id)),
);
const deleteRows = computed(() =>
    props.reservations.data.filter((r) => deleteIds.value.includes(r.id)),
);

function toggleRow(id: number) {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((x) => x !== id)
        : [...selectedIds.value, id];
}
function toggleAll() {
    selectedIds.value = allSelected.value
        ? []
        : deletableRows.value.map((r) => r.id);
}

function askDelete(ids: number[]) {
    deleteIds.value = ids;
    deleteOpen.value = true;
}

async function submitDelete() {
    if (deleteBusy.value || !deleteIds.value.length) return;
    deleteBusy.value = true;
    try {
        const { data } = await axios.delete('/api/reservations', {
            data: { ids: deleteIds.value },
        });
        deleteOpen.value = false;
        detail.value = null;
        selectedIds.value = selectedIds.value.filter(
            (id) => !deleteIds.value.includes(id),
        );
        deleteIds.value = [];
        toast.success(
            'Historial depurado',
            `${data.deleted} reserva(s) eliminada(s) definitivamente.`,
        );
        router.reload({ only: ['reservations'] });
    } catch (error: any) {
        toast.error(
            'No se pudo eliminar',
            error.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        deleteBusy.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Historial de reservas">
        <div class="mt-2">
            <!-- Encabezado -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="History" class="h-4 w-4" />
                    </div>
                    <div>
                        <h1 class="text-base font-medium">
                            Historial de reservas
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ property.name }} · {{ reservations.total }}
                            completadas, canceladas o huéspedes que no llegaron
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        :as="Link"
                        :href="route('tenant.reservations')"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="ArrowLeft"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Volver a reservas
                    </Button>
                </div>
            </div>

            <div class="box box--stacked mt-5">
                <!-- Filtros -->
                <div
                    class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div class="relative w-full sm:w-72">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                        />
                        <FormInput
                            v-model="q"
                            type="text"
                            placeholder="Buscar por huésped, código o habitación…"
                            class="pl-9"
                        />
                    </div>
                    <FormSelect v-model="status" class="w-full sm:w-48">
                        <option value="">Todos los estados</option>
                        <option
                            v-for="option in statusOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{
                                friendlyStatusLabel(option.value, option.label)
                            }}
                        </option>
                    </FormSelect>
                    <!-- De quién es este historial: se llega desde su ficha. -->
                    <div
                        v-if="guest"
                        class="flex items-center gap-1.5 rounded-full bg-primary/5 py-1 pr-1.5 pl-3 text-xs text-primary"
                    >
                        <Lucide icon="User" class="h-3.5 w-3.5" />
                        <Link
                            :href="route('tenant.guests.show', guest.id)"
                            class="font-medium hover:underline"
                        >
                            {{ guest.full_name }}
                        </Link>
                        <Link
                            :href="route('tenant.reservations.history')"
                            class="rounded-full p-1 text-primary/60 hover:bg-primary/10 hover:text-primary"
                            title="Ver el historial completo del hotel"
                        >
                            <Lucide icon="X" class="h-3.5 w-3.5" />
                        </Link>
                    </div>
                    <template v-if="canManage && selectedIds.length">
                        <span class="ml-auto text-xs text-slate-500"
                            >{{ selectedIds.length }} seleccionada(s)</span
                        >
                        <button
                            type="button"
                            class="text-xs font-medium text-primary hover:underline"
                            @click="selectedIds = []"
                        >
                            Quitar selección
                        </button>
                        <Button
                            variant="danger"
                            class="rounded-[0.5rem] !px-3 !py-1.5 text-xs"
                            @click="askDelete(selectedIds)"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            Eliminar seleccionadas
                        </Button>
                    </template>
                </div>

                <!-- Móvil: tarjetas apiladas. La tabla de ocho columnas se
                     arrastraba de lado en el celular. -->
                <div
                    v-if="reservations.data.length"
                    class="space-y-2 p-4 sm:hidden"
                >
                    <div
                        v-for="r in reservations.data"
                        :key="`card-${r.id}`"
                        class="rounded-lg border border-slate-200/70 bg-white p-3 dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <div class="flex items-start gap-2">
                            <FormCheck.Input
                                v-if="canManage && isDeletable(r)"
                                type="checkbox"
                                class="mt-1 shrink-0"
                                :checked="selectedIds.includes(r.id)"
                                @change="toggleRow(r.id)"
                            />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">
                                    {{ r.guest_name ?? 'Anónimo' }}
                                </div>
                                <div class="mt-0.5 text-xs text-slate-500">
                                    {{ r.code }} · Hab. {{ r.room ?? '—' }}
                                </div>
                            </div>
                            <span
                                class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="statusFor(r.status).class"
                            >
                                <Lucide
                                    :icon="statusFor(r.status).icon"
                                    class="h-3 w-3"
                                />
                                {{
                                    friendlyStatusLabel(
                                        r.status,
                                        r.status_label,
                                    )
                                }}
                            </span>
                        </div>

                        <div
                            class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"
                        >
                            <span>{{ r.starts_at }} → {{ r.ends_at }}</span>
                            <span class="font-medium text-slate-600"
                                >${{ r.total_amount }}</span
                            >
                            <span
                                class="rounded-full px-2 py-0.5 text-[11px]"
                                :class="paymentBadge(r)"
                            >
                                {{ r.payment_status_label }}
                            </span>
                        </div>
                        <p
                            v-if="r.cancellation_reason"
                            class="mt-1 text-[11px] text-slate-400"
                        >
                            {{ r.cancellation_reason }}
                        </p>

                        <div
                            class="mt-2.5 flex items-center gap-2 border-t border-dashed border-slate-200/70 pt-2.5 dark:border-darkmode-400"
                        >
                            <button
                                type="button"
                                class="inline-flex h-8 items-center gap-1.5 rounded-[0.5rem] border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 dark:border-darkmode-400 dark:bg-darkmode-600"
                                @click="detail = r"
                            >
                                <Lucide icon="Eye" class="h-3.5 w-3.5" />
                                Ver detalle
                            </button>
                            <button
                                v-if="canManage && isReopenable(r)"
                                type="button"
                                class="inline-flex h-8 items-center gap-1.5 rounded-[0.5rem] border border-primary/30 bg-white px-3 text-xs font-medium text-primary dark:bg-darkmode-600"
                                @click="openReopen(r)"
                            >
                                <Lucide icon="RotateCcw" class="h-3.5 w-3.5" />
                                Reabrir
                            </button>
                            <button
                                v-if="canManage && isDeletable(r)"
                                type="button"
                                class="ml-auto flex h-8 w-8 items-center justify-center rounded-full text-danger transition hover:bg-danger/10"
                                title="Eliminar definitivamente"
                                @click="askDelete([r.id])"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Escritorio: tabla -->
                <div
                    class="hidden overflow-auto p-4 sm:block lg:overflow-visible"
                >
                    <Table v-if="reservations.data.length" striped>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th v-if="canManage" class="w-10">
                                    <FormCheck.Input
                                        type="checkbox"
                                        :checked="allSelected"
                                        title="Seleccionar esta página"
                                        @change="toggleAll"
                                    />
                                </Table.Th>
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
                                <Table.Td v-if="canManage" class="w-10">
                                    <FormCheck.Input
                                        v-if="isDeletable(r)"
                                        type="checkbox"
                                        :checked="selectedIds.includes(r.id)"
                                        @change="toggleRow(r.id)"
                                    />
                                </Table.Td>
                                <Table.Td>
                                    <span
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                    >
                                        {{ r.code }}
                                    </span>
                                    <div class="mt-1 text-sm font-medium">
                                        {{ r.guest_name ?? 'Anónimo' }}
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
                                </Table.Td>
                                <Table.Td>${{ r.total_amount }}</Table.Td>
                                <Table.Td>
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-xs"
                                        :class="paymentBadge(r)"
                                    >
                                        {{ r.payment_status_label }}
                                    </span>
                                </Table.Td>
                                <Table.Td>
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs"
                                        :class="statusFor(r.status).class"
                                    >
                                        <Lucide
                                            :icon="statusFor(r.status).icon"
                                            class="h-3 w-3"
                                        />
                                        {{
                                            friendlyStatusLabel(
                                                r.status,
                                                r.status_label,
                                            )
                                        }}
                                    </span>
                                    <span
                                        v-if="r.cancellation_reason"
                                        class="block max-w-[200px] truncate text-xs text-slate-400"
                                        :title="r.cancellation_reason"
                                        >{{ r.cancellation_reason }}</span
                                    >
                                </Table.Td>
                                <Table.Td>
                                    <div class="flex justify-end gap-1">
                                        <button
                                            v-if="canManage && isReopenable(r)"
                                            class="rounded-md p-1.5 text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                            title="Reabrir o reagendar"
                                            @click="openReopen(r)"
                                        >
                                            <Lucide
                                                icon="RotateCcw"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                        <button
                                            class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 dark:hover:bg-darkmode-400"
                                            title="Ver detalle"
                                            @click="detail = r"
                                        >
                                            <Lucide
                                                icon="Eye"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                        <button
                                            v-if="canManage && isDeletable(r)"
                                            class="rounded-md p-1.5 text-danger hover:bg-danger/10"
                                            title="Eliminar definitivamente"
                                            @click="askDelete([r.id])"
                                        >
                                            <Lucide
                                                icon="Trash2"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </div>

                <!-- El vacío y la paginación viven FUERA del bloque de
                     escritorio: si no, en el celular no se veían. -->
                <div
                    v-if="!reservations.data.length"
                    class="px-4 py-10 text-center text-slate-500"
                >
                    {{
                        filters.q || filters.status
                            ? 'Nada coincide con la búsqueda.'
                            : guest
                              ? 'Este huésped todavía no tiene reservas.'
                              : 'Aún no hay historial.'
                    }}
                </div>

                <!-- Paginación -->
                <div
                    v-if="reservations.links.length > 3"
                    class="flex flex-wrap justify-center gap-1 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <template v-for="(link, i) in reservations.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-state
                            class="rounded-md px-3 py-1.5 text-sm"
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
                            class="px-3 py-1.5 text-sm text-slate-400"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>

        <!-- Detalle de la reserva -->
        <Dialog :open="detail !== null" size="lg" @close="detail = null">
            <Dialog.Panel v-if="detail" class="sm:w-[94vw] lg:w-[640px]">
                <div class="flex max-h-[calc(100dvh-6rem)] flex-col">
                    <div class="flex items-start gap-3.5 p-6 pb-4">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="History" class="h-5 w-5" />
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
                            {{
                                friendlyStatusLabel(
                                    detail.status,
                                    detail.status_label,
                                )
                            }}
                        </span>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-6 pb-2">
                        <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
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
                            v-if="detail.cancellation_reason"
                            class="mt-3 rounded-lg bg-danger/5 px-3.5 py-2.5 text-sm text-danger"
                        >
                            Motivo de cancelación:
                            {{ detail.cancellation_reason }}
                        </p>
                        <p
                            v-if="detail.guest_notes"
                            class="mt-3 text-sm text-slate-500"
                        >
                            Nota del huésped: {{ detail.guest_notes }}
                        </p>
                        <p
                            v-if="detail.notes"
                            class="mt-2 text-sm text-slate-500"
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
                        class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200/60 px-4 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            v-if="canManage && isDeletable(detail)"
                            variant="outline-danger"
                            class="h-9 rounded-[0.5rem] text-xs"
                            @click="askDelete([detail.id])"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            Eliminar
                        </Button>
                        <Button
                            v-if="canManage && isReopenable(detail)"
                            variant="primary"
                            class="ml-auto h-9 rounded-[0.5rem] text-xs"
                            @click="openReopen(detail)"
                        >
                            <Lucide
                                icon="RotateCcw"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Reabrir o reagendar
                        </Button>
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] text-xs"
                            :class="
                                canManage && isReopenable(detail)
                                    ? ''
                                    : 'ml-auto'
                            "
                            @click="detail = null"
                        >
                            Cerrar
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <ReopenDialog
            :reservation="reopenTarget"
            :hold-minutes="holdMinutes"
            @close="reopenTarget = null"
            @done="onReopened"
        />

        <!-- Confirmación de borrado -->
        <Dialog :open="deleteOpen" @close="deleteOpen = false">
            <Dialog.Panel class="sm:w-[94vw] lg:w-[560px]">
                <div class="flex max-h-[calc(100dvh-6rem)] flex-col">
                    <div class="flex items-start gap-3.5 p-6 pb-4">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-base font-medium">
                                ¿Eliminar {{ deleteRows.length }} reserva(s) del
                                historial?
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Se borran definitivamente junto con sus pagos
                                registrados y su línea de tiempo. Esta acción no
                                se puede deshacer.
                            </p>
                        </div>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-6">
                        <div
                            class="rounded-lg border border-dashed border-slate-300/70 dark:border-darkmode-400"
                        >
                            <div
                                v-for="r in deleteRows"
                                :key="r.id"
                                class="flex items-center justify-between gap-3 border-b border-dashed border-slate-200/80 px-3.5 py-2.5 text-sm last:border-0 dark:border-darkmode-400"
                            >
                                <div class="min-w-0 truncate">
                                    <span class="font-medium">{{
                                        r.guest_name ?? 'Anónimo'
                                    }}</span>
                                    <span class="text-slate-500">
                                        · {{ r.code }}</span
                                    >
                                </div>
                                <span class="text-xs text-slate-500">{{
                                    friendlyStatusLabel(
                                        r.status,
                                        r.status_label,
                                    )
                                }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 p-5">
                        <Button
                            variant="outline-secondary"
                            class="rounded-[0.5rem]"
                            :disabled="deleteBusy"
                            @click="deleteOpen = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            variant="danger"
                            class="rounded-[0.5rem]"
                            :disabled="deleteBusy"
                            @click="submitDelete"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            {{ deleteBusy ? 'Eliminando…' : 'Eliminar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
