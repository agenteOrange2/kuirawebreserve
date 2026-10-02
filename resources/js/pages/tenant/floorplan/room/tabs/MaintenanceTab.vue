<script setup lang="ts">
import { computed, inject, ref } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import { FloorPlanKey } from '../../context';
import { transitionMeta } from '../../status';
import ReportIncidentDialog from '../ReportIncidentDialog.vue';
import ScheduleBlockDialog from '../ScheduleBlockDialog.vue';

/**
 * Mantenimiento: lo que impide vender el cuarto y no es limpieza.
 *
 * Vivía repartido y escondido: las fallas y el reporte estaban hasta el fondo
 * del tab Cuarto —que es la ficha de inventario, no la operación del día— y
 * el bloqueo de fechas, debajo de las notas. Quien va a reportar un aire que
 * no enfría no busca ahí.
 *
 * Tres cosas distintas que suelen confundirse:
 *  - El semáforo en "mantenimiento" saca el cuarto de venta HOY.
 *  - Un bloqueo aparta FECHAS futuras y no toca el semáforo.
 *  - Una falla es el reporte de lo que se descompuso; puede o no sacar el
 *    cuarto de venta.
 *
 * Las dos capturas (reportar y programar) se fueron a su propio modal: como
 * formularios desplegables empujaban lo que había que leer y dejaban media
 * franja vacía a la derecha.
 */
const ctx = inject(FloorPlanKey)!;

const room = computed(() => ctx.room.value!);

const {
    canManage,
    incidentCategories,
    busyAction,
    saving,
    changeStatus,
    reportIncident,
    createBlock,
    deleteBlock,
} = ctx;

/**
 * Del semáforo, solo lo que es mantenimiento: sucia → en limpieza →
 * disponible es el otro tab. Con el cuarto ya fuera de servicio se ofrecen
 * todas, porque cualquiera de ellas es el camino de vuelta a la venta.
 */
const stateActions = computed(() =>
    room.value.status === 'maintenance'
        ? room.value.transitions
        : room.value.transitions.filter((status) => status === 'maintenance'),
);

const incidentOpen = ref(false);
const blockOpen = ref(false);

async function submitIncident(payload: {
    title: string;
    category: string | null;
    priority: string;
    description: string | null;
    source: string;
    set_maintenance: boolean;
    photo: File | null;
}) {
    await reportIncident(payload);
    incidentOpen.value = false;
}

async function submitBlock(payload: {
    starts_at: string;
    ends_at: string;
    reason: string | null;
}) {
    await createBlock(payload);
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

/** Las dos tarjetas de arriba solo se ponen en paralelo si las dos existen:
 *  una sola a media anchura deja el hueco que se venía a quitar. */
const showState = computed(() => canManage && stateActions.value.length > 0);
const showBlocks = computed(() => canManage || room.value.blocks.length > 0);
const twoUp = computed(() => showState.value && showBlocks.value);

const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
</script>

<template>
    <div class="space-y-4">
        <!-- Fuera de servicio hoy: es lo primero que hay que saber al abrir
             este tab, con la nota de quien la sacó de venta. -->
        <section
            v-if="room.status === 'maintenance'"
            class="rounded-xl border border-warning/30 bg-warning/5 px-4 py-3.5 dark:border-warning/30 dark:bg-warning/10"
        >
            <div class="flex items-start gap-3">
                <div
                    :class="sectionIcon"
                    class="border-warning/20 bg-warning/10 text-warning"
                >
                    <Lucide icon="Wrench" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <h3 class="text-sm font-medium">Fuera de servicio</h3>
                    <p
                        class="mt-0.5 text-xs text-slate-600 dark:text-slate-300"
                    >
                        No se puede vender ni apartar para ninguna fecha
                        mientras siga en mantenimiento.
                    </p>
                    <p
                        v-if="room.maintenance_notes"
                        class="mt-2 rounded-lg bg-white/70 px-3 py-2 text-xs whitespace-pre-line text-slate-700 dark:bg-darkmode-600/60 dark:text-slate-200"
                    >
                        {{ room.maintenance_notes }}
                    </p>
                </div>
            </div>
        </section>

        <!-- Las dos decisiones del día, una al lado de la otra: sacarlo de
             venta HOY y apartar fechas para después. -->
        <div
            v-if="showState || showBlocks"
            class="grid items-stretch gap-4"
            :class="twoUp ? 'lg:grid-cols-2' : 'grid-cols-1'"
        >
            <section
                v-if="showState"
                class="flex flex-col overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
            >
                <div :class="cardHeader">
                    <div
                        :class="sectionIcon"
                        class="border-dark/10 bg-dark/10 text-dark dark:text-slate-200"
                    >
                        <Lucide icon="PowerOff" class="h-4 w-4" />
                    </div>
                    <!-- basis-0: con el subtítulo largo, la cabecera (que
                         envuelve) mandaba el texto a otro renglón y el
                         círculo quedaba solo arriba. -->
                    <div class="min-w-0 flex-1 basis-0">
                        <h3 class="text-sm font-medium">Estado del cuarto</h3>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{
                                room.status === 'maintenance'
                                    ? 'Cuando quede reparado, devuélvelo al flujo de limpieza para volver a venderlo.'
                                    : 'Sacarlo de venta lo deja fuera del plano y de la disponibilidad hasta que lo regreses.'
                            }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-1 flex-col px-4 py-3">
                    <!-- Con una sola salida, el botón ocupa el renglón entero:
                         en dos columnas quedaba media franja vacía. -->
                    <div
                        class="grid gap-2"
                        :class="stateActions.length > 1 ? 'sm:grid-cols-2' : ''"
                    >
                        <Button
                            v-for="status in stateActions"
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
                </div>
            </section>

            <!-- Mantenimiento programado: aparta FECHAS y no toca el semáforo
                 de hoy, que es lo que más se confunde. -->
            <section
                v-if="showBlocks"
                class="flex flex-col overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
            >
                <div :class="cardHeader">
                    <div
                        :class="sectionIcon"
                        class="border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="CalendarOff" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-sm font-medium">
                            Mantenimiento programado
                        </h3>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{
                                room.blocks.length
                                    ? 'Estas fechas no se venden; el semáforo de hoy no cambia.'
                                    : 'No hay fechas apartadas.'
                            }}
                        </p>
                    </div>
                    <Button
                        v-if="canManage"
                        variant="outline-secondary"
                        class="ml-auto h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                        title="Aparta fechas para que no se vendan; el semáforo de hoy no cambia"
                        @click="blockOpen = true"
                    >
                        <Lucide icon="CalendarOff" class="mr-1.5 h-3.5 w-3.5" />
                        Programar fechas
                    </Button>
                </div>

                <div class="flex flex-1 flex-col px-4 py-3">
                    <div
                        v-if="room.blocks.length"
                        class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                    >
                        <div
                            v-for="item in room.blocks"
                            :key="item.id"
                            class="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0"
                        >
                            <Lucide
                                icon="Wrench"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0"
                                :class="
                                    item.active
                                        ? 'text-danger'
                                        : 'text-slate-400'
                                "
                            />
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-medium"
                                >
                                    {{ item.starts_at }} al {{ item.ends_at }}
                                    <span
                                        v-if="item.active"
                                        class="rounded-full bg-danger/10 px-2 py-0.5 text-[11px] text-danger"
                                        >En curso</span
                                    >
                                </div>
                                <p
                                    v-if="item.reason"
                                    class="mt-0.5 text-xs text-slate-500"
                                >
                                    {{ item.reason }}
                                </p>
                            </div>
                            <button
                                v-if="canManage"
                                type="button"
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                title="Retirar el bloqueo y volver a vender esas fechas"
                                :disabled="busyAction === `block:${item.id}`"
                                @click="removeBlock(item.id)"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                    <p v-else class="text-xs text-slate-500">
                        Un bloqueo sirve para pintar, fumigar o reparar sin que
                        nadie pueda reservar esas noches.
                    </p>
                </div>
            </section>
        </div>

        <!-- Fallas del cuarto: lo abierto y el botón de levantar una nueva en
             la misma tarjeta. Antes eran dos secciones, y la de reportar era
             una franja vacía cuando nadie estaba capturando. -->
        <section
            v-if="
                room.incidents?.length ||
                (canManage && incidentCategories.length)
            "
            class="overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
        >
            <div :class="cardHeader">
                <div
                    :class="sectionIcon"
                    class="border-warning/10 bg-warning/10 text-warning"
                >
                    <Lucide icon="TriangleAlert" class="h-4 w-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-medium">
                        Fallas sin resolver
                        <span
                            v-if="room.incidents?.length"
                            class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                            >{{ room.incidents.length }}</span
                        >
                    </h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Lo que sigue descompuesto en esta habitación.
                    </p>
                </div>
                <Button
                    v-if="canManage && incidentCategories.length"
                    variant="outline-secondary"
                    class="ml-auto h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                    @click="incidentOpen = true"
                >
                    <Lucide icon="TriangleAlert" class="mr-1.5 h-3.5 w-3.5" />
                    Levantar reporte
                </Button>
            </div>

            <div
                v-if="room.incidents?.length"
                class="grid gap-2 px-4 py-3 sm:grid-cols-2"
            >
                <a
                    v-for="item in room.incidents"
                    :key="item.id"
                    :href="route('tenant.incidents.show', item.id)"
                    class="flex items-start gap-2.5 rounded-lg border px-3 py-2.5 transition hover:bg-slate-50 dark:hover:bg-darkmode-700/50"
                    :class="
                        item.overdue
                            ? 'border-danger/30 bg-danger/5'
                            : 'border-slate-200/70 dark:border-darkmode-400'
                    "
                >
                    <Lucide
                        icon="Wrench"
                        class="mt-0.5 h-3.5 w-3.5 shrink-0"
                        :class="item.overdue ? 'text-danger' : 'text-slate-400'"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-xs font-medium">
                            {{ item.title }}
                        </div>
                        <div class="mt-0.5 text-[11px] text-slate-500">
                            {{ item.priority_label }}
                            <template v-if="item.category_label">
                                · {{ item.category_label }}
                            </template>
                            <span
                                v-if="item.overdue"
                                class="font-medium text-danger"
                            >
                                · sin atender
                            </span>
                        </div>
                    </div>
                </a>
            </div>
            <p v-else class="px-4 py-3 text-xs text-slate-500">
                Sin fallas abiertas. Si algo se descompuso, levanta el reporte
                aquí mismo y queda en incidencias con quien lo vio.
            </p>
        </section>

        <ReportIncidentDialog
            :open="incidentOpen"
            :room-number="room.number"
            :categories="incidentCategories"
            :busy="saving"
            @close="incidentOpen = false"
            @submit="submitIncident"
        />

        <ScheduleBlockDialog
            :open="blockOpen"
            :room-number="room.number"
            :busy="saving"
            @close="blockOpen = false"
            @submit="submitBlock"
        />
    </div>
</template>
