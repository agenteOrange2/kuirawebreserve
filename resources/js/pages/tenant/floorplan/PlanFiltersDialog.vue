<script setup lang="ts">
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import PlanModal from './PlanModal.vue';

/**
 * Filtros y salto por zona del modo presentación
 * (docs/spec-plano-pantalla-completa.md).
 *
 * Nació porque las zonas vivían como pastillas en la barra de arriba: con
 * nombres reales ("Habitaciones Jacuzzi VIP") se amontonaban y la última
 * quedaba cortada contra el semáforo. Detrás de un botón caben todas, quepan
 * las que quepan, y además vuelven el buscador y los filtros de estado y
 * tipo, que en pantalla completa no estaban en ningún lado.
 *
 * Ojo: la zona ENCUADRA (mueve la vista) y los demás filtros ATENÚAN las
 * habitaciones que no coinciden — son cosas distintas y por eso van
 * separadas y rotuladas.
 */

interface ZoneOption {
    id: number;
    name: string;
}

interface StatusOption {
    status: string;
    label: string;
    count: number;
}

defineProps<{
    open: boolean;
    zones: ZoneOption[];
    statusOptions: StatusOption[];
    typeOptions: string[];
    activeZone: number | null;
    matching: number;
    total: number;
    filtersActive: boolean;
}>();

const search = defineModel<string>('search', { required: true });
const status = defineModel<string>('status', { required: true });
const type = defineModel<string>('type', { required: true });

defineEmits<{
    (e: 'close'): void;
    (e: 'zone', id: number | null): void;
    (e: 'clear'): void;
}>();
</script>

<template>
    <PlanModal
        :open="open"
        icon="SlidersHorizontal"
        title="Buscar y filtrar"
        :subtitle="`${matching} de ${total} ${total === 1 ? 'habitación' : 'habitaciones'} a la vista`"
        panel-class="sm:w-[560px]"
        @close="$emit('close')"
    >
        <div v-if="zones.length > 1">
            <div
                class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                Ir a una zona
            </div>
            <p class="mt-0.5 mb-2.5 text-xs text-slate-500">
                Encuadra esa planta sin salir de pantalla completa.
            </p>
            <div class="grid gap-2 sm:grid-cols-2">
                <button
                    type="button"
                    class="flex h-9 items-center gap-2 rounded-[0.5rem] border px-3 text-left text-xs font-medium transition"
                    :class="
                        activeZone === null
                            ? 'border-primary bg-primary/5 text-primary'
                            : 'border-slate-200/70 text-slate-600 hover:bg-slate-50 dark:border-darkmode-400 dark:text-slate-300 dark:hover:bg-darkmode-400'
                    "
                    @click="$emit('zone', null)"
                >
                    <Lucide icon="Scan" class="h-3.5 w-3.5 shrink-0" />
                    Todo el plano
                </button>
                <button
                    v-for="zone in zones"
                    :key="zone.id"
                    type="button"
                    class="flex h-9 items-center gap-2 rounded-[0.5rem] border px-3 text-left text-xs font-medium transition"
                    :class="
                        activeZone === zone.id
                            ? 'border-primary bg-primary/5 text-primary'
                            : 'border-slate-200/70 text-slate-600 hover:bg-slate-50 dark:border-darkmode-400 dark:text-slate-300 dark:hover:bg-darkmode-400'
                    "
                    @click="$emit('zone', zone.id)"
                >
                    <Lucide icon="MapPin" class="h-3.5 w-3.5 shrink-0" />
                    <span class="min-w-0 flex-1 truncate">{{ zone.name }}</span>
                </button>
            </div>
        </div>

        <div
            class="space-y-4"
            :class="
                zones.length > 1
                    ? 'border-t border-slate-200/60 pt-5 dark:border-darkmode-400'
                    : ''
            "
        >
            <div
                class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                Atenuar las demás
            </div>
            <div>
                <label
                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                    for="plan-search"
                    >Buscar habitación o huésped</label
                >
                <div class="relative">
                    <Lucide
                        icon="Search"
                        class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                    />
                    <FormInput
                        id="plan-search"
                        v-model="search"
                        type="text"
                        class="h-9 pl-9 text-xs"
                        placeholder="Número, huésped, tipo o código de reserva"
                    />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label
                        class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                        for="plan-status"
                        >Estado</label
                    >
                    <FormSelect
                        id="plan-status"
                        v-model="status"
                        class="h-9 text-xs"
                    >
                        <option value="">Todos los estados</option>
                        <option
                            v-for="option in statusOptions"
                            :key="option.status"
                            :value="option.status"
                        >
                            {{ option.label }} ({{ option.count }})
                        </option>
                    </FormSelect>
                </div>
                <div v-if="typeOptions.length > 1">
                    <label
                        class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                        for="plan-type"
                        >Tipo</label
                    >
                    <FormSelect
                        id="plan-type"
                        v-model="type"
                        class="h-9 text-xs"
                    >
                        <option value="">Todos los tipos</option>
                        <option v-for="t in typeOptions" :key="t" :value="t">
                            {{ t }}
                        </option>
                    </FormSelect>
                </div>
            </div>
            <p class="text-xs text-slate-500">
                Las que no coinciden se ven atenuadas, para no perder el acomodo
                del plano.
            </p>
        </div>

        <template #footer>
            <Button
                v-if="filtersActive"
                variant="outline-secondary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                @click="$emit('clear')"
            >
                <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                Limpiar
            </Button>
            <Button
                variant="primary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                @click="$emit('close')"
            >
                Ver el plano
            </Button>
        </template>
    </PlanModal>
</template>
