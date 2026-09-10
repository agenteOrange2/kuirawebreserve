<script setup lang="ts">
import { computed, inject } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import { FloorPlanKey } from '../../context';
import { statusStyles, transitionMeta } from '../../status';

/**
 * Limpieza: la operación física del cuarto, sucia → en limpieza →
 * disponible.
 *
 * Vivía como una sección al fondo del Resumen, debajo de la venta y del
 * huésped, con el título "Limpieza y mantenimiento" mezclando dos oficios que
 * hacen personas distintas. Mantenimiento se fue a su tab; aquí queda lo de
 * camaristas.
 *
 * Las dos tarjetas van en paralelo y no apiladas a lo ancho: en un modal de
 * 1240px, una franja entera para decir "Ocupada" y otra con un solo botón
 * dejaban dos tercios de vacío a la derecha.
 *
 * Reservada y ocupada NO se tocan desde aquí: nacen de reservas reales y
 * marcarlas a mano deja el semáforo mintiendo sobre quién viene o quién está
 * adentro. El servidor solo autoriza las transiciones que llegan en
 * `room.transitions`.
 */
const ctx = inject(FloorPlanKey)!;

const room = computed(() => ctx.room.value!);

const { canManage, saving, changeStatus } = ctx;

/** Mantenimiento tiene su tab; aquí solo el flujo de limpieza. */
const cleaningTransitions = computed(() =>
    room.value.transitions.filter((status) => status !== 'maintenance'),
);

// El catálogo va por COLOR del semáforo, no por estado: es el mismo mapa que
// pinta el encabezado del modal y las tarjetas del plano.
const statusStyle = computed(() => statusStyles[room.value.color]);

/** Qué significa el semáforo de hoy, en palabras de mostrador. */
const contexto = computed(() => {
    switch (room.value.status) {
        case 'dirty':
            return 'Falta limpiarla antes de volver a entregarla.';
        case 'cleaning':
            return 'La están limpiando; al terminar vuelve a venderse.';
        case 'occupied':
            return 'Hay alguien adentro: la limpieza empieza cuando registres la salida.';
        case 'maintenance':
            return 'Está fuera de servicio por mantenimiento; eso se resuelve en su tab.';
        case 'reserved':
            return 'Está apartada para una llegada, así que ya debería estar lista.';
        default:
            return 'Lista para entregarse.';
    }
});

const cardHeader =
    'flex items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
</script>

<template>
    <div
        class="grid items-stretch gap-4"
        :class="canManage ? 'lg:grid-cols-2' : 'grid-cols-1'"
    >
        <section
            class="flex flex-col overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
        >
            <div :class="cardHeader">
                <div
                    :class="sectionIcon"
                    class="border-primary/10 bg-primary/10 text-primary"
                >
                    <Lucide icon="SprayCan" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <h3 class="text-sm font-medium">Cómo está ahora</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        El semáforo de limpieza de esta habitación.
                    </p>
                </div>
            </div>

            <div class="flex flex-1 flex-col gap-3 px-4 py-3">
                <div class="flex flex-wrap items-center gap-2.5">
                    <span
                        class="rounded-full px-2.5 py-1 text-[11px] font-medium"
                        :class="statusStyle?.soft"
                    >
                        {{ room.label }}
                    </span>
                    <p class="text-xs text-slate-600 dark:text-slate-300">
                        {{ contexto }}
                    </p>
                </div>

                <!-- Limpieza en curso (módulo limpieza): quién la trabaja y
                     desde cuándo. Antes solo se veía como badge en el plano,
                     así que al abrir el cuarto no había manera de saber quién
                     estaba dentro. -->
                <div
                    v-if="room.cleaning"
                    class="flex flex-wrap items-center gap-2.5 rounded-lg border border-warning/30 bg-warning/5 px-3 py-2.5 text-xs dark:border-warning/30 dark:bg-warning/10"
                >
                    <Lucide
                        icon="SprayCan"
                        class="h-3.5 w-3.5 shrink-0 text-warning"
                    />
                    <span
                        class="font-medium text-slate-700 dark:text-slate-200"
                    >
                        {{ room.cleaning.housekeeper ?? 'Sin asignar' }}
                    </span>
                    <span class="text-slate-500">
                        lleva {{ room.cleaning.minutes }} min
                    </span>
                </div>

                <!-- El aviso nombra a la reserva que de verdad aparta el
                     cuarto, no a la próxima —que puede ser de dentro de un mes
                     y cuya cancelación no liberaba nada. -->
                <p
                    v-if="
                        room.status === 'reserved' && room.holding_reservation
                    "
                    class="flex items-start gap-2 rounded-lg border border-info/30 bg-info/5 px-3 py-2.5 text-xs text-slate-600 dark:text-slate-300"
                >
                    <Lucide
                        icon="Info"
                        class="mt-0.5 h-3.5 w-3.5 shrink-0 text-info"
                    />
                    <span>
                        La aparta la reserva
                        {{ room.holding_reservation.code }}: llega el
                        {{ room.holding_reservation.starts_at }} y sale el
                        {{ room.holding_reservation.ends_at }}. Para liberarla
                        antes, cancela esa reserva.
                    </span>
                </p>
                <p
                    v-else-if="room.status === 'reserved'"
                    class="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/5 px-3 py-2.5 text-xs text-slate-600 dark:text-slate-300"
                >
                    <Lucide
                        icon="TriangleAlert"
                        class="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning"
                    />
                    <span>
                        El semáforo quedó apartado sin ninguna reserva que lo
                        respalde. Puedes liberarlo al lado; el barrido
                        automático lo hace solo en la siguiente corrida.
                    </span>
                </p>
            </div>
        </section>

        <section
            v-if="canManage"
            class="flex flex-col overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
        >
            <div :class="cardHeader">
                <div
                    :class="sectionIcon"
                    class="border-info/10 bg-info/10 text-info"
                >
                    <Lucide icon="Repeat" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <h3 class="text-sm font-medium">Mover el semáforo</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Aquí solo va la limpieza: reservada y ocupada se mueven
                        solas con la reserva y la llegada.
                    </p>
                </div>
            </div>

            <div class="flex flex-1 flex-col px-4 py-3">
                <!-- Un solo botón ocupa el renglón entero: en dos columnas
                     quedaba una mitad vacía, que es de lo que se quejaba el
                     mostrador. -->
                <div
                    v-if="cleaningTransitions.length"
                    class="grid gap-2"
                    :class="
                        cleaningTransitions.length > 1 ? 'sm:grid-cols-2' : ''
                    "
                >
                    <Button
                        v-for="status in cleaningTransitions"
                        :key="status"
                        :variant="transitionMeta[status].variant"
                        :disabled="saving"
                        class="h-9 w-full justify-center rounded-[0.5rem] text-xs"
                        @click="changeStatus(room, status)"
                    >
                        <Lucide
                            :icon="transitionMeta[status].icon"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        {{ transitionMeta[status].label }}
                    </Button>
                </div>

                <!-- Sin botones no es un hueco: es que el reloj lleva el flujo
                     o que el cuarto está en manos de una reserva. -->
                <p
                    v-else
                    class="rounded-lg border border-dashed border-slate-300/70 px-3.5 py-3 text-xs text-slate-500 dark:border-darkmode-400"
                >
                    Ahora mismo no hay nada que mover a mano en este cuarto. En
                    modo de limpieza automático los pasos los da el reloj
                    (/ajustes/limpieza), y una habitación apartada u ocupada se
                    libera con su reserva.
                </p>
            </div>
        </section>
    </div>
</template>
