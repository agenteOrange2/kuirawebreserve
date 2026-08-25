<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormCheck,
    FormHelp,
    FormInput,
    FormLabel,
    FormSwitch,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog, Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

/**
 * Registro de vehículos: el equivalente de Huéspedes cuando el cliente es el
 * carro, con su misma estructura de tarjetas y acciones (ver ficha, editar,
 * archivar o eliminar). Dos pestañas porque son las dos formas de entrar a
 * una caseta: en vehículo (placa) o a pie (identificación).
 */
interface VehicleRow {
    id: number;
    plate: string;
    label: string | null;
    brand: string | null;
    model: string | null;
    color: string | null;
    year: number | null;
    notes: string | null;
    blacklist_reason: string | null;
    visits: number;
    last_seen_at: string | null;
    created_at: string;
    is_inside: boolean;
    is_blacklisted: boolean;
    is_archived: boolean;
}

interface ArrivalRow {
    id: number;
    guest_name: string;
    guest_id: number | null;
    id_document_type: string | null;
    documents: string[];
    room: string | null;
    rate_plan: string | null;
    check_in_at: string;
    check_out_at: string | null;
    planned_end_at: string | null;
    status: string;
    amount: number;
    num_people: number;
    notes: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    total: number;
}

const props = defineProps<{
    tab: 'vehiculos' | 'pie';
    vehicles: Paginated<VehicleRow> | null;
    arrivals: Paginated<ArrivalRow> | null;
    counts: {
        vehicles: number;
        archived: number;
        inside: number;
        foot: number;
    };
    filters: {
        q: string;
        inside: boolean;
        blacklisted: boolean;
        archived: boolean;
    };
    canManage: boolean;
    canViewDocuments: boolean;
}>();

const toast = useToasts();

const documentLabels: Record<string, string> = {
    ine: 'INE',
    pasaporte: 'Pasaporte',
    licencia: 'Licencia',
    otro: 'Otro documento',
};

const money = (value: number) =>
    `$${Number(value).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

const q = ref(props.filters.q);
const inside = ref(props.filters.inside);
const blacklisted = ref(props.filters.blacklisted);
const archived = ref(props.filters.archived);
const tab = ref(props.tab);

const filtersActive = computed(
    () =>
        q.value.trim() !== '' ||
        inside.value ||
        blacklisted.value ||
        archived.value,
);

function clearFilters(): void {
    q.value = '';
    inside.value = false;
    blacklisted.value = false;
    archived.value = false;
}

// Mismo patrón que el CRM de huéspedes: debounce y recarga parcial por
// Inertia, sin endpoint aparte.
let timer: ReturnType<typeof setTimeout> | null = null;

watch([q, inside, blacklisted, archived, tab], () => {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => {
        const enVehiculos = tab.value === 'vehiculos';

        router.get(
            route('tenant.vehicles'),
            {
                q: q.value || undefined,
                inside: enVehiculos && inside.value ? 1 : undefined,
                blacklisted: enVehiculos && blacklisted.value ? 1 : undefined,
                archived: enVehiculos && archived.value ? 1 : undefined,
                tab: enVehiculos ? undefined : 'pie',
            },
            {
                preserveState: true,
                replace: true,
                only: ['vehicles', 'arrivals', 'counts', 'filters', 'tab'],
            },
        );
    }, 350);
});

// ── Editar ficha ──
const editing = ref<VehicleRow | null>(null);
const saving = ref(false);
const errors = ref<Record<string, string>>({});
const form = reactive({
    brand: '',
    model: '',
    color: '',
    year: '' as number | string,
    notes: '',
    is_blacklisted: false,
    blacklist_reason: '',
});

function openEdit(vehicle: VehicleRow): void {
    editing.value = vehicle;
    errors.value = {};
    form.brand = vehicle.brand ?? '';
    form.model = vehicle.model ?? '';
    form.color = vehicle.color ?? '';
    form.year = vehicle.year ?? '';
    form.notes = vehicle.notes ?? '';
    form.is_blacklisted = vehicle.is_blacklisted;
    form.blacklist_reason = vehicle.blacklist_reason ?? '';
}

async function saveEdit(): Promise<void> {
    if (!editing.value) return;
    saving.value = true;
    errors.value = {};

    try {
        await axios.patch(`/api/vehicles/${editing.value.id}`, {
            brand: form.brand || null,
            model: form.model || null,
            color: form.color || null,
            year: form.year === '' ? null : Number(form.year),
            notes: form.notes || null,
            is_blacklisted: form.is_blacklisted,
            blacklist_reason: form.is_blacklisted
                ? form.blacklist_reason || null
                : null,
        });
        toast.success('Vehículo actualizado', `Placa ${editing.value.plate}`);
        editing.value = null;
        router.reload({ only: ['vehicles', 'counts'] });
    } catch (error: any) {
        errors.value = error.response?.data?.errors ?? {};
        toast.error(
            'No se pudo guardar',
            error.response?.data?.message ?? 'Revisa los datos.',
        );
    } finally {
        saving.value = false;
    }
}

// ── Archivar o eliminar ──
// Con historial se archiva (las estancias quedarían huérfanas) y se puede
// restaurar; sin historial se borra de verdad. Igual que en Huéspedes.
const deleting = ref<VehicleRow | null>(null);
const deleteBusy = ref(false);
const restoringId = ref<number | null>(null);

async function confirmDelete(): Promise<void> {
    if (!deleting.value) return;
    deleteBusy.value = true;

    try {
        const { data, status } = await axios.delete(
            `/api/vehicles/${deleting.value.id}`,
        );
        toast.success(
            status === 204 ? 'Vehículo eliminado' : 'Vehículo archivado',
            data?.message ?? `Placa ${deleting.value.plate}`,
        );
        deleting.value = null;
        router.reload({ only: ['vehicles', 'counts'] });
    } catch (error: any) {
        toast.error(
            'No se pudo completar',
            error.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        deleteBusy.value = false;
    }
}

async function restoreVehicle(vehicle: VehicleRow): Promise<void> {
    restoringId.value = vehicle.id;

    try {
        await axios.post(`/api/vehicles/${vehicle.id}/restore`);
        toast.success('Vehículo restaurado', `Placa ${vehicle.plate}`);
        router.reload({ only: ['vehicles', 'counts'] });
    } catch (error: any) {
        toast.error(
            'No se pudo restaurar',
            error.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        restoringId.value = null;
    }
}
</script>

<template>
    <RazeLayout title="Vehículos">
        <div class="box box--stacked mt-5 p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Car" class="h-7 w-7" />
                    </div>
                    <div>
                        <h1 class="text-xl font-medium">
                            Registro de vehículos
                        </h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Quién entra a la caseta: por placa o, si llegan a
                            pie, con su identificación.
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 font-medium text-success"
                    >
                        <Lucide icon="Car" class="h-3.5 w-3.5" />
                        {{ counts.inside }} adentro ahora
                    </span>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-200"
                    >
                        {{ counts.vehicles }}
                        {{ counts.vehicles === 1 ? 'placa' : 'placas' }}
                    </span>
                </div>
            </div>
        </div>

        <div class="box box--stacked mt-5 p-5 sm:p-6">
            <div class="flex items-start gap-4">
                <div
                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                >
                    <Lucide icon="Search" class="h-6 w-6" />
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-medium">
                        {{
                            tab === 'pie'
                                ? 'Encuentra una llegada a pie'
                                : 'Encuentra un vehículo'
                        }}
                    </h2>
                    <p class="text-sm text-slate-500">
                        {{
                            tab === 'pie'
                                ? 'Busca por el nombre con el que se registró.'
                                : 'Busca por placa, marca, modelo o color. Da igual cómo escribas la placa.'
                        }}
                    </p>
                </div>
            </div>

            <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:items-center">
                <div
                    class="inline-flex shrink-0 gap-1 rounded-[0.6rem] bg-slate-100/80 p-1 dark:bg-darkmode-700"
                >
                    <button
                        type="button"
                        class="rounded-[0.5rem] px-3 py-1.5 text-sm font-medium transition"
                        :class="
                            tab === 'vehiculos'
                                ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                : 'text-slate-500'
                        "
                        @click="tab = 'vehiculos'"
                    >
                        Vehículos
                    </button>
                    <button
                        type="button"
                        class="rounded-[0.5rem] px-3 py-1.5 text-sm font-medium transition"
                        :class="
                            tab === 'pie'
                                ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                : 'text-slate-500'
                        "
                        @click="tab = 'pie'"
                    >
                        Llegadas a pie ({{ counts.foot }})
                    </button>
                </div>
                <div class="relative min-w-0 flex-1">
                    <Lucide
                        icon="Search"
                        class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                    />
                    <FormInput
                        v-model="q"
                        type="text"
                        class="min-h-11 pl-9"
                        :placeholder="
                            tab === 'pie'
                                ? 'Nombre del huésped'
                                : 'Placa, marca o modelo'
                        "
                    />
                </div>
                <div
                    v-if="tab === 'vehiculos'"
                    class="flex flex-wrap items-center gap-4"
                >
                    <FormCheck>
                        <FormCheck.Input v-model="inside" type="checkbox" />
                        <FormCheck.Label class="text-sm whitespace-nowrap"
                            >Adentro ahora</FormCheck.Label
                        >
                    </FormCheck>
                    <FormCheck>
                        <FormCheck.Input
                            v-model="blacklisted"
                            type="checkbox"
                        />
                        <FormCheck.Label class="text-sm whitespace-nowrap"
                            >Vetadas</FormCheck.Label
                        >
                    </FormCheck>
                    <FormCheck v-if="counts.archived">
                        <FormCheck.Input v-model="archived" type="checkbox" />
                        <FormCheck.Label class="text-sm whitespace-nowrap"
                            >Archivadas ({{ counts.archived }})</FormCheck.Label
                        >
                    </FormCheck>
                </div>
                <Button
                    v-if="filtersActive"
                    variant="outline-secondary"
                    class="min-h-11 rounded-[0.5rem] whitespace-nowrap"
                    @click="clearFilters"
                >
                    <Lucide icon="X" class="mr-2 h-4 w-4" />
                    Limpiar
                </Button>
            </div>
        </div>

        <!-- ── Pestaña de vehículos ── -->
        <div
            v-if="tab === 'vehiculos'"
            class="box box--stacked mt-5 p-5 sm:p-6"
        >
            <div class="mb-4">
                <h2 class="text-base font-medium">Placas registradas</h2>
                <p class="text-xs text-slate-500">
                    {{ vehicles?.total ?? 0 }}
                    {{
                        (vehicles?.total ?? 0) === 1 ? 'vehículo' : 'vehículos'
                    }}
                </p>
            </div>

            <div v-if="vehicles && vehicles.data.length" class="space-y-3">
                <article
                    v-for="vehicle in vehicles.data"
                    :key="vehicle.id"
                    class="grid grid-cols-1 items-center gap-x-4 gap-y-3 rounded-xl border border-slate-200/70 bg-white p-4 shadow-xs transition hover:border-primary/25 hover:shadow-sm lg:grid-cols-[minmax(12rem,1.1fr)_minmax(10rem,1fr)_minmax(9rem,0.6fr)_auto] dark:border-darkmode-400 dark:bg-darkmode-600"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-white"
                        >
                            <Lucide icon="Car" class="h-6 w-6" />
                        </div>
                        <div class="min-w-0">
                            <Link
                                :href="
                                    route('tenant.vehicles.show', vehicle.id)
                                "
                                class="block truncate text-base font-semibold tracking-wide text-primary hover:underline"
                            >
                                {{ vehicle.plate }}
                            </Link>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                <span
                                    v-if="vehicle.is_blacklisted"
                                    class="rounded-full bg-danger/10 px-2 py-0.5 text-xs font-medium text-danger"
                                >
                                    Vetada
                                </span>
                                <span
                                    v-if="vehicle.is_archived"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500 dark:bg-darkmode-400"
                                >
                                    Archivada
                                </span>
                                <span
                                    v-if="vehicle.is_inside"
                                    class="rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success"
                                >
                                    Adentro
                                </span>
                                <span class="text-xs text-slate-400"
                                    >Registrada {{ vehicle.created_at }}</span
                                >
                            </div>
                        </div>
                    </div>

                    <div class="min-w-0 text-sm text-slate-500">
                        <div
                            v-if="vehicle.label"
                            class="flex items-center gap-2"
                        >
                            <Lucide
                                icon="Car"
                                class="h-4 w-4 shrink-0 text-primary"
                            />
                            <span class="truncate">{{ vehicle.label }}</span>
                        </div>
                        <span v-else>Sin datos del vehículo</span>
                        <div
                            v-if="vehicle.notes"
                            class="mt-1 truncate text-xs text-slate-400"
                        >
                            {{ vehicle.notes }}
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-full"
                            :class="
                                vehicle.visits > 0
                                    ? 'bg-success/10 text-success'
                                    : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                            "
                        >
                            <Lucide icon="BedDouble" class="h-5 w-5" />
                        </div>
                        <div>
                            <div class="text-sm font-medium">
                                {{ vehicle.visits }}
                                {{
                                    vehicle.visits === 1
                                        ? 'entrada'
                                        : 'entradas'
                                }}
                            </div>
                            <div class="text-xs text-slate-500">
                                {{
                                    vehicle.last_seen_at
                                        ? `Última: ${vehicle.last_seen_at}`
                                        : 'Sin entradas'
                                }}
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 lg:justify-end">
                        <Button
                            :as="Link"
                            :href="route('tenant.vehicles.show', vehicle.id)"
                            variant="outline-secondary"
                            class="min-h-10 flex-1 rounded-[0.5rem] whitespace-nowrap lg:flex-none"
                        >
                            <Lucide icon="Eye" class="mr-2 h-4 w-4" />
                            Ver ficha
                        </Button>
                        <Menu v-if="canManage">
                            <Menu.Button
                                class="flex h-10 w-10 items-center justify-center rounded-[0.5rem] border border-slate-200 text-slate-500 transition hover:bg-slate-100 dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                                title="Más acciones"
                            >
                                <Lucide icon="MoreVertical" class="h-5 w-5" />
                            </Menu.Button>
                            <Menu.Items class="w-56">
                                <Menu.Item
                                    v-if="!vehicle.is_archived"
                                    as="button"
                                    type="button"
                                    @click="openEdit(vehicle)"
                                >
                                    <Lucide
                                        icon="Pencil"
                                        class="mr-2 h-5 w-5"
                                    />
                                    Editar vehículo
                                </Menu.Item>
                                <Menu.Item
                                    v-if="!vehicle.is_archived"
                                    as="button"
                                    type="button"
                                    class="text-danger"
                                    @click="deleting = vehicle"
                                >
                                    <Lucide
                                        icon="Archive"
                                        class="mr-2 h-5 w-5"
                                    />
                                    Archivar o eliminar
                                </Menu.Item>
                                <Menu.Item
                                    v-if="vehicle.is_archived"
                                    as="button"
                                    type="button"
                                    :disabled="restoringId === vehicle.id"
                                    @click="restoreVehicle(vehicle)"
                                >
                                    <Lucide
                                        icon="ArchiveRestore"
                                        class="mr-2 h-5 w-5"
                                    />
                                    {{
                                        restoringId === vehicle.id
                                            ? 'Restaurando…'
                                            : 'Restaurar vehículo'
                                    }}
                                </Menu.Item>
                                <Menu.Item
                                    v-if="vehicle.is_archived"
                                    as="button"
                                    type="button"
                                    class="text-danger"
                                    @click="deleting = vehicle"
                                >
                                    <Lucide
                                        icon="Trash2"
                                        class="mr-2 h-5 w-5"
                                    />
                                    Eliminar definitivamente
                                </Menu.Item>
                            </Menu.Items>
                        </Menu>
                    </div>
                </article>
            </div>
            <div
                v-else
                class="flex flex-col items-center gap-3 py-12 text-center"
            >
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"
                >
                    <Lucide icon="Car" class="h-6 w-6" />
                </div>
                <p class="max-w-md px-6 text-sm text-slate-500">
                    {{
                        filtersActive
                            ? 'Ninguna placa coincide con la búsqueda.'
                            : 'Todavía no hay placas registradas. Se van llenando solas conforme registras llegadas en el plano.'
                    }}
                </p>
            </div>
        </div>

        <!-- ── Pestaña de llegadas a pie ── -->
        <div v-else class="box box--stacked mt-5 p-5 sm:p-6">
            <div class="mb-4">
                <h2 class="text-base font-medium">Llegadas a pie</h2>
                <p class="text-xs text-slate-500">
                    Quien entró sin vehículo y se registró con identificación.
                </p>
            </div>

            <div v-if="arrivals && arrivals.data.length" class="space-y-3">
                <article
                    v-for="arrival in arrivals.data"
                    :key="arrival.id"
                    class="grid grid-cols-1 items-center gap-x-4 gap-y-3 rounded-xl border border-slate-200/70 bg-white p-4 shadow-xs transition hover:border-primary/25 hover:shadow-sm lg:grid-cols-[minmax(12rem,1.1fr)_minmax(10rem,1fr)_minmax(9rem,0.6fr)_auto] dark:border-darkmode-400 dark:bg-darkmode-600"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-white"
                        >
                            <Lucide icon="Footprints" class="h-6 w-6" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate font-medium">
                                {{ arrival.guest_name }}
                            </div>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                <span
                                    v-if="arrival.status === 'active'"
                                    class="rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success"
                                >
                                    Adentro
                                </span>
                                <span class="text-xs text-slate-400"
                                    >Entró {{ arrival.check_in_at }}</span
                                >
                            </div>
                        </div>
                    </div>

                    <div class="min-w-0 text-sm text-slate-500">
                        <div class="flex items-center gap-2">
                            <Lucide
                                icon="IdCard"
                                class="h-4 w-4 shrink-0 text-primary"
                            />
                            <span class="truncate">{{
                                documentLabels[
                                    arrival.id_document_type ?? ''
                                ] ?? 'Documento'
                            }}</span>
                        </div>
                        <div class="mt-1 text-xs">
                            <span
                                v-if="arrival.documents.length"
                                class="text-success"
                                >{{ arrival.documents.length }}
                                {{
                                    arrival.documents.length === 1
                                        ? 'foto'
                                        : 'fotos'
                                }}</span
                            >
                            <span
                                v-else-if="!canViewDocuments"
                                class="text-slate-400"
                                >Fotos con permiso</span
                            >
                            <span v-else class="text-slate-400">Sin fotos</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-500 dark:bg-darkmode-400"
                        >
                            <Lucide icon="DoorClosed" class="h-5 w-5" />
                        </div>
                        <div>
                            <div class="text-sm font-medium">
                                {{
                                    arrival.room
                                        ? `Habitación ${arrival.room}`
                                        : 'Sin habitación'
                                }}
                            </div>
                            <div class="text-xs text-slate-500">
                                {{ money(arrival.amount) }}
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 lg:justify-end">
                        <Button
                            :as="Link"
                            :href="route('tenant.vehicles.arrival', arrival.id)"
                            variant="outline-secondary"
                            class="min-h-10 flex-1 rounded-[0.5rem] whitespace-nowrap lg:flex-none"
                        >
                            <Lucide icon="Eye" class="mr-2 h-4 w-4" />
                            Ver ficha
                        </Button>
                    </div>
                </article>
            </div>
            <div
                v-else
                class="flex flex-col items-center gap-3 py-12 text-center"
            >
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"
                >
                    <Lucide icon="Footprints" class="h-6 w-6" />
                </div>
                <p class="max-w-md px-6 text-sm text-slate-500">
                    Aquí aparecen las llegadas a pie: las que se registran con
                    identificación en vez de placa.
                </p>
            </div>
        </div>

        <!-- Editar ficha del vehículo -->
        <Dialog :open="editing !== null" size="lg" @close="editing = null">
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="saveEdit">
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                        >
                            <Lucide icon="Car" class="h-5 w-5 text-primary" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                Vehículo {{ editing?.plate }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                La placa no se edita: es la identidad de la
                                ficha.
                            </p>
                        </div>
                    </div>

                    <div class="space-y-4 px-6 py-5">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <FormLabel htmlFor="v-brand">Marca</FormLabel>
                                <FormInput
                                    id="v-brand"
                                    v-model="form.brand"
                                    type="text"
                                    placeholder="Nissan"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="v-model">Modelo</FormLabel>
                                <FormInput
                                    id="v-model"
                                    v-model="form.model"
                                    type="text"
                                    placeholder="Versa"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="v-color">Color</FormLabel>
                                <FormInput
                                    id="v-color"
                                    v-model="form.color"
                                    type="text"
                                    placeholder="Gris"
                                />
                            </div>
                        </div>
                        <div>
                            <FormLabel htmlFor="v-notes">Notas</FormLabel>
                            <FormTextarea
                                id="v-notes"
                                v-model="form.notes"
                                placeholder="Lo que convenga recordar de este vehículo"
                            />
                        </div>
                        <div
                            class="rounded-lg border border-slate-200/70 p-4 dark:border-darkmode-400"
                        >
                            <FormSwitch>
                                <FormSwitch.Input
                                    v-model="form.is_blacklisted"
                                    type="checkbox"
                                />
                                <FormSwitch.Label class="ml-2 text-sm"
                                    >Vetar esta placa</FormSwitch.Label
                                >
                            </FormSwitch>
                            <div v-if="form.is_blacklisted" class="mt-3">
                                <FormLabel htmlFor="v-reason">Motivo</FormLabel>
                                <FormInput
                                    id="v-reason"
                                    v-model="form.blacklist_reason"
                                    type="text"
                                    placeholder="Por qué no se le vuelve a rentar"
                                />
                                <FormHelp
                                    v-if="errors.blacklist_reason"
                                    class="text-danger"
                                    >{{ errors.blacklist_reason }}</FormHelp
                                >
                                <FormHelp v-else
                                    >Al registrar una llegada con esta placa, el
                                    plano avisará.</FormHelp
                                >
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="rounded-[0.5rem]"
                            @click="editing = null"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="rounded-[0.5rem]"
                            :disabled="saving"
                            >{{ saving ? 'Guardando…' : 'Guardar' }}</Button
                        >
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Archivar o eliminar -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide
                        icon="TriangleAlert"
                        class="mx-auto mb-3 h-12 w-12 text-danger"
                    />
                    <h2 class="text-base font-medium">
                        {{
                            deleting?.is_archived
                                ? `¿Eliminar definitivamente ${deleting?.plate}?`
                                : `¿Quitar ${deleting?.plate} del registro?`
                        }}
                    </h2>
                    <p class="mt-2 text-sm text-slate-500">
                        <template v-if="deleting?.is_archived">
                            Se borra la ficha sin vuelta atrás. Las estancias
                            que ya pasaron conservan la placa con la que se
                            registraron.
                        </template>
                        <template v-else-if="deleting?.visits">
                            Esta placa tiene {{ deleting?.visits }}
                            {{
                                deleting?.visits === 1 ? 'entrada' : 'entradas'
                            }}
                            registradas, así que se
                            <strong>archiva</strong> en vez de borrarse: sale
                            del registro y la puedes restaurar cuando quieras.
                        </template>
                        <template v-else>
                            No tiene entradas registradas, así que se elimina de
                            verdad.
                        </template>
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="deleting = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="deleteBusy"
                            @click="confirmDelete"
                        >
                            {{ deleteBusy ? 'Procesando…' : 'Confirmar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
