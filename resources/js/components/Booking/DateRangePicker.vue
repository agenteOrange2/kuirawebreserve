<script setup lang="ts">
import Litepicker from 'litepicker';
import { onBeforeUnmount, onMounted, ref } from 'vue';

// Calendario de rango SIEMPRE visible (inline) para el paso 1 del wizard
// público: el huésped toca sus noches directo sobre el mes, como en
// cualquier motor de reservas — nada de <input type="date"> del navegador.
// Va inline (no popup) a propósito: dentro del flujo de la tarjeta no hay
// problemas de z-index, funciona igual dentro del iframe del widget WP
// (useEmbedResize crece solo) y la piel booking-dark lo alcanza por scope.
const props = defineProps<{
    // Ambas en 'YYYY-MM-DD' (hora local), el mismo formato que ya maneja
    // el estado del wizard — el contrato con searchAvailability no cambia.
    start: string;
    end: string;
}>();

const emit = defineEmits<{
    (e: 'update:start', value: string): void;
    (e: 'update:end', value: string): void;
}>();

const host = ref<HTMLDivElement>();
const anchor = ref<HTMLInputElement>();
let picker: Litepicker | null = null;

// 'YYYY-MM-DD' a secas se interpreta como UTC; forzamos hora local para
// que el calendario no arranque un día corrido.
function toLocalDate(ymd: string): Date {
    return new Date(`${ymd}T00:00`);
}

function toYmd(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

onMounted(() => {
    const oneMonth = window.matchMedia('(max-width: 639px)').matches;
    picker = new Litepicker({
        element: anchor.value!,
        parentEl: host.value!,
        inlineMode: true,
        singleMode: false,
        lang: 'es-MX',
        minDate: new Date(),
        startDate: toLocalDate(props.start),
        endDate: toLocalDate(props.end),
        numberOfMonths: oneMonth ? 1 : 2,
        numberOfColumns: oneMonth ? 1 : 2,
        // Mínimo una noche: elegir el mismo día de entrada y salida no es
        // una estancia.
        minDays: 2,
        tooltipText: { one: 'noche', other: 'noches' },
        setup: (p: Litepicker) => {
            p.on('selected', (start, end) => {
                emit('update:start', toYmd(start.toJSDate()));
                emit('update:end', toYmd(end.toJSDate()));
            });
        },
    });
});

onBeforeUnmount(() => {
    picker?.destroy();
    picker = null;
});
</script>

<template>
    <div ref="host" class="booking-datepicker">
        <!-- Ancla requerida por Litepicker; el calendario vive inline -->
        <input ref="anchor" type="hidden" />
    </div>
</template>
