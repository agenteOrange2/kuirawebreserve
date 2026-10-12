<script setup lang="ts">
import { computed } from 'vue';

/**
 * Quién es, en la primera columna de las tablas de reservas: iniciales en
 * círculo (canon: avatar de renglón h-9), el nombre y debajo los datos que
 * sirven para encontrarlo (folio, canal, teléfono). El nombre es botón: el
 * renglón se abre tocándolo.
 */
const props = defineProps<{
    name: string | null;
    /** Datos de abajo, ya en texto: se unen con " · ". */
    meta?: (string | null | undefined)[];
    phone?: string | null;
}>();

const emit = defineEmits<{ open: [] }>();

const initials = computed(() => {
    const words = (props.name ?? '')
        .trim()
        .split(/\s+/)
        .filter((w) => w.length > 0);
    if (!words.length) return '—';
    const first = words[0][0] ?? '';
    const last = words.length > 1 ? (words[words.length - 1][0] ?? '') : '';
    return (first + last).toUpperCase();
});

const metaText = computed(() => (props.meta ?? []).filter(Boolean).join(' · '));
</script>

<template>
    <div class="flex min-w-0 items-center gap-2.5">
        <div
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-[11px] font-medium text-primary"
            aria-hidden="true"
        >
            {{ initials }}
        </div>
        <div class="min-w-0">
            <button
                type="button"
                class="block max-w-full truncate text-left text-sm font-medium transition hover:text-primary"
                @click="emit('open')"
            >
                {{ name ?? 'Anónimo' }}
            </button>
            <div class="truncate text-xs text-slate-500">
                {{ metaText }}
                <template v-if="phone">
                    <template v-if="metaText"> · </template>
                    <a
                        :href="`tel:${phone}`"
                        class="transition hover:text-primary"
                        @click.stop
                        >{{ phone }}</a
                    >
                </template>
            </div>
        </div>
    </div>
</template>
