<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormLabel, FormSelect, FormSwitch, FormTextarea } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

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
    maintenance_notes: string | null;
}

const props = defineProps<{
    property: { id: number; name: string };
    rooms: RoomRow[];
    zones: { id: number; name: string; kind: string; color: string | null }[];
    roomTypes: { id: number; name: string }[];
    bedTypes: Record<string, string>;
    maxRooms: number | null;
    canManage: boolean;
}>();

const toast = useToasts();

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
        Object.entries(data.errors).forEach(([key, messages]) => (errors[key] = (messages as string[])[0]));
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
                <Button v-if="canManage" variant="primary" :disabled="!roomTypes.length" @click="openCreate">
                    <Lucide icon="Plus" class="mr-2 h-4 w-4" />
                    Nueva habitación
                </Button>
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
                <div class="overflow-x-auto p-5">
                    <Table v-if="rooms.length" striped>
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
                            <Table.Tr v-for="room in rooms" :key="room.id">
                                <Table.Td>
                                    <Link :href="route('tenant.rooms.show', room.id)" class="font-medium text-primary hover:underline">{{ room.number }}</Link>
                                    <div v-if="room.name" class="text-xs text-slate-500">{{ room.name }}</div>
                                </Table.Td>
                                <Table.Td>{{ room.room_type }}</Table.Td>
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
                    <div v-else class="py-8 text-center text-slate-500">
                        Sin habitaciones aún.
                        <template v-if="canManage && roomTypes.length"> Crea la primera con "Nueva habitación".</template>
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
                                            <option v-for="type in roomTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
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
                            </div>
                        </div>

                        <!-- Ficha -->
                        <div class="space-y-5 border-t border-slate-200/60 pt-6 dark:border-darkmode-400">
                            <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="ClipboardList" class="h-3.5 w-3.5" /> Ficha
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

                            <!-- Ocupación / superficie / vista -->
                            <div class="grid grid-cols-12 gap-x-5 gap-y-4">
                                <div class="col-span-12 sm:col-span-4">
                                    <FormLabel htmlFor="room-max-occupancy">Ocupación máxima</FormLabel>
                                    <div class="relative">
                                        <Lucide icon="Users" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                        <FormInput id="room-max-occupancy" v-model="form.max_occupancy" type="number" min="1" class="pl-9" placeholder="hereda del tipo" />
                                    </div>
                                    <FormHelp v-if="errors.max_occupancy" class="text-danger">{{ errors.max_occupancy }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-4">
                                    <FormLabel htmlFor="room-size">Superficie (m²)</FormLabel>
                                    <div class="relative">
                                        <Lucide icon="Ruler" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                        <FormInput id="room-size" v-model="form.size_m2" type="number" step="0.1" min="0" class="pl-9" placeholder="24" />
                                    </div>
                                    <FormHelp v-if="errors.size_m2" class="text-danger">{{ errors.size_m2 }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-4">
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

                        <!-- Precio -->
                        <div class="space-y-4 border-t border-slate-200/60 pt-6 dark:border-darkmode-400">
                            <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="DollarSign" class="h-3.5 w-3.5" /> Precio
                            </div>
                            <div>
                                <FormLabel htmlFor="room-price-modifier">Ajuste de precio ($)</FormLabel>
                                <div class="relative sm:w-56">
                                    <Lucide icon="DollarSign" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                    <FormInput id="room-price-modifier" v-model="form.price_modifier" type="number" step="0.01" placeholder="0" class="pl-9" />
                                </div>
                                <FormHelp>
                                    Ajuste por unidad sobre la tarifa del tipo: +100 vista al mar, −50 interior. Aplica a todas las tarifas.
                                </FormHelp>
                                <FormHelp v-if="errors.price_modifier" class="text-danger">{{ errors.price_modifier }}</FormHelp>
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
