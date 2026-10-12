<script setup lang="ts">
import Lucide from '@/components/Base/Lucide';
import type { GuestHit } from '@/composables/useGuestSearch';

/**
 * Huésped que ya vino, en los modales del plano (exprés, llegó sin reserva,
 * apartar): con uno elegido pinta su ficha con una X para soltarlo; sin
 * elegir, las coincidencias en renglones a ras. Va dentro del flujo y no
 * flotando: un menú absoluto quedaba recortado por el cuerpo con scroll.
 */
defineProps<{
    hits: GuestHit[];
    selected: GuestHit | null;
}>();

const emit = defineEmits<{
    pick: [hit: GuestHit];
    clear: [];
}>();

const visitsLabel = (n: number) => `${n} ${n === 1 ? 'visita' : 'visitas'}`;
</script>

<template>
    <div v-if="selected">
        <div
            class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2"
            :class="
                selected.is_blacklisted
                    ? 'border-danger/30 bg-danger/5'
                    : 'border-primary/30 bg-primary/5'
            "
        >
            <div class="flex min-w-0 items-center gap-2.5">
                <div
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                >
                    <Lucide icon="UserCheck" class="h-3.5 w-3.5" />
                </div>
                <div class="min-w-0 text-xs">
                    <div class="truncate font-medium">
                        {{ selected.full_name ?? 'Sin nombre' }}
                    </div>
                    <div class="truncate text-slate-500">
                        <span v-if="selected.phone"
                            >{{ selected.phone }} ·
                        </span>
                        {{ visitsLabel(selected.visits) }} · ligado a su ficha
                    </div>
                </div>
            </div>
            <button
                type="button"
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-darkmode-400"
                title="Cambiar huésped"
                @click="emit('clear')"
            >
                <Lucide icon="X" class="h-3.5 w-3.5" />
            </button>
        </div>
        <p
            v-if="selected.is_blacklisted"
            class="mt-1.5 flex items-start gap-2 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
        >
            <Lucide icon="ShieldAlert" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            En lista negra{{
                selected.blacklist_reason
                    ? `: ${selected.blacklist_reason}`
                    : '; revisa con el encargado antes de registrar.'
            }}
        </p>
    </div>
    <div
        v-else-if="hits.length"
        class="divide-y divide-slate-200/60 overflow-hidden rounded-lg border border-slate-200/70 dark:divide-darkmode-400 dark:border-darkmode-400"
    >
        <div
            class="bg-slate-50 px-3 py-1.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-700"
        >
            Ya estuvo aquí: elígelo para ligarlo a su ficha
        </div>
        <button
            v-for="hit in hits"
            :key="hit.id"
            type="button"
            class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-xs transition hover:bg-slate-50 dark:hover:bg-darkmode-400"
            @click="emit('pick', hit)"
        >
            <span class="min-w-0 truncate">
                <span class="font-medium">{{
                    hit.full_name ?? 'Sin nombre'
                }}</span>
                <span v-if="hit.phone" class="ml-2 text-slate-500">{{
                    hit.phone
                }}</span>
            </span>
            <span class="flex shrink-0 items-center gap-1.5">
                <span
                    v-if="hit.is_blacklisted"
                    class="rounded-full bg-danger/10 px-1.5 text-[11px] text-danger"
                    >Vetado</span
                >
                <span
                    class="rounded-full bg-slate-100 px-1.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                    >{{ visitsLabel(hit.visits) }}</span
                >
            </span>
        </button>
    </div>
</template>
