<script setup lang="ts">
import { router } from '@inertiajs/vue3';
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
    lines: string[];
}

const props = defineProps<{
    incident: IncidentDetail;
    timeline: TimelineEntry[];
    staff: Array<{ id: number; name: string }>;
    technicians: TechnicianRow[];
    stay: StayBlock | null;
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
            <!-- Header de tarjeta, mismo patrón que el índice: icono en
                 círculo + título con badges + acciones a la derecha -->
            <div
                class="box box--stacked flex flex-wrap items-center justify-between gap-4 p-5"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <div
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Wrench" class="h-7 w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1
                            class="flex flex-wrap items-center gap-2 text-xl font-medium"
                        >
                            {{ incident.title }}
                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="statusClass(incident.status)"
                            >
                                {{ incident.status_label }}
                            </span>
                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="priorityClass(incident.priority)"
                            >
                                {{ incident.priority_label }}
                            </span>
                            <span
                                v-if="incident.category_label"
                                class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                            >
                                {{ incident.category_label }}
                            </span>
                            <span
                                v-if="incident.guest_reported"
                                class="rounded-full bg-info/10 px-2.5 py-0.5 text-xs font-medium text-info"
                            >
                                Reportó huésped
                            </span>
                            <span
                                v-if="dueLabel"
                                class="flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="
                                    incident.overdue
                                        ? 'bg-danger/10 text-danger'
                                        : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400 dark:text-slate-300'
                                "
                            >
                                <Lucide icon="Timer" class="h-3.5 w-3.5" />
                                {{ dueLabel }}
                            </span>
                        </h1>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ incident.room ?? 'Área general' }} · reportada el
                            {{ incident.created_at
                            }}<template v-if="incident.reported_by">
                                por {{ incident.reported_by }}</template
                            >
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        as="a"
                        :href="route('tenant.incidents')"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="ArrowLeft"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Volver a Incidencias
                    </Button>
                    <template v-if="canManage">
                        <Button
                            v-if="incident.status === 'open'"
                            variant="outline-primary"
                            class="rounded-[0.5rem] bg-white"
                            :disabled="busy"
                            @click="
                                patch(
                                    { status: 'in_progress' },
                                    'La incidencia quedó en proceso.',
                                )
                            "
                        >
                            <Lucide icon="Hammer" class="mr-2 h-4 w-4" />
                            Marcar en proceso
                        </Button>
                        <Button
                            v-if="incident.status !== 'resolved'"
                            variant="primary"
                            class="rounded-[0.5rem] shadow-md shadow-primary/20"
                            :disabled="busy"
                            @click="openResolve()"
                        >
                            <Lucide icon="CircleCheck" class="mr-2 h-4 w-4" />
                            Resolver
                        </Button>
                        <Button
                            v-else
                            variant="outline-secondary"
                            class="rounded-[0.5rem] bg-white"
                            :disabled="busy"
                            @click="
                                patch(
                                    { status: 'open' },
                                    'La incidencia se reabrió.',
                                )
                            "
                        >
                            <Lucide icon="RefreshCw" class="mr-2 h-4 w-4" />
                            Reabrir
                        </Button>
                        <Button
                            v-if="canDelete"
                            variant="outline-danger"
                            class="rounded-[0.5rem] bg-white"
                            @click="deleting = true"
                        >
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" />
                            Eliminar
                        </Button>
                    </template>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-6">
                <div class="col-span-12 flex flex-col gap-6 xl:col-span-7">
                    <!-- Detalle -->
                    <div class="box box--stacked p-5">
                        <div
                            class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Wrench" class="h-3.5 w-3.5" />
                            Detalle
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <div class="text-xs text-slate-500">
                                    Habitación
                                </div>
                                <div class="mt-0.5 text-sm font-medium">
                                    {{ incident.room ?? 'Área general' }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-slate-500">
                                    Atiende
                                </div>
                                <FormSelect
                                    v-if="canManage && advanced"
                                    v-model="assignSel"
                                    class="mt-1"
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
                            <template v-if="advanced">
                                <div>
                                    <div class="text-xs text-slate-500">
                                        Costó reparar
                                    </div>
                                    <div class="mt-0.5 text-sm font-medium">
                                        {{
                                            incident.cost !== null
                                                ? money.format(incident.cost)
                                                : 'Sin registrar'
                                        }}
                                    </div>
                                </div>
                                <div>
                                    <div class="text-xs text-slate-500">
                                        Lo reparó
                                    </div>
                                    <div class="mt-0.5 text-sm font-medium">
                                        {{
                                            incident.technician ??
                                            'Sin registrar'
                                        }}
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div v-if="incident.description" class="mt-4">
                            <div class="text-xs text-slate-500">
                                Descripción
                            </div>
                            <p
                                class="mt-1 text-sm whitespace-pre-line text-slate-600 dark:text-slate-300"
                            >
                                {{ incident.description }}
                            </p>
                        </div>
                        <div
                            v-if="incident.status === 'resolved'"
                            class="mt-4 rounded-lg bg-success/10 px-3.5 py-2.5 text-sm text-success"
                        >
                            Resuelta el {{ incident.resolved_at
                            }}<template v-if="incident.resolved_by">
                                por {{ incident.resolved_by }}</template
                            >.
                            <span v-if="incident.resolution_notes">
                                {{ incident.resolution_notes }}
                            </span>
                        </div>
                    </div>

                    <!-- Estancia que causó el daño: lo que se le cobró
                         al huésped, junto a lo que costó repararlo -->
                    <div v-if="stay" class="box box--stacked p-5">
                        <div
                            class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Receipt" class="h-3.5 w-3.5" />
                            Se le cobró al huésped
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <div class="text-xs text-slate-500">
                                    Huésped
                                </div>
                                <div class="mt-0.5 text-sm font-medium">
                                    {{ stay.guest }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-slate-500">
                                    Estancia
                                </div>
                                <div class="mt-0.5 text-sm font-medium">
                                    {{ stay.check_in_at ?? '—' }}
                                    <template v-if="stay.check_out_at">
                                        a {{ stay.check_out_at }}
                                    </template>
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="stay.charges.length"
                            class="mt-4 divide-y divide-dashed divide-slate-200/70 rounded-lg border border-slate-200/70 dark:divide-darkmode-400 dark:border-darkmode-400"
                        >
                            <div
                                v-for="(charge, index) in stay.charges"
                                :key="index"
                                class="flex items-center justify-between gap-3 px-3.5 py-2.5 text-sm"
                            >
                                <span class="min-w-0 truncate">
                                    {{ charge.concept }}
                                </span>
                                <span class="shrink-0 font-medium">
                                    {{ money.format(charge.amount) }}
                                </span>
                            </div>
                            <div
                                class="flex items-center justify-between gap-3 px-3.5 py-2.5 text-sm font-medium"
                            >
                                <span>Total cobrado</span>
                                <span>{{
                                    money.format(stay.charged_total)
                                }}</span>
                            </div>
                        </div>
                        <p v-else class="mt-4 text-sm text-slate-400">
                            No quedó cargo de daño en esa cuenta.
                        </p>
                        <div
                            v-if="advanced && incident.cost !== null"
                            class="mt-3 rounded-lg px-3.5 py-2.5 text-sm"
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
                                {{ money.format(incident.cost) }} y se cobraron
                                {{ money.format(stay.charged_total) }}: la casa
                                puso
                                {{
                                    money.format(
                                        incident.cost - stay.charged_total,
                                    )
                                }}.
                            </template>
                        </div>
                    </div>

                    <!-- Fotos -->
                    <div class="box box--stacked p-5">
                        <div
                            class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Camera" class="h-3.5 w-3.5" />
                            Fotos de evidencia
                        </div>
                        <div class="flex flex-wrap items-center gap-2.5">
                            <div
                                v-for="(photo, index) in incident.photos"
                                :key="photo.id"
                                class="relative h-24 w-24 overflow-hidden rounded-lg border border-slate-200/70 dark:border-darkmode-400"
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
                                class="flex h-24 w-24 flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-slate-300/70 text-slate-400 transition hover:border-primary hover:text-primary dark:border-darkmode-400"
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
                                    class="h-6 w-6"
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
                                class="text-sm text-slate-400"
                            >
                                Sin fotos.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Línea de tiempo -->
                <div class="col-span-12 xl:col-span-5">
                    <div class="box box--stacked h-full p-5">
                        <div
                            class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="History" class="h-3.5 w-3.5" />
                            Línea de tiempo
                        </div>
                        <div
                            class="space-y-4 border-l border-dashed border-slate-300/70 pl-4 dark:border-darkmode-400"
                        >
                            <div
                                v-for="(entry, index) in timeline"
                                :key="index"
                                class="relative"
                            >
                                <span
                                    class="absolute top-0.5 -left-[1.45rem] flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-slate-500 dark:bg-darkmode-400"
                                >
                                    <Lucide
                                        :icon="entry.icon"
                                        class="h-3 w-3"
                                    />
                                </span>
                                <p
                                    v-for="line in entry.lines"
                                    :key="line"
                                    class="text-sm text-slate-600 dark:text-slate-300"
                                >
                                    {{ line }}
                                </p>
                                <p class="mt-0.5 text-xs text-slate-400">
                                    {{ entry.date
                                    }}<template v-if="entry.by">
                                        · {{ entry.by }}</template
                                    >
                                </p>
                            </div>
                            <p
                                v-if="!timeline.length"
                                class="text-sm text-slate-400"
                            >
                                Sin movimientos registrados.
                            </p>
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
