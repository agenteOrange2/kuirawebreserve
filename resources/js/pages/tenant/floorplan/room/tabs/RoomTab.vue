<script setup lang="ts">
import { computed, inject, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormDate, FormInput, FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import { FloorPlanKey } from '../../context';
import RoomForm from '../RoomForm.vue';
import type { RoomFormValue } from '../RoomForm.vue';
import { formatMoney, priceModifierLabel, usageBadgeTitle } from '../../format';

/**
 * Cuarto: cómo es la habitación y —con el candado "Editar plano" abierto— dar
 * de alta, renombrar, duplicar o quitarla.
 *
 * El formulario son tres campos a propósito: número, tipo y zona. El perfil
 * completo (camas, amenidades, cargos, ocupación, contador de usos) son veinte
 * campos y vive en /habitaciones — el plano es para operar, no para
 * administrar.
 */
const ctx = inject(FloorPlanKey)!;

const room = computed(() => ctx.room.value!);

const {
    canManage,
    canManageRooms,
    canToggleEdit,
    editMode,
    roomTypes,
    zones,
    incidentCategories,
    busyAction,
    saving,
    createRoom,
    updateRoom,
    duplicateRoom,
    deleteRoom,
    reportIncident,
    createBlock,
    deleteBlock,
    resetUsage,
} = ctx;

/* --- Cómo es la habitación ----------------------------------------------
 * Venían de la ficha vieja. Los datos duros en fila de tarjetas y las
 * amenidades agrupadas por tema: una lista plana de veinte no se lee cuando
 * hay que explicarle el cuarto a alguien que está esperando.
 */
const fichaItems = computed<{ icon: Icon; label: string; text: string }[]>(
    () => {
        const room = ctx.room.value;

        if (!room) {
            return [];
        }

        const items: { icon: Icon; label: string; text: string }[] = [];

        if (room.beds_label) {
            items.push({
                icon: 'BedDouble',
                label: 'Camas',
                text: room.beds_label,
            });
        }

        if (room.capacity) {
            items.push({
                icon: 'Users',
                label: 'Capacidad',
                text: `Hasta ${room.capacity} personas`,
            });
        }

        if (room.included_occupancy && room.extra_guest_fee) {
            items.push({
                icon: 'UserPlus',
                label: 'Persona extra',
                text: `${formatMoney(room.extra_guest_fee)} por persona después de ${room.included_occupancy}`,
            });
        }

        if (room.size_m2) {
            items.push({
                icon: 'Ruler',
                label: 'Superficie',
                text: `${room.size_m2} m²`,
            });
        }

        if (room.view) {
            items.push({ icon: 'Eye', label: 'Vista', text: room.view });
        }

        items.push(
            room.smoking
                ? {
                      icon: 'Cigarette',
                      label: 'Fumar',
                      text: 'Permitido',
                  }
                : {
                      icon: 'CigaretteOff',
                      label: 'Fumar',
                      text: 'No permitido',
                  },
        );

        if (room.accessible) {
            items.push({
                icon: 'Accessibility',
                label: 'Accesibilidad',
                text: 'Accesible',
            });
        }

        if (room.check_in_time || room.check_out_time) {
            const times = [
                room.check_in_time ? `Llegada ${room.check_in_time}` : null,
                room.check_out_time ? `Salida ${room.check_out_time}` : null,
            ].filter((part): part is string => part !== null);

            items.push({
                icon: 'Clock',
                label: 'Horarios',
                text: times.join(' · '),
            });
        }

        return items;
    },
);

interface AmenityGroup {
    title: string;
    icon: Icon;
    items: string[];
}

const amenityGroups = computed<AmenityGroup[]>(() => {
    const amenities = ctx.room.value?.amenities ?? [];
    const groups: AmenityGroup[] = [
        { title: 'Descanso y comodidad', icon: 'BedDouble', items: [] },
        { title: 'Entretenimiento y conexión', icon: 'Tv', items: [] },
        { title: 'Servicios y acceso', icon: 'ConciergeBell', items: [] },
        { title: 'Otros detalles', icon: 'Sparkles', items: [] },
    ];

    amenities.forEach((amenity) => {
        const normalized = amenity
            .normalize('NFD')
            .replace(/\p{Diacritic}/gu, '')
            .toLowerCase();

        if (
            /(tv|television|teatro|theater|cable|wifi|internet|audio|sonido)/.test(
                normalized,
            )
        ) {
            groups[1].items.push(amenity);
        } else if (
            /(servicio|comida|bebida|cochera|garage|garaje|puerta|estacionamiento)/.test(
                normalized,
            )
        ) {
            groups[2].items.push(amenity);
        } else if (
            /(cama|bano|espejo|iluminacion|piso|acabado|minisplit|clima|aire|colchon|almohada)/.test(
                normalized,
            )
        ) {
            groups[0].items.push(amenity);
        } else {
            groups[3].items.push(amenity);
        }
    });

    return groups.filter((group) => group.items.length > 0);
});

// Alta y edición comparten formulario: son los mismos tres campos y así no hay
// dos maneras de capturar lo mismo.
const mode = ref<'idle' | 'create' | 'edit'>('idle');
const form = ref({
    number: '',
    room_type_id: 0,
    zone_id: null as number | null,
});

const busy = computed(() => busyAction.value !== null || saving.value);
const occupied = computed(() => room.value.active_stay !== null);
const canEdit = computed(() => canManageRooms.value && editMode.value);

function startCreate() {
    mode.value = 'create';
    form.value = {
        number: '',
        // El tipo y la zona del cuarto abierto son la apuesta más probable:
        // casi siempre se da de alta el vecino del que se está viendo.
        room_type_id: room.value.room_type_id ?? roomTypes[0]?.id ?? 0,
        zone_id: room.value.zone_id,
    };
}

function startEdit() {
    mode.value = 'edit';
    form.value = {
        number: room.value.number,
        room_type_id: room.value.room_type_id ?? roomTypes[0]?.id ?? 0,
        zone_id: room.value.zone_id,
    };
}

function submit(payload: RoomFormValue) {
    if (mode.value === 'create') {
        createRoom(payload);
    } else {
        updateRoom(payload);
    }

    mode.value = 'idle';
}

// Cambiar de cuarto o cerrar el candado cierra el formulario: seguir editando
// el anterior es la manera segura de renombrar el equivocado.
watch([() => room.value.id, editMode], () => {
    mode.value = 'idle';
    incidentOpen.value = false;
    blockOpen.value = false;
});

/* --- Mantenimiento: reportar falla y programar fechas -------------------
 * Las dos operaciones ya existían en sus secciones; aquí se hacen desde donde
 * se descubre el problema, que es mirando la habitación.
 */
const incidentOpen = ref(false);
const incident = ref({
    title: '',
    category: '',
    priority: 'medium',
    description: '',
    source: 'staff',
    set_maintenance: false,
});
const incidentPhoto = ref<File | null>(null);

// Prioridad alta pre-marca sacar el cuarto de servicio: una falla urgente
// casi siempre impide venderlo. La casilla queda visible para desmarcarla.
watch(
    () => incident.value.priority,
    (priority) => {
        if (priority === 'high') {
            incident.value.set_maintenance = true;
        }
    },
);

function onIncidentPhoto(event: Event) {
    const input = event.target as HTMLInputElement;
    incidentPhoto.value = input.files?.[0] ?? null;
}

async function submitIncident() {
    if (!incident.value.title.trim()) {
        return;
    }

    await reportIncident({
        title: incident.value.title.trim(),
        category: incident.value.category || null,
        priority: incident.value.priority,
        description: incident.value.description.trim() || null,
        source: incident.value.source,
        set_maintenance: incident.value.set_maintenance,
        photo: incidentPhoto.value,
    });

    incident.value = {
        title: '',
        category: '',
        priority: 'medium',
        description: '',
        source: 'staff',
        set_maintenance: false,
    };
    incidentPhoto.value = null;
    incidentOpen.value = false;
}

const blockOpen = ref(false);
const block = ref({ starts_at: '', ends_at: '', reason: '' });

async function submitBlock() {
    if (!block.value.starts_at || !block.value.ends_at) {
        return;
    }

    await createBlock({
        starts_at: block.value.starts_at,
        ends_at: block.value.ends_at,
        reason: block.value.reason.trim() || null,
    });

    block.value = { starts_at: '', ends_at: '', reason: '' };
    blockOpen.value = false;
}

async function removeBlock(blockId: number) {
    if (
        !window.confirm('¿Retirar este bloqueo y volver a vender esas fechas?')
    ) {
        return;
    }

    await deleteBlock(blockId);
}
</script>

<template>
    <div class="space-y-5">
        <!-- Edición arriba y compacta: quien abre este tab con el candado
             puesto viene a editar, no a leer 250 líneas de ficha. -->
        <section
            v-if="canEdit"
            class="rounded-xl border border-primary/20 bg-primary/5 p-4 dark:border-primary/30 dark:bg-primary/10"
        >
            <template v-if="mode === 'idle'">
                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="primary"
                        class="min-h-11 rounded-[0.5rem] text-xs"
                        :disabled="busy || !roomTypes.length"
                        :title="
                            roomTypes.length
                                ? 'Da de alta una habitación en el centro del plano'
                                : 'Primero crea un tipo de habitación en el catálogo'
                        "
                        @click="startCreate"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Nueva habitación
                    </Button>
                    <Button
                        variant="outline-primary"
                        class="min-h-11 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                        :disabled="busy"
                        @click="startEdit"
                    >
                        <Lucide icon="Pencil" class="mr-1.5 h-3.5 w-3.5" />
                        Editar esta
                    </Button>
                    <Button
                        variant="outline-primary"
                        class="min-h-11 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                        :disabled="busy"
                        title="Copia el tipo, la zona y el tamaño con el siguiente número libre"
                        @click="duplicateRoom"
                    >
                        <Lucide icon="Copy" class="mr-1.5 h-3.5 w-3.5" />
                        Duplicar
                    </Button>
                    <Button
                        variant="outline-danger"
                        class="min-h-11 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                        :disabled="busy || occupied"
                        :title="
                            occupied
                                ? 'Tiene huésped adentro: registra la salida antes de quitarla'
                                : 'Quita la habitación del plano y del inventario'
                        "
                        @click="deleteRoom"
                    >
                        <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                        Quitar del plano
                    </Button>
                </div>
            </template>

            <template v-else>
                <div class="text-sm font-medium">
                    {{
                        mode === 'create'
                            ? 'Nueva habitación'
                            : `Editar la ${room.number}`
                    }}
                </div>
                <RoomForm
                    :mode="mode"
                    :initial="form"
                    :room-types="roomTypes"
                    :zones="zones"
                    :busy="busy"
                    class="mt-3"
                    @submit="submit"
                    @cancel="mode = 'idle'"
                />
            </template>
        </section>

        <!-- Con el candado cerrado, una línea; antes esto era una tarjeta
             entera diciendo lo que NO se puede hacer. -->
        <button
            v-else-if="canManageRooms && canToggleEdit"
            type="button"
            class="flex w-full items-center gap-2 rounded-xl border border-dashed border-slate-300 px-4 py-2.5 text-left text-xs text-slate-500 transition hover:border-primary/40 hover:text-primary dark:border-darkmode-400"
            @click="editMode = true"
        >
            <Lucide icon="Lock" class="h-4 w-4 shrink-0" />
            Desbloquea «Editar plano» para dar de alta, editar o quitar
            habitaciones.
        </button>

        <section
            class="rounded-xl border border-slate-200/70 bg-slate-50/80 p-4 dark:border-darkmode-400 dark:bg-darkmode-700/50"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3
                        class="text-sm font-medium text-slate-900 dark:text-slate-100"
                    >
                        Información de la habitación
                    </h3>
                    <p class="mt-1 text-xs text-slate-500">
                        Lo más importante para explicarle la habitación al
                        huésped.
                    </p>
                </div>
                <div
                    v-if="room.price_from !== null"
                    class="rounded-xl bg-white px-4 py-3 text-right shadow-sm dark:bg-darkmode-600"
                >
                    <div
                        class="text-xs font-medium tracking-wide text-slate-500 uppercase"
                    >
                        Desde
                    </div>
                    <div
                        class="mt-1 text-base font-semibold text-slate-900 dark:text-slate-100"
                    >
                        {{ formatMoney(room.price_from) }}
                    </div>
                </div>
            </div>

            <div
                v-if="fichaItems.length"
                class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2"
            >
                <div
                    v-for="item in fichaItems"
                    :key="item.label"
                    class="flex min-h-20 items-center gap-3 rounded-xl bg-white p-3.5 shadow-sm dark:bg-darkmode-600"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide :icon="item.icon" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <div
                            class="text-xs font-medium tracking-wide text-slate-500 uppercase"
                        >
                            {{ item.label }}
                        </div>
                        <div
                            class="mt-1 text-base leading-snug font-medium text-slate-800 dark:text-slate-100"
                        >
                            {{ item.text }}
                        </div>
                    </div>
                </div>
            </div>

            <p
                v-if="room.description"
                class="mt-4 rounded-xl bg-white p-3.5 text-sm leading-relaxed text-slate-600 shadow-sm dark:bg-darkmode-600 dark:text-slate-300"
            >
                {{ room.description }}
            </p>

            <div
                v-if="room.amenities.length"
                class="mt-5 border-t border-slate-200/70 pt-5 dark:border-darkmode-400"
            >
                <div>
                    <h4
                        class="text-sm font-medium text-slate-900 dark:text-slate-100"
                    >
                        Lo que incluye
                    </h4>
                    <p class="mt-1 text-xs text-slate-500">
                        Amenidades agrupadas para encontrarlas más rápido.
                    </p>
                </div>
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div
                        v-for="group in amenityGroups"
                        :key="group.title"
                        class="rounded-xl bg-white p-4 shadow-sm dark:bg-darkmode-600"
                    >
                        <div
                            class="flex items-center gap-2.5 text-sm font-semibold text-slate-800 dark:text-slate-100"
                        >
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                            >
                                <Lucide :icon="group.icon" class="h-5 w-5" />
                            </div>
                            {{ group.title }}
                        </div>
                        <ul class="mt-3 space-y-2.5">
                            <li
                                v-for="amenity in group.items"
                                :key="amenity"
                                class="flex items-start gap-2 text-sm leading-snug text-slate-600 dark:text-slate-300"
                            >
                                <Lucide
                                    icon="CircleCheck"
                                    class="mt-0.5 h-4 w-4 shrink-0 text-success"
                                />
                                <span>{{ amenity }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div
                v-if="room.optional_charges.length"
                class="mt-4 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
            >
                <div
                    class="text-sm font-semibold text-slate-900 dark:text-slate-100"
                >
                    Servicios con costo adicional
                </div>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    <div
                        v-for="charge in room.optional_charges"
                        :key="charge.concept"
                        class="flex items-center justify-between gap-3 rounded-xl bg-white px-3.5 py-3 text-sm shadow-sm dark:bg-darkmode-600"
                    >
                        <span class="flex items-center gap-2">
                            <Lucide
                                icon="CirclePlus"
                                class="h-5 w-5 shrink-0 text-primary"
                            />
                            {{ charge.concept }}
                        </span>
                        <span class="font-semibold">
                            {{ formatMoney(charge.amount) }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Las fotos que ya se suben en Catálogo: sirven para describir
                 la habitación a quien pregunta por teléfono. -->
            <div
                v-if="room.room_type_photos.length"
                class="mt-4 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
            >
                <div
                    class="text-sm font-semibold text-slate-900 dark:text-slate-100"
                >
                    Cómo se ve
                </div>
                <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
                    <a
                        v-for="photo in room.room_type_photos"
                        :key="photo.id"
                        :href="photo.url"
                        target="_blank"
                        rel="noopener"
                        class="shrink-0 overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
                        title="Abrir la foto en grande"
                    >
                        <img
                            :src="photo.thumb_url"
                            alt=""
                            class="h-28 w-40 object-cover"
                            loading="lazy"
                        />
                    </a>
                </div>
            </div>

            <div
                v-if="room.usage_count > 0 || room.usage_limit"
                class="mt-4 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
            >
                <div
                    class="text-sm font-semibold text-slate-900 dark:text-slate-100"
                >
                    Contador de usos
                </div>
                <div
                    class="mt-2 flex items-center gap-2 text-sm"
                    :class="
                        room.usage_locked
                            ? 'text-danger'
                            : 'text-slate-600 dark:text-slate-300'
                    "
                >
                    <Lucide
                        :icon="room.usage_locked ? 'Lock' : 'Repeat'"
                        class="h-4 w-4 shrink-0"
                    />
                    <span>{{ usageBadgeTitle(room) }}</span>
                </div>
                <!-- Liberar el candado es del mismo permiso que el semáforo:
                     recepción lo hace sin pasar por administración. -->
                <Button
                    v-if="
                        canManage && (room.usage_locked || room.usage_count > 0)
                    "
                    variant="outline-secondary"
                    class="mt-2 min-h-10 rounded-[0.5rem]"
                    :disabled="busy"
                    title="Pone el contador en cero y quita el candado de rotación"
                    @click="resetUsage"
                >
                    <Lucide icon="RotateCcw" class="mr-1.5 h-3.5 w-3.5" />
                    Reiniciar contador
                </Button>
            </div>

            <div
                v-if="room.notes"
                class="mt-4 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
            >
                <div
                    class="text-sm font-semibold text-slate-900 dark:text-slate-100"
                >
                    Información adicional
                </div>
                <p
                    class="mt-2 text-sm whitespace-pre-line text-slate-600 dark:text-slate-300"
                >
                    {{ room.notes }}
                </p>
            </div>

            <!-- Mantenimiento programado: el semáforo no lo
                         refleja, así que se dice aquí explícitamente. -->
            <div
                v-if="room.blocks.length || canManage"
                class="mt-4 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div
                        class="text-sm font-semibold text-slate-900 dark:text-slate-100"
                    >
                        Mantenimiento programado
                    </div>
                    <Button
                        v-if="canManage"
                        variant="outline-secondary"
                        class="min-h-10 rounded-[0.5rem]"
                        title="Aparta fechas para que no se vendan; el semáforo de hoy no cambia"
                        @click="blockOpen = !blockOpen"
                    >
                        <Lucide icon="CalendarOff" class="mr-1.5 h-3.5 w-3.5" />
                        {{ blockOpen ? 'Cancelar' : 'Programar fechas' }}
                    </Button>
                </div>

                <form
                    v-if="blockOpen"
                    class="mt-3 grid gap-3 rounded-xl bg-slate-50 p-3 sm:grid-cols-3 dark:bg-darkmode-700/50"
                    @submit.prevent="submitBlock"
                >
                    <div>
                        <label class="text-xs text-slate-500" for="block-from"
                            >Desde</label
                        >
                        <FormDate
                            id="block-from"
                            v-model="block.starts_at"
                            class="mt-1"
                            required
                        />
                    </div>
                    <div>
                        <label class="text-xs text-slate-500" for="block-to"
                            >Hasta</label
                        >
                        <FormDate
                            id="block-to"
                            v-model="block.ends_at"
                            class="mt-1"
                            required
                        />
                    </div>
                    <div>
                        <label class="text-xs text-slate-500" for="block-reason"
                            >Motivo (opcional)</label
                        >
                        <FormInput
                            id="block-reason"
                            v-model="block.reason"
                            type="text"
                            maxlength="255"
                            class="mt-1"
                            placeholder="Pintura, plomería…"
                        />
                    </div>
                    <div class="sm:col-span-3">
                        <Button
                            variant="primary"
                            type="submit"
                            class="min-h-11 rounded-[0.5rem] text-xs"
                            :disabled="busy"
                        >
                            Programar
                        </Button>
                    </div>
                </form>
                <div class="mt-3 space-y-2">
                    <div
                        v-for="block in room.blocks"
                        :key="block.id"
                        class="rounded-xl px-3.5 py-3 text-sm"
                        :class="
                            block.active
                                ? 'bg-danger/10 text-danger'
                                : 'bg-white text-slate-600 shadow-sm dark:bg-darkmode-600 dark:text-slate-300'
                        "
                    >
                        <div class="flex items-center gap-2 font-medium">
                            <Lucide icon="Wrench" class="h-4 w-4 shrink-0" />
                            {{ block.starts_at }} al
                            {{ block.ends_at }}
                            <span
                                v-if="block.active"
                                class="rounded-full bg-danger/15 px-2 py-0.5 text-[10px]"
                                >En curso</span
                            >
                        </div>
                        <p v-if="block.reason" class="mt-1">
                            {{ block.reason }}
                        </p>
                        <button
                            v-if="canManage"
                            type="button"
                            class="mt-1 text-xs underline-offset-2 hover:underline"
                            :disabled="busyAction === `block:${block.id}`"
                            @click="removeBlock(block.id)"
                        >
                            Retirar bloqueo
                        </button>
                    </div>
                </div>
                <p
                    v-if="room.blocks.length"
                    class="mt-2 text-xs text-slate-500"
                >
                    Estas fechas no se pueden vender. El semáforo no cambia por
                    un bloqueo.
                </p>
                <p v-else-if="!blockOpen" class="mt-2 text-xs text-slate-500">
                    No hay fechas apartadas.
                </p>
            </div>

            <!-- Fallas sin resolver: lo primero que hay que saber antes de
                 vender el cuarto. Antes solo se podía CREAR el reporte desde
                 aquí, no ver los que ya existían. -->
            <div
                v-if="room.incidents?.length"
                class="mt-4 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
            >
                <div
                    class="text-sm font-semibold text-slate-900 dark:text-slate-100"
                >
                    Fallas sin resolver ({{ room.incidents.length }})
                </div>
                <div class="mt-2 flex flex-col gap-2">
                    <a
                        v-for="incident in room.incidents"
                        :key="incident.id"
                        :href="route('tenant.incidents.show', incident.id)"
                        class="flex items-start gap-2.5 rounded-lg border p-2.5 transition hover:bg-slate-50 dark:hover:bg-darkmode-700/50"
                        :class="
                            incident.overdue
                                ? 'border-danger/30 bg-danger/5'
                                : 'border-slate-200/70 dark:border-darkmode-400'
                        "
                    >
                        <Lucide
                            icon="Wrench"
                            class="mt-0.5 h-4 w-4 shrink-0"
                            :class="
                                incident.overdue
                                    ? 'text-danger'
                                    : 'text-slate-400'
                            "
                        />
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium">
                                {{ incident.title }}
                            </div>
                            <div class="mt-0.5 text-xs text-slate-500">
                                {{ incident.priority_label }}
                                <template v-if="incident.category_label">
                                    · {{ incident.category_label }}
                                </template>
                                <span
                                    v-if="incident.overdue"
                                    class="font-medium text-danger"
                                >
                                    · sin atender
                                </span>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Reportar una falla desde donde se descubre: mirando el cuarto,
                 no yendo a buscarlo en la lista de incidencias. -->
            <div
                v-if="canManage && incidentCategories.length"
                class="mt-4 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div
                        class="text-sm font-semibold text-slate-900 dark:text-slate-100"
                    >
                        Reportar una falla
                    </div>
                    <Button
                        variant="outline-secondary"
                        class="min-h-10 rounded-[0.5rem]"
                        @click="incidentOpen = !incidentOpen"
                    >
                        <Lucide
                            icon="TriangleAlert"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        {{ incidentOpen ? 'Cancelar' : 'Levantar reporte' }}
                    </Button>
                </div>

                <form
                    v-if="incidentOpen"
                    class="mt-3 grid gap-3 rounded-xl bg-slate-50 p-3 sm:grid-cols-2 dark:bg-darkmode-700/50"
                    @submit.prevent="submitIncident"
                >
                    <div class="sm:col-span-2">
                        <label
                            class="text-xs text-slate-500"
                            for="incident-title"
                            >Qué pasó</label
                        >
                        <FormInput
                            id="incident-title"
                            v-model="incident.title"
                            type="text"
                            maxlength="120"
                            class="mt-1"
                            placeholder="El aire no enfría"
                            required
                        />
                    </div>
                    <div>
                        <label
                            class="text-xs text-slate-500"
                            for="incident-category"
                            >Tipo de falla</label
                        >
                        <FormSelect
                            id="incident-category"
                            v-model="incident.category"
                            class="mt-1"
                        >
                            <option value="">Sin clasificar</option>
                            <option
                                v-for="category in incidentCategories"
                                :key="category.key"
                                :value="category.key"
                            >
                                {{ category.label }}
                            </option>
                        </FormSelect>
                    </div>
                    <div>
                        <label
                            class="text-xs text-slate-500"
                            for="incident-priority"
                            >Urgencia</label
                        >
                        <FormSelect
                            id="incident-priority"
                            v-model="incident.priority"
                            class="mt-1"
                        >
                            <option value="low">Puede esperar</option>
                            <option value="medium">Normal</option>
                            <option value="high">Urgente</option>
                        </FormSelect>
                    </div>
                    <div>
                        <label
                            class="text-xs text-slate-500"
                            for="incident-source"
                            >Quién lo reporta</label
                        >
                        <FormSelect
                            id="incident-source"
                            v-model="incident.source"
                            class="mt-1"
                        >
                            <option value="staff">El personal</option>
                            <option value="guest">El huésped</option>
                        </FormSelect>
                    </div>
                    <div>
                        <label
                            class="text-xs text-slate-500"
                            for="incident-photo"
                            >Foto (opcional)</label
                        >
                        <input
                            id="incident-photo"
                            type="file"
                            accept="image/*"
                            class="mt-1 block w-full text-xs text-slate-500 file:mr-3 file:rounded-md file:border-0 file:bg-slate-200 file:px-3 file:py-2 file:text-sm dark:file:bg-darkmode-400"
                            @change="onIncidentPhoto"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <label
                            class="text-xs text-slate-500"
                            for="incident-description"
                            >Detalle (opcional)</label
                        >
                        <FormInput
                            id="incident-description"
                            v-model="incident.description"
                            type="text"
                            maxlength="2000"
                            class="mt-1"
                            placeholder="Suena raro desde ayer y no baja de 26°"
                        />
                    </div>
                    <label
                        class="flex items-center gap-2 text-sm sm:col-span-2"
                    >
                        <input
                            v-model="incident.set_maintenance"
                            type="checkbox"
                            class="rounded border-slate-300"
                        />
                        Sacar la habitación de venta (pasa a mantenimiento)
                    </label>
                    <div class="sm:col-span-2">
                        <Button
                            variant="primary"
                            type="submit"
                            class="min-h-11 rounded-[0.5rem] text-xs"
                            :disabled="busy || !incident.title.trim()"
                        >
                            Reportar
                        </Button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</template>
