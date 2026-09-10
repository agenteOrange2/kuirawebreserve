<script setup lang="ts">
import { computed, inject, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import { FloorPlanKey } from '../../context';
import RoomForm from '../RoomForm.vue';
import type { RoomFormValue } from '../RoomForm.vue';
import { formatMoney, usageBadgeTitle } from '../../format';

/**
 * Cuarto: cómo es la habitación y —con el candado "Editar plano" abierto— dar
 * de alta, renombrar, duplicar o quitarla.
 *
 * El formulario son tres campos a propósito: número, tipo y zona. El perfil
 * completo (camas, amenidades, cargos, ocupación, contador de usos) son veinte
 * campos y vive en /habitaciones — el plano es para operar, no para
 * administrar.
 *
 * Escala chica, como el resto del panel: tarjetas de datos en renglón (círculo
 * de 9, rótulo de 11px, dato en 13), listas a ras con `divide-y` y botones de
 * 9. Antes esto eran cifras en `text-base` dentro de cajas de 80px de alto:
 * la ficha ocupaba tres pantallas para decir seis cosas.
 *
 * Las fallas, el reporte y el bloqueo de fechas estaban al fondo de este tab
 * y se fueron al de Mantenimiento: esto es la ficha del cuarto, no la
 * operación del día.
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
    busyAction,
    saving,
    createRoom,
    updateRoom,
    duplicateRoom,
    deleteRoom,
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
});

/** Cargos y contador van en paralelo solo si existen los dos; una tarjeta
 *  sola a media anchura deja medio renglón vacío. */
const showCharges = computed(() => room.value.optional_charges.length > 0);
const showUsage = computed(
    () => room.value.usage_count > 0 || room.value.usage_limit !== null,
);

const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
</script>

<template>
    <div class="space-y-4">
        <!-- Edición arriba y compacta: quien abre este tab con el candado
             puesto viene a editar, no a leer 250 líneas de ficha. -->
        <section
            v-if="canEdit"
            class="rounded-xl border border-primary/20 bg-primary/5 px-4 py-3.5 dark:border-primary/30 dark:bg-primary/10"
        >
            <template v-if="mode === 'idle'">
                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs"
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
                        class="h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                        :disabled="busy"
                        @click="startEdit"
                    >
                        <Lucide icon="Pencil" class="mr-1.5 h-3.5 w-3.5" />
                        Editar esta
                    </Button>
                    <Button
                        variant="outline-primary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                        :disabled="busy"
                        title="Copia el tipo, la zona y el tamaño con el siguiente número libre"
                        @click="duplicateRoom"
                    >
                        <Lucide icon="Copy" class="mr-1.5 h-3.5 w-3.5" />
                        Duplicar
                    </Button>
                    <Button
                        variant="outline-danger"
                        class="h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
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
            <Lucide icon="Lock" class="h-3.5 w-3.5 shrink-0" />
            Desbloquea «Editar plano» para dar de alta, editar o quitar
            habitaciones.
        </button>

        <section
            class="overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
        >
            <div :class="cardHeader">
                <div
                    :class="sectionIcon"
                    class="border-primary/10 bg-primary/10 text-primary"
                >
                    <Lucide icon="BedDouble" class="h-4 w-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-medium">
                        Información de la habitación
                    </h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Lo más importante para explicarle la habitación al
                        huésped.
                    </p>
                </div>
                <div
                    v-if="room.price_from !== null"
                    class="ml-auto shrink-0 text-right"
                >
                    <div
                        class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                    >
                        Desde
                    </div>
                    <div class="text-sm font-medium">
                        {{ formatMoney(room.price_from) }}
                    </div>
                </div>
            </div>

            <div class="px-4 py-3">
                <div
                    v-if="fichaItems.length"
                    class="grid auto-rows-fr gap-2 sm:grid-cols-2 xl:grid-cols-3"
                >
                    <div
                        v-for="item in fichaItems"
                        :key="item.label"
                        class="flex items-center gap-2.5 rounded-lg border border-slate-200/70 px-3 py-2.5 dark:border-darkmode-400"
                    >
                        <div
                            :class="sectionIcon"
                            class="border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide :icon="item.icon" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div
                                class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                {{ item.label }}
                            </div>
                            <div class="mt-0.5 text-xs font-medium">
                                {{ item.text }}
                            </div>
                        </div>
                    </div>
                </div>

                <p
                    v-if="room.description"
                    class="mt-3 rounded-lg bg-slate-50 px-3.5 py-3 text-xs leading-relaxed text-slate-600 dark:bg-darkmode-700/50 dark:text-slate-300"
                >
                    {{ room.description }}
                </p>
            </div>
        </section>

        <section
            v-if="amenityGroups.length"
            class="overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
        >
            <div :class="cardHeader">
                <div
                    :class="sectionIcon"
                    class="border-info/10 bg-info/10 text-info"
                >
                    <Lucide icon="Sparkles" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <h3 class="text-sm font-medium">Lo que incluye</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Amenidades agrupadas para encontrarlas más rápido.
                    </p>
                </div>
            </div>
            <div class="grid gap-3 px-4 py-3 sm:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="group in amenityGroups"
                    :key="group.title"
                    class="rounded-lg border border-slate-200/70 px-3 py-2.5 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2 text-xs font-medium">
                        <Lucide
                            :icon="group.icon"
                            class="h-3.5 w-3.5 shrink-0 text-info"
                        />
                        {{ group.title }}
                    </div>
                    <ul class="mt-2 space-y-1.5">
                        <li
                            v-for="amenity in group.items"
                            :key="amenity"
                            class="flex items-start gap-2 text-xs leading-snug text-slate-600 dark:text-slate-300"
                        >
                            <Lucide
                                icon="CircleCheck"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0 text-success"
                            />
                            <span>{{ amenity }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <div
            v-if="showCharges || showUsage"
            class="grid items-start gap-4"
            :class="showCharges && showUsage ? 'lg:grid-cols-2' : 'grid-cols-1'"
        >
            <section
                v-if="showCharges"
                class="overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
            >
                <div :class="cardHeader">
                    <div
                        :class="sectionIcon"
                        class="border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="CirclePlus" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-sm font-medium">
                            Servicios con costo adicional
                        </h3>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Se cobran aparte de la tarifa.
                        </p>
                    </div>
                </div>
                <div
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="charge in room.optional_charges"
                        :key="charge.concept"
                        class="flex items-center justify-between gap-3 px-4 py-2.5 text-xs"
                    >
                        <span class="min-w-0 truncate">{{
                            charge.concept
                        }}</span>
                        <span class="shrink-0 font-medium">{{
                            formatMoney(charge.amount)
                        }}</span>
                    </div>
                </div>
            </section>

            <section
                v-if="showUsage"
                class="overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
            >
                <div :class="cardHeader">
                    <div
                        :class="sectionIcon"
                        class="border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400"
                    >
                        <Lucide
                            :icon="room.usage_locked ? 'Lock' : 'Repeat'"
                            class="h-4 w-4"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-sm font-medium">Contador de usos</h3>
                        <p
                            class="mt-0.5 text-xs"
                            :class="
                                room.usage_locked
                                    ? 'text-danger'
                                    : 'text-slate-500'
                            "
                        >
                            {{ usageBadgeTitle(room) }}
                        </p>
                    </div>
                    <!-- Liberar el candado es del mismo permiso que el
                         semáforo: recepción lo hace sin pasar por
                         administración. -->
                    <Button
                        v-if="
                            canManage &&
                            (room.usage_locked || room.usage_count > 0)
                        "
                        variant="outline-secondary"
                        class="ml-auto h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                        :disabled="busy"
                        title="Pone el contador en cero y quita el candado de rotación"
                        @click="resetUsage"
                    >
                        <Lucide icon="RotateCcw" class="mr-1.5 h-3.5 w-3.5" />
                        Reiniciar
                    </Button>
                </div>
            </section>
        </div>

        <!-- Las fotos que ya se suben en Catálogo: sirven para describir
             la habitación a quien pregunta por teléfono. -->
        <section
            v-if="room.room_type_photos.length"
            class="overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
        >
            <div :class="cardHeader">
                <div
                    :class="sectionIcon"
                    class="border-info/10 bg-info/10 text-info"
                >
                    <Lucide icon="Image" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <h3 class="text-sm font-medium">Cómo se ve</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Fotos del tipo, tal como las ve el huésped al reservar.
                    </p>
                </div>
            </div>
            <div class="flex gap-2 overflow-x-auto px-4 py-3">
                <a
                    v-for="photo in room.room_type_photos"
                    :key="photo.id"
                    :href="photo.url"
                    target="_blank"
                    rel="noopener"
                    class="shrink-0 overflow-hidden rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                    title="Abrir la foto en grande"
                >
                    <img
                        :src="photo.thumb_url"
                        alt=""
                        class="h-20 w-28 object-cover"
                        loading="lazy"
                    />
                </a>
            </div>
        </section>

        <section
            v-if="room.notes"
            class="overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
        >
            <div :class="cardHeader">
                <div
                    :class="sectionIcon"
                    class="border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400"
                >
                    <Lucide icon="StickyNote" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <h3 class="text-sm font-medium">Información adicional</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Notas internas de esta habitación.
                    </p>
                </div>
            </div>
            <p
                class="px-4 py-3 text-xs whitespace-pre-line text-slate-600 dark:text-slate-300"
            >
                {{ room.notes }}
            </p>
        </section>
    </div>
</template>
