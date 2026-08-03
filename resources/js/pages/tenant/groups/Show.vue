<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormLabel,
    FormSelect,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog, Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ReservationDetail {
    id: number;
    code: string;
    room_type: string | null;
    room_type_id: number;
    rate_plan_id: number;
    room: string | null;
    adults: number;
    children: number;
    starts_at: string;
    ends_at: string | null;
    total: number;
    status: string;
    status_label: string;
}

interface GroupExperienceRow {
    id: number;
    code: string;
    name: string | null;
    starts_at: string | null;
    people: number;
    total: number;
    status: string;
    status_label: string;
}

interface PaymentRequestRow {
    id: number;
    method: string;
    amount: number;
    amount_label: string;
    status: string;
    checkout_url: string | null;
    expires_at: string | null;
    created_at: string;
}

interface GroupDetail {
    id: number;
    code: string;
    guest_name: string | null;
    guest_phone: string | null;
    guest_email: string | null;
    notes: string | null;
    mode: string;
    total: number;
    paid_total: number;
    pending_balance: number;
    starts_at: string | null;
    ends_at: string | null;
    reservations_detail: ReservationDetail[];
    experiences: GroupExperienceRow[];
    payment_requests: PaymentRequestRow[];
}

interface RoomTypeOption {
    id: number;
    name: string;
    capacity: number;
    rooms_count: number;
}

// Sesiones disponibles para agregar un recorrido (mismo endpoint del wizard).
interface ExperienceSessionOption {
    id: number;
    starts_at: string;
    remaining: number;
}

interface ExperienceOption {
    id: number;
    name: string;
    price_label: string;
    min_people: number;
    max_people: number | null;
    sessions: ExperienceSessionOption[];
}

const props = defineProps<{
    group: GroupDetail;
    roomTypes: RoomTypeOption[];
    hasExperiencesModule: boolean;
    canManage: boolean;
}>();

const toast = useToasts();
const money = (n: number) =>
    `$${Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;
const formatDateTime = (iso: string) =>
    new Date(iso).toLocaleString('es-MX', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });

const statusClass: Record<string, string> = {
    pending: 'bg-pending/10 text-pending',
    confirmed: 'bg-success/10 text-success',
    checked_in: 'bg-primary/10 text-primary',
    completed: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    cancelled: 'bg-danger/10 text-danger',
    no_show: 'bg-danger/10 text-danger',
};

const liveStatuses = ['pending', 'confirmed', 'checked_in'];
const editableStatuses = ['pending', 'confirmed'];

const activeRoomsCount = computed(
    () =>
        props.group.reservations_detail.filter((reservation) =>
            liveStatuses.includes(reservation.status),
        ).length,
);

const totalGuests = computed(() =>
    props.group.reservations_detail.reduce(
        (total, reservation) =>
            total + reservation.adults + reservation.children,
        0,
    ),
);

const paymentProgress = computed(() => {
    if (props.group.total <= 0) {
        return 0;
    }

    return Math.min(
        100,
        Math.round((props.group.paid_total / props.group.total) * 100),
    );
});

const groupStatus = computed<{
    label: string;
    icon: Icon;
    class: string;
}>(() => {
    const statuses = props.group.reservations_detail.map(
        (reservation) => reservation.status,
    );

    if (statuses.includes('checked_in')) {
        return {
            label: 'Grupo alojado',
            icon: 'DoorOpen',
            class: 'bg-primary/10 text-primary',
        };
    }

    if (statuses.includes('confirmed')) {
        return {
            label: 'Grupo confirmado',
            icon: 'CircleCheck',
            class: 'bg-success/10 text-success',
        };
    }

    if (statuses.includes('pending')) {
        return {
            label: 'Por confirmar',
            icon: 'AlarmClock',
            class: 'bg-pending/10 text-pending',
        };
    }

    if (
        statuses.length > 0 &&
        statuses.every(
            (status) => status === 'cancelled' || status === 'no_show',
        )
    ) {
        return {
            label: 'Grupo cancelado',
            icon: 'Ban',
            class: 'bg-danger/10 text-danger',
        };
    }

    return {
        label: 'Grupo finalizado',
        icon: 'History',
        class: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    };
});

function reload() {
    router.reload();
}

// ── Editar responsable y notas ──
const editingInfo = ref(false);
const infoForm = reactive({ guest_name: '', notes: '' });
const infoBusy = ref(false);

function openInfo() {
    infoForm.guest_name = props.group.guest_name ?? '';
    infoForm.notes = props.group.notes ?? '';
    editingInfo.value = true;
}

async function submitInfo() {
    infoBusy.value = true;
    try {
        await axios.patch(
            route('tenant.group-reservations.update', props.group.id),
            {
                guest_name: infoForm.guest_name,
                notes: infoForm.notes || null,
            },
        );
        toast.success('Grupo actualizado');
        editingInfo.value = false;
        reload();
    } catch (e: any) {
        toast.error(
            'Error',
            e.response?.data?.message ?? 'No se pudo actualizar.',
        );
    } finally {
        infoBusy.value = false;
    }
}

// ── Agregar habitaciones (mismas fechas del grupo) ──
const addingRooms = ref(false);
const roomsForm = reactive({
    room_type_id: '' as string | number,
    rooms: 1,
    adults: 2,
    children: 0,
});
const roomsBusy = ref(false);

function openAddRooms() {
    roomsForm.room_type_id = '';
    roomsForm.rooms = 1;
    roomsForm.adults = 2;
    roomsForm.children = 0;
    addingRooms.value = true;
}

async function submitRooms() {
    roomsBusy.value = true;
    try {
        await axios.post(
            route('tenant.group-reservations.rooms', props.group.id),
            {
                room_type_id: Number(roomsForm.room_type_id),
                rooms: roomsForm.rooms,
                adults: roomsForm.adults,
                children: roomsForm.children,
            },
        );
        toast.success(
            'Habitaciones agregadas',
            'El total del grupo se actualizó; vuelve a generar el cobro si había uno.',
        );
        addingRooms.value = false;
        roomsForm.room_type_id = '';
        roomsForm.rooms = 1;
        reload();
    } catch (e: any) {
        toast.error(
            'No se pudo agregar',
            e.response?.data?.message ?? 'Revisa la disponibilidad.',
        );
    } finally {
        roomsBusy.value = false;
    }
}

// ── Editar personas de una habitación ──
const editingPeople = ref<ReservationDetail | null>(null);
const peopleForm = reactive({ adults: 1, children: 0 });
const peopleBusy = ref(false);

function openPeople(row: ReservationDetail) {
    editingPeople.value = row;
    peopleForm.adults = row.adults;
    peopleForm.children = row.children;
}

async function submitPeople() {
    if (!editingPeople.value) return;
    peopleBusy.value = true;
    try {
        await axios.patch(
            route('tenant.reservations.update', editingPeople.value.id),
            {
                rate_plan_id: editingPeople.value.rate_plan_id,
                starts_at: editingPeople.value.starts_at,
                ends_at: editingPeople.value.ends_at,
                adults: peopleForm.adults,
                children: peopleForm.children,
            },
        );
        toast.success(
            'Personas actualizadas',
            'El total se recalculó con los cargos que apliquen.',
        );
        editingPeople.value = null;
        reload();
    } catch (e: any) {
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ??
                'Revisa la capacidad de la habitación.',
        );
    } finally {
        peopleBusy.value = false;
    }
}

// ── Cancelar una habitación del grupo ──
const cancellingRoom = ref<ReservationDetail | null>(null);
const cancelRoomBusy = ref(false);

async function cancelRoom() {
    if (!cancellingRoom.value) return;
    cancelRoomBusy.value = true;
    try {
        await axios.patch(
            route('tenant.reservations.cancel', cancellingRoom.value.id),
            {
                reason: `Ajuste del grupo ${props.group.code}.`,
            },
        );
        toast.success(
            'Habitación cancelada',
            'Se liberó del grupo; el folio queda como rastro.',
        );
        cancellingRoom.value = null;
        reload();
    } catch (e: any) {
        toast.error(
            'Error',
            e.response?.data?.message ?? 'No se pudo cancelar.',
        );
    } finally {
        cancelRoomBusy.value = false;
    }
}

// ── Recorridos: agregar y cancelar ──
const addingExperience = ref(false);
const expCatalog = ref<ExperienceOption[]>([]);
const expLoading = ref(false);
const expForm = reactive({
    experience_id: '' as string | number,
    session_id: '' as string | number,
    people: 1,
});
const expBusy = ref(false);

const expSessions = computed(() => {
    const exp = expCatalog.value.find(
        (e) => e.id === Number(expForm.experience_id),
    );
    return exp?.sessions ?? [];
});

async function openAddExperience() {
    addingExperience.value = true;
    expForm.experience_id = '';
    expForm.session_id = '';
    expForm.people = 1;
    expLoading.value = true;
    try {
        const start = props.group.starts_at?.slice(0, 10);
        const end = props.group.ends_at?.slice(0, 10) ?? start;
        const { data } = await axios.get('/api/grupos/experiences', {
            params: { start, end },
        });
        expCatalog.value = data.experiences ?? [];
    } catch {
        expCatalog.value = [];
    } finally {
        expLoading.value = false;
    }
}

async function submitExperience() {
    expBusy.value = true;
    try {
        await axios.post(
            route('tenant.group-reservations.experiences', props.group.id),
            {
                session_id: Number(expForm.session_id),
                people: expForm.people,
            },
        );
        toast.success(
            'Recorrido agregado',
            'Suma al total del grupo; vuelve a generar el cobro si había uno.',
        );
        addingExperience.value = false;
        reload();
    } catch (e: any) {
        toast.error(
            'No se pudo agregar',
            e.response?.data?.message ?? 'Revisa el cupo de la sesión.',
        );
    } finally {
        expBusy.value = false;
    }
}

const cancellingExperience = ref<GroupExperienceRow | null>(null);
const cancelExperienceBusy = ref(false);

async function cancelExperience() {
    if (!cancellingExperience.value) {
        return;
    }

    cancelExperienceBusy.value = true;

    try {
        await axios.patch(
            route(
                'tenant.experience-bookings.status',
                cancellingExperience.value.id,
            ),
            { status: 'cancelled' },
        );
        toast.success('Experiencia cancelada', 'Su cupo quedó libre.');
        cancellingExperience.value = null;
        reload();
    } catch (e: any) {
        toast.error(
            'No se pudo cancelar',
            e.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        cancelExperienceBusy.value = false;
    }
}

// ── Cobro consolidado desde el panel ──
const chargeBusy = ref(false);

async function issueCharge(method: 'gateway' | 'transfer') {
    chargeBusy.value = true;
    try {
        const { data } = await axios.post(
            route('tenant.group-reservations.payment-request', props.group.id),
            { method },
        );
        if (data.checkout_url) {
            try {
                await navigator.clipboard.writeText(data.checkout_url);
                toast.success(
                    'Link de pago copiado',
                    `${data.amount_label} — compártelo con el responsable.`,
                );
            } catch {
                toast.success(
                    'Cobro generado',
                    `${data.amount_label} — copia el link desde la lista de cobros.`,
                );
            }
        } else {
            toast.success(
                'Cobro por transferencia emitido',
                `${data.amount_label} — verifícalo en Pagos cuando llegue el comprobante.`,
            );
        }
        reload();
    } catch (e: any) {
        toast.error(
            'No se pudo generar el cobro',
            e.response?.data?.message ?? 'Revisa Métodos de pago.',
        );
    } finally {
        chargeBusy.value = false;
    }
}

async function copyRequestLink(pr: PaymentRequestRow) {
    if (!pr.checkout_url) return;
    try {
        await navigator.clipboard.writeText(pr.checkout_url);
        toast.success('Link copiado', pr.amount_label);
    } catch {
        toast.error('No se pudo copiar', pr.checkout_url);
    }
}

const requestStatusLabel: Record<string, string> = {
    pending: 'Vigente',
    paid: 'Pagado',
    expired: 'Vencido',
    canceled: 'Cancelado',
    rejected: 'Rechazado',
};
const requestStatusClass: Record<string, string> = {
    pending: 'bg-warning/10 text-warning',
    paid: 'bg-success/10 text-success',
    expired: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    canceled: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    rejected: 'bg-danger/10 text-danger',
};
</script>

<template>
    <RazeLayout :title="`Grupo ${group.code}`">
        <div class="mt-2">
            <Link
                :href="route('tenant.groups')"
                class="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-slate-500 transition hover:text-primary"
            >
                <Lucide icon="ArrowLeft" class="h-5 w-5" />
                Volver a grupos
            </Link>
            <div
                class="box box--stacked mt-1 flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary sm:h-14 sm:w-14"
                    >
                        <Lucide
                            icon="UsersRound"
                            class="h-5 w-5 sm:h-7 sm:w-7"
                        />
                    </div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h1 class="text-lg font-medium sm:text-xl">
                                Grupo {{ group.code }}
                            </h1>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium"
                                :class="groupStatus.class"
                            >
                                <Lucide
                                    :icon="groupStatus.icon"
                                    class="h-4 w-4"
                                />
                                {{ groupStatus.label }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-slate-500">
                            Responsable:
                            <span class="font-medium text-slate-700">
                                {{ group.guest_name ?? 'Sin nombre' }}
                            </span>
                            <template v-if="group.starts_at">
                                · Llega {{ formatDateTime(group.starts_at) }}
                            </template>
                        </p>
                    </div>
                </div>
                <div
                    v-if="canManage"
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2.5"
                >
                    <Button
                        variant="outline-secondary"
                        class="min-h-11 rounded-[0.5rem] bg-white"
                        @click="openInfo"
                    >
                        <Lucide icon="UserPen" class="mr-2 h-5 w-5" />
                        Editar responsable
                    </Button>
                    <Button
                        variant="primary"
                        class="min-h-11 rounded-[0.5rem] shadow-md shadow-primary/20"
                        :disabled="chargeBusy || group.pending_balance <= 0"
                        @click="issueCharge('gateway')"
                    >
                        <Lucide icon="Link" class="mr-2 h-5 w-5" />
                        Generar link de pago
                    </Button>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-5">
                <div
                    class="box box--stacked col-span-12 flex items-center gap-3.5 p-5 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-info/10 text-info"
                    >
                        <Lucide icon="BedDouble" class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="text-xl font-medium">
                            {{ activeRoomsCount }}
                        </div>
                        <div class="text-xs text-slate-500">
                            Habitaciones activas
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-3.5 p-5 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                    >
                        <Lucide icon="UsersRound" class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="text-xl font-medium">
                            {{ totalGuests }}
                        </div>
                        <div class="text-xs text-slate-500">
                            Personas registradas
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-3.5 p-5 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                    >
                        <Lucide icon="Wallet" class="h-6 w-6" />
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-xl font-medium">
                            {{ money(group.total) }}
                        </div>
                        <div class="text-xs text-slate-500">
                            Total del grupo
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-3.5 p-5 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full"
                        :class="
                            group.pending_balance > 0
                                ? 'bg-pending/10 text-pending'
                                : 'bg-success/10 text-success'
                        "
                    >
                        <Lucide
                            :icon="
                                group.pending_balance > 0
                                    ? 'ReceiptText'
                                    : 'CircleCheck'
                            "
                            class="h-6 w-6"
                        />
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-xl font-medium">
                            {{ money(group.pending_balance) }}
                        </div>
                        <div class="text-xs text-slate-500">
                            Saldo pendiente
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-5">
                <!-- Habitaciones y recorridos -->
                <div class="col-span-12 xl:col-span-8">
                    <div class="box box--stacked">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-11 w-11 items-center justify-center rounded-full bg-info/10 text-info"
                                >
                                    <Lucide icon="BedDouble" class="h-6 w-6" />
                                </div>
                                <div>
                                    <div class="font-medium">
                                        Habitaciones del grupo
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        Ocupación, estado y total de cada
                                        cuarto.
                                    </div>
                                </div>
                            </div>
                            <Button
                                v-if="canManage"
                                variant="outline-secondary"
                                class="min-h-10 rounded-[0.5rem] bg-white"
                                @click="openAddRooms"
                            >
                                <Lucide icon="Plus" class="mr-2 h-5 w-5" />
                                Agregar habitación
                            </Button>
                        </div>
                        <div class="space-y-3 p-5">
                            <article
                                v-for="row in group.reservations_detail"
                                :key="row.id"
                                class="grid gap-4 rounded-xl border border-slate-200/80 p-4 md:grid-cols-[minmax(14rem,1.25fr)_minmax(10rem,0.8fr)_minmax(10rem,0.8fr)_auto] md:items-center dark:border-darkmode-400"
                            >
                                <div class="flex min-w-0 items-center gap-3">
                                    <div
                                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-info/10 text-info"
                                    >
                                        <Lucide
                                            icon="BedDouble"
                                            class="h-6 w-6"
                                        />
                                    </div>
                                    <div class="min-w-0">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <span class="font-medium">
                                                {{
                                                    row.room
                                                        ? `Habitación ${row.room}`
                                                        : row.code
                                                }}
                                            </span>
                                            <span
                                                class="rounded-full px-2.5 py-1 text-xs font-medium"
                                                :class="
                                                    statusClass[row.status] ??
                                                    'bg-slate-100 text-slate-500'
                                                "
                                            >
                                                {{ row.status_label }}
                                            </span>
                                        </div>
                                        <div
                                            class="mt-0.5 truncate text-xs text-slate-500"
                                        >
                                            {{ row.room_type }} · {{ row.code }}
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <div
                                        class="flex items-center gap-2 text-xs font-medium text-slate-400"
                                    >
                                        <Lucide
                                            icon="UsersRound"
                                            class="h-4 w-4"
                                        />
                                        OCUPACIÓN
                                    </div>
                                    <div class="mt-1 text-sm font-medium">
                                        {{ row.adults }}
                                        {{
                                            row.adults === 1
                                                ? 'adulto'
                                                : 'adultos'
                                        }}
                                        <template v-if="row.children">
                                            + {{ row.children }}
                                            {{
                                                row.children === 1
                                                    ? 'niño'
                                                    : 'niños'
                                            }}
                                        </template>
                                    </div>
                                </div>

                                <div>
                                    <div
                                        class="flex items-center gap-2 text-xs font-medium text-slate-400"
                                    >
                                        <Lucide
                                            icon="CalendarDays"
                                            class="h-4 w-4"
                                        />
                                        ESTANCIA
                                    </div>
                                    <div class="mt-1 text-sm font-medium">
                                        {{ formatDateTime(row.starts_at) }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-slate-500">
                                        {{ money(row.total) }}
                                    </div>
                                </div>

                                <div
                                    v-if="
                                        canManage &&
                                        editableStatuses.includes(row.status)
                                    "
                                    class="flex items-center gap-2 md:justify-end"
                                >
                                    <Button
                                        variant="outline-primary"
                                        class="min-h-10 flex-1 whitespace-nowrap md:flex-none"
                                        @click="openPeople(row)"
                                    >
                                        <Lucide
                                            icon="UsersRound"
                                            class="mr-2 h-5 w-5"
                                        />
                                        Editar ocupación
                                    </Button>
                                    <Menu>
                                        <Menu.Button
                                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-100 dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                                            title="Más acciones"
                                        >
                                            <Lucide
                                                icon="EllipsisVertical"
                                                class="h-5 w-5"
                                            />
                                        </Menu.Button>
                                        <Menu.Items class="w-56">
                                            <Menu.Item
                                                as="button"
                                                type="button"
                                                class="text-danger"
                                                @click="cancellingRoom = row"
                                            >
                                                <Lucide
                                                    icon="Ban"
                                                    class="mr-2 h-4 w-4"
                                                />
                                                Cancelar esta habitación
                                            </Menu.Item>
                                        </Menu.Items>
                                    </Menu>
                                </div>
                                <div
                                    v-else
                                    class="text-sm text-slate-400 md:text-right"
                                >
                                    Sin acciones pendientes
                                </div>
                            </article>
                        </div>
                    </div>

                    <div
                        v-if="hasExperiencesModule"
                        class="box box--stacked mt-5"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-11 w-11 items-center justify-center rounded-full bg-success/10 text-success"
                                >
                                    <Lucide icon="Compass" class="h-6 w-6" />
                                </div>
                                <div>
                                    <div class="font-medium">
                                        Recorridos y experiencias
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        Servicios adicionales incluidos en el
                                        mismo grupo.
                                    </div>
                                </div>
                            </div>
                            <Button
                                v-if="canManage"
                                variant="outline-secondary"
                                class="min-h-10 rounded-[0.5rem] bg-white"
                                @click="openAddExperience"
                            >
                                <Lucide icon="Plus" class="mr-2 h-5 w-5" />
                                Agregar experiencia
                            </Button>
                        </div>
                        <div
                            v-if="group.experiences.length"
                            class="space-y-3 p-5"
                        >
                            <article
                                v-for="exp in group.experiences"
                                :key="exp.id"
                                class="flex flex-col gap-4 rounded-xl border border-slate-200/80 p-4 sm:flex-row sm:items-center dark:border-darkmode-400"
                            >
                                <div
                                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                                >
                                    <Lucide icon="Compass" class="h-6 w-6" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span class="font-medium">
                                            {{ exp.name ?? exp.code }}
                                        </span>
                                        <span
                                            class="rounded-full px-2.5 py-1 text-xs font-medium"
                                            :class="
                                                statusClass[exp.status] ??
                                                'bg-slate-100 text-slate-500'
                                            "
                                        >
                                            {{ exp.status_label }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ exp.code }} · {{ exp.people }}
                                        {{
                                            exp.people === 1
                                                ? 'persona'
                                                : 'personas'
                                        }}
                                        <template v-if="exp.starts_at">
                                            ·
                                            {{ formatDateTime(exp.starts_at) }}
                                        </template>
                                    </p>
                                </div>
                                <div
                                    class="flex items-center justify-between gap-3 sm:justify-end"
                                >
                                    <span class="font-medium">{{
                                        money(exp.total)
                                    }}</span>
                                    <Button
                                        v-if="
                                            canManage &&
                                            editableStatuses.includes(
                                                exp.status,
                                            )
                                        "
                                        type="button"
                                        variant="outline-danger"
                                        class="min-h-10 whitespace-nowrap"
                                        @click="cancellingExperience = exp"
                                    >
                                        <Lucide
                                            icon="Ban"
                                            class="mr-2 h-5 w-5"
                                        />
                                        Cancelar
                                    </Button>
                                </div>
                            </article>
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 px-5 py-8 text-center"
                        >
                            <Lucide
                                icon="Compass"
                                class="h-8 w-8 text-slate-300"
                            />
                            <div class="text-sm font-medium">
                                Sin experiencias agregadas
                            </div>
                            <div class="text-xs text-slate-500">
                                Puedes agregarlas al grupo y cobrarlas juntas.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Dinero y responsable -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="box box--stacked overflow-hidden">
                        <div
                            class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-full bg-success/10 text-success"
                            >
                                <Lucide icon="Wallet" class="h-6 w-6" />
                            </div>
                            <div>
                                <div class="font-medium">Cobro del grupo</div>
                                <div class="text-xs text-slate-500">
                                    Total, pagos y saldo por liquidar.
                                </div>
                            </div>
                        </div>
                        <div class="p-5">
                            <div
                                class="rounded-xl bg-slate-50 p-4 dark:bg-darkmode-700"
                            >
                                <div class="text-xs text-slate-500">
                                    Saldo pendiente
                                </div>
                                <div
                                    class="mt-1 text-2xl font-semibold"
                                    :class="
                                        group.pending_balance > 0
                                            ? 'text-pending'
                                            : 'text-success'
                                    "
                                >
                                    {{ money(group.pending_balance) }}
                                </div>
                                <div
                                    class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-darkmode-400"
                                >
                                    <div
                                        class="h-full rounded-full bg-success transition-all"
                                        :style="{
                                            width: `${paymentProgress}%`,
                                        }"
                                    ></div>
                                </div>
                                <div
                                    class="mt-2 flex justify-between text-xs text-slate-500"
                                >
                                    <span>{{ paymentProgress }}% pagado</span>
                                    <span>{{ money(group.total) }} total</span>
                                </div>
                            </div>

                            <div class="mt-4 space-y-2 text-sm">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500">Total</span>
                                    <span class="font-semibold">{{
                                        money(group.total)
                                    }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500">Pagado</span>
                                    <span class="font-medium text-success">{{
                                        money(group.paid_total)
                                    }}</span>
                                </div>
                                <div
                                    class="flex items-center justify-between border-t border-dashed border-slate-300/70 pt-2 dark:border-darkmode-400"
                                >
                                    <span class="text-slate-500"
                                        >Pendiente</span
                                    >
                                    <span
                                        class="font-semibold"
                                        :class="
                                            group.pending_balance > 0
                                                ? 'text-warning'
                                                : 'text-success'
                                        "
                                    >
                                        {{ money(group.pending_balance) }}
                                    </span>
                                </div>
                            </div>
                            <div
                                v-if="canManage && group.pending_balance > 0"
                                class="mt-4 flex flex-col gap-2"
                            >
                                <Button
                                    variant="primary"
                                    class="min-h-11 rounded-[0.5rem]"
                                    :disabled="chargeBusy"
                                    @click="issueCharge('gateway')"
                                >
                                    <Lucide icon="Link" class="mr-2 h-5 w-5" />
                                    {{
                                        chargeBusy
                                            ? 'Generando...'
                                            : 'Generar link de pago'
                                    }}
                                </Button>
                                <Button
                                    variant="outline-secondary"
                                    class="min-h-11 rounded-[0.5rem] bg-white"
                                    :disabled="chargeBusy"
                                    @click="issueCharge('transfer')"
                                >
                                    <Lucide
                                        icon="Landmark"
                                        class="mr-2 h-5 w-5"
                                    />
                                    Cobro por transferencia
                                </Button>
                            </div>

                            <div
                                v-if="group.payment_requests.length"
                                class="mt-5 border-t border-dashed border-slate-300/70 pt-4 dark:border-darkmode-400"
                            >
                                <div class="mb-3 text-sm font-medium">
                                    Cobros emitidos
                                </div>
                                <div class="space-y-2">
                                    <div
                                        v-for="pr in group.payment_requests"
                                        :key="pr.id"
                                        class="rounded-lg border border-slate-200/80 px-3 py-2.5 text-sm dark:border-darkmode-400"
                                    >
                                        <div
                                            class="flex items-center justify-between gap-2"
                                        >
                                            <div class="min-w-0">
                                                <div class="font-medium">
                                                    {{ pr.amount_label }}
                                                </div>
                                                <div
                                                    class="text-xs text-slate-500"
                                                >
                                                    {{
                                                        pr.method === 'transfer'
                                                            ? 'Transferencia'
                                                            : 'Link de pago'
                                                    }}
                                                    ·
                                                    {{
                                                        formatDateTime(
                                                            pr.created_at,
                                                        )
                                                    }}
                                                </div>
                                            </div>
                                            <span
                                                class="rounded-full px-2.5 py-1 text-xs font-medium"
                                                :class="
                                                    requestStatusClass[
                                                        pr.status
                                                    ] ??
                                                    'bg-slate-100 text-slate-500'
                                                "
                                            >
                                                {{
                                                    requestStatusLabel[
                                                        pr.status
                                                    ] ?? pr.status
                                                }}
                                            </span>
                                        </div>
                                        <Button
                                            v-if="
                                                pr.status === 'pending' &&
                                                pr.checkout_url
                                            "
                                            type="button"
                                            variant="outline-secondary"
                                            size="sm"
                                            class="mt-2 w-full"
                                            @click="copyRequestLink(pr)"
                                        >
                                            <Lucide
                                                icon="Copy"
                                                class="mr-2 h-4 w-4"
                                            />
                                            Copiar link de pago
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="box box--stacked mt-5 overflow-hidden">
                        <div
                            class="flex items-center justify-between gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-11 w-11 items-center justify-center rounded-full bg-primary/10 text-primary"
                                >
                                    <Lucide icon="UserRound" class="h-6 w-6" />
                                </div>
                                <div>
                                    <div class="font-medium">Responsable</div>
                                    <div class="text-xs text-slate-500">
                                        Contacto y notas del grupo.
                                    </div>
                                </div>
                            </div>
                            <Button
                                v-if="canManage"
                                type="button"
                                variant="outline-secondary"
                                class="!h-10 !w-10 rounded-full !p-0"
                                title="Editar responsable"
                                @click="openInfo"
                            >
                                <Lucide icon="Pencil" class="h-5 w-5" />
                            </Button>
                        </div>
                        <div class="space-y-3 p-5 text-sm">
                            <div class="text-base font-medium">
                                {{ group.guest_name ?? 'Sin nombre' }}
                            </div>
                            <div
                                v-if="group.guest_phone"
                                class="flex items-center gap-2 text-slate-500"
                            >
                                <Lucide icon="Phone" class="h-5 w-5" />
                                {{ group.guest_phone }}
                            </div>
                            <div
                                v-if="group.guest_email"
                                class="flex items-center gap-2 text-slate-500"
                            >
                                <Lucide icon="Mail" class="h-5 w-5" />
                                {{ group.guest_email }}
                            </div>
                            <p
                                v-if="group.notes"
                                class="rounded-lg bg-slate-50 px-3.5 py-3 text-sm text-slate-500 dark:bg-darkmode-700"
                            >
                                <span
                                    class="mb-1 block text-xs font-medium text-slate-400"
                                >
                                    NOTAS PARA EL PERSONAL
                                </span>
                                {{ group.notes }}
                            </p>
                            <div
                                v-if="
                                    !group.guest_phone &&
                                    !group.guest_email &&
                                    !group.notes
                                "
                                class="rounded-lg border border-dashed border-slate-300/70 px-3.5 py-3 text-xs text-slate-500 dark:border-darkmode-400"
                            >
                                No hay teléfono, correo ni notas registradas.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal editar responsable/notas -->
        <Dialog :open="editingInfo" size="lg" @close="editingInfo = false">
            <Dialog.Panel>
                <form @submit.prevent="submitInfo">
                    <div class="flex items-start gap-4 px-6 py-5">
                        <div
                            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="UserPen" class="h-7 w-7" />
                        </div>
                        <div>
                            <h2 class="text-lg font-medium">
                                Editar responsable
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                Actualiza el contacto y las notas del grupo
                                {{ group.code }}.
                            </p>
                        </div>
                    </div>
                    <div
                        class="space-y-5 border-y border-slate-200/70 px-6 py-5 dark:border-darkmode-400"
                    >
                        <div>
                            <FormLabel htmlFor="show-group-guest">
                                Nombre del responsable
                            </FormLabel>
                            <FormInput
                                id="show-group-guest"
                                v-model="infoForm.guest_name"
                                type="text"
                                class="h-11"
                                placeholder="Nombre completo"
                            />
                        </div>
                        <div>
                            <FormLabel htmlFor="show-group-notes">
                                Notas para el personal
                            </FormLabel>
                            <FormTextarea
                                id="show-group-notes"
                                v-model="infoForm.notes"
                                rows="4"
                                placeholder="Evento, hora de llegada o acuerdos importantes..."
                            />
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 px-6 py-4">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="min-h-11 px-5"
                            @click="editingInfo = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            class="min-h-11 px-5"
                            :disabled="infoBusy || !infoForm.guest_name.trim()"
                        >
                            <Lucide icon="Check" class="mr-2 h-5 w-5" />
                            {{ infoBusy ? 'Guardando...' : 'Guardar cambios' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal agregar habitaciones -->
        <Dialog :open="addingRooms" size="lg" @close="addingRooms = false">
            <Dialog.Panel>
                <form @submit.prevent="submitRooms">
                    <div class="flex items-start gap-4 px-6 py-5">
                        <div
                            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-info/10 text-info"
                        >
                            <Lucide icon="BedDouble" class="h-7 w-7" />
                        </div>
                        <div>
                            <h2 class="text-lg font-medium">
                                Agregar habitaciones al grupo
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                Usarán las mismas fechas. Si faltan cuartos, no
                                se agregará ninguno de esta captura.
                            </p>
                        </div>
                    </div>
                    <div
                        class="space-y-5 border-y border-slate-200/70 px-6 py-5 dark:border-darkmode-400"
                    >
                        <div>
                            <FormLabel htmlFor="add-group-room-type">
                                Tipo de habitación
                            </FormLabel>
                            <FormSelect
                                id="add-group-room-type"
                                v-model="roomsForm.room_type_id"
                                class="h-11"
                            >
                                <option value="" disabled>Elige un tipo</option>
                                <option
                                    v-for="type in roomTypes"
                                    :key="type.id"
                                    :value="type.id"
                                >
                                    {{ type.name }} ·
                                    {{ type.rooms_count }} cuartos físicos ·
                                    hasta {{ type.capacity }} personas
                                </option>
                            </FormSelect>
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <FormLabel htmlFor="add-group-room-count">
                                    Habitaciones
                                </FormLabel>
                                <FormInput
                                    id="add-group-room-count"
                                    v-model.number="roomsForm.rooms"
                                    type="number"
                                    class="h-11"
                                    min="1"
                                    max="10"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="add-group-adults">
                                    Adultos por cuarto
                                </FormLabel>
                                <FormInput
                                    id="add-group-adults"
                                    v-model.number="roomsForm.adults"
                                    type="number"
                                    class="h-11"
                                    min="1"
                                    max="20"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="add-group-children">
                                    Niños por cuarto
                                </FormLabel>
                                <FormInput
                                    id="add-group-children"
                                    v-model.number="roomsForm.children"
                                    type="number"
                                    class="h-11"
                                    min="0"
                                    max="20"
                                />
                            </div>
                        </div>
                        <div
                            class="flex items-start gap-3 rounded-xl border border-dashed border-slate-300/70 p-4 dark:border-darkmode-400"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-5 w-5 shrink-0 text-info"
                            />
                            <FormHelp class="mt-0">
                                Si el grupo está confirmado, los cuartos nuevos
                                también quedarán confirmados. Si ya existía un
                                cobro pendiente, deberás generar uno nuevo con
                                el total actualizado.
                            </FormHelp>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 px-6 py-4">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="min-h-11 px-5"
                            @click="addingRooms = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            class="min-h-11 px-5"
                            :disabled="
                                roomsBusy || roomsForm.room_type_id === ''
                            "
                        >
                            <Lucide icon="Plus" class="mr-2 h-5 w-5" />
                            {{
                                roomsBusy
                                    ? 'Agregando...'
                                    : 'Agregar habitaciones'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal editar personas -->
        <Dialog
            :open="editingPeople !== null"
            size="lg"
            @close="editingPeople = null"
        >
            <Dialog.Panel>
                <form @submit.prevent="submitPeople">
                    <div class="flex items-start gap-4 px-6 py-5">
                        <div
                            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="UsersRound" class="h-7 w-7" />
                        </div>
                        <div>
                            <h2 class="text-lg font-medium">
                                Editar ocupación
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                {{ editingPeople?.room_type }}
                                <template v-if="editingPeople?.room">
                                    · Habitación {{ editingPeople.room }}
                                </template>
                                · {{ editingPeople?.code }}
                            </p>
                        </div>
                    </div>
                    <div
                        class="grid grid-cols-1 gap-4 border-y border-slate-200/70 px-6 py-5 sm:grid-cols-2 dark:border-darkmode-400"
                    >
                        <div>
                            <FormLabel htmlFor="edit-room-adults">
                                Adultos
                            </FormLabel>
                            <FormInput
                                id="edit-room-adults"
                                v-model.number="peopleForm.adults"
                                type="number"
                                class="h-11"
                                min="1"
                                max="20"
                            />
                        </div>
                        <div>
                            <FormLabel htmlFor="edit-room-children">
                                Niños
                            </FormLabel>
                            <FormInput
                                id="edit-room-children"
                                v-model.number="peopleForm.children"
                                type="number"
                                class="h-11"
                                min="0"
                                max="20"
                            />
                        </div>
                        <FormHelp class="sm:col-span-2">
                            El total se recalculará si la tarifa cobra personas
                            adicionales.
                        </FormHelp>
                    </div>
                    <div class="flex justify-end gap-3 px-6 py-4">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="min-h-11 px-5"
                            @click="editingPeople = null"
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            class="min-h-11 px-5"
                            :disabled="peopleBusy"
                        >
                            <Lucide icon="Check" class="mr-2 h-5 w-5" />
                            {{
                                peopleBusy
                                    ? 'Guardando...'
                                    : 'Guardar ocupación'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal confirmar cancelación de habitación -->
        <Dialog
            :open="cancellingRoom !== null"
            size="lg"
            @close="cancellingRoom = null"
        >
            <Dialog.Panel>
                <div class="px-6 py-6 text-center">
                    <div
                        class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-danger/10 text-danger"
                    >
                        <Lucide icon="TriangleAlert" class="h-8 w-8" />
                    </div>
                    <h2 class="mt-4 text-lg font-medium">
                        ¿Cancelar esta habitación?
                    </h2>
                    <p class="mx-auto mt-2 max-w-lg text-sm text-slate-500">
                        Se liberará
                        <span class="font-medium text-slate-700">
                            {{
                                cancellingRoom?.room
                                    ? `la habitación ${cancellingRoom.room}`
                                    : cancellingRoom?.room_type
                            }}
                        </span>
                        y el folio {{ cancellingRoom?.code }} seguirá visible en
                        Reservas como historial.
                    </p>
                    <div class="mt-6 flex justify-center gap-3">
                        <Button
                            variant="outline-secondary"
                            class="min-h-11 px-5"
                            @click="cancellingRoom = null"
                        >
                            Conservar habitación
                        </Button>
                        <Button
                            variant="danger"
                            class="min-h-11 px-5"
                            :disabled="cancelRoomBusy"
                            @click="cancelRoom"
                        >
                            <Lucide icon="Ban" class="mr-2 h-5 w-5" />
                            {{
                                cancelRoomBusy
                                    ? 'Cancelando...'
                                    : 'Cancelar habitación'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <Dialog
            :open="cancellingExperience !== null"
            size="lg"
            @close="cancellingExperience = null"
        >
            <Dialog.Panel>
                <div class="px-6 py-6 text-center">
                    <div
                        class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-danger/10 text-danger"
                    >
                        <Lucide icon="TriangleAlert" class="h-8 w-8" />
                    </div>
                    <h2 class="mt-4 text-lg font-medium">
                        ¿Cancelar esta experiencia?
                    </h2>
                    <p class="mx-auto mt-2 max-w-lg text-sm text-slate-500">
                        Se cancelará
                        <span class="font-medium text-slate-700">
                            {{
                                cancellingExperience?.name ??
                                cancellingExperience?.code
                            }}
                        </span>
                        para {{ cancellingExperience?.people }}
                        {{
                            cancellingExperience?.people === 1
                                ? 'persona'
                                : 'personas'
                        }}. El cupo quedará libre.
                    </p>
                    <div class="mt-6 flex justify-center gap-3">
                        <Button
                            variant="outline-secondary"
                            class="min-h-11 px-5"
                            @click="cancellingExperience = null"
                        >
                            Conservar experiencia
                        </Button>
                        <Button
                            variant="danger"
                            class="min-h-11 px-5"
                            :disabled="cancelExperienceBusy"
                            @click="cancelExperience"
                        >
                            <Lucide icon="Ban" class="mr-2 h-5 w-5" />
                            {{
                                cancelExperienceBusy
                                    ? 'Cancelando...'
                                    : 'Cancelar experiencia'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal agregar recorrido -->
        <Dialog
            :open="addingExperience"
            size="lg"
            @close="addingExperience = false"
        >
            <Dialog.Panel>
                <form @submit.prevent="submitExperience">
                    <div class="flex items-start gap-4 px-6 py-5">
                        <div
                            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                        >
                            <Lucide icon="Compass" class="h-7 w-7" />
                        </div>
                        <div>
                            <h2 class="text-lg font-medium">
                                Agregar experiencia
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                Elige una sesión con cupo durante la estancia;
                                se sumará al total del grupo.
                            </p>
                        </div>
                    </div>
                    <div
                        class="border-y border-slate-200/70 px-6 py-5 dark:border-darkmode-400"
                    >
                        <div v-if="expLoading" class="py-8 text-center">
                            <Lucide
                                icon="RefreshCw"
                                class="mx-auto h-8 w-8 animate-spin text-primary"
                            />
                            <div class="mt-2 text-sm text-slate-500">
                                Buscando sesiones disponibles...
                            </div>
                        </div>
                        <div
                            v-else-if="!expCatalog.length"
                            class="rounded-xl border border-dashed border-slate-300/70 px-4 py-8 text-center dark:border-darkmode-400"
                        >
                            <Lucide
                                icon="CalendarX"
                                class="mx-auto h-8 w-8 text-slate-300"
                            />
                            <div class="mt-2 text-sm font-medium">
                                No hay sesiones disponibles
                            </div>
                            <div class="mt-0.5 text-xs text-slate-500">
                                No encontramos experiencias con cupo en las
                                fechas del grupo.
                            </div>
                        </div>
                        <div v-else class="space-y-5">
                            <div>
                                <FormLabel htmlFor="group-experience">
                                    Experiencia
                                </FormLabel>
                                <FormSelect
                                    id="group-experience"
                                    v-model="expForm.experience_id"
                                    class="h-11"
                                    @change="expForm.session_id = ''"
                                >
                                    <option value="" disabled>
                                        Elige una experiencia
                                    </option>
                                    <option
                                        v-for="exp in expCatalog"
                                        :key="exp.id"
                                        :value="exp.id"
                                    >
                                        {{ exp.name }} — {{ exp.price_label }}
                                    </option>
                                </FormSelect>
                            </div>
                            <div>
                                <FormLabel htmlFor="group-experience-session">
                                    Fecha y horario
                                </FormLabel>
                                <FormSelect
                                    id="group-experience-session"
                                    v-model="expForm.session_id"
                                    class="h-11"
                                    :disabled="expForm.experience_id === ''"
                                >
                                    <option value="" disabled>
                                        Elige fecha y horario
                                    </option>
                                    <option
                                        v-for="session in expSessions"
                                        :key="session.id"
                                        :value="session.id"
                                    >
                                        {{ formatDateTime(session.starts_at) }}
                                        ·
                                        {{ session.remaining }}
                                        {{
                                            session.remaining === 1
                                                ? 'lugar'
                                                : 'lugares'
                                        }}
                                    </option>
                                </FormSelect>
                            </div>
                            <div>
                                <FormLabel htmlFor="group-experience-people">
                                    Personas
                                </FormLabel>
                                <FormInput
                                    id="group-experience-people"
                                    v-model.number="expForm.people"
                                    type="number"
                                    class="h-11"
                                    min="1"
                                    max="100"
                                />
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 px-6 py-4">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="min-h-11 px-5"
                            @click="addingExperience = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            class="min-h-11 px-5"
                            :disabled="expBusy || expForm.session_id === ''"
                        >
                            <Lucide icon="Plus" class="mr-2 h-5 w-5" />
                            {{
                                expBusy ? 'Agregando...' : 'Agregar experiencia'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
