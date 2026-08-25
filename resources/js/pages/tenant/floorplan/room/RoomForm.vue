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
}>();

const emit = defineEmits<{
    (e: 'submit', value: RoomFormValue): void;
    (e: 'cancel'): void;
}>();

const form = ref<RoomFormValue>({ ...props.initial });

watch(
    () => props.initial,
    (initial) => {
        form.value = { ...initial };
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
    <form class="space-y-3" @submit.prevent="submit">
        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label class="text-xs text-slate-500" :for="fieldId('number')"
                    >Número</label
                >
                <FormInput
                    :id="fieldId('number')"
                    v-model="form.number"
                    type="text"
                    class="mt-1"
                    maxlength="20"
                    placeholder="Ej. 110"
                    required
                />
            </div>
            <div>
                <label class="text-xs text-slate-500" :for="fieldId('type')"
                    >Tipo</label
                >
                <FormSelect
                    :id="fieldId('type')"
                    v-model.number="form.room_type_id"
                    class="mt-1"
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
                <label class="text-xs text-slate-500" :for="fieldId('zone')"
                    >Zona</label
                >
                <FormSelect
                    :id="fieldId('zone')"
                    v-model.number="form.zone_id"
                    class="mt-1"
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

        <div class="flex gap-2">
            <Button
                variant="primary"
                type="submit"
                class="min-h-11 rounded-[0.5rem]"
                :disabled="busy"
            >
                {{ mode === 'create' ? 'Crear' : 'Guardar' }}
            </Button>
            <Button
                variant="outline-secondary"
                type="button"
                class="min-h-11 rounded-[0.5rem] bg-white dark:bg-darkmode-600"
                @click="emit('cancel')"
            >
                Cancelar
            </Button>
        </div>
    </form>
</template>
