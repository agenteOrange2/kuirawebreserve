<script setup lang="ts">
import axios from 'axios';
import { reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect, FormTextarea } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import PlanModal from './PlanModal.vue';
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

// Lo último que se mostró de cada diálogo: el plano suelta el cuarto al cerrar
// y sin esto el título se vaciaba durante la animación de salida.
const shownStart = ref<RoomData | null>(null);
const shownClose = ref<RoomData | null>(null);
watch(
    () => props.startRoom,
    (room) => {
        if (room) {
            shownStart.value = room;
        }
    },
);
watch(
    () => props.closeRoom,
    (room) => {
        if (room) {
            shownClose.value = room;
        }
    },
);

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
    <PlanModal
        :open="startRoom !== null"
        icon="Sparkles"
        tone="info"
        :title="`Iniciar limpieza · ${shownStart?.number ?? ''}`"
        subtitle="Queda con nombre y hora; el tiempo corre desde ahora."
        panel-class="sm:w-[480px]"
        :busy="busy"
        @close="emit('close')"
    >
        <div>
            <label
                for="cleaning-housekeeper"
                class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                >Camarista</label
            >
            <FormSelect
                id="cleaning-housekeeper"
                v-model="startForm.housekeeper_id"
                class="h-9 text-xs"
            >
                <option value="">Elige quién limpia</option>
                <option v-for="h in housekeepers" :key="h.id" :value="h.id">
                    {{ h.name }}
                </option>
            </FormSelect>
            <p
                v-if="housekeepers.length === 0"
                class="mt-1.5 text-xs text-slate-500"
            >
                No hay camaristas dadas de alta.
                <a
                    :href="route('tenant.housekeeping.staff')"
                    class="font-medium text-primary hover:underline"
                    >Agrégalas en Limpieza</a
                >
                para poder registrar el trabajo.
            </p>
        </div>
        <div>
            <label
                for="cleaning-kind"
                class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                >Tipo de limpieza</label
            >
            <FormSelect
                id="cleaning-kind"
                v-model="startForm.kind"
                class="h-9 text-xs"
            >
                <option v-for="(label, key) in kinds" :key="key" :value="key">
                    {{ label }}
                </option>
            </FormSelect>
        </div>

        <template #footer>
            <Button
                variant="outline-secondary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="busy"
                @click="emit('close')"
            >
                Cancelar
            </Button>
            <Button
                variant="primary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="busy || !startForm.housekeeper_id"
                @click="start"
            >
                <Lucide icon="Sparkles" class="mr-1.5 h-3.5 w-3.5" />
                {{ busy ? 'Iniciando…' : 'Iniciar limpieza' }}
            </Button>
        </template>
    </PlanModal>

    <!-- Terminar -->
    <PlanModal
        :open="closeRoom !== null"
        icon="CircleCheck"
        tone="success"
        :title="`Terminar limpieza · ${shownClose?.number ?? ''}`"
        :subtitle="
            shownClose?.cleaning
                ? `${shownClose.cleaning.housekeeper} · ${shownClose.cleaning.minutes} min`
                : null
        "
        panel-class="sm:w-[94vw] lg:w-[620px]"
        :busy="busy"
        @close="emit('close')"
    >
        <div>
            <div
                class="mb-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                Qué se hizo
            </div>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <button
                    v-for="task in checklist"
                    :key="task.key"
                    type="button"
                    class="flex h-9 items-center gap-2 rounded-[0.5rem] border px-3 text-left text-xs transition"
                    :class="
                        closeForm.checklist.includes(task.key)
                            ? 'border-success/30 bg-success/5 text-slate-700 dark:text-slate-200'
                            : 'border-slate-200/70 text-slate-500 hover:bg-slate-50 dark:border-darkmode-400 dark:hover:bg-darkmode-400'
                    "
                    @click="toggleTask(task.key)"
                >
                    <Lucide
                        :icon="
                            closeForm.checklist.includes(task.key)
                                ? 'CircleCheck'
                                : 'Circle'
                        "
                        class="h-3.5 w-3.5 shrink-0"
                        :class="
                            closeForm.checklist.includes(task.key)
                                ? 'text-success'
                                : 'text-slate-300'
                        "
                    />
                    <span class="min-w-0 truncate">{{ task.label }}</span>
                </button>
            </div>
        </div>

        <div v-if="linens.length">
            <div
                class="mb-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                Ropa usada
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div v-for="item in linens" :key="item.key">
                    <label
                        :for="`cleaning-linen-${item.key}`"
                        class="mb-1 block truncate text-xs text-slate-500"
                    >
                        {{ item.label }}
                    </label>
                    <FormInput
                        :id="`cleaning-linen-${item.key}`"
                        v-model.number="closeForm.linens[item.key]"
                        type="number"
                        min="0"
                        max="99"
                        placeholder="0"
                        class="h-9 text-center text-xs"
                    />
                </div>
            </div>
        </div>

        <div
            class="space-y-4 border-t border-slate-200/60 pt-5 dark:border-darkmode-400"
        >
            <div>
                <label
                    for="cleaning-incident"
                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                    >¿Encontró algo roto o faltante?</label
                >
                <FormInput
                    id="cleaning-incident"
                    v-model="closeForm.incident_title"
                    type="text"
                    class="h-9 text-xs"
                    placeholder="Regadera goteando, control sin pilas…"
                />
                <p class="mt-1.5 text-xs text-slate-500">
                    Si escribes algo, se levanta una incidencia de mantenimiento
                    para esta habitación.
                </p>
            </div>

            <div>
                <label
                    for="cleaning-notes"
                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                    >Notas</label
                >
                <FormTextarea
                    id="cleaning-notes"
                    v-model="closeForm.notes"
                    rows="2"
                    class="text-xs"
                />
            </div>
            <p class="text-[11px] text-slate-400">
                La falla y las notas son opcionales.
            </p>
        </div>

        <template #footer>
            <Button
                variant="outline-secondary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="busy"
                @click="emit('close')"
            >
                Cancelar
            </Button>
            <Button
                variant="primary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="busy"
                @click="finish"
            >
                <Lucide icon="CircleCheck" class="mr-1.5 h-3.5 w-3.5" />
                {{ busy ? 'Guardando…' : 'Terminar y liberar' }}
            </Button>
        </template>
    </PlanModal>
</template>
