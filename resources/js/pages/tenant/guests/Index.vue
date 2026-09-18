<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormCheck, FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog, Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import GuestFormModal from './GuestFormModal.vue';

interface GuestRow {
    id: number;
    full_name: string;
    phone: string | null;
    email: string | null;
    visits: number;
    /** Hospedaje + consumos de todo lo que ya se usó. */
    total_spent: number;
    /** Cuándo vino la última vez (estancia o reserva completada). */
    last_visit: string | null;
    /** Llegada de su próxima reserva viva, si trae alguna. */
    next_arrival: string | null;
    is_blacklisted: boolean;
    is_archived: boolean;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    guests: {
        data: GuestRow[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    archivedCount: number;
    /** Cifras del directorio completo (no dependen del filtro activo). */
    stats: {
        total: number;
        upcoming: number;
        blacklisted: number;
        archived: number;
    };
    filters: {
        q: string;
        blacklisted: boolean;
        upcoming: boolean;
        archived: boolean;
        sort: string;
    };
    canManage: boolean;
    canViewDocuments: boolean;
    documentTypes: string[];
}>();

const toast = useToasts();
const q = ref(props.filters.q);
const blacklisted = ref(props.filters.blacklisted);
const archived = ref(props.filters.archived);
const upcomingOnly = ref(props.filters.upcoming);
const sort = ref(props.filters.sort || 'recent');
// El orden NO es un filtro: si contara, "Limpiar" aparecía solo por haber
// cambiado cómo se ordena la lista.
const filtersActive = computed(
    () =>
        q.value.trim() !== '' ||
        blacklisted.value ||
        upcomingOnly.value ||
        archived.value,
);

const money = (value: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value) || 0);

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const sectionLabel =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
const rowAction =
    'flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition';

function clearFilters(): void {
    q.value = '';
    blacklisted.value = false;
    upcomingOnly.value = false;
    archived.value = false;
    sort.value = 'recent';
}

/** Las tarjetas de arriba son filtros: tocarlas acota la lista. */
function applyCard(card: 'all' | 'upcoming' | 'blacklisted' | 'archived') {
    q.value = '';
    blacklisted.value = card === 'blacklisted';
    upcomingOnly.value = card === 'upcoming';
    archived.value = card === 'archived';
}

/** Enlace de descarga del CSV con los filtros que se estén viendo. */
const exportHref = computed(() => {
    const params = new URLSearchParams();
    if (q.value.trim()) params.set('q', q.value.trim());
    if (blacklisted.value) params.set('blacklisted', '1');
    if (upcomingOnly.value) params.set('upcoming', '1');
    if (archived.value) params.set('archived', '1');
    if (sort.value !== 'recent') params.set('sort', sort.value);
    const query = params.toString();

    return route('tenant.guests.export') + (query ? `?${query}` : '');
});

let timer: ReturnType<typeof setTimeout> | null = null;
watch([q, blacklisted, upcomingOnly, archived, sort], () => {
    selectedIds.value = [];
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            route('tenant.guests'),
            {
                q: q.value || undefined,
                blacklisted: blacklisted.value || undefined,
                upcoming: upcomingOnly.value || undefined,
                archived: archived.value || undefined,
                sort: sort.value !== 'recent' ? sort.value : undefined,
            },
            {
                preserveState: true,
                replace: true,
                only: ['guests', 'archivedCount', 'stats', 'filters'],
            },
        );
    }, 350);
});

const showCreate = ref(false);

function onSaved(id: number) {
    showCreate.value = false;
    router.visit(route('tenant.guests.show', id));
}

// Eliminar (con confirmación; con historial el backend archiva en vez
// de borrar, y aquí se avisa con un toast).
const deleting = ref<GuestRow | null>(null);
const deleteBusy = ref(false);
const deleteError = ref<string | null>(null);

function askDelete(g: GuestRow) {
    deleteError.value = null;
    deleting.value = g;
}

async function submitDelete() {
    if (!deleting.value) return;
    deleteBusy.value = true;
    deleteError.value = null;
    try {
        const { data } = await axios.delete(`/api/guests/${deleting.value.id}`);
        if (data?.archived) {
            toast.success('Huésped archivado', data.message);
        } else if (deleting.value.is_archived) {
            toast.success(
                'Huésped eliminado',
                `${deleting.value.full_name} se eliminó definitivamente; su historial queda sin huésped vinculado.`,
            );
        } else {
            toast.success(
                'Huésped eliminado',
                `${deleting.value.full_name} se eliminó del directorio.`,
            );
        }
        deleting.value = null;
        router.reload({ only: ['guests', 'archivedCount', 'stats'] });
    } catch (error: any) {
        deleteError.value =
            error.response?.data?.message ?? 'No se pudo eliminar el huésped.';
    } finally {
        deleteBusy.value = false;
    }
}

// Restaurar un huésped archivado al directorio.
const restoringId = ref<number | null>(null);

async function restoreGuest(g: GuestRow) {
    restoringId.value = g.id;
    try {
        await axios.post(`/api/guests/${g.id}/restore`);
        toast.success(
            'Huésped restaurado',
            `${g.full_name} vuelve a aparecer en el directorio.`,
        );
        router.reload({ only: ['guests', 'archivedCount', 'stats'] });
    } catch (error: any) {
        toast.error(
            'No se pudo restaurar',
            error.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        restoringId.value = null;
    }
}

// ── Selección múltiple (opera sobre la página visible; el backend
// archiva los que tienen historial y elimina el resto; en la vista de
// archivados todo se elimina definitivamente) ──
const selectedIds = ref<number[]>([]);
const bulkDeleteOpen = ref(false);
const bulkDeleting = ref(false);

const allSelected = computed(
    () =>
        props.guests.data.length > 0 &&
        props.guests.data.every((g) => selectedIds.value.includes(g.id)),
);
const selectedRows = computed(() =>
    props.guests.data.filter((g) => selectedIds.value.includes(g.id)),
);

function toggleRow(id: number) {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((x) => x !== id)
        : [...selectedIds.value, id];
}
function toggleAll() {
    selectedIds.value = allSelected.value
        ? []
        : props.guests.data.map((g) => g.id);
}

async function bulkDelete() {
    bulkDeleting.value = true;
    try {
        const { data } = await axios.delete('/api/guests', {
            data: { ids: selectedIds.value },
        });
        toast.success(
            'Listo',
            archived.value
                ? `${data.deleted} huésped(es) eliminado(s) definitivamente.`
                : `${data.deleted} eliminado(s)` +
                      (data.archived
                          ? ` · ${data.archived} archivado(s) por tener historial (restaurables)`
                          : ''),
        );
        selectedIds.value = [];
        bulkDeleteOpen.value = false;
        router.reload({ only: ['guests', 'archivedCount', 'stats'] });
    } catch (error: any) {
        toast.error(
            'No se pudo eliminar',
            error.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        bulkDeleting.value = false;
    }
}

// Al cambiar de página la selección anterior ya no se ve, pero el botón
// seguía diciendo "3 seleccionados" y borraba gente que no estaba en
// pantalla. Se limpia al cambiar el contenido de la lista.
watch(
    () => props.guests.data.map((g) => g.id).join(','),
    () => {
        selectedIds.value = [];
    },
);

/**
 * Liga de WhatsApp con el número normalizado: guardado como
 * "+52 614 586 9225", wa.me solo acepta dígitos. Diez dígitos se asumen
 * mexicanos (es el lada del hotel).
 */
const whatsappHref = (phone: string) => {
    const digits = phone.replace(/\D+/g, '');
    const full = digits.length === 10 ? `52${digits}` : digits;

    return `https://wa.me/${full}`;
};

const initials = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join('') || '?';
</script>

<template>
    <RazeLayout title="Huéspedes">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Users" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Directorio de huéspedes
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Contacto, visitas e historial de quien ya se ha
                            hospedado aquí.
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Button
                        as="a"
                        :href="exportHref"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide icon="Download" class="mr-1.5 h-3.5 w-3.5" />
                        Exportar CSV
                    </Button>
                    <Button
                        v-if="canManage"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        @click="showCreate = true"
                    >
                        <Lucide icon="UserPlus" class="mr-1.5 h-3.5 w-3.5" />
                        Nuevo huésped
                    </Button>
                </div>
            </div>

            <!-- Las cifras del directorio son filtros: tocarlas acota la lista -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">El directorio</span>
                <span class="hidden text-[11px] text-slate-400 sm:inline">
                    Toca una cifra para filtrar la lista
                </span>
            </div>
            <div class="mt-2 grid auto-rows-fr grid-cols-12 gap-4">
                <button
                    type="button"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 text-left transition hover:border-primary/30 xl:col-span-3"
                    :class="!filtersActive ? 'border-primary/30' : ''"
                    @click="applyCard('all')"
                >
                    <div
                        :class="[
                            sectionIcon,
                            'border-primary/10 bg-primary/10 text-primary',
                        ]"
                    >
                        <Lucide icon="Users" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">{{ stats.total }}</div>
                        <div class="text-xs leading-tight text-slate-500">
                            En el directorio
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            Se dan de alta solos al reservar
                        </div>
                    </div>
                </button>
                <button
                    type="button"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 text-left transition hover:border-primary/30 xl:col-span-3"
                    :class="upcomingOnly ? 'border-primary/30' : ''"
                    @click="applyCard('upcoming')"
                >
                    <div
                        :class="[
                            sectionIcon,
                            'border-info/10 bg-info/10 text-info',
                        ]"
                    >
                        <Lucide icon="CalendarClock" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.upcoming }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Con llegada próxima
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            Traen algo apartado
                        </div>
                    </div>
                </button>
                <button
                    type="button"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 text-left transition hover:border-primary/30 xl:col-span-3"
                    :class="blacklisted ? 'border-danger/30' : ''"
                    @click="applyCard('blacklisted')"
                >
                    <div
                        :class="[
                            sectionIcon,
                            stats.blacklisted
                                ? 'border-danger/10 bg-danger/10 text-danger'
                                : 'border-slate-200 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400',
                        ]"
                    >
                        <Lucide icon="ShieldAlert" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.blacklisted }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            En lista negra
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            No se les vuelve a rentar
                        </div>
                    </div>
                </button>
                <button
                    type="button"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 text-left transition hover:border-primary/30 xl:col-span-3"
                    :class="archived ? 'border-primary/30' : ''"
                    @click="applyCard('archived')"
                >
                    <div
                        :class="[
                            sectionIcon,
                            'border-pending/10 bg-pending/10 text-pending',
                        ]"
                    >
                        <Lucide icon="Archive" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.archived }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Archivados
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            Fuera del directorio, con su historial
                        </div>
                    </div>
                </button>
            </div>

            <!-- Buscador y resultados en la misma caja: eran dos cajas y el
                 filtro quedaba lejos de la lista que filtra. -->
            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div
                        :class="[
                            sectionIcon,
                            'border-primary/10 bg-primary/10 text-primary',
                        ]"
                    >
                        <Lucide icon="Users" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{
                                archived ? 'Huéspedes archivados' : 'Huéspedes'
                            }}
                            <span class="text-xs font-normal text-slate-500">
                                · {{ guests.total }}
                            </span>
                        </div>
                        <div class="text-xs text-slate-500">
                            {{
                                upcomingOnly
                                    ? 'Solo los que traen una llegada apartada'
                                    : blacklisted
                                      ? 'Solo los de la lista negra'
                                      : 'Contacto, visitas y lo que ha dejado cada quien'
                            }}
                        </div>
                    </div>
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <FormSelect
                            v-model="sort"
                            class="h-8 w-40 text-xs"
                            title="Cómo se ordena la lista"
                        >
                            <option value="recent">Más recientes</option>
                            <option value="name">Nombre (A-Z)</option>
                            <option value="visits">Más visitas</option>
                            <option value="spent">Más han dejado</option>
                        </FormSelect>
                        <Button
                            as="a"
                            :href="exportHref"
                            variant="outline-secondary"
                            class="h-8 rounded-[0.5rem] bg-white text-xs"
                            title="Descarga la lista tal como la estás viendo"
                        >
                            <Lucide
                                icon="Download"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Exportar CSV
                        </Button>
                    </div>
                </div>

                <!-- Buscador y filtros, en franja gris pegada a la lista -->
                <div
                    class="border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="mb-2.5 flex flex-wrap items-center gap-2">
                        <span :class="sectionLabel">Encuentra un huésped</span>
                        <span
                            class="hidden text-[11px] text-slate-400 sm:inline"
                        >
                            Nombre completo, teléfono (como sea que lo escribas)
                            o correo
                        </span>
                    </div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <div class="relative w-full min-w-0 sm:w-80">
                            <Lucide
                                icon="Search"
                                class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                            />
                            <FormInput
                                v-model="q"
                                type="search"
                                placeholder="Nombre, teléfono o correo"
                                class="h-9 pl-9 text-xs"
                            />
                        </div>
                        <label
                            class="flex h-9 cursor-pointer items-center gap-2 rounded-[0.5rem] border px-3 text-xs font-medium transition"
                            :class="
                                upcomingOnly
                                    ? 'border-info/30 bg-info/5 text-info'
                                    : 'border-slate-200/70 bg-white text-slate-500 hover:bg-slate-50 dark:border-darkmode-400 dark:bg-darkmode-600'
                            "
                        >
                            <FormCheck.Input
                                id="f-upcoming"
                                v-model="upcomingOnly"
                                type="checkbox"
                                class="!mt-0"
                            />
                            <Lucide icon="CalendarClock" class="h-3.5 w-3.5" />
                            Con llegada
                        </label>
                        <label
                            class="flex h-9 cursor-pointer items-center gap-2 rounded-[0.5rem] border px-3 text-xs font-medium transition"
                            :class="
                                blacklisted
                                    ? 'border-danger/30 bg-danger/5 text-danger'
                                    : 'border-slate-200/70 bg-white text-slate-500 hover:bg-slate-50 dark:border-darkmode-400 dark:bg-darkmode-600'
                            "
                        >
                            <FormCheck.Input
                                id="f-blacklist"
                                v-model="blacklisted"
                                type="checkbox"
                                class="!mt-0"
                            />
                            <Lucide icon="ShieldAlert" class="h-3.5 w-3.5" />
                            Lista negra
                        </label>
                        <label
                            v-if="archivedCount > 0 || archived"
                            class="flex h-9 cursor-pointer items-center gap-2 rounded-[0.5rem] border px-3 text-xs font-medium transition"
                            :class="
                                archived
                                    ? 'border-primary/30 bg-primary/5 text-primary'
                                    : 'border-slate-200/70 bg-white text-slate-500 hover:bg-slate-50 dark:border-darkmode-400 dark:bg-darkmode-600'
                            "
                        >
                            <FormCheck.Input
                                id="f-archived"
                                v-model="archived"
                                type="checkbox"
                                class="!mt-0"
                            />
                            <Lucide icon="Archive" class="h-3.5 w-3.5" />
                            Archivados ({{ archivedCount }})
                        </label>
                        <button
                            v-if="filtersActive"
                            type="button"
                            class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 text-xs font-medium text-slate-500 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                            @click="clearFilters"
                        >
                            <Lucide icon="X" class="h-3.5 w-3.5" />
                            Limpiar
                        </button>
                        <label
                            v-if="canManage && guests.data.length"
                            class="ml-auto flex cursor-pointer items-center gap-2 text-xs text-slate-500"
                        >
                            <FormCheck.Input
                                type="checkbox"
                                :checked="allSelected"
                                @change="toggleAll"
                            />
                            Seleccionar esta página
                        </label>
                    </div>
                </div>

                <div
                    v-if="canManage && selectedIds.length"
                    class="flex flex-wrap items-center gap-2 border-b border-slate-200/60 bg-primary/5 px-4 py-2.5 dark:border-darkmode-400"
                >
                    <span class="text-xs text-slate-600 dark:text-slate-300">
                        {{ selectedIds.length }}
                        {{
                            selectedIds.length === 1
                                ? 'seleccionado'
                                : 'seleccionados'
                        }}
                    </span>
                    <div class="ml-auto flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-8 bg-white text-xs"
                            @click="selectedIds = []"
                        >
                            Quitar selección
                        </Button>
                        <Button
                            variant="danger"
                            class="h-8 rounded-[0.5rem] text-xs"
                            @click="bulkDeleteOpen = true"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                archived
                                    ? 'Eliminar definitivamente'
                                    : 'Eliminar seleccionados'
                            }}
                        </Button>
                    </div>
                </div>

                <div
                    v-if="guests.data.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <article
                        v-for="g in guests.data"
                        :key="g.id"
                        class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-2 px-4 py-3 transition hover:bg-slate-50/70 sm:px-5 lg:grid-cols-[auto_minmax(12rem,1.2fr)_minmax(10rem,1fr)_minmax(9rem,0.8fr)_minmax(7rem,auto)_auto] dark:hover:bg-darkmode-700/30"
                    >
                        <FormCheck.Input
                            v-if="canManage"
                            type="checkbox"
                            :checked="selectedIds.includes(g.id)"
                            @change="toggleRow(g.id)"
                        />
                        <div v-else class="h-4 w-4" aria-hidden="true" />

                        <div class="flex min-w-0 items-center gap-2.5">
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-xs font-semibold text-white"
                            >
                                {{ initials(g.full_name) }}
                            </div>
                            <div class="min-w-0">
                                <Link
                                    :href="route('tenant.guests.show', g.id)"
                                    class="block truncate text-sm font-medium hover:text-primary"
                                >
                                    {{ g.full_name }}
                                </Link>
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <span
                                        v-if="g.is_blacklisted"
                                        class="rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                    >
                                        Lista negra
                                    </span>
                                    <span
                                        v-if="g.is_archived"
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                    >
                                        Archivado
                                    </span>
                                    <span class="text-[11px] text-slate-400">
                                        Alta {{ g.created_at }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div
                            class="col-start-2 min-w-0 text-xs text-slate-500 lg:col-start-auto"
                        >
                            <a
                                v-if="g.phone"
                                :href="`tel:${g.phone}`"
                                class="flex items-center gap-1.5 transition hover:text-primary"
                            >
                                <Lucide
                                    icon="Phone"
                                    class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                />
                                <span class="truncate">{{ g.phone }}</span>
                            </a>
                            <a
                                v-if="g.email"
                                :href="`mailto:${g.email}`"
                                class="flex items-center gap-1.5 transition hover:text-primary"
                            >
                                <Lucide
                                    icon="Mail"
                                    class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                />
                                <span class="truncate">{{ g.email }}</span>
                            </a>
                            <span
                                v-if="!g.phone && !g.email"
                                class="text-slate-400"
                            >
                                Sin datos de contacto
                            </span>
                        </div>

                        <!-- Cuántas veces ha venido y cuándo fue la última -->
                        <div
                            class="col-start-2 flex items-center gap-2 lg:col-start-auto"
                        >
                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                :class="
                                    g.visits > 0
                                        ? 'bg-success/10 text-success'
                                        : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                "
                            >
                                <Lucide icon="BedDouble" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-xs font-medium">
                                    {{ g.visits }}
                                    {{ g.visits === 1 ? 'visita' : 'visitas' }}
                                </div>
                                <div
                                    v-if="g.last_visit"
                                    class="truncate text-[11px] text-slate-400"
                                >
                                    última {{ g.last_visit }}
                                </div>
                                <div v-else class="text-[11px] text-slate-400">
                                    Nunca se ha hospedado
                                </div>
                            </div>
                        </div>

                        <!-- Lo que ha dejado y lo que trae apartado -->
                        <div
                            class="col-start-2 min-w-0 lg:col-start-auto lg:text-right"
                        >
                            <div class="text-xs font-medium tabular-nums">
                                {{ money(g.total_spent) }}
                            </div>
                            <div
                                v-if="g.next_arrival"
                                class="truncate text-[11px] font-medium text-primary"
                            >
                                Llega el {{ g.next_arrival }}
                            </div>
                            <div v-else class="text-[11px] text-slate-400">
                                Sin llegadas próximas
                            </div>
                        </div>

                        <!-- Móvil: las acciones en su propio renglón a lo
                             ancho — compartir fila aplastaba el nombre. -->
                        <div
                            class="col-span-full flex items-center gap-1.5 lg:col-span-1 lg:col-start-auto lg:justify-end"
                        >
                            <Button
                                :as="Link"
                                :href="route('tenant.guests.show', g.id)"
                                variant="outline-primary"
                                class="h-8 flex-1 rounded-[0.5rem] bg-white text-xs whitespace-nowrap lg:flex-none"
                            >
                                <Lucide icon="Eye" class="mr-1.5 h-3.5 w-3.5" />
                                Ver ficha
                            </Button>
                            <Menu v-if="canManage">
                                <Menu.Button
                                    :class="rowAction"
                                    class="shrink-0 border border-slate-200 hover:bg-slate-100 dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                                    title="Más acciones"
                                >
                                    <Lucide
                                        icon="EllipsisVertical"
                                        class="h-4 w-4"
                                    />
                                </Menu.Button>
                                <Menu.Items class="w-52">
                                    <Menu.Item
                                        v-if="!g.is_archived"
                                        :as="Link"
                                        :href="`${route('tenant.guests.show', g.id)}?edit=1`"
                                    >
                                        <Lucide
                                            icon="Pencil"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Editar huésped
                                    </Menu.Item>
                                    <Menu.Item
                                        v-if="g.phone"
                                        as="a"
                                        :href="whatsappHref(g.phone)"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        <Lucide
                                            icon="MessageCircle"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Escribir por WhatsApp
                                    </Menu.Item>
                                    <Menu.Item
                                        v-if="!g.is_archived"
                                        :as="Link"
                                        :href="`${route('tenant.reservations.operation')}?intent=reserve&guest=${g.id}`"
                                    >
                                        <Lucide
                                            icon="CalendarPlus"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Reservar para él
                                    </Menu.Item>
                                    <Menu.Item
                                        v-if="!g.is_archived"
                                        as="button"
                                        type="button"
                                        class="text-danger"
                                        @click="askDelete(g)"
                                    >
                                        <Lucide
                                            icon="Archive"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Archivar o eliminar
                                    </Menu.Item>
                                    <Menu.Item
                                        v-if="g.is_archived"
                                        as="button"
                                        type="button"
                                        :disabled="restoringId === g.id"
                                        @click="restoreGuest(g)"
                                    >
                                        <Lucide
                                            icon="ArchiveRestore"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        {{
                                            restoringId === g.id
                                                ? 'Restaurando...'
                                                : 'Restaurar huésped'
                                        }}
                                    </Menu.Item>
                                    <Menu.Item
                                        v-if="g.is_archived"
                                        as="button"
                                        type="button"
                                        class="text-danger"
                                        @click="askDelete(g)"
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
                    v-if="!guests.data.length"
                    class="flex flex-col items-center gap-2 px-5 py-10 text-center"
                >
                    <Lucide
                        :icon="filtersActive ? 'SearchX' : 'Users'"
                        class="h-8 w-8 text-slate-300"
                    />
                    <p class="text-sm font-medium text-slate-600">
                        {{
                            filters.archived
                                ? 'No hay huéspedes archivados'
                                : filtersActive
                                  ? 'Nadie coincide con lo que buscas'
                                  : 'Aún no hay huéspedes'
                        }}
                    </p>
                    <p class="text-xs text-slate-500">
                        {{
                            filtersActive
                                ? 'Puedes buscar por el nombre completo, el teléfono como sea que lo escribas o el correo.'
                                : 'Se dan de alta solos al reservar, o puedes crear uno a mano.'
                        }}
                    </p>
                    <Button
                        v-if="canManage && !filtersActive"
                        variant="outline-primary"
                        class="mt-1 h-9 rounded-[0.5rem] text-xs"
                        @click="showCreate = true"
                    >
                        <Lucide icon="UserPlus" class="mr-1.5 h-3.5 w-3.5" />
                        Nuevo huésped
                    </Button>
                </div>

                <!-- Paginación -->
                <div
                    v-if="guests.links.length > 3"
                    class="flex flex-wrap items-center gap-2 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <span class="text-xs text-slate-500">
                        {{ guests.from }}–{{ guests.to }} de {{ guests.total }}
                    </span>
                    <div class="ml-auto flex flex-wrap gap-1">
                        <component
                            :is="link.url ? Link : 'span'"
                            v-for="(link, i) in guests.links"
                            :key="i"
                            :href="link.url ?? undefined"
                            preserve-state
                            class="rounded-md px-2.5 py-1 text-xs"
                            :class="
                                link.active
                                    ? 'bg-primary text-white'
                                    : link.url
                                      ? 'text-slate-500 hover:bg-slate-100 dark:hover:bg-darkmode-400'
                                      : 'text-slate-300'
                            "
                        >
                            <!-- El rótulo trae las flechas « » de Laravel. -->
                            <span v-html="link.label" />
                        </component>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal alta de huésped -->
        <GuestFormModal
            :open="showCreate"
            :document-types="documentTypes"
            :can-view-documents="canViewDocuments"
            @close="showCreate = false"
            @saved="onSaved"
        />

        <!-- Modal eliminar -->
        <Dialog size="lg" :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div v-if="deleting" class="p-5">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                {{
                                    deleting.is_archived
                                        ? `¿Eliminar definitivamente a ${deleting.full_name}?`
                                        : `¿Eliminar a ${deleting.full_name}?`
                                }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{
                                    deleting.is_archived
                                        ? 'Se borran su ficha, fotos y documentos para siempre.'
                                        : 'Si no tiene historial, se borran su ficha y fotos de forma definitiva.'
                                }}
                            </p>
                        </div>
                    </div>
                    <div
                        v-if="deleting.is_archived"
                        class="mt-4 flex items-center gap-2 rounded-lg border border-danger/20 bg-danger/5 px-3 py-2.5 text-xs text-danger"
                    >
                        <Lucide icon="TriangleAlert" class="h-4 w-4 shrink-0" />
                        Esta acción no se puede deshacer. Sus reservas y
                        estancias pasadas se conservan, pero quedarán sin
                        huésped vinculado. Si prefieres conservarlo, usa
                        Restaurar huésped.
                    </div>
                    <div
                        v-else
                        class="mt-4 flex items-center gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                    >
                        <Lucide icon="Archive" class="h-4 w-4 shrink-0" /> Si
                        tiene reservas o estancias, se archiva: desaparece del
                        directorio pero su historial se conserva y podrás
                        restaurarlo desde el filtro Archivados.
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
                            @click="deleting = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            class="h-9 px-5 text-xs"
                            :disabled="deleteBusy"
                            @click="submitDelete"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            {{ deleteBusy ? 'Eliminando…' : 'Sí, eliminar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmar borrado masivo -->
        <Dialog
            size="lg"
            :open="bulkDeleteOpen"
            @close="bulkDeleteOpen = false"
        >
            <Dialog.Panel>
                <div class="p-5">
                    <div class="mb-3 flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10"
                        >
                            <Lucide icon="Trash2" class="h-5 w-5 text-danger" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                {{
                                    archived
                                        ? `Eliminar definitivamente ${selectedRows.length} huésped(es)`
                                        : `Eliminar ${selectedRows.length} huésped(es)`
                                }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{
                                    archived
                                        ? 'Se borran sus fichas, fotos y documentos para siempre; su historial queda sin huésped vinculado. Esta acción no se puede deshacer.'
                                        : 'Los que tengan historial de reservas o estancias se archivan (restaurables); el resto se elimina definitivamente.'
                                }}
                            </p>
                        </div>
                    </div>
                    <div
                        class="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-dashed border-slate-300/70 p-2 text-sm dark:border-darkmode-400"
                    >
                        <div
                            v-for="row in selectedRows"
                            :key="row.id"
                            class="flex items-center justify-between gap-2 px-1"
                        >
                            <span class="font-medium">{{ row.full_name }}</span>
                            <span class="text-xs text-slate-500">{{
                                row.phone ?? row.email ?? ''
                            }}</span>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 px-5 text-xs"
                            @click="bulkDeleteOpen = false"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            class="h-9 px-5 text-xs"
                            :disabled="bulkDeleting"
                            @click="bulkDelete"
                        >
                            {{ bulkDeleting ? 'Eliminando…' : 'Sí, eliminar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
