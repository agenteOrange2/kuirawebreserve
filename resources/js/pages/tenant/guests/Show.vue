<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
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

/** Una visita: la reserva con su estancia fundida, o un walk-in suelto. */
interface HistoryRow {
    key: string;
    code: string | null;
    kind: 'reservation' | 'walk_in';
    room: string | null;
    starts_at: string;
    ends_at: string;
    year: number;
    status: string;
    status_label: string;
    upcoming: boolean;
    amount: number;
    consumos: number;
    checked_in_at: string | null;
    checked_out_at: string | null;
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
    history: {
        upcoming: HistoryRow[];
        years: {
            year: number;
            visits: number;
            total: number;
            rows: HistoryRow[];
        }[];
        shown: number;
        total: number;
    };
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
const hasHistory = computed(() => props.history.total > 0);

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
    'rounded-lg border border-slate-200/60 bg-slate-50/60 p-3 dark:border-darkmode-400 dark:bg-darkmode-700/40';

// ── Historial ─────────────────────────────────────────────────
const rowClass = (row: HistoryRow) =>
    [
        'flex flex-wrap items-start justify-between gap-3 rounded-lg border px-3.5 py-2.5',
        row.upcoming
            ? 'border-primary/20 bg-primary/[0.03]'
            : 'border-slate-200/60 bg-slate-50/60 dark:border-darkmode-400 dark:bg-darkmode-700/40',
    ].join(' ');

const statusClass = (row: HistoryRow) => {
    if (row.status === 'cancelled' || row.status === 'no_show') {
        return 'bg-danger/10 text-danger';
    }
    if (row.status === 'checked_in' || row.status === 'active') {
        return 'bg-success/10 text-success';
    }
    if (row.upcoming) {
        return 'bg-primary/10 text-primary';
    }
    return 'bg-slate-100 text-slate-600 dark:bg-darkmode-400 dark:text-slate-300';
};

const historyBadge = computed(() =>
    props.history.total > props.history.shown
        ? `${props.history.shown} de ${props.history.total}`
        : String(props.history.total),
);

// El historial completo de este huésped ya vive en la búsqueda de
// reservas; aquí solo se asoman las últimas.
const fullHistoryHref = computed(
    () =>
        `${route('tenant.reservations.history')}?q=${encodeURIComponent(props.guest.full_name)}`,
);
</script>

<template>
    <RazeLayout :title="guest.full_name">
        <div class="mt-2">
            <!-- Volver: pastilla con forma de control, no un botón más entre
                 las acciones de la ficha. -->
            <Link
                :href="route('tenant.guests')"
                class="mb-2.5 inline-flex h-8 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
            >
                <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                Volver a huéspedes
            </Link>

            <!-- Encabezado en franjas: quién es y su contacto, los avisos que
                 cambian cómo se le atiende, y sus cifras. -->
            <div class="box box--stacked overflow-hidden">
                <div
                    class="flex flex-col gap-4 p-5 md:flex-row md:items-start md:justify-between"
                >
                    <div class="flex min-w-0 gap-3.5">
                        <div
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-sm font-semibold text-white shadow-md"
                        >
                            {{ initials(guest.full_name) }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-base font-medium">
                                    {{ guest.full_name }}
                                </h1>
                                <span
                                    v-if="metrics.active_stay"
                                    class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-[11px] font-medium text-success"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full bg-success"
                                    />
                                    En casa
                                </span>
                                <span
                                    v-if="guest.is_blacklisted"
                                    class="inline-flex items-center gap-1 rounded-full bg-danger/10 px-2.5 py-1 text-[11px] font-medium text-danger"
                                >
                                    <Lucide
                                        icon="ShieldAlert"
                                        class="h-3.5 w-3.5"
                                    />
                                    Lista negra
                                </span>
                                <span
                                    v-if="guest.is_archived"
                                    class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                >
                                    <Lucide
                                        icon="Archive"
                                        class="h-3.5 w-3.5"
                                    />
                                    Archivado
                                </span>
                            </div>
                            <!-- Contacto en pastillas: cada dato se lee solo y
                                 el teléfono y el correo se pueden tocar. -->
                            <div
                                class="mt-2 flex flex-wrap items-center gap-1.5"
                            >
                                <a
                                    v-if="guest.phone"
                                    :href="`tel:${guest.phone}`"
                                    class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 transition hover:bg-primary/10 hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                    title="Llamar al huésped"
                                >
                                    <Lucide
                                        icon="Phone"
                                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                    />
                                    <span class="truncate">
                                        {{ guest.phone }}
                                    </span>
                                </a>
                                <a
                                    v-if="guest.email"
                                    :href="`mailto:${guest.email}`"
                                    class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 transition hover:bg-primary/10 hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                    title="Escribir al huésped"
                                >
                                    <Lucide
                                        icon="Mail"
                                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                    />
                                    <span class="truncate">
                                        {{ guest.email }}
                                    </span>
                                </a>
                                <span
                                    v-if="!guest.phone && !guest.email"
                                    class="text-xs text-slate-400"
                                >
                                    Sin teléfono ni correo registrados
                                </span>
                                <span class="text-xs text-slate-400">
                                    Alta {{ guest.created_at }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div
                        class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:shrink-0 md:flex-wrap md:items-center md:gap-2"
                    >
                        <Button
                            v-if="canManage && guest.is_archived"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                            :disabled="restoreBusy"
                            @click="submitRestore"
                        >
                            <Lucide
                                icon="ArchiveRestore"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            {{ restoreBusy ? 'Restaurando...' : 'Restaurar' }}
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
                            class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                            title="Abre una nueva reserva con los datos de este huésped precargados"
                        >
                            <Lucide
                                icon="CalendarPlus"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Reservar de nuevo
                        </Button>
                        <Button
                            v-if="canManage && !guest.is_archived"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs"
                            @click="showEdit = true"
                        >
                            <Lucide icon="Pencil" class="mr-1.5 h-3.5 w-3.5" />
                            Editar perfil
                        </Button>
                        <Menu v-if="canManage && !guest.is_archived">
                            <Menu.Button
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-100 dark:border-darkmode-400 dark:bg-darkmode-600 dark:hover:bg-darkmode-400"
                                title="Más acciones"
                            >
                                <Lucide
                                    icon="EllipsisVertical"
                                    class="h-4 w-4"
                                />
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
                                        class="mr-1.5 h-3.5 w-3.5"
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
                    class="flex items-start gap-2 border-t border-slate-200/60 bg-slate-100/70 px-5 py-3 text-xs dark:border-darkmode-400 dark:bg-darkmode-600"
                >
                    <Lucide
                        icon="Archive"
                        class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-500"
                    />
                    <span class="text-slate-600 dark:text-slate-300">
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
                    class="flex items-start gap-2 border-t border-slate-200/60 bg-danger/5 px-5 py-3 text-xs dark:border-darkmode-400"
                >
                    <Lucide
                        icon="ShieldAlert"
                        class="mt-0.5 h-3.5 w-3.5 shrink-0 text-danger"
                    />
                    <span class="text-slate-600 dark:text-slate-300">
                        <span class="font-medium text-danger">
                            En lista negra:
                        </span>
                        {{ guest.blacklist_reason }}
                    </span>
                </div>

                <!-- Cifras del huésped: antes eran cuatro cajas que empujaban
                     la ficha hacia abajo. -->
                <div
                    class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-slate-200/60 bg-slate-50/70 px-5 py-3 text-xs dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <span
                        class="inline-flex items-center gap-1.5 text-slate-500"
                    >
                        <Lucide
                            icon="BedDouble"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                        >
                            {{ metrics.visits }}
                        </span>
                        {{ metrics.visits === 1 ? 'visita' : 'visitas' }}
                    </span>
                    <span
                        class="hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400"
                    />
                    <span
                        class="inline-flex items-center gap-1.5 text-slate-500"
                    >
                        <Lucide
                            icon="Wallet"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                        >
                            {{ money(metrics.total_spent) }}
                        </span>
                        gastado
                    </span>
                    <span
                        class="hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400"
                    />
                    <span
                        class="inline-flex items-center gap-1.5 text-slate-500"
                    >
                        <Lucide
                            icon="CalendarClock"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                        >
                            {{ metrics.last_visit ?? 'Sin visitas' }}
                        </span>
                        <template v-if="metrics.last_visit">
                            última visita
                        </template>
                    </span>
                    <span
                        v-if="metrics.cancellations || metrics.no_shows"
                        class="inline-flex items-center gap-1.5 rounded-full bg-danger/10 px-2.5 py-1 text-[11px] font-medium text-danger md:ml-auto"
                        title="Reservas canceladas y veces que no llegó"
                    >
                        <Lucide icon="Ban" class="h-3.5 w-3.5" />
                        {{ metrics.cancellations }} canceló ·
                        {{ metrics.no_shows }} no llegó
                    </span>
                    <span
                        v-else
                        class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-[11px] font-medium text-success md:ml-auto"
                    >
                        <Lucide icon="CircleCheck" class="h-3.5 w-3.5" />
                        Sin cancelaciones
                    </span>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-12 gap-5">
                <!-- Columna izquierda: la ficha completa en una tarjeta, con
                 sus secciones separadas por una línea. -->
                <div class="col-span-12 xl:col-span-5">
                    <div class="box box--stacked">
                        <!-- Contacto -->
                        <div class="p-4">
                            <div class="mb-3.5 flex items-center gap-2.5">
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                                >
                                    <Lucide icon="User" class="h-4 w-4" />
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
                            <dl class="grid gap-2.5 sm:grid-cols-2">
                                <div :class="detailItemClass">
                                    <dt
                                        class="flex items-center gap-1.5 text-xs text-slate-500"
                                    >
                                        <Lucide
                                            icon="Phone"
                                            class="h-3.5 w-3.5 text-primary"
                                        />
                                        Teléfono
                                    </dt>
                                    <dd class="mt-1 text-sm font-medium">
                                        {{ guest.phone ?? '—' }}
                                    </dd>
                                </div>
                                <div :class="detailItemClass">
                                    <dt
                                        class="flex items-center gap-1.5 text-xs text-slate-500"
                                    >
                                        <Lucide
                                            icon="Mail"
                                            class="h-3.5 w-3.5 text-info"
                                        />
                                        Correo electrónico
                                    </dt>
                                    <dd
                                        class="mt-1 text-sm font-medium break-all"
                                    >
                                        {{ guest.email ?? '—' }}
                                    </dd>
                                </div>
                                <div :class="detailItemClass">
                                    <dt
                                        class="flex items-center gap-1.5 text-xs text-slate-500"
                                    >
                                        <Lucide
                                            icon="Cake"
                                            class="h-3.5 w-3.5 text-warning"
                                        />
                                        Nacimiento
                                    </dt>
                                    <dd class="mt-1 text-sm font-medium">
                                        {{ guest.birth_date ?? '—' }}
                                    </dd>
                                </div>
                                <div :class="detailItemClass">
                                    <dt
                                        class="flex items-center gap-1.5 text-xs text-slate-500"
                                    >
                                        <Lucide
                                            icon="Flag"
                                            class="h-3.5 w-3.5 text-success"
                                        />
                                        Nacionalidad
                                    </dt>
                                    <dd class="mt-1 text-sm font-medium">
                                        {{ guest.nationality ?? '—' }}
                                    </dd>
                                </div>
                                <div
                                    :class="detailItemClass"
                                    class="sm:col-span-2"
                                >
                                    <dt
                                        class="flex items-center gap-1.5 text-xs text-slate-500"
                                    >
                                        <Lucide
                                            icon="MapPin"
                                            class="h-3.5 w-3.5 text-danger"
                                        />
                                        Dirección
                                    </dt>
                                    <dd class="mt-1 text-sm font-medium">
                                        {{ address || '—' }}
                                    </dd>
                                </div>
                                <div
                                    :class="detailItemClass"
                                    class="sm:col-span-2"
                                >
                                    <dt
                                        class="flex items-center gap-1.5 text-xs text-slate-500"
                                    >
                                        <Lucide
                                            icon="Megaphone"
                                            class="h-3.5 w-3.5 text-pending"
                                        />
                                        Mensajes promocionales
                                    </dt>
                                    <dd class="mt-1 text-sm font-medium">
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
                                class="mt-3 rounded-lg border border-pending/20 bg-pending/5 p-3.5"
                            >
                                <div
                                    class="mb-1.5 flex items-center gap-2 text-xs font-medium"
                                >
                                    <Lucide
                                        icon="StickyNote"
                                        class="h-3.5 w-3.5 text-pending"
                                    />
                                    Notas para el personal
                                </div>
                                <p
                                    class="text-xs leading-relaxed whitespace-pre-line text-slate-600 dark:text-slate-300"
                                >
                                    {{ guest.notes }}
                                </p>
                            </div>
                        </div>

                        <!-- Identificación -->
                        <div
                            v-if="canViewDocuments"
                            class="border-t border-slate-200/60 p-4 dark:border-darkmode-400"
                        >
                            <div class="mb-3.5 flex items-center gap-2.5">
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                                >
                                    <Lucide icon="IdCard" class="h-4 w-4" />
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
                            <dl class="grid gap-2.5 sm:grid-cols-2">
                                <div :class="detailItemClass">
                                    <dt class="text-xs text-slate-500">Tipo</dt>
                                    <dd class="mt-1 text-sm font-medium">
                                        {{
                                            guest.id_document_type
                                                ? docLabels[
                                                      guest.id_document_type
                                                  ]
                                                : '—'
                                        }}
                                    </dd>
                                </div>
                                <div :class="detailItemClass">
                                    <dt class="text-xs text-slate-500">
                                        Número
                                    </dt>
                                    <dd
                                        class="mt-1 font-mono text-sm font-medium"
                                    >
                                        {{ guest.id_document_number ?? '—' }}
                                    </dd>
                                </div>
                            </dl>
                            <div
                                v-if="documents.length"
                                class="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-3"
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
                                        class="h-24 w-full rounded-lg border border-slate-200 object-cover transition hover:border-primary/40 dark:border-darkmode-400"
                                    />
                                </a>
                            </div>
                            <p
                                v-else
                                class="mt-3 flex items-center gap-2.5 rounded-lg border border-dashed border-slate-300/70 px-3.5 py-3 text-xs text-slate-400 dark:border-darkmode-400"
                            >
                                <Lucide icon="Camera" class="h-4 w-4" /> Sin
                                fotos del documento. Agrégalas al editar el
                                perfil.
                            </p>
                        </div>

                        <!-- Vehículo -->
                        <div
                            v-if="canViewDocuments"
                            class="border-t border-slate-200/60 p-4 dark:border-darkmode-400"
                        >
                            <div class="mb-3.5 flex items-center gap-2.5">
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                                >
                                    <Lucide icon="Car" class="h-4 w-4" />
                                </div>
                                <div>
                                    <h2 class="text-base font-medium">
                                        Vehículo
                                    </h2>
                                    <p class="text-xs text-slate-500">
                                        Datos para reconocerlo en el acceso.
                                    </p>
                                </div>
                            </div>
                            <template v-if="hasVehicle">
                                <dl class="grid gap-2.5">
                                    <div :class="detailItemClass">
                                        <dt class="text-xs text-slate-500">
                                            Placa
                                        </dt>
                                        <dd class="mt-1 text-sm">
                                            <span
                                                class="rounded-md bg-slate-100 px-2 py-0.5 font-mono font-medium uppercase dark:bg-darkmode-400"
                                                >{{
                                                    vehicle?.plate ?? '—'
                                                }}</span
                                            >
                                        </dd>
                                    </div>
                                    <div
                                        v-if="vehicleSummary"
                                        :class="detailItemClass"
                                    >
                                        <dt class="text-xs text-slate-500">
                                            Vehículo
                                        </dt>
                                        <dd class="mt-1 text-sm font-medium">
                                            {{ vehicleSummary }}
                                        </dd>
                                    </div>
                                    <div
                                        v-if="vehicle?.notes"
                                        :class="detailItemClass"
                                    >
                                        <dt class="text-xs text-slate-500">
                                            Detalle
                                        </dt>
                                        <dd class="mt-1 text-sm">
                                            {{ vehicle.notes }}
                                        </dd>
                                    </div>
                                </dl>
                                <div
                                    v-if="vehiclePhotos.length"
                                    class="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-3"
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
                                            class="h-24 w-full rounded-lg border border-slate-200 object-cover transition hover:border-primary/40 dark:border-darkmode-400"
                                        />
                                    </a>
                                </div>
                            </template>
                            <p
                                v-else
                                class="flex items-center gap-2.5 rounded-lg border border-dashed border-slate-300/70 px-3.5 py-3 text-xs text-slate-400 dark:border-darkmode-400"
                            >
                                <Lucide icon="Car" class="h-4 w-4" /> Sin
                                vehículo registrado. Agrégalo al editar el
                                perfil.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Columna derecha: un solo historial. La reserva y su
                 estancia son la misma noche: van en la misma fila, con la
                 habitación real, la hora de llegada y sus consumos. -->
                <div class="col-span-12 xl:col-span-7">
                    <div class="box box--stacked">
                        <div
                            class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                            >
                                <Lucide icon="History" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Historial del huésped
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Lo próximo, y lo que ya pasó agrupado por
                                    año.
                                </p>
                            </div>
                            <div class="ml-auto flex items-center gap-2">
                                <span
                                    v-if="history.total"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                                >
                                    {{ historyBadge }}
                                </span>
                                <Button
                                    v-if="history.total > history.shown"
                                    :as="Link"
                                    :href="fullHistoryHref"
                                    variant="outline-secondary"
                                    class="h-8 rounded-[0.5rem] bg-white text-xs"
                                >
                                    <Lucide
                                        icon="ChevronRight"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Ver todo
                                </Button>
                            </div>
                        </div>

                        <!-- Lo que viene -->
                        <div v-if="history.upcoming.length" class="px-4 py-3">
                            <div
                                class="mb-2 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide
                                    icon="CalendarDays"
                                    class="h-3.5 w-3.5 text-primary"
                                />
                                Próximas
                            </div>
                            <div class="space-y-1.5">
                                <article
                                    v-for="row in history.upcoming"
                                    :key="row.key"
                                    :class="rowClass(row)"
                                >
                                    <div class="min-w-0">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <span
                                                class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium dark:bg-darkmode-400"
                                            >
                                                Hab.
                                                {{ row.room ?? 'por asignar' }}
                                            </span>
                                            <span
                                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                :class="statusClass(row)"
                                            >
                                                {{ row.status_label }}
                                            </span>
                                        </div>
                                        <div class="mt-0.5 text-xs">
                                            {{ row.starts_at }}
                                            <span class="text-slate-400"
                                                >→</span
                                            >
                                            {{ row.ends_at }}
                                        </div>
                                        <div
                                            v-if="row.code"
                                            class="text-xs text-slate-400"
                                        >
                                            {{ row.code }}
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm font-medium">
                                            {{ money(row.amount) }}
                                        </div>
                                        <div
                                            v-if="row.consumos"
                                            class="text-xs text-slate-500"
                                        >
                                            + {{ money(row.consumos) }} consumos
                                        </div>
                                    </div>
                                </article>
                            </div>
                        </div>

                        <!-- Lo que ya pasó, por año -->
                        <div
                            v-for="group in history.years"
                            :key="group.year"
                            class="border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div
                                class="mb-2 flex flex-wrap items-center gap-2 text-xs text-slate-500"
                            >
                                <span
                                    class="text-xs font-medium text-slate-600 dark:text-slate-300"
                                    >{{ group.year }}</span
                                >
                                <span
                                    >· {{ group.visits }}
                                    {{
                                        group.visits === 1
                                            ? 'estancia'
                                            : 'estancias'
                                    }}</span
                                >
                                <span class="ml-auto font-medium">{{
                                    money(group.total)
                                }}</span>
                            </div>
                            <div class="space-y-1.5">
                                <article
                                    v-for="row in group.rows"
                                    :key="row.key"
                                    :class="rowClass(row)"
                                >
                                    <div class="min-w-0">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <span
                                                class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium dark:bg-darkmode-400"
                                            >
                                                Hab. {{ row.room ?? '—' }}
                                            </span>
                                            <span
                                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                :class="statusClass(row)"
                                            >
                                                {{ row.status_label }}
                                            </span>
                                            <span
                                                v-if="row.kind === 'walk_in'"
                                                class="text-xs text-slate-400"
                                                >Llegó sin reserva</span
                                            >
                                        </div>
                                        <div class="mt-0.5 text-xs">
                                            {{ row.starts_at }}
                                            <span class="text-slate-400"
                                                >→</span
                                            >
                                            {{ row.ends_at }}
                                            <span
                                                v-if="row.checked_in_at"
                                                class="text-xs text-slate-400"
                                            >
                                                · entró {{ row.checked_in_at
                                                }}<template
                                                    v-if="row.checked_out_at"
                                                    >, salió
                                                    {{
                                                        row.checked_out_at
                                                    }}</template
                                                >
                                            </span>
                                        </div>
                                        <div
                                            v-if="row.code"
                                            class="text-xs text-slate-400"
                                        >
                                            {{ row.code }}
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm font-medium">
                                            {{ money(row.amount) }}
                                        </div>
                                        <div
                                            v-if="row.consumos"
                                            class="text-xs text-slate-500"
                                        >
                                            + {{ money(row.consumos) }} consumos
                                        </div>
                                    </div>
                                </article>
                            </div>
                        </div>

                        <div
                            v-if="!history.total"
                            class="flex flex-col items-center gap-2.5 py-10 text-center text-xs text-slate-500"
                        >
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Lucide icon="History" class="h-5 w-5" />
                            </div>
                            Todavía no se hospeda ni tiene reservas.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal archivar/eliminar -->
        <Dialog size="lg" :open="deleting" @close="deleting = false">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                            :class="
                                hasHistory
                                    ? 'bg-primary/10 text-primary'
                                    : 'bg-danger/10 text-danger'
                            "
                        >
                            <Lucide
                                :icon="hasHistory ? 'Archive' : 'Trash2'"
                                class="h-5 w-5"
                            />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                {{
                                    hasHistory
                                        ? `¿Archivar a ${guest.full_name}?`
                                        : `¿Eliminar a ${guest.full_name}?`
                                }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
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
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 px-5 text-xs"
                            @click="deleting = false"
                            >Cancelar</Button
                        >
                        <Button
                            :variant="hasHistory ? 'primary' : 'danger'"
                            class="h-9 px-5 text-xs"
                            :disabled="deleteBusy"
                            @click="submitDelete"
                        >
                            <Lucide
                                :icon="hasHistory ? 'Archive' : 'Trash2'"
                                class="mr-1.5 h-3.5 w-3.5"
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
