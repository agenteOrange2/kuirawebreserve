<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormLabel, FormSelect, FormSwitch, FormTextarea } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import { AMENITY_SUGGESTIONS } from '@/lib/amenities';

interface Bed {
    type: string;
    qty: number;
}

interface RoomRow {
    id: number;
    number: string;
    name: string | null;
    description: string | null;
    zone_id: number | null;
    zone: string | null;
    zone_color: string | null;
    room_type_id: number;
    room_type: string;
    status: string;
    status_label: string;
    status_color: string;
    notes: string | null;
    beds: Bed[];
    beds_label: string | null;
    max_occupancy: number | null;
    capacity: number | null;
    size_m2: number | null;
    view: string | null;
    amenities: string[];
    smoking: boolean;
    accessible: boolean;
    price_modifier: number | null;
    included_occupancy: number | null;
    extra_guest_fee: number | null;
    optional_charges: OptionalCharge[];
    maintenance_notes: string | null;
}

interface OptionalCharge {
    concept: string;
    amount: number;
}

const props = defineProps<{
    property: { id: number; name: string };
    rooms: RoomRow[];
    zones: { id: number; name: string; kind: string; color: string | null }[];
    roomTypes: { id: number; name: string; capacity: number; price_from: number | null; has_active_rate: boolean }[];
    bedTypes: Record<string, string>;
    maxRooms: number | null;
    canManage: boolean;
}>();

// Guarda de precio único: tipos sin tarifa activa no son reservables.
const typesWithoutRate = computed(() => new Set(props.roomTypes.filter((t) => !t.has_active_rate).map((t) => t.id)));

const toast = useToasts();

// Límite del plan visible (patrón spec-plan-maestro §8).
const limitPercent = computed(() =>
    props.maxRooms ? Math.min(100, Math.round((props.rooms.length / props.maxRooms) * 100)) : 0,
);

// Filtros y búsqueda (client-side: la lista completa ya está cargada).
const filters = reactive({ search: '', zone: '' as string | number, type: '' as string | number, status: '' });

const statusOptions = computed(() => {
    const seen = new Map<string, string>();
    props.rooms.forEach((r) => seen.set(r.status, r.status_label));
    return [...seen.entries()].map(([value, label]) => ({ value, label }));
});

const filteredRooms = computed(() =>
    props.rooms.filter((r) => {
        const q = filters.search.trim().toLowerCase();
        if (q && !r.number.toLowerCase().includes(q) && !(r.name ?? '').toLowerCase().includes(q)) return false;
        if (filters.zone !== '' && r.zone_id !== Number(filters.zone)) return false;
        if (filters.type !== '' && r.room_type_id !== Number(filters.type)) return false;
        if (filters.status && r.status !== filters.status) return false;
        return true;
    }),
);

const filtersActive = computed(
    () => filters.search.trim() !== '' || filters.zone !== '' || filters.type !== '' || filters.status !== '',
);

function clearFilters() {
    filters.search = '';
    filters.zone = '';
    filters.type = '';
    filters.status = '';
}

// Herencia explícita: lo que la habitación toma del tipo seleccionado.
const selectedFormType = computed(() => props.roomTypes.find((t) => t.id === Number(form.room_type_id)) ?? null);

// spec-reservas-avanzado §2.4: "personas incluidas" + "cobro por persona
// extra" solo tienen efecto si el TECHO REAL de este cuarto deja espacio
// para esa gente extra. Ese techo es `max_occupancy` si está capturado
// (spec-reservas-avanzado §2.3: es lo que de verdad usa la búsqueda y
// CreateReservation desde el fix de capacidad) — antes esta vista previa
// comparaba contra la capacidad del TIPO nada más, así que un cuarto con
// `max_occupancy` propio más alto seguía viendo "nunca se va a cobrar" de
// forma incorrecta (el caso real que rompió esto: motellacupula 101 con
// incluidas=2 igual a la capacidad del tipo, y max_occupancy nunca
// capturado por vivir en otra sección del formulario — ya no puede pasar,
// están juntos aquí).
const occupancyPreview = computed(() => {
    const typeCapacity = selectedFormType.value?.capacity ?? null;
    const maxOccupancy = form.max_occupancy === '' ? typeCapacity : Number(form.max_occupancy);
    const included = form.included_occupancy === '' ? null : Number(form.included_occupancy);
    const fee = form.extra_guest_fee === '' ? 0 : Number(form.extra_guest_fee);

    if (maxOccupancy === null || included === null) return null;

    if (included >= maxOccupancy) {
        return {
            unreachable: true,
            message:
                form.max_occupancy === ''
                    ? `El recargo nunca se va a cobrar: sin "ocupación máxima" propia, este cuarto hereda el tope del tipo "${selectedFormType.value?.name}" (${typeCapacity}) y ya lo incluyes todo. Sube la ocupación máxima de este cuarto para que el recargo tenga efecto.`
                    : `El recargo nunca se va a cobrar: la ocupación máxima de este cuarto es ${maxOccupancy} y ya la incluyes toda.`,
        };
    }

    const extraSeats = maxOccupancy - included;
    return {
        unreachable: false,
        message:
            fee > 0
                ? `Hasta ${maxOccupancy} personas en total: ${included} incluidas + hasta ${extraSeats} extra pagando $${fee} cada una.`
                : `Hasta ${maxOccupancy} personas en total: ${included} incluidas + hasta ${extraSeats} extra (agrega un cobro para que paguen algo).`,
    };
});

const durationUnits = [
    { value: 'minute', label: 'Minutos' },
    { value: 'hour', label: 'Horas' },
    { value: 'day', label: 'Días' },
    { value: 'week', label: 'Semanas' },
    { value: 'month', label: 'Meses' },
];

// ── Alta masiva por rango ──
const showBulk = ref(false);
const bulkForm = reactive({
    room_type_id: '' as string | number,
    zone_id: '' as string | number,
    number_from: '' as string | number,
    number_to: '' as string | number,
});

const existingNumbers = computed(() => new Set(props.rooms.map((r) => r.number)));

const bulkPreview = computed(() => {
    const from = Number(bulkForm.number_from);
    const to = Number(bulkForm.number_to);
    if (!Number.isInteger(from) || !Number.isInteger(to) || from < 1 || to < from || to - from >= 100) return [];
    return Array.from({ length: to - from + 1 }, (_, i) => String(from + i)).map((number) => ({
        number,
        exists: existingNumbers.value.has(number),
    }));
});

const bulkNew = computed(() => bulkPreview.value.filter((p) => !p.exists).length);
const bulkOverLimit = computed(() => props.maxRooms !== null && props.rooms.length + bulkNew.value > props.maxRooms);

function openBulk() {
    bulkForm.room_type_id = props.roomTypes[0]?.id ?? '';
    bulkForm.zone_id = '';
    bulkForm.number_from = '';
    bulkForm.number_to = '';
    clearErrors();
    showBulk.value = true;
}

async function submitBulk() {
    saving.value = true;
    clearErrors();
    try {
        const { data } = await axios.post<{ created: string[]; skipped: string[] }>('/api/rooms/bulk', {
            property_id: props.property.id,
            room_type_id: bulkForm.room_type_id,
            zone_id: bulkForm.zone_id === '' ? null : bulkForm.zone_id,
            number_from: Number(bulkForm.number_from),
            number_to: Number(bulkForm.number_to),
        });
        showBulk.value = false;
        toast.success(
            `${data.created.length} habitación(es) creada(s)`,
            data.skipped.length ? `Se omitieron ${data.skipped.length} números que ya existían: ${data.skipped.join(', ')}.` : undefined,
        );
        router.reload({ only: ['rooms'] });
    } catch (error) {
        handleError(error);
    } finally {
        saving.value = false;
    }
}

// ── Alta rápida: tipo + tarifa + habitación (caso motel) ──
const showQuick = ref(false);
const quickForm = reactive({
    number: '',
    name: '',
    capacity: 2 as number | string,
    zone_id: '' as string | number,
    price: '' as string | number,
    rate_type: 'night',
    duration_value: 3 as number | string,
    duration_unit: 'hour',
});

function openQuick() {
    quickForm.number = '';
    quickForm.name = '';
    quickForm.capacity = 2;
    quickForm.zone_id = '';
    quickForm.price = '';
    quickForm.rate_type = 'night';
    quickForm.duration_value = 3;
    quickForm.duration_unit = 'hour';
    clearErrors();
    showQuick.value = true;
}

async function submitQuick() {
    saving.value = true;
    clearErrors();
    try {
        await axios.post('/api/rooms/single-unit', {
            property_id: props.property.id,
            zone_id: quickForm.zone_id === '' ? null : quickForm.zone_id,
            number: quickForm.number,
            name: quickForm.name,
            capacity: Number(quickForm.capacity),
            price: quickForm.price,
            rate_type: quickForm.rate_type,
            duration_unit: quickForm.rate_type === 'block' ? quickForm.duration_unit : null,
            duration_value: quickForm.rate_type === 'block' ? quickForm.duration_value : null,
        });
        showQuick.value = false;
        toast.success('Habitación creada', `${quickForm.name} quedó con su tipo, tarifa y habitación ${quickForm.number}.`);
        router.reload({ only: ['rooms', 'roomTypes'] });
    } catch (error) {
        handleError(error);
    } finally {
        saving.value = false;
    }
}

async function duplicateRoom(room: RoomRow) {
    try {
        const { data } = await axios.post<{ number: string }>(`/api/rooms/${room.id}/duplicate`);
        toast.success('Habitación duplicada', `Se creó la ${data.number} con la misma ficha que la ${room.number}.`);
        router.reload({ only: ['rooms'] });
    } catch (error) {
        handleError(error);
    }
}

const dotColor: Record<string, string> = {
    green: 'bg-success',
    cyan: 'bg-info',
    red: 'bg-primary',
    orange: 'bg-pending',
    blue: 'bg-warning',
    gray: 'bg-dark',
};

const showForm = ref(false);
const editing = ref<RoomRow | null>(null);
const deleting = ref<RoomRow | null>(null);
const saving = ref(false);
const errors = reactive<Record<string, string>>({});
const generalError = ref<string | null>(null);

const form = reactive({
    number: '',
    name: '',
    room_type_id: '' as string | number,
    zone_id: '' as string | number,
    description: '',
    beds: [] as Bed[],
    max_occupancy: '' as string | number,
    size_m2: '' as string | number,
    view: '',
    amenities: [] as string[],
    smoking: false,
    accessible: false,
    price_modifier: '' as string | number,
    included_occupancy: '' as string | number,
    extra_guest_fee: '' as string | number,
    optional_charges: [] as { concept: string; amount: string | number }[],
    notes: '',
    maintenance_notes: '',
});

const amenityInput = ref('');

function openCreate() {
    editing.value = null;
    form.number = '';
    form.name = '';
    form.room_type_id = props.roomTypes[0]?.id ?? '';
    form.zone_id = '';
    form.description = '';
    form.beds = [];
    form.max_occupancy = '';
    form.size_m2 = '';
    form.view = '';
    form.amenities = [];
    form.smoking = false;
    form.accessible = false;
    form.price_modifier = '';
    form.included_occupancy = '';
    form.extra_guest_fee = '';
    form.optional_charges = [];
    form.notes = '';
    form.maintenance_notes = '';
    amenityInput.value = '';
    clearErrors();
    showForm.value = true;
}

function openEdit(room: RoomRow) {
    editing.value = room;
    form.number = room.number;
    form.name = room.name ?? '';
    form.room_type_id = room.room_type_id;
    form.zone_id = room.zone_id ?? '';
    form.description = room.description ?? '';
    form.beds = (room.beds ?? []).map((bed) => ({ ...bed }));
    form.max_occupancy = room.max_occupancy ?? '';
    form.size_m2 = room.size_m2 ?? '';
    form.view = room.view ?? '';
    form.amenities = [...(room.amenities ?? [])];
    form.smoking = room.smoking;
    form.accessible = room.accessible;
    form.price_modifier = room.price_modifier ?? '';
    form.included_occupancy = room.included_occupancy ?? '';
    form.extra_guest_fee = room.extra_guest_fee ?? '';
    form.optional_charges = (room.optional_charges ?? []).map((charge) => ({ ...charge }));
    form.notes = room.notes ?? '';
    form.maintenance_notes = room.maintenance_notes ?? '';
    amenityInput.value = '';
    clearErrors();
    showForm.value = true;
}

function addBed() {
    form.beds.push({ type: 'matrimonial', qty: 1 });
}

function removeBed(index: number) {
    form.beds.splice(index, 1);
}

function addOptionalCharge() {
    form.optional_charges.push({ concept: '', amount: '' });
}

function removeOptionalCharge(index: number) {
    form.optional_charges.splice(index, 1);
}

function addAmenity() {
    const value = amenityInput.value.trim();
    if (value && !form.amenities.includes(value)) {
        form.amenities.push(value);
    }
    amenityInput.value = '';
}

function removeAmenity(index: number) {
    form.amenities.splice(index, 1);
}

function priceModifierLabel(modifier: number): string {
    const rounded = Math.round(Math.abs(modifier));
    return modifier > 0 ? `+$${rounded}` : `−$${rounded}`;
}

function clearErrors() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    generalError.value = null;
}

function handleError(error: any) {
    clearErrors();
    const data = error.response?.data;
    if (data?.errors) {
        Object.entries(data.errors).forEach(([key, messages]) => {
            errors[key] = (messages as string[])[0];
            // Los campos de arreglo llegan como `amenities.8`: se copia el
            // mensaje a la clave base para que se pinte bajo el input.
            const base = key.split('.')[0];
            if (!errors[base]) {
                errors[base] = (messages as string[])[0];
            }
        });
        toast.error('Revisa el formulario', Object.values(errors)[0]);
    } else {
        generalError.value = data?.message ?? 'Ocurrió un error inesperado.';
        toast.error('Error', generalError.value ?? undefined);
    }
}

function toNumberOrNull(value: string | number): number | null {
    return value === '' || value === null ? null : Number(value);
}

async function submit() {
    saving.value = true;
    clearErrors();

    const payload: Record<string, unknown> = {
        number: form.number,
        name: form.name.trim() === '' ? null : form.name.trim(),
        room_type_id: form.room_type_id,
        zone_id: form.zone_id === '' ? null : form.zone_id,
        description: form.description.trim() === '' ? null : form.description,
        beds: form.beds.map((bed) => ({ type: bed.type, qty: Number(bed.qty) })),
        max_occupancy: toNumberOrNull(form.max_occupancy),
        size_m2: toNumberOrNull(form.size_m2),
        view: form.view.trim() === '' ? null : form.view.trim(),
        amenities: form.amenities,
        smoking: form.smoking,
        accessible: form.accessible,
        price_modifier: toNumberOrNull(form.price_modifier),
        included_occupancy: toNumberOrNull(form.included_occupancy),
        extra_guest_fee: toNumberOrNull(form.extra_guest_fee),
        optional_charges: form.optional_charges
            .filter((charge) => charge.concept.trim() !== '')
            .map((charge) => ({ concept: charge.concept.trim(), amount: Number(charge.amount) || 0 })),
        notes: form.notes === '' ? null : form.notes,
        maintenance_notes: form.maintenance_notes.trim() === '' ? null : form.maintenance_notes,
    };

    try {
        if (editing.value) {
            await axios.patch(`/api/rooms/${editing.value.id}`, payload);
            toast.success('Habitación actualizada', `La habitación ${form.number} se guardó correctamente.`);
        } else {
            // Posición inicial escalonada para que no se encimen en el plano.
            const i = props.rooms.length;
            await axios.post('/api/rooms', {
                ...payload,
                property_id: props.property.id,
                pos_x: 40 + (i % 5) * 160,
                pos_y: 40 + Math.floor(i / 5) * 120,
            });
            toast.success('Habitación creada', `La habitación ${form.number} se agregó al plano.`);
        }
        showForm.value = false;
        router.reload({ only: ['rooms'] });
    } catch (error) {
        handleError(error);
    } finally {
        saving.value = false;
    }
}

// Deep-link desde la ficha (Show): /habitaciones?edit=ID abre el modal.
onMounted(() => {
    const editId = new URLSearchParams(window.location.search).get('edit');
    if (editId && props.canManage) {
        const room = props.rooms.find((r) => r.id === Number(editId));
        if (room) openEdit(room);
    }
});

async function submitDelete() {
    if (!deleting.value) return;
    saving.value = true;
    const number = deleting.value.number;
    try {
        await axios.delete(`/api/rooms/${deleting.value.id}`);
        deleting.value = null;
        toast.success('Habitación eliminada', `La habitación ${number} se quitó del sistema.`);
        router.reload({ only: ['rooms'] });
    } catch (error) {
        handleError(error);
        deleting.value = null;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Habitaciones">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Habitaciones</h1>
                    <p class="text-sm text-slate-500">
                        {{ rooms.length }}<span v-if="maxRooms"> de {{ maxRooms }}</span> habitaciones · {{ property.name }}
                    </p>
                </div>
                <div v-if="canManage" class="flex flex-wrap gap-2">
                    <Button variant="outline-secondary" title="Crea el tipo, su tarifa y la habitación en un paso" @click="openQuick">
                        <Lucide icon="Zap" class="mr-2 h-4 w-4" />
                        Alta rápida
                    </Button>
                    <Button
                        variant="outline-secondary"
                        :disabled="!roomTypes.length"
                        title="Crea varias habitaciones de golpe por rango de números"
                        @click="openBulk"
                    >
                        <Lucide icon="Layers" class="mr-2 h-4 w-4" />
                        Alta masiva
                    </Button>
                    <Button variant="primary" :disabled="!roomTypes.length" @click="openCreate">
                        <Lucide icon="Plus" class="mr-2 h-4 w-4" />
                        Nueva habitación
                    </Button>
                </div>
            </div>

            <div v-if="!roomTypes.length" class="box mt-5 border-l-4 border-l-warning p-5">
                <p class="text-sm">
                    Antes de crear habitaciones define al menos un
                    <Link :href="route('tenant.catalog')" class="text-primary underline">tipo de habitación</Link>.
                </p>
            </div>

            <div v-if="generalError" class="mt-4 rounded-md bg-danger/10 px-4 py-3 text-sm text-danger">
                {{ generalError }}
            </div>

            <div class="box mt-5">
                <!-- Límite del plan visible (X de Y) -->
                <div v-if="maxRooms" class="border-b border-slate-200/60 px-5 pb-3.5 pt-4 dark:border-darkmode-400">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span class="text-slate-500">Habitaciones del plan</span>
                        <span class="text-xs" :class="limitPercent >= 100 ? 'font-medium text-danger' : 'text-slate-500'">
                            {{ rooms.length }} de {{ maxRooms }}
                            <Link
                                v-if="limitPercent >= 80"
                                :href="route('tenant.hotel-settings')"
                                class="ml-2 font-medium text-primary hover:underline"
                            >
                                Tu plan
                            </Link>
                        </span>
                    </div>
                    <div class="mt-2 h-1.5 rounded-full bg-slate-200/70 dark:bg-darkmode-400">
                        <div
                            class="h-1.5 rounded-full"
                            :class="limitPercent >= 100 ? 'bg-danger' : limitPercent >= 80 ? 'bg-warning' : 'bg-primary'"
                            :style="{ width: `${limitPercent}%` }"
                        />
                    </div>
                </div>

                <!-- Filtros y búsqueda -->
                <div v-if="rooms.length" class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400">
                    <div class="relative w-full sm:w-56">
                        <Lucide icon="Search" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                        <FormInput v-model="filters.search" type="text" class="pl-9" placeholder="Buscar por número o nombre" />
                    </div>
                    <FormSelect v-model="filters.zone" class="w-full sm:w-40">
                        <option value="">Todas las zonas</option>
                        <option v-for="zone in zones" :key="zone.id" :value="zone.id">{{ zone.name }}</option>
                    </FormSelect>
                    <FormSelect v-model="filters.type" class="w-full sm:w-44">
                        <option value="">Todos los tipos</option>
                        <option v-for="type in roomTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
                    </FormSelect>
                    <FormSelect v-model="filters.status" class="w-full sm:w-40">
                        <option value="">Todos los estados</option>
                        <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </FormSelect>
                    <div v-if="filtersActive" class="flex items-center gap-2 text-xs text-slate-500">
                        {{ filteredRooms.length }} de {{ rooms.length }}
                        <button type="button" class="font-medium text-primary hover:underline" @click="clearFilters">Limpiar</button>
                    </div>
                </div>

                <div class="overflow-x-auto p-5">
                    <Table v-if="filteredRooms.length" striped>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Número</Table.Th>
                                <Table.Th>Tipo</Table.Th>
                                <Table.Th>Camas / Capacidad</Table.Th>
                                <Table.Th>Zona</Table.Th>
                                <Table.Th>Estado</Table.Th>
                                <Table.Th>Notas</Table.Th>
                                <Table.Th v-if="canManage" class="text-right">Acciones</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="room in filteredRooms" :key="room.id">
                                <Table.Td>
                                    <Link :href="route('tenant.rooms.show', room.id)" class="font-medium text-primary hover:underline">{{ room.number }}</Link>
                                    <div v-if="room.name" class="text-xs text-slate-500">{{ room.name }}</div>
                                </Table.Td>
                                <Table.Td>
                                    {{ room.room_type }}
                                    <span
                                        v-if="typesWithoutRate.has(room.room_type_id)"
                                        class="ml-1.5 whitespace-nowrap rounded-full bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning"
                                        title="El tipo no tiene tarifa activa; agrégala en Zonas y tipos"
                                    >
                                        Sin tarifa
                                    </span>
                                </Table.Td>
                                <Table.Td>
                                    <span class="text-slate-600 dark:text-slate-300">{{ room.beds_label ?? '—' }}</span>
                                    <span v-if="room.capacity" class="whitespace-nowrap text-slate-500"> · {{ room.capacity }} pers</span>
                                </Table.Td>
                                <Table.Td>
                                    <span class="inline-flex items-center gap-1.5">
                                        <span
                                            v-if="room.zone_color"
                                            class="h-2 w-2 shrink-0 rounded-full"
                                            :style="{ backgroundColor: room.zone_color }"
                                        />
                                        {{ room.zone ?? '—' }}
                                    </span>
                                </Table.Td>
                                <Table.Td>
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-2 w-2 rounded-full" :class="dotColor[room.status_color]" />
                                        {{ room.status_label }}
                                    </span>
                                    <div
                                        v-if="room.smoking || room.accessible || room.price_modifier"
                                        class="mt-1 flex items-center gap-1.5"
                                    >
                                        <span v-if="room.smoking" title="Se permite fumar">
                                            <Lucide icon="Cigarette" class="h-4 w-4 text-slate-400" />
                                        </span>
                                        <span v-if="room.accessible" title="Accesible / planta baja">
                                            <Lucide icon="Accessibility" class="h-4 w-4 text-slate-400" />
                                        </span>
                                        <span
                                            v-if="room.price_modifier"
                                            title="Ajuste de precio por unidad sobre la tarifa del tipo"
                                            class="rounded-full px-1.5 py-0.5 text-xs font-medium"
                                            :class="room.price_modifier > 0 ? 'bg-warning/10 text-warning' : 'bg-success/10 text-success'"
                                        >
                                            {{ priceModifierLabel(room.price_modifier) }}
                                        </span>
                                    </div>
                                </Table.Td>
                                <Table.Td class="max-w-[200px] truncate text-slate-500">{{ room.notes ?? '—' }}</Table.Td>
                                <Table.Td v-if="canManage">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link
                                            :href="route('tenant.rooms.show', room.id)"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-primary dark:hover:bg-darkmode-400"
                                            title="Ver ficha y uso"
                                        >
                                            <Lucide icon="Eye" class="h-4 w-4" />
                                        </Link>
                                        <button
                                            type="button"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                            title="Editar"
                                            @click="openEdit(room)"
                                        >
                                            <Lucide icon="Pencil" class="h-4 w-4" />
                                        </button>
                                        <button
                                            type="button"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-primary dark:hover:bg-darkmode-400"
                                            title="Duplicar con el siguiente número libre"
                                            @click="duplicateRoom(room)"
                                        >
                                            <Lucide icon="Copy" class="h-4 w-4" />
                                        </button>
                                        <button
                                            type="button"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                            title="Eliminar"
                                            @click="deleting = room"
                                        >
                                            <Lucide icon="Trash2" class="h-4 w-4" />
                                        </button>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div v-else-if="rooms.length" class="flex flex-col items-center gap-2 py-8 text-center text-slate-500">
                        Ninguna habitación coincide con los filtros.
                        <button type="button" class="text-sm font-medium text-primary hover:underline" @click="clearFilters">
                            Limpiar filtros
                        </button>
                    </div>
                    <div v-else class="py-8 text-center text-slate-500">
                        Sin habitaciones aún.
                        <template v-if="canManage && roomTypes.length">
                            Crea la primera con "Nueva habitación", o varias de golpe con "Alta masiva".
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: crear / editar -->
        <Dialog :open="showForm" size="xl" @close="showForm = false">
            <Dialog.Panel>
                <form class="flex max-h-[85vh] flex-col" @submit.prevent="submit">
                    <!-- Header -->
                    <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Lucide :icon="editing ? 'Pencil' : 'BedDouble'" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">{{ editing ? `Editar habitación ${editing.number}` : 'Nueva habitación' }}</h2>
                            <p class="mt-0.5 text-xs text-slate-500">{{ property.name }}</p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showForm = false"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 space-y-7 overflow-y-auto px-6 py-6">
                        <!-- Identificación -->
                        <div class="space-y-4">
                            <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="Hash" class="h-3.5 w-3.5" /> Identificación
                            </div>
                            <div class="grid grid-cols-12 gap-4">
                                <div class="col-span-12 sm:col-span-3">
                                    <FormLabel htmlFor="room-number">Número *</FormLabel>
                                    <div class="relative">
                                        <Lucide icon="Hash" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                        <FormInput id="room-number" v-model="form.number" type="text" class="pl-9" placeholder="101" />
                                    </div>
                                    <FormHelp v-if="errors.number" class="text-danger">{{ errors.number }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-9">
                                    <FormLabel htmlFor="room-name">Nombre comercial (opcional)</FormLabel>
                                    <div class="relative">
                                        <Lucide icon="Sparkles" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                        <FormInput id="room-name" v-model="form.name" type="text" class="pl-9" placeholder="Suite Luna de Miel" />
                                    </div>
                                    <FormHelp v-if="errors.name" class="text-danger">{{ errors.name }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <FormLabel htmlFor="room-type">Tipo *</FormLabel>
                                    <div class="relative">
                                        <Lucide icon="Tag" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                        <FormSelect id="room-type" v-model="form.room_type_id" class="pl-9">
                                            <option v-for="type in roomTypes" :key="type.id" :value="type.id">
                                                {{ type.name }}{{ type.price_from !== null ? ` — desde $${type.price_from}` : ' — sin tarifa' }}
                                            </option>
                                        </FormSelect>
                                    </div>
                                    <FormHelp v-if="errors.room_type_id" class="text-danger">{{ errors.room_type_id }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <FormLabel htmlFor="room-zone">Zona (opcional)</FormLabel>
                                    <div class="relative">
                                        <Lucide icon="Map" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                        <FormSelect id="room-zone" v-model="form.zone_id" class="pl-9">
                                            <option value="">Sin zona</option>
                                            <option v-for="zone in zones" :key="zone.id" :value="zone.id">{{ zone.name }}</option>
                                        </FormSelect>
                                    </div>
                                    <FormHelp v-if="errors.zone_id" class="text-danger">{{ errors.zone_id }}</FormHelp>
                                </div>
                                <!-- Herencia explícita: qué aporta el tipo (se edita en el catálogo) -->
                                <div
                                    v-if="selectedFormType"
                                    class="col-span-12 flex items-start gap-2 rounded-md border border-slate-200/70 bg-slate-50/70 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                                >
                                    <Lucide icon="ArrowDownToLine" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                                    <span>
                                        <span class="font-medium text-slate-600 dark:text-slate-300">Del tipo {{ selectedFormType.name }}:</span>
                                        capacidad {{ selectedFormType.capacity }} pers ·
                                        {{ selectedFormType.price_from !== null ? `desde $${selectedFormType.price_from}` : 'sin tarifa (no reservable)' }}
                                        · horarios y amenidades base — se editan en
                                        <Link :href="route('tenant.catalog')" class="font-medium text-primary hover:underline">Zonas y tipos</Link>.
                                        Abajo capturas solo lo propio de ESTA habitación.
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- De esta habitación -->
                        <div class="space-y-5 border-t border-slate-200/60 pt-6 dark:border-darkmode-400">
                            <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="ClipboardList" class="h-3.5 w-3.5" /> De esta habitación
                            </div>
                            <div>
                                <FormLabel htmlFor="room-description">Descripción</FormLabel>
                                <FormTextarea
                                    id="room-description"
                                    v-model="form.description"
                                    rows="2"
                                    placeholder="Habitación amplia con balcón privado…"
                                />
                                <FormHelp v-if="errors.description" class="text-danger">{{ errors.description }}</FormHelp>
                            </div>

                            <!-- Camas (grupo propio) -->
                            <div>
                                <div class="mb-2 flex items-center justify-between">
                                    <FormLabel class="mb-0">Camas</FormLabel>
                                    <Button type="button" variant="outline-secondary" size="sm" class="rounded-[0.5rem]" @click="addBed">
                                        <Lucide icon="Plus" class="mr-1 h-4 w-4" /> Agregar cama
                                    </Button>
                                </div>
                                <div class="rounded-lg border border-dashed border-slate-300/70 p-3 dark:border-darkmode-400">
                                    <div v-if="form.beds.length" class="space-y-2.5">
                                        <div v-for="(bed, index) in form.beds" :key="index" class="flex items-center gap-2.5">
                                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400 dark:bg-darkmode-400">
                                                <Lucide icon="BedDouble" class="h-4 w-4" />
                                            </div>
                                            <FormSelect v-model="bed.type" class="flex-1">
                                                <option v-for="(label, value) in bedTypes" :key="value" :value="value">{{ label }}</option>
                                            </FormSelect>
                                            <FormInput v-model.number="bed.qty" type="number" min="1" class="w-20 text-center" />
                                            <button
                                                type="button"
                                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                                title="Quitar cama"
                                                @click="removeBed(index)"
                                            >
                                                <Lucide icon="Trash2" class="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>
                                    <div v-else class="flex items-center justify-center gap-2 py-2 text-xs text-slate-400">
                                        <Lucide icon="BedDouble" class="h-4 w-4" /> Sin camas configuradas — se hereda la capacidad del tipo.
                                    </div>
                                </div>
                                <FormHelp v-if="errors.beds" class="text-danger">{{ errors.beds }}</FormHelp>
                            </div>

                            <!-- Superficie / vista (la ocupación vive junto al recargo por persona extra, más abajo — son el mismo concepto) -->
                            <div class="grid grid-cols-12 gap-x-5 gap-y-4">
                                <div class="col-span-12 sm:col-span-6">
                                    <FormLabel htmlFor="room-size">Superficie (m²)</FormLabel>
                                    <div class="relative">
                                        <Lucide icon="Ruler" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                        <FormInput id="room-size" v-model="form.size_m2" type="number" step="0.1" min="0" class="pl-9" placeholder="24" />
                                    </div>
                                    <FormHelp v-if="errors.size_m2" class="text-danger">{{ errors.size_m2 }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <FormLabel htmlFor="room-view">Vista</FormLabel>
                                    <div class="relative">
                                        <Lucide icon="Eye" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                        <FormInput id="room-view" v-model="form.view" type="text" list="room-view-options" class="pl-9" placeholder="mar" />
                                    </div>
                                    <datalist id="room-view-options">
                                        <option value="mar" />
                                        <option value="jardín" />
                                        <option value="ciudad" />
                                        <option value="interior" />
                                    </datalist>
                                    <FormHelp v-if="errors.view" class="text-danger">{{ errors.view }}</FormHelp>
                                </div>
                            </div>

                            <!-- Amenidades -->
                            <div>
                                <FormLabel htmlFor="room-amenities">Amenidades</FormLabel>
                                <div class="relative">
                                    <Lucide icon="Sparkles" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                    <FormInput
                                        id="room-amenities"
                                        v-model="amenityInput"
                                        type="text"
                                        maxlength="100"
                                        class="pl-9"
                                        placeholder="Escribe y presiona Enter (TV, minibar, jacuzzi…)"
                                        @keydown.enter.prevent="addAmenity"
                                    />
                                </div>
                                <div v-if="form.amenities.length" class="mt-2.5 flex flex-wrap gap-1.5">
                                    <span
                                        v-for="(amenity, index) in form.amenities"
                                        :key="amenity"
                                        class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-1 text-xs font-medium text-primary"
                                    >
                                        {{ amenity }}
                                        <button type="button" class="text-primary/50 hover:text-danger" title="Quitar" @click="removeAmenity(index)">
                                            <Lucide icon="X" class="h-3 w-3" />
                                        </button>
                                    </span>
                                </div>
                                <!-- Sugerencias comunes: un clic agrega (extras propios de ESTA habitación) -->
                                <div class="mt-2.5 flex flex-wrap gap-1.5">
                                    <button
                                        v-for="suggestion in AMENITY_SUGGESTIONS.filter((a) => !form.amenities.includes(a))"
                                        :key="suggestion"
                                        type="button"
                                        class="rounded-full border border-dashed border-slate-300 px-2 py-0.5 text-xs text-slate-500 transition hover:border-primary hover:text-primary dark:border-darkmode-400"
                                        @click="form.amenities.push(suggestion)"
                                    >
                                        + {{ suggestion }}
                                    </button>
                                </div>
                                <FormHelp v-if="errors.amenities" class="text-danger">{{ errors.amenities }}</FormHelp>
                            </div>

                            <!-- Características (switches en tarjetas) -->
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400">
                                    <span class="flex items-center gap-2 text-sm">
                                        <Lucide icon="Cigarette" class="h-4 w-4 text-slate-400" /> Se permite fumar
                                    </span>
                                    <FormSwitch>
                                        <FormSwitch.Input id="room-smoking" v-model="form.smoking" type="checkbox" />
                                    </FormSwitch>
                                </div>
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400">
                                    <span class="flex items-center gap-2 text-sm">
                                        <Lucide icon="Accessibility" class="h-4 w-4 text-slate-400" /> Accesible / planta baja
                                    </span>
                                    <FormSwitch>
                                        <FormSwitch.Input id="room-accessible" v-model="form.accessible" type="checkbox" />
                                    </FormSwitch>
                                </div>
                            </div>
                        </div>

                        <!-- Ajuste de precio: concepto DISTINTO a la ocupación (vista, piso, ubicación) -->
                        <div class="space-y-4 border-t border-slate-200/60 pt-6 dark:border-darkmode-400">
                            <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="DollarSign" class="h-3.5 w-3.5" /> Ajuste de precio de esta habitación
                            </div>
                            <div class="max-w-sm">
                                <FormLabel htmlFor="room-price-modifier">Ajuste sobre la tarifa del tipo ($)</FormLabel>
                                <div class="relative">
                                    <Lucide icon="DollarSign" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                    <FormInput id="room-price-modifier" v-model="form.price_modifier" type="number" step="0.01" placeholder="0" class="pl-9" />
                                </div>
                                <FormHelp>
                                    NO es por número de personas — es por características de ESTE cuarto: +100 vista al mar, −50
                                    interior. Se suma a la tarifa por cada noche/periodo que se cobre.
                                </FormHelp>
                                <FormHelp v-if="errors.price_modifier" class="text-danger">{{ errors.price_modifier }}</FormHelp>
                            </div>
                        </div>

                        <!-- Ocupación: techo real, cuántas trae la tarifa gratis, y cuánto cuesta cada persona
                             de más — las 3 caras del mismo concepto, antes dispersas en 2 secciones distintas. -->
                        <div class="space-y-4 border-t border-slate-200/60 pt-6 dark:border-darkmode-400">
                            <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="Users" class="h-3.5 w-3.5" /> Ocupación de esta habitación
                            </div>
                            <p class="text-xs text-slate-500">
                                El wizard y las reservas nunca aceptan más personas que la "ocupación máxima" de aquí abajo — si la
                                dejas vacía, el techo es la capacidad del tipo. Entre las incluidas y el máximo, cada persona de más
                                paga el cobro extra.
                            </p>
                            <div class="grid grid-cols-12 gap-x-5 gap-y-4">
                                <div class="col-span-12 sm:col-span-4">
                                    <FormLabel htmlFor="room-max-occupancy">Ocupación máxima</FormLabel>
                                    <div class="relative">
                                        <Lucide icon="UsersRound" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                        <FormInput id="room-max-occupancy" v-model="form.max_occupancy" type="number" min="1" class="pl-9" placeholder="hereda del tipo" />
                                    </div>
                                    <FormHelp>
                                        El techo real de este cuarto. Vacío = hereda la capacidad del tipo ({{ selectedFormType?.capacity ?? '—' }}).
                                    </FormHelp>
                                    <FormHelp v-if="errors.max_occupancy" class="text-danger">{{ errors.max_occupancy }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-4">
                                    <FormLabel htmlFor="room-included-occupancy">Personas incluidas en la tarifa</FormLabel>
                                    <div class="relative">
                                        <Lucide icon="Users" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                        <FormInput id="room-included-occupancy" v-model="form.included_occupancy" type="number" min="1" class="pl-9" placeholder="2" />
                                    </div>
                                    <FormHelp>
                                        Vacío = sin cobro por persona extra, sin importar cuánta gente se reserve.
                                    </FormHelp>
                                    <FormHelp v-if="errors.included_occupancy" class="text-danger">{{ errors.included_occupancy }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-4">
                                    <FormLabel htmlFor="room-extra-guest-fee">Cobro por cada persona extra ($)</FormLabel>
                                    <div class="relative">
                                        <Lucide icon="UserPlus" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                        <FormInput id="room-extra-guest-fee" v-model="form.extra_guest_fee" type="number" step="0.01" min="0" class="pl-9" placeholder="150" />
                                    </div>
                                    <FormHelp>
                                        Por persona que exceda las incluidas, por noche o periodo de la tarifa.
                                    </FormHelp>
                                    <FormHelp v-if="errors.extra_guest_fee" class="text-danger">{{ errors.extra_guest_fee }}</FormHelp>
                                </div>
                            </div>
                            <div
                                v-if="occupancyPreview"
                                class="flex items-start gap-2 rounded-md px-3 py-2.5 text-xs"
                                :class="occupancyPreview.unreachable ? 'border border-warning/30 bg-warning/5 text-slate-600 dark:text-slate-300' : 'bg-slate-50 text-slate-600 dark:bg-darkmode-400 dark:text-slate-300'"
                            >
                                <Lucide
                                    :icon="occupancyPreview.unreachable ? 'TriangleAlert' : 'Info'"
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                    :class="occupancyPreview.unreachable ? 'text-warning' : 'text-slate-400'"
                                />
                                {{ occupancyPreview.message }}
                            </div>

                            <!-- Cargos opcionales (dinámicos) -->
                            <div>
                                <div class="mb-2 flex items-center justify-between">
                                    <FormLabel class="mb-0">Cargos opcionales</FormLabel>
                                    <Button type="button" variant="outline-secondary" size="sm" class="rounded-[0.5rem]" @click="addOptionalCharge">
                                        <Lucide icon="Plus" class="mr-1 h-4 w-4" /> Agregar cargo
                                    </Button>
                                </div>
                                <div class="rounded-lg border border-dashed border-slate-300/70 p-3 dark:border-darkmode-400">
                                    <div v-if="form.optional_charges.length" class="space-y-2.5">
                                        <div v-for="(charge, index) in form.optional_charges" :key="index" class="flex items-center gap-2.5">
                                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400 dark:bg-darkmode-400">
                                                <Lucide icon="Receipt" class="h-4 w-4" />
                                            </div>
                                            <FormInput v-model="charge.concept" type="text" maxlength="100" class="flex-1" placeholder="Mascota, decoración, cama extra…" />
                                            <div class="relative w-32">
                                                <Lucide icon="DollarSign" class="absolute inset-y-0 left-0 z-10 my-auto ml-2.5 h-3.5 w-3.5 stroke-[1.3] text-slate-400" />
                                                <FormInput v-model="charge.amount" type="number" step="0.01" min="0" class="pl-8" placeholder="200" />
                                            </div>
                                            <button
                                                type="button"
                                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                                title="Quitar cargo"
                                                @click="removeOptionalCharge(index)"
                                            >
                                                <Lucide icon="Trash2" class="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>
                                    <div v-else class="flex items-center justify-center gap-2 py-2 text-xs text-slate-400">
                                        <Lucide icon="Receipt" class="h-4 w-4" /> Sin cargos opcionales — agrégalos y el personal podrá aplicarlos al reservar o en walk-in.
                                    </div>
                                </div>
                                <FormHelp v-if="errors.optional_charges" class="text-danger">{{ errors.optional_charges }}</FormHelp>
                            </div>
                        </div>

                        <!-- Notas -->
                        <div class="space-y-4 border-t border-slate-200/60 pt-6 dark:border-darkmode-400">
                            <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="StickyNote" class="h-3.5 w-3.5" /> Notas
                            </div>
                            <div class="grid grid-cols-12 gap-4">
                                <div class="col-span-12 sm:col-span-6">
                                    <FormLabel htmlFor="room-notes">Notas internas</FormLabel>
                                    <FormTextarea id="room-notes" v-model="form.notes" placeholder="Aire nuevo, TV pendiente…" />
                                    <FormHelp v-if="errors.notes" class="text-danger">{{ errors.notes }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <FormLabel htmlFor="room-maintenance-notes">Notas de mantenimiento</FormLabel>
                                    <FormTextarea
                                        id="room-maintenance-notes"
                                        v-model="form.maintenance_notes"
                                        placeholder="Por qué está fuera de servicio…"
                                    />
                                    <FormHelp>Notas de mantenimiento — por qué está fuera de servicio.</FormHelp>
                                    <FormHelp v-if="errors.maintenance_notes" class="text-danger">{{ errors.maintenance_notes }}</FormHelp>
                                </div>
                            </div>
                        </div>

                        <p v-if="generalError" class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ generalError }}</p>
                    </div>

                    <!-- Footer -->
                    <div class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <Button type="button" variant="outline-secondary" @click="showForm = false">Cancelar</Button>
                        <Button type="submit" variant="primary" class="shadow-md shadow-primary/20" :disabled="saving">
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{ saving ? 'Guardando…' : editing ? 'Guardar cambios' : 'Crear habitación' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal: alta masiva por rango -->
        <Dialog :open="showBulk" size="lg" @close="showBulk = false">
            <Dialog.Panel>
                <form class="flex max-h-[85vh] flex-col" @submit.prevent="submitBulk">
                    <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Lucide icon="Layers" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">Alta masiva por rango</h2>
                            <p class="mt-0.5 text-xs text-slate-500">Varias habitaciones del mismo tipo y zona de un jalón.</p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showBulk = false"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                        <div class="grid grid-cols-12 gap-4">
                            <div class="col-span-12 sm:col-span-6">
                                <FormLabel htmlFor="bulk-type">Tipo *</FormLabel>
                                <FormSelect id="bulk-type" v-model="bulkForm.room_type_id">
                                    <option v-for="type in roomTypes" :key="type.id" :value="type.id">
                                        {{ type.name }}{{ type.price_from !== null ? ` — desde $${type.price_from}` : ' — sin tarifa' }}
                                    </option>
                                </FormSelect>
                                <FormHelp v-if="errors.room_type_id" class="text-danger">{{ errors.room_type_id }}</FormHelp>
                            </div>
                            <div class="col-span-12 sm:col-span-6">
                                <FormLabel htmlFor="bulk-zone">Zona (opcional)</FormLabel>
                                <FormSelect id="bulk-zone" v-model="bulkForm.zone_id">
                                    <option value="">Sin zona</option>
                                    <option v-for="zone in zones" :key="zone.id" :value="zone.id">{{ zone.name }}</option>
                                </FormSelect>
                                <FormHelp v-if="errors.zone_id" class="text-danger">{{ errors.zone_id }}</FormHelp>
                            </div>
                            <div class="col-span-6 sm:col-span-3">
                                <FormLabel htmlFor="bulk-from">Del número *</FormLabel>
                                <FormInput id="bulk-from" v-model="bulkForm.number_from" type="number" min="1" placeholder="101" />
                                <FormHelp v-if="errors.number_from" class="text-danger">{{ errors.number_from }}</FormHelp>
                            </div>
                            <div class="col-span-6 sm:col-span-3">
                                <FormLabel htmlFor="bulk-to">Al número *</FormLabel>
                                <FormInput id="bulk-to" v-model="bulkForm.number_to" type="number" min="1" placeholder="110" />
                                <FormHelp v-if="errors.number_to" class="text-danger">{{ errors.number_to }}</FormHelp>
                            </div>
                        </div>

                        <!-- Preview con colisiones marcadas -->
                        <div v-if="bulkPreview.length" class="rounded-lg border border-slate-200/70 p-3.5 dark:border-darkmode-400">
                            <div class="mb-2.5 text-xs text-slate-500">
                                Se crearán <span class="font-medium text-slate-700 dark:text-slate-200">{{ bulkNew }}</span> habitación(es);
                                <template v-if="bulkPreview.length - bulkNew">
                                    <span class="font-medium text-warning">{{ bulkPreview.length - bulkNew }}</span> ya existen y se omiten.
                                </template>
                                <template v-else>ningún número está ocupado.</template>
                            </div>
                            <div class="flex max-h-36 flex-wrap gap-1.5 overflow-y-auto">
                                <span
                                    v-for="item in bulkPreview"
                                    :key="item.number"
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="item.exists ? 'bg-slate-100 text-slate-400 line-through dark:bg-darkmode-400' : 'bg-success/10 text-success'"
                                    :title="item.exists ? 'Ya existe: se omite' : 'Se creará'"
                                >
                                    {{ item.number }}
                                </span>
                            </div>
                        </div>

                        <div
                            v-if="bulkOverLimit"
                            class="flex items-center gap-2 rounded-md border border-danger/20 bg-danger/5 px-3 py-2.5 text-xs text-danger"
                        >
                            <Lucide icon="TriangleAlert" class="h-4 w-4 shrink-0" />
                            Este rango rebasa el límite de tu plan ({{ rooms.length }} de {{ maxRooms }} usadas). Ajusta el rango o revisa
                            <Link :href="route('tenant.hotel-settings')" class="font-medium underline">Tu plan</Link>.
                        </div>

                        <p v-if="generalError" class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ generalError }}</p>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <Button type="button" variant="outline-secondary" @click="showBulk = false">Cancelar</Button>
                        <Button type="submit" variant="primary" class="shadow-md shadow-primary/20" :disabled="saving || !bulkNew || bulkOverLimit">
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{ saving ? 'Creando…' : `Crear ${bulkNew || ''} habitación(es)` }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal: alta rápida (tipo + tarifa + habitación) -->
        <Dialog :open="showQuick" size="lg" @close="showQuick = false">
            <Dialog.Panel>
                <form class="flex max-h-[85vh] flex-col" @submit.prevent="submitQuick">
                    <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Lucide icon="Zap" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">Alta rápida: habitación única</h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Crea el tipo, su tarifa base y la habitación en un solo paso — para habitaciones que no se repiten.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showQuick = false"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                        <div class="grid grid-cols-12 gap-4">
                            <div class="col-span-12 sm:col-span-3">
                                <FormLabel htmlFor="quick-number">Número *</FormLabel>
                                <FormInput id="quick-number" v-model="quickForm.number" type="text" placeholder="501" />
                                <FormHelp v-if="errors.number" class="text-danger">{{ errors.number }}</FormHelp>
                            </div>
                            <div class="col-span-12 sm:col-span-9">
                                <FormLabel htmlFor="quick-name">Nombre *</FormLabel>
                                <FormInput id="quick-name" v-model="quickForm.name" type="text" placeholder="Habitación Master Junior VIP" />
                                <FormHelp v-if="errors.name" class="text-danger">{{ errors.name }}</FormHelp>
                                <FormHelp v-else>Se usa como nombre del tipo y de la habitación.</FormHelp>
                            </div>
                            <div class="col-span-6 sm:col-span-3">
                                <FormLabel htmlFor="quick-capacity">Capacidad (pax)</FormLabel>
                                <FormInput id="quick-capacity" v-model.number="quickForm.capacity" type="number" min="1" max="20" />
                                <FormHelp v-if="errors.capacity" class="text-danger">{{ errors.capacity }}</FormHelp>
                            </div>
                            <div class="col-span-6 sm:col-span-9">
                                <FormLabel htmlFor="quick-zone">Zona (opcional)</FormLabel>
                                <FormSelect id="quick-zone" v-model="quickForm.zone_id">
                                    <option value="">Sin zona</option>
                                    <option v-for="zone in zones" :key="zone.id" :value="zone.id">{{ zone.name }}</option>
                                </FormSelect>
                                <FormHelp v-if="errors.zone_id" class="text-danger">{{ errors.zone_id }}</FormHelp>
                            </div>
                        </div>

                        <div class="rounded-md border border-slate-200/70 p-3 dark:border-darkmode-400">
                            <div class="mb-3 flex items-center gap-1.5 text-sm font-medium">
                                <Lucide icon="Tag" class="h-4 w-4 text-success" /> Precio y modalidad
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <FormLabel htmlFor="quick-price">Precio ($) *</FormLabel>
                                    <FormInput id="quick-price" v-model="quickForm.price" type="number" step="0.01" min="0.01" placeholder="3500.00" />
                                    <FormHelp v-if="errors.price" class="text-danger">{{ errors.price }}</FormHelp>
                                </div>
                                <div>
                                    <FormLabel htmlFor="quick-rate-type">Cobro</FormLabel>
                                    <FormSelect id="quick-rate-type" v-model="quickForm.rate_type">
                                        <option value="night">Por noche</option>
                                        <option value="block">Por periodo (horas/días/semanas)</option>
                                    </FormSelect>
                                </div>
                            </div>
                            <div v-if="quickForm.rate_type === 'block'" class="mt-3">
                                <FormLabel>Duración del periodo</FormLabel>
                                <div class="flex gap-2">
                                    <FormInput v-model.number="quickForm.duration_value" type="number" min="1" class="w-24" />
                                    <FormSelect v-model="quickForm.duration_unit" class="flex-1">
                                        <option v-for="unit in durationUnits" :key="unit.value" :value="unit.value">{{ unit.label }}</option>
                                    </FormSelect>
                                </div>
                                <FormHelp v-if="errors.duration_value || errors.duration_unit" class="text-danger">
                                    {{ errors.duration_value ?? errors.duration_unit }}
                                </FormHelp>
                            </div>
                            <FormHelp class="mt-2">Queda como la tarifa "Tarifa base"; camas, vista y demás detalles se completan después editando la habitación.</FormHelp>
                        </div>

                        <p v-if="generalError" class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ generalError }}</p>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <Button type="button" variant="outline-secondary" @click="showQuick = false">Cancelar</Button>
                        <Button type="submit" variant="primary" class="shadow-md shadow-primary/20" :disabled="saving">
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{ saving ? 'Creando…' : 'Crear habitación' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal: eliminar -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide icon="AlertTriangle" class="mx-auto mb-3 h-12 w-12 text-danger" />
                    <h2 class="text-base font-medium">¿Eliminar la habitación {{ deleting?.number }}?</h2>
                    <p class="mt-2 text-sm text-slate-500">Se quitará del plano y del sistema.</p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button variant="outline-secondary" @click="deleting = null">Cancelar</Button>
                        <Button variant="danger" :disabled="saving" @click="submitDelete">Sí, eliminar</Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
