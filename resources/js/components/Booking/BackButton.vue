<script setup lang="ts">
import Lucide from '@/components/Base/Lucide';

// "Atrás" de los pasos públicos: pastilla, a la altura del botón principal
// (44px), siempre en el mismo lugar del pie — antes cada paso lo ponía
// arriba con otro texto ("Cambiar fechas", "Atrás", "Volver").
defineProps<{ label?: string }>();
defineEmits<{ (e: 'click'): void }>();
</script>

<template>
    <button
        type="button"
        class="inline-flex h-11 w-11 shrink-0 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white text-sm font-medium text-slate-600 shadow-sm transition hover:border-slate-300 hover:text-slate-800 sm:w-auto sm:px-4"
        :aria-label="label ?? 'Atrás'"
        :title="label ?? 'Atrás'"
        @click="$emit('click')"
    >
        <Lucide icon="ArrowLeft" class="h-4 w-4" />
        <!-- En celular solo la flecha: con el texto, la acción principal
             se iba a un segundo renglón. -->
        <span class="hidden sm:inline">{{ label ?? 'Atrás' }}</span>
    </button>
</template>
