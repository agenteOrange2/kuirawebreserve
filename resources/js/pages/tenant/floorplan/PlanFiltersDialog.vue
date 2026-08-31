<script setup lang="ts">
import Button from '@/components/Base/Button';
import { FormInput, FormLabel, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';

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
    <Dialog :open="open" size="lg" @close="$emit('close')">
        <Dialog.Panel>
            <div
                class="flex items-center gap-3.5 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                >
                    <Lucide
                        icon="SlidersHorizontal"
                        class="h-5 w-5 text-primary"
                    />
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-medium">Buscar y filtrar</h2>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ matching }} de {{ total }}
                        {{ total === 1 ? 'habitación' : 'habitaciones' }} a la
                        vista
                    </p>
                </div>
                <button
                    type="button"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                    aria-label="Cerrar"
                    @click="$emit('close')"
                >
                    <Lucide icon="X" class="h-5 w-5" />
                </button>
            </div>

            <div class="max-h-[70vh] space-y-5 overflow-y-auto px-5 py-4">
                <div v-if="zones.length > 1">
                    <FormLabel>Ir a una zona</FormLabel>
                    <p class="mb-2.5 text-xs text-slate-500">
                        Encuadra esa planta sin salir de pantalla completa.
                    </p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <button
                            type="button"
                            class="flex min-h-11 items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition"
                            :class="
                                activeZone === null
                                    ? 'border-primary bg-primary/5 text-primary'
                                    : 'border-slate-200/70 hover:border-slate-300 dark:border-darkmode-400'
                            "
                            @click="$emit('zone', null)"
                        >
                            <Lucide icon="Scan" class="h-4 w-4 shrink-0" />
                            Todo el plano
                        </button>
                        <button
                            v-for="zone in zones"
                            :key="zone.id"
                            type="button"
                            class="flex min-h-11 items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition"
                            :class="
                                activeZone === zone.id
                                    ? 'border-primary bg-primary/5 text-primary'
                                    : 'border-slate-200/70 hover:border-slate-300 dark:border-darkmode-400'
                            "
                            @click="$emit('zone', zone.id)"
                        >
                            <Lucide icon="MapPin" class="h-4 w-4 shrink-0" />
                            <span class="min-w-0 flex-1 truncate">{{
                                zone.name
                            }}</span>
                        </button>
                    </div>
                </div>

                <div
                    :class="
                        zones.length > 1
                            ? 'border-t border-dashed border-slate-300/70 pt-5'
                            : ''
                    "
                >
                    <FormLabel htmlFor="plan-search"
                        >Buscar habitación o huésped</FormLabel
                    >
                    <div class="relative">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                        />
                        <FormInput
                            id="plan-search"
                            v-model="search"
                            type="text"
                            class="pl-9"
                            placeholder="Número, huésped, tipo o código de reserva"
                        />
                    </div>
                    <p class="mt-2 text-xs text-slate-500">
                        Las que no coinciden se ven atenuadas, para no perder el
                        acomodo del plano.
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <FormLabel htmlFor="plan-status">Estado</FormLabel>
                        <FormSelect id="plan-status" v-model="status">
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
                        <FormLabel htmlFor="plan-type">Tipo</FormLabel>
                        <FormSelect id="plan-type" v-model="type">
                            <option value="">Todos los tipos</option>
                            <option
                                v-for="t in typeOptions"
                                :key="t"
                                :value="t"
                            >
                                {{ t }}
                            </option>
                        </FormSelect>
                    </div>
                </div>
            </div>

            <div
                class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
            >
                <Button
                    v-if="filtersActive"
                    variant="outline-secondary"
                    class="rounded-[0.5rem]"
                    @click="$emit('clear')"
                >
                    <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                    Limpiar
                </Button>
                <Button
                    variant="primary"
                    class="rounded-[0.5rem]"
                    @click="$emit('close')"
                >
                    Ver el plano
                </Button>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
