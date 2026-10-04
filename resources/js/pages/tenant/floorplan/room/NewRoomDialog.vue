<script setup lang="ts">
import { computed } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import PlanModal from '../PlanModal.vue';
import RoomForm from './RoomForm.vue';
import type { RoomFormValue } from './RoomForm.vue';

/**
 * Dar de alta una habitación desde la barra del plano.
 *
 * El alta también vive en el tab Cuarto del modal, pero ahí solo aparece con el
 * candado "Editar plano" abierto: quien busca "crear una habitación" no la
 * encontraba. Este es el lugar donde uno la busca, y usa el MISMO formulario.
 */
const props = defineProps<{
    open: boolean;
    roomTypes: { id: number; name: string }[];
    zones: { id: number; name: string }[];
    busy: boolean;
    /** Semillas del cuarto que se estaba mirando, si había alguno. */
    seed: { room_type_id: number | null; zone_id: number | null } | null;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'create', value: RoomFormValue): void;
}>();

const initial = computed<RoomFormValue>(() => ({
    number: '',
    room_type_id: props.seed?.room_type_id ?? props.roomTypes[0]?.id ?? 0,
    zone_id: props.seed?.zone_id ?? null,
}));
</script>

<template>
    <PlanModal
        :open="open"
        icon="BedDouble"
        title="Nueva habitación"
        subtitle="Lo mínimo para que exista y se pueda vender; el resto de la ficha se completa en Habitaciones."
        panel-class="sm:w-[600px]"
        :busy="busy"
        @close="emit('close')"
    >
        <div
            v-if="!roomTypes.length"
            class="flex items-start gap-2.5 rounded-lg border border-warning/20 bg-warning/5 px-3 py-2.5 text-xs text-slate-600 dark:text-slate-300"
        >
            <Lucide
                icon="TriangleAlert"
                class="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning"
            />
            <span>
                Primero crea un tipo de habitación: una habitación sin tipo no
                tiene tarifa que cobrar.
                <a
                    :href="route('tenant.catalog')"
                    class="font-medium text-primary hover:underline"
                    >Ir a Zonas y tipos</a
                >
            </span>
        </div>
        <RoomForm
            v-else
            mode="create"
            :initial="initial"
            :room-types="roomTypes"
            :zones="zones"
            :busy="busy"
            id-prefix="new-room"
            hide-actions
            @submit="(value) => emit('create', value)"
            @cancel="emit('close')"
        />

        <template #footer>
            <Button
                variant="outline-secondary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="busy"
                @click="emit('close')"
            >
                {{ roomTypes.length ? 'Cancelar' : 'Cerrar' }}
            </Button>
            <Button
                v-if="roomTypes.length"
                variant="primary"
                type="submit"
                form="new-room-form"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="busy"
            >
                <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                {{ busy ? 'Creando…' : 'Crear habitación' }}
            </Button>
        </template>
    </PlanModal>
</template>
