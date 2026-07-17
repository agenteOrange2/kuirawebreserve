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
import { AMENITY_SUGGESTIONS } from '@/lib/amenities';

interface ZoneRow {
    id: number;
    name: string;
    kind: string;
    kind_label: string;
    color: string | null;
    sort_order: number;
    rooms_count: number;
}

interface TypePhoto {
    id: number;
    url: string;
    thumb_url: string;
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
    // Precio único: derivado de la tarifa activa más barata (null = sin tarifa).
    price_from: number | null;
    has_active_rate: boolean;
    rooms_count: number;
    photos: TypePhoto[];
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
    cancel_free_unit: string | null;
    cancel_free_value: number | null;
    cancel_penalty_percent: string | null;
    cancellation_policy_label: string | null;
    active: boolean;
    seasons_count: number;
}

interface SeasonRow {
    id: number;
    rate_plan_id: number;
    name: string;
    kind: 'season' | 'promo';
    starts_on: string;
    ends_on: string;
    price: string | number;
    priority: number;
    active: boolean;
}

const props = defineProps<{
    property: { id: number; name: string };
    zones: ZoneRow[];
    roomTypes: RoomTypeRow[];
    ratePlans: RatePlanRow[];
    zoneKinds: Record<string, string>;
    totalRooms: number;
    canManage: boolean;
}>();

const money = (v: number | string) =>
    `$${Number(v).toLocaleString('es-MX', { maximumFractionDigits: 2 })}`;

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

// Tipos de habitación. El precio se captura UNA sola vez al crear (se
// guarda como la tarifa "Tarifa base"); al editar, el precio vive en las
// tarifas del tipo.
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
    price: '' as string | number,
    rate_type: 'night',
    duration_value: 3 as number | string,
    duration_unit: 'hour',
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
    typeForm.price = '';
    typeForm.rate_type = 'night';
    typeForm.duration_value = 3;
    typeForm.duration_unit = 'hour';
    typeForm.sort_order = type?.sort_order ?? props.roomTypes.length;
    typeForm.active = type?.active ?? true;
    typeAmenityInput.value = '';
    typePhotos.value = [...(type?.photos ?? [])];
    clearErrors();
    showTypeForm.value = true;
}

// ── Fotos del tipo (galería del wizard): la primera es la portada ──
const typePhotos = ref<TypePhoto[]>([]);
const photoBusy = ref(false);
const photoInput = ref<HTMLInputElement | null>(null);

async function uploadPhotos(event: Event) {
    const files = (event.target as HTMLInputElement).files;
    if (!files?.length || !editingType.value) return;
    const formData = new FormData();
    [...files].forEach((file) => formData.append('photos[]', file));
    photoBusy.value = true;
    try {
        const { data } = await axios.post<{ photos: TypePhoto[] }>(`/api/room-types/${editingType.value.id}/photos`, formData);
        typePhotos.value = data.photos;
        toast.success('Fotos subidas', 'La galería del wizard ya las muestra.');
        router.reload({ only: ['roomTypes'] });
    } catch (e: any) {
        const errs = e.response?.data?.errors as Record<string, string[]> | undefined;
        toast.error('No se pudieron subir', e.response?.data?.message ?? (errs ? Object.values(errs)[0]?.[0] : 'Revisa formato (JPG, PNG, WebP) y peso (máx. 6 MB).'));
    } finally {
        photoBusy.value = false;
        if (photoInput.value) photoInput.value.value = '';
    }
}

async function removePhoto(photo: TypePhoto) {
    if (!editingType.value) return;
    photoBusy.value = true;
    try {
        const { data } = await axios.delete<{ photos: TypePhoto[] }>(`/api/room-types/${editingType.value.id}/photos/${photo.id}`);
        typePhotos.value = data.photos;
        router.reload({ only: ['roomTypes'] });
    } catch {
        toast.error('Error', 'No se pudo quitar la foto.');
    } finally {
        photoBusy.value = false;
    }
}

// Portada = primera de la galería: reordena poniendo la elegida al frente.
async function makeCover(photo: TypePhoto) {
    if (!editingType.value) return;
    photoBusy.value = true;
    try {
        const order = [photo.id, ...typePhotos.value.filter((p) => p.id !== photo.id).map((p) => p.id)];
        const { data } = await axios.patch<{ photos: TypePhoto[] }>(`/api/room-types/${editingType.value.id}/photos/order`, { order });
        typePhotos.value = data.photos;
        toast.success('Portada actualizada', 'Esa foto encabeza la galería.');
        router.reload({ only: ['roomTypes'] });
    } catch {
        toast.error('Error', 'No se pudo cambiar la portada.');
    } finally {
        photoBusy.value = false;
    }
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
    const payload: Record<string, unknown> = {
        name: typeForm.name,
        description: typeForm.description.trim() === '' ? null : typeForm.description,
        capacity: typeForm.capacity,
        max_adults: typeForm.max_adults === '' ? null : Number(typeForm.max_adults),
        max_children: typeForm.max_children === '' ? null : Number(typeForm.max_children),
        check_in_time: typeForm.check_in_time === '' ? null : typeForm.check_in_time,
        check_out_time: typeForm.check_out_time === '' ? null : typeForm.check_out_time,
        amenities: typeForm.amenities,
        sort_order: typeForm.sort_order === '' ? 0 : Number(typeForm.sort_order),
        active: typeForm.active,
    };

    // Alta en una sola captura: el precio se guarda como la tarifa
    // "Tarifa base" del tipo (editar precio después = editar la tarifa).
    if (!editingType.value) {
        payload.price = typeForm.price;
        payload.rate_type = typeForm.rate_type;
        payload.duration_unit = typeForm.rate_type === 'block' ? typeForm.duration_unit : null;
        payload.duration_value = typeForm.rate_type === 'block' ? typeForm.duration_value : null;
    }

    mutate(
        () =>
            editingType.value
                ? axios.patch(`/api/room-types/${editingType.value.id}`, payload)
                : axios.post('/api/room-types', { ...payload, property_id: props.property.id }),
        editingType.value ? 'Tipo de habitación actualizado' : 'Tipo creado con su tarifa base',
        () => (showTypeForm.value = false),
    );
}

function deleteType(type: RoomTypeRow) {
    mutate(() => axios.delete(`/api/room-types/${type.id}`), 'Tipo de habitación eliminado');
}

function duplicateType(type: RoomTypeRow) {
    mutate(() => axios.post(`/api/room-types/${type.id}/duplicate`), 'Tipo duplicado con sus tarifas');
}

// Tipos expandibles: los que no tienen tarifa nacen abiertos (guarda visible).
const expandedTypes = ref<Set<number>>(
    new Set(props.roomTypes.filter((t) => !t.has_active_rate).map((t) => t.id)),
);

function toggleTypeRow(id: number) {
    const next = new Set(expandedTypes.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    expandedTypes.value = next;
}

const plansForType = (typeId: number) => props.ratePlans.filter((plan) => plan.room_type_id === typeId);

// Tarifas cuyo tipo ya no existe (defensivo; no debería ocurrir).
const orphanPlans = computed(() => {
    const knownIds = new Set(props.roomTypes.map((type) => type.id));
    return props.ratePlans.filter((plan) => !knownIds.has(plan.room_type_id));
});

// Progreso real del stepper (guía de 3 pasos).
const typesWithoutRate = computed(() => props.roomTypes.filter((t) => !t.has_active_rate));

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
    has_cancel_policy: false,
    cancel_free_value: 1 as number | string,
    cancel_free_unit: 'day',
    cancel_penalty_percent: 100 as number | string,
    active: true,
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
    planForm.has_cancel_policy = Boolean(plan?.cancel_free_value);
    planForm.cancel_free_value = plan?.cancel_free_value ?? 1;
    planForm.cancel_free_unit = plan?.cancel_free_unit ?? 'day';
    planForm.cancel_penalty_percent = plan?.cancel_penalty_percent ? Number(plan.cancel_penalty_percent) : 100;
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
        cancel_free_unit: planForm.has_cancel_policy ? planForm.cancel_free_unit : null,
        cancel_free_value: planForm.has_cancel_policy ? planForm.cancel_free_value : null,
        cancel_penalty_percent: planForm.has_cancel_policy ? planForm.cancel_penalty_percent : null,
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

// Temporadas y promos por tarifa (spec-motor-reservas-web E0.5): un rango
// de fechas con un precio que sustituye al de la tarifa mientras esté
// vigente. Modal aparte (no dentro del de la tarifa) para no saturarlo —
// se abre directo desde la tabla.
const showSeasonsModal = ref(false);
const seasonsPlan = ref<RatePlanRow | null>(null);
const seasons = ref<SeasonRow[]>([]);
const loadingSeasons = ref(false);
const editingSeason = ref<SeasonRow | null>(null);
const seasonForm = reactive({
    name: '',
    kind: 'season' as 'season' | 'promo',
    starts_on: '',
    ends_on: '',
    price: '' as string | number,
    priority: 0 as number | string,
});

function resetSeasonForm() {
    editingSeason.value = null;
    seasonForm.name = '';
    seasonForm.kind = 'season';
    seasonForm.starts_on = '';
    seasonForm.ends_on = '';
    seasonForm.price = '';
    seasonForm.priority = 0;
}

async function openSeasons(plan: RatePlanRow) {
    seasonsPlan.value = plan;
    resetSeasonForm();
    clearErrors();
    showSeasonsModal.value = true;
    loadingSeasons.value = true;
    try {
        const { data } = await axios.get<SeasonRow[]>(`/api/rate-plans/${plan.id}/seasons`);
        seasons.value = data;
    } catch {
        toast.error('No se pudieron cargar las temporadas');
    } finally {
        loadingSeasons.value = false;
    }
}

function editSeason(season: SeasonRow) {
    editingSeason.value = season;
    seasonForm.name = season.name;
    seasonForm.kind = season.kind;
    seasonForm.starts_on = season.starts_on;
    seasonForm.ends_on = season.ends_on;
    seasonForm.price = season.price;
    seasonForm.priority = season.priority;
    clearErrors();
}

function submitSeason() {
    if (!seasonsPlan.value) return;
    const planId = seasonsPlan.value.id;
    const payload = { ...seasonForm };

    mutate(
        () =>
            editingSeason.value
                ? axios.patch(`/api/rate-plans/${planId}/seasons/${editingSeason.value.id}`, payload)
                : axios.post(`/api/rate-plans/${planId}/seasons`, payload),
        editingSeason.value ? 'Temporada actualizada' : 'Temporada creada',
        async () => {
            resetSeasonForm();
            const { data } = await axios.get<SeasonRow[]>(`/api/rate-plans/${planId}/seasons`);
            seasons.value = data;
        },
    );
}

async function deleteSeason(season: SeasonRow) {
    if (!seasonsPlan.value) return;
    const planId = seasonsPlan.value.id;
    await mutate(() => axios.delete(`/api/rate-plans/${planId}/seasons/${season.id}`), 'Temporada eliminada');
    seasons.value = seasons.value.filter((s) => s.id !== season.id);
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
                    <!-- Paso 1: Zonas -->
                    <div class="flex flex-col rounded-lg border border-slate-200/70 p-4 dark:border-darkmode-400">
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
                        <div class="mt-auto flex items-center gap-1.5 pt-2.5 text-xs" :class="zones.length ? 'text-success' : 'text-slate-400'">
                            <Lucide :icon="zones.length ? 'CircleCheck' : 'Circle'" class="h-3.5 w-3.5" />
                            {{ zones.length ? `${zones.length} zona(s) creada(s)` : 'Pendiente: crea la primera zona' }}
                        </div>
                    </div>
                    <!-- Paso 2: Tipos y tarifas -->
                    <div class="flex flex-col rounded-lg border border-slate-200/70 p-4 dark:border-darkmode-400">
                        <div class="flex items-center gap-2.5">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-info/10 text-sm font-semibold text-info">2</div>
                            <div class="flex items-center gap-1.5 font-medium">
                                <Lucide icon="BedDouble" class="h-4 w-4 text-info" /> Tipos y tarifas
                            </div>
                        </div>
                        <p class="mt-2.5 text-xs leading-relaxed text-slate-500">
                            <span class="font-medium text-slate-600 dark:text-slate-300">Qué ofreces y cómo lo cobras.</span>
                            Al crear un tipo capturas su precio UNA vez (queda como su tarifa base); luego puedes agregar más tarifas: rato, semana, promo.
                        </p>
                        <div
                            class="mt-auto flex items-center gap-1.5 pt-2.5 text-xs"
                            :class="!roomTypes.length ? 'text-slate-400' : typesWithoutRate.length ? 'text-warning' : 'text-success'"
                        >
                            <Lucide
                                :icon="!roomTypes.length ? 'Circle' : typesWithoutRate.length ? 'TriangleAlert' : 'CircleCheck'"
                                class="h-3.5 w-3.5"
                            />
                            {{
                                !roomTypes.length
                                    ? 'Pendiente: crea el primer tipo'
                                    : typesWithoutRate.length
                                      ? `${typesWithoutRate.length} tipo(s) sin tarifa — no reservables`
                                      : `${roomTypes.length} tipo(s), todos con tarifa`
                            }}
                        </div>
                    </div>
                    <!-- Paso 3: Habitaciones -->
                    <div class="flex flex-col rounded-lg border border-slate-200/70 p-4 dark:border-darkmode-400">
                        <div class="flex items-center gap-2.5">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-success/10 text-sm font-semibold text-success">3</div>
                            <div class="flex items-center gap-1.5 font-medium">
                                <Lucide icon="DoorOpen" class="h-4 w-4 text-success" /> Habitaciones
                            </div>
                        </div>
                        <p class="mt-2.5 text-xs leading-relaxed text-slate-500">
                            Las habitaciones <span class="font-medium text-slate-600 dark:text-slate-300">reales</span>, cada una con su zona y su tipo.
                            Se dan de alta en
                            <Link :href="route('tenant.rooms')" class="font-medium text-primary hover:underline">Habitaciones</Link>.
                        </p>
                        <div class="mt-auto flex items-center gap-1.5 pt-2.5 text-xs" :class="totalRooms ? 'text-success' : 'text-slate-400'">
                            <Lucide :icon="totalRooms ? 'CircleCheck' : 'Circle'" class="h-3.5 w-3.5" />
                            <template v-if="totalRooms">{{ totalRooms }} habitación(es) dada(s) de alta</template>
                            <template v-else>
                                Pendiente:&nbsp;
                                <Link :href="route('tenant.rooms')" class="font-medium text-primary hover:underline">ir a Habitaciones</Link>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-6 grid grid-cols-12 items-stretch gap-6">
                <!-- Zonas -->
                <div class="col-span-12 flex flex-col xl:col-span-4">
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

                <!-- Tipos y tarifas -->
                <div class="col-span-12 flex flex-col xl:col-span-8">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-200/60 p-5 dark:border-darkmode-400">
                            <div class="flex items-center gap-2.5">
                                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-info/10 text-xs font-semibold text-info">2</div>
                                <div>
                                    <h2 class="flex items-center gap-1.5 text-base font-medium">
                                        Tipos y tarifas
                                        <span
                                            title="Cada tipo es una categoría (ej. Sencilla, Suite) con sus tarifas anidadas. El precio vive ÚNICAMENTE en las tarifas: el 'Desde' es la tarifa activa más barata. Un tipo sin tarifa activa no se puede reservar."
                                            class="inline-flex cursor-help"
                                        >
                                            <Lucide icon="Info" class="h-3.5 w-3.5 text-slate-400" />
                                        </span>
                                    </h2>
                                    <p class="text-xs text-slate-500">Qué ofreces y cómo lo cobras — el precio vive en las tarifas</p>
                                </div>
                            </div>
                            <Button v-if="canManage" variant="outline-primary" size="sm" class="rounded-[0.5rem]" @click="openType(null)">
                                <Lucide icon="Plus" class="mr-1 h-4 w-4" /> Agregar tipo
                            </Button>
                        </div>
                        <div class="flex-1">
                            <div v-if="roomTypes.length" class="divide-y divide-slate-200/60 dark:divide-darkmode-400">
                                <div v-for="type in roomTypes" :key="type.id">
                                    <!-- Fila del tipo -->
                                    <div
                                        class="flex cursor-pointer items-center gap-3 px-5 py-3.5 transition-colors hover:bg-slate-50/70 dark:hover:bg-darkmode-600/40"
                                        :class="!type.active && 'opacity-60'"
                                        @click="toggleTypeRow(type.id)"
                                    >
                                        <Lucide
                                            :icon="expandedTypes.has(type.id) ? 'ChevronDown' : 'ChevronRight'"
                                            class="h-4 w-4 shrink-0 text-slate-400"
                                        />
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-medium">{{ type.name }}</span>
                                                <span
                                                    v-if="!type.active"
                                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                                                >
                                                    Inactivo
                                                </span>
                                                <span
                                                    v-if="!type.has_active_rate"
                                                    class="rounded-full bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning"
                                                    title="Agrega una tarifa activa para poder reservar este tipo"
                                                >
                                                    Sin tarifa — no reservable
                                                </span>
                                            </div>
                                            <div class="mt-0.5 text-xs text-slate-500">
                                                {{ type.capacity }} pers<template v-if="occupancyBreakdown(type)"> ({{ occupancyBreakdown(type) }})</template>
                                                · {{ type.rooms_count }} hab. · {{ plansForType(type.id).length }} tarifa(s)
                                            </div>
                                        </div>
                                        <div class="shrink-0 text-right">
                                            <div v-if="type.price_from !== null" class="text-sm font-medium">
                                                Desde {{ money(type.price_from) }}
                                            </div>
                                        </div>
                                        <div v-if="canManage" class="flex shrink-0 gap-2.5" @click.stop>
                                            <a href="#" class="text-success" title="Agregar tarifa" @click.prevent="openPlanForType(type.id)">
                                                <Lucide icon="Tag" class="h-4 w-4" />
                                            </a>
                                            <a href="#" class="text-slate-500" title="Duplicar tipo con sus tarifas" @click.prevent="duplicateType(type)">
                                                <Lucide icon="Copy" class="h-4 w-4" />
                                            </a>
                                            <a href="#" class="text-primary" title="Editar tipo" @click.prevent="openType(type)">
                                                <Lucide icon="Pencil" class="h-4 w-4" />
                                            </a>
                                            <a href="#" class="text-danger" title="Eliminar tipo" @click.prevent="deleteType(type)">
                                                <Lucide icon="Trash2" class="h-4 w-4" />
                                            </a>
                                        </div>
                                    </div>

                                    <!-- Ficha y tarifas del tipo (expandido) -->
                                    <div v-if="expandedTypes.has(type.id)" class="bg-slate-50/60 px-5 pb-4 dark:bg-darkmode-600/30">
                                        <!-- Ficha comercial: lo que ve la web, el bot y el importador -->
                                        <div v-if="type.description || type.amenities.length" class="border-b border-dashed border-slate-300/70 py-3">
                                            <p v-if="type.description" class="text-xs leading-relaxed text-slate-600 dark:text-slate-300">
                                                {{ type.description }}
                                            </p>
                                            <div v-if="type.amenities.length" class="mt-2 flex flex-wrap gap-1.5">
                                                <span
                                                    v-for="amenity in type.amenities"
                                                    :key="amenity"
                                                    class="rounded-full bg-white px-2 py-0.5 text-xs text-slate-500 shadow-sm dark:bg-darkmode-400 dark:text-slate-300"
                                                >
                                                    {{ amenity }}
                                                </span>
                                            </div>
                                        </div>
                                        <div v-else class="border-b border-dashed border-slate-300/70 py-3 text-xs text-slate-400">
                                            Sin descripción ni amenidades aún — edítalas en el tipo o impórtalas desde tu sitio en
                                            <Link :href="route('tenant.integration')" class="font-medium text-primary hover:underline">Integración</Link>.
                                        </div>
                                        <div v-if="plansForType(type.id).length" class="overflow-x-auto">
                                            <Table>
                                                <Table.Thead>
                                                    <Table.Tr>
                                                        <Table.Th class="whitespace-nowrap">Tarifa</Table.Th>
                                                        <Table.Th>Duración</Table.Th>
                                                        <Table.Th>Precio</Table.Th>
                                                        <Table.Th>Anticipo</Table.Th>
                                                        <Table.Th>Antelación</Table.Th>
                                                        <Table.Th>Estado</Table.Th>
                                                        <Table.Th v-if="canManage" class="text-right" />
                                                    </Table.Tr>
                                                </Table.Thead>
                                                <Table.Tbody>
                                                    <Table.Tr v-for="plan in plansForType(type.id)" :key="plan.id">
                                                        <Table.Td class="font-medium">{{ plan.name }}</Table.Td>
                                                        <Table.Td>
                                                            <span
                                                                class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs"
                                                                :class="plan.type === 'night' ? 'bg-primary/10 text-primary' : 'bg-pending/10 text-pending'"
                                                            >
                                                                {{ plan.duration_label }}
                                                            </span>
                                                        </Table.Td>
                                                        <Table.Td class="whitespace-nowrap font-medium">
                                                            {{ money(plan.price) }}
                                                            <button
                                                                type="button"
                                                                class="ml-1.5 inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-normal text-slate-500 hover:bg-slate-200 dark:bg-darkmode-400 dark:hover:bg-darkmode-300"
                                                                title="Temporadas y promos"
                                                                @click="openSeasons(plan)"
                                                            >
                                                                <Lucide icon="CalendarRange" class="h-3 w-3" />
                                                                {{ plan.seasons_count || 'Temporadas' }}
                                                            </button>
                                                        </Table.Td>
                                                        <Table.Td class="text-slate-500">
                                                            <template v-if="plan.deposit_percent">
                                                                {{ Number(plan.deposit_percent) }}%
                                                                <span v-if="plan.payment_due_label" class="block text-xs">liquidar {{ plan.payment_due_label }}</span>
                                                            </template>
                                                            <span v-else>No requiere</span>
                                                        </Table.Td>
                                                        <Table.Td class="text-slate-500">{{ plan.min_advance_label ?? 'Sin restricción' }}</Table.Td>
                                                        <Table.Td>
                                                            <span
                                                                class="rounded-full px-2 py-0.5 text-xs"
                                                                :class="plan.active ? 'bg-success/10 text-success' : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'"
                                                            >
                                                                {{ plan.active ? 'Activa' : 'Inactiva' }}
                                                            </span>
                                                        </Table.Td>
                                                        <Table.Td v-if="canManage">
                                                            <div class="flex justify-end gap-3">
                                                                <a href="#" class="text-primary" title="Editar tarifa" @click.prevent="openPlan(plan)">
                                                                    <Lucide icon="Pencil" class="h-4 w-4" />
                                                                </a>
                                                                <a href="#" class="text-danger" title="Eliminar tarifa" @click.prevent="deletePlan(plan)">
                                                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                                                </a>
                                                            </div>
                                                        </Table.Td>
                                                    </Table.Tr>
                                                </Table.Tbody>
                                            </Table>
                                        </div>
                                        <div
                                            v-else
                                            class="flex flex-wrap items-center gap-3 rounded-md border border-dashed border-warning/40 bg-warning/5 px-4 py-3 text-xs text-slate-600 dark:text-slate-300"
                                        >
                                            <Lucide icon="TriangleAlert" class="h-4 w-4 shrink-0 text-warning" />
                                            Este tipo no tiene tarifas: no se puede reservar.
                                            <Button v-if="canManage" variant="outline-primary" size="sm" @click="openPlanForType(type.id)">
                                                <Lucide icon="Plus" class="mr-1 h-3 w-3" /> Agregar tarifa
                                            </Button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Tarifas sin tipo (defensivo) -->
                                <div v-if="orphanPlans.length" class="px-5 py-3.5">
                                    <div class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">Tarifas sin tipo asignado</div>
                                    <div v-for="plan in orphanPlans" :key="plan.id" class="flex items-center gap-3 py-1.5 text-sm">
                                        <span class="font-medium">{{ plan.name }}</span>
                                        <span class="text-slate-500">{{ plan.duration_label }} · {{ money(plan.price) }}</span>
                                        <a v-if="canManage" href="#" class="ml-auto text-danger" title="Eliminar tarifa" @click.prevent="deletePlan(plan)">
                                            <Lucide icon="Trash2" class="h-4 w-4" />
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="flex flex-col items-center gap-3 py-10 text-center">
                                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-info/10 text-info">
                                    <Lucide icon="BedDouble" class="h-6 w-6" />
                                </div>
                                <div>
                                    <p class="text-sm font-medium">Aún no hay tipos de habitación</p>
                                    <p class="mt-0.5 text-xs text-slate-500">Ej: "Sencilla", "Suite", "Jacuzzi". Al crearlo capturas su precio una sola vez.</p>
                                </div>
                                <Button v-if="canManage" variant="outline-primary" size="sm" class="rounded-[0.5rem]" @click="openType(null)">
                                    <Lucide icon="Plus" class="mr-1 h-4 w-4" /> Crear primer tipo
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

                        <div class="rounded-md border border-slate-200/70 p-3 dark:border-darkmode-400">
                            <FormCheck>
                                <FormCheck.Input id="plan-cancel-policy" v-model="planForm.has_cancel_policy" type="checkbox" />
                                <FormCheck.Label htmlFor="plan-cancel-policy">Política de cancelación con reembolso</FormCheck.Label>
                            </FormCheck>
                            <div v-if="planForm.has_cancel_policy" class="mt-3 space-y-3">
                                <div>
                                    <FormLabel>Cancelación sin costo hasta</FormLabel>
                                    <div class="flex gap-2">
                                        <FormInput v-model.number="planForm.cancel_free_value" type="number" min="1" class="w-24" />
                                        <FormSelect v-model="planForm.cancel_free_unit" class="flex-1">
                                            <option v-for="unit in advanceUnits" :key="unit.value" :value="unit.value">{{ unit.label }}</option>
                                        </FormSelect>
                                    </div>
                                    <FormHelp>Antes de este límite se sugiere reembolsar todo lo pagado.</FormHelp>
                                    <FormHelp v-if="errors.cancel_free_value || errors.cancel_free_unit" class="text-danger">
                                        {{ errors.cancel_free_value ?? errors.cancel_free_unit }}
                                    </FormHelp>
                                </div>
                                <div>
                                    <FormLabel htmlFor="plan-cancel-penalty">Penalidad fuera de la ventana (% de lo pagado que se retiene)</FormLabel>
                                    <FormInput id="plan-cancel-penalty" v-model.number="planForm.cancel_penalty_percent" type="number" min="0" max="100" step="0.5" class="w-32" />
                                    <FormHelp>100 = sin reembolso al cancelar tarde (lo típico). Es una sugerencia al reembolsar; tu equipo siempre decide.</FormHelp>
                                    <FormHelp v-if="errors.cancel_penalty_percent" class="text-danger">{{ errors.cancel_penalty_percent }}</FormHelp>
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

        <!-- Modal temporadas y promos -->
        <Dialog :open="showSeasonsModal" size="lg" @close="showSeasonsModal = false">
            <Dialog.Panel>
                <div class="p-5">
                    <h2 class="mb-1 text-base font-medium">Temporadas y promos</h2>
                    <p class="mb-4 text-xs text-slate-500">
                        {{ seasonsPlan?.name }} — un rango de fechas con un precio que SUSTITUYE al de la tarifa mientras esté
                        vigente. Si dos se solapan, gana la de mayor prioridad.
                    </p>

                    <div v-if="loadingSeasons" class="py-6 text-center text-sm text-slate-500">Cargando…</div>
                    <template v-else>
                        <div v-if="seasons.length" class="mb-5 space-y-2">
                            <div
                                v-for="season in seasons"
                                :key="season.id"
                                class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200/70 p-3 text-sm dark:border-darkmode-400"
                            >
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">{{ season.name }}</span>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[11px]"
                                            :class="season.kind === 'promo' ? 'bg-pending/10 text-pending' : 'bg-primary/10 text-primary'"
                                        >
                                            {{ season.kind === 'promo' ? 'Promo' : 'Temporada' }}
                                        </span>
                                        <span v-if="!season.active" class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400">
                                            Inactiva
                                        </span>
                                    </div>
                                    <div class="mt-0.5 text-xs text-slate-500">
                                        {{ season.starts_on }} → {{ season.ends_on }} · {{ money(season.price) }} · prioridad {{ season.priority }}
                                    </div>
                                </div>
                                <div class="flex shrink-0 gap-3">
                                    <a href="#" class="text-primary" title="Editar" @click.prevent="editSeason(season)">
                                        <Lucide icon="Pencil" class="h-4 w-4" />
                                    </a>
                                    <a href="#" class="text-danger" title="Eliminar" @click.prevent="deleteSeason(season)">
                                        <Lucide icon="Trash2" class="h-4 w-4" />
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div v-else class="mb-5 rounded-md border border-dashed border-slate-200 px-4 py-3 text-center text-xs text-slate-500 dark:border-darkmode-400">
                            Sin temporadas — la tarifa cobra su precio normal todo el año.
                        </div>

                        <form class="space-y-3 border-t border-slate-200/60 pt-4 dark:border-darkmode-400" @submit.prevent="submitSeason">
                            <h3 class="text-xs font-medium uppercase tracking-wide text-slate-400">
                                {{ editingSeason ? 'Editar temporada' : 'Agregar temporada o promo' }}
                            </h3>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <FormLabel htmlFor="season-name">Nombre</FormLabel>
                                    <FormInput id="season-name" v-model="seasonForm.name" type="text" placeholder="Semana Santa" />
                                    <FormHelp v-if="errors.name" class="text-danger">{{ errors.name }}</FormHelp>
                                </div>
                                <div>
                                    <FormLabel htmlFor="season-kind">Tipo</FormLabel>
                                    <FormSelect id="season-kind" v-model="seasonForm.kind">
                                        <option value="season">Temporada</option>
                                        <option value="promo">Promo</option>
                                    </FormSelect>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <FormLabel htmlFor="season-starts">Desde</FormLabel>
                                    <FormInput id="season-starts" v-model="seasonForm.starts_on" type="date" />
                                    <FormHelp v-if="errors.starts_on" class="text-danger">{{ errors.starts_on }}</FormHelp>
                                </div>
                                <div>
                                    <FormLabel htmlFor="season-ends">Hasta</FormLabel>
                                    <FormInput id="season-ends" v-model="seasonForm.ends_on" type="date" />
                                    <FormHelp v-if="errors.ends_on" class="text-danger">{{ errors.ends_on }}</FormHelp>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <FormLabel htmlFor="season-price">Precio ($)</FormLabel>
                                    <FormInput id="season-price" v-model="seasonForm.price" type="number" step="0.01" min="0" />
                                    <FormHelp>Sustituye el precio normal ({{ money(seasonsPlan?.price ?? 0) }}) mientras esté vigente.</FormHelp>
                                    <FormHelp v-if="errors.price" class="text-danger">{{ errors.price }}</FormHelp>
                                </div>
                                <div>
                                    <FormLabel htmlFor="season-priority">Prioridad</FormLabel>
                                    <FormInput id="season-priority" v-model.number="seasonForm.priority" type="number" min="0" />
                                    <FormHelp>Si se solapa con otra, gana la de número más alto.</FormHelp>
                                </div>
                            </div>
                            <div class="flex justify-end gap-2 pt-1">
                                <Button v-if="editingSeason" type="button" variant="outline-secondary" size="sm" @click="resetSeasonForm">
                                    Cancelar edición
                                </Button>
                                <Button type="submit" variant="primary" size="sm" :disabled="saving">
                                    {{ editingSeason ? 'Guardar cambios' : 'Agregar' }}
                                </Button>
                            </div>
                        </form>
                    </template>

                    <div class="flex justify-end pt-4">
                        <Button type="button" variant="outline-secondary" @click="showSeasonsModal = false">Cerrar</Button>
                    </div>
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
                <form class="flex flex-col" @submit.prevent="submitType">
                    <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-7 py-5 dark:border-darkmode-400">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="BedDouble" class="h-5 w-5 text-primary" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">{{ editingType ? `Editar tipo ${editingType.name}` : 'Nuevo tipo de habitación' }}</h2>
                            <p class="mt-0.5 text-xs text-slate-500">Es la ficha que el widget web y los bots usan para vender.</p>
                        </div>
                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400" @click="showTypeForm = false">
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="max-h-[70vh] space-y-6 overflow-y-auto px-7 py-6">
                        <!-- Identidad -->
                        <section>
                            <div class="mb-4 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="BadgeCheck" class="h-3.5 w-3.5" /> Identidad
                            </div>
                            <div class="grid grid-cols-12 gap-5">
                                <div class="col-span-12">
                                    <FormLabel htmlFor="type-name">Nombre</FormLabel>
                                    <FormInput id="type-name" v-model="typeForm.name" type="text" placeholder="Suite" />
                                    <FormHelp v-if="errors.name" class="text-danger">{{ errors.name }}</FormHelp>
                                </div>
                                <div class="col-span-12">
                                    <FormLabel htmlFor="type-description">Descripción</FormLabel>
                                    <FormTextarea id="type-description" v-model="typeForm.description" placeholder="Suite amplia con jacuzzi y balcón…" />
                                    <FormHelp v-if="errors.description" class="text-danger">{{ errors.description }}</FormHelp>
                                    <FormHelp v-else>Es el texto con el que el widget y los bots describen la habitación.</FormHelp>
                                </div>
                            </div>
                        </section>

                        <!-- Fotos (solo al editar: la galería se liga al tipo ya guardado) -->
                        <section class="border-t border-dashed border-slate-300/70 pt-5">
                            <div class="mb-1 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="Image" class="h-3.5 w-3.5" /> Fotos
                            </div>
                            <p class="mb-4 text-xs text-slate-500">
                                La galería que ve el huésped en el wizard. La primera foto es la portada de la tarjeta.
                            </p>

                            <div v-if="!editingType" class="rounded-lg border border-dashed border-slate-300/70 px-4 py-5 text-center text-xs text-slate-500 dark:border-darkmode-400">
                                Guarda el tipo primero; después podrás subir sus fotos desde aquí.
                            </div>
                            <template v-else>
                                <div v-if="typePhotos.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                    <div
                                        v-for="(photo, index) in typePhotos"
                                        :key="photo.id"
                                        class="group relative aspect-[4/3] overflow-hidden rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                                    >
                                        <img :src="photo.thumb_url" alt="" class="h-full w-full object-cover" loading="lazy" />
                                        <span
                                            v-if="index === 0"
                                            class="absolute left-1.5 top-1.5 rounded-full bg-primary px-2 py-0.5 text-[10px] font-medium text-white"
                                        >
                                            Portada
                                        </span>
                                        <div class="absolute inset-x-0 bottom-0 flex justify-end gap-1 bg-gradient-to-t from-black/60 to-transparent p-1.5 opacity-0 transition group-hover:opacity-100">
                                            <button
                                                v-if="index !== 0"
                                                type="button"
                                                class="rounded bg-white/90 px-1.5 py-1 text-[10px] font-medium text-slate-700 transition hover:bg-white"
                                                title="Usar como portada"
                                                :disabled="photoBusy"
                                                @click="makeCover(photo)"
                                            >
                                                Portada
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded bg-white/90 p-1 text-danger transition hover:bg-white"
                                                title="Quitar foto"
                                                :disabled="photoBusy"
                                                @click="removePhoto(photo)"
                                            >
                                                <Lucide icon="Trash2" class="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="rounded-lg border border-dashed border-slate-300/70 px-4 py-5 text-center text-xs text-slate-500 dark:border-darkmode-400">
                                    Sin fotos: la tarjeta del wizard se muestra solo con texto. Una buena galería es lo que más ayuda a que reserven.
                                </div>

                                <div class="mt-3 flex items-center gap-3">
                                    <input
                                        ref="photoInput"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        multiple
                                        class="hidden"
                                        @change="uploadPhotos"
                                    />
                                    <Button type="button" variant="outline-secondary" class="rounded-[0.5rem] bg-white" :disabled="photoBusy || typePhotos.length >= 12" @click="photoInput?.click()">
                                        <Lucide :icon="photoBusy ? 'RefreshCw' : 'ImagePlus'" class="mr-2 h-4 w-4" :class="photoBusy && 'animate-spin'" />
                                        {{ photoBusy ? 'Subiendo…' : 'Agregar fotos' }}
                                    </Button>
                                    <span class="text-xs text-slate-400">{{ typePhotos.length }} de 12 · JPG, PNG o WebP, máx. 6 MB</span>
                                </div>
                            </template>
                        </section>

                        <!-- Capacidad -->
                        <section class="border-t border-dashed border-slate-300/70 pt-5">
                            <div class="mb-4 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="Users" class="h-3.5 w-3.5" /> Capacidad
                            </div>
                            <div class="grid grid-cols-12 gap-5">
                                <div class="col-span-12 sm:col-span-4">
                                    <FormLabel htmlFor="type-capacity">Capacidad (pax)</FormLabel>
                                    <FormInput id="type-capacity" v-model.number="typeForm.capacity" type="number" min="1" max="20" />
                                    <FormHelp v-if="errors.capacity" class="text-danger">{{ errors.capacity }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-4">
                                    <FormLabel htmlFor="type-max-adults">Máx. adultos</FormLabel>
                                    <FormInput id="type-max-adults" v-model="typeForm.max_adults" type="number" min="0" placeholder="—" />
                                    <FormHelp v-if="errors.max_adults" class="text-danger">{{ errors.max_adults }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-4">
                                    <FormLabel htmlFor="type-max-children">Máx. niños</FormLabel>
                                    <FormInput id="type-max-children" v-model="typeForm.max_children" type="number" min="0" placeholder="—" />
                                    <FormHelp v-if="errors.max_children" class="text-danger">{{ errors.max_children }}</FormHelp>
                                </div>
                            </div>
                        </section>

                        <!-- Precio único: solo se captura al crear; después vive en las tarifas -->
                        <section v-if="!editingType" class="border-t border-dashed border-slate-300/70 pt-5">
                            <div class="mb-4 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="Tag" class="h-3.5 w-3.5" /> Precio y modalidad
                            </div>
                            <div class="grid grid-cols-12 gap-5">
                                <div class="col-span-12 sm:col-span-4">
                                    <FormLabel htmlFor="type-price">Precio ($)</FormLabel>
                                    <FormInput id="type-price" v-model="typeForm.price" type="number" step="0.01" min="0.01" placeholder="650.00" />
                                    <FormHelp v-if="errors.price" class="text-danger">{{ errors.price }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-8">
                                    <FormLabel htmlFor="type-rate-type">Cobro</FormLabel>
                                    <FormSelect id="type-rate-type" v-model="typeForm.rate_type">
                                        <option value="night">Por noche</option>
                                        <option value="block">Por periodo (horas/días/semanas)</option>
                                    </FormSelect>
                                </div>
                                <div v-if="typeForm.rate_type === 'block'" class="col-span-12">
                                    <FormLabel>Duración del periodo</FormLabel>
                                    <div class="flex gap-2">
                                        <FormInput v-model.number="typeForm.duration_value" type="number" min="1" class="w-24" />
                                        <FormSelect v-model="typeForm.duration_unit" class="flex-1">
                                            <option v-for="unit in durationUnits" :key="unit.value" :value="unit.value">{{ unit.label }}</option>
                                        </FormSelect>
                                    </div>
                                    <FormHelp v-if="errors.duration_value || errors.duration_unit" class="text-danger">
                                        {{ errors.duration_value ?? errors.duration_unit }}
                                    </FormHelp>
                                </div>
                            </div>
                            <FormHelp class="mt-2">
                                Se guarda como la tarifa "Tarifa base" del tipo. Después puedes agregar más tarifas (rato, semana, promo) desde la lista.
                            </FormHelp>
                        </section>
                        <section v-else class="border-t border-dashed border-slate-300/70 pt-5">
                            <div class="flex items-start gap-2 rounded-lg border border-info/20 bg-info/5 px-3 py-2.5 text-xs text-slate-600 dark:text-slate-300">
                                <Lucide icon="Info" class="h-4 w-4 shrink-0 text-info" />
                                <span>
                                    El precio de este tipo vive en sus <span class="font-medium">tarifas</span>: se editan en su fila de la lista
                                    (expande el tipo) o agrega una nueva con el icono de etiqueta.
                                </span>
                            </div>
                        </section>

                        <!-- Horarios -->
                        <section class="border-t border-dashed border-slate-300/70 pt-5">
                            <div class="mb-4 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="Clock" class="h-3.5 w-3.5" /> Horarios
                            </div>
                            <div class="grid grid-cols-12 gap-5">
                                <div class="col-span-12 sm:col-span-4">
                                    <FormLabel htmlFor="type-checkin">Check-in</FormLabel>
                                    <FormInput id="type-checkin" v-model="typeForm.check_in_time" type="time" />
                                    <FormHelp v-if="errors.check_in_time" class="text-danger">{{ errors.check_in_time }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-4">
                                    <FormLabel htmlFor="type-checkout">Check-out</FormLabel>
                                    <FormInput id="type-checkout" v-model="typeForm.check_out_time" type="time" />
                                    <FormHelp v-if="errors.check_out_time" class="text-danger">{{ errors.check_out_time }}</FormHelp>
                                </div>
                            </div>
                        </section>

                        <!-- Amenidades -->
                        <section class="border-t border-dashed border-slate-300/70 pt-5">
                            <div class="mb-4 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="Sparkles" class="h-3.5 w-3.5" /> Amenidades
                            </div>
                            <FormInput
                                id="type-amenities"
                                v-model="typeAmenityInput"
                                type="text"
                                maxlength="100"
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
                            <!-- Sugerencias comunes (spec-integracion-sitios §5): un clic agrega -->
                            <div class="mt-2.5 flex flex-wrap gap-1.5">
                                <button
                                    v-for="suggestion in AMENITY_SUGGESTIONS.filter((a) => !typeForm.amenities.includes(a))"
                                    :key="suggestion"
                                    type="button"
                                    class="rounded-full border border-dashed border-slate-300 px-2 py-0.5 text-xs text-slate-500 transition hover:border-primary hover:text-primary dark:border-darkmode-400"
                                    @click="typeForm.amenities.push(suggestion)"
                                >
                                    + {{ suggestion }}
                                </button>
                            </div>
                            <FormHelp v-if="errors.amenities" class="text-danger">{{ errors.amenities }}</FormHelp>
                        </section>

                        <!-- Publicación -->
                        <section class="border-t border-dashed border-slate-300/70 pt-5">
                            <div class="mb-4 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="Store" class="h-3.5 w-3.5" /> Publicación
                            </div>
                            <div class="grid grid-cols-12 gap-5">
                                <div class="col-span-12 sm:col-span-4">
                                    <FormLabel htmlFor="type-sort">Orden</FormLabel>
                                    <FormInput id="type-sort" v-model.number="typeForm.sort_order" type="number" min="0" />
                                    <FormHelp v-if="errors.sort_order" class="text-danger">{{ errors.sort_order }}</FormHelp>
                                    <FormHelp v-else>Menor número aparece primero.</FormHelp>
                                </div>
                                <label class="col-span-12 flex cursor-pointer items-start gap-3.5 rounded-lg border border-slate-200/70 p-4 dark:border-darkmode-400 sm:col-span-8">
                                    <FormSwitch class="mt-0.5">
                                        <FormSwitch.Input id="type-active" v-model="typeForm.active" type="checkbox" />
                                    </FormSwitch>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium">Activo (a la venta)</span>
                                        <span class="mt-0.5 block text-xs text-slate-500">El widget y los bots pueden ofrecerlo; al apagarlo deja de venderse sin tocar sus habitaciones.</span>
                                    </span>
                                </label>
                            </div>
                        </section>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-7 py-4 dark:border-darkmode-400">
                        <Button type="button" variant="outline-secondary" @click="showTypeForm = false">Cancelar</Button>
                        <Button type="submit" variant="primary" class="shadow-md shadow-primary/20" :disabled="saving">
                            <Lucide icon="Check" class="mr-2 h-4 w-4" /> {{ saving ? 'Guardando…' : 'Guardar tipo' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
