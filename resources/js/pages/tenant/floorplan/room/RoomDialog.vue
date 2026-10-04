<script setup lang="ts">
import { computed, inject, provide, ref, watch } from 'vue';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import { FloorPlanKey } from '../context';
import type { RoomData } from '../types';
import { statusStyles } from '../status';
import ChargesTab from './tabs/ChargesTab.vue';
import CleaningTab from './tabs/CleaningTab.vue';
import HistoryTab from './tabs/HistoryTab.vue';
import MaintenanceTab from './tabs/MaintenanceTab.vue';
import RoomTab from './tabs/RoomTab.vue';
import SummaryTab from './tabs/SummaryTab.vue';

/**
 * LA superficie de una habitación. Antes eran tres —la hoja de acciones, la
 * ficha en slideover y las tarjetas del dock—, y cada una repetía el mismo
 * sujeto; la ficha además era un scroll de 1.200 líneas con el dinero, las
 * tarifas y el historial revueltos.
 *
 * Ahora: un modal centrado (llega el pulgar en el mostrador táctil y no tapa el
 * costado del plano) con un tab por oficio. La hoja de acciones sobrevive como
 * primer paso en pantalla completa y este modal es el "ver más".
 *
 * Los tabs NO cambian según el estado del cuarto: si aparecieran y
 * desaparecieran, cambiar de habitación reacomodaría la fila bajo el dedo. Un
 * tab que no aplica lo dice en una línea.
 */
type TabKey =
    | 'resumen'
    | 'consumos'
    | 'limpieza'
    | 'mantenimiento'
    | 'historial'
    | 'cuarto';

const ctx = inject(FloorPlanKey)!;

const open = computed(() => ctx.room.value !== null);

/**
 * El cuarto que se pinta. El plano suelta la selección en el mismo instante en
 * que cierra el modal; si el panel dependiera de eso (`v-if="room"`), se
 * desmontaba de golpe junto con el fondo y no había animación de salida. Esta
 * copia se queda con el último cuarto mientras el modal se va, y los tabs la
 * reciben por un `provide` propio que tapa el del plano.
 */
const shown = ref<RoomData | null>(ctx.room.value);

watch(
    () => ctx.room.value,
    (current) => {
        if (current) {
            shown.value = current;
        }
    },
    { flush: 'sync' },
);

const room = computed(() => shown.value);

provide(FloorPlanKey, { ...ctx, room });

const tab = ref<TabKey>('resumen');

interface TabDefinition {
    key: TabKey;
    label: string;
    /** Etiqueta corta: con las largas los cuatro tabs no caben en un teléfono
     *  y los últimos quedan fuera de la vista, donde nadie los busca. */
    short: string;
    icon: Icon;
}

const tabs = computed<TabDefinition[]>(() => [
    {
        key: 'resumen',
        label: 'Resumen',
        short: 'Resumen',
        icon: 'LayoutList',
    },
    // Consumos exige el permiso Y el módulo del punto de venta: sin los dos
    // no hay a dónde cargar ni con qué cobrar.
    ...(ctx.canChargeHere.value
        ? [
              {
                  key: 'consumos' as TabKey,
                  label: 'Consumos y cobro',
                  short: 'Consumos',
                  icon: 'ShoppingCart' as Icon,
              },
          ]
        : []),
    // Limpieza y mantenimiento son dos oficios distintos y los hacen personas
    // distintas: vivían revueltos en una sección del Resumen titulada
    // "Limpieza y mantenimiento", y las fallas estaban hasta el fondo del tab
    // Cuarto, que es la ficha de inventario.
    {
        key: 'limpieza',
        label: 'Limpieza',
        short: 'Limpieza',
        icon: 'SprayCan',
    },
    {
        key: 'mantenimiento',
        label: 'Mantenimiento',
        short: 'Manten.',
        icon: 'Wrench',
    },
    {
        key: 'historial',
        label: 'Historial',
        short: 'Historial',
        icon: 'History',
    },
    {
        key: 'cuarto',
        label: 'Cuarto',
        short: 'Cuarto',
        icon: 'BedDouble',
    },
]);

// Cada apertura empieza en Resumen: es lo que se necesita el 90% de las veces,
// y quedarse en "Cuarto" del cuarto anterior invita a editar el equivocado.
// Se mira la SELECCIÓN y no el modal: cuando se apaga un momento para
// confirmar algo o extender, al volver debe seguir en el tab donde estaba.
watch(ctx.selectedRoomId, (id) => {
    if (id !== null) {
        tab.value = 'resumen';
    }
});

// Si al hotel le quitan el POS con el tab abierto, no se queda en un tab que ya
// no existe.
watch(tabs, (list) => {
    if (!list.some((item) => item.key === tab.value)) {
        tab.value = 'resumen';
    }
});
</script>

<template>
    <!-- Crece por pasos hasta 1200px: en el mostrador se trabaja con tablas y
         catálogos, y a 900px todo quedaba apretado. El plano se sigue viendo
         alrededor, que es la mitad del punto de operar desde aquí. -->
    <Dialog size="xl" :open="open" @close="ctx.close()">
        <Dialog.Panel
            class="sm:w-[720px] lg:w-[1000px] xl:w-[1240px] 2xl:w-[1400px]"
        >
            <!-- Alto acotado con encabezado y tabs fijos: el cuerpo scrollea
                 solo. El tope es `100dvh - 6rem` y no un 92vh a secas porque
                 el panel del theme entra con `mt-16`: con vh pelado el pie se
                 salía de la pantalla. El mínimo (desde sm) evita que un tab
                 corto deje el modal como una tira; en el teléfono acostado
                 solo robaba alto al cuerpo. -->
            <div
                v-if="room"
                class="flex max-h-[calc(100dvh-6rem)] flex-col sm:min-h-[55vh]"
            >
                <div
                    class="shrink-0 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                            :class="statusStyles[room.color]?.soft"
                        >
                            <Lucide icon="DoorClosed" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div
                                class="flex flex-wrap items-center gap-x-2 gap-y-1"
                            >
                                <Dialog.Title
                                    as="h2"
                                    class="block border-0 p-0 text-base font-medium"
                                >
                                    Habitación {{ room.number }}
                                </Dialog.Title>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="statusStyles[room.color]?.soft"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full"
                                        :class="statusStyles[room.color]?.dot"
                                    />
                                    {{ room.label }}
                                </span>
                            </div>
                            <!-- En el teléfono en dos renglones: truncado a
                                 una línea se perdían zona y capacidad. -->
                            <p
                                class="mt-0.5 line-clamp-2 text-xs text-slate-500 sm:line-clamp-1"
                            >
                                <!-- El nombre suele venir igual que el tipo:
                                     repetirlo dejaba el encabezado diciendo dos
                                     veces lo mismo. -->
                                <template
                                    v-if="
                                        room.name &&
                                        room.name !== room.room_type
                                    "
                                    >{{ room.name }} ·
                                </template>
                                {{ room.room_type ?? 'Sin tipo' }}
                                <template v-if="room.zone">
                                    · {{ room.zone }}
                                </template>
                                · hasta {{ room.capacity ?? '—' }} personas
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            aria-label="Cerrar"
                            @click="ctx.close()"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>

                    <!-- Misma fila de pastillas que usan Inventario y Turnos.
                         En el teléfono, rejilla de tres: en una fila con
                         scroll lateral, Historial y Cuarto quedaban fuera de
                         la vista y nadie los encontraba. -->
                    <div
                        role="tablist"
                        class="mt-3.5 grid grid-cols-3 gap-1 rounded-[0.6rem] bg-slate-100/80 p-1 sm:flex sm:overflow-x-auto dark:bg-darkmode-800/60"
                    >
                        <button
                            v-for="item in tabs"
                            :key="item.key"
                            type="button"
                            role="tab"
                            :aria-selected="tab === item.key"
                            class="flex h-8 min-w-0 items-center justify-center gap-1.5 rounded-[0.5rem] px-2 text-xs font-medium transition sm:shrink-0 sm:px-3.5"
                            :class="
                                tab === item.key
                                    ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                            "
                            @click="tab = item.key"
                        >
                            <!-- Sin icono en el teléfono: en un tercio de ancho
                                 el icono cortaba "Consumos". -->
                            <Lucide
                                :icon="item.icon"
                                class="hidden h-3.5 w-3.5 shrink-0 sm:block"
                            />
                            <!-- El nombre largo hasta lg: en la tablet vertical
                                 (panel de 720px) "Consumos y cobro" +
                                 "Mantenimiento" empujaban la barra y los
                                 extremos quedaban cortados. -->
                            <span class="truncate lg:hidden">{{
                                item.short
                            }}</span>
                            <span class="hidden lg:inline">{{
                                item.label
                            }}</span>
                        </button>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    <SummaryTab v-show="tab === 'resumen'" />
                    <ChargesTab
                        v-if="ctx.canChargeHere.value"
                        v-show="tab === 'consumos'"
                    />
                    <CleaningTab v-show="tab === 'limpieza'" />
                    <MaintenanceTab v-show="tab === 'mantenimiento'" />
                    <HistoryTab v-show="tab === 'historial'" />
                    <RoomTab v-show="tab === 'cuarto'" />
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
