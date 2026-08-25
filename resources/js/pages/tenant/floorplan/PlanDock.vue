<script setup lang="ts">
import Lucide from '@/components/Base/Lucide';
import { usePlanPanels } from '@/composables/usePlanPanels';
import HouseStatusPanel from './panels/HouseStatusPanel.vue';

/**
 * Lo único que flota permanentemente sobre el plano: el contador "Estado de la
 * casa". Todo lo que es de UNA habitación vive en su modal con tabs, y la caja
 * del turno detrás del chip de la barra — una tarjeta por cuarto era hablar del
 * mismo sujeto dos veces.
 *
 * Cuelga del contenedor del canvas, que es `relative` en los dos modos, así que
 * viaja con el <Teleport> a pantalla completa sin volver a montarse. Se acopla
 * arriba a la derecha porque abajo a la izquierda están los controles de zoom,
 * abajo a la derecha el minimapa, y por abajo sube la hoja de acciones. En el
 * teléfono se apila al pie dejando libre la columna del zoom, y arranca
 * colapsado: ahí el plano es lo único que cabe.
 */
interface StatusCount {
    status: string;
    label: string;
    count: number;
    dot: string;
}

defineProps<{
    counts: StatusCount[];
    total: number;
    activeStatus: string;
}>();

defineEmits<{
    (e: 'filter', status: string): void;
}>();

// Ver u ocultar el contador es preferencia de cada quien y se recuerda: en una
// pantalla de mostrador el plano manda y a veces estorba.
const { countersVisible } = usePlanPanels();
</script>

<template>
    <div
        class="pointer-events-none absolute inset-x-0 bottom-0 z-20 flex justify-end p-3 pl-[4.5rem] lg:inset-y-0 lg:right-0 lg:left-auto lg:items-start lg:p-4 lg:pl-4"
    >
        <div class="pointer-events-auto flex w-full flex-col gap-3 lg:w-72">
            <button
                type="button"
                class="ml-auto flex h-9 items-center gap-2 rounded-full bg-white px-3 text-xs font-medium text-slate-600 shadow-md transition hover:bg-slate-50 dark:bg-darkmode-600 dark:text-slate-200 dark:hover:bg-darkmode-400"
                :title="
                    countersVisible
                        ? 'Ocultar el estado de la casa'
                        : 'Mostrar el estado de la casa'
                "
                @click="countersVisible = !countersVisible"
            >
                <Lucide
                    :icon="countersVisible ? 'ChevronRight' : 'LayoutDashboard'"
                    class="h-4 w-4"
                />
                {{ countersVisible ? 'Ocultar' : 'Estado' }}
            </button>

            <div
                v-if="countersVisible"
                class="rounded-xl border border-slate-200/70 bg-white shadow-lg dark:border-darkmode-400 dark:bg-darkmode-600"
            >
                <div
                    class="flex items-center gap-2 border-b border-slate-200/70 px-4 py-2.5 text-xs font-medium tracking-wide text-slate-500 uppercase dark:border-darkmode-400"
                >
                    <Lucide icon="LayoutDashboard" class="h-3.5 w-3.5" />
                    Estado de la casa
                </div>

                <HouseStatusPanel
                    :counts="counts"
                    :total="total"
                    :active-status="activeStatus"
                    @filter="$emit('filter', $event)"
                />
            </div>
        </div>
    </div>
</template>
