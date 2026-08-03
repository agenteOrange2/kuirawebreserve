<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import Button from '@/components/Base/Button';
import { Dialog, Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import GuestFormModal from './GuestFormModal.vue';

interface MediaItem {
    id: number;
    name: string;
    url: string;
}
interface Vehicle {
    plate?: string | null;
    brand?: string | null;
    model?: string | null;
    color?: string | null;
    year?: number | null;
    notes?: string | null;
}
interface GuestData {
    id: number;
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    phone: string | null;
    email: string | null;
    birth_date: string | null;
    nationality: string | null;
    address: string | null;
    city: string | null;
    state: string | null;
    zip: string | null;
    id_document_type: string | null;
    id_document_number: string | null;
    notes: string | null;
    is_blacklisted: boolean;
    blacklist_reason: string | null;
    marketing_consent: boolean;
    created_at: string;
    is_archived: boolean;
    archived_at: string | null;
}

const props = defineProps<{
    guest: GuestData;
    metrics: {
        visits: number;
        active_stay: boolean;
        total_spent: number;
        last_visit: string | null;
        cancellations: number;
        no_shows: number;
    };
    documents: MediaItem[];
    vehicle: Vehicle | null;
    vehiclePhotos: MediaItem[];
    stays: {
        id: number;
        room: string | null;
        rate_plan: string | null;
        check_in_at: string;
        check_out_at: string | null;
        status: string;
        amount: string;
        consumos: number;
    }[];
    reservations: {
        id: number;
        room: string | null;
        starts_at: string;
        ends_at: string;
        status: string;
        status_label: string;
        total_amount: string;
        is_upcoming: boolean;
    }[];
    canManage: boolean;
    canReserve: boolean;
    canViewDocuments: boolean;
    documentTypes: string[];
}>();

const toast = useToasts();
const showEdit = ref(false);
function onSaved() {
    showEdit.value = false;
    router.reload();
}

// Con historial (reservas/estancias) el backend ARCHIVA en vez de borrar,
// así que el modal avisa desde antes qué va a pasar.
const hasHistory = computed(
    () => props.stays.length > 0 || props.reservations.length > 0,
);

const deleting = ref(false);
const deleteBusy = ref(false);
const deleteError = ref<string | null>(null);

async function submitDelete() {
    deleteBusy.value = true;
    deleteError.value = null;
    try {
        const { data } = await axios.delete(`/api/guests/${props.guest.id}`);
        if (data?.archived) {
            toast.success('Huésped archivado', data.message);
        } else {
            toast.success(
                'Huésped eliminado',
                `${props.guest.full_name} se eliminó del directorio.`,
            );
        }
        router.visit(route('tenant.guests'));
    } catch (error: any) {
        deleteError.value =
            error.response?.data?.message ?? 'No se pudo eliminar el huésped.';
    } finally {
        deleteBusy.value = false;
    }
}

// Restaurar un perfil archivado al directorio.
const restoreBusy = ref(false);

async function submitRestore() {
    restoreBusy.value = true;
    try {
        await axios.post(`/api/guests/${props.guest.id}/restore`);
        toast.success(
            'Huésped restaurado',
            `${props.guest.full_name} vuelve a aparecer en el directorio.`,
        );
        router.reload();
    } catch (error: any) {
        toast.error(
            'No se pudo restaurar',
            error.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        restoreBusy.value = false;
    }
}

onMounted(() => {
    if (
        props.canManage &&
        !props.guest.is_archived &&
        new URLSearchParams(window.location.search).get('edit')
    ) {
        showEdit.value = true;
    }
});

const docLabels: Record<string, string> = {
    ine: 'INE',
    pasaporte: 'Pasaporte',
    licencia: 'Licencia',
    otro: 'Otro',
};

const initials = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join('') || '?';

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n ?? 0);

const address = [
    props.guest.address,
    props.guest.city,
    props.guest.state,
    props.guest.zip,
]
    .filter(Boolean)
    .join(', ');
const vehicleSummary = props.vehicle
    ? [
          props.vehicle.brand,
          props.vehicle.model,
          props.vehicle.year,
          props.vehicle.color,
      ]
          .filter(Boolean)
          .join(' · ')
    : '';
const hasVehicle =
    !!props.vehicle &&
    (!!props.vehicle.plate ||
        !!props.vehicle.brand ||
        !!props.vehicle.model ||
        !!props.vehicle.color ||
        !!props.vehicle.year ||
        !!props.vehicle.notes);
const detailItemClass =
    'rounded-lg border border-slate-200/60 bg-slate-50/60 p-3.5 dark:border-darkmode-400 dark:bg-darkmode-700/40';
const friendlyReservationStatus = (status: string, label: string) =>
    status === 'no_show' ? 'No llegó' : label;
</script>

<template>
    <RazeLayout :title="guest.full_name">
        <div class="grid grid-cols-12 gap-x-6 gap-y-8">
            <!-- Encabezado del perfil -->
            <div class="box box--stacked col-span-12 p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-4">
                        <div
                            class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-lg font-semibold text-white shadow-md"
                        >
                            {{ initials(guest.full_name) }}
                        </div>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-xl font-medium">
                                    {{ guest.full_name }}
                                </h1>
                                <span
                                    v-if="metrics.active_stay"
                                    class="inline-flex items-center gap-2 rounded-full bg-success/10 px-3 py-1 text-sm font-medium text-success"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full bg-success"
                                    />
                                    En casa
                                </span>
                                <span
                                    v-if="guest.is_blacklisted"
                                    class="rounded-full bg-danger/10 px-3 py-1 text-sm font-medium text-danger"
                                    >Lista negra</span
                                >
                                <span
                                    v-if="guest.is_archived"
                                    class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-500 dark:bg-darkmode-400"
                                >
                                    <Lucide icon="Archive" class="h-4 w-4" />
                                    Archivado
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">
                                Huésped registrado desde {{ guest.created_at }}
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            as="a"
                            :href="route('tenant.guests')"
                            variant="outline-secondary"
                            class="min-h-11 rounded-[0.5rem] bg-white"
                        >
                            <Lucide
                                icon="ArrowLeft"
                                class="mr-2 h-5 w-5 stroke-[1.5]"
                            />
                            Huéspedes
                        </Button>
                        <Button
                            v-if="canManage && guest.is_archived"
                            variant="primary"
                            class="min-h-11 rounded-[0.5rem] shadow-md shadow-primary/20"
                            :disabled="restoreBusy"
                            @click="submitRestore"
                        >
                            <Lucide
                                icon="ArchiveRestore"
                                class="mr-2 h-5 w-5 stroke-[1.5]"
                            />
                            {{ restoreBusy ? 'Restaurando…' : 'Restaurar' }}
                        </Button>
                        <Button
                            v-if="
                                canReserve &&
                                !guest.is_blacklisted &&
                                !guest.is_archived
                            "
                            as="a"
                            :href="`${route('tenant.reservations')}?intent=reserve&guest=${guest.id}`"
                            variant="primary"
                            class="min-h-11 rounded-[0.5rem] shadow-md shadow-primary/20"
                            title="Abre una nueva reserva con los datos de este huésped precargados"
                        >
                            <Lucide
                                icon="CalendarPlus"
                                class="mr-2 h-5 w-5 stroke-[1.5]"
                            />
                            Reservar de nuevo
                        </Button>
                        <Button
                            v-if="canManage && !guest.is_archived"
                            variant="outline-primary"
                            class="min-h-11 rounded-[0.5rem] bg-white"
                            @click="showEdit = true"
                        >
                            <Lucide
                                icon="Pencil"
                                class="mr-2 h-5 w-5 stroke-[1.5]"
                            />
                            Editar perfil
                        </Button>
                        <Menu v-if="canManage && !guest.is_archived">
                            <Menu.Button
                                class="flex h-11 w-11 items-center justify-center rounded-[0.5rem] border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-100 dark:border-darkmode-400 dark:bg-darkmode-600 dark:hover:bg-darkmode-400"
                            >
                                <Lucide icon="MoreVertical" class="h-5 w-5" />
                            </Menu.Button>
                            <Menu.Items class="w-48">
                                <Menu.Item
                                    as="button"
                                    type="button"
                                    class="text-danger"
                                    @click="
                                        deleteError = null;
                                        deleting = true;
                                    "
                                >
                                    <Lucide
                                        :icon="
                                            hasHistory ? 'Archive' : 'Trash2'
                                        "
                                        class="mr-2 h-4 w-4"
                                    />
                                    {{
                                        hasHistory
                                            ? 'Archivar huésped'
                                            : 'Eliminar huésped'
                                    }}
                                </Menu.Item>
                            </Menu.Items>
                        </Menu>
                    </div>
                </div>

                <div
                    v-if="guest.is_archived"
                    class="mt-4 flex items-center gap-2 rounded-lg border-l-4 border-l-slate-400 bg-slate-100/70 px-4 py-3 text-sm dark:bg-darkmode-600"
                >
                    <Lucide
                        icon="Archive"
                        class="h-5 w-5 shrink-0 text-slate-500"
                    />
                    <span>
                        <span class="font-medium">Perfil archivado</span>
                        <template v-if="guest.archived_at">
                            el {{ guest.archived_at }}</template
                        >: no aparece en el directorio ni en el buscador de
                        reservas, pero su historial se conserva. Restáuralo para
                        volver a usarlo.
                    </span>
                </div>

                <div
                    v-if="guest.is_blacklisted"
                    class="mt-4 flex items-center gap-2 rounded-lg border-l-4 border-l-danger bg-danger/5 px-4 py-3 text-sm"
                >
                    <Lucide
                        icon="ShieldAlert"
                        class="h-5 w-5 shrink-0 text-danger"
                    />
                    <span
                        ><span class="font-medium text-danger"
                            >En lista negra:</span
                        >
                        {{ guest.blacklist_reason }}</span
                    >
                </div>
            </div>

            <!-- KPIs -->
            <div class="col-span-12">
                <div class="grid grid-cols-12 gap-5">
                    <div class="box box--stacked col-span-6 p-5 xl:col-span-3">
                        <div class="flex items-center justify-between">
                            <div
                                class="flex h-14 w-14 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                            >
                                <Lucide
                                    icon="BedDouble"
                                    class="h-7 w-7 text-primary"
                                />
                            </div>
                            <div class="text-2xl font-medium">
                                {{ metrics.visits }}
                            </div>
                        </div>
                        <div class="mt-4 text-sm font-medium">Visitas</div>
                        <div class="mt-1 text-xs text-slate-500">
                            Estancias completadas
                        </div>
                    </div>
                    <div class="box box--stacked col-span-6 p-5 xl:col-span-3">
                        <div class="flex items-center justify-between">
                            <div
                                class="flex h-14 w-14 items-center justify-center rounded-full border border-success/10 bg-success/10"
                            >
                                <Lucide
                                    icon="DollarSign"
                                    class="h-7 w-7 text-success"
                                />
                            </div>
                            <div class="text-2xl font-medium">
                                {{ money(metrics.total_spent) }}
                            </div>
                        </div>
                        <div class="mt-4 text-sm font-medium">Gasto total</div>
                        <div class="mt-1 text-xs text-slate-500">
                            Hospedaje + consumos
                        </div>
                    </div>
                    <div class="box box--stacked col-span-6 p-5 xl:col-span-3">
                        <div class="flex items-center justify-between">
                            <div
                                class="flex h-14 w-14 items-center justify-center rounded-full border border-info/10 bg-info/10"
                            >
                                <Lucide
                                    icon="CalendarClock"
                                    class="h-7 w-7 text-info"
                                />
                            </div>
                            <div class="text-2xl font-medium">
                                {{ metrics.last_visit ?? '—' }}
                            </div>
                        </div>
                        <div class="mt-4 text-sm font-medium">
                            Última visita
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            Fecha de entrada más reciente
                        </div>
                    </div>
                    <div class="box box--stacked col-span-6 p-5 xl:col-span-3">
                        <div class="flex items-center justify-between">
                            <div
                                class="flex h-14 w-14 items-center justify-center rounded-full border border-danger/10 bg-danger/10"
                            >
                                <Lucide
                                    icon="Ban"
                                    class="h-7 w-7 text-danger"
                                />
                            </div>
                            <div class="text-2xl font-medium">
                                {{ metrics.cancellations }} /
                                {{ metrics.no_shows }}
                            </div>
                        </div>
                        <div class="mt-4 text-sm font-medium">
                            Cancelaciones / No llegó
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            Confiabilidad del huésped
                        </div>
                    </div>
                </div>
            </div>

            <!-- Columna izquierda: datos agrupados -->
            <div class="col-span-12 flex flex-col gap-6 xl:col-span-5">
                <!-- Contacto -->
                <div class="box box--stacked p-5">
                    <div class="mb-5 flex items-center gap-3">
                        <div
                            class="flex h-11 w-11 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="User" class="h-6 w-6" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                Información personal
                            </h2>
                            <p class="text-xs text-slate-500">
                                Contacto, residencia y preferencias.
                            </p>
                        </div>
                    </div>
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div :class="detailItemClass">
                            <dt class="flex items-center gap-2 text-slate-500">
                                <Lucide
                                    icon="Phone"
                                    class="h-5 w-5 text-primary"
                                />
                                Teléfono
                            </dt>
                            <dd class="mt-2 font-medium">
                                {{ guest.phone ?? '—' }}
                            </dd>
                        </div>
                        <div :class="detailItemClass">
                            <dt class="flex items-center gap-2 text-slate-500">
                                <Lucide icon="Mail" class="h-5 w-5 text-info" />
                                Correo electrónico
                            </dt>
                            <dd class="mt-2 font-medium break-all">
                                {{ guest.email ?? '—' }}
                            </dd>
                        </div>
                        <div :class="detailItemClass">
                            <dt class="flex items-center gap-2 text-slate-500">
                                <Lucide
                                    icon="Cake"
                                    class="h-5 w-5 text-warning"
                                />
                                Nacimiento
                            </dt>
                            <dd class="mt-2 font-medium">
                                {{ guest.birth_date ?? '—' }}
                            </dd>
                        </div>
                        <div :class="detailItemClass">
                            <dt class="flex items-center gap-2 text-slate-500">
                                <Lucide
                                    icon="Flag"
                                    class="h-5 w-5 text-success"
                                />
                                Nacionalidad
                            </dt>
                            <dd class="mt-2 font-medium">
                                {{ guest.nationality ?? '—' }}
                            </dd>
                        </div>
                        <div :class="detailItemClass" class="sm:col-span-2">
                            <dt class="flex items-center gap-2 text-slate-500">
                                <Lucide
                                    icon="MapPin"
                                    class="h-5 w-5 text-danger"
                                />
                                Dirección
                            </dt>
                            <dd class="mt-2 font-medium">
                                {{ address || '—' }}
                            </dd>
                        </div>
                        <div :class="detailItemClass" class="sm:col-span-2">
                            <dt class="flex items-center gap-2 text-slate-500">
                                <Lucide
                                    icon="Megaphone"
                                    class="h-5 w-5 text-pending"
                                />
                                Mensajes promocionales
                            </dt>
                            <dd class="mt-2 font-medium">
                                {{
                                    guest.marketing_consent
                                        ? 'Aceptados por el huésped'
                                        : 'No autorizados'
                                }}
                            </dd>
                        </div>
                    </dl>
                    <div
                        v-if="guest.notes"
                        class="mt-4 rounded-lg border border-pending/20 bg-pending/5 p-4"
                    >
                        <div
                            class="mb-2 flex items-center gap-2 text-sm font-medium"
                        >
                            <Lucide
                                icon="StickyNote"
                                class="h-5 w-5 text-pending"
                            />
                            Notas para el personal
                        </div>
                        <p
                            class="text-sm whitespace-pre-line text-slate-600 dark:text-slate-300"
                        >
                            {{ guest.notes }}
                        </p>
                    </div>
                </div>

                <!-- Identificación -->
                <div v-if="canViewDocuments" class="box box--stacked p-5">
                    <div class="mb-5 flex items-center gap-3">
                        <div
                            class="flex h-11 w-11 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                        >
                            <Lucide icon="IdCard" class="h-6 w-6" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                Identificación
                            </h2>
                            <p class="text-xs text-slate-500">
                                Documento registrado y fotografías.
                            </p>
                        </div>
                    </div>
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div :class="detailItemClass">
                            <dt class="text-slate-500">Tipo</dt>
                            <dd class="mt-2 font-medium">
                                {{
                                    guest.id_document_type
                                        ? docLabels[guest.id_document_type]
                                        : '—'
                                }}
                            </dd>
                        </div>
                        <div :class="detailItemClass">
                            <dt class="text-slate-500">Número</dt>
                            <dd class="mt-2 font-mono font-medium">
                                {{ guest.id_document_number ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                    <div
                        v-if="documents.length"
                        class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3"
                    >
                        <a
                            v-for="doc in documents"
                            :key="doc.id"
                            :href="doc.url"
                            target="_blank"
                            class="block"
                        >
                            <img
                                :src="doc.url"
                                class="h-28 w-full rounded-lg border border-slate-200 object-cover transition hover:border-primary/40 dark:border-darkmode-400"
                            />
                        </a>
                    </div>
                    <p
                        v-else
                        class="mt-4 flex items-center gap-3 rounded-lg border border-dashed border-slate-300/70 px-4 py-4 text-sm text-slate-400 dark:border-darkmode-400"
                    >
                        <Lucide icon="Camera" class="h-5 w-5" /> Sin fotos del
                        documento. Agrégalas al editar el perfil.
                    </p>
                </div>

                <!-- Vehículo -->
                <div v-if="canViewDocuments" class="box box--stacked p-5">
                    <div class="mb-5 flex items-center gap-3">
                        <div
                            class="flex h-11 w-11 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                        >
                            <Lucide icon="Car" class="h-6 w-6" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">Vehículo</h2>
                            <p class="text-xs text-slate-500">
                                Datos para reconocerlo en el acceso.
                            </p>
                        </div>
                    </div>
                    <template v-if="hasVehicle">
                        <dl class="grid gap-3 text-sm">
                            <div :class="detailItemClass">
                                <dt class="text-slate-500">Placa</dt>
                                <dd class="mt-2">
                                    <span
                                        class="rounded-md bg-slate-100 px-2 py-0.5 font-mono font-medium uppercase dark:bg-darkmode-400"
                                        >{{ vehicle?.plate ?? '—' }}</span
                                    >
                                </dd>
                            </div>
                            <div v-if="vehicleSummary" :class="detailItemClass">
                                <dt class="text-slate-500">Vehículo</dt>
                                <dd class="mt-2 font-medium">
                                    {{ vehicleSummary }}
                                </dd>
                            </div>
                            <div v-if="vehicle?.notes" :class="detailItemClass">
                                <dt class="text-slate-500">Detalle</dt>
                                <dd class="mt-2">{{ vehicle.notes }}</dd>
                            </div>
                        </dl>
                        <div
                            v-if="vehiclePhotos.length"
                            class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3"
                        >
                            <a
                                v-for="p in vehiclePhotos"
                                :key="p.id"
                                :href="p.url"
                                target="_blank"
                                class="block"
                            >
                                <img
                                    :src="p.url"
                                    class="h-28 w-full rounded-lg border border-slate-200 object-cover transition hover:border-primary/40 dark:border-darkmode-400"
                                />
                            </a>
                        </div>
                    </template>
                    <p
                        v-else
                        class="flex items-center gap-3 rounded-lg border border-dashed border-slate-300/70 px-4 py-4 text-sm text-slate-400 dark:border-darkmode-400"
                    >
                        <Lucide icon="Car" class="h-5 w-5" /> Sin vehículo
                        registrado. Agrégalo al editar el perfil.
                    </p>
                </div>
            </div>

            <!-- Columna derecha: historial -->
            <div class="col-span-12 flex flex-col gap-6 xl:col-span-7">
                <div class="box box--stacked">
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/60 p-5 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="DoorOpen" class="h-6 w-6" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                Historial de estancias
                            </h2>
                            <p class="text-xs text-slate-500">
                                Habitaciones usadas y consumos registrados.
                            </p>
                        </div>
                        <span
                            v-if="stays.length"
                            class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                            >{{ stays.length }}</span
                        >
                    </div>
                    <div class="overflow-auto p-5 lg:overflow-visible">
                        <Table v-if="stays.length">
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>Hab.</Table.Th>
                                    <Table.Th>Entrada</Table.Th>
                                    <Table.Th>Salida</Table.Th>
                                    <Table.Th class="text-right"
                                        >Hospedaje</Table.Th
                                    >
                                    <Table.Th class="text-right"
                                        >Consumos</Table.Th
                                    >
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr
                                    v-for="s in stays"
                                    :key="s.id"
                                    class="[&>td]:py-4"
                                >
                                    <Table.Td class="font-medium">{{
                                        s.room
                                    }}</Table.Td>
                                    <Table.Td
                                        class="text-sm whitespace-nowrap text-slate-500"
                                        >{{ s.check_in_at }}</Table.Td
                                    >
                                    <Table.Td
                                        class="text-sm whitespace-nowrap text-slate-500"
                                    >
                                        {{ s.check_out_at ?? '—' }}
                                        <span
                                            v-if="s.status === 'active'"
                                            class="ml-1 rounded-full bg-success/10 px-1.5 text-xs text-success"
                                            >Alojado ahora</span
                                        >
                                    </Table.Td>
                                    <Table.Td class="text-right font-medium"
                                        >${{ s.amount }}</Table.Td
                                    >
                                    <Table.Td
                                        class="text-right text-slate-500"
                                        >{{
                                            s.consumos
                                                ? `$${s.consumos.toFixed(2)}`
                                                : '—'
                                        }}</Table.Td
                                    >
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>
                        <div
                            v-else
                            class="flex flex-col items-center gap-3 py-10 text-center text-sm text-slate-500"
                        >
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Lucide icon="BedDouble" class="h-6 w-6" />
                            </div>
                            Sin estancias registradas.
                        </div>
                    </div>
                </div>

                <div class="box box--stacked">
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/60 p-5 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                        >
                            <Lucide icon="CalendarDays" class="h-6 w-6" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                Historial de reservas
                            </h2>
                            <p class="text-xs text-slate-500">
                                Próximas, completadas y canceladas.
                            </p>
                        </div>
                        <span
                            v-if="reservations.length"
                            class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                            >{{ reservations.length }}</span
                        >
                    </div>
                    <div class="overflow-auto p-5 lg:overflow-visible">
                        <Table v-if="reservations.length">
                            <Table.Tbody>
                                <Table.Tr
                                    v-for="r in reservations"
                                    :key="r.id"
                                    class="[&>td]:py-4"
                                >
                                    <Table.Td class="font-medium">{{
                                        r.room ?? '—'
                                    }}</Table.Td>
                                    <Table.Td
                                        class="text-sm whitespace-nowrap text-slate-500"
                                        >{{ r.starts_at }} →
                                        {{ r.ends_at }}</Table.Td
                                    >
                                    <Table.Td class="text-right font-medium"
                                        >${{ r.total_amount }}</Table.Td
                                    >
                                    <Table.Td class="text-right">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="
                                                r.is_upcoming
                                                    ? 'bg-primary/10 text-primary'
                                                    : r.status ===
                                                            'cancelled' ||
                                                        r.status === 'no_show'
                                                      ? 'bg-danger/10 text-danger'
                                                      : 'bg-slate-100 text-slate-600 dark:bg-darkmode-400'
                                            "
                                        >
                                            {{
                                                friendlyReservationStatus(
                                                    r.status,
                                                    r.status_label,
                                                )
                                            }}
                                        </span>
                                    </Table.Td>
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>
                        <div
                            v-else
                            class="flex flex-col items-center gap-3 py-10 text-center text-sm text-slate-500"
                        >
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-full bg-info/10 text-info"
                            >
                                <Lucide icon="CalendarDays" class="h-6 w-6" />
                            </div>
                            Sin reservas registradas.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal archivar/eliminar -->
        <Dialog size="lg" :open="deleting" @close="deleting = false">
            <Dialog.Panel>
                <div class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full"
                            :class="
                                hasHistory
                                    ? 'bg-primary/10 text-primary'
                                    : 'bg-danger/10 text-danger'
                            "
                        >
                            <Lucide
                                :icon="hasHistory ? 'Archive' : 'Trash2'"
                                class="h-7 w-7"
                            />
                        </div>
                        <div>
                            <h2 class="text-lg font-medium">
                                {{
                                    hasHistory
                                        ? `¿Archivar a ${guest.full_name}?`
                                        : `¿Eliminar a ${guest.full_name}?`
                                }}
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                {{
                                    hasHistory
                                        ? 'Tiene historial de reservas o estancias, así que no se borra: se archiva y desaparece del directorio y del buscador.'
                                        : 'Se borran su ficha, fotos de INE y vehículo. Esta acción no se puede deshacer.'
                                }}
                            </p>
                        </div>
                    </div>
                    <div
                        v-if="hasHistory"
                        class="mt-4 flex items-center gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                    >
                        <Lucide icon="Info" class="h-4 w-4 shrink-0" /> Su
                        historial se conserva y podrás restaurarlo cuando
                        quieras desde Huéspedes, con el filtro Archivados.
                    </div>
                    <p
                        v-if="deleteError"
                        class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                    >
                        {{ deleteError }}
                    </p>
                    <div class="mt-6 flex justify-end gap-3">
                        <Button
                            variant="outline-secondary"
                            class="min-h-11 px-5"
                            @click="deleting = false"
                            >Cancelar</Button
                        >
                        <Button
                            :variant="hasHistory ? 'primary' : 'danger'"
                            class="min-h-11 px-5"
                            :disabled="deleteBusy"
                            @click="submitDelete"
                        >
                            <Lucide
                                :icon="hasHistory ? 'Archive' : 'Trash2'"
                                class="mr-2 h-5 w-5"
                            />
                            {{
                                deleteBusy
                                    ? hasHistory
                                        ? 'Archivando…'
                                        : 'Eliminando…'
                                    : hasHistory
                                      ? 'Sí, archivar'
                                      : 'Sí, eliminar'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal editar huésped -->
        <GuestFormModal
            :open="showEdit"
            :guest="guest"
            :document-types="documentTypes"
            :can-view-documents="canViewDocuments"
            :documents="documents"
            :vehicle-photos="vehiclePhotos"
            :vehicle="vehicle"
            @close="showEdit = false"
            @saved="onSaved"
        />
    </RazeLayout>
</template>
