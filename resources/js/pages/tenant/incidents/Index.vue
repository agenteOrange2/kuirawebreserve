<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormCheck,
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import SlaDialog from './SlaDialog.vue';
import TechniciansDialog from './TechniciansDialog.vue';
import type { SlaHours, TechnicianRow } from './types';

interface IncidentPhoto {
    id: number;
    url: string;
}

interface IncidentRow {
    id: number;
    room_id: number | null;
    room: string | null;
    room_status: string | null;
    title: string;
    category: string | null;
    category_label: string | null;
    guest_reported: boolean;
    description: string | null;
    priority: 'low' | 'medium' | 'high';
    priority_label: string;
    status: 'open' | 'in_progress' | 'resolved';
    status_label: string;
    reported_by: string | null;
    assigned_to: number | null;
    assignee: string | null;
    resolved_by: string | null;
    resolved_at: string | null;
    resolution_notes: string | null;
    created_at: string;
    due_at: string | null;
    overdue: boolean;
    age_hours: number;
    cost: number | null;
    technician_id: number | null;
    technician: string | null;
    stay_id: number | null;
    photos: IncidentPhoto[];
}

interface RoomOption {
    id: number;
    label: string;
    status: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    incidents: {
        data: IncidentRow[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    categories: Record<string, string>;
    kpis: {
        open: number;
        in_progress: number;
        resolved_month: number;
        overdue: number;
    };
    rooms: RoomOption[];
    staff: Array<{ id: number; name: string }>;
    technicians: TechnicianRow[];
    sla: SlaHours;
    filters: {
        status: string;
        priority: string | null;
        room: number | null;
        category?: string | null;
        q: string;
        assignee: string | null;
        source: string | null;
        overdue: boolean;
    };
    canManage: boolean;
    canDelete: boolean;
    // Incidencias avanzadas (Empresarial): responsables y reportes.
    advanced: boolean;
}>();

const toast = useToasts();
const incidents = ref<IncidentRow[]>([...props.incidents.data]);
// La página en sí (totales y ligas); `incidents` es la copia mutable de
// las filas visibles, que cambia en vivo al resolver o asignar.
const paginator = computed(() => props.incidents);
const kpis = reactive({ ...props.kpis });

// Al cambiar de página o de filtro llegan props nuevos: la copia local
// (que se muta en vivo al resolver o asignar) tiene que seguirlos.
watch(
    () => props.incidents,
    (page) => {
        incidents.value = [...page.data];
        selectedIds.value = [];
    },
);
watch(
    () => props.kpis,
    (fresh) => Object.assign(kpis, fresh),
);

const priorityClass = (priority: IncidentRow['priority']) =>
    priority === 'high'
        ? 'bg-danger/10 text-danger'
        : priority === 'medium'
          ? 'bg-pending/10 text-pending'
          : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400';

const statusClass = (status: IncidentRow['status']) =>
    status === 'open'
        ? 'bg-danger/10 text-danger'
        : status === 'in_progress'
          ? 'bg-warning/10 text-warning'
          : 'bg-success/10 text-success';

// Los KPI se ajustan en vivo al mutar, sin recargar la página.
function shiftKpis(
    from: IncidentRow['status'] | null,
    to: IncidentRow['status'] | null,
) {
    if (from === 'open') kpis.open--;
    if (from === 'in_progress') kpis.in_progress--;
    if (to === 'open') kpis.open++;
    if (to === 'in_progress') kpis.in_progress++;
    if (to === 'resolved' && from !== 'resolved') kpis.resolved_month++;
}

// ── Filtros (server-side) ──
const filterState = reactive({
    status: props.filters.status,
    priority: props.filters.priority ?? '',
    room: props.filters.room ?? '',
    category: props.filters.category ?? '',
    q: props.filters.q ?? '',
    assignee: props.filters.assignee ?? '',
    source: props.filters.source ?? '',
    overdue: props.filters.overdue,
});

const filtersActive = computed(
    () =>
        filterState.status !== 'active' ||
        filterState.priority !== '' ||
        filterState.room !== '' ||
        filterState.category !== '' ||
        filterState.q.trim() !== '' ||
        filterState.assignee !== '' ||
        filterState.source !== '' ||
        filterState.overdue,
);

function applyFilters() {
    router.get(
        route('tenant.incidents'),
        {
            status: filterState.status,
            priority: filterState.priority || undefined,
            room: filterState.room || undefined,
            category: filterState.category || undefined,
            q: filterState.q.trim() || undefined,
            assignee: filterState.assignee || undefined,
            source: filterState.source || undefined,
            overdue: filterState.overdue || undefined,
        },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['incidents', 'kpis', 'filters'],
        },
    );
}

function clearFilters() {
    Object.assign(filterState, {
        status: 'active',
        priority: '',
        room: '',
        category: '',
        q: '',
        assignee: '',
        source: '',
        overdue: false,
    });
    applyFilters();
}

// El buscador va solo, con respiro: escribir "regadera" no debe disparar
// ocho consultas.
let searchTimer: ReturnType<typeof setTimeout> | null = null;
watch(
    () => filterState.q,
    () => {
        if (searchTimer) clearTimeout(searchTimer);
        searchTimer = setTimeout(applyFilters, 350);
    },
);

// ── Selección múltiple y borrado en bloque ──
const selectedIds = ref<number[]>([]);
/** Las filas marcadas: el diálogo enseña qué se va a borrar. */
const selectedRows = computed(() =>
    incidents.value.filter((incident) =>
        selectedIds.value.includes(incident.id),
    ),
);
const bulkDeleteOpen = ref(false);
const bulkDeleting = ref(false);

const allSelected = computed(
    () =>
        incidents.value.length > 0 &&
        incidents.value.every((i) => selectedIds.value.includes(i.id)),
);

function toggleRow(id: number) {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((x) => x !== id)
        : [...selectedIds.value, id];
}

function toggleAll() {
    selectedIds.value = allSelected.value
        ? []
        : incidents.value.map((i) => i.id);
}

async function bulkDelete() {
    bulkDeleting.value = true;
    try {
        const { data } = await axios.delete('/api/incidents/bulk', {
            data: { ids: selectedIds.value },
        });
        toast.success('Listo', `${data.deleted} incidencia(s) eliminada(s).`);
        selectedIds.value = [];
        bulkDeleteOpen.value = false;
        router.reload({ only: ['incidents', 'kpis'] });
    } catch (e: any) {
        toast.error(
            'No se pudo eliminar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        bulkDeleting.value = false;
    }
}

// ── Modal reportar/editar ──
const showForm = ref(false);
const editing = ref<IncidentRow | null>(null);
const saving = ref(false);
const errors = reactive<Record<string, string>>({});
const form = reactive({
    room_id: '' as string | number,
    title: '',
    category: '',
    guest_reported: false,
    description: '',
    priority: 'medium' as IncidentRow['priority'],
    assigned_to: '' as string | number,
    set_maintenance: false,
});
const stagedPhotos = ref<File[]>([]);
const photoInput = ref<HTMLInputElement>();
const uploadingPhoto = ref(false);

const selectedRoom = computed(
    () => props.rooms.find((r) => r.id === Number(form.room_id)) ?? null,
);

// Solo tiene caso ofrecer "poner en mantenimiento" cuando no hay huésped
// de por medio — mismo criterio que valida el backend.
const canSetMaintenance = computed(
    () =>
        !editing.value &&
        selectedRoom.value !== null &&
        ['available', 'dirty', 'cleaning'].includes(selectedRoom.value.status),
);

// Una falla urgente casi siempre impide vender el cuarto: la casilla se
// pre-marca al elegir prioridad alta, visible para desmarcarla. Forzarlo
// sería quitarle al hotel una decisión de venta que es suya.
watch(
    () => form.priority,
    (priority) => {
        if (priority === 'high' && canSetMaintenance.value) {
            form.set_maintenance = true;
        }
    },
);

function openForm(incident: IncidentRow | null = null) {
    editing.value = incident;
    form.room_id = incident?.room_id ?? '';
    form.title = incident?.title ?? '';
    form.category = incident?.category ?? '';
    form.guest_reported = incident?.guest_reported ?? false;
    form.description = incident?.description ?? '';
    form.priority = incident?.priority ?? 'medium';
    form.assigned_to = incident?.assigned_to ?? '';
    form.set_maintenance = false;
    stagedPhotos.value = [];
    Object.keys(errors).forEach((k) => delete errors[k]);
    showForm.value = true;
}

function replaceRow(row: IncidentRow) {
    incidents.value = incidents.value.map((i) => (i.id === row.id ? row : i));
}

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    const payload = {
        room_id: form.room_id === '' ? null : Number(form.room_id),
        title: form.title,
        category: form.category || null,
        source: form.guest_reported ? 'guest' : 'staff',
        description: form.description || null,
        priority: form.priority,
        assigned_to: form.assigned_to === '' ? null : Number(form.assigned_to),
    };
    try {
        if (editing.value) {
            const { data } = await axios.patch<IncidentRow>(
                `/api/incidents/${editing.value.id}`,
                payload,
            );
            replaceRow(data);
            toast.success(
                'Incidencia actualizada',
                'Los cambios quedaron guardados.',
            );
        } else {
            const { data } = await axios.post<IncidentRow>('/api/incidents', {
                ...payload,
                set_maintenance: form.set_maintenance,
            });
            let created = data;
            for (const file of stagedPhotos.value) {
                created = await uploadPhoto(created.id, file);
            }
            incidents.value = [created, ...incidents.value];
            shiftKpis(null, created.status);
            toast.success(
                'Incidencia reportada',
                form.set_maintenance && canSetMaintenance.value
                    ? 'La habitación quedó en mantenimiento en el plano.'
                    : 'Quedó registrada para su seguimiento.',
            );
        }
        showForm.value = false;
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(
                ([key, msgs]) => (errors[key] = (msgs as string[])[0]),
            );
        } else {
            toast.error('Error', data?.message ?? 'No se pudo guardar.');
        }
    } finally {
        saving.value = false;
    }
}

// ── Fotos de evidencia ──
async function uploadPhoto(
    incidentId: number,
    file: File,
): Promise<IncidentRow> {
    const body = new FormData();
    body.append('file', file);
    const { data } = await axios.post<IncidentRow>(
        `/api/incidents/${incidentId}/photos`,
        body,
    );
    return data;
}

async function onPhotoPicked(event: Event) {
    const files = Array.from((event.target as HTMLInputElement).files ?? []);
    if (photoInput.value) photoInput.value.value = '';
    if (!files.length) return;

    if (!editing.value) {
        stagedPhotos.value = [...stagedPhotos.value, ...files];
        return;
    }

    uploadingPhoto.value = true;
    try {
        let updated = editing.value;
        for (const file of files) {
            updated = await uploadPhoto(editing.value.id, file);
        }
        editing.value = updated;
        replaceRow(updated);
    } catch (e: any) {
        toast.error(
            'Error',
            e.response?.data?.message ?? 'No se pudo subir la foto.',
        );
    } finally {
        uploadingPhoto.value = false;
    }
}

async function destroyPhoto(photo: IncidentPhoto) {
    if (!editing.value) return;
    try {
        const { data } = await axios.delete<IncidentRow>(
            `/api/incidents/${editing.value.id}/photos/${photo.id}`,
        );
        editing.value = data;
        replaceRow(data);
    } catch {
        toast.error('Error', 'No se pudo eliminar la foto.');
    }
}

// ── Cambios de estado ──
async function patchStatus(
    incident: IncidentRow,
    status: IncidentRow['status'],
    extra: Record<string, unknown> = {},
) {
    const previous = incident.status;
    try {
        const { data } = await axios.patch<IncidentRow>(
            `/api/incidents/${incident.id}`,
            { status, ...extra },
        );
        replaceRow(data);
        shiftKpis(previous, data.status);
        return data;
    } catch (e: any) {
        toast.error(
            'Error',
            e.response?.data?.message ?? 'No se pudo actualizar la incidencia.',
        );
        return null;
    }
}

// ── Tiempos objetivo y catálogo de quién repara ──
const slaHours = ref<SlaHours>({ ...props.sla });
const showSla = ref(false);
const showTechnicians = ref(false);

// Cuánto tiempo queda (o cuánto lleva vencida) en palabras: "vence en 3 h"
// dice más que una fecha suelta a media tabla.
function dueLabel(incident: IncidentRow): string | null {
    if (incident.status === 'resolved' || !incident.due_at) return null;
    return incident.overdue ? 'Vencida' : `Vence ${incident.due_at}`;
}

const money = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
});

// ── Modal resolver ──
const resolving = ref<IncidentRow | null>(null);
const resolveNotes = ref('');
const releaseRoom = ref(true);
const resolveCost = ref<string>('');
const resolveTechnician = ref<string | number>('');
const resolvingBusy = ref(false);

function openResolve(incident: IncidentRow) {
    resolving.value = incident;
    resolveNotes.value = '';
    releaseRoom.value = true;
    resolveCost.value = incident.cost !== null ? String(incident.cost) : '';
    resolveTechnician.value = incident.technician_id ?? '';
    Object.keys(errors).forEach((k) => delete errors[k]);
}

async function confirmResolve() {
    if (!resolving.value) return;
    resolvingBusy.value = true;
    const releasing =
        releaseRoom.value && resolving.value.room_status === 'maintenance';
    const data = await patchStatus(resolving.value, 'resolved', {
        resolution_notes: resolveNotes.value || null,
        release_room: releasing,
        // Costo y técnico solo existen con incidencias avanzadas; el
        // backend los ignora si el módulo está apagado.
        cost: resolveCost.value === '' ? null : Number(resolveCost.value),
        technician_id:
            resolveTechnician.value === ''
                ? null
                : Number(resolveTechnician.value),
    });
    resolvingBusy.value = false;
    if (data) {
        resolving.value = null;
        toast.success(
            'Incidencia resuelta',
            releasing
                ? 'La habitación volvió a disponible en el plano.'
                : 'Quedó registrada la resolución.',
        );
    }
}

// ── Eliminar ──
const deleting = ref<IncidentRow | null>(null);

async function destroy() {
    if (!deleting.value) return;
    try {
        await axios.delete(`/api/incidents/${deleting.value.id}`);
        shiftKpis(deleting.value.status, null);
        incidents.value = incidents.value.filter(
            (i) => i.id !== deleting.value!.id,
        );
        deleting.value = null;
        toast.success('Incidencia eliminada', 'Se quitó del historial.');
    } catch {
        toast.error('Error', 'No se pudo eliminar.');
    }
}
</script>

<template>
    <RazeLayout title="Incidencias">
        <div class="mt-2 grid grid-cols-12 gap-5">
            <!-- Encabezado -->
            <div class="col-span-12">
                <div
                    class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Wrench" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-base font-medium">
                                Incidencias de mantenimiento
                            </h1>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Fallas de habitaciones y áreas generales, con
                                seguimiento hasta resolverlas.
                            </p>
                        </div>
                    </div>
                    <div
                        class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2.5"
                    >
                        <Button
                            v-if="canManage"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs"
                            @click="showSla = true"
                        >
                            <Lucide icon="Timer" class="mr-1.5 h-3.5 w-3.5" />
                            Tiempos
                        </Button>
                        <Button
                            v-if="advanced && canManage"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs"
                            @click="showTechnicians = true"
                        >
                            <Lucide icon="HardHat" class="mr-1.5 h-3.5 w-3.5" />
                            Quién repara
                        </Button>
                        <Button
                            v-if="advanced"
                            as="a"
                            :href="route('tenant.incidents.reports')"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs"
                        >
                            <Lucide
                                icon="ChartColumn"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Reportes
                        </Button>
                        <Button
                            v-if="canManage"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                            @click="openForm()"
                        >
                            <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                            Reportar incidencia
                        </Button>
                    </div>
                </div>
            </div>

            <!-- KPIs -->
            <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                <div
                    class="box box--stacked flex h-full items-center gap-2.5 p-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                    >
                        <Lucide icon="CircleAlert" class="h-4 w-4" />
                    </div>
                    <div>
                        <div class="text-sm font-medium">{{ kpis.open }}</div>
                        <div class="text-xs text-slate-500">Abiertas</div>
                    </div>
                </div>
            </div>
            <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                <div
                    class="box box--stacked flex h-full items-center gap-2.5 p-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                    >
                        <Lucide icon="Hammer" class="h-4 w-4" />
                    </div>
                    <div>
                        <div class="text-sm font-medium">
                            {{ kpis.in_progress }}
                        </div>
                        <div class="text-xs text-slate-500">En proceso</div>
                    </div>
                </div>
            </div>
            <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                <div
                    class="box box--stacked flex h-full items-center gap-2.5 p-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="CircleCheck" class="h-4 w-4" />
                    </div>
                    <div>
                        <div class="text-sm font-medium">
                            {{ kpis.resolved_month }}
                        </div>
                        <div class="text-xs text-slate-500">
                            Resueltas este mes
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                <div
                    class="box box--stacked flex h-full items-center gap-2.5 p-3"
                    :class="kpis.overdue > 0 ? 'ring-1 ring-danger/30' : ''"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                        :class="
                            kpis.overdue > 0
                                ? 'border-danger/10 bg-danger/10 text-danger'
                                : 'border-slate-200/70 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400'
                        "
                    >
                        <Lucide icon="Timer" class="h-4 w-4" />
                    </div>
                    <div>
                        <div class="text-sm font-medium">
                            {{ kpis.overdue }}
                        </div>
                        <div class="text-xs text-slate-500">
                            Fuera de tiempo
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtros + listado -->
            <div class="col-span-12">
                <div class="box box--stacked">
                    <div
                        class="flex flex-col gap-2.5 border-b border-slate-200/70 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                    >
                        <div class="flex flex-col gap-2.5 sm:flex-row">
                            <div class="relative sm:flex-1">
                                <Lucide
                                    icon="Search"
                                    class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400"
                                />
                                <FormInput
                                    v-model="filterState.q"
                                    class="h-9 pl-9 text-xs"
                                    placeholder="Buscar por falla, nota o habitación"
                                />
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    class="flex h-9 items-center gap-1.5 rounded-lg border px-3 text-xs transition"
                                    :class="
                                        filterState.overdue
                                            ? 'border-danger/30 bg-danger/10 font-medium text-danger'
                                            : 'border-slate-200/70 bg-white text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-600'
                                    "
                                    @click="
                                        filterState.overdue =
                                            !filterState.overdue;
                                        applyFilters();
                                    "
                                >
                                    <Lucide icon="Timer" class="h-3.5 w-3.5" />
                                    Fuera de tiempo
                                </button>
                                <button
                                    v-if="filtersActive"
                                    type="button"
                                    class="flex h-9 items-center gap-1.5 rounded-lg border border-slate-200/70 bg-white px-3 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-600"
                                    @click="clearFilters"
                                >
                                    <Lucide icon="X" class="h-3.5 w-3.5" />
                                    Limpiar
                                </button>
                            </div>
                            <!-- Selección múltiple: mismos controles que el
                                 listado de habitaciones. -->
                            <template v-if="canDelete && selectedIds.length">
                                <span
                                    class="text-xs text-slate-500 sm:ml-auto sm:self-center"
                                >
                                    {{ selectedIds.length }} seleccionada(s)
                                </span>
                                <button
                                    type="button"
                                    class="text-xs font-medium text-primary hover:underline sm:self-center"
                                    @click="selectedIds = []"
                                >
                                    Quitar selección
                                </button>
                                <Button
                                    variant="danger"
                                    class="h-8 rounded-[0.5rem] text-xs sm:self-center"
                                    @click="bulkDeleteOpen = true"
                                >
                                    <Lucide
                                        icon="Trash2"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Eliminar seleccionadas
                                </Button>
                            </template>
                        </div>
                        <div
                            class="flex flex-col gap-2.5 sm:flex-row sm:flex-wrap"
                        >
                            <FormSelect
                                v-model="filterState.status"
                                class="h-9 text-xs sm:w-44"
                                @change="applyFilters"
                            >
                                <option value="active">Pendientes</option>
                                <option value="all">Todas</option>
                                <option value="open">Abiertas</option>
                                <option value="in_progress">En proceso</option>
                                <option value="resolved">Resueltas</option>
                            </FormSelect>
                            <FormSelect
                                v-model="filterState.priority"
                                class="h-9 text-xs sm:w-44"
                                @change="applyFilters"
                            >
                                <option value="">Toda prioridad</option>
                                <option value="high">Alta</option>
                                <option value="medium">Media</option>
                                <option value="low">Baja</option>
                            </FormSelect>
                            <FormSelect
                                v-model="filterState.room"
                                class="h-9 text-xs sm:w-56"
                                @change="applyFilters"
                            >
                                <option value="">Todas las habitaciones</option>
                                <option
                                    v-for="room in rooms"
                                    :key="room.id"
                                    :value="room.id"
                                >
                                    {{ room.label }}
                                </option>
                            </FormSelect>
                            <FormSelect
                                v-model="filterState.category"
                                class="h-9 text-xs sm:w-56"
                                @change="applyFilters"
                            >
                                <option value="">Todo tipo de falla</option>
                                <option
                                    v-for="(label, value) in categories"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option>
                            </FormSelect>
                            <FormSelect
                                v-if="advanced"
                                v-model="filterState.assignee"
                                class="h-9 text-xs sm:w-48"
                                @change="applyFilters"
                            >
                                <option value="">Cualquier responsable</option>
                                <option value="none">Sin asignar</option>
                                <option
                                    v-for="person in staff"
                                    :key="person.id"
                                    :value="String(person.id)"
                                >
                                    {{ person.name }}
                                </option>
                            </FormSelect>
                            <FormSelect
                                v-model="filterState.source"
                                class="h-9 text-xs sm:w-44"
                                @change="applyFilters"
                            >
                                <option value="">Quien la reportó</option>
                                <option value="staff">El equipo</option>
                                <option value="guest">Un huésped</option>
                            </FormSelect>
                        </div>
                    </div>

                    <template v-if="incidents.length">
                        <!-- Móvil: tarjetas apiladas -->
                        <div class="space-y-2 p-4 sm:hidden">
                            <div
                                v-for="incident in incidents"
                                :key="`card-${incident.id}`"
                                class="rounded-lg border border-slate-200/70 bg-white p-3.5 dark:border-darkmode-400 dark:bg-darkmode-600"
                            >
                                <div
                                    class="flex items-center justify-between gap-2"
                                >
                                    <FormCheck.Input
                                        v-if="canDelete"
                                        type="checkbox"
                                        class="shrink-0"
                                        :checked="
                                            selectedIds.includes(incident.id)
                                        "
                                        @change="toggleRow(incident.id)"
                                    />
                                    <Link
                                        :href="
                                            route(
                                                'tenant.incidents.show',
                                                incident.id,
                                            )
                                        "
                                        class="min-w-0 flex-1 truncate font-medium"
                                    >
                                        {{ incident.title }}
                                    </Link>
                                    <span
                                        class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="statusClass(incident.status)"
                                    >
                                        {{ incident.status_label }}
                                    </span>
                                </div>
                                <div
                                    class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"
                                >
                                    <span class="font-medium text-slate-600">
                                        {{ incident.room ?? 'Área general' }}
                                    </span>
                                    <span
                                        class="rounded-full px-2 py-0.5 font-medium"
                                        :class="
                                            priorityClass(incident.priority)
                                        "
                                    >
                                        {{ incident.priority_label }}
                                    </span>
                                    <span
                                        v-if="incident.category_label"
                                        class="rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                    >
                                        {{ incident.category_label }}
                                    </span>
                                    <span
                                        v-if="incident.guest_reported"
                                        class="rounded-full bg-info/10 px-2 py-0.5 font-medium text-info"
                                    >
                                        Reportó huésped
                                    </span>
                                    <span
                                        v-if="dueLabel(incident)"
                                        class="rounded-full px-2 py-0.5 font-medium"
                                        :class="
                                            incident.overdue
                                                ? 'bg-danger/10 text-danger'
                                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400 dark:text-slate-300'
                                        "
                                    >
                                        {{ dueLabel(incident) }}
                                    </span>
                                    <span>{{ incident.created_at }}</span>
                                    <span v-if="incident.assignee">
                                        Atiende: {{ incident.assignee }}
                                    </span>
                                    <span v-if="incident.cost !== null">
                                        Costó {{ money.format(incident.cost) }}
                                        <template v-if="incident.technician">
                                            · {{ incident.technician }}
                                        </template>
                                    </span>
                                </div>
                                <div
                                    class="mt-3 flex items-center gap-2 border-t border-dashed border-slate-200/70 pt-2.5 dark:border-darkmode-400"
                                >
                                    <Button
                                        v-if="
                                            canManage &&
                                            incident.status !== 'resolved'
                                        "
                                        variant="outline-success"
                                        size="sm"
                                        @click="openResolve(incident)"
                                    >
                                        <Lucide
                                            icon="CircleCheck"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Resolver
                                    </Button>
                                    <Link
                                        :href="
                                            route(
                                                'tenant.incidents.show',
                                                incident.id,
                                            )
                                        "
                                        class="ml-auto flex h-8 w-8 items-center justify-center rounded-full border border-slate-200/70 text-slate-500 dark:border-darkmode-400"
                                        title="Ver detalle"
                                    >
                                        <Lucide icon="Eye" class="h-4 w-4" />
                                    </Link>
                                    <button
                                        v-if="canManage"
                                        type="button"
                                        class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200/70 text-slate-500 dark:border-darkmode-400"
                                        title="Editar"
                                        @click="openForm(incident)"
                                    >
                                        <Lucide icon="Pencil" class="h-4 w-4" />
                                    </button>
                                    <button
                                        v-if="canDelete"
                                        type="button"
                                        class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200/70 text-danger dark:border-darkmode-400"
                                        title="Eliminar"
                                        @click="deleting = incident"
                                    >
                                        <Lucide icon="Trash2" class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Escritorio: tabla -->
                        <div
                            class="hidden overflow-auto p-4 sm:block lg:overflow-visible"
                        >
                            <Table>
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th v-if="canDelete" class="w-10">
                                            <FormCheck.Input
                                                type="checkbox"
                                                title="Seleccionar las visibles"
                                                :checked="allSelected"
                                                @change="toggleAll"
                                            />
                                        </Table.Th>
                                        <Table.Th>Incidencia</Table.Th>
                                        <Table.Th class="whitespace-nowrap"
                                            >Habitación</Table.Th
                                        >
                                        <Table.Th class="whitespace-nowrap"
                                            >Prioridad</Table.Th
                                        >
                                        <Table.Th class="whitespace-nowrap"
                                            >Estado</Table.Th
                                        >
                                        <Table.Th class="whitespace-nowrap"
                                            >Atiende</Table.Th
                                        >
                                        <Table.Th class="whitespace-nowrap"
                                            >Reportada</Table.Th
                                        >
                                        <Table.Th
                                            class="text-right whitespace-nowrap"
                                            >Acciones</Table.Th
                                        >
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    <Table.Tr
                                        v-for="incident in incidents"
                                        :key="incident.id"
                                    >
                                        <Table.Td v-if="canDelete">
                                            <FormCheck.Input
                                                type="checkbox"
                                                :checked="
                                                    selectedIds.includes(
                                                        incident.id,
                                                    )
                                                "
                                                @change="toggleRow(incident.id)"
                                            />
                                        </Table.Td>
                                        <Table.Td>
                                            <Link
                                                :href="
                                                    route(
                                                        'tenant.incidents.show',
                                                        incident.id,
                                                    )
                                                "
                                                class="font-medium hover:text-primary"
                                            >
                                                {{ incident.title }}
                                            </Link>
                                            <div
                                                v-if="
                                                    incident.category_label ||
                                                    incident.guest_reported
                                                "
                                                class="mt-1 flex flex-wrap items-center gap-1.5"
                                            >
                                                <span
                                                    v-if="
                                                        incident.category_label
                                                    "
                                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                                >
                                                    {{
                                                        incident.category_label
                                                    }}
                                                </span>
                                                <span
                                                    v-if="
                                                        incident.guest_reported
                                                    "
                                                    class="rounded-full bg-info/10 px-2 py-0.5 text-[11px] font-medium text-info"
                                                >
                                                    Reportó huésped
                                                </span>
                                            </div>
                                            <div
                                                v-if="incident.description"
                                                class="mt-0.5 max-w-72 truncate text-xs text-slate-500"
                                            >
                                                {{ incident.description }}
                                            </div>
                                            <div
                                                v-if="incident.photos.length"
                                                class="mt-1 flex items-center gap-1 text-xs text-slate-400"
                                            >
                                                <Lucide
                                                    icon="Camera"
                                                    class="h-3.5 w-3.5"
                                                />
                                                {{ incident.photos.length }}
                                            </div>
                                        </Table.Td>
                                        <Table.Td class="whitespace-nowrap">
                                            {{
                                                incident.room ?? 'Área general'
                                            }}
                                        </Table.Td>
                                        <Table.Td>
                                            <span
                                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                                :class="
                                                    priorityClass(
                                                        incident.priority,
                                                    )
                                                "
                                            >
                                                {{ incident.priority_label }}
                                            </span>
                                        </Table.Td>
                                        <Table.Td>
                                            <span
                                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                                :class="
                                                    statusClass(incident.status)
                                                "
                                            >
                                                {{ incident.status_label }}
                                            </span>
                                            <div
                                                v-if="dueLabel(incident)"
                                                class="mt-1 flex items-center gap-1 text-[11px] whitespace-nowrap"
                                                :class="
                                                    incident.overdue
                                                        ? 'font-medium text-danger'
                                                        : 'text-slate-400'
                                                "
                                            >
                                                <Lucide
                                                    icon="Timer"
                                                    class="h-3 w-3"
                                                />
                                                {{ dueLabel(incident) }}
                                            </div>
                                        </Table.Td>
                                        <Table.Td class="whitespace-nowrap">
                                            {{ incident.assignee ?? '—' }}
                                            <div
                                                v-if="incident.cost !== null"
                                                class="mt-0.5 text-[11px] text-slate-400"
                                            >
                                                {{
                                                    money.format(incident.cost)
                                                }}
                                                <template
                                                    v-if="incident.technician"
                                                >
                                                    · {{ incident.technician }}
                                                </template>
                                            </div>
                                        </Table.Td>
                                        <Table.Td
                                            class="text-xs whitespace-nowrap text-slate-500"
                                        >
                                            {{ incident.created_at }}
                                            <span
                                                v-if="incident.reported_by"
                                                class="block"
                                                >por
                                                {{ incident.reported_by }}</span
                                            >
                                        </Table.Td>
                                        <Table.Td>
                                            <!-- Mismos botones fantasma que
                                                 el listado de habitaciones:
                                                 gris en reposo, color al
                                                 pasar encima. -->
                                            <div
                                                class="flex items-center justify-end gap-1"
                                            >
                                                <Link
                                                    :href="
                                                        route(
                                                            'tenant.incidents.show',
                                                            incident.id,
                                                        )
                                                    "
                                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-primary dark:hover:bg-darkmode-400"
                                                    title="Ver detalle"
                                                >
                                                    <Lucide
                                                        icon="Eye"
                                                        class="h-4 w-4"
                                                    />
                                                </Link>
                                                <template v-if="canManage">
                                                    <button
                                                        v-if="
                                                            incident.status ===
                                                            'open'
                                                        "
                                                        type="button"
                                                        class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-warning/10 hover:text-warning"
                                                        title="Marcar en proceso"
                                                        @click="
                                                            patchStatus(
                                                                incident,
                                                                'in_progress',
                                                            )
                                                        "
                                                    >
                                                        <Lucide
                                                            icon="Hammer"
                                                            class="h-4 w-4"
                                                        />
                                                    </button>
                                                    <button
                                                        v-if="
                                                            incident.status !==
                                                            'resolved'
                                                        "
                                                        type="button"
                                                        class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-success/10 hover:text-success"
                                                        title="Resolver"
                                                        @click="
                                                            openResolve(
                                                                incident,
                                                            )
                                                        "
                                                    >
                                                        <Lucide
                                                            icon="CircleCheck"
                                                            class="h-4 w-4"
                                                        />
                                                    </button>
                                                    <button
                                                        v-else
                                                        type="button"
                                                        class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-primary dark:hover:bg-darkmode-400"
                                                        title="Reabrir"
                                                        @click="
                                                            patchStatus(
                                                                incident,
                                                                'open',
                                                            )
                                                        "
                                                    >
                                                        <Lucide
                                                            icon="RefreshCw"
                                                            class="h-4 w-4"
                                                        />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                                        title="Editar"
                                                        @click="
                                                            openForm(incident)
                                                        "
                                                    >
                                                        <Lucide
                                                            icon="Pencil"
                                                            class="h-4 w-4"
                                                        />
                                                    </button>
                                                    <button
                                                        v-if="canDelete"
                                                        type="button"
                                                        class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                                        title="Eliminar"
                                                        @click="
                                                            deleting = incident
                                                        "
                                                    >
                                                        <Lucide
                                                            icon="Trash2"
                                                            class="h-4 w-4"
                                                        />
                                                    </button>
                                                </template>
                                            </div>
                                        </Table.Td>
                                    </Table.Tr>
                                </Table.Tbody>
                            </Table>
                        </div>

                        <!-- Paginación: antes se cortaba en 100 sin avisar -->
                        <div
                            class="flex flex-col items-center gap-2 border-t border-slate-200/70 px-4 py-3 sm:flex-row sm:justify-between dark:border-darkmode-400"
                        >
                            <p class="text-xs text-slate-500">
                                Mostrando {{ paginator.from ?? 0 }}–{{
                                    paginator.to ?? 0
                                }}
                                de {{ paginator.total }}
                            </p>
                            <div
                                v-if="paginator.links.length > 3"
                                class="flex flex-wrap justify-center gap-1"
                            >
                                <template
                                    v-for="(link, i) in paginator.links"
                                    :key="i"
                                >
                                    <Link
                                        v-if="link.url"
                                        :href="link.url"
                                        preserve-state
                                        preserve-scroll
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
                    </template>
                    <div
                        v-else
                        class="flex flex-col items-center gap-2.5 px-4 py-10 text-center"
                    >
                        <Lucide icon="Wrench" class="h-8 w-8 text-slate-300" />
                        <div>
                            <p class="text-sm font-medium text-slate-600">
                                Sin incidencias con estos filtros
                            </p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Cuando el personal reporte una falla, aparecerá
                                aquí para darle seguimiento.
                            </p>
                        </div>
                        <Button
                            v-if="canManage"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] text-xs"
                            @click="openForm()"
                        >
                            <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                            Reportar incidencia
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal reportar/editar -->
        <Dialog size="lg" :open="showForm" @close="showForm = false">
            <Dialog.Panel>
                <form @submit.prevent="submit">
                    <div class="max-h-[85vh] overflow-y-auto p-5">
                        <div class="mb-4 flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                            >
                                <Lucide
                                    icon="Wrench"
                                    class="h-5 w-5 text-primary"
                                />
                            </div>
                            <div>
                                <h2 class="text-base font-medium">
                                    {{
                                        editing
                                            ? 'Editar incidencia'
                                            : 'Reportar incidencia'
                                    }}
                                </h2>
                                <p class="text-xs text-slate-500">
                                    {{
                                        editing
                                            ? `Reportada el ${editing.created_at}${editing.reported_by ? ` por ${editing.reported_by}` : ''}.`
                                            : 'Describe la falla para que quien la atienda sepa qué buscar.'
                                    }}
                                </p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm"
                                        >Habitación</label
                                    >
                                    <FormSelect v-model="form.room_id">
                                        <option value="">
                                            Área general (sin habitación)
                                        </option>
                                        <option
                                            v-for="room in rooms"
                                            :key="room.id"
                                            :value="room.id"
                                        >
                                            {{ room.label }}
                                        </option>
                                    </FormSelect>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm"
                                        >Prioridad</label
                                    >
                                    <FormSelect v-model="form.priority">
                                        <option value="low">Baja</option>
                                        <option value="medium">Media</option>
                                        <option value="high">Alta</option>
                                    </FormSelect>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm"
                                        >Tipo de falla</label
                                    >
                                    <FormSelect v-model="form.category">
                                        <option value="">Sin clasificar</option>
                                        <option
                                            v-for="(label, value) in categories"
                                            :key="value"
                                            :value="value"
                                        >
                                            {{ label }}
                                        </option>
                                    </FormSelect>
                                    <FormHelp
                                        v-if="errors.category"
                                        class="text-danger"
                                        >{{ errors.category }}</FormHelp
                                    >
                                </div>
                                <div class="flex items-end pb-1.5">
                                    <label
                                        class="flex cursor-pointer items-center gap-2.5 text-sm"
                                    >
                                        <FormSwitch.Input
                                            v-model="form.guest_reported"
                                            type="checkbox"
                                        />
                                        Lo reportó un huésped
                                    </label>
                                </div>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Qué pasó</label
                                >
                                <FormInput
                                    v-model="form.title"
                                    type="text"
                                    placeholder="Fuga en la regadera"
                                    maxlength="120"
                                />
                                <FormHelp
                                    v-if="errors.title"
                                    class="text-danger"
                                    >{{ errors.title }}</FormHelp
                                >
                            </div>
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Detalle (opcional)</label
                                >
                                <FormTextarea
                                    v-model="form.description"
                                    rows="3"
                                    placeholder="Dónde está la falla, desde cuándo, qué se necesita…"
                                />
                            </div>
                            <div v-if="advanced">
                                <label class="mb-1 block text-sm"
                                    >Asignar a (opcional)</label
                                >
                                <FormSelect v-model="form.assigned_to">
                                    <option value="">Sin asignar</option>
                                    <option
                                        v-for="person in staff"
                                        :key="person.id"
                                        :value="person.id"
                                    >
                                        {{ person.name }}
                                    </option>
                                </FormSelect>
                                <FormHelp v-if="!editing">
                                    Con responsable asignado, la incidencia nace
                                    "En proceso".
                                </FormHelp>
                            </div>
                            <div
                                v-if="canSetMaintenance"
                                class="flex items-center justify-between rounded-lg border border-dashed border-slate-300/70 px-3 py-2.5 dark:border-darkmode-400"
                            >
                                <div class="pr-3">
                                    <span class="text-sm"
                                        >Poner la habitación en
                                        mantenimiento</span
                                    >
                                    <p class="text-xs text-slate-500">
                                        Sale del plano hasta que se resuelva la
                                        incidencia.
                                    </p>
                                </div>
                                <FormSwitch>
                                    <FormSwitch.Input
                                        :checked="form.set_maintenance"
                                        type="checkbox"
                                        @change="
                                            form.set_maintenance =
                                                !form.set_maintenance
                                        "
                                    />
                                </FormSwitch>
                            </div>

                            <!-- Fotos de evidencia -->
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Fotos (opcional)</label
                                >
                                <div class="flex flex-wrap items-center gap-2">
                                    <template v-if="editing">
                                        <div
                                            v-for="photo in editing.photos"
                                            :key="photo.id"
                                            class="relative h-16 w-16 overflow-hidden rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                                        >
                                            <img
                                                :src="photo.url"
                                                alt="Evidencia de la incidencia"
                                                class="h-full w-full object-cover"
                                            />
                                            <button
                                                type="button"
                                                class="absolute top-0.5 right-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-danger text-white"
                                                title="Eliminar foto"
                                                @click="destroyPhoto(photo)"
                                            >
                                                <Lucide
                                                    icon="X"
                                                    class="h-3 w-3"
                                                />
                                            </button>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <div
                                            v-for="(
                                                file, index
                                            ) in stagedPhotos"
                                            :key="index"
                                            class="flex items-center gap-1.5 rounded-lg border border-slate-200/70 px-2.5 py-1.5 text-xs text-slate-500 dark:border-darkmode-400"
                                        >
                                            <Lucide
                                                icon="Camera"
                                                class="h-3.5 w-3.5"
                                            />
                                            <span class="max-w-28 truncate">{{
                                                file.name
                                            }}</span>
                                            <button
                                                type="button"
                                                title="Quitar"
                                                @click="
                                                    stagedPhotos.splice(
                                                        index,
                                                        1,
                                                    )
                                                "
                                            >
                                                <Lucide
                                                    icon="X"
                                                    class="h-3.5 w-3.5"
                                                />
                                            </button>
                                        </div>
                                    </template>
                                    <button
                                        type="button"
                                        class="flex h-16 w-16 flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-slate-300/70 text-slate-400 transition hover:border-primary hover:text-primary dark:border-darkmode-400"
                                        :disabled="uploadingPhoto"
                                        title="Agregar fotos"
                                        @click="photoInput?.click()"
                                    >
                                        <Lucide
                                            icon="ImagePlus"
                                            class="h-5 w-5"
                                        />
                                    </button>
                                    <input
                                        ref="photoInput"
                                        type="file"
                                        accept="image/*"
                                        multiple
                                        class="hidden"
                                        @change="onPhotoPicked"
                                    />
                                </div>
                                <FormHelp>
                                    Evidencia interna del hotel; no se publica
                                    en ningún lado.
                                </FormHelp>
                            </div>

                            <div
                                v-if="editing?.status === 'resolved'"
                                class="rounded-lg bg-success/10 px-3.5 py-2.5 text-sm text-success"
                            >
                                Resuelta el {{ editing.resolved_at
                                }}<template v-if="editing.resolved_by">
                                    por {{ editing.resolved_by }}</template
                                >.
                                <span v-if="editing.resolution_notes">
                                    {{ editing.resolution_notes }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div
                        class="flex justify-end gap-2 border-t border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="min-h-11"
                            @click="showForm = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="min-h-11"
                            :disabled="saving || uploadingPhoto"
                        >
                            {{
                                saving
                                    ? 'Guardando…'
                                    : editing
                                      ? 'Guardar cambios'
                                      : 'Reportar'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal resolver -->
        <Dialog :open="resolving !== null" @close="resolving = null">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="mb-4 flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full border border-success/10 bg-success/10"
                        >
                            <Lucide
                                icon="CircleCheck"
                                class="h-5 w-5 text-success"
                            />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                Resolver "{{ resolving?.title }}"
                            </h2>
                            <p class="text-xs text-slate-500">
                                {{ resolving?.room ?? 'Área general' }}
                            </p>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm"
                                >Qué se hizo (opcional)</label
                            >
                            <FormTextarea
                                v-model="resolveNotes"
                                rows="3"
                                placeholder="Se cambió el empaque de la regadera…"
                            />
                        </div>
                        <div v-if="advanced" class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Costó reparar (opcional)</label
                                >
                                <FormInput
                                    v-model="resolveCost"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    placeholder="0.00"
                                />
                                <FormHelp>
                                    Material y mano de obra: es lo que suma el
                                    reporte por habitación.
                                </FormHelp>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Quién lo reparó</label
                                >
                                <FormSelect v-model="resolveTechnician">
                                    <option value="">Sin registrar</option>
                                    <option
                                        v-for="technician in technicians"
                                        :key="technician.id"
                                        :value="technician.id"
                                    >
                                        {{ technician.name }} ·
                                        {{ technician.kind_label }}
                                    </option>
                                </FormSelect>
                                <FormHelp>
                                    <button
                                        type="button"
                                        class="text-primary"
                                        @click="showTechnicians = true"
                                    >
                                        Administrar la lista
                                    </button>
                                </FormHelp>
                            </div>
                        </div>
                        <div
                            v-if="resolving?.room_status === 'maintenance'"
                            class="flex items-center justify-between rounded-lg border border-dashed border-slate-300/70 px-3 py-2.5 dark:border-darkmode-400"
                        >
                            <div class="pr-3">
                                <span class="text-sm"
                                    >Devolver la habitación a disponible</span
                                >
                                <p class="text-xs text-slate-500">
                                    Está en mantenimiento; al resolver vuelve al
                                    plano.
                                </p>
                            </div>
                            <FormSwitch>
                                <FormSwitch.Input
                                    :checked="releaseRoom"
                                    type="checkbox"
                                    @change="releaseRoom = !releaseRoom"
                                />
                            </FormSwitch>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            class="min-h-11"
                            @click="resolving = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="success"
                            class="min-h-11 text-white"
                            :disabled="resolvingBusy"
                            @click="confirmResolve"
                        >
                            {{
                                resolvingBusy
                                    ? 'Guardando…'
                                    : 'Marcar como resuelta'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmar eliminación -->
        <Dialog :open="deleting !== null" @close="deleting = null">
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
                                ¿Eliminar "{{ deleting?.title }}"?
                            </h2>
                            <p class="text-xs text-slate-500">
                                Se borra el ticket y sus fotos. Si ya se
                                atendió, mejor márcala como resuelta para
                                conservar el historial.
                            </p>
                        </div>
                    </div>
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
                            @click="destroy"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            Sí, eliminar
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <Dialog :open="bulkDeleteOpen" @close="bulkDeleteOpen = false">
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
                                Eliminar
                                {{ selectedRows.length }} incidencia(s)
                            </h2>
                            <p class="text-xs text-slate-500">
                                Se borran los tickets y sus fotos. Lo que ya se
                                atendió conviene dejarlo como resuelto: es el
                                historial de la habitación.
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
                            <span class="min-w-0 truncate font-medium">
                                {{ row.title }}
                            </span>
                            <span class="shrink-0 text-xs text-slate-500">
                                {{ row.room ?? 'Área general' }}
                            </span>
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
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                bulkDeleting ? 'Eliminando...' : 'Sí, eliminar'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <SlaDialog
            :open="showSla"
            :sla="slaHours"
            @close="showSla = false"
            @saved="
                (hours) => {
                    slaHours = hours;
                    router.reload({ only: ['incidents', 'kpis', 'sla'] });
                }
            "
        />

        <TechniciansDialog
            :open="showTechnicians"
            :technicians="technicians"
            :can-manage="canManage"
            @close="showTechnicians = false"
        />
    </RazeLayout>
</template>
