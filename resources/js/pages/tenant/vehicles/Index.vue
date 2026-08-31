<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormLabel,
    FormSelect,
    FormSwitch,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog, Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

/**
 * Registro de vehículos, con la misma estructura que Reservas grupales:
 * cabecera compacta, contadores en fila, y una sola tarjeta con el buscador
 * arriba y renglones divididos abajo. Dos pestañas porque son las dos formas
 * de entrar a una caseta: en vehículo (placa) o a pie (identificación).
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
    from: number | null;
    to: number | null;
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
const tab = ref(props.tab);

// Los tres filtros de placa viven en un solo selector: en la práctica no se
// combinan (una placa vetada Y adentro es una incidencia, no una búsqueda) y
// así el renglón de filtros cabe junto al buscador, como en grupos.
type Scope = '' | 'inside' | 'blacklisted' | 'archived';

const scope = ref<Scope>(
    props.filters.archived
        ? 'archived'
        : props.filters.blacklisted
          ? 'blacklisted'
          : props.filters.inside
            ? 'inside'
            : '',
);

const filtersActive = computed(
    () => q.value.trim() !== '' || scope.value !== '',
);

function clearFilters(): void {
    q.value = '';
    scope.value = '';
}

/** El paginador de la pestaña que se está viendo. */
const listed = computed<Paginated<VehicleRow | ArrivalRow> | null>(() =>
    tab.value === 'vehiculos' ? props.vehicles : props.arrivals,
);

// Mismo patrón que el CRM de huéspedes: debounce y recarga parcial por
// Inertia, sin endpoint aparte.
let timer: ReturnType<typeof setTimeout> | null = null;

watch([q, scope, tab], () => {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => {
        const enVehiculos = tab.value === 'vehiculos';
        const activo = (valor: Scope) => enVehiculos && scope.value === valor;

        router.get(
            route('tenant.vehicles'),
            {
                q: q.value || undefined,
                inside: activo('inside') ? 1 : undefined,
                blacklisted: activo('blacklisted') ? 1 : undefined,
                archived: activo('archived') ? 1 : undefined,
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
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Car" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Registro de vehículos
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Quién entra a la caseta: por placa o, si llegan a
                            pie, con su identificación.
                        </p>
                    </div>
                </div>
                <!-- Las dos formas de entrar son la navegación de la página, no
                     un filtro más: van en la cabecera. -->
                <div
                    class="grid w-full grid-cols-2 gap-1 rounded-[0.6rem] bg-slate-100/80 p-1 md:flex md:w-auto md:items-center dark:bg-darkmode-700"
                >
                    <button
                        type="button"
                        class="h-7 rounded-[0.45rem] px-3 text-xs font-medium whitespace-nowrap transition"
                        :class="
                            tab === 'vehiculos'
                                ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                : 'text-slate-500'
                        "
                        @click="tab = 'vehiculos'"
                    >
                        Vehículos ({{ counts.vehicles }})
                    </button>
                    <button
                        type="button"
                        class="h-7 rounded-[0.45rem] px-3 text-xs font-medium whitespace-nowrap transition"
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
            </div>

            <div class="mt-4 grid grid-cols-12 gap-4">
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Car" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ counts.vehicles }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Placas registradas
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="DoorClosed" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ counts.inside }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Adentro ahora
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                    >
                        <Lucide icon="Footprints" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">{{ counts.foot }}</div>
                        <div class="truncate text-xs text-slate-500">
                            Llegadas a pie
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="Archive" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ counts.archived }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Placas archivadas
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
                            :icon="tab === 'pie' ? 'Footprints' : 'Car'"
                            class="h-4 w-4 text-slate-400"
                        />
                        {{
                            tab === 'pie'
                                ? 'Llegadas a pie'
                                : 'Placas registradas'
                        }}
                        <span
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-darkmode-400"
                        >
                            {{ listed?.total ?? 0 }}
                        </span>
                    </div>
                    <div
                        class="ml-auto flex items-center gap-2 text-xs text-slate-500"
                    >
                        <span v-if="listed?.data.length">
                            Mostrando {{ listed.from }}-{{ listed.to }} de
                            {{ listed.total }}
                        </span>
                        <button
                            v-if="filtersActive"
                            type="button"
                            class="font-medium text-primary hover:underline"
                            @click="clearFilters"
                        >
                            Limpiar filtros
                        </button>
                    </div>
                </div>

                <div
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
                                {{
                                    tab === 'pie'
                                        ? 'Encuentra una llegada a pie'
                                        : 'Encuentra un vehículo'
                                }}
                            </div>
                            <div class="text-xs text-slate-500">
                                {{
                                    tab === 'pie'
                                        ? 'Busca por el nombre con el que se registró.'
                                        : 'Busca por placa, marca, modelo o color. Da igual cómo escribas la placa.'
                                }}
                            </div>
                        </div>
                    </div>
                    <div
                        class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-[minmax(15rem,1.5fr)_14rem_auto]"
                    >
                        <div>
                            <FormLabel htmlFor="vehicle-search">
                                Búsqueda rápida
                            </FormLabel>
                            <div class="relative">
                                <Lucide
                                    icon="Search"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                />
                                <FormInput
                                    id="vehicle-search"
                                    v-model="q"
                                    type="search"
                                    class="h-9 pl-9 text-xs"
                                    :placeholder="
                                        tab === 'pie'
                                            ? 'Nombre del huésped'
                                            : 'Placa, marca o modelo'
                                    "
                                />
                            </div>
                        </div>
                        <div v-if="tab === 'vehiculos'">
                            <FormLabel htmlFor="vehicle-scope">
                                Mostrar
                            </FormLabel>
                            <FormSelect
                                id="vehicle-scope"
                                v-model="scope"
                                class="h-9 text-xs"
                            >
                                <option value="">Todas las placas</option>
                                <option value="inside">Adentro ahora</option>
                                <option value="blacklisted">Vetadas</option>
                                <option value="archived">
                                    Archivadas ({{ counts.archived }})
                                </option>
                            </FormSelect>
                        </div>
                        <div class="flex items-end">
                            <Button
                                v-if="filtersActive"
                                type="button"
                                variant="outline-secondary"
                                class="h-9 w-full text-xs whitespace-nowrap xl:w-auto"
                                @click="clearFilters"
                            >
                                <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                                Limpiar
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- ── Pestaña de vehículos ── -->
                <template v-if="tab === 'vehiculos'">
                    <div
                        v-if="vehicles && vehicles.data.length"
                        class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                    >
                        <article
                            v-for="vehicle in vehicles.data"
                            :key="vehicle.id"
                            class="grid gap-3 px-4 py-3 sm:px-5 lg:grid-cols-[minmax(13rem,1.3fr)_minmax(10rem,1fr)_minmax(9rem,0.7fr)_auto] lg:items-center"
                        >
                            <div class="flex min-w-0 items-center gap-2.5">
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                                >
                                    <Lucide icon="Car" class="h-4 w-4" />
                                </div>
                                <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <Link
                                            :href="
                                                route(
                                                    'tenant.vehicles.show',
                                                    vehicle.id,
                                                )
                                            "
                                            class="text-sm font-semibold tracking-wide text-primary hover:underline"
                                        >
                                            {{ vehicle.plate }}
                                        </Link>
                                        <span
                                            v-if="vehicle.is_inside"
                                            class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success"
                                        >
                                            <Lucide
                                                icon="DoorClosed"
                                                class="h-3.5 w-3.5"
                                            />
                                            Adentro
                                        </span>
                                        <span
                                            v-if="vehicle.is_blacklisted"
                                            class="inline-flex items-center gap-1 rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                            :title="
                                                vehicle.blacklist_reason ??
                                                undefined
                                            "
                                        >
                                            <Lucide
                                                icon="ShieldAlert"
                                                class="h-3.5 w-3.5"
                                            />
                                            Vetada
                                        </span>
                                        <span
                                            v-if="vehicle.is_archived"
                                            class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                        >
                                            <Lucide
                                                icon="Archive"
                                                class="h-3.5 w-3.5"
                                            />
                                            Archivada
                                        </span>
                                    </div>
                                    <div
                                        class="mt-0.5 truncate text-xs text-slate-500"
                                    >
                                        Registrada {{ vehicle.created_at }}
                                    </div>
                                </div>
                            </div>

                            <div class="min-w-0">
                                <div
                                    class="flex items-center gap-1.5 text-[11px] font-medium text-slate-400"
                                >
                                    <Lucide icon="Car" class="h-3.5 w-3.5" />
                                    VEHÍCULO
                                </div>
                                <div
                                    class="mt-0.5 truncate text-xs font-medium"
                                >
                                    {{ vehicle.label ?? 'Sin datos' }}
                                </div>
                                <div
                                    v-if="vehicle.notes"
                                    class="truncate text-xs text-slate-500"
                                    :title="vehicle.notes"
                                >
                                    {{ vehicle.notes }}
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
                                    ENTRADAS
                                </div>
                                <div class="mt-0.5 text-xs font-medium">
                                    {{ vehicle.visits }}
                                    {{
                                        vehicle.visits === 1
                                            ? 'entrada'
                                            : 'entradas'
                                    }}
                                </div>
                                <div class="truncate text-xs text-slate-500">
                                    {{
                                        vehicle.last_seen_at
                                            ? `Última ${vehicle.last_seen_at}`
                                            : 'Sin entradas'
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
                                            'tenant.vehicles.show',
                                            vehicle.id,
                                        )
                                    "
                                    variant="outline-primary"
                                    class="h-9 flex-1 rounded-[0.5rem] text-xs whitespace-nowrap lg:flex-none"
                                >
                                    <Lucide
                                        icon="Eye"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Ver ficha
                                </Button>
                                <Menu v-if="canManage">
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
                                            v-if="!vehicle.is_archived"
                                            as="button"
                                            type="button"
                                            @click="openEdit(vehicle)"
                                        >
                                            <Lucide
                                                icon="Pencil"
                                                class="mr-1.5 h-3.5 w-3.5"
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
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            Archivar o eliminar
                                        </Menu.Item>
                                        <Menu.Item
                                            v-if="vehicle.is_archived"
                                            as="button"
                                            type="button"
                                            :disabled="
                                                restoringId === vehicle.id
                                            "
                                            @click="restoreVehicle(vehicle)"
                                        >
                                            <Lucide
                                                icon="ArchiveRestore"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            {{
                                                restoringId === vehicle.id
                                                    ? 'Restaurando...'
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
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            Eliminar definitivamente
                                        </Menu.Item>
                                    </Menu.Items>
                                </Menu>
                            </div>
                        </article>
                    </div>

                    <div
                        v-else-if="filtersActive"
                        class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                    >
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                        >
                            <Lucide icon="SearchX" class="h-7 w-7" />
                        </div>
                        <div>
                            <p class="text-sm font-medium">
                                Ninguna placa coincide con la búsqueda
                            </p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Cambia el texto o vuelve a "Todas las placas".
                            </p>
                        </div>
                        <Button
                            variant="outline-secondary"
                            @click="clearFilters"
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
                            <Lucide icon="Car" class="h-7 w-7" />
                        </div>
                        <div>
                            <p class="text-sm font-medium">
                                Todavía no hay placas registradas
                            </p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Se van llenando solas conforme registras
                                llegadas en el plano.
                            </p>
                        </div>
                    </div>
                </template>

                <!-- ── Pestaña de llegadas a pie ── -->
                <template v-else>
                    <div
                        v-if="arrivals && arrivals.data.length"
                        class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                    >
                        <article
                            v-for="arrival in arrivals.data"
                            :key="arrival.id"
                            class="grid gap-3 px-4 py-3 sm:px-5 lg:grid-cols-[minmax(13rem,1.3fr)_minmax(10rem,1fr)_minmax(9rem,0.7fr)_auto] lg:items-center"
                        >
                            <div class="flex min-w-0 items-center gap-2.5">
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                                >
                                    <Lucide icon="Footprints" class="h-4 w-4" />
                                </div>
                                <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <Link
                                            :href="
                                                route(
                                                    'tenant.vehicles.arrival',
                                                    arrival.id,
                                                )
                                            "
                                            class="truncate text-sm font-medium text-primary hover:underline"
                                        >
                                            {{ arrival.guest_name }}
                                        </Link>
                                        <span
                                            v-if="arrival.status === 'active'"
                                            class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success"
                                        >
                                            <Lucide
                                                icon="DoorClosed"
                                                class="h-3.5 w-3.5"
                                            />
                                            Adentro
                                        </span>
                                    </div>
                                    <div
                                        class="mt-0.5 truncate text-xs text-slate-500"
                                    >
                                        Entró {{ arrival.check_in_at }}
                                    </div>
                                </div>
                            </div>

                            <div class="min-w-0">
                                <div
                                    class="flex items-center gap-1.5 text-[11px] font-medium text-slate-400"
                                >
                                    <Lucide icon="IdCard" class="h-3.5 w-3.5" />
                                    IDENTIFICACIÓN
                                </div>
                                <div
                                    class="mt-0.5 truncate text-xs font-medium"
                                >
                                    {{
                                        documentLabels[
                                            arrival.id_document_type ?? ''
                                        ] ?? 'Documento'
                                    }}
                                </div>
                                <div class="text-xs text-slate-500">
                                    <span
                                        v-if="arrival.documents.length"
                                        class="text-success"
                                    >
                                        {{ arrival.documents.length }}
                                        {{
                                            arrival.documents.length === 1
                                                ? 'foto'
                                                : 'fotos'
                                        }}
                                    </span>
                                    <span v-else-if="!canViewDocuments">
                                        Fotos con permiso
                                    </span>
                                    <span v-else>Sin fotos</span>
                                </div>
                            </div>

                            <div>
                                <div
                                    class="flex items-center gap-1.5 text-[11px] font-medium text-slate-400"
                                >
                                    <Lucide
                                        icon="DoorClosed"
                                        class="h-3.5 w-3.5"
                                    />
                                    HABITACIÓN
                                </div>
                                <div class="mt-0.5 text-xs font-medium">
                                    {{ arrival.room ?? 'Sin habitación' }}
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ money(arrival.amount) }}
                                </div>
                            </div>

                            <div
                                class="flex items-center gap-1.5 lg:justify-end"
                            >
                                <Button
                                    :as="Link"
                                    :href="
                                        route(
                                            'tenant.vehicles.arrival',
                                            arrival.id,
                                        )
                                    "
                                    variant="outline-primary"
                                    class="h-9 flex-1 rounded-[0.5rem] text-xs whitespace-nowrap lg:flex-none"
                                >
                                    <Lucide
                                        icon="Eye"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Ver ficha
                                </Button>
                            </div>
                        </article>
                    </div>

                    <div
                        v-else
                        class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                    >
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-info/10 text-info"
                        >
                            <Lucide
                                :icon="filtersActive ? 'SearchX' : 'Footprints'"
                                class="h-7 w-7"
                            />
                        </div>
                        <div>
                            <p class="text-sm font-medium">
                                {{
                                    filtersActive
                                        ? 'Ninguna llegada coincide con la búsqueda'
                                        : 'Sin llegadas a pie registradas'
                                }}
                            </p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Aquí aparecen las que se registran con
                                identificación en vez de placa.
                            </p>
                        </div>
                        <Button
                            v-if="filtersActive"
                            variant="outline-secondary"
                            @click="clearFilters"
                        >
                            <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                            Limpiar filtros
                        </Button>
                    </div>
                </template>

                <!-- Paginación: la lista trae quince fichas por vuelta -->
                <div
                    v-if="listed && listed.links.length > 3"
                    class="flex flex-wrap justify-center gap-1 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <template v-for="(link, i) in listed.links" :key="i">
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

        <!-- Editar ficha del vehículo -->
        <Dialog :open="editing !== null" size="lg" @close="editing = null">
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="saveEdit">
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Car" class="h-4 w-4" />
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
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="!h-9 !w-9 shrink-0 rounded-full !p-0"
                            title="Cerrar"
                            @click="editing = null"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </Button>
                    </div>

                    <div class="space-y-4 px-5 py-4">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <FormLabel htmlFor="v-brand">Marca</FormLabel>
                                <FormInput
                                    id="v-brand"
                                    v-model="form.brand"
                                    type="text"
                                    class="h-9 text-xs"
                                    placeholder="Nissan"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="v-model">Modelo</FormLabel>
                                <FormInput
                                    id="v-model"
                                    v-model="form.model"
                                    type="text"
                                    class="h-9 text-xs"
                                    placeholder="Versa"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="v-color">Color</FormLabel>
                                <FormInput
                                    id="v-color"
                                    v-model="form.color"
                                    type="text"
                                    class="h-9 text-xs"
                                    placeholder="Gris"
                                />
                            </div>
                        </div>
                        <div>
                            <FormLabel htmlFor="v-notes">Notas</FormLabel>
                            <FormTextarea
                                id="v-notes"
                                v-model="form.notes"
                                rows="3"
                                placeholder="Lo que convenga recordar de este vehículo"
                            />
                        </div>
                        <div
                            class="rounded-lg border border-slate-200/70 p-3.5 dark:border-darkmode-400"
                        >
                            <FormSwitch>
                                <FormSwitch.Input
                                    v-model="form.is_blacklisted"
                                    type="checkbox"
                                />
                                <FormSwitch.Label class="ml-2 text-sm">
                                    Vetar esta placa
                                </FormSwitch.Label>
                            </FormSwitch>
                            <div v-if="form.is_blacklisted" class="mt-3">
                                <FormLabel htmlFor="v-reason">Motivo</FormLabel>
                                <FormInput
                                    id="v-reason"
                                    v-model="form.blacklist_reason"
                                    type="text"
                                    class="h-9 text-xs"
                                    placeholder="Por qué no se le vuelve a rentar"
                                />
                                <FormHelp
                                    v-if="errors.blacklist_reason"
                                    class="text-danger"
                                >
                                    {{ errors.blacklist_reason }}
                                </FormHelp>
                                <FormHelp v-else>
                                    Al registrar una llegada con esta placa, el
                                    plano avisará.
                                </FormHelp>
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-4 text-xs"
                            @click="editing = null"
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-4 text-xs"
                            :disabled="saving"
                        >
                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                            {{ saving ? 'Guardando...' : 'Guardar' }}
                        </Button>
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
                        class="mx-auto mb-3 h-10 w-10 text-danger"
                    />
                    <h2 class="text-base font-medium">
                        {{
                            deleting?.is_archived
                                ? `¿Eliminar definitivamente ${deleting?.plate}?`
                                : `¿Quitar ${deleting?.plate} del registro?`
                        }}
                    </h2>
                    <p class="mt-2 text-xs text-slate-500">
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
                            class="h-9 rounded-[0.5rem] px-4 text-xs"
                            @click="deleting = null"
                        >
                            Cancelar
                        </Button>
                        <Button
                            variant="danger"
                            class="h-9 rounded-[0.5rem] px-4 text-xs"
                            :disabled="deleteBusy"
                            @click="confirmDelete"
                        >
                            {{ deleteBusy ? 'Procesando...' : 'Confirmar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
