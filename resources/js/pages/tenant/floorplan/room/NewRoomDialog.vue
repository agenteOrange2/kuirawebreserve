<script setup lang="ts">
import { computed } from 'vue';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
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
    <Dialog :open="open" size="lg" @close="emit('close')">
        <Dialog.Panel>
            <div
                class="flex items-center gap-3.5 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                >
                    <Lucide icon="BedDouble" class="h-5 w-5 text-primary" />
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-medium">Nueva habitación</h2>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Lo mínimo para que exista y se pueda vender; el resto de
                        la ficha se completa en Habitaciones.
                    </p>
                </div>
            </div>

            <div class="px-5 py-4">
                <p v-if="!roomTypes.length" class="text-xs text-slate-500">
                    Primero crea un tipo de habitación en el catálogo: una
                    habitación sin tipo no tiene tarifa que cobrar.
                </p>
                <RoomForm
                    v-else
                    mode="create"
                    :initial="initial"
                    :room-types="roomTypes"
                    :zones="zones"
                    :busy="busy"
                    id-prefix="new-room"
                    @submit="(value) => emit('create', value)"
                    @cancel="emit('close')"
                />
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
