<script setup lang="ts">
import { computed } from 'vue';
import Lucide from '@/components/Base/Lucide';

/**
 * Estado de la casa: cuántas habitaciones hay en cada estado, de un vistazo.
 *
 * No consulta nada: cuenta sobre el mismo `rooms` que ya pinta el plano, así
 * que se actualiza solo cuando llega el evento de Echo que cambia un estado.
 * Tocar un renglón filtra el plano, para que el conteo no sea decorativo.
 */
interface StatusCount {
    status: string;
    label: string;
    count: number;
    dot: string;
}

const props = defineProps<{
    counts: StatusCount[];
    total: number;
    activeStatus: string;
}>();

defineEmits<{
    (e: 'filter', status: string): void;
}>();

// Ocupación real: lo que está adentro sobre el total, que es la cifra que
// pregunta el dueño. Reservada no cuenta como ocupada — todavía no llegan.
const occupancy = computed(() => {
    if (props.total === 0) {
        return 0;
    }

    const occupied =
        props.counts.find((item) => item.status === 'occupied')?.count ?? 0;

    return Math.round((occupied / props.total) * 100);
});
</script>

<template>
    <div class="p-4">
        <!-- Cifra en renglón compacto (canon §4), nunca la caja alta con el
             número en text-2xl. -->
        <div class="flex items-center gap-2.5">
            <div
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
            >
                <Lucide icon="Gauge" class="h-4 w-4" />
            </div>
            <div class="min-w-0">
                <div class="text-sm font-medium">{{ occupancy }}% ocupado</div>
                <div class="truncate text-xs text-slate-500">
                    {{ total }}
                    {{ total === 1 ? 'habitación' : 'habitaciones' }} en total
                </div>
            </div>
        </div>
        <div
            class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
        >
            <div
                class="h-full rounded-full bg-primary"
                :style="{ width: `${occupancy}%` }"
            />
        </div>

        <!-- En el teléfono los seis estados en columna se comían la pantalla:
             a dos columnas el panel mide la mitad y se sigue leyendo. -->
        <div class="mt-3 grid grid-cols-2 gap-x-2 gap-y-1 lg:grid-cols-1">
            <button
                v-for="item in counts"
                :key="item.status"
                type="button"
                class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs transition"
                :class="
                    activeStatus === item.status
                        ? 'bg-primary/10 text-primary'
                        : 'hover:bg-slate-100 dark:hover:bg-darkmode-400'
                "
                :title="`Ver solo ${item.label.toLowerCase()}`"
                @click="$emit('filter', item.status)"
            >
                <span
                    class="h-2.5 w-2.5 shrink-0 rounded-full"
                    :class="item.dot"
                />
                <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                <span class="font-medium">{{ item.count }}</span>
            </button>
        </div>
    </div>
</template>
