<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import TechniciansDialog from './TechniciansDialog.vue';
import type { TechnicianRow } from './types';

/** Técnico con la marca del rol del día. */
type TechnicianOnDuty = TechnicianRow & { on_duty?: boolean };

interface IncidentPhoto {
    id: number;
    url: string;
}

interface IncidentDetail {
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

/** La estancia que causó el daño y lo que se le cobró al huésped. */
interface StayBlock {
    id: number;
    guest: string;
    check_in_at: string | null;
    check_out_at: string | null;
    charges: Array<{ concept: string; amount: number }>;
    charged_total: number;
}

interface TimelineEntry {
    date: string;
    by: string | null;
    icon: Icon;
    // note = alguien escribió en la bitácora; change = lo movió el sistema.
    kind: 'note' | 'change';
    lines: string[];
}

/** Reloj del ticket contra el tiempo objetivo de su prioridad. */
interface Sla {
    target_hours: number;
    elapsed_hours: number;
    percent: number;
    resolved_in_time: boolean | null;
}

/** Lo que esta habitación lleva acumulado en fallas. */
interface RoomHistory {
    room_id: number;
    total: number;
    open: number;
    same_category: number;
    spent: number;
    recent: Array<{
        id: number;
        title: string;
        status: string;
        status_label: string;
        category_label: string | null;
        created_at: string;
    }>;
}

const props = defineProps<{
    incident: IncidentDetail;
    timeline: TimelineEntry[];
    staff: Array<{ id: number; name: string }>;
    categories: Array<{ value: string; label: string }>;
    technicians: TechnicianOnDuty[];
    // Programados hoy en mantenimiento (rol de Turnos).
    onDuty: {
        id: number;
        name: string;
        shift: string | null;
        time: string | null;
        color: string;
        now: boolean;
    }[];
    stay: StayBlock | null;
    sla: Sla;
    roomHistory: RoomHistory | null;
    canManage: boolean;
    canDelete: boolean;
    // Incidencias avanzadas (Empresarial): asignación de responsables.
    advanced: boolean;
}>();

const toast = useToasts();
const busy = ref(false);

const priorityClass = (priority: IncidentDetail['priority']) =>
    priority === 'high'
        ? 'bg-danger/10 text-danger'
        : priority === 'medium'
          ? 'bg-pending/10 text-pending'
          : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400';

const statusClass = (status: IncidentDetail['status']) =>
    status === 'open'
        ? 'bg-danger/10 text-danger'
        : status === 'in_progress'
          ? 'bg-warning/10 text-warning'
          : 'bg-success/10 text-success';

async function patch(data: Record<string, unknown>, success?: string) {
    busy.value = true;
    try {
        await axios.patch(`/api/incidents/${props.incident.id}`, data);
        if (success) toast.success('Listo', success);
        router.reload();
        return true;
    } catch (e: any) {
        toast.error(
            'Error',
            e.response?.data?.message ?? 'No se pudo actualizar la incidencia.',
        );
        return false;
    } finally {
        busy.value = false;
    }
}

// ── Asignación rápida ──
const assignSel = ref<string | number>(props.incident.assigned_to ?? '');

function reassign() {
    patch(
        {
            assigned_to:
                assignSel.value === '' ? null : Number(assignSel.value),
        },
        'El responsable quedó actualizado.',
    );
}

const money = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
});

// Cuánto falta para el tiempo objetivo (o desde cuándo se pasó).
const dueLabel = computed(() => {
    if (props.incident.status === 'resolved' || !props.incident.due_at) {
        return null;
    }
    return props.incident.overdue
        ? 'Fuera de tiempo'
        : `Vence ${props.incident.due_at}`;
});

// ── Edición en línea: prioridad y tipo de falla se corrigen aquí mismo,
// sin tener que volver al listado ──
const prioritySel = ref<IncidentDetail['priority']>(props.incident.priority);
const categorySel = ref<string>(props.incident.category ?? '');

function changePriority() {
    patch(
        { priority: prioritySel.value },
        'La prioridad cambió; el tiempo objetivo se recalculó.',
    );
}

function changeCategory() {
    patch(
        { category: categorySel.value === '' ? null : categorySel.value },
        'El tipo de falla quedó actualizado.',
    );
}

// Costo y técnico: antes solo se podían capturar al resolver, así que una
// cifra mal puesta ya no se podía corregir.
const editingCost = ref(false);
const costDraft = ref('');
const technicianDraft = ref<string | number>('');

function openCostEditor() {
    costDraft.value =
        props.incident.cost !== null ? String(props.incident.cost) : '';
    technicianDraft.value = props.incident.technician_id ?? '';
    editingCost.value = true;
}

async function saveCost() {
    const ok = await patch(
        {
            cost: costDraft.value === '' ? null : Number(costDraft.value),
            technician_id:
                technicianDraft.value === ''
                    ? null
                    : Number(technicianDraft.value),
        },
        'Quedó registrado lo que costó la reparación.',
    );
    if (ok) editingCost.value = false;
}

// ── Bitácora: dejar constancia sin mover el estado del ticket ──
const note = ref('');
const savingNote = ref(false);

async function addNote() {
    const text = note.value.trim();
    if (!text || savingNote.value) return;
    savingNote.value = true;
    try {
        await axios.post(`/api/incidents/${props.incident.id}/notes`, {
            note: text,
        });
        note.value = '';
        router.reload({ only: ['timeline'] });
        toast.success('Nota agregada', 'Quedó en la bitácora del ticket.');
    } catch (e: any) {
        toast.error(
            'Error',
            e.response?.data?.message ?? 'No se pudo guardar la nota.',
        );
    } finally {
        savingNote.value = false;
    }
}

// ── Avisar por WhatsApp a quien va a reparar ──
const technicianWa = computed(() => {
    const tech = props.technicians.find(
        (t) => t.id === props.incident.technician_id,
    );
    const digits = (tech?.phone ?? '').replace(/\D+/g, '');
    if (!digits) return null;
    const phone = digits.length === 10 ? `52${digits}` : digits;
    const text = `Hola ${tech?.name ?? ''}: ${props.incident.title}${
        props.incident.room ? ` en la habitación ${props.incident.room}` : ''
    }. Prioridad ${props.incident.priority_label.toLowerCase()}${
        props.incident.due_at ? `, con fecha objetivo ${props.incident.due_at}` : ''
    }.`;
    return `https://wa.me/${phone}?text=${encodeURIComponent(text)}`;
});

// ── Tarjetas de arriba: el ticket en números, no en párrafos ──
type Tone = 'success' | 'pending' | 'danger' | 'primary';

const slaTone = computed<Tone>(() => {
    if (props.incident.status === 'resolved') {
        return props.sla.resolved_in_time === false ? 'pending' : 'success';
    }
    return props.incident.overdue ? 'danger' : 'primary';
});

// Clases completas y literales: Tailwind no compila `bg-${variable}`.
const TONE_CIRCLE: Record<Tone, string> = {
    success: 'border-success/10 bg-success/10 text-success',
    pending: 'border-pending/10 bg-pending/10 text-pending',
    danger: 'border-danger/10 bg-danger/10 text-danger',
    primary: 'border-primary/10 bg-primary/10 text-primary',
};
const TONE_BAR: Record<Tone, string> = {
    success: 'bg-success',
    pending: 'bg-pending',
    danger: 'bg-danger',
    primary: 'bg-primary',
};

const slaText = computed(() => {
    const { elapsed_hours: elapsed, target_hours: target } = props.sla;
    if (props.incident.status === 'resolved') {
        return props.sla.resolved_in_time === false
            ? `Se resolvió en ${elapsed} h, ${target} h era el objetivo`
            : `Resuelta en ${elapsed} h de ${target} h`;
    }
    return props.incident.overdue
        ? `${elapsed} h abierta; el objetivo eran ${target} h`
        : `${elapsed} h de ${target} h`;
});

// Lo que la casa puso: reparación menos lo que se le cobró al huésped.
const houseCost = computed(() => {
    if (props.incident.cost === null) return null;
    return Math.max(0, props.incident.cost - (props.stay?.charged_total ?? 0));
});

// ── Resolver ──
const resolving = ref(false);
const resolveNotes = ref('');
const releaseRoom = ref(true);
const resolveCost = ref('');
const resolveTechnician = ref<string | number>('');
const showTechnicians = ref(false);

function openResolve() {
    resolveNotes.value = '';
    releaseRoom.value = true;
    resolveCost.value =
        props.incident.cost !== null ? String(props.incident.cost) : '';
    resolveTechnician.value = props.incident.technician_id ?? '';
    resolving.value = true;
}

async function confirmResolve() {
    const releasing =
        releaseRoom.value && props.incident.room_status === 'maintenance';
    const ok = await patch({
        status: 'resolved',
        resolution_notes: resolveNotes.value || null,
        release_room: releasing,
        cost: resolveCost.value === '' ? null : Number(resolveCost.value),
        technician_id:
            resolveTechnician.value === ''
                ? null
                : Number(resolveTechnician.value),
    });
    if (ok) resolving.value = false;
}

// ── Fotos ──
const photoInput = ref<HTMLInputElement>();
const uploadingPhoto = ref(false);

async function onPhotoPicked(event: Event) {
    const files = Array.from((event.target as HTMLInputElement).files ?? []);
    if (photoInput.value) photoInput.value.value = '';
    if (!files.length) return;
    uploadingPhoto.value = true;
    try {
        for (const file of files) {
            const body = new FormData();
            body.append('file', file);
            await axios.post(
                `/api/incidents/${props.incident.id}/photos`,
                body,
            );
        }
        router.reload();
    } catch (e: any) {
        toast.error(
            'Error',
            e.response?.data?.message ?? 'No se pudo subir la foto.',
        );
    } finally {
        uploadingPhoto.value = false;
    }
}

// ── Visor de fotos: la evidencia se abre en modal, no en otra pestaña ──
const viewingIndex = ref<number | null>(null);
const viewingPhoto = computed(() =>
    viewingIndex.value === null
        ? null
        : (props.incident.photos[viewingIndex.value] ?? null),
);

function stepPhoto(delta: number) {
    if (viewingIndex.value === null) return;
    const total = props.incident.photos.length;
    viewingIndex.value = (viewingIndex.value + delta + total) % total;
}

async function destroyPhoto(photo: IncidentPhoto) {
    try {
        await axios.delete(
            `/api/incidents/${props.incident.id}/photos/${photo.id}`,
        );
        router.reload();
    } catch {
        toast.error('Error', 'No se pudo eliminar la foto.');
    }
}

// Antigüedad legible: "3 h abierta" o "2 días abierta".
const ageLabel = computed(() => {
    const hours = props.incident.age_hours;
    if (hours < 24) {
        return `${hours} h abierta`;
    }
    const days = Math.floor(hours / 24);
    return `${days} ${days === 1 ? 'día' : 'días'} abierta`;
});

/** El círculo del encabezado habla del estado: rojo abierta, verde lista. */
const headIcon = computed<Icon>(() =>
    props.incident.status === 'resolved'
        ? 'CircleCheck'
        : props.incident.status === 'in_progress'
          ? 'Hammer'
          : 'Wrench',
);
const headIconClass = computed(() =>
    props.incident.status === 'resolved'
        ? 'border-success/10 bg-success/10 text-success'
        : props.incident.status === 'in_progress'
          ? 'border-warning/10 bg-warning/10 text-warning'
          : 'border-danger/10 bg-danger/10 text-danger',
);

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const factRow =
    'flex items-center justify-between gap-3 border-b border-dashed border-slate-200/70 pb-2.5 text-xs dark:border-darkmode-400';
const stripDivider =
    'hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400';

// ── Eliminar ──
const deleting = ref(false);

async function destroy() {
    try {
        await axios.delete(`/api/incidents/${props.incident.id}`);
        toast.success('Incidencia eliminada', 'Se quitó del historial.');
        router.visit(route('tenant.incidents'));
    } catch {
        toast.error('Error', 'No se pudo eliminar.');
    }
}
</script>

<template>
    <RazeLayout :title="`Incidencia · ${incident.title}`">
        <div class="mt-2">
            <!-- Volver: pastilla, no un botón compitiendo con las acciones
                 que sí cambian el ticket. -->
            <Link
                :href="route('tenant.incidents')"
                class="mb-2.5 inline-flex h-8 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
            >
                <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                Volver a incidencias
            </Link>

            <!-- Encabezado: la falla y su estado arriba, el contexto duro
                 (dónde, desde cuándo, quién) en la franja de abajo. -->
            <div class="box box--stacked overflow-hidden">
                <div
                    class="flex flex-col gap-4 p-5 md:flex-row md:items-start md:justify-between"
                >
                    <div class="flex min-w-0 gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border"
                            :class="headIconClass"
                        >
                            <Lucide :icon="headIcon" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-base font-medium">
                                {{ incident.title }}
                            </h1>
                            <div
                                class="mt-1.5 flex flex-wrap items-center gap-1.5"
                            >
                                <span
                                    class="rounded-full px-2.5 py-1 text-[11px] font-medium"
                                    :class="statusClass(incident.status)"
                                >
                                    {{ incident.status_label }}
                                </span>
                                <span
                                    class="rounded-full px-2.5 py-1 text-[11px] font-medium"
                                    :class="priorityClass(incident.priority)"
                                >
                                    Prioridad {{ incident.priority_label }}
                                </span>
                                <span
                                    v-if="incident.category_label"
                                    class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                >
                                    {{ incident.category_label }}
                                </span>
                                <span
                                    v-if="incident.guest_reported"
                                    class="rounded-full bg-info/10 px-2.5 py-1 text-[11px] font-medium text-info"
                                >
                                    Reportó huésped
                                </span>
                                <span
                                    v-if="dueLabel"
                                    class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-medium"
                                    :class="
                                        incident.overdue
                                            ? 'bg-danger/10 text-danger'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400 dark:text-slate-300'
                                    "
                                >
                                    <Lucide icon="Timer" class="h-3.5 w-3.5" />
                                    {{ dueLabel }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div
                        v-if="canManage"
                        class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:shrink-0 md:flex-wrap md:items-center md:gap-2"
                    >
                        <Button
                            v-if="incident.status === 'open'"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs"
                            :disabled="busy"
                            @click="
                                patch(
                                    { status: 'in_progress' },
                                    'La incidencia quedó en proceso.',
                                )
                            "
                        >
                            <Lucide icon="Hammer" class="mr-1.5 h-3.5 w-3.5" />
                            Marcar en proceso
                        </Button>
                        <Button
                            v-if="incident.status !== 'resolved'"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                            :disabled="busy"
                            @click="openResolve()"
                        >
                            <Lucide
                                icon="CircleCheck"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Resolver
                        </Button>
                        <Button
                            v-else
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs"
                            :disabled="busy"
                            @click="
                                patch(
                                    { status: 'open' },
                                    'La incidencia se reabrió.',
                                )
                            "
                        >
                            <Lucide
                                icon="RefreshCw"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Reabrir
                        </Button>
                        <Button
                            v-if="canDelete"
                            variant="outline-danger"
                            class="h-9 rounded-[0.5rem] bg-white text-xs"
                            @click="deleting = true"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            Eliminar
                        </Button>
                    </div>
                </div>

                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-slate-200/60 bg-slate-50/70 px-5 py-3 text-xs dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <!-- La habitación lleva a su ficha: desde aquí se ve si
                         el cuarto está vendido o detenido. -->
                    <Link
                        v-if="incident.room_id"
                        :href="route('tenant.rooms.show', incident.room_id)"
                        class="inline-flex items-center gap-1.5 text-slate-500 transition hover:text-primary"
                    >
                        <Lucide
                            icon="BedDouble"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                        >
                            {{ incident.room }}
                        </span>
                    </Link>
                    <span
                        v-else
                        class="inline-flex items-center gap-1.5 text-slate-500"
                    >
                        <Lucide
                            icon="Map"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                        >
                            Área general
                        </span>
                    </span>
                    <span :class="stripDivider" />
                    <span
                        class="inline-flex items-center gap-1.5 text-slate-500"
                    >
                        <Lucide
                            icon="CalendarDays"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        Reportada
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                        >
                            {{ incident.created_at }}
                        </span>
                        <template v-if="incident.reported_by">
                            por {{ incident.reported_by }}
                        </template>
                    </span>
                    <span :class="stripDivider" />
                    <span
                        class="inline-flex items-center gap-1.5 text-slate-500"
                    >
                        <Lucide
                            icon="Clock"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                        >
                            {{ ageLabel }}
                        </span>
                    </span>
                    <span
                        v-if="incident.status === 'resolved'"
                        class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-[11px] font-medium text-success md:ml-auto"
                    >
                        <Lucide icon="CircleCheck" class="h-3.5 w-3.5" />
                        Resuelta {{ incident.resolved_at }}
                    </span>
                </div>
            </div>

            <!-- El ticket en números: reloj, dinero y reincidencia -->
            <div class="mt-4 grid grid-cols-12 gap-5">
                <div class="col-span-6 xl:col-span-3">
                    <div class="box box--stacked h-full p-4">
                        <div class="flex items-center gap-3">
                            <div
                                :class="[sectionIcon, TONE_CIRCLE[slaTone]]"
                            >
                                <Lucide icon="Timer" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="truncate text-[11px] text-slate-500">
                                    Tiempo objetivo
                                </div>
                                <div class="text-base font-medium">
                                    {{ sla.elapsed_hours }} h
                                </div>
                            </div>
                        </div>
                        <div
                            class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                        >
                            <div
                                class="h-full rounded-full"
                                :class="TONE_BAR[slaTone]"
                                :style="{ width: `${sla.percent}%` }"
                            />
                        </div>
                        <p class="mt-1.5 text-[11px] text-slate-500">
                            {{ slaText }}
                        </p>
                    </div>
                </div>

                <div class="col-span-6 xl:col-span-3">
                    <div class="box box--stacked h-full p-4">
                        <div class="flex items-center gap-3">
                            <div
                                :class="sectionIcon"
                                class="border-primary/10 bg-primary/10 text-primary"
                            >
                                <Lucide icon="Wrench" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="truncate text-[11px] text-slate-500">
                                    Costó reparar
                                </div>
                                <div class="text-base font-medium">
                                    {{
                                        incident.cost !== null
                                            ? money.format(incident.cost)
                                            : 'Sin registrar'
                                    }}
                                </div>
                            </div>
                        </div>
                        <p class="mt-3 text-[11px] text-slate-500">
                            <template v-if="incident.technician">
                                Lo reparó {{ incident.technician }}
                            </template>
                            <template v-else>
                                Falta registrar quién lo reparó
                            </template>
                        </p>
                    </div>
                </div>

                <div class="col-span-6 xl:col-span-3">
                    <div class="box box--stacked h-full p-4">
                        <div class="flex items-center gap-3">
                            <div
                                :class="sectionIcon"
                                class="border-success/10 bg-success/10 text-success"
                            >
                                <Lucide icon="Receipt" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="truncate text-[11px] text-slate-500">
                                    Se le cobró
                                </div>
                                <div class="text-base font-medium">
                                    {{
                                        stay
                                            ? money.format(stay.charged_total)
                                            : 'No aplica'
                                    }}
                                </div>
                            </div>
                        </div>
                        <p class="mt-3 text-[11px] text-slate-500">
                            <template v-if="!stay">
                                No salió de una estancia
                            </template>
                            <template v-else-if="houseCost === null">
                                Falta el costo de la reparación
                            </template>
                            <template v-else-if="houseCost > 0">
                                La casa puso {{ money.format(houseCost) }}
                            </template>
                            <template v-else>
                                El cobro alcanzó
                            </template>
                        </p>
                    </div>
                </div>

                <div class="col-span-6 xl:col-span-3">
                    <div class="box box--stacked h-full p-4">
                        <div class="flex items-center gap-3">
                            <div
                                :class="sectionIcon"
                                class="border-pending/10 bg-pending/10 text-pending"
                                v-if="(roomHistory?.same_category ?? 0) > 0"
                            >
                                <Lucide icon="History" class="h-4 w-4" />
                            </div>
                            <div
                                v-else
                                :class="sectionIcon"
                                class="border-slate-200/70 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400"
                            >
                                <Lucide icon="History" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="truncate text-[11px] text-slate-500">
                                    Fallas previas del cuarto
                                </div>
                                <div class="text-base font-medium">
                                    {{ roomHistory?.total ?? 0 }}
                                </div>
                            </div>
                        </div>
                        <p class="mt-3 text-[11px] text-slate-500">
                            <template v-if="!roomHistory">
                                Es un área general, sin habitación
                            </template>
                            <template v-else-if="roomHistory.same_category > 0">
                                {{ roomHistory.same_category }} del mismo tipo:
                                conviene ver la causa
                            </template>
                            <template v-else-if="roomHistory.total === 0">
                                Primera falla registrada aquí
                            </template>
                            <template v-else>
                                {{ roomHistory.open }} sin resolver
                            </template>
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-5">
                <div class="col-span-12 space-y-5 xl:col-span-8">
                    <!-- Qué pasó -->
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-primary/10 bg-primary/10 text-primary"
                            >
                                <Lucide icon="Wrench" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">Qué pasó</h2>
                                <p class="text-xs text-slate-500">
                                    Lo que reportó quien la levantó.
                                </p>
                            </div>
                        </div>
                        <div class="px-4 py-3">
                            <p
                                v-if="incident.description"
                                class="text-xs leading-relaxed whitespace-pre-line text-slate-600 dark:text-slate-300"
                            >
                                {{ incident.description }}
                            </p>
                            <p v-else class="text-xs text-slate-400">
                                Se levantó sin descripción.
                            </p>
                            <div
                                v-if="incident.status === 'resolved'"
                                class="mt-3 rounded-lg bg-success/10 px-3.5 py-2.5 text-xs text-success"
                            >
                                <span class="font-medium">
                                    Resuelta el {{ incident.resolved_at
                                    }}<template v-if="incident.resolved_by">
                                        por {{ incident.resolved_by }}</template
                                    >.
                                </span>
                                <span
                                    v-if="incident.resolution_notes"
                                    class="mt-0.5 block whitespace-pre-line"
                                >
                                    {{ incident.resolution_notes }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Estancia que causó el daño: lo que se le cobró
                         al huésped, junto a lo que costó repararlo -->
                    <div v-if="stay" class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-success/10 bg-success/10 text-success"
                            >
                                <Lucide icon="Receipt" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Se le cobró al huésped
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Cargos de daño que quedaron en esa cuenta.
                                </p>
                            </div>
                        </div>
                        <div class="px-4 py-3">
                            <div
                                class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <Lucide
                                        icon="UserRound"
                                        class="h-3.5 w-3.5 text-slate-400"
                                    />
                                    <span
                                        class="font-medium text-slate-700 dark:text-slate-300"
                                    >
                                        {{ stay.guest }}
                                    </span>
                                </span>
                                <span :class="stripDivider" />
                                <span class="inline-flex items-center gap-1.5">
                                    <Lucide
                                        icon="CalendarDays"
                                        class="h-3.5 w-3.5 text-slate-400"
                                    />
                                    {{ stay.check_in_at ?? '—' }}
                                    <template v-if="stay.check_out_at">
                                        <span class="text-slate-400">→</span>
                                        {{ stay.check_out_at }}
                                    </template>
                                </span>
                            </div>
                            <div
                                v-if="stay.charges.length"
                                class="mt-2.5 divide-y divide-dashed divide-slate-200/70 rounded-lg border border-slate-200/70 dark:divide-darkmode-400 dark:border-darkmode-400"
                            >
                                <div
                                    v-for="(charge, index) in stay.charges"
                                    :key="index"
                                    class="flex items-center justify-between gap-3 px-3 py-2 text-xs"
                                >
                                    <span class="min-w-0 truncate">
                                        {{ charge.concept }}
                                    </span>
                                    <span class="shrink-0 font-medium">
                                        {{ money.format(charge.amount) }}
                                    </span>
                                </div>
                                <div
                                    class="flex items-center justify-between gap-3 px-3 py-2 text-xs font-medium"
                                >
                                    <span>Total cobrado</span>
                                    <span>
                                        {{ money.format(stay.charged_total) }}
                                    </span>
                                </div>
                            </div>
                            <p v-else class="mt-2.5 text-xs text-slate-400">
                                No quedó cargo de daño en esa cuenta.
                            </p>
                            <div
                                v-if="advanced && incident.cost !== null"
                                class="mt-2.5 rounded-lg px-3 py-2 text-xs"
                                :class="
                                    stay.charged_total >= incident.cost
                                        ? 'bg-success/10 text-success'
                                        : 'bg-pending/10 text-pending'
                                "
                            >
                                <template
                                    v-if="stay.charged_total >= incident.cost"
                                >
                                    Reparar costó
                                    {{ money.format(incident.cost) }}: el cobro
                                    alcanzó.
                                </template>
                                <template v-else>
                                    Reparar costó
                                    {{ money.format(incident.cost) }} y se
                                    cobraron
                                    {{ money.format(stay.charged_total) }}: la
                                    casa puso
                                    {{
                                        money.format(
                                            incident.cost - stay.charged_total,
                                        )
                                    }}.
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Fotos -->
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-info/10 bg-info/10 text-info"
                            >
                                <Lucide icon="Camera" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Fotos de evidencia
                                </h2>
                                <p class="text-xs text-slate-500">
                                    {{
                                        incident.photos.length
                                            ? `${incident.photos.length} ${incident.photos.length === 1 ? 'foto' : 'fotos'} del daño.`
                                            : 'Todavía sin fotos del daño.'
                                    }}
                                </p>
                            </div>
                        </div>
                        <div
                            class="flex flex-wrap items-center gap-2 px-4 py-3"
                        >
                            <div
                                v-for="(photo, index) in incident.photos"
                                :key="photo.id"
                                class="relative h-20 w-20 overflow-hidden rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                            >
                                <button
                                    type="button"
                                    class="block h-full w-full cursor-zoom-in"
                                    title="Ver en grande"
                                    @click="viewingIndex = index"
                                >
                                    <img
                                        :src="photo.url"
                                        alt="Evidencia de la incidencia"
                                        class="h-full w-full object-cover"
                                    />
                                </button>
                                <button
                                    v-if="canManage"
                                    type="button"
                                    class="absolute top-1 right-1 flex h-5 w-5 items-center justify-center rounded-full bg-danger text-white"
                                    title="Eliminar foto"
                                    @click="destroyPhoto(photo)"
                                >
                                    <Lucide icon="X" class="h-3 w-3" />
                                </button>
                            </div>
                            <button
                                v-if="canManage"
                                type="button"
                                class="flex h-20 w-20 flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-slate-300/70 text-slate-400 transition hover:border-primary hover:text-primary dark:border-darkmode-400"
                                :disabled="uploadingPhoto"
                                title="Agregar fotos"
                                @click="photoInput?.click()"
                            >
                                <Lucide
                                    :icon="
                                        uploadingPhoto
                                            ? 'RefreshCw'
                                            : 'ImagePlus'
                                    "
                                    class="h-5 w-5"
                                    :class="uploadingPhoto && 'animate-spin'"
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
                            <p
                                v-if="!incident.photos.length && !canManage"
                                class="text-xs text-slate-400"
                            >
                                Sin fotos.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 space-y-5 xl:col-span-4">
                    <!-- Seguimiento: quién la atiende y qué costó -->
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-warning/10 bg-warning/10 text-warning"
                            >
                                <Lucide icon="HardHat" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">Seguimiento</h2>
                                <p class="text-xs text-slate-500">
                                    Quién la atiende y qué costó repararla.
                                </p>
                            </div>
                        </div>
                        <div class="space-y-3 px-4 py-3">
                            <div>
                                <div class="text-[11px] text-slate-500">
                                    Atiende
                                </div>
                                <FormSelect
                                    v-if="canManage && advanced"
                                    v-model="assignSel"
                                    class="mt-1 h-9 text-xs"
                                    :disabled="busy"
                                    @change="reassign"
                                >
                                    <option value="">Sin asignar</option>
                                    <option
                                        v-for="person in staff"
                                        :key="person.id"
                                        :value="person.id"
                                    >
                                        {{ person.name }}
                                    </option>
                                </FormSelect>
                                <div v-else class="mt-0.5 text-sm font-medium">
                                    {{ incident.assignee ?? 'Sin asignar' }}
                                </div>
                            </div>

                            <!-- Prioridad y tipo se corrigen aquí: antes solo
                                 se elegían al levantar el ticket y una falla
                                 mal clasificada se quedaba así. -->
                            <div v-if="canManage" class="grid grid-cols-2 gap-2">
                                <div>
                                    <div class="text-[11px] text-slate-500">
                                        Prioridad
                                    </div>
                                    <FormSelect
                                        v-model="prioritySel"
                                        class="mt-1 h-9 text-xs"
                                        :disabled="busy"
                                        @change="changePriority"
                                    >
                                        <option value="high">Alta</option>
                                        <option value="medium">Media</option>
                                        <option value="low">Baja</option>
                                    </FormSelect>
                                </div>
                                <div>
                                    <div class="text-[11px] text-slate-500">
                                        Tipo de falla
                                    </div>
                                    <FormSelect
                                        v-model="categorySel"
                                        class="mt-1 h-9 text-xs"
                                        :disabled="busy"
                                        @change="changeCategory"
                                    >
                                        <option value="">Sin clasificar</option>
                                        <option
                                            v-for="option in categories"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </option>
                                    </FormSelect>
                                </div>
                            </div>

                            <template v-if="advanced">
                                <template v-if="!editingCost">
                                    <div :class="factRow">
                                        <span class="text-slate-500">
                                            Costó reparar
                                        </span>
                                        <span class="flex items-center gap-2">
                                            <span class="font-medium">
                                                {{
                                                    incident.cost !== null
                                                        ? money.format(
                                                              incident.cost,
                                                          )
                                                        : 'Sin registrar'
                                                }}
                                            </span>
                                            <button
                                                v-if="canManage"
                                                type="button"
                                                class="text-primary hover:underline"
                                                @click="openCostEditor"
                                            >
                                                Editar
                                            </button>
                                        </span>
                                    </div>
                                    <div :class="factRow">
                                        <span class="text-slate-500">
                                            Lo reparó
                                        </span>
                                        <span class="flex items-center gap-2">
                                            <span class="font-medium">
                                                {{
                                                    incident.technician ??
                                                    'Sin registrar'
                                                }}
                                            </span>
                                            <a
                                                v-if="technicianWa"
                                                :href="technicianWa"
                                                target="_blank"
                                                rel="noopener"
                                                class="flex items-center gap-1 text-success"
                                                title="Pasarle la falla por WhatsApp"
                                            >
                                                <Lucide
                                                    icon="MessageCircle"
                                                    class="h-3.5 w-3.5"
                                                />
                                            </a>
                                        </span>
                                    </div>
                                </template>

                                <!-- Captura en línea: una cifra mal puesta ya
                                     no obliga a reabrir y volver a resolver. -->
                                <div
                                    v-else
                                    class="space-y-2 rounded-lg border border-primary/30 p-3"
                                >
                                    <div>
                                        <div class="text-[11px] text-slate-500">
                                            Costó reparar
                                        </div>
                                        <FormInput
                                            v-model="costDraft"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            placeholder="0.00"
                                            class="mt-1 h-9 text-xs"
                                        />
                                    </div>
                                    <div>
                                        <div class="text-[11px] text-slate-500">
                                            Lo reparó
                                        </div>
                                        <FormSelect
                                            v-model="technicianDraft"
                                            class="mt-1 h-9 text-xs"
                                        >
                                            <option value="">
                                                Sin registrar
                                            </option>
                                            <option
                                                v-for="tech in technicians"
                                                :key="tech.id"
                                                :value="tech.id"
                                            >
                                                {{ tech.name
                                                }}{{
                                                    tech.on_duty
                                                        ? ' · en turno'
                                                        : ''
                                                }}
                                            </option>
                                        </FormSelect>
                                    </div>
                                    <div class="flex justify-end gap-2 pt-1">
                                        <Button
                                            variant="outline-secondary"
                                            class="h-8 rounded-[0.5rem] bg-white text-xs"
                                            @click="editingCost = false"
                                        >
                                            Cancelar
                                        </Button>
                                        <Button
                                            variant="primary"
                                            class="h-8 rounded-[0.5rem] text-xs"
                                            :disabled="busy"
                                            @click="saveCost"
                                        >
                                            Guardar
                                        </Button>
                                    </div>
                                </div>
                            </template>
                            <!-- Rol del día de mantenimiento: asignarle a
                                 quien no entra hasta mañana es como se
                                 vencen los tiempos objetivo. -->
                            <div
                                v-if="advanced && onDuty.length"
                                class="rounded-lg border border-slate-200/70 px-3 py-2 dark:border-darkmode-400"
                            >
                                <div
                                    class="flex items-center gap-1.5 text-[11px] text-slate-500"
                                >
                                    <Lucide
                                        icon="CalendarClock"
                                        class="h-3.5 w-3.5"
                                    />
                                    Mantenimiento hoy
                                </div>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    <span
                                        v-for="person in onDuty"
                                        :key="person.id"
                                        class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px]"
                                        :class="
                                            person.now
                                                ? 'border-success/30 bg-success/5 text-success'
                                                : 'border-slate-200/70 text-slate-500 dark:border-darkmode-400'
                                        "
                                    >
                                        <span
                                            v-if="person.now"
                                            class="h-1.5 w-1.5 rounded-full bg-success"
                                        />
                                        {{ person.name }}
                                        <span class="text-slate-400">{{
                                            person.shift
                                        }}</span>
                                    </span>
                                </div>
                            </div>

                            <div :class="factRow" class="!border-b-0 !pb-0">
                                <span class="text-slate-500">
                                    Tiempo objetivo
                                </span>
                                <span
                                    class="font-medium"
                                    :class="incident.overdue && 'text-danger'"
                                >
                                    {{ incident.due_at ?? 'Sin tiempo fijado' }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Esta habitación: una falla suelta se arregla, una
                         que se repite se investiga. -->
                    <div
                        v-if="roomHistory && roomHistory.total > 0"
                        class="box box--stacked"
                    >
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-pending/10 bg-pending/10 text-pending"
                            >
                                <Lucide icon="History" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Historial del cuarto
                                </h2>
                                <p class="text-xs text-slate-500">
                                    {{ roomHistory.total }} falla(s) antes de
                                    esta<template v-if="roomHistory.spent > 0">
                                        · {{ money.format(roomHistory.spent) }}
                                        gastados</template
                                    >.
                                </p>
                            </div>
                        </div>
                        <div class="px-4 py-3">
                            <ul class="space-y-2">
                                <li
                                    v-for="other in roomHistory.recent"
                                    :key="other.id"
                                >
                                    <Link
                                        :href="
                                            route(
                                                'tenant.incidents.show',
                                                other.id,
                                            )
                                        "
                                        class="flex items-start gap-2 rounded-lg border border-slate-200/70 px-3 py-2 transition hover:border-primary/40 dark:border-darkmode-400"
                                    >
                                        <span
                                            class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full"
                                            :class="
                                                other.status === 'resolved'
                                                    ? 'bg-success'
                                                    : 'bg-danger'
                                            "
                                        />
                                        <span class="min-w-0 flex-1">
                                            <span
                                                class="block truncate text-xs font-medium"
                                            >
                                                {{ other.title }}
                                            </span>
                                            <span
                                                class="mt-0.5 block text-[11px] text-slate-400"
                                            >
                                                {{ other.created_at }} ·
                                                {{ other.status_label
                                                }}<template
                                                    v-if="other.category_label"
                                                >
                                                    ·
                                                    {{
                                                        other.category_label
                                                    }}</template
                                                >
                                            </span>
                                        </span>
                                    </Link>
                                </li>
                            </ul>
                            <Link
                                v-if="roomHistory.total > roomHistory.recent.length"
                                :href="route('tenant.incidents')"
                                class="mt-2.5 block text-center text-xs font-medium text-primary hover:underline"
                            >
                                Ver todas en el listado
                            </Link>
                        </div>
                    </div>

                    <!-- Línea de tiempo -->
                    <div class="box box--stacked">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-slate-200/70 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400"
                            >
                                <Lucide icon="History" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Línea de tiempo
                                </h2>
                                <p class="text-xs text-slate-500">
                                    Cada movimiento del ticket y lo que anota
                                    quien lo atiende.
                                </p>
                            </div>
                        </div>
                        <div class="px-4 py-3">
                            <!-- Nota de seguimiento: dejar constancia
                                 ("pedí la refacción", "el técnico vuelve el
                                 jueves") sin tener que mover el estado. -->
                            <form
                                v-if="canManage"
                                class="mb-4"
                                @submit.prevent="addNote"
                            >
                                <FormTextarea
                                    v-model="note"
                                    rows="2"
                                    maxlength="1000"
                                    class="text-xs"
                                    placeholder="Anota lo que va pasando: refacción pedida, visita del técnico, acuerdo con el huésped…"
                                />
                                <div class="mt-2 flex justify-end">
                                    <Button
                                        type="submit"
                                        variant="outline-primary"
                                        class="h-8 rounded-[0.5rem] bg-white text-xs"
                                        :disabled="!note.trim() || savingNote"
                                    >
                                        <Lucide
                                            icon="MessageSquarePlus"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        {{
                                            savingNote
                                                ? 'Guardando…'
                                                : 'Agregar a la bitácora'
                                        }}
                                    </Button>
                                </div>
                            </form>

                            <div
                                class="space-y-3 border-l border-dashed border-slate-300/70 pl-4 dark:border-darkmode-400"
                            >
                                <div
                                    v-for="(entry, index) in timeline"
                                    :key="index"
                                    class="relative"
                                >
                                    <span
                                        class="absolute top-0.5 -left-[1.45rem] flex h-5 w-5 items-center justify-center rounded-full"
                                        :class="
                                            entry.kind === 'note'
                                                ? 'bg-primary/10 text-primary'
                                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                        "
                                    >
                                        <Lucide
                                            :icon="entry.icon"
                                            class="h-3 w-3"
                                        />
                                    </span>
                                    <p
                                        v-for="line in entry.lines"
                                        :key="line"
                                        class="text-xs whitespace-pre-line text-slate-600 dark:text-slate-300"
                                        :class="
                                            entry.kind === 'note' &&
                                            'rounded-lg bg-slate-50 px-2.5 py-1.5 dark:bg-darkmode-600/50'
                                        "
                                    >
                                        {{ line }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-[11px] text-slate-400"
                                    >
                                        {{ entry.date
                                        }}<template v-if="entry.by">
                                            · {{ entry.by }}</template
                                        >
                                    </p>
                                </div>
                                <p
                                    v-if="!timeline.length"
                                    class="text-xs text-slate-400"
                                >
                                    Sin movimientos registrados.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal resolver -->
        <Dialog :open="resolving" @close="resolving = false">
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
                                Resolver "{{ incident.title }}"
                            </h2>
                            <p class="text-xs text-slate-500">
                                {{ incident.room ?? 'Área general' }}
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
                                        {{ technician.kind_label
                                        }}{{
                                            technician.on_duty
                                                ? ' · en turno'
                                                : ''
                                        }}
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
                            v-if="incident.room_status === 'maintenance'"
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
                            @click="resolving = false"
                            >Cancelar</Button
                        >
                        <Button
                            variant="success"
                            class="min-h-11 text-white"
                            :disabled="busy"
                            @click="confirmResolve"
                        >
                            {{ busy ? 'Guardando…' : 'Marcar como resuelta' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Visor de evidencia: la foto en grande sin salir de la página -->
        <Dialog
            size="xl"
            :open="viewingPhoto !== null"
            @close="viewingIndex = null"
        >
            <Dialog.Panel>
                <div class="p-5">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                            >
                                <Lucide
                                    icon="Camera"
                                    class="h-5 w-5 text-primary"
                                />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-base font-medium">Evidencia</h2>
                                <p class="truncate text-xs text-slate-500">
                                    Foto {{ (viewingIndex ?? 0) + 1 }} de
                                    {{ incident.photos.length }} ·
                                    {{ incident.title }}
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 rounded p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-darkmode-400"
                            title="Cerrar"
                            @click="viewingIndex = null"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>
                    <div
                        class="relative flex min-h-[16rem] items-center justify-center overflow-hidden rounded-lg bg-dark p-2"
                    >
                        <img
                            v-if="viewingPhoto"
                            :src="viewingPhoto.url"
                            alt="Evidencia de la incidencia"
                            class="max-h-[70vh] w-auto max-w-full rounded object-contain"
                        />
                        <template v-if="incident.photos.length > 1">
                            <button
                                type="button"
                                class="absolute top-1/2 left-2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/15 text-white transition hover:bg-white/30"
                                title="Foto anterior"
                                @click="stepPhoto(-1)"
                            >
                                <Lucide icon="ChevronLeft" class="h-5 w-5" />
                            </button>
                            <button
                                type="button"
                                class="absolute top-1/2 right-2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/15 text-white transition hover:bg-white/30"
                                title="Foto siguiente"
                                @click="stepPhoto(1)"
                            >
                                <Lucide icon="ChevronRight" class="h-5 w-5" />
                            </button>
                        </template>
                    </div>
                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                        <Button
                            as="a"
                            :href="viewingPhoto?.url"
                            target="_blank"
                            rel="noopener"
                            variant="outline-secondary"
                            class="min-h-11 rounded-[0.5rem] bg-white"
                        >
                            <Lucide icon="ExternalLink" class="mr-2 h-4 w-4" />
                            Abrir original
                        </Button>
                        <Button
                            variant="primary"
                            class="min-h-11 rounded-[0.5rem]"
                            @click="viewingIndex = null"
                        >
                            Cerrar
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmar eliminación -->
        <Dialog :open="deleting" @close="deleting = false">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide
                        icon="AlertTriangle"
                        class="mx-auto mb-3 h-12 w-12 text-danger"
                    />
                    <h2 class="text-base font-medium">
                        ¿Eliminar "{{ incident.title }}"?
                    </h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Se borra el ticket y sus fotos. Si ya se atendió, mejor
                        márcala como resuelta para conservar el historial.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            class="min-h-11"
                            @click="deleting = false"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            class="min-h-11"
                            @click="destroy"
                            >Sí, eliminar</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <TechniciansDialog
            :open="showTechnicians"
            :technicians="technicians"
            :can-manage="canManage"
            @close="showTechnicians = false"
        />
    </RazeLayout>
</template>
