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
        <div class="flex items-baseline justify-between gap-3">
            <div>
                <div class="text-2xl font-semibold">{{ occupancy }}%</div>
                <div class="text-xs text-slate-500">
                    de ocupación · {{ total }}
                    {{ total === 1 ? 'habitación' : 'habitaciones' }}
                </div>
            </div>
            <Lucide icon="LayoutDashboard" class="h-5 w-5 text-slate-300" />
        </div>

        <!-- En el teléfono los seis estados en columna se comían la pantalla:
             a dos columnas el panel mide la mitad y se sigue leyendo. -->
        <div class="mt-3 grid grid-cols-2 gap-x-2 gap-y-1 lg:grid-cols-1">
            <button
                v-for="item in counts"
                :key="item.status"
                type="button"
                class="flex w-full items-center gap-2 rounded-lg px-1.5 py-1.5 text-left text-[13px] transition lg:gap-2.5 lg:px-2 lg:text-sm"
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
                <span class="font-semibold">{{ item.count }}</span>
            </button>
        </div>
    </div>
</template>
