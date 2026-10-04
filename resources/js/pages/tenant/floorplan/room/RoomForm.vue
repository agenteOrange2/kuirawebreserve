<script setup lang="ts">
import { ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';

/**
 * El formulario corto de una habitación: número, tipo y zona. A propósito son
 * tres campos — el perfil completo (camas, amenidades, cargos, ocupación,
 * contador de usos) son veinte y vive en /habitaciones.
 *
 * Lo usan los dos lugares desde donde se dan de alta cuartos: el tab Cuarto del
 * modal y el botón "Nueva habitación" de la barra del plano. Compartirlo evita
 * que uno de los dos se quede atrás cuando cambie un campo.
 */
interface Option {
    id: number;
    name: string;
}

export interface RoomFormValue {
    number: string;
    room_type_id: number;
    zone_id: number | null;
}

const props = defineProps<{
    mode: 'create' | 'edit';
    /** Semillas: al dar de alta, el tipo y la zona del cuarto que se miraba. */
    initial: RoomFormValue;
    roomTypes: Option[];
    zones: Option[];
    busy: boolean;
    /** Prefijo de los id, para no repetirlos si hay dos formularios abiertos. */
    idPrefix?: string;
    /**
     * Sin botones propios: el modal de alta los pone en su pie fijo y envía
     * este formulario por su id (`${idPrefix}-form`).
     */
    hideActions?: boolean;
}>();

const emit = defineEmits<{
    (e: 'submit', value: RoomFormValue): void;
    (e: 'cancel'): void;
}>();

// El select usa 0 para "Sin zona"; con null no coincide ninguna opción y el
// campo salía en blanco, como si faltara elegir algo.
const fromInitial = (initial: RoomFormValue): RoomFormValue => ({
    ...initial,
    zone_id: initial.zone_id ?? 0,
});

const form = ref<RoomFormValue>(fromInitial(props.initial));

watch(
    () => props.initial,
    (initial) => {
        form.value = fromInitial(initial);
    },
    { deep: true },
);

const fieldId = (name: string) => `${props.idPrefix ?? 'room'}-${name}`;

function submit() {
    if (!form.value.number.trim() || !form.value.room_type_id) {
        return;
    }

    emit('submit', {
        number: form.value.number.trim(),
        room_type_id: form.value.room_type_id,
        // El select usa 0 para "sin zona": la API espera null.
        zone_id: form.value.zone_id === 0 ? null : form.value.zone_id,
    });
}
</script>

<template>
    <form :id="fieldId('form')" class="space-y-3" @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label
                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                    :for="fieldId('number')"
                    >Número</label
                >
                <FormInput
                    :id="fieldId('number')"
                    v-model="form.number"
                    type="text"
                    class="h-9 text-xs"
                    maxlength="20"
                    placeholder="Ej. 110"
                    required
                />
            </div>
            <div>
                <label
                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                    :for="fieldId('type')"
                    >Tipo</label
                >
                <FormSelect
                    :id="fieldId('type')"
                    v-model.number="form.room_type_id"
                    class="h-9 text-xs"
                >
                    <option
                        v-for="type in roomTypes"
                        :key="type.id"
                        :value="type.id"
                    >
                        {{ type.name }}
                    </option>
                </FormSelect>
            </div>
            <div>
                <label
                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                    :for="fieldId('zone')"
                    >Zona</label
                >
                <FormSelect
                    :id="fieldId('zone')"
                    v-model.number="form.zone_id"
                    class="h-9 text-xs"
                >
                    <option :value="0">Sin zona</option>
                    <option
                        v-for="zone in zones"
                        :key="zone.id"
                        :value="zone.id"
                    >
                        {{ zone.name }}
                    </option>
                </FormSelect>
            </div>
        </div>

        <p v-if="mode === 'create'" class="text-xs text-slate-500">
            Nace en el centro de lo que estás viendo; arrástrala a su lugar con
            el plano desbloqueado.
        </p>

        <div v-if="!hideActions" class="flex justify-end gap-2">
            <Button
                variant="outline-secondary"
                type="button"
                class="h-9 rounded-[0.5rem] bg-white px-5 text-xs dark:bg-darkmode-600"
                :disabled="busy"
                @click="emit('cancel')"
            >
                Cancelar
            </Button>
            <Button
                variant="primary"
                type="submit"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="busy"
            >
                {{
                    busy
                        ? 'Guardando…'
                        : mode === 'create'
                          ? 'Crear'
                          : 'Guardar'
                }}
            </Button>
        </div>
    </form>
</template>
