<script setup lang="ts">
import Button from '@/components/Base/Button';
import Lucide, { type Icon } from '@/components/Base/Lucide';

/**
 * Hoja de acciones del modo presentación (docs/spec-plano-pantalla-completa.md).
 * En pantalla completa el tap en un cuarto abre esto y no la ficha completa:
 * el pulgar llega abajo y las acciones que se usan de verdad —vender el
 * cuarto, registrar la llegada, cerrar la estancia— quedan a un toque. No
 * decide nada por su cuenta: emite y el plano ejecuta los mismos handlers
 * que ya usaba la ficha, así que no hay una segunda versión de la lógica.
 */

interface SheetRoom {
    id: number;
    number: string;
    room_type: string | null;
    label: string;
    status: string;
    active_stay: {
        id: number;
        guest_name: string | null;
        balance_due: number | null;
    } | null;
    upcoming_reservation: {
        id: number;
        guest_name: string | null;
        starts_at: string;
    } | null;
}

/** Caminos de venta que ofrece el modo de operación; los arma el plano. */
interface ArrivalAction {
    key: string;
    label: string;
    hint: string;
    icon: Icon;
    primary: boolean;
}

const props = defineProps<{
    room: SheetRoom | null;
    canManageReservations: boolean;
    manualCheckinAllowed: boolean;
    arrivalActions: ArrivalAction[];
    busyAction: string | null;
    saving: boolean;
    /** Transiciones de limpieza/mantenimiento que autoriza el servidor. */
    transitions: { status: string; label: string; icon: Icon }[];
    /** Permiso Y módulo del punto de venta: sin los dos, /pos responde 403. */
    canChargeConsumption: boolean;
}>();

defineEmits<{
    (e: 'close'): void;
    (e: 'ficha'): void;
    (e: 'arrival', key: string): void;
    (e: 'checkin'): void;
    (e: 'checkout'): void;
    (e: 'extend'): void;
    (e: 'move'): void;
    (e: 'pos'): void;
    (e: 'status', status: string): void;
}>();

const busyStay = () =>
    props.room?.active_stay
        ? props.busyAction === `stay:${props.room.active_stay.id}`
        : false;

const busyReservation = () =>
    props.room?.upcoming_reservation
        ? props.busyAction ===
          `reservation:${props.room.upcoming_reservation.id}`
        : false;
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-y-full opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-full opacity-0"
    >
        <div
            v-if="room"
            class="absolute inset-x-0 bottom-0 z-40 rounded-t-2xl border-t border-slate-200/70 bg-white p-4 shadow-[0_-8px_30px_rgba(0,0,0,0.12)] sm:p-5 dark:border-darkmode-400 dark:bg-darkmode-600"
        >
            <div class="mx-auto w-full max-w-3xl">
                <div class="flex items-start gap-3">
                    <div
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="DoorClosed" class="h-6 w-6" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div
                            class="flex flex-wrap items-center gap-x-2 gap-y-1"
                        >
                            <span class="text-lg font-semibold"
                                >Habitación {{ room.number }}</span
                            >
                            <span
                                class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-200"
                                >{{ room.label }}</span
                            >
                        </div>
                        <p class="mt-0.5 truncate text-sm text-slate-500">
                            <template v-if="room.active_stay?.guest_name">
                                {{ room.active_stay.guest_name }}
                            </template>
                            <template
                                v-else-if="
                                    room.upcoming_reservation?.guest_name
                                "
                            >
                                Llega
                                {{ room.upcoming_reservation.guest_name }} ·
                                {{ room.upcoming_reservation.starts_at }}
                            </template>
                            <template v-else>
                                {{ room.room_type ?? 'Sin tipo' }}
                            </template>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                        aria-label="Cerrar"
                        @click="$emit('close')"
                    >
                        <Lucide icon="X" class="h-5 w-5" />
                    </button>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                    <!-- Cuarto libre: vender es lo único que importa aquí. Los
                         caminos los decide el modo de operación en el plano;
                         esta hoja solo los pinta y avisa cuál se tocó. -->
                    <template
                        v-if="
                            room.status === 'available' && canManageReservations
                        "
                    >
                        <Button
                            v-for="action in arrivalActions"
                            :key="action.key"
                            :variant="
                                action.primary ? 'primary' : 'outline-primary'
                            "
                            class="min-h-14 justify-start rounded-xl px-4 text-left"
                            :class="
                                action.primary
                                    ? ''
                                    : 'bg-white dark:bg-darkmode-600'
                            "
                            @click="$emit('arrival', action.key)"
                        >
                            <Lucide
                                :icon="action.icon"
                                class="mr-3 h-5 w-5 shrink-0"
                            />
                            <span class="min-w-0">
                                <span class="block font-semibold">{{
                                    action.label
                                }}</span>
                                <span
                                    class="mt-0.5 block text-xs font-normal"
                                    :class="
                                        action.primary
                                            ? 'opacity-80'
                                            : 'text-slate-500'
                                    "
                                    >{{ action.hint }}</span
                                >
                            </span>
                        </Button>
                    </template>

                    <!-- Reservada: la llegada del que ya reservó. -->
                    <Button
                        v-if="
                            room.status === 'reserved' &&
                            room.upcoming_reservation &&
                            canManageReservations &&
                            manualCheckinAllowed
                        "
                        variant="primary"
                        class="min-h-14 justify-center rounded-xl"
                        :disabled="busyReservation()"
                        @click="$emit('checkin')"
                    >
                        <Lucide icon="LogIn" class="mr-2 h-5 w-5" />
                        {{
                            busyReservation()
                                ? 'Procesando…'
                                : 'Registrar llegada'
                        }}
                    </Button>

                    <!-- Ocupada: lo que se hace con alguien adentro. -->
                    <template v-if="room.active_stay && canManageReservations">
                        <Button
                            variant="primary"
                            class="min-h-14 justify-center rounded-xl"
                            :disabled="busyStay()"
                            @click="$emit('checkout')"
                        >
                            <Lucide icon="LogOut" class="mr-2 h-5 w-5" />
                            {{
                                busyStay() ? 'Procesando…' : 'Registrar salida'
                            }}
                        </Button>
                        <Button
                            variant="outline-primary"
                            class="min-h-14 justify-center rounded-xl bg-white dark:bg-darkmode-600"
                            @click="$emit('extend')"
                        >
                            <Lucide icon="CalendarPlus" class="mr-2 h-5 w-5" />
                            Extender
                        </Button>
                        <Button
                            variant="outline-primary"
                            class="min-h-14 justify-center rounded-xl bg-white dark:bg-darkmode-600"
                            @click="$emit('move')"
                        >
                            <Lucide
                                icon="ArrowRightLeft"
                                class="mr-2 h-5 w-5"
                            />
                            Cambiar de cuarto
                        </Button>
                        <Button
                            v-if="canChargeConsumption"
                            variant="outline-primary"
                            class="min-h-14 justify-center rounded-xl bg-white dark:bg-darkmode-600"
                            @click="$emit('pos')"
                        >
                            <Lucide icon="ReceiptText" class="mr-2 h-5 w-5" />
                            Cargar consumo
                        </Button>
                    </template>

                    <!-- Limpieza y mantenimiento: lo que se marca caminando
                         el hotel con el teléfono en la mano. -->
                    <Button
                        v-for="transition in transitions"
                        :key="transition.status"
                        variant="outline-secondary"
                        class="min-h-14 justify-center rounded-xl bg-white dark:bg-darkmode-600"
                        :disabled="saving"
                        @click="$emit('status', transition.status)"
                    >
                        <Lucide :icon="transition.icon" class="mr-2 h-5 w-5" />
                        {{ transition.label }}
                    </Button>

                    <!-- Siempre disponible: el detalle completo del cuarto,
                         con historial, amenidades y todo lo demás. -->
                    <Button
                        variant="outline-secondary"
                        class="min-h-14 justify-center rounded-xl bg-white sm:col-span-2 dark:bg-darkmode-600"
                        @click="$emit('ficha')"
                    >
                        <Lucide icon="PanelRightOpen" class="mr-2 h-5 w-5" />
                        Ver ficha completa
                    </Button>
                </div>
            </div>
        </div>
    </Transition>
</template>
