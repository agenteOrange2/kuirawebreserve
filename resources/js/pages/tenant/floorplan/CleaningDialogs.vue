<script setup lang="ts">
import axios from 'axios';
import { reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import type { RoomData } from './types';

/**
 * Los dos momentos del registro de limpieza dentro del plano: quién empieza
 * y qué se hizo al terminar. Vive aparte de FloorPlan.vue porque esa página
 * ya carga con demasiado.
 */
const props = defineProps<{
    startRoom: RoomData | null;
    closeRoom: RoomData | null;
    housekeepers: { id: number; name: string }[];
    checklist: { key: string; label: string }[];
    linens: { key: string; label: string }[];
    kinds: Record<string, string>;
}>();

const emit = defineEmits<{
    (e: 'saved'): void;
    (e: 'close'): void;
}>();

const toast = useToasts();
const busy = ref(false);

const startForm = reactive({ housekeeper_id: '', kind: 'salida' });
const closeForm = reactive({
    checklist: [] as string[],
    linens: {} as Record<string, number>,
    notes: '',
    incident_title: '',
    set_maintenance: false,
});

watch(
    () => props.startRoom,
    (room) => {
        if (room) {
            startForm.housekeeper_id = '';
            startForm.kind = 'salida';
        }
    },
);

watch(
    () => props.closeRoom,
    (room) => {
        if (room) {
            // Todas marcadas: lo normal es la limpieza completa, así cerrar
            // cuesta un clic en vez de seis.
            closeForm.checklist = props.checklist.map((t) => t.key);
            closeForm.linens = {};
            closeForm.notes = '';
            closeForm.incident_title = '';
            closeForm.set_maintenance = false;
        }
    },
);

function toggleTask(key: string) {
    closeForm.checklist = closeForm.checklist.includes(key)
        ? closeForm.checklist.filter((k) => k !== key)
        : [...closeForm.checklist, key];
}

async function start() {
    if (!props.startRoom || !startForm.housekeeper_id) {
        toast.error('Falta la camarista', 'Elige quién va a limpiar.');
        return;
    }
    busy.value = true;
    try {
        await axios.post(`/api/rooms/${props.startRoom.id}/cleanings`, {
            housekeeper_id: Number(startForm.housekeeper_id),
            kind: startForm.kind,
        });
        toast.success(
            'Limpieza iniciada',
            `La ${props.startRoom.number} quedó en limpieza.`,
        );
        emit('saved');
    } catch (e: any) {
        toast.error(
            'No se pudo iniciar',
            e.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        busy.value = false;
    }
}

async function finish() {
    const cleaning = props.closeRoom?.cleaning;
    if (!cleaning) return;
    busy.value = true;
    try {
        await axios.patch(`/api/cleanings/${cleaning.id}`, { ...closeForm });
        toast.success(
            'Limpieza registrada',
            `La ${props.closeRoom?.number} quedó disponible.`,
        );
        emit('saved');
    } catch (e: any) {
        toast.error(
            'No se pudo cerrar',
            e.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <!-- Iniciar -->
    <Dialog :open="startRoom !== null" @close="emit('close')">
        <Dialog.Panel>
            <Dialog.Title>
                <div
                    class="flex h-10 w-10 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                >
                    <Lucide icon="Sparkles" class="h-5 w-5" />
                </div>
                <h2 class="ml-3 text-base font-medium">
                    Iniciar limpieza · {{ startRoom?.number }}
                </h2>
            </Dialog.Title>
            <Dialog.Description>
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-sm">Camarista</label>
                        <FormSelect v-model="startForm.housekeeper_id">
                            <option value="">Elige quién limpia</option>
                            <option
                                v-for="h in housekeepers"
                                :key="h.id"
                                :value="h.id"
                            >
                                {{ h.name }}
                            </option>
                        </FormSelect>
                        <FormHelp v-if="housekeepers.length === 0">
                            No hay camaristas dadas de alta: agrégalas en
                            Limpieza para poder registrar el trabajo.
                        </FormHelp>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">
                            Tipo de limpieza
                        </label>
                        <FormSelect v-model="startForm.kind">
                            <option
                                v-for="(label, key) in kinds"
                                :key="key"
                                :value="key"
                            >
                                {{ label }}
                            </option>
                        </FormSelect>
                    </div>
                </div>
            </Dialog.Description>
            <Dialog.Footer>
                <Button
                    variant="outline-secondary"
                    class="mr-2 w-24"
                    @click="emit('close')"
                >
                    Cancelar
                </Button>
                <Button
                    variant="primary"
                    class="w-28"
                    :disabled="busy"
                    @click="start"
                >
                    Iniciar
                </Button>
            </Dialog.Footer>
        </Dialog.Panel>
    </Dialog>

    <!-- Terminar -->
    <Dialog :open="closeRoom !== null" size="lg" @close="emit('close')">
        <Dialog.Panel>
            <Dialog.Title>
                <div
                    class="flex h-10 w-10 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                >
                    <Lucide icon="CircleCheck" class="h-5 w-5" />
                </div>
                <h2 class="ml-3 text-base font-medium">
                    Terminar limpieza · {{ closeRoom?.number }}
                </h2>
                <span
                    v-if="closeRoom?.cleaning"
                    class="ml-2 text-xs text-slate-500"
                >
                    {{ closeRoom.cleaning.housekeeper }} ·
                    {{ closeRoom.cleaning.minutes }} min
                </span>
            </Dialog.Title>
            <Dialog.Description>
                <div class="space-y-4">
                    <div>
                        <div class="mb-2 text-sm font-medium">
                            ¿Qué se hizo?
                        </div>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <button
                                v-for="task in checklist"
                                :key="task.key"
                                type="button"
                                class="flex items-center gap-2 rounded-lg border p-2.5 text-left text-sm transition"
                                :class="
                                    closeForm.checklist.includes(task.key)
                                        ? 'border-success/30 bg-success/5'
                                        : 'border-slate-200/70 dark:border-darkmode-400'
                                "
                                @click="toggleTask(task.key)"
                            >
                                <Lucide
                                    :icon="
                                        closeForm.checklist.includes(task.key)
                                            ? 'CircleCheck'
                                            : 'Circle'
                                    "
                                    class="h-4 w-4 shrink-0"
                                    :class="
                                        closeForm.checklist.includes(task.key)
                                            ? 'text-success'
                                            : 'text-slate-300'
                                    "
                                />
                                {{ task.label }}
                            </button>
                        </div>
                    </div>

                    <div v-if="linens.length">
                        <div class="mb-2 text-sm font-medium">Ropa usada</div>
                        <div class="grid grid-cols-2 gap-3">
                            <div v-for="item in linens" :key="item.key">
                                <label
                                    class="mb-1 block text-xs text-slate-500"
                                >
                                    {{ item.label }}
                                </label>
                                <FormInput
                                    v-model.number="closeForm.linens[item.key]"
                                    type="number"
                                    min="0"
                                    max="99"
                                    placeholder="0"
                                    class="text-center"
                                />
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm">
                            ¿Encontró algo roto o faltante?
                        </label>
                        <FormInput
                            v-model="closeForm.incident_title"
                            type="text"
                            placeholder="Opcional: regadera goteando, control sin pilas..."
                        />
                        <FormHelp>
                            Si escribes algo, se levanta una incidencia de
                            mantenimiento para esta habitación.
                        </FormHelp>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm">Notas</label>
                        <FormTextarea
                            v-model="closeForm.notes"
                            rows="2"
                            placeholder="Opcional"
                        />
                    </div>
                </div>
            </Dialog.Description>
            <Dialog.Footer>
                <Button
                    variant="outline-secondary"
                    class="mr-2 w-24"
                    @click="emit('close')"
                >
                    Cancelar
                </Button>
                <Button
                    variant="primary"
                    class="w-36"
                    :disabled="busy"
                    @click="finish"
                >
                    Terminar y liberar
                </Button>
            </Dialog.Footer>
        </Dialog.Panel>
    </Dialog>
</template>
