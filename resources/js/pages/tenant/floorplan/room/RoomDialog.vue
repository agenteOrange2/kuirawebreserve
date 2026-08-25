<script setup lang="ts">
import { computed, inject, ref, watch } from 'vue';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import { FloorPlanKey } from '../context';
import { statusStyles } from '../status';
import ChargesTab from './tabs/ChargesTab.vue';
import HistoryTab from './tabs/HistoryTab.vue';
import RoomTab from './tabs/RoomTab.vue';
import SummaryTab from './tabs/SummaryTab.vue';

/**
 * LA superficie de una habitación. Antes eran tres —la hoja de acciones, la
 * ficha en slideover y las tarjetas del dock—, y cada una repetía el mismo
 * sujeto; la ficha además era un scroll de 1.200 líneas con el dinero, las
 * tarifas y el historial revueltos.
 *
 * Ahora: un modal centrado (llega el pulgar en el mostrador táctil y no tapa el
 * costado del plano) con cuatro tabs. La hoja de acciones sobrevive como primer
 * paso en pantalla completa y este modal es el "ver más".
 *
 * Los tabs NO cambian según el estado del cuarto: si aparecieran y
 * desaparecieran, cambiar de habitación reacomodaría la fila bajo el dedo. Un
 * tab que no aplica lo dice en una línea.
 */
type TabKey = 'resumen' | 'consumos' | 'historial' | 'cuarto';

const ctx = inject(FloorPlanKey)!;

const room = computed(() => ctx.room.value);
const open = computed(() => room.value !== null);

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
watch(
    () => room.value?.id,
    (id) => {
        if (id !== undefined) {
            tab.value = 'resumen';
        }
    },
);

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
            v-if="room"
            class="sm:w-[720px] lg:w-[1000px] xl:w-[1240px] 2xl:w-[1400px]"
        >
            <!-- Alto acotado con encabezado y tabs fijos: el cuerpo scrollea
                 solo. El tope es `100dvh - 6rem` y no un 92vh a secas porque
                 el panel del theme entra con `mt-16`: con vh pelado el pie se
                 salía de la pantalla. El mínimo evita que un tab corto deje el
                 modal como una tira. -->
            <div class="flex max-h-[calc(100dvh-6rem)] min-h-[55vh] flex-col">
                <div
                    class="shrink-0 border-b border-slate-200/70 px-5 py-5 sm:px-7 dark:border-darkmode-400"
                >
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="DoorClosed" class="h-6 w-6" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div
                                class="flex flex-wrap items-center gap-x-3 gap-y-1"
                            >
                                <h2
                                    class="text-xl font-semibold text-slate-900 dark:text-slate-100"
                                >
                                    Habitación {{ room.number }}
                                </h2>
                                <span
                                    class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-semibold"
                                    :class="statusStyles[room.color]?.soft"
                                >
                                    <span
                                        class="h-2.5 w-2.5 rounded-full"
                                        :class="statusStyles[room.color]?.dot"
                                    />
                                    {{ room.label }}
                                </span>
                            </div>
                            <p class="mt-1 truncate text-sm text-slate-500">
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
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-darkmode-400 dark:hover:text-slate-100"
                            aria-label="Cerrar"
                            @click="ctx.close()"
                        >
                            <Lucide icon="X" class="h-6 w-6" />
                        </button>
                    </div>

                    <!-- Misma fila de pastillas que usan Inventario y Turnos:
                         una sola manera de cambiar de tab en todo el panel. -->
                    <div
                        class="mt-5 flex gap-1 overflow-x-auto rounded-[0.6rem] bg-slate-100/80 p-1 dark:bg-darkmode-800/60"
                    >
                        <button
                            v-for="item in tabs"
                            :key="item.key"
                            type="button"
                            class="flex min-h-10 shrink-0 items-center gap-2 rounded-[0.5rem] px-3 text-sm font-medium transition sm:px-4"
                            :class="
                                tab === item.key
                                    ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                            "
                            @click="tab = item.key"
                        >
                            <Lucide
                                :icon="item.icon"
                                class="hidden h-4 w-4 sm:block"
                            />
                            <span class="sm:hidden">{{ item.short }}</span>
                            <span class="hidden sm:inline">{{
                                item.label
                            }}</span>
                        </button>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto px-5 py-6 sm:px-7">
                    <SummaryTab v-show="tab === 'resumen'" />
                    <ChargesTab
                        v-if="ctx.canChargeHere.value"
                        v-show="tab === 'consumos'"
                    />
                    <HistoryTab v-show="tab === 'historial'" />
                    <RoomTab v-show="tab === 'cuarto'" />
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
