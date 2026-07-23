<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
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

// Experiencia colgada del grupo (tour comprado como plus del GRP-).
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

const props = defineProps<{
    groups: GroupRow[];
    roomTypes: RoomTypeOption[];
    canManage: boolean;
}>();

const toast = useToasts();
const money = (n: number) =>
    `$${n.toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;
const formatDateTime = (iso: string) =>
    new Date(iso).toLocaleString('es-MX', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });

const expanded = ref<Set<number>>(new Set());
function toggle(id: number) {
    const next = new Set(expanded.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    expanded.value = next;
}

const statusClass: Record<string, string> = {
    pending: 'bg-warning/10 text-warning',
    confirmed: 'bg-success/10 text-success',
    checked_in: 'bg-primary/10 text-primary',
    completed: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    cancelled: 'bg-danger/10 text-danger',
    no_show: 'bg-danger/10 text-danger',
};

// ── Alta de grupo ──
interface LineForm {
    room_type_id: number | '';
    rooms: number;
    adults: number;
    children: number;
}

const showForm = ref(false);
const saving = ref(false);
const errors = reactive<Record<string, string>>({});
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

const modeAvailable = computed(() => ({
    night: props.roomTypes.some((t) => t.has_night),
    block: props.roomTypes.some((t) => t.has_block),
}));

const typesForMode = computed(() =>
    props.roomTypes.filter(
        (t) =>
            t.rooms_count > 0 &&
            (form.mode === 'night' ? t.has_night : t.has_block),
    ),
);

const hasModeChoice = computed(
    () => modeAvailable.value.night && modeAvailable.value.block,
);

const totalRooms = computed(() =>
    form.lines.reduce((sum, l) => sum + (Number(l.rooms) || 0), 0),
);

function openForm() {
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
    Object.keys(errors).forEach((k) => delete errors[k]);
    showForm.value = true;
}

function addLine() {
    form.lines.push({ room_type_id: '', rooms: 1, adults: 2, children: 0 });
}

// Topes reales por línea (mismos clamps que el wizard de grupos): cuartos
// físicos del tipo y capacidad por habitación. El servidor los vuelve a
// validar; aquí solo evitamos capturar combinaciones imposibles.
function typeFor(line: LineForm): RoomTypeOption | undefined {
    return props.roomTypes.find((t) => t.id === line.room_type_id);
}

function maxRoomsFor(line: LineForm): number {
    return Math.min(typeFor(line)?.rooms_count || 30, 30);
}

function capacityFor(line: LineForm): number {
    return typeFor(line)?.capacity || 20;
}

function setRooms(line: LineForm, n: number) {
    line.rooms = Math.max(1, Math.min(n, maxRoomsFor(line)));
}

function setAdults(line: LineForm, n: number) {
    line.adults = Math.max(1, Math.min(n, capacityFor(line) - line.children));
}

function setChildren(line: LineForm, n: number) {
    line.children = Math.max(0, Math.min(n, capacityFor(line) - line.adults));
}

// Al elegir o cambiar el tipo, los contadores se reajustan a sus topes.
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

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    const startsAt =
        form.mode === 'night' ? `${form.arrive_date}T15:00` : form.arrive_at;
    const endsAt =
        form.mode === 'night' && form.depart_date
            ? `${form.depart_date}T12:00`
            : null;
    try {
        const { data } = await axios.post<GroupRow>('/api/group-reservations', {
            mode: form.mode,
            starts_at: startsAt,
            ends_at: endsAt,
            guest_name: form.guest_name,
            guest_phone: form.guest_phone || null,
            guest_email: form.guest_email || null,
            notes: form.notes || null,
            confirmed: form.confirmed,
            lines: form.lines.filter((l) => l.room_type_id !== ''),
        });
        showForm.value = false;
        toast.success(
            'Grupo creado',
            `Folio ${data.code}: ${data.rooms} habitaciones por ${money(data.total)}.`,
        );
        router.reload({ only: ['groups'] });
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(
                ([key, msgs]) => (errors[key] = (msgs as string[])[0]),
            );
            toast.error(
                'Revisa el formulario',
                Object.values(
                    data.errors as Record<string, string[]>,
                )[0]?.[0] ?? '',
            );
        } else {
            toast.error(
                'No se pudo crear el grupo',
                data?.message ?? 'Nada se reservó: revisa disponibilidad.',
            );
        }
    } finally {
        saving.value = false;
    }
}

// ── Cancelar grupo ──
const cancelling = ref<GroupRow | null>(null);
const cancelBusy = ref(false);

async function cancelGroup() {
    if (!cancelling.value) return;
    cancelBusy.value = true;
    try {
        const { data } = await axios.post(
            `/api/group-reservations/${cancelling.value.id}/cancel`,
        );
        toast.success(
            'Grupo cancelado',
            `${data.cancelled} reserva(s) canceladas; las que ya avanzaron no se tocan.`,
        );
        cancelling.value = null;
        router.reload({ only: ['groups'] });
    } catch (e: any) {
        toast.error(
            'Error',
            e.response?.data?.message ?? 'No se pudo cancelar el grupo.',
        );
    } finally {
        cancelBusy.value = false;
    }
}

const groupIsLive = (group: GroupRow) =>
    group.reservations.some(
        (r) => r.status === 'pending' || r.status === 'confirmed',
    );

// ── Editar grupo (responsable y notas; los cuartos se operan en /reservas) ──
const editingGroup = ref<GroupRow | null>(null);
const editForm = reactive({ guest_name: '', notes: '' });
const editBusy = ref(false);

function openEdit(group: GroupRow) {
    editingGroup.value = group;
    editForm.guest_name = group.guest_name ?? '';
    editForm.notes = group.notes ?? '';
}

async function submitEdit() {
    if (!editingGroup.value) return;
    editBusy.value = true;
    try {
        await axios.patch(`/api/group-reservations/${editingGroup.value.id}`, {
            guest_name: editForm.guest_name,
            notes: editForm.notes || null,
        });
        toast.success('Grupo actualizado');
        editingGroup.value = null;
        router.reload({ only: ['groups'] });
    } catch (e: any) {
        toast.error(
            'Error',
            e.response?.data?.message ?? 'No se pudo actualizar el grupo.',
        );
    } finally {
        editBusy.value = false;
    }
}

// ── Eliminar grupo muerto (sin reservas vivas ni pagos): limpieza del
// listado; sus reservas canceladas siguen visibles en /reservas ──
const deletingGroup = ref<GroupRow | null>(null);
const deleteBusy = ref(false);

async function deleteGroup() {
    if (!deletingGroup.value) return;
    deleteBusy.value = true;
    try {
        await axios.delete(`/api/group-reservations/${deletingGroup.value.id}`);
        toast.success(
            'Grupo eliminado',
            'Sus reservas canceladas siguen visibles en Reservas.',
        );
        deletingGroup.value = null;
        router.reload({ only: ['groups'] });
    } catch (e: any) {
        toast.error(
            'No se pudo eliminar',
            e.response?.data?.message ?? 'Cancela el grupo primero.',
        );
    } finally {
        deleteBusy.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Reservas grupales">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Reservas grupales</h1>
                    <p class="mt-0.5 text-sm text-slate-500">
                        Varias habitaciones de un jalón bajo un folio de grupo.
                        Todo o nada: si falta una, no se aparta ninguna.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        as="a"
                        href="/reservar/grupos"
                        target="_blank"
                        variant="outline-primary"
                        class="rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="ExternalLink"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Ver wizard de grupos
                    </Button>
                    <Button
                        v-if="canManage"
                        variant="primary"
                        class="rounded-[0.5rem] shadow-md shadow-primary/20"
                        @click="openForm"
                    >
                        <Lucide icon="Plus" class="mr-2 h-4 w-4" /> Nuevo grupo
                    </Button>
                </div>
            </div>

            <div v-if="groups.length" class="mt-5 space-y-4">
                <div
                    v-for="group in groups"
                    :key="group.id"
                    class="box box--stacked"
                >
                    <button
                        type="button"
                        class="flex w-full flex-wrap items-center gap-4 px-5 py-4 text-left"
                        @click="toggle(group.id)"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                        >
                            <Lucide
                                icon="UsersRound"
                                class="h-5 w-5 text-primary"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{
                                    group.code
                                }}</span>
                                <span class="text-sm text-slate-500">{{
                                    group.guest_name
                                }}</span>
                            </div>
                            <div class="mt-0.5 text-xs text-slate-500">
                                {{ group.rooms }} habitaciones ·
                                <template v-if="group.starts_at"
                                    >{{ formatDateTime(group.starts_at)
                                    }}<template v-if="group.ends_at">
                                        →
                                        {{
                                            formatDateTime(group.ends_at)
                                        }}</template
                                    ></template
                                >
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="font-semibold">
                                {{ money(group.total) }}
                            </div>
                            <div class="text-xs text-slate-400">
                                total del grupo
                            </div>
                        </div>
                        <Lucide
                            :icon="
                                expanded.has(group.id)
                                    ? 'ChevronUp'
                                    : 'ChevronDown'
                            "
                            class="h-4 w-4 shrink-0 text-slate-400"
                        />
                    </button>

                    <div
                        v-if="expanded.has(group.id)"
                        class="border-t border-dashed border-slate-300/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div class="space-y-2">
                            <div
                                v-for="reservation in group.reservations"
                                :key="reservation.id"
                                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200/70 px-3 py-2.5 text-sm dark:border-darkmode-400"
                            >
                                <div class="min-w-0">
                                    <span class="font-medium">{{
                                        reservation.code
                                    }}</span>
                                    <span class="ml-2 text-slate-500"
                                        >{{ reservation.room_type
                                        }}<template v-if="reservation.room">
                                            · Hab.
                                            {{ reservation.room }}</template
                                        ></span
                                    >
                                    <span class="ml-2 text-xs text-slate-400"
                                        >{{ reservation.adults }}A{{
                                            reservation.children
                                                ? ` + ${reservation.children}N`
                                                : ''
                                        }}</span
                                    >
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <span class="text-sm font-medium">{{
                                        money(reservation.total)
                                    }}</span>
                                    <span
                                        class="rounded-full px-2.5 py-1 text-xs font-medium"
                                        :class="
                                            statusClass[reservation.status] ??
                                            'bg-slate-100 text-slate-500'
                                        "
                                    >
                                        {{ reservation.status_label }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <!-- Tours del grupo: mismo apartado, mismo cobro consolidado -->
                        <div
                            v-if="group.experiences?.length"
                            class="mt-2 space-y-2"
                        >
                            <div
                                v-for="exp in group.experiences"
                                :key="exp.id"
                                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200/70 px-3 py-2.5 text-sm dark:border-darkmode-400"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <Lucide
                                        icon="Compass"
                                        class="h-4 w-4 shrink-0 text-slate-400"
                                    />
                                    <span class="font-medium">{{
                                        exp.code
                                    }}</span>
                                    <span class="text-slate-500">{{
                                        exp.name
                                    }}</span>
                                    <span class="text-xs text-slate-400"
                                        >{{ exp.people }} persona(s)<template
                                            v-if="exp.starts_at"
                                        >
                                            ·
                                            {{
                                                formatDateTime(exp.starts_at)
                                            }}</template
                                        ></span
                                    >
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <span class="text-sm font-medium">{{
                                        money(exp.total)
                                    }}</span>
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
                            </div>
                        </div>
                        <div
                            v-if="group.notes"
                            class="mt-3 text-xs text-slate-500"
                        >
                            Notas: {{ group.notes }}
                        </div>
                        <div
                            v-if="canManage"
                            class="mt-4 flex flex-wrap justify-end gap-2"
                        >
                            <Button
                                as="a"
                                :href="`/grupos/${group.id}`"
                                variant="outline-primary"
                                size="sm"
                                class="rounded-[0.5rem] bg-white"
                            >
                                <Lucide
                                    icon="Eye"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Ver detalle
                            </Button>
                            <Button
                                variant="outline-secondary"
                                size="sm"
                                class="rounded-[0.5rem] bg-white"
                                @click="openEdit(group)"
                            >
                                <Lucide
                                    icon="Pencil"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Editar
                            </Button>
                            <Button
                                v-if="groupIsLive(group)"
                                variant="outline-secondary"
                                size="sm"
                                class="rounded-[0.5rem] bg-white text-danger"
                                @click="cancelling = group"
                            >
                                <Lucide icon="Ban" class="mr-1.5 h-3.5 w-3.5" />
                                Cancelar grupo completo
                            </Button>
                            <Button
                                v-else
                                variant="outline-secondary"
                                size="sm"
                                class="rounded-[0.5rem] bg-white text-danger"
                                @click="deletingGroup = group"
                            >
                                <Lucide
                                    icon="Trash2"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Eliminar grupo
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
            <div
                v-else
                class="box box--stacked mt-5 flex flex-col items-center gap-3 px-5 py-12 text-center"
            >
                <Lucide icon="UsersRound" class="h-10 w-10 text-slate-300" />
                <div>
                    <p class="text-sm font-medium text-slate-600">
                        Aún no hay reservas grupales
                    </p>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Cuando llegue una familia grande o un evento, crea el
                        grupo aquí: todas las habitaciones de un jalón.
                    </p>
                </div>
                <Button
                    v-if="canManage"
                    variant="primary"
                    class="rounded-[0.5rem]"
                    @click="openForm"
                >
                    <Lucide icon="Plus" class="mr-2 h-4 w-4" /> Nuevo grupo
                </Button>
            </div>
        </div>

        <!-- Modal alta de grupo -->
        <Dialog :open="showForm" size="xl" @close="showForm = false">
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="submit">
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-7 py-5 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                        >
                            <Lucide
                                icon="UsersRound"
                                class="h-5 w-5 text-primary"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">Nuevo grupo</h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Todo o nada: si alguna habitación no tiene
                                disponibilidad, no se aparta ninguna.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showForm = false"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <div
                        class="max-h-[70vh] space-y-6 overflow-y-auto px-7 py-6"
                    >
                        <!-- Fechas -->
                        <section>
                            <div
                                class="mb-4 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide
                                    icon="CalendarDays"
                                    class="h-3.5 w-3.5"
                                />
                                Fechas
                            </div>
                            <div class="grid grid-cols-12 gap-5">
                                <div
                                    v-if="hasModeChoice"
                                    class="col-span-12 sm:col-span-4"
                                >
                                    <label class="mb-1 block text-sm"
                                        >Modalidad</label
                                    >
                                    <FormSelect v-model="form.mode">
                                        <option value="night">Por noche</option>
                                        <option value="block">
                                            Por periodo
                                        </option>
                                    </FormSelect>
                                </div>
                                <template v-if="form.mode === 'night'">
                                    <div
                                        class="col-span-6"
                                        :class="
                                            hasModeChoice
                                                ? 'sm:col-span-4'
                                                : 'sm:col-span-6'
                                        "
                                    >
                                        <label class="mb-1 block text-sm"
                                            >Llegada</label
                                        >
                                        <FormInput
                                            v-model="form.arrive_date"
                                            type="date"
                                        />
                                    </div>
                                    <div
                                        class="col-span-6"
                                        :class="
                                            hasModeChoice
                                                ? 'sm:col-span-4'
                                                : 'sm:col-span-6'
                                        "
                                    >
                                        <label class="mb-1 block text-sm"
                                            >Salida</label
                                        >
                                        <FormInput
                                            v-model="form.depart_date"
                                            type="date"
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
                                    <label class="mb-1 block text-sm"
                                        >Fecha y hora de llegada</label
                                    >
                                    <FormInput
                                        v-model="form.arrive_at"
                                        type="datetime-local"
                                    />
                                </div>
                            </div>
                            <FormHelp
                                v-if="errors.starts_at"
                                class="text-danger"
                                >{{ errors.starts_at }}</FormHelp
                            >
                        </section>

                        <!-- Habitaciones -->
                        <section
                            class="border-t border-dashed border-slate-300/70 pt-5"
                        >
                            <div
                                class="mb-4 flex items-center justify-between gap-2"
                            >
                                <div
                                    class="flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    <Lucide
                                        icon="BedDouble"
                                        class="h-3.5 w-3.5"
                                    />
                                    Habitaciones ({{ totalRooms }} en total)
                                </div>
                                <Button
                                    type="button"
                                    variant="outline-secondary"
                                    size="sm"
                                    class="rounded-[0.5rem] bg-white"
                                    :disabled="form.lines.length >= 10"
                                    @click="addLine"
                                >
                                    <Lucide
                                        icon="Plus"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Otro tipo
                                </Button>
                            </div>
                            <div class="space-y-3">
                                <div
                                    v-for="(line, index) in form.lines"
                                    :key="index"
                                    class="rounded-lg border border-slate-200/70 p-4 dark:border-darkmode-400"
                                >
                                    <div
                                        v-if="form.lines.length > 1"
                                        class="mb-3 flex items-center justify-between"
                                    >
                                        <span
                                            class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                                            >Tipo {{ index + 1 }}</span
                                        >
                                        <button
                                            type="button"
                                            class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                            title="Quitar línea"
                                            @click="form.lines.splice(index, 1)"
                                        >
                                            <Lucide
                                                icon="Trash2"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                    </div>
                                    <div
                                        class="grid grid-cols-12 items-end gap-4"
                                    >
                                        <div class="col-span-12 sm:col-span-6">
                                            <label
                                                class="mb-1 block text-sm whitespace-nowrap"
                                                >Tipo de habitación</label
                                            >
                                            <FormSelect
                                                v-model="line.room_type_id"
                                            >
                                                <option value="" disabled>
                                                    Elige un tipo
                                                </option>
                                                <option
                                                    v-for="type in typesForMode"
                                                    :key="type.id"
                                                    :value="type.id"
                                                >
                                                    {{ type.name }} ({{
                                                        type.rooms_count
                                                    }}
                                                    {{
                                                        type.rooms_count === 1
                                                            ? 'cuarto'
                                                            : 'cuartos'
                                                    }}, hasta
                                                    {{ type.capacity }}
                                                    {{
                                                        type.capacity === 1
                                                            ? 'persona'
                                                            : 'personas'
                                                    }})
                                                </option>
                                            </FormSelect>
                                        </div>
                                        <div class="col-span-4 sm:col-span-2">
                                            <label
                                                class="mb-1 block text-sm whitespace-nowrap"
                                                >Cuartos</label
                                            >
                                            <div
                                                class="flex h-[38px] items-center gap-2"
                                            >
                                                <button
                                                    type="button"
                                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30 dark:border-darkmode-400 dark:hover:bg-darkmode-400"
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
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </button>
                                                <span
                                                    class="w-6 text-center text-sm font-medium"
                                                    >{{ line.rooms }}</span
                                                >
                                                <button
                                                    type="button"
                                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30 dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                                                    :disabled="
                                                        line.rooms >=
                                                        maxRoomsFor(line)
                                                    "
                                                    :title="`Solo hay ${maxRoomsFor(line)} de este tipo`"
                                                    @click="
                                                        setRooms(
                                                            line,
                                                            line.rooms + 1,
                                                        )
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Plus"
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </button>
                                            </div>
                                        </div>
                                        <div class="col-span-4 sm:col-span-2">
                                            <label
                                                class="mb-1 block text-sm whitespace-nowrap"
                                                >Adultos c/u</label
                                            >
                                            <div
                                                class="flex h-[38px] items-center gap-2"
                                            >
                                                <button
                                                    type="button"
                                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30 dark:border-darkmode-400 dark:hover:bg-darkmode-400"
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
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </button>
                                                <span
                                                    class="w-6 text-center text-sm font-medium"
                                                    >{{ line.adults }}</span
                                                >
                                                <button
                                                    type="button"
                                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30 dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                                                    :disabled="
                                                        line.adults +
                                                            line.children >=
                                                        capacityFor(line)
                                                    "
                                                    :title="`Capacidad: hasta ${capacityFor(line)} personas por habitación`"
                                                    @click="
                                                        setAdults(
                                                            line,
                                                            line.adults + 1,
                                                        )
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Plus"
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </button>
                                            </div>
                                        </div>
                                        <div class="col-span-4 sm:col-span-2">
                                            <label
                                                class="mb-1 block text-sm whitespace-nowrap"
                                                >Niños c/u</label
                                            >
                                            <div
                                                class="flex h-[38px] items-center gap-2"
                                            >
                                                <button
                                                    type="button"
                                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30 dark:border-darkmode-400 dark:hover:bg-darkmode-400"
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
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </button>
                                                <span
                                                    class="w-6 text-center text-sm font-medium"
                                                    >{{ line.children }}</span
                                                >
                                                <button
                                                    type="button"
                                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30 dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                                                    :disabled="
                                                        line.adults +
                                                            line.children >=
                                                        capacityFor(line)
                                                    "
                                                    :title="`Capacidad: hasta ${capacityFor(line)} personas por habitación`"
                                                    @click="
                                                        setChildren(
                                                            line,
                                                            line.children + 1,
                                                        )
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Plus"
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <FormHelp v-if="totalRooms > 30" class="text-danger"
                                >Máximo 30 habitaciones por grupo.</FormHelp
                            >
                            <FormHelp
                                >El precio por habitación lo pone la tarifa
                                activa más barata de la modalidad elegida, igual
                                que en el wizard.</FormHelp
                            >
                        </section>

                        <!-- Responsable -->
                        <section
                            class="border-t border-dashed border-slate-300/70 pt-5"
                        >
                            <div
                                class="mb-4 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide icon="User" class="h-3.5 w-3.5" />
                                Responsable del grupo
                            </div>
                            <div class="grid grid-cols-12 gap-5">
                                <div class="col-span-12 sm:col-span-6">
                                    <label class="mb-1 block text-sm"
                                        >Nombre</label
                                    >
                                    <FormInput
                                        v-model="form.guest_name"
                                        type="text"
                                        placeholder="Quien responde por el grupo"
                                    />
                                    <FormHelp
                                        v-if="errors.guest_name"
                                        class="text-danger"
                                        >{{ errors.guest_name }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-3">
                                    <label class="mb-1 block text-sm"
                                        >Teléfono</label
                                    >
                                    <FormInput
                                        v-model="form.guest_phone"
                                        type="tel"
                                        placeholder="10 dígitos"
                                    />
                                </div>
                                <div class="col-span-12 sm:col-span-3">
                                    <label class="mb-1 block text-sm"
                                        >Email</label
                                    >
                                    <FormInput
                                        v-model="form.guest_email"
                                        type="email"
                                        placeholder="opcional"
                                    />
                                </div>
                                <div class="col-span-12">
                                    <label class="mb-1 block text-sm"
                                        >Notas</label
                                    >
                                    <FormTextarea
                                        v-model="form.notes"
                                        rows="2"
                                        placeholder="Boda García, llegan en autobús a las 4pm…"
                                    />
                                </div>
                            </div>
                            <div
                                class="mt-4 flex items-center justify-between gap-4 rounded-lg border border-dashed border-slate-300/70 px-4 py-3 dark:border-darkmode-400"
                            >
                                <div class="text-sm">
                                    <div class="font-medium">
                                        Confirmar de una vez
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        Apagado: quedan como apartado pendiente
                                        que expira solo si nadie lo confirma.
                                    </p>
                                </div>
                                <FormSwitch>
                                    <FormSwitch.Input
                                        :checked="form.confirmed"
                                        type="checkbox"
                                        @change="
                                            form.confirmed = !form.confirmed
                                        "
                                    />
                                </FormSwitch>
                            </div>
                        </section>
                    </div>

                    <div
                        class="flex justify-end gap-2 border-t border-slate-200/70 px-7 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="showForm = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            :disabled="
                                saving ||
                                totalRooms < 2 ||
                                totalRooms > 30 ||
                                !form.guest_name.trim() ||
                                (form.mode === 'night'
                                    ? !form.arrive_date || !form.depart_date
                                    : !form.arrive_at)
                            "
                        >
                            {{
                                saving
                                    ? 'Reservando…'
                                    : `Reservar ${totalRooms} habitaciones`
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmar cancelación de grupo -->
        <Dialog :open="cancelling !== null" @close="cancelling = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide
                        icon="AlertTriangle"
                        class="mx-auto mb-3 h-12 w-12 text-danger"
                    />
                    <h2 class="text-base font-medium">
                        ¿Cancelar el grupo {{ cancelling?.code }}?
                    </h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Se cancelan sus
                        {{
                            cancelling?.reservations.filter(
                                (r) =>
                                    r.status === 'pending' ||
                                    r.status === 'confirmed',
                            ).length
                        }}
                        reserva(s) vivas y se liberan las habitaciones. Las que
                        ya hicieron check-in no se tocan.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="cancelling = null"
                            >Conservar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="cancelBusy"
                            @click="cancelGroup"
                            >{{
                                cancelBusy ? 'Cancelando…' : 'Sí, cancelar todo'
                            }}</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Editar grupo (responsable y notas) -->
        <Dialog :open="editingGroup !== null" @close="editingGroup = null">
            <Dialog.Panel>
                <form class="p-5" @submit.prevent="submitEdit">
                    <div class="mb-4 flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                        >
                            <Lucide icon="Pencil" class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                Editar grupo {{ editingGroup?.code }}
                            </h2>
                            <p class="text-xs text-slate-500">
                                Responsable y notas. Las habitaciones se operan
                                una por una en Reservas.
                            </p>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm"
                                >Responsable del grupo</label
                            >
                            <FormInput
                                v-model="editForm.guest_name"
                                type="text"
                                placeholder="Quien responde por el grupo"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Notas</label>
                            <FormTextarea
                                v-model="editForm.notes"
                                rows="2"
                                placeholder="Boda, evento, hora de llegada del grupo…"
                            />
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="editingGroup = null"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            :disabled="editBusy || !editForm.guest_name.trim()"
                        >
                            {{ editBusy ? 'Guardando…' : 'Guardar cambios' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmar eliminación de grupo muerto -->
        <Dialog :open="deletingGroup !== null" @close="deletingGroup = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide
                        icon="AlertTriangle"
                        class="mx-auto mb-3 h-12 w-12 text-danger"
                    />
                    <h2 class="text-base font-medium">
                        ¿Eliminar el grupo {{ deletingGroup?.code }}?
                    </h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Solo desaparece el folio del grupo de esta lista; sus
                        reservas canceladas siguen visibles en Reservas. Si
                        tiene pagos registrados no se puede eliminar.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="deletingGroup = null"
                            >Conservar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="deleteBusy"
                            @click="deleteGroup"
                        >
                            {{ deleteBusy ? 'Eliminando…' : 'Sí, eliminar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
