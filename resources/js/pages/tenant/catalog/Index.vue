<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormCheck, FormHelp, FormInput, FormLabel, FormSelect, FormSwitch, FormTextarea } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ZoneRow {
    id: number;
    name: string;
    kind: string;
    kind_label: string;
    color: string | null;
    sort_order: number;
    rooms_count: number;
}

interface RoomTypeRow {
    id: number;
    name: string;
    description: string | null;
    capacity: number;
    max_adults: number | null;
    max_children: number | null;
    check_in_time: string | null;
    check_out_time: string | null;
    amenities: string[];
    sort_order: number;
    active: boolean;
    base_price: string;
    rooms_count: number;
}

interface RatePlanRow {
    id: number;
    room_type_id: number;
    room_type: string | null;
    name: string;
    type: string;
    duration_unit: string | null;
    duration_value: number | null;
    duration_label: string;
    price: string;
    min_advance_unit: string | null;
    min_advance_value: number | null;
    min_advance_label: string | null;
    deposit_percent: string | null;
    payment_due_unit: string | null;
    payment_due_value: number | null;
    payment_due_label: string | null;
    active: boolean;
}

const props = defineProps<{
    property: { id: number; name: string };
    zones: ZoneRow[];
    roomTypes: RoomTypeRow[];
    ratePlans: RatePlanRow[];
    zoneKinds: Record<string, string>;
    canManage: boolean;
}>();

const toast = useToasts();

const durationUnits = [
    { value: 'minute', label: 'Minutos' },
    { value: 'hour', label: 'Horas' },
    { value: 'day', label: 'Días' },
    { value: 'week', label: 'Semanas' },
    { value: 'month', label: 'Meses' },
];

const advanceUnits = [
    { value: 'hour', label: 'Horas' },
    { value: 'day', label: 'Días' },
    { value: 'week', label: 'Semanas' },
];

const saving = ref(false);
const showGuide = ref(true);
const generalError = ref<string | null>(null);
const errors = reactive<Record<string, string>>({});

function clearErrors() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    generalError.value = null;
}

function handleError(error: any) {
    clearErrors();
    const data = error.response?.data;
    if (data?.errors) {
        Object.entries(data.errors).forEach(([key, messages]) => (errors[key] = (messages as string[])[0]));
        toast.error('Revisa el formulario', Object.values(errors)[0]);
    } else {
        generalError.value = data?.message ?? 'Ocurrió un error inesperado.';
        toast.error('Error', generalError.value ?? undefined);
    }
}

async function mutate(fn: () => Promise<unknown>, successMessage: string, onDone?: () => void) {
    saving.value = true;
    clearErrors();
    try {
        await fn();
        onDone?.();
        toast.success(successMessage);
        router.reload({ only: ['zones', 'roomTypes', 'ratePlans'] });
    } catch (error) {
        handleError(error);
    } finally {
        saving.value = false;
    }
}

// Zonas
const showZoneForm = ref(false);
const editingZone = ref<ZoneRow | null>(null);
const zoneForm = reactive({
    name: '',
    kind: 'piso',
    color: null as string | null,
    sort_order: 0 as number | string,
});

function openZone(zone: ZoneRow | null) {
    editingZone.value = zone;
    zoneForm.name = zone?.name ?? '';
    zoneForm.kind = zone?.kind ?? 'piso';
    zoneForm.color = zone?.color ?? null;
    zoneForm.sort_order = zone?.sort_order ?? props.zones.length;
    clearErrors();
    showZoneForm.value = true;
}

function setZoneColor(event: Event) {
    zoneForm.color = (event.target as HTMLInputElement).value;
}

function submitZone() {
    const payload = {
        name: zoneForm.name,
        kind: zoneForm.kind,
        color: zoneForm.color,
        sort_order: zoneForm.sort_order === '' ? 0 : Number(zoneForm.sort_order),
    };
    mutate(
        () =>
            editingZone.value
                ? axios.patch(`/api/zones/${editingZone.value.id}`, payload)
                : axios.post('/api/zones', { ...payload, property_id: props.property.id }),
        editingZone.value ? 'Zona actualizada' : 'Zona creada',
        () => (showZoneForm.value = false),
    );
}

function deleteZone(zone: ZoneRow) {
    mutate(() => axios.delete(`/api/zones/${zone.id}`), 'Zona eliminada');
}

// Tipos de habitación
const showTypeForm = ref(false);
const editingType = ref<RoomTypeRow | null>(null);
const typeForm = reactive({
    name: '',
    description: '',
    capacity: 2,
    max_adults: '' as string | number,
    max_children: '' as string | number,
    check_in_time: '',
    check_out_time: '',
    amenities: [] as string[],
    base_price: '',
    sort_order: 0 as number | string,
    active: true,
});
const typeAmenityInput = ref('');

function openType(type: RoomTypeRow | null) {
    editingType.value = type;
    typeForm.name = type?.name ?? '';
    typeForm.description = type?.description ?? '';
    typeForm.capacity = type?.capacity ?? 2;
    typeForm.max_adults = type?.max_adults ?? '';
    typeForm.max_children = type?.max_children ?? '';
    typeForm.check_in_time = type?.check_in_time ?? '';
    typeForm.check_out_time = type?.check_out_time ?? '';
    typeForm.amenities = [...(type?.amenities ?? [])];
    typeForm.base_price = type?.base_price ?? '';
    typeForm.sort_order = type?.sort_order ?? props.roomTypes.length;
    typeForm.active = type?.active ?? true;
    typeAmenityInput.value = '';
    clearErrors();
    showTypeForm.value = true;
}

function addTypeAmenity() {
    const value = typeAmenityInput.value.trim();
    if (value && !typeForm.amenities.includes(value)) {
        typeForm.amenities.push(value);
    }
    typeAmenityInput.value = '';
}

function removeTypeAmenity(index: number) {
    typeForm.amenities.splice(index, 1);
}

function submitType() {
    const payload = {
        name: typeForm.name,
        description: typeForm.description.trim() === '' ? null : typeForm.description,
        capacity: typeForm.capacity,
        max_adults: typeForm.max_adults === '' ? null : Number(typeForm.max_adults),
        max_children: typeForm.max_children === '' ? null : Number(typeForm.max_children),
        check_in_time: typeForm.check_in_time === '' ? null : typeForm.check_in_time,
        check_out_time: typeForm.check_out_time === '' ? null : typeForm.check_out_time,
        amenities: typeForm.amenities,
        base_price: typeForm.base_price || 0,
        sort_order: typeForm.sort_order === '' ? 0 : Number(typeForm.sort_order),
        active: typeForm.active,
    };
    mutate(
        () =>
            editingType.value
                ? axios.patch(`/api/room-types/${editingType.value.id}`, payload)
                : axios.post('/api/room-types', { ...payload, property_id: props.property.id }),
        editingType.value ? 'Tipo de habitación actualizado' : 'Tipo de habitación creado',
        () => (showTypeForm.value = false),
    );
}

function deleteType(type: RoomTypeRow) {
    mutate(() => axios.delete(`/api/room-types/${type.id}`), 'Tipo de habitación eliminado');
}

function occupancyBreakdown(type: RoomTypeRow): string | null {
    const parts: string[] = [];
    if (type.max_adults !== null) parts.push(`${type.max_adults} adultos`);
    if (type.max_children !== null) parts.push(`${type.max_children} niños`);
    return parts.length ? parts.join(' · ') : null;
}

// Tarifas (rate plans): duración con unidad + antelación mínima.
const showPlanForm = ref(false);
const editingPlan = ref<RatePlanRow | null>(null);
const planForm = reactive({
    room_type_id: '' as string | number,
    name: '',
    type: 'night',
    duration_value: 1 as number | string,
    duration_unit: 'hour',
    price: '' as string | number,
    has_advance: false,
    min_advance_value: 1 as number | string,
    min_advance_unit: 'hour',
    has_prepayment: false,
    deposit_percent: 20 as number | string,
    payment_due_value: 1 as number | string,
    payment_due_unit: 'week',
    active: true,
});

// Lista agrupada por tipo de habitación (tarifas huérfanas al final, por si acaso).
const groupedPlans = computed(() => {
    const groups = props.roomTypes.map((type) => ({
        key: `type-${type.id}`,
        typeId: type.id as number | null,
        name: type.name,
        plans: props.ratePlans.filter((plan) => plan.room_type_id === type.id),
    }));
    const knownIds = new Set(props.roomTypes.map((type) => type.id));
    const orphans = props.ratePlans.filter((plan) => !knownIds.has(plan.room_type_id));
    if (orphans.length) {
        groups.push({ key: 'type-none', typeId: null, name: 'Sin tipo asignado', plans: orphans });
    }
    return groups;
});

function openPlan(plan: RatePlanRow | null) {
    editingPlan.value = plan;
    planForm.room_type_id = plan?.room_type_id ?? props.roomTypes[0]?.id ?? '';
    planForm.name = plan?.name ?? '';
    planForm.type = plan?.type ?? 'night';
    planForm.duration_value = plan?.duration_value ?? 3;
    planForm.duration_unit = plan?.duration_unit ?? 'hour';
    planForm.price = plan?.price ?? '';
    planForm.has_advance = Boolean(plan?.min_advance_value);
    planForm.min_advance_value = plan?.min_advance_value ?? 4;
    planForm.min_advance_unit = plan?.min_advance_unit ?? 'hour';
    planForm.has_prepayment = Boolean(plan?.deposit_percent);
    planForm.deposit_percent = plan?.deposit_percent ? Number(plan.deposit_percent) : 20;
    planForm.payment_due_value = plan?.payment_due_value ?? 1;
    planForm.payment_due_unit = plan?.payment_due_unit ?? 'week';
    planForm.active = plan?.active ?? true;
    clearErrors();
    showPlanForm.value = true;
}

function openPlanForType(roomTypeId: number) {
    openPlan(null);
    planForm.room_type_id = roomTypeId;
}

function submitPlan() {
    const payload: Record<string, unknown> = {
        room_type_id: planForm.room_type_id,
        name: planForm.name,
        type: planForm.type,
        duration_unit: planForm.type === 'block' ? planForm.duration_unit : null,
        duration_value: planForm.type === 'block' ? planForm.duration_value : null,
        price: planForm.price,
        min_advance_unit: planForm.has_advance ? planForm.min_advance_unit : null,
        min_advance_value: planForm.has_advance ? planForm.min_advance_value : null,
        deposit_percent: planForm.has_prepayment ? planForm.deposit_percent : null,
        payment_due_unit: planForm.has_prepayment ? planForm.payment_due_unit : null,
        payment_due_value: planForm.has_prepayment ? planForm.payment_due_value : null,
        active: planForm.active,
    };

    mutate(
        () =>
            editingPlan.value
                ? axios.patch(`/api/rate-plans/${editingPlan.value.id}`, payload)
                : axios.post('/api/rate-plans', { ...payload, property_id: props.property.id }),
        editingPlan.value ? 'Tarifa actualizada' : 'Tarifa creada',
        () => (showPlanForm.value = false),
    );
}

function deletePlan(plan: RatePlanRow) {
    mutate(() => axios.delete(`/api/rate-plans/${plan.id}`), 'Tarifa eliminada');
}
</script>

<template>
    <RazeLayout title="Zonas, tipos y tarifas">
        <div class="mt-2">
            <h1 class="text-lg font-medium">Zonas, tipos de habitación y tarifas</h1>
            <p class="text-sm text-slate-500">{{ property.name }}</p>

            <div v-if="generalError" class="mt-4 rounded-md bg-danger/10 px-4 py-3 text-sm text-danger">
                {{ generalError }}
            </div>

            <!-- Guía: cómo funciona el catálogo -->
            <div class="mt-5 box box--stacked overflow-hidden">
                <div class="flex items-center gap-3 border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <Lucide icon="Compass" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-medium">¿Cómo funciona el catálogo?</h2>
                        <p class="text-xs text-slate-500">Config&shy;úralo en 3 pasos. Sobre esto se apoyan tus habitaciones y reservas.</p>
                    </div>
                    <button type="button" class="flex items-center gap-1 text-xs font-medium text-primary" @click="showGuide = !showGuide">
                        {{ showGuide ? 'Ocultar' : 'Mostrar' }}
                        <Lucide :icon="showGuide ? 'ChevronUp' : 'ChevronDown'" class="h-3.5 w-3.5" />
                    </button>
                </div>
                <div v-if="showGuide" class="grid gap-4 p-5 md:grid-cols-3">
                    <!-- Paso 1 -->
                    <div class="rounded-lg border border-slate-200/70 p-4 dark:border-darkmode-400">
                        <div class="flex items-center gap-2.5">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">1</div>
                            <div class="flex items-center gap-1.5 font-medium">
                                <Lucide icon="Map" class="h-4 w-4 text-primary" /> Zonas / pisos
                            </div>
                        </div>
                        <p class="mt-2.5 text-xs leading-relaxed text-slate-500">
                            <span class="font-medium text-slate-600 dark:text-slate-300">Dónde</span> están las habitaciones. Agrupas por ubicación para el plano.
                            Ej: <span class="font-medium">Planta baja</span>, <span class="font-medium">Piso 1</span>.
                        </p>
                    </div>
                    <!-- Paso 2 -->
                    <div class="rounded-lg border border-slate-200/70 p-4 dark:border-darkmode-400">
                        <div class="flex items-center gap-2.5">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-info/10 text-sm font-semibold text-info">2</div>
                            <div class="flex items-center gap-1.5 font-medium">
                                <Lucide icon="BedDouble" class="h-4 w-4 text-info" /> Tipos de habitación
                            </div>
                        </div>
                        <p class="mt-2.5 text-xs leading-relaxed text-slate-500">
                            <span class="font-medium text-slate-600 dark:text-slate-300">Qué</span> ofreces. Cada tipo agrupa habitaciones con igual capacidad y precio base.
                            Ej: <span class="font-medium">Sencilla</span>, <span class="font-medium">Suite</span>.
                        </p>
                    </div>
                    <!-- Paso 3 -->
                    <div class="rounded-lg border border-slate-200/70 p-4 dark:border-darkmode-400">
                        <div class="flex items-center gap-2.5">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-success/10 text-sm font-semibold text-success">3</div>
                            <div class="flex items-center gap-1.5 font-medium">
                                <Lucide icon="Tag" class="h-4 w-4 text-success" /> Tarifas
                            </div>
                        </div>
                        <p class="mt-2.5 text-xs leading-relaxed text-slate-500">
                            <span class="font-medium text-slate-600 dark:text-slate-300">Cómo cobras</span> cada tipo: por noche, por rato (horas), semana… con anticipo opcional.
                        </p>
                    </div>
                    <div class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-xs text-slate-500 md:col-span-3 dark:bg-darkmode-700">
                        <Lucide icon="Info" class="h-4 w-4 shrink-0 text-slate-400" />
                        <span>
                            Después, las habitaciones reales se dan de alta en
                            <Link :href="route('tenant.rooms')" class="font-medium text-primary hover:underline">Habitaciones</Link>,
                            eligiendo una <span class="font-medium">zona</span> y un <span class="font-medium">tipo</span>.
                        </span>
                    </div>
                </div>
            </div>

            <div class="mt-6 grid grid-cols-12 items-stretch gap-6">
                <!-- Zonas -->
                <div class="col-span-12 flex flex-col xl:col-span-5">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-200/60 p-5 dark:border-darkmode-400">
                            <div class="flex items-center gap-2.5">
                                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">1</div>
                                <div>
                                    <h2 class="flex items-center gap-1.5 text-base font-medium">
                                        Zonas / pisos
                                        <span
                                            title="Agrupan tus habitaciones por ubicación (planta baja, piso 1, área alberca…). Sirven para el plano y para ubicar cada habitación rápido. No tienen precio."
                                            class="inline-flex cursor-help"
                                        >
                                            <Lucide icon="Info" class="h-3.5 w-3.5 text-slate-400" />
                                        </span>
                                    </h2>
                                    <p class="text-xs text-slate-500">Dónde están las habitaciones</p>
                                </div>
                            </div>
                            <Button v-if="canManage" variant="outline-primary" size="sm" class="rounded-[0.5rem]" @click="openZone(null)">
                                <Lucide icon="Plus" class="mr-1 h-4 w-4" /> Agregar
                            </Button>
                        </div>
                        <div class="p-5">
                            <Table v-if="zones.length">
                                <Table.Tbody>
                                    <Table.Tr v-for="zone in zones" :key="zone.id">
                                        <Table.Td class="font-medium">
                                            <span class="inline-flex items-center gap-1.5">
                                                <span
                                                    v-if="zone.color"
                                                    class="h-2.5 w-2.5 shrink-0 rounded-full"
                                                    :style="{ backgroundColor: zone.color }"
                                                />
                                                {{ zone.name }}
                                            </span>
                                            <span
                                                class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-darkmode-400"
                                            >
                                                {{ zone.kind_label }}
                                            </span>
                                        </Table.Td>
                                        <Table.Td class="text-slate-500">{{ zone.rooms_count }} hab.</Table.Td>
                                        <Table.Td v-if="canManage">
                                            <div class="flex justify-end gap-3">
                                                <a href="#" class="text-primary" @click.prevent="openZone(zone)">
                                                    <Lucide icon="Pencil" class="h-4 w-4" />
                                                </a>
                                                <a href="#" class="text-danger" @click.prevent="deleteZone(zone)">
                                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                                </a>
                                            </div>
                                        </Table.Td>
                                    </Table.Tr>
                                </Table.Tbody>
                            </Table>
                            <div v-else class="flex flex-col items-center gap-3 py-8 text-center">
                                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                                    <Lucide icon="Map" class="h-6 w-6" />
                                </div>
                                <div>
                                    <p class="text-sm font-medium">Aún no hay zonas</p>
                                    <p class="mt-0.5 text-xs text-slate-500">Ej: "Planta baja", "Piso 1", "Área alberca".</p>
                                </div>
                                <Button v-if="canManage" variant="outline-primary" size="sm" class="rounded-[0.5rem]" @click="openZone(null)">
                                    <Lucide icon="Plus" class="mr-1 h-4 w-4" /> Crear primera zona
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tipos -->
                <div class="col-span-12 flex flex-col xl:col-span-7">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-200/60 p-5 dark:border-darkmode-400">
                            <div class="flex items-center gap-2.5">
                                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-info/10 text-xs font-semibold text-info">2</div>
                                <div>
                                    <h2 class="flex items-center gap-1.5 text-base font-medium">
                                        Tipos de habitación
                                        <span
                                            title="Cada tipo es una categoría con la misma capacidad y precio base (ej. Sencilla, Suite). Las habitaciones reales se asignan a un tipo. El precio base es la referencia; el cobro real lo definen las tarifas (paso 3)."
                                            class="inline-flex cursor-help"
                                        >
                                            <Lucide icon="Info" class="h-3.5 w-3.5 text-slate-400" />
                                        </span>
                                    </h2>
                                    <p class="text-xs text-slate-500">Categorías con capacidad y precio base</p>
                                </div>
                            </div>
                            <Button v-if="canManage" variant="outline-primary" size="sm" class="rounded-[0.5rem]" @click="openType(null)">
                                <Lucide icon="Plus" class="mr-1 h-4 w-4" /> Agregar
                            </Button>
                        </div>
                        <div class="overflow-x-auto p-5">
                            <Table v-if="roomTypes.length">
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th>Nombre</Table.Th>
                                        <Table.Th>Capacidad</Table.Th>
                                        <Table.Th>Check-in / out</Table.Th>
                                        <Table.Th>Precio base</Table.Th>
                                        <Table.Th>Habs.</Table.Th>
                                        <Table.Th v-if="canManage" class="text-right" />
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    <Table.Tr v-for="type in roomTypes" :key="type.id" :class="!type.active && 'opacity-60'">
                                        <Table.Td class="font-medium">
                                            {{ type.name }}
                                            <span
                                                v-if="!type.active"
                                                class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-darkmode-400"
                                            >
                                                Inactivo
                                            </span>
                                        </Table.Td>
                                        <Table.Td>
                                            {{ type.capacity }} pers
                                            <span v-if="occupancyBreakdown(type)" class="block text-xs text-slate-500">
                                                ({{ occupancyBreakdown(type) }})
                                            </span>
                                        </Table.Td>
                                        <Table.Td class="whitespace-nowrap text-slate-500">
                                            <template v-if="type.check_in_time || type.check_out_time">
                                                {{ type.check_in_time ?? '—' }} / {{ type.check_out_time ?? '—' }}
                                            </template>
                                            <span v-else>—</span>
                                        </Table.Td>
                                        <Table.Td>${{ type.base_price }}</Table.Td>
                                        <Table.Td class="text-slate-500">{{ type.rooms_count }}</Table.Td>
                                        <Table.Td v-if="canManage">
                                            <div class="flex justify-end gap-3">
                                                <a href="#" class="text-primary" @click.prevent="openType(type)">
                                                    <Lucide icon="Pencil" class="h-4 w-4" />
                                                </a>
                                                <a href="#" class="text-danger" @click.prevent="deleteType(type)">
                                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                                </a>
                                            </div>
                                        </Table.Td>
                                    </Table.Tr>
                                </Table.Tbody>
                            </Table>
                            <div v-else class="flex flex-col items-center gap-3 py-8 text-center">
                                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-info/10 text-info">
                                    <Lucide icon="BedDouble" class="h-6 w-6" />
                                </div>
                                <div>
                                    <p class="text-sm font-medium">Aún no hay tipos de habitación</p>
                                    <p class="mt-0.5 text-xs text-slate-500">Ej: "Sencilla", "Doble", "Suite", "Jacuzzi".</p>
                                </div>
                                <Button v-if="canManage" variant="outline-primary" size="sm" class="rounded-[0.5rem]" @click="openType(null)">
                                    <Lucide icon="Plus" class="mr-1 h-4 w-4" /> Crear primer tipo
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tarifas -->
                <div class="col-span-12">
                    <div class="box box--stacked">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-200/60 p-5 dark:border-darkmode-400">
                            <div class="flex items-center gap-2.5">
                                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-success/10 text-xs font-semibold text-success">3</div>
                                <div>
                                    <h2 class="flex items-center gap-1.5 text-base font-medium">
                                        Tarifas
                                        <span
                                            title="Definen cómo cobras cada tipo de habitación: por noche o por periodo (horas, días, semanas, meses). Puedes pedir antelación mínima y un anticipo. Un mismo tipo puede tener varias tarifas (ej. Rato 3h, Noche, Semana)."
                                            class="inline-flex cursor-help"
                                        >
                                            <Lucide icon="Info" class="h-3.5 w-3.5 text-slate-400" />
                                        </span>
                                    </h2>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        Cómo cobras cada tipo: por noche o por periodo, con anticipo opcional
                                    </p>
                                </div>
                            </div>
                            <Button v-if="canManage" variant="outline-primary" size="sm" class="rounded-[0.5rem]" :disabled="!roomTypes.length" @click="openPlan(null)">
                                <Lucide icon="Plus" class="mr-1 h-4 w-4" /> Agregar
                            </Button>
                        </div>
                        <div v-if="canManage && !roomTypes.length" class="flex items-center gap-2 border-b border-slate-200/60 bg-warning/5 px-5 py-3 text-xs text-slate-600 dark:border-darkmode-400 dark:text-slate-300">
                            <Lucide icon="TriangleAlert" class="h-4 w-4 shrink-0 text-warning" />
                            Primero crea un <span class="font-medium">tipo de habitación</span> (paso 2). Las tarifas se agregan sobre un tipo.
                        </div>
                        <div class="overflow-x-auto p-5">
                            <Table v-if="groupedPlans.length">
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th>Tarifa</Table.Th>
                                        <Table.Th>Duración</Table.Th>
                                        <Table.Th>Antelación mínima</Table.Th>
                                        <Table.Th>Cobro anticipado</Table.Th>
                                        <Table.Th>Precio</Table.Th>
                                        <Table.Th>Estado</Table.Th>
                                        <Table.Th v-if="canManage" class="text-right" />
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    <template v-for="group in groupedPlans" :key="group.key">
                                        <Table.Tr>
                                            <Table.Td
                                                :colspan="canManage ? 7 : 6"
                                                class="bg-slate-50 py-2 dark:bg-darkmode-600/60"
                                            >
                                                <div class="flex items-center justify-between">
                                                    <span class="text-sm font-medium">{{ group.name }}</span>
                                                    <Button
                                                        v-if="canManage && group.typeId !== null"
                                                        variant="outline-primary"
                                                        size="sm"
                                                        @click="openPlanForType(group.typeId)"
                                                    >
                                                        <Lucide icon="Plus" class="mr-1 h-3 w-3" /> Tarifa
                                                    </Button>
                                                </div>
                                            </Table.Td>
                                        </Table.Tr>
                                        <Table.Tr v-for="plan in group.plans" :key="plan.id">
                                            <Table.Td class="font-medium">{{ plan.name }}</Table.Td>
                                            <Table.Td>
                                                <span class="rounded-full px-2 py-0.5 text-xs" :class="plan.type === 'night' ? 'bg-primary/10 text-primary' : 'bg-pending/10 text-pending'">
                                                    {{ plan.duration_label }}
                                                </span>
                                            </Table.Td>
                                            <Table.Td class="text-slate-500">{{ plan.min_advance_label ?? 'Sin restricción' }}</Table.Td>
                                            <Table.Td class="text-slate-500">
                                                <template v-if="plan.deposit_percent">
                                                    {{ Number(plan.deposit_percent) }}% de anticipo
                                                    <span v-if="plan.payment_due_label" class="block text-xs">liquidar {{ plan.payment_due_label }}</span>
                                                </template>
                                                <span v-else>No requiere</span>
                                            </Table.Td>
                                            <Table.Td>${{ plan.price }}</Table.Td>
                                            <Table.Td>
                                                <span class="rounded-full px-2 py-0.5 text-xs" :class="plan.active ? 'bg-success/10 text-success' : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'">
                                                    {{ plan.active ? 'Activa' : 'Inactiva' }}
                                                </span>
                                            </Table.Td>
                                            <Table.Td v-if="canManage">
                                                <div class="flex justify-end gap-3">
                                                    <a href="#" class="text-primary" @click.prevent="openPlan(plan)">
                                                        <Lucide icon="Pencil" class="h-4 w-4" />
                                                    </a>
                                                    <a href="#" class="text-danger" @click.prevent="deletePlan(plan)">
                                                        <Lucide icon="Trash2" class="h-4 w-4" />
                                                    </a>
                                                </div>
                                            </Table.Td>
                                        </Table.Tr>
                                        <Table.Tr v-if="!group.plans.length">
                                            <Table.Td :colspan="canManage ? 7 : 6" class="text-sm text-slate-500">
                                                Sin tarifas para este tipo.
                                            </Table.Td>
                                        </Table.Tr>
                                    </template>
                                </Table.Tbody>
                            </Table>
                            <div v-else class="flex flex-col items-center gap-3 py-8 text-center">
                                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-success/10 text-success">
                                    <Lucide icon="Tag" class="h-6 w-6" />
                                </div>
                                <div>
                                    <p class="text-sm font-medium">Aún no hay tarifas</p>
                                    <p class="mt-0.5 text-xs text-slate-500">Ej: "Noche sencilla $650", "Rato 3 horas $250", "Semana $3,800".</p>
                                </div>
                                <Button v-if="canManage && roomTypes.length" variant="outline-primary" size="sm" class="rounded-[0.5rem]" @click="openPlan(null)">
                                    <Lucide icon="Plus" class="mr-1 h-4 w-4" /> Crear primera tarifa
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal tarifa -->
        <Dialog :open="showPlanForm" @close="showPlanForm = false">
            <Dialog.Panel>
                <div class="p-5">
                    <h2 class="mb-4 text-base font-medium">{{ editingPlan ? 'Editar tarifa' : 'Nueva tarifa' }}</h2>
                    <form class="space-y-4" @submit.prevent="submitPlan">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <FormLabel htmlFor="plan-roomtype">Tipo de habitación</FormLabel>
                                <FormSelect id="plan-roomtype" v-model="planForm.room_type_id">
                                    <option v-for="type in roomTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
                                </FormSelect>
                                <FormHelp v-if="errors.room_type_id" class="text-danger">{{ errors.room_type_id }}</FormHelp>
                            </div>
                            <div>
                                <FormLabel htmlFor="plan-name">Nombre</FormLabel>
                                <FormInput id="plan-name" v-model="planForm.name" type="text" placeholder="Rato 3 horas" />
                                <FormHelp v-if="errors.name" class="text-danger">{{ errors.name }}</FormHelp>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <FormLabel htmlFor="plan-type">Cobro</FormLabel>
                                <FormSelect id="plan-type" v-model="planForm.type">
                                    <option value="night">Por noche</option>
                                    <option value="block">Por periodo (horas/días/semanas/meses)</option>
                                </FormSelect>
                            </div>
                            <div>
                                <FormLabel htmlFor="plan-price">Precio ($)</FormLabel>
                                <FormInput id="plan-price" v-model="planForm.price" type="number" step="0.01" min="0" />
                                <FormHelp v-if="errors.price" class="text-danger">{{ errors.price }}</FormHelp>
                            </div>
                        </div>

                        <div v-if="planForm.type === 'block'">
                            <FormLabel>Duración del periodo</FormLabel>
                            <div class="flex gap-2">
                                <FormInput v-model.number="planForm.duration_value" type="number" min="1" class="w-24" />
                                <FormSelect v-model="planForm.duration_unit" class="flex-1">
                                    <option v-for="unit in durationUnits" :key="unit.value" :value="unit.value">{{ unit.label }}</option>
                                </FormSelect>
                            </div>
                            <FormHelp>Ej: 3 horas (rato), 1 día, 1 semana, 1 mes. El precio es por cada periodo completo.</FormHelp>
                            <FormHelp v-if="errors.duration_value || errors.duration_unit" class="text-danger">
                                {{ errors.duration_value ?? errors.duration_unit }}
                            </FormHelp>
                        </div>

                        <div class="rounded-md border border-slate-200/70 p-3 dark:border-darkmode-400">
                            <FormCheck>
                                <FormCheck.Input id="plan-advance" v-model="planForm.has_advance" type="checkbox" />
                                <FormCheck.Label htmlFor="plan-advance">Exigir antelación mínima para reservar</FormCheck.Label>
                            </FormCheck>
                            <div v-if="planForm.has_advance" class="mt-3">
                                <div class="flex gap-2">
                                    <FormInput v-model.number="planForm.min_advance_value" type="number" min="1" class="w-24" />
                                    <FormSelect v-model="planForm.min_advance_unit" class="flex-1">
                                        <option v-for="unit in advanceUnits" :key="unit.value" :value="unit.value">{{ unit.label }}</option>
                                    </FormSelect>
                                </div>
                                <FormHelp>Ej: 4 horas → solo se aceptan reservas hechas al menos 4 horas antes de la llegada. No aplica a walk-ins.</FormHelp>
                                <FormHelp v-if="errors.min_advance_value || errors.min_advance_unit" class="text-danger">
                                    {{ errors.min_advance_value ?? errors.min_advance_unit }}
                                </FormHelp>
                            </div>
                        </div>

                        <div class="rounded-md border border-slate-200/70 p-3 dark:border-darkmode-400">
                            <FormCheck>
                                <FormCheck.Input id="plan-prepay" v-model="planForm.has_prepayment" type="checkbox" />
                                <FormCheck.Label htmlFor="plan-prepay">Exigir cobro anticipado</FormCheck.Label>
                            </FormCheck>
                            <div v-if="planForm.has_prepayment" class="mt-3 space-y-3">
                                <div>
                                    <FormLabel htmlFor="plan-deposit">Anticipo al reservar (%)</FormLabel>
                                    <FormInput id="plan-deposit" v-model.number="planForm.deposit_percent" type="number" min="1" max="100" step="0.5" class="w-32" />
                                    <FormHelp v-if="errors.deposit_percent" class="text-danger">{{ errors.deposit_percent }}</FormHelp>
                                </div>
                                <div>
                                    <FormLabel>Liquidar el total antes de la llegada</FormLabel>
                                    <div class="flex gap-2">
                                        <FormInput v-model.number="planForm.payment_due_value" type="number" min="1" class="w-24" />
                                        <FormSelect v-model="planForm.payment_due_unit" class="flex-1">
                                            <option v-for="unit in advanceUnits" :key="unit.value" :value="unit.value">{{ unit.label }}</option>
                                        </FormSelect>
                                    </div>
                                    <FormHelp>Ej: 1 semana → el total debe estar pagado una semana antes de la llegada; si no, la reserva se marca "pago vencido".</FormHelp>
                                    <FormHelp v-if="errors.payment_due_value || errors.payment_due_unit" class="text-danger">
                                        {{ errors.payment_due_value ?? errors.payment_due_unit }}
                                    </FormHelp>
                                </div>
                            </div>
                        </div>

                        <FormCheck v-if="editingPlan">
                            <FormCheck.Input id="plan-active" v-model="planForm.active" type="checkbox" />
                            <FormCheck.Label htmlFor="plan-active">Tarifa activa</FormCheck.Label>
                        </FormCheck>

                        <div class="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline-secondary" @click="showPlanForm = false">Cancelar</Button>
                            <Button type="submit" variant="primary" :disabled="saving">Guardar</Button>
                        </div>
                    </form>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal zona -->
        <Dialog :open="showZoneForm" @close="showZoneForm = false">
            <Dialog.Panel>
                <div class="p-5">
                    <h2 class="mb-4 text-base font-medium">{{ editingZone ? 'Editar zona' : 'Nueva zona' }}</h2>
                    <form class="space-y-4" @submit.prevent="submitZone">
                        <div>
                            <FormLabel htmlFor="zone-name">Nombre</FormLabel>
                            <FormInput id="zone-name" v-model="zoneForm.name" type="text" placeholder="Planta baja" />
                            <FormHelp v-if="errors.name" class="text-danger">{{ errors.name }}</FormHelp>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <FormLabel htmlFor="zone-kind">Tipo de zona</FormLabel>
                                <FormSelect id="zone-kind" v-model="zoneForm.kind">
                                    <option v-for="(label, value) in zoneKinds" :key="value" :value="value">{{ label }}</option>
                                </FormSelect>
                                <FormHelp v-if="errors.kind" class="text-danger">{{ errors.kind }}</FormHelp>
                            </div>
                            <div>
                                <FormLabel htmlFor="zone-sort">Orden</FormLabel>
                                <FormInput id="zone-sort" v-model.number="zoneForm.sort_order" type="number" min="0" />
                                <FormHelp v-if="errors.sort_order" class="text-danger">{{ errors.sort_order }}</FormHelp>
                            </div>
                        </div>
                        <div>
                            <FormLabel htmlFor="zone-color">Color</FormLabel>
                            <div class="flex items-center gap-2">
                                <input
                                    id="zone-color"
                                    type="color"
                                    :value="zoneForm.color ?? '#64748b'"
                                    class="h-9 w-14 cursor-pointer rounded-md border border-slate-200 bg-white p-1 shadow-sm dark:border-darkmode-400 dark:bg-darkmode-800"
                                    @input="setZoneColor"
                                />
                                <Button type="button" variant="outline-secondary" size="sm" @click="zoneForm.color = null">
                                    Sin color
                                </Button>
                                <span class="text-xs text-slate-500">{{ zoneForm.color ?? 'Sin color asignado' }}</span>
                            </div>
                            <FormHelp>Se usa como puntito identificador en el plano y las listas.</FormHelp>
                            <FormHelp v-if="errors.color" class="text-danger">{{ errors.color }}</FormHelp>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline-secondary" @click="showZoneForm = false">Cancelar</Button>
                            <Button type="submit" variant="primary" :disabled="saving">Guardar</Button>
                        </div>
                    </form>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal tipo -->
        <Dialog :open="showTypeForm" size="lg" @close="showTypeForm = false">
            <Dialog.Panel>
                <div class="p-5">
                    <h2 class="mb-4 text-base font-medium">{{ editingType ? 'Editar tipo' : 'Nuevo tipo de habitación' }}</h2>
                    <form class="space-y-4" @submit.prevent="submitType">
                        <div>
                            <FormLabel htmlFor="type-name">Nombre</FormLabel>
                            <FormInput id="type-name" v-model="typeForm.name" type="text" placeholder="Suite" />
                            <FormHelp v-if="errors.name" class="text-danger">{{ errors.name }}</FormHelp>
                        </div>
                        <div>
                            <FormLabel htmlFor="type-description">Descripción</FormLabel>
                            <FormTextarea id="type-description" v-model="typeForm.description" placeholder="Suite amplia con jacuzzi y balcón…" />
                            <FormHelp>La usará el widget web y los bots para vender.</FormHelp>
                            <FormHelp v-if="errors.description" class="text-danger">{{ errors.description }}</FormHelp>
                        </div>
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                            <div>
                                <FormLabel htmlFor="type-capacity">Capacidad (pax)</FormLabel>
                                <FormInput id="type-capacity" v-model.number="typeForm.capacity" type="number" min="1" max="20" />
                                <FormHelp v-if="errors.capacity" class="text-danger">{{ errors.capacity }}</FormHelp>
                            </div>
                            <div>
                                <FormLabel htmlFor="type-max-adults">Máx. adultos</FormLabel>
                                <FormInput id="type-max-adults" v-model="typeForm.max_adults" type="number" min="0" placeholder="—" />
                                <FormHelp v-if="errors.max_adults" class="text-danger">{{ errors.max_adults }}</FormHelp>
                            </div>
                            <div>
                                <FormLabel htmlFor="type-max-children">Máx. niños</FormLabel>
                                <FormInput id="type-max-children" v-model="typeForm.max_children" type="number" min="0" placeholder="—" />
                                <FormHelp v-if="errors.max_children" class="text-danger">{{ errors.max_children }}</FormHelp>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                            <div>
                                <FormLabel htmlFor="type-price">Precio base ($)</FormLabel>
                                <FormInput id="type-price" v-model="typeForm.base_price" type="number" step="0.01" min="0" placeholder="650.00" />
                                <FormHelp v-if="errors.base_price" class="text-danger">{{ errors.base_price }}</FormHelp>
                            </div>
                            <div>
                                <FormLabel htmlFor="type-checkin">Check-in</FormLabel>
                                <FormInput id="type-checkin" v-model="typeForm.check_in_time" type="time" />
                                <FormHelp v-if="errors.check_in_time" class="text-danger">{{ errors.check_in_time }}</FormHelp>
                            </div>
                            <div>
                                <FormLabel htmlFor="type-checkout">Check-out</FormLabel>
                                <FormInput id="type-checkout" v-model="typeForm.check_out_time" type="time" />
                                <FormHelp v-if="errors.check_out_time" class="text-danger">{{ errors.check_out_time }}</FormHelp>
                            </div>
                        </div>
                        <div>
                            <FormLabel htmlFor="type-amenities">Amenidades</FormLabel>
                            <FormInput
                                id="type-amenities"
                                v-model="typeAmenityInput"
                                type="text"
                                placeholder="Escribe y presiona Enter (TV, minibar, jacuzzi…)"
                                @keydown.enter.prevent="addTypeAmenity"
                            />
                            <div v-if="typeForm.amenities.length" class="mt-2 flex flex-wrap gap-1.5">
                                <span
                                    v-for="(amenity, index) in typeForm.amenities"
                                    :key="amenity"
                                    class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs dark:bg-darkmode-400"
                                >
                                    {{ amenity }}
                                    <button
                                        type="button"
                                        class="text-slate-400 hover:text-danger"
                                        title="Quitar"
                                        @click="removeTypeAmenity(index)"
                                    >
                                        <Lucide icon="X" class="h-3 w-3" />
                                    </button>
                                </span>
                            </div>
                            <FormHelp v-if="errors.amenities" class="text-danger">{{ errors.amenities }}</FormHelp>
                        </div>
                        <div class="flex flex-wrap items-end justify-between gap-4">
                            <div>
                                <FormLabel htmlFor="type-sort">Orden</FormLabel>
                                <FormInput id="type-sort" v-model.number="typeForm.sort_order" type="number" min="0" class="w-24" />
                                <FormHelp v-if="errors.sort_order" class="text-danger">{{ errors.sort_order }}</FormHelp>
                            </div>
                            <FormSwitch class="mb-2">
                                <FormSwitch.Input id="type-active" v-model="typeForm.active" type="checkbox" />
                                <FormSwitch.Label htmlFor="type-active">Activo (a la venta)</FormSwitch.Label>
                            </FormSwitch>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline-secondary" @click="showTypeForm = false">Cancelar</Button>
                            <Button type="submit" variant="primary" :disabled="saving">Guardar</Button>
                        </div>
                    </form>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
