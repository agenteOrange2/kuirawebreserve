<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormDate,
    FormDateTime,
    FormHelp,
    FormInput,
    FormLabel,
    FormSelect,
    FormSwitch,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog, Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface GroupReservationRow {
    id: number;
    code: string;
    room_type: string | null;
    room: string | null;
    adults: number;
    children: number;
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

interface GroupRow {
    id: number;
    code: string;
    guest_name: string | null;
    notes: string | null;
    rooms: number;
    total: number;
    starts_at: string | null;
    ends_at: string | null;
    created_at: string;
    reservations: GroupReservationRow[];
    experiences: GroupExperienceRow[];
}

interface RoomTypeOption {
    id: number;
    name: string;
    capacity: number;
    rooms_count: number;
    has_night: boolean;
    has_block: boolean;
}

interface LineForm {
    room_type_id: number | '';
    rooms: number;
    adults: number;
    children: number;
}

type GroupStatus =
    | 'pending'
    | 'confirmed'
    | 'checked_in'
    | 'completed'
    | 'cancelled';
type FormStep = 'stay' | 'rooms' | 'contact';
type PhoneCountry = 'mx' | 'us' | 'other';

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    groups: {
        data: GroupRow[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { q: string; status: string; from: string; to: string };
    // Contadores sobre TODOS los grupos: la página que se ve es solo una
    // rebanada, así que no se pueden sacar de la lista.
    stats: {
        total: number;
        active: number;
        pending: number;
        rooms: number;
        value: number;
    };
    roomTypes: RoomTypeOption[];
    canManage: boolean;
}>();

const toast = useToasts();
const liveReservationStatuses = ['pending', 'confirmed', 'checked_in'];
const expanded = ref<Set<number>>(new Set());

const money = (amount: number) =>
    `$${Number(amount).toLocaleString('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

const formatDateTime = (iso: string) =>
    new Date(iso).toLocaleString('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const statusMeta: Record<
    GroupStatus,
    { label: string; icon: Icon; class: string }
> = {
    pending: {
        label: 'Por confirmar',
        icon: 'AlarmClock',
        class: 'bg-pending/10 text-pending',
    },
    confirmed: {
        label: 'Confirmado',
        icon: 'CircleCheck',
        class: 'bg-success/10 text-success',
    },
    checked_in: {
        label: 'Alojado',
        icon: 'DoorOpen',
        class: 'bg-primary/10 text-primary',
    },
    completed: {
        label: 'Finalizado',
        icon: 'History',
        class: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    },
    cancelled: {
        label: 'Cancelado',
        icon: 'Ban',
        class: 'bg-danger/10 text-danger',
    },
};

const reservationStatusClass: Record<string, string> = {
    pending: 'bg-pending/10 text-pending',
    confirmed: 'bg-success/10 text-success',
    checked_in: 'bg-primary/10 text-primary',
    completed: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    cancelled: 'bg-danger/10 text-danger',
    no_show: 'bg-danger/10 text-danger',
};

function groupStatus(group: GroupRow): GroupStatus {
    const statuses = group.reservations.map(
        (reservation) => reservation.status,
    );

    if (statuses.includes('checked_in')) {
        return 'checked_in';
    }

    if (statuses.includes('confirmed')) {
        return 'confirmed';
    }

    if (statuses.includes('pending')) {
        return 'pending';
    }

    if (
        statuses.length > 0 &&
        statuses.every(
            (status) => status === 'cancelled' || status === 'no_show',
        )
    ) {
        return 'cancelled';
    }

    return 'completed';
}

const groupIsLive = (group: GroupRow) =>
    group.reservations.some((reservation) =>
        liveReservationStatuses.includes(reservation.status),
    );

const groupCanCancel = (group: GroupRow) =>
    group.reservations.some((reservation) =>
        ['pending', 'confirmed'].includes(reservation.status),
    );

const peopleInGroup = (group: GroupRow) =>
    group.reservations.reduce(
        (total, reservation) =>
            total + reservation.adults + reservation.children,
        0,
    );

function toggleDetails(groupId: number): void {
    const next = new Set(expanded.value);

    if (next.has(groupId)) {
        next.delete(groupId);
    } else {
        next.add(groupId);
    }

    expanded.value = next;
}

// Los filtros se resuelven en el servidor (la página trae quince grupos,
// no todos): se mandan con un respiro para no pedir en cada tecla.
const listFilters = reactive({
    query: props.filters.q,
    status: props.filters.status as '' | GroupStatus,
    from: props.filters.from,
    to: props.filters.to,
});

const listFiltersActive = computed(
    () =>
        listFilters.query !== '' ||
        listFilters.status !== '' ||
        listFilters.from !== '' ||
        listFilters.to !== '',
);

let filterTimer: ReturnType<typeof setTimeout> | null = null;

watch(listFilters, () => {
    if (filterTimer) clearTimeout(filterTimer);
    filterTimer = setTimeout(() => {
        router.get(
            route('tenant.groups'),
            {
                q: listFilters.query || undefined,
                status: listFilters.status || undefined,
                from: listFilters.from || undefined,
                to: listFilters.to || undefined,
            },
            {
                preserveState: true,
                replace: true,
                only: ['groups', 'filters', 'stats'],
            },
        );
    }, 350);
});

const filteredGroups = computed(() => props.groups.data);

const activeGroupsCount = computed(() => props.stats.active);
const pendingGroupsCount = computed(() => props.stats.pending);
const activeRoomsCount = computed(() => props.stats.rooms);
const activeGroupsValue = computed(() => props.stats.value);

function clearListFilters(): void {
    listFilters.query = '';
    listFilters.status = '';
    listFilters.from = '';
    listFilters.to = '';
}

const showForm = ref(false);
const activeFormStep = ref<FormStep>('stay');
const formScrollContainer = ref<HTMLElement | null>(null);
const saving = ref(false);
const errors = reactive<Record<string, string>>({});
const phoneCountry = ref<PhoneCountry>('mx');
const phoneDialCode = ref('+52');
const phoneNationalNumber = ref('');
const form = reactive({
    mode: 'night' as 'night' | 'block',
    arrive_date: '',
    depart_date: '',
    arrive_at: '',
    guest_name: '',
    guest_phone: '',
    guest_email: '',
    notes: '',
    confirmed: false,
    lines: [
        { room_type_id: '', rooms: 2, adults: 2, children: 0 },
    ] as LineForm[],
});

const formSteps: Array<{
    key: FormStep;
    number: number;
    label: string;
    description: string;
    icon: Icon;
}> = [
    {
        key: 'stay',
        number: 1,
        label: 'Estancia',
        description: 'Fechas y modalidad',
        icon: 'CalendarDays',
    },
    {
        key: 'rooms',
        number: 2,
        label: 'Habitaciones',
        description: 'Tipos y personas',
        icon: 'BedDouble',
    },
    {
        key: 'contact',
        number: 3,
        label: 'Responsable',
        description: 'Contacto y estado',
        icon: 'UserRound',
    },
];

const activeFormStepIndex = computed(() =>
    formSteps.findIndex((step) => step.key === activeFormStep.value),
);

const modeAvailable = computed(() => ({
    night: props.roomTypes.some((roomType) => roomType.has_night),
    block: props.roomTypes.some((roomType) => roomType.has_block),
}));

const typesForMode = computed(() =>
    props.roomTypes.filter(
        (roomType) =>
            roomType.rooms_count > 0 &&
            (form.mode === 'night' ? roomType.has_night : roomType.has_block),
    ),
);

const hasModeChoice = computed(
    () => modeAvailable.value.night && modeAvailable.value.block,
);

const totalRooms = computed(() =>
    form.lines.reduce((total, line) => total + (Number(line.rooms) || 0), 0),
);

const totalGuests = computed(() =>
    form.lines.reduce(
        (total, line) =>
            total +
            (Number(line.rooms) || 0) *
                ((Number(line.adults) || 0) + (Number(line.children) || 0)),
        0,
    ),
);

const stayStepComplete = computed(() =>
    form.mode === 'night'
        ? Boolean(form.arrive_date && form.depart_date)
        : Boolean(form.arrive_at),
);

const roomsStepComplete = computed(
    () =>
        totalRooms.value >= 2 &&
        totalRooms.value <= 30 &&
        form.lines.every((line) => line.room_type_id !== ''),
);

const contactStepComplete = computed(() => Boolean(form.guest_name.trim()));

const guestPhonePreview = computed(() => {
    const number = phoneNationalNumber.value.replace(/\D/g, '');

    if (!number) {
        return '';
    }

    const dialCode =
        phoneCountry.value === 'mx'
            ? '52'
            : phoneCountry.value === 'us'
              ? '1'
              : phoneDialCode.value.replace(/\D/g, '');

    return dialCode ? `+${dialCode}${number}` : `+${number}`;
});

function syncGuestPhone(): void {
    form.guest_phone = guestPhonePreview.value;
}

function changePhoneCountry(): void {
    phoneDialCode.value =
        phoneCountry.value === 'mx'
            ? '+52'
            : phoneCountry.value === 'us'
              ? '+1'
              : '';
    syncGuestPhone();
}

function openForm(): void {
    form.mode = modeAvailable.value.night ? 'night' : 'block';
    form.arrive_date = '';
    form.depart_date = '';
    form.arrive_at = '';
    form.guest_name = '';
    form.guest_phone = '';
    form.guest_email = '';
    form.notes = '';
    form.confirmed = false;
    form.lines = [{ room_type_id: '', rooms: 2, adults: 2, children: 0 }];
    phoneCountry.value = 'mx';
    phoneDialCode.value = '+52';
    phoneNationalNumber.value = '';
    activeFormStep.value = 'stay';
    Object.keys(errors).forEach((key) => delete errors[key]);
    showForm.value = true;
}

function addLine(): void {
    form.lines.push({
        room_type_id: '',
        rooms: 1,
        adults: 2,
        children: 0,
    });
}

function typeFor(line: LineForm): RoomTypeOption | undefined {
    return props.roomTypes.find(
        (roomType) => roomType.id === line.room_type_id,
    );
}

function maxRoomsFor(line: LineForm): number {
    return Math.min(typeFor(line)?.rooms_count || 30, 30);
}

function capacityFor(line: LineForm): number {
    return typeFor(line)?.capacity || 20;
}

function setRooms(line: LineForm, amount: number): void {
    line.rooms = Math.max(1, Math.min(amount, maxRoomsFor(line)));
}

function setAdults(line: LineForm, amount: number): void {
    line.adults = Math.max(
        1,
        Math.min(amount, capacityFor(line) - line.children),
    );
}

function setChildren(line: LineForm, amount: number): void {
    line.children = Math.max(
        0,
        Math.min(amount, capacityFor(line) - line.adults),
    );
}

function goToFormStep(step: FormStep): void {
    if (step === 'rooms' && !stayStepComplete.value) {
        return;
    }

    if (
        step === 'contact' &&
        (!stayStepComplete.value || !roomsStepComplete.value)
    ) {
        return;
    }

    activeFormStep.value = step;
}

function nextFormStep(): void {
    if (activeFormStep.value === 'stay' && stayStepComplete.value) {
        activeFormStep.value = 'rooms';
    } else if (activeFormStep.value === 'rooms' && roomsStepComplete.value) {
        activeFormStep.value = 'contact';
    }
}

function previousFormStep(): void {
    if (activeFormStep.value === 'contact') {
        activeFormStep.value = 'rooms';
    } else if (activeFormStep.value === 'rooms') {
        activeFormStep.value = 'stay';
    }
}

watch(
    () => form.lines.map((line) => line.room_type_id),
    () => {
        form.lines.forEach((line) => {
            setRooms(line, Number(line.rooms) || 1);
            setAdults(line, Number(line.adults) || 1);
            setChildren(line, Number(line.children) || 0);
        });
    },
);

watch(activeFormStep, async () => {
    await nextTick();
    formScrollContainer.value?.scrollTo({ top: 0, behavior: 'auto' });
});

async function submit(): Promise<void> {
    saving.value = true;
    Object.keys(errors).forEach((key) => delete errors[key]);

    const startsAt =
        form.mode === 'night' ? `${form.arrive_date}T15:00` : form.arrive_at;
    const endsAt =
        form.mode === 'night' && form.depart_date
            ? `${form.depart_date}T12:00`
            : null;

    try {
        const { data } = await axios.post<GroupRow>(
            route('tenant.group-reservations.store'),
            {
                mode: form.mode,
                starts_at: startsAt,
                ends_at: endsAt,
                guest_name: form.guest_name,
                guest_phone: form.guest_phone || null,
                guest_email: form.guest_email || null,
                notes: form.notes || null,
                confirmed: form.confirmed,
                lines: form.lines.filter((line) => line.room_type_id !== ''),
            },
        );

        showForm.value = false;
        toast.success(
            'Grupo creado',
            `${data.code}: ${data.rooms} habitaciones por ${money(data.total)}.`,
        );
        router.reload({ only: ['groups', 'stats'] });
    } catch (error: any) {
        const responseData = error.response?.data;

        if (responseData?.errors) {
            Object.entries(responseData.errors).forEach(
                ([key, messages]) => (errors[key] = (messages as string[])[0]),
            );

            const errorKeys = Object.keys(responseData.errors);

            if (
                errorKeys.some((key) =>
                    ['mode', 'starts_at', 'ends_at'].includes(key),
                )
            ) {
                activeFormStep.value = 'stay';
            } else if (
                errorKeys.some(
                    (key) => key === 'lines' || key.startsWith('lines.'),
                )
            ) {
                activeFormStep.value = 'rooms';
            } else {
                activeFormStep.value = 'contact';
            }

            toast.error(
                'Revisa la información',
                Object.values(
                    responseData.errors as Record<string, string[]>,
                )[0]?.[0] ?? '',
            );
        } else {
            toast.error(
                'No se pudo crear el grupo',
                responseData?.message ??
                    'No se apartó ninguna habitación. Revisa la disponibilidad.',
            );
        }
    } finally {
        saving.value = false;
    }
}

const cancelling = ref<GroupRow | null>(null);
const cancelBusy = ref(false);

async function cancelGroup(): Promise<void> {
    if (!cancelling.value) {
        return;
    }

    cancelBusy.value = true;

    try {
        const { data } = await axios.post(
            route('tenant.group-reservations.cancel', cancelling.value.id),
        );

        toast.success(
            'Grupo cancelado',
            `${data.cancelled} reserva(s) canceladas. Las habitaciones quedaron libres.`,
        );
        cancelling.value = null;
        router.reload({ only: ['groups', 'stats'] });
    } catch (error: any) {
        toast.error(
            'No se pudo cancelar',
            error.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        cancelBusy.value = false;
    }
}

const editingGroup = ref<GroupRow | null>(null);
const editForm = reactive({ guest_name: '', notes: '' });
const editBusy = ref(false);

function openEdit(group: GroupRow): void {
    editingGroup.value = group;
    editForm.guest_name = group.guest_name ?? '';
    editForm.notes = group.notes ?? '';
}

async function submitEdit(): Promise<void> {
    if (!editingGroup.value) {
        return;
    }

    editBusy.value = true;

    try {
        await axios.patch(
            route('tenant.group-reservations.update', editingGroup.value.id),
            {
                guest_name: editForm.guest_name,
                notes: editForm.notes || null,
            },
        );

        toast.success('Datos del grupo actualizados');
        editingGroup.value = null;
        router.reload({ only: ['groups', 'stats'] });
    } catch (error: any) {
        toast.error(
            'No se pudo actualizar',
            error.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        editBusy.value = false;
    }
}

const deletingGroup = ref<GroupRow | null>(null);
const deleteBusy = ref(false);

async function deleteGroup(): Promise<void> {
    if (!deletingGroup.value) {
        return;
    }

    deleteBusy.value = true;

    try {
        await axios.delete(
            route('tenant.group-reservations.destroy', deletingGroup.value.id),
        );

        toast.success(
            'Folio eliminado',
            'Las reservas canceladas siguen visibles en Reservas.',
        );
        deletingGroup.value = null;
        router.reload({ only: ['groups', 'stats'] });
    } catch (error: any) {
        toast.error(
            'No se pudo eliminar',
            error.response?.data?.message ?? 'Cancela el grupo primero.',
        );
    } finally {
        deleteBusy.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Reservas grupales">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="UsersRound" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Reservas grupales</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Administra varias habitaciones bajo un mismo folio y
                            responsable.
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2.5"
                >
                    <Button
                        as="a"
                        :href="route('tenant.booking.groups')"
                        target="_blank"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="ExternalLink"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.5]"
                        />
                        Abrir reserva pública
                    </Button>
                    <Button
                        v-if="canManage"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        :disabled="!roomTypes.length"
                        @click="openForm"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Nuevo grupo
                    </Button>
                </div>
            </div>

            <div
                v-if="!roomTypes.length"
                class="box mt-4 border-l-4 border-l-warning p-4"
            >
                <div class="flex items-start gap-3">
                    <Lucide
                        icon="TriangleAlert"
                        class="mt-0.5 h-5 w-5 shrink-0 text-warning"
                    />
                    <div>
                        <p class="text-sm font-medium">
                            No hay tipos de habitación disponibles
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Activa habitaciones y tarifas antes de crear una
                            reserva grupal.
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-12 gap-4">
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="UsersRound" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ activeGroupsCount }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Grupos activos
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                    >
                        <Lucide icon="AlarmClock" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ pendingGroupsCount }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Por confirmar
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                    >
                        <Lucide icon="BedDouble" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ activeRoomsCount }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Habitaciones en grupos
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="Wallet" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium">
                            {{ money(activeGroupsValue) }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Valor de grupos activos
                        </div>
                    </div>
                </div>
            </div>

            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <Lucide
                            icon="CalendarRange"
                            class="h-4 w-4 text-slate-400"
                        />
                        Reservas de grupo
                        <span
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-darkmode-400"
                        >
                            {{ groups.total }}
                        </span>
                    </div>
                    <div
                        class="ml-auto flex items-center gap-2 text-xs text-slate-500"
                    >
                        <span v-if="groups.data.length">
                            Mostrando {{ groups.from }}-{{ groups.to }} de
                            {{ groups.total }}
                        </span>
                        <button
                            v-if="listFiltersActive"
                            type="button"
                            class="font-medium text-primary hover:underline"
                            @click="clearListFilters"
                        >
                            Limpiar filtros
                        </button>
                    </div>
                </div>

                <div
                    v-if="stats.total"
                    class="border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="mb-3 flex items-center gap-2.5">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Filter" class="h-4 w-4" />
                        </div>
                        <div>
                            <div class="text-sm font-medium">
                                Encuentra un grupo
                            </div>
                            <div class="text-xs text-slate-500">
                                Busca por responsable, folio, habitación o tipo.
                            </div>
                        </div>
                    </div>
                    <div
                        class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-[minmax(15rem,1.5fr)_12rem_12rem_12rem_auto]"
                    >
                        <div>
                            <FormLabel htmlFor="group-search">
                                Búsqueda rápida
                            </FormLabel>
                            <div class="relative">
                                <Lucide
                                    icon="Search"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                />
                                <FormInput
                                    id="group-search"
                                    v-model="listFilters.query"
                                    type="search"
                                    class="h-9 pl-9 text-xs"
                                    placeholder="Responsable, folio o habitación"
                                />
                            </div>
                        </div>
                        <div>
                            <FormLabel htmlFor="group-status">Estado</FormLabel>
                            <FormSelect
                                id="group-status"
                                v-model="listFilters.status"
                                class="h-9 text-xs"
                            >
                                <option value="">Todos los estados</option>
                                <option value="pending">Por confirmar</option>
                                <option value="confirmed">Confirmados</option>
                                <option value="checked_in">Alojados</option>
                                <option value="completed">Finalizados</option>
                                <option value="cancelled">Cancelados</option>
                            </FormSelect>
                        </div>
                        <div>
                            <FormLabel htmlFor="group-from">
                                Llegada desde
                            </FormLabel>
                            <FormDate
                                id="group-from"
                                v-model="listFilters.from"
                                input-class="h-9 text-xs"
                            />
                        </div>
                        <div>
                            <FormLabel htmlFor="group-to">
                                Llegada hasta
                            </FormLabel>
                            <FormDate
                                id="group-to"
                                v-model="listFilters.to"
                                input-class="h-9 text-xs"
                            />
                        </div>
                        <div class="flex items-end">
                            <Button
                                v-if="listFiltersActive"
                                type="button"
                                variant="outline-secondary"
                                class="h-9 w-full text-xs whitespace-nowrap xl:w-auto"
                                @click="clearListFilters"
                            >
                                <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                                Limpiar
                            </Button>
                        </div>
                    </div>
                </div>

                <div v-if="filteredGroups.length">
                    <div
                        class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                    >
                        <article
                            v-for="group in filteredGroups"
                            :key="group.id"
                        >
                            <div
                                class="grid gap-3 px-4 py-3 sm:px-5 lg:grid-cols-[minmax(14rem,1.4fr)_minmax(11rem,1fr)_minmax(9rem,0.7fr)_minmax(7rem,0.6fr)_auto] lg:items-center"
                            >
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                                    >
                                        <Lucide
                                            icon="UsersRound"
                                            class="h-4 w-4"
                                        />
                                    </div>
                                    <div class="min-w-0">
                                        <div
                                            class="flex flex-wrap items-center gap-1.5"
                                        >
                                            <span
                                                class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-darkmode-400"
                                            >
                                                {{ group.code }}
                                            </span>
                                            <span
                                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                :class="
                                                    statusMeta[
                                                        groupStatus(group)
                                                    ].class
                                                "
                                            >
                                                <Lucide
                                                    :icon="
                                                        statusMeta[
                                                            groupStatus(group)
                                                        ].icon
                                                    "
                                                    class="h-3.5 w-3.5"
                                                />
                                                {{
                                                    statusMeta[
                                                        groupStatus(group)
                                                    ].label
                                                }}
                                            </span>
                                        </div>
                                        <div
                                            class="mt-0.5 truncate text-sm font-medium"
                                        >
                                            {{
                                                group.guest_name ??
                                                'Sin responsable'
                                            }}
                                        </div>
                                        <div
                                            v-if="group.notes"
                                            class="truncate text-xs text-slate-500"
                                            :title="group.notes"
                                        >
                                            {{ group.notes }}
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
                                    <div
                                        v-if="group.starts_at"
                                        class="mt-0.5 truncate text-xs font-medium"
                                    >
                                        {{ formatDateTime(group.starts_at) }}
                                    </div>
                                    <div
                                        v-if="group.ends_at"
                                        class="truncate text-xs text-slate-500"
                                    >
                                        Sale {{ formatDateTime(group.ends_at) }}
                                    </div>
                                    <div
                                        v-else-if="!group.starts_at"
                                        class="mt-0.5 text-xs text-slate-400"
                                    >
                                        Sin fecha registrada
                                    </div>
                                </div>

                                <div>
                                    <div
                                        class="flex items-center gap-1.5 text-[11px] font-medium text-slate-400"
                                    >
                                        <Lucide
                                            icon="BedDouble"
                                            class="h-3.5 w-3.5"
                                        />
                                        OCUPACIÓN
                                    </div>
                                    <div class="mt-0.5 text-xs font-medium">
                                        {{ group.rooms }}
                                        {{
                                            group.rooms === 1
                                                ? 'habitación'
                                                : 'habitaciones'
                                        }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ peopleInGroup(group) }}
                                        {{
                                            peopleInGroup(group) === 1
                                                ? 'persona'
                                                : 'personas'
                                        }}
                                    </div>
                                </div>

                                <div>
                                    <div
                                        class="text-[11px] font-medium text-slate-400"
                                    >
                                        TOTAL
                                    </div>
                                    <div class="mt-0.5 text-sm font-semibold">
                                        {{ money(group.total) }}
                                    </div>
                                    <div
                                        v-if="group.experiences.length"
                                        class="text-xs text-slate-500"
                                    >
                                        Incluye
                                        {{ group.experiences.length }}
                                        {{
                                            group.experiences.length === 1
                                                ? 'experiencia'
                                                : 'experiencias'
                                        }}
                                    </div>
                                </div>

                                <div
                                    class="flex items-center gap-1.5 lg:justify-end"
                                >
                                    <Button
                                        :as="Link"
                                        :href="
                                            route(
                                                'tenant.groups.show',
                                                group.id,
                                            )
                                        "
                                        variant="outline-primary"
                                        class="h-9 flex-1 rounded-[0.5rem] text-xs whitespace-nowrap lg:flex-none"
                                    >
                                        <Lucide
                                            icon="Eye"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Ver grupo
                                    </Button>
                                    <Menu>
                                        <Menu.Button
                                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-100 dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                                            title="Más acciones"
                                        >
                                            <Lucide
                                                icon="EllipsisVertical"
                                                class="h-4 w-4"
                                            />
                                        </Menu.Button>
                                        <Menu.Items class="w-56">
                                            <Menu.Item
                                                as="button"
                                                type="button"
                                                @click="toggleDetails(group.id)"
                                            >
                                                <Lucide
                                                    :icon="
                                                        expanded.has(group.id)
                                                            ? 'ChevronUp'
                                                            : 'ChevronDown'
                                                    "
                                                    class="mr-1.5 h-3.5 w-3.5"
                                                />
                                                {{
                                                    expanded.has(group.id)
                                                        ? 'Ocultar habitaciones'
                                                        : 'Ver habitaciones aquí'
                                                }}
                                            </Menu.Item>
                                            <template v-if="canManage">
                                                <Menu.Item
                                                    as="button"
                                                    type="button"
                                                    @click="openEdit(group)"
                                                >
                                                    <Lucide
                                                        icon="Pencil"
                                                        class="mr-1.5 h-3.5 w-3.5"
                                                    />
                                                    Editar responsable
                                                </Menu.Item>
                                                <Menu.Divider
                                                    v-if="
                                                        groupCanCancel(group) ||
                                                        !groupIsLive(group)
                                                    "
                                                />
                                                <Menu.Item
                                                    v-if="groupCanCancel(group)"
                                                    as="button"
                                                    type="button"
                                                    class="text-danger"
                                                    @click="cancelling = group"
                                                >
                                                    <Lucide
                                                        icon="Ban"
                                                        class="mr-1.5 h-3.5 w-3.5"
                                                    />
                                                    Cancelar grupo completo
                                                </Menu.Item>
                                                <Menu.Item
                                                    v-else-if="
                                                        !groupIsLive(group)
                                                    "
                                                    as="button"
                                                    type="button"
                                                    class="text-danger"
                                                    @click="
                                                        deletingGroup = group
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Trash2"
                                                        class="mr-1.5 h-3.5 w-3.5"
                                                    />
                                                    Eliminar folio
                                                </Menu.Item>
                                            </template>
                                        </Menu.Items>
                                    </Menu>
                                </div>
                            </div>

                            <!-- Detalle desplegable: mismo renglón compacto,
                                 sin tarjetas dentro de tarjetas. -->
                            <div
                                v-if="expanded.has(group.id)"
                                class="border-t border-dashed border-slate-300/70 bg-slate-50/60 px-4 py-3 sm:px-5 dark:border-darkmode-400 dark:bg-darkmode-700/40"
                            >
                                <div
                                    class="mb-2 flex flex-wrap items-center justify-between gap-2"
                                >
                                    <div class="text-xs text-slate-500">
                                        Consulta rápida; abre el grupo para
                                        editar personas, cobros o habitaciones.
                                    </div>
                                    <Button
                                        :as="Link"
                                        :href="
                                            route(
                                                'tenant.groups.show',
                                                group.id,
                                            )
                                        "
                                        variant="outline-secondary"
                                        class="h-8 rounded-[0.5rem] bg-white text-xs"
                                    >
                                        Administrar grupo
                                        <Lucide
                                            icon="ArrowRight"
                                            class="ml-1.5 h-3.5 w-3.5"
                                        />
                                    </Button>
                                </div>
                                <div
                                    class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
                                >
                                    <div
                                        v-for="reservation in group.reservations"
                                        :key="reservation.id"
                                        class="flex items-center gap-2.5 rounded-lg border border-slate-200/80 bg-white px-3 py-2 dark:border-darkmode-400 dark:bg-darkmode-600"
                                    >
                                        <div
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-info/10 text-info"
                                        >
                                            <Lucide
                                                icon="BedDouble"
                                                class="h-4 w-4"
                                            />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div
                                                class="flex flex-wrap items-center gap-1.5"
                                            >
                                                <span
                                                    class="text-xs font-medium"
                                                >
                                                    {{
                                                        reservation.room ??
                                                        reservation.code
                                                    }}
                                                </span>
                                                <span
                                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                    :class="
                                                        reservationStatusClass[
                                                            reservation.status
                                                        ] ??
                                                        'bg-slate-100 text-slate-500'
                                                    "
                                                >
                                                    {{
                                                        reservation.status_label
                                                    }}
                                                </span>
                                            </div>
                                            <div
                                                class="truncate text-xs text-slate-500"
                                            >
                                                {{ reservation.room_type }} ·
                                                {{ reservation.adults }}
                                                {{
                                                    reservation.adults === 1
                                                        ? 'adulto'
                                                        : 'adultos'
                                                }}
                                                <template
                                                    v-if="reservation.children"
                                                >
                                                    +
                                                    {{ reservation.children }}
                                                    {{
                                                        reservation.children ===
                                                        1
                                                            ? 'niño'
                                                            : 'niños'
                                                    }}
                                                </template>
                                            </div>
                                        </div>
                                        <div
                                            class="shrink-0 text-xs font-medium"
                                        >
                                            {{ money(reservation.total) }}
                                        </div>
                                    </div>
                                </div>

                                <div
                                    v-if="group.experiences.length"
                                    class="mt-3"
                                >
                                    <div
                                        class="mb-1.5 text-xs font-medium text-slate-500"
                                    >
                                        Experiencias incluidas
                                    </div>
                                    <div
                                        class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
                                    >
                                        <div
                                            v-for="experience in group.experiences"
                                            :key="experience.id"
                                            class="flex items-center gap-2.5 rounded-lg border border-slate-200/80 bg-white px-3 py-2 dark:border-darkmode-400 dark:bg-darkmode-600"
                                        >
                                            <div
                                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                                            >
                                                <Lucide
                                                    icon="Compass"
                                                    class="h-4 w-4"
                                                />
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div
                                                    class="truncate text-xs font-medium"
                                                >
                                                    {{
                                                        experience.name ??
                                                        experience.code
                                                    }}
                                                </div>
                                                <div
                                                    class="truncate text-xs text-slate-500"
                                                >
                                                    {{ experience.people }}
                                                    {{
                                                        experience.people === 1
                                                            ? 'persona'
                                                            : 'personas'
                                                    }}
                                                    <template
                                                        v-if="
                                                            experience.starts_at
                                                        "
                                                    >
                                                        ·
                                                        {{
                                                            formatDateTime(
                                                                experience.starts_at,
                                                            )
                                                        }}
                                                    </template>
                                                </div>
                                            </div>
                                            <div
                                                class="shrink-0 text-xs font-medium"
                                            >
                                                {{ money(experience.total) }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </div>

                    <!-- Paginación: la lista trae quince grupos por vuelta -->
                    <div
                        v-if="groups.links.length > 3"
                        class="flex flex-wrap justify-center gap-1 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                    >
                        <template v-for="(link, i) in groups.links" :key="i">
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

                <div
                    v-else-if="stats.total"
                    class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="SearchX" class="h-7 w-7" />
                    </div>
                    <div>
                        <p class="text-sm font-medium">
                            Ningún grupo coincide con los filtros
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cambia la búsqueda, el estado o el rango de fechas.
                        </p>
                    </div>
                    <Button
                        variant="outline-secondary"
                        @click="clearListFilters"
                    >
                        <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                        Limpiar filtros
                    </Button>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-primary"
                    >
                        <Lucide icon="UsersRound" class="h-7 w-7" />
                    </div>
                    <div>
                        <p class="text-sm font-medium">
                            Aún no hay reservas grupales
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Crea un grupo cuando una familia o evento necesite
                            dos habitaciones o más.
                        </p>
                    </div>
                    <Button
                        v-if="canManage"
                        variant="primary"
                        :disabled="!roomTypes.length"
                        @click="openForm"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Crear primer grupo
                    </Button>
                </div>
            </div>
        </div>

        <Dialog :open="showForm" size="xl" @close="showForm = false">
            <Dialog.Panel
                class="h-[calc(100dvh-4.5rem)] max-h-[calc(100dvh-4.5rem)] overflow-hidden sm:h-auto sm:max-h-[calc(100dvh-5rem)] sm:w-[94vw] lg:w-[980px]"
            >
                <form
                    class="flex h-full min-h-0 flex-col sm:max-h-[calc(100dvh-5rem)]"
                    @submit.prevent="submit"
                >
                    <div
                        class="flex shrink-0 items-start gap-3 border-b border-slate-200/70 px-4 py-3 sm:gap-4 sm:px-7 sm:py-5 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary sm:h-14 sm:w-14"
                        >
                            <Lucide icon="UsersRound" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium sm:text-lg">
                                Nueva reserva grupal
                            </h2>
                            <p
                                class="mt-0.5 text-xs leading-4 text-slate-500 sm:text-sm sm:leading-5"
                            >
                                Fechas, habitaciones y responsable en tres
                                pasos.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="!h-9 !w-9 shrink-0 rounded-full !p-0 sm:!h-10 sm:!w-10"
                            title="Cerrar"
                            @click="showForm = false"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </Button>
                    </div>

                    <div
                        class="grid shrink-0 grid-cols-3 gap-1.5 border-b border-slate-200/70 bg-slate-50/70 px-4 py-2.5 sm:gap-2 sm:px-7 sm:py-4 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                    >
                        <Button
                            v-for="step in formSteps"
                            :key="step.key"
                            type="button"
                            :variant="
                                activeFormStep === step.key
                                    ? 'primary'
                                    : 'outline-secondary'
                            "
                            class="min-h-10 justify-center rounded-[0.65rem] px-1.5 text-center text-xs sm:min-h-12 sm:justify-start sm:px-3 sm:text-left"
                            :disabled="
                                (step.key === 'rooms' && !stayStepComplete) ||
                                (step.key === 'contact' &&
                                    (!stayStepComplete || !roomsStepComplete))
                            "
                            @click="goToFormStep(step.key)"
                        >
                            <span
                                class="mr-3 hidden h-9 w-9 shrink-0 items-center justify-center rounded-full sm:flex"
                                :class="
                                    activeFormStep === step.key
                                        ? 'bg-white/15'
                                        : 'bg-primary/10 text-primary'
                                "
                            >
                                <Lucide :icon="step.icon" class="h-5 w-5" />
                            </span>
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-xs font-medium sm:text-sm"
                                >
                                    {{ step.number }}. {{ step.label }}
                                </span>
                                <span
                                    class="hidden truncate text-xs sm:block"
                                    :class="
                                        activeFormStep === step.key
                                            ? 'text-white/75'
                                            : 'text-slate-500'
                                    "
                                >
                                    {{ step.description }}
                                </span>
                            </span>
                        </Button>
                    </div>

                    <div
                        ref="formScrollContainer"
                        class="min-h-0 flex-1 touch-pan-y overflow-y-auto overscroll-contain px-4 py-4 [-webkit-overflow-scrolling:touch] [scrollbar-gutter:stable] sm:px-7 sm:py-5"
                    >
                        <section
                            v-if="activeFormStep === 'stay'"
                            class="space-y-5"
                        >
                            <div
                                class="rounded-xl border border-info/20 bg-info/5 p-4"
                            >
                                <div class="flex items-start gap-3">
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-info/10 text-info"
                                    >
                                        <Lucide
                                            icon="CalendarDays"
                                            class="h-4 w-4"
                                        />
                                    </div>
                                    <div>
                                        <h3 class="text-base font-medium">
                                            ¿Cuándo se hospedará el grupo?
                                        </h3>
                                        <p
                                            class="mt-0.5 text-sm text-slate-500"
                                        >
                                            Todos los cuartos compartirán estas
                                            fechas.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-12 gap-5">
                                <div
                                    v-if="hasModeChoice"
                                    class="col-span-12 sm:col-span-4"
                                >
                                    <FormLabel htmlFor="group-mode">
                                        Tipo de estancia
                                    </FormLabel>
                                    <FormSelect
                                        id="group-mode"
                                        v-model="form.mode"
                                        class="h-9 text-xs"
                                    >
                                        <option value="night">Por noche</option>
                                        <option value="block">
                                            Por periodo
                                        </option>
                                    </FormSelect>
                                </div>
                                <template v-if="form.mode === 'night'">
                                    <div
                                        class="col-span-12 sm:col-span-4"
                                        :class="
                                            hasModeChoice ? '' : 'sm:col-span-6'
                                        "
                                    >
                                        <FormLabel htmlFor="group-arrival">
                                            Fecha de llegada
                                        </FormLabel>
                                        <FormDate
                                            id="group-arrival"
                                            v-model="form.arrive_date"
                                            input-class="h-9 text-xs"
                                        />
                                    </div>
                                    <div
                                        class="col-span-12 sm:col-span-4"
                                        :class="
                                            hasModeChoice ? '' : 'sm:col-span-6'
                                        "
                                    >
                                        <FormLabel htmlFor="group-departure">
                                            Fecha de salida
                                        </FormLabel>
                                        <FormDate
                                            id="group-departure"
                                            v-model="form.depart_date"
                                            input-class="h-9 text-xs"
                                            :min="form.arrive_date"
                                        />
                                    </div>
                                </template>
                                <div
                                    v-else
                                    class="col-span-12"
                                    :class="
                                        hasModeChoice ? 'sm:col-span-8' : ''
                                    "
                                >
                                    <FormLabel htmlFor="group-arrival-time">
                                        Fecha y hora de llegada
                                    </FormLabel>
                                    <FormDateTime
                                        id="group-arrival-time"
                                        v-model="form.arrive_at"
                                        input-class="h-9 text-xs"
                                    />
                                </div>
                            </div>

                            <div
                                class="flex items-start gap-3 rounded-xl border border-dashed border-slate-300/70 p-4 dark:border-darkmode-400"
                            >
                                <Lucide
                                    icon="ShieldCheck"
                                    class="mt-0.5 h-5 w-5 shrink-0 text-success"
                                />
                                <div class="text-sm">
                                    <div class="font-medium">
                                        El grupo se reserva completo
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        Si falta disponibilidad para alguna
                                        habitación, no se apartará ninguna.
                                    </p>
                                </div>
                            </div>

                            <FormHelp
                                v-if="errors.starts_at || errors.ends_at"
                                class="text-danger"
                            >
                                {{ errors.starts_at ?? errors.ends_at }}
                            </FormHelp>
                        </section>

                        <section
                            v-else-if="activeFormStep === 'rooms'"
                            class="space-y-5"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-3"
                            >
                                <div>
                                    <h3 class="text-base font-medium">
                                        Elige las habitaciones
                                    </h3>
                                    <p class="mt-0.5 text-sm text-slate-500">
                                        Captura cuántos cuartos y personas
                                        necesita cada tipo.
                                    </p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        class="rounded-full bg-info/10 px-3 py-1.5 text-xs font-medium text-info"
                                    >
                                        {{ totalRooms }} habitaciones
                                    </span>
                                    <span
                                        class="rounded-full bg-primary/10 px-3 py-1.5 text-xs font-medium text-primary"
                                    >
                                        {{ totalGuests }} personas
                                    </span>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <div
                                    v-for="(line, index) in form.lines"
                                    :key="index"
                                    class="rounded-xl border border-slate-200/80 p-4 dark:border-darkmode-400"
                                >
                                    <div
                                        class="mb-4 flex items-center justify-between gap-3"
                                    >
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="flex h-10 w-10 items-center justify-center rounded-full bg-info/10 text-info"
                                            >
                                                <Lucide
                                                    icon="BedDouble"
                                                    class="h-5 w-5"
                                                />
                                            </div>
                                            <div>
                                                <div
                                                    class="text-sm font-medium"
                                                >
                                                    Tipo de habitación
                                                    {{ index + 1 }}
                                                </div>
                                                <div
                                                    class="text-xs text-slate-500"
                                                >
                                                    Una línea por cada tipo de
                                                    cuarto.
                                                </div>
                                            </div>
                                        </div>
                                        <Button
                                            v-if="form.lines.length > 1"
                                            type="button"
                                            variant="outline-danger"
                                            class="!h-10 !w-10 rounded-full !p-0"
                                            title="Quitar este tipo"
                                            @click="form.lines.splice(index, 1)"
                                        >
                                            <Lucide
                                                icon="Trash2"
                                                class="h-5 w-5"
                                            />
                                        </Button>
                                    </div>

                                    <div
                                        class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(15rem,1.4fr)_repeat(3,minmax(8rem,0.75fr))]"
                                    >
                                        <div>
                                            <FormLabel
                                                :htmlFor="`group-room-type-${index}`"
                                            >
                                                Tipo
                                            </FormLabel>
                                            <FormSelect
                                                :id="`group-room-type-${index}`"
                                                v-model="line.room_type_id"
                                                class="h-9 text-xs"
                                            >
                                                <option value="" disabled>
                                                    Elige un tipo
                                                </option>
                                                <option
                                                    v-for="roomType in typesForMode"
                                                    :key="roomType.id"
                                                    :value="roomType.id"
                                                >
                                                    {{ roomType.name }} ·
                                                    {{ roomType.rooms_count }}
                                                    disponibles · hasta
                                                    {{ roomType.capacity }}
                                                    personas
                                                </option>
                                            </FormSelect>
                                        </div>

                                        <div>
                                            <FormLabel>Habitaciones</FormLabel>
                                            <div
                                                class="flex h-11 items-center justify-between gap-2 rounded-[0.375rem] border border-slate-200 bg-white px-1.5 shadow-sm dark:border-darkmode-400 dark:bg-darkmode-800"
                                            >
                                                <Button
                                                    type="button"
                                                    variant="outline-secondary"
                                                    class="!h-8 !w-8 rounded-full !p-0"
                                                    :disabled="line.rooms <= 1"
                                                    @click="
                                                        setRooms(
                                                            line,
                                                            line.rooms - 1,
                                                        )
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Minus"
                                                        class="h-4 w-4"
                                                    />
                                                </Button>
                                                <span
                                                    class="text-sm font-medium"
                                                >
                                                    {{ line.rooms }}
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="outline-secondary"
                                                    class="!h-8 !w-8 rounded-full !p-0"
                                                    :disabled="
                                                        line.rooms >=
                                                        maxRoomsFor(line)
                                                    "
                                                    :title="`Hay ${maxRoomsFor(line)} cuartos físicos de este tipo`"
                                                    @click="
                                                        setRooms(
                                                            line,
                                                            line.rooms + 1,
                                                        )
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Plus"
                                                        class="h-4 w-4"
                                                    />
                                                </Button>
                                            </div>
                                        </div>

                                        <div>
                                            <FormLabel>
                                                Adultos por cuarto
                                            </FormLabel>
                                            <div
                                                class="flex h-11 items-center justify-between gap-2 rounded-[0.375rem] border border-slate-200 bg-white px-1.5 shadow-sm dark:border-darkmode-400 dark:bg-darkmode-800"
                                            >
                                                <Button
                                                    type="button"
                                                    variant="outline-secondary"
                                                    class="!h-8 !w-8 rounded-full !p-0"
                                                    :disabled="line.adults <= 1"
                                                    @click="
                                                        setAdults(
                                                            line,
                                                            line.adults - 1,
                                                        )
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Minus"
                                                        class="h-4 w-4"
                                                    />
                                                </Button>
                                                <span
                                                    class="text-sm font-medium"
                                                >
                                                    {{ line.adults }}
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="outline-secondary"
                                                    class="!h-8 !w-8 rounded-full !p-0"
                                                    :disabled="
                                                        line.adults +
                                                            line.children >=
                                                        capacityFor(line)
                                                    "
                                                    @click="
                                                        setAdults(
                                                            line,
                                                            line.adults + 1,
                                                        )
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Plus"
                                                        class="h-4 w-4"
                                                    />
                                                </Button>
                                            </div>
                                        </div>

                                        <div>
                                            <FormLabel>
                                                Niños por cuarto
                                            </FormLabel>
                                            <div
                                                class="flex h-11 items-center justify-between gap-2 rounded-[0.375rem] border border-slate-200 bg-white px-1.5 shadow-sm dark:border-darkmode-400 dark:bg-darkmode-800"
                                            >
                                                <Button
                                                    type="button"
                                                    variant="outline-secondary"
                                                    class="!h-8 !w-8 rounded-full !p-0"
                                                    :disabled="
                                                        line.children <= 0
                                                    "
                                                    @click="
                                                        setChildren(
                                                            line,
                                                            line.children - 1,
                                                        )
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Minus"
                                                        class="h-4 w-4"
                                                    />
                                                </Button>
                                                <span
                                                    class="text-sm font-medium"
                                                >
                                                    {{ line.children }}
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="outline-secondary"
                                                    class="!h-8 !w-8 rounded-full !p-0"
                                                    :disabled="
                                                        line.adults +
                                                            line.children >=
                                                        capacityFor(line)
                                                    "
                                                    @click="
                                                        setChildren(
                                                            line,
                                                            line.children + 1,
                                                        )
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Plus"
                                                        class="h-4 w-4"
                                                    />
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="flex flex-wrap items-center justify-between gap-3"
                            >
                                <Button
                                    type="button"
                                    variant="outline-secondary"
                                    :disabled="form.lines.length >= 10"
                                    @click="addLine"
                                >
                                    <Lucide
                                        icon="Plus"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Agregar otro tipo
                                </Button>
                                <p class="text-xs text-slate-500">
                                    Mínimo 2 y máximo 30 habitaciones por grupo.
                                </p>
                            </div>

                            <FormHelp
                                v-if="
                                    errors.lines ||
                                    Object.keys(errors).some((key) =>
                                        key.startsWith('lines.'),
                                    )
                                "
                                class="text-danger"
                            >
                                {{
                                    errors.lines ??
                                    errors[
                                        Object.keys(errors).find((key) =>
                                            key.startsWith('lines.'),
                                        ) ?? ''
                                    ]
                                }}
                            </FormHelp>
                        </section>

                        <section v-else class="space-y-5">
                            <div
                                class="rounded-xl border border-primary/20 bg-primary/5 p-4"
                            >
                                <div class="flex items-start gap-3">
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                                    >
                                        <Lucide
                                            icon="UserRound"
                                            class="h-4 w-4"
                                        />
                                    </div>
                                    <div>
                                        <h3 class="text-base font-medium">
                                            ¿Quién responde por el grupo?
                                        </h3>
                                        <p
                                            class="mt-0.5 text-sm text-slate-500"
                                        >
                                            El nombre es obligatorio; teléfono,
                                            correo y notas son opcionales.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-12 gap-5">
                                <div class="col-span-12 sm:col-span-6">
                                    <FormLabel htmlFor="group-guest-name">
                                        Nombre del responsable
                                    </FormLabel>
                                    <div class="relative">
                                        <Lucide
                                            icon="User"
                                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3.5 h-5 w-5 text-slate-400"
                                        />
                                        <FormInput
                                            id="group-guest-name"
                                            v-model="form.guest_name"
                                            type="text"
                                            class="h-11 pl-11"
                                            placeholder="Nombre completo"
                                        />
                                    </div>
                                    <FormHelp
                                        v-if="errors.guest_name"
                                        class="text-danger"
                                    >
                                        {{ errors.guest_name }}
                                    </FormHelp>
                                </div>

                                <div class="col-span-12 sm:col-span-6">
                                    <FormLabel htmlFor="group-email">
                                        Correo electrónico
                                    </FormLabel>
                                    <div class="relative">
                                        <Lucide
                                            icon="Mail"
                                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3.5 h-5 w-5 text-slate-400"
                                        />
                                        <FormInput
                                            id="group-email"
                                            v-model="form.guest_email"
                                            type="email"
                                            class="h-11 pl-11"
                                            placeholder="correo@ejemplo.com"
                                        />
                                    </div>
                                    <FormHelp
                                        v-if="errors.guest_email"
                                        class="text-danger"
                                    >
                                        {{ errors.guest_email }}
                                    </FormHelp>
                                </div>

                                <div class="col-span-12">
                                    <FormLabel htmlFor="group-phone-country">
                                        Teléfono
                                    </FormLabel>
                                    <div
                                        class="grid gap-2"
                                        :class="
                                            phoneCountry === 'other'
                                                ? 'grid-cols-1 sm:grid-cols-[13rem_7rem_minmax(0,1fr)]'
                                                : 'grid-cols-1 sm:grid-cols-[13rem_minmax(0,1fr)]'
                                        "
                                    >
                                        <FormSelect
                                            id="group-phone-country"
                                            v-model="phoneCountry"
                                            class="h-9 text-xs"
                                            aria-label="País de la lada"
                                            @change="changePhoneCountry"
                                        >
                                            <option value="mx">
                                                +52 · México
                                            </option>
                                            <option value="us">
                                                +1 · Estados Unidos / Canadá
                                            </option>
                                            <option value="other">
                                                Otro país · escribir lada
                                            </option>
                                        </FormSelect>
                                        <FormInput
                                            v-if="phoneCountry === 'other'"
                                            v-model="phoneDialCode"
                                            type="tel"
                                            inputmode="numeric"
                                            class="h-9 text-xs"
                                            placeholder="+34"
                                            aria-label="Lada internacional"
                                            @input="syncGuestPhone"
                                        />
                                        <div class="relative">
                                            <Lucide
                                                icon="Phone"
                                                class="absolute inset-y-0 left-0 z-10 my-auto ml-3.5 h-5 w-5 text-slate-400"
                                            />
                                            <FormInput
                                                v-model="phoneNationalNumber"
                                                type="tel"
                                                inputmode="tel"
                                                autocomplete="tel-national"
                                                class="h-11 pl-11"
                                                placeholder="Número telefónico"
                                                @input="syncGuestPhone"
                                            />
                                        </div>
                                    </div>
                                    <FormHelp>
                                        {{
                                            guestPhonePreview
                                                ? `Se guardará como ${guestPhonePreview}`
                                                : 'La lada se guardará junto con el número.'
                                        }}
                                    </FormHelp>
                                    <FormHelp
                                        v-if="errors.guest_phone"
                                        class="text-danger"
                                    >
                                        {{ errors.guest_phone }}
                                    </FormHelp>
                                </div>

                                <div class="col-span-12">
                                    <FormLabel htmlFor="group-notes">
                                        Notas para el personal
                                    </FormLabel>
                                    <FormTextarea
                                        id="group-notes"
                                        v-model="form.notes"
                                        rows="3"
                                        placeholder="Nombre del evento, hora aproximada de llegada o acuerdos importantes..."
                                    />
                                </div>
                            </div>

                            <div
                                class="flex flex-col gap-4 rounded-xl border border-slate-200/80 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-darkmode-400"
                            >
                                <div class="flex items-start gap-3">
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                                        :class="
                                            form.confirmed
                                                ? 'bg-success/10 text-success'
                                                : 'bg-pending/10 text-pending'
                                        "
                                    >
                                        <Lucide
                                            :icon="
                                                form.confirmed
                                                    ? 'CircleCheck'
                                                    : 'AlarmClock'
                                            "
                                            class="h-4 w-4"
                                        />
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium">
                                            {{
                                                form.confirmed
                                                    ? 'Crear como confirmado'
                                                    : 'Crear por confirmar'
                                            }}
                                        </div>
                                        <p
                                            class="mt-0.5 text-xs text-slate-500"
                                        >
                                            {{
                                                form.confirmed
                                                    ? 'Las habitaciones quedarán confirmadas de inmediato.'
                                                    : 'Quedarán apartadas temporalmente hasta confirmar el grupo.'
                                            }}
                                        </p>
                                    </div>
                                </div>
                                <FormSwitch>
                                    <FormSwitch.Input
                                        :checked="form.confirmed"
                                        type="checkbox"
                                        aria-label="Cambiar estado inicial"
                                        @change="
                                            form.confirmed = !form.confirmed
                                        "
                                    />
                                </FormSwitch>
                            </div>
                        </section>
                    </div>

                    <div
                        class="flex shrink-0 flex-col gap-2 border-t border-slate-200/70 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-7 sm:py-4 dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <div class="text-xs text-slate-500">
                            Paso {{ activeFormStepIndex + 1 }} de
                            {{ formSteps.length }}
                        </div>
                        <div
                            class="grid w-full gap-2 sm:flex sm:w-auto sm:flex-row"
                            :class="
                                activeFormStepIndex > 0
                                    ? 'grid-cols-2'
                                    : 'grid-cols-1'
                            "
                        >
                            <Button
                                v-if="activeFormStepIndex > 0"
                                type="button"
                                variant="outline-secondary"
                                class="h-10 w-full px-3 text-xs sm:h-10 sm:px-5"
                                @click="previousFormStep"
                            >
                                <Lucide
                                    icon="ArrowLeft"
                                    class="mr-1.5 h-4 w-4 sm:h-5 sm:w-5"
                                />
                                Anterior
                            </Button>
                            <Button
                                v-if="activeFormStep !== 'contact'"
                                type="button"
                                variant="primary"
                                class="h-10 w-full px-3 text-xs sm:h-10 sm:px-5"
                                :disabled="
                                    activeFormStep === 'stay'
                                        ? !stayStepComplete
                                        : !roomsStepComplete
                                "
                                @click="nextFormStep"
                            >
                                Siguiente
                                <Lucide
                                    icon="ArrowRight"
                                    class="ml-1.5 h-4 w-4 sm:h-5 sm:w-5"
                                />
                            </Button>
                            <Button
                                v-else
                                type="submit"
                                variant="primary"
                                class="h-10 w-full px-3 text-xs sm:h-10 sm:px-5"
                                :disabled="
                                    saving ||
                                    !stayStepComplete ||
                                    !roomsStepComplete ||
                                    !contactStepComplete
                                "
                            >
                                <Lucide
                                    icon="CircleCheck"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                {{
                                    saving
                                        ? 'Reservando...'
                                        : `Reservar ${totalRooms} habitaciones`
                                }}
                            </Button>
                        </div>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <Dialog
            :open="editingGroup !== null"
            size="lg"
            @close="editingGroup = null"
        >
            <Dialog.Panel>
                <form @submit.prevent="submitEdit">
                    <div class="flex items-start gap-4 px-5 py-4">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="UserPen" class="h-7 w-7" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                Editar responsable
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                Grupo {{ editingGroup?.code }}. Las habitaciones
                                se administran desde la ficha del grupo.
                            </p>
                        </div>
                    </div>
                    <div
                        class="space-y-5 border-y border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                    >
                        <div>
                            <FormLabel htmlFor="edit-group-guest">
                                Nombre del responsable
                            </FormLabel>
                            <FormInput
                                id="edit-group-guest"
                                v-model="editForm.guest_name"
                                type="text"
                                class="h-9 text-xs"
                                placeholder="Nombre completo"
                            />
                        </div>
                        <div>
                            <FormLabel htmlFor="edit-group-notes">
                                Notas para el personal
                            </FormLabel>
                            <FormTextarea
                                id="edit-group-notes"
                                v-model="editForm.notes"
                                rows="4"
                                placeholder="Evento, hora de llegada o acuerdos importantes..."
                            />
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 px-5 py-3.5">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-10 px-5 text-xs"
                            @click="editingGroup = null"
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            class="h-10 px-5 text-xs"
                            :disabled="editBusy || !editForm.guest_name.trim()"
                        >
                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                            {{ editBusy ? 'Guardando...' : 'Guardar cambios' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <Dialog
            :open="cancelling !== null"
            size="lg"
            @close="cancelling = null"
        >
            <Dialog.Panel>
                <div class="px-5 py-5 text-center">
                    <div
                        class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-danger/10 text-danger"
                    >
                        <Lucide icon="TriangleAlert" class="h-8 w-8" />
                    </div>
                    <h2 class="mt-4 text-base font-medium">
                        ¿Cancelar todo el grupo {{ cancelling?.code }}?
                    </h2>
                    <p class="mx-auto mt-2 max-w-lg text-sm text-slate-500">
                        Se liberarán las habitaciones pendientes o confirmadas.
                        Las que ya tienen un huésped alojado conservarán su
                        estado.
                    </p>
                    <div
                        class="mt-4 rounded-xl border border-danger/20 bg-danger/5 px-4 py-3 text-left text-sm"
                    >
                        <span class="font-medium">
                            {{
                                cancelling?.reservations.filter(
                                    (reservation) =>
                                        reservation.status === 'pending' ||
                                        reservation.status === 'confirmed',
                                ).length
                            }}
                            reservas se cancelarán.
                        </span>
                        <span class="text-slate-500">
                            Esta acción deja historial.
                        </span>
                    </div>
                    <div class="mt-6 flex justify-center gap-3">
                        <Button
                            variant="outline-secondary"
                            class="h-10 px-5 text-xs"
                            @click="cancelling = null"
                        >
                            Conservar grupo
                        </Button>
                        <Button
                            variant="danger"
                            class="h-10 px-5 text-xs"
                            :disabled="cancelBusy"
                            @click="cancelGroup"
                        >
                            <Lucide icon="Ban" class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                cancelBusy
                                    ? 'Cancelando...'
                                    : 'Cancelar todo el grupo'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <Dialog
            :open="deletingGroup !== null"
            size="lg"
            @close="deletingGroup = null"
        >
            <Dialog.Panel>
                <div class="px-5 py-5 text-center">
                    <div
                        class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-danger/10 text-danger"
                    >
                        <Lucide icon="Trash2" class="h-8 w-8" />
                    </div>
                    <h2 class="mt-4 text-base font-medium">
                        ¿Eliminar el folio {{ deletingGroup?.code }}?
                    </h2>
                    <p class="mx-auto mt-2 max-w-lg text-sm text-slate-500">
                        El grupo desaparecerá de esta lista. Sus reservas
                        canceladas seguirán en Reservas y los grupos con pagos
                        no pueden eliminarse.
                    </p>
                    <div class="mt-6 flex justify-center gap-3">
                        <Button
                            variant="outline-secondary"
                            class="h-10 px-5 text-xs"
                            @click="deletingGroup = null"
                        >
                            Conservar folio
                        </Button>
                        <Button
                            variant="danger"
                            class="h-10 px-5 text-xs"
                            :disabled="deleteBusy"
                            @click="deleteGroup"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                deleteBusy ? 'Eliminando...' : 'Eliminar folio'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
