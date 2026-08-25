<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
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
import RazeLayout from '@/layouts/RazeLayout.vue';

interface Cleaning {
    id: number;
    room_id: number;
    room: string | null;
    housekeeper_id: number | null;
    housekeeper: string | null;
    kind: string;
    kind_label: string;
    started_label: string | null;
    ended_label: string | null;
    minutes: number;
    open: boolean;
    notes: string | null;
    source: string;
}

interface RoomRow {
    id: number;
    number: string;
    type: string | null;
    status: string;
    status_label: string;
    since_minutes: number | null;
    cleaning: Cleaning | null;
}

const props = defineProps<{
    rooms: RoomRow[];
    cleanings: Cleaning[];
    housekeepers: { id: number; name: string }[];
    allRooms: { id: number; number: string }[];
    checklist: { key: string; label: string }[];
    linens: { key: string; label: string }[];
    kinds: Record<string, string>;
    stats: {
        cleaned_today: number;
        in_progress: number;
        pending: number;
        avg_minutes: number | null;
        unregistered: number;
        housekeepers: number;
    };
    canManage: boolean;
    canViewReports: boolean;
}>();

const toast = useToasts();
const busy = ref(false);

const pending = computed(() => props.rooms.filter((r) => r.status === 'dirty'));
const working = computed(() =>
    props.rooms.filter((r) => r.status === 'cleaning'),
);
const doneToday = computed(() => props.cleanings.filter((c) => !c.open));

const cards = computed(() => [
    {
        label: 'Por limpiar',
        value: props.stats.pending,
        icon: 'Brush' as const,
        tone: 'border-pending/20 bg-pending/10 text-pending',
    },
    {
        label: 'En limpieza ahora',
        value: props.stats.in_progress,
        icon: 'Sparkles' as const,
        tone: 'border-info/20 bg-info/10 text-info',
    },
    {
        label: 'Limpiadas hoy',
        value: props.stats.cleaned_today,
        icon: 'CircleCheck' as const,
        tone: 'border-success/20 bg-success/10 text-success',
    },
    {
        label: 'Tiempo promedio',
        value:
            props.stats.avg_minutes !== null
                ? `${props.stats.avg_minutes} min`
                : '—',
        icon: 'Clock' as const,
        tone: 'border-primary/20 bg-primary/10 text-primary',
    },
]);

// ── Iniciar limpieza ──
const startModal = ref(false);
const startRoom = ref<RoomRow | null>(null);
const startForm = reactive({ housekeeper_id: '', kind: 'salida' });

function openStart(room: RoomRow) {
    startRoom.value = room;
    startForm.housekeeper_id = '';
    startForm.kind = 'salida';
    startModal.value = true;
}

async function startCleaning() {
    if (!startRoom.value || !startForm.housekeeper_id) {
        toast.error(
            'Falta la camarista',
            'Elige quién va a limpiar la habitación.',
        );
        return;
    }
    busy.value = true;
    try {
        await axios.post(`/api/rooms/${startRoom.value.id}/cleanings`, {
            housekeeper_id: Number(startForm.housekeeper_id),
            kind: startForm.kind,
        });
        startModal.value = false;
        router.reload({ only: ['rooms', 'cleanings', 'stats'] });
        toast.success(
            'Limpieza iniciada',
            `La ${startRoom.value.number} está en limpieza.`,
        );
    } catch (e: any) {
        toast.error(
            'No se pudo iniciar',
            e.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        busy.value = false;
    }
}

// ── Cerrar limpieza ──
const closeModal = ref(false);
const closeRoom = ref<RoomRow | null>(null);
const closeForm = reactive({
    checklist: [] as string[],
    linens: {} as Record<string, number>,
    notes: '',
    incident_title: '',
    set_maintenance: false,
});

function openClose(room: RoomRow) {
    closeRoom.value = room;
    // Se marcan todas por defecto: lo normal es que la limpieza se haga
    // completa, y así el registro cuesta un clic en vez de seis.
    closeForm.checklist = props.checklist.map((t) => t.key);
    closeForm.linens = {};
    closeForm.notes = '';
    closeForm.incident_title = '';
    closeForm.set_maintenance = false;
    closeModal.value = true;
}

function toggleTask(key: string) {
    closeForm.checklist = closeForm.checklist.includes(key)
        ? closeForm.checklist.filter((k) => k !== key)
        : [...closeForm.checklist, key];
}

async function finishCleaning() {
    const cleaning = closeRoom.value?.cleaning;
    if (!cleaning) return;
    busy.value = true;
    try {
        await axios.patch(`/api/cleanings/${cleaning.id}`, { ...closeForm });
        closeModal.value = false;
        router.reload({ only: ['rooms', 'cleanings', 'stats'] });
        toast.success(
            'Limpieza registrada',
            `La ${closeRoom.value?.number} quedó lista.`,
        );
    } catch (e: any) {
        toast.error(
            'No se pudo cerrar',
            e.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        busy.value = false;
    }
}

// ── Captura manual ──
const manualModal = ref(false);
const manualForm = reactive({
    room_id: '',
    housekeeper_id: '',
    kind: 'salida',
    started_at: '',
    ended_at: '',
    notes: '',
});

function openManual() {
    Object.assign(manualForm, {
        room_id: '',
        housekeeper_id: '',
        kind: 'salida',
        started_at: '',
        ended_at: '',
        notes: '',
    });
    manualModal.value = true;
}

async function saveManual() {
    busy.value = true;
    try {
        await axios.post('/api/cleanings', {
            ...manualForm,
            room_id: Number(manualForm.room_id),
            housekeeper_id: Number(manualForm.housekeeper_id),
        });
        manualModal.value = false;
        router.reload({ only: ['rooms', 'cleanings', 'stats'] });
        toast.success('Registrada', 'La limpieza quedó asentada.');
    } catch (e: any) {
        const data = e.response?.data;
        // El primer error de validación es más útil que "revisa los datos".
        const firstError = Object.values(
            (data?.errors ?? {}) as Record<string, string[]>,
        )[0]?.[0];
        toast.error(
            'No se pudo registrar',
            data?.message ?? firstError ?? 'Revisa los datos.',
        );
    } finally {
        busy.value = false;
    }
}

const waitLabel = (minutes: number | null) => {
    if (minutes === null) return '';
    if (minutes < 60) return `${minutes} min`;
    const h = Math.floor(minutes / 60);
    return `${h} h ${minutes % 60} min`;
};
</script>

<template>
    <RazeLayout title="Limpieza">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-wrap items-center justify-between gap-4 p-5"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <div
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Brush" class="h-7 w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl font-medium">Limpieza</h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Qué falta, qué se está limpiando ahorita y quién lo
                            trabajó.
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="canViewReports"
                        as="a"
                        :href="route('tenant.housekeeping.reports')"
                        variant="outline-secondary"
                        class="min-h-11 rounded-[0.5rem] bg-white"
                    >
                        <Lucide icon="ChartColumn" class="mr-2 h-4 w-4" />
                        Rendimiento
                    </Button>
                    <Button
                        as="a"
                        :href="route('tenant.housekeeping.staff')"
                        variant="outline-secondary"
                        class="min-h-11 rounded-[0.5rem] bg-white"
                    >
                        <Lucide icon="Users" class="mr-2 h-4 w-4" />
                        Camaristas ({{ stats.housekeepers }})
                    </Button>
                    <Button
                        v-if="canManage"
                        variant="primary"
                        class="min-h-11 rounded-[0.5rem] shadow-md shadow-primary/20"
                        @click="openManual"
                    >
                        <Lucide icon="Plus" class="mr-2 h-4 w-4" />
                        Registrar limpieza
                    </Button>
                </div>
            </div>

            <div
                v-if="stats.unregistered > 0"
                class="box box--stacked mt-5 flex items-start gap-3 border-l-4 border-l-warning p-5"
            >
                <div
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-warning/20 bg-warning/10 text-warning"
                >
                    <Lucide icon="TriangleAlert" class="h-5 w-5" />
                </div>
                <div class="min-w-0 text-sm">
                    <div class="font-medium">
                        {{ stats.unregistered }} limpieza(s) de hoy sin
                        registrar
                    </div>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Habitaciones que quedaron disponibles sin que nadie
                        capturara quién las limpió — normalmente porque el
                        sistema las liberó solo por tiempo. Usa "Registrar
                        limpieza" para asentarlas y que cuenten en el reporte.
                    </p>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-5">
                <div
                    v-for="card in cards"
                    :key="card.label"
                    class="col-span-6 xl:col-span-3"
                >
                    <div
                        class="box box--stacked flex h-full items-center gap-4 p-5"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border"
                            :class="card.tone"
                        >
                            <Lucide :icon="card.icon" class="h-5 w-5" />
                        </div>
                        <div>
                            <div class="text-xl font-medium">
                                {{ card.value }}
                            </div>
                            <div class="mt-0.5 text-xs text-slate-500">
                                {{ card.label }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-12 items-start gap-6">
                <!-- Por limpiar -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="box box--stacked">
                        <div
                            class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="Brush"
                                    class="h-4 w-4 stroke-[1.5] text-pending"
                                />
                                <h2 class="text-base font-medium">
                                    Por limpiar
                                </h2>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                Ordenadas por número; el tiempo dice cuánto
                                llevan esperando.
                            </p>
                        </div>
                        <div class="p-5">
                            <p
                                v-if="pending.length === 0"
                                class="rounded-lg border border-dashed border-slate-300/70 py-6 text-center text-sm text-slate-500 dark:border-darkmode-400"
                            >
                                Ninguna habitación esperando.
                            </p>
                            <div v-else class="flex flex-col gap-2">
                                <div
                                    v-for="room in pending"
                                    :key="room.id"
                                    class="flex items-center gap-3 rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400"
                                >
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium">
                                            {{ room.number }}
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            {{ room.type }}
                                            <span v-if="room.since_minutes">
                                                · esperando
                                                {{
                                                    waitLabel(
                                                        room.since_minutes,
                                                    )
                                                }}
                                            </span>
                                        </div>
                                    </div>
                                    <Button
                                        v-if="canManage"
                                        variant="outline-primary"
                                        size="sm"
                                        class="shrink-0 rounded-[0.5rem] bg-white"
                                        :disabled="busy"
                                        @click="openStart(room)"
                                    >
                                        Iniciar
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- En limpieza -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="box box--stacked">
                        <div
                            class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="Sparkles"
                                    class="h-4 w-4 stroke-[1.5] text-info"
                                />
                                <h2 class="text-base font-medium">
                                    En limpieza
                                </h2>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                Con el cronómetro corriendo.
                            </p>
                        </div>
                        <div class="p-5">
                            <p
                                v-if="working.length === 0"
                                class="rounded-lg border border-dashed border-slate-300/70 py-6 text-center text-sm text-slate-500 dark:border-darkmode-400"
                            >
                                Nadie limpiando en este momento.
                            </p>
                            <div v-else class="flex flex-col gap-2">
                                <div
                                    v-for="room in working"
                                    :key="room.id"
                                    class="flex items-center gap-3 rounded-lg border border-info/30 bg-info/5 p-3"
                                >
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium">
                                            {{ room.number }}
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            <template v-if="room.cleaning">
                                                {{
                                                    room.cleaning.housekeeper ??
                                                    'Sin camarista'
                                                }}
                                                ·
                                                {{ room.cleaning.minutes }} min
                                            </template>
                                            <template v-else>
                                                Sin registro: usa Registrar
                                                limpieza
                                            </template>
                                        </div>
                                    </div>
                                    <Button
                                        v-if="canManage && room.cleaning"
                                        variant="outline-primary"
                                        size="sm"
                                        class="shrink-0 rounded-[0.5rem] bg-white"
                                        :disabled="busy"
                                        @click="openClose(room)"
                                    >
                                        Terminar
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Limpiadas hoy -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="box box--stacked">
                        <div
                            class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="CircleCheck"
                                    class="h-4 w-4 stroke-[1.5] text-success"
                                />
                                <h2 class="text-base font-medium">
                                    Limpiadas hoy
                                </h2>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                Lo que ya quedó, con su tiempo real.
                            </p>
                        </div>
                        <div class="p-5">
                            <p
                                v-if="doneToday.length === 0"
                                class="rounded-lg border border-dashed border-slate-300/70 py-6 text-center text-sm text-slate-500 dark:border-darkmode-400"
                            >
                                Todavía no se registra ninguna.
                            </p>
                            <div v-else class="flex flex-col gap-2">
                                <div
                                    v-for="cleaning in doneToday"
                                    :key="cleaning.id"
                                    class="flex items-center gap-3 rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400"
                                >
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium">
                                            {{ cleaning.room }}
                                            <span
                                                class="ml-1 rounded-full border border-slate-200/70 px-2 py-0.5 text-[10px] font-normal text-slate-500 dark:border-darkmode-400"
                                                >{{ cleaning.kind_label }}</span
                                            >
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            {{
                                                cleaning.housekeeper ??
                                                'Sin camarista'
                                            }}
                                            · {{ cleaning.started_label }}–{{
                                                cleaning.ended_label
                                            }}
                                            · {{ cleaning.minutes }} min
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Iniciar limpieza -->
        <Dialog :open="startModal" @close="startModal = false">
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
                                Todavía no hay camaristas dadas de alta.
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
                        @click="startModal = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="primary"
                        class="w-28"
                        :disabled="busy"
                        @click="startCleaning"
                    >
                        Iniciar
                    </Button>
                </Dialog.Footer>
            </Dialog.Panel>
        </Dialog>

        <!-- Terminar limpieza -->
        <Dialog :open="closeModal" size="lg" @close="closeModal = false">
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
                                            closeForm.checklist.includes(
                                                task.key,
                                            )
                                                ? 'CircleCheck'
                                                : 'Circle'
                                        "
                                        class="h-4 w-4 shrink-0"
                                        :class="
                                            closeForm.checklist.includes(
                                                task.key,
                                            )
                                                ? 'text-success'
                                                : 'text-slate-300'
                                        "
                                    />
                                    {{ task.label }}
                                </button>
                            </div>
                        </div>

                        <div v-if="linens.length">
                            <div class="mb-2 text-sm font-medium">
                                Ropa usada
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div v-for="item in linens" :key="item.key">
                                    <label
                                        class="mb-1 block text-xs text-slate-500"
                                    >
                                        {{ item.label }}
                                    </label>
                                    <FormInput
                                        v-model.number="
                                            closeForm.linens[item.key]
                                        "
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
                        @click="closeModal = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="primary"
                        class="w-36"
                        :disabled="busy"
                        @click="finishCleaning"
                    >
                        Terminar y liberar
                    </Button>
                </Dialog.Footer>
            </Dialog.Panel>
        </Dialog>

        <!-- Captura manual -->
        <Dialog :open="manualModal" size="lg" @close="manualModal = false">
            <Dialog.Panel>
                <Dialog.Title>
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Brush" class="h-5 w-5" />
                    </div>
                    <h2 class="ml-3 text-base font-medium">
                        Registrar limpieza
                    </h2>
                </Dialog.Title>
                <Dialog.Description>
                    <p class="mb-3 text-xs text-slate-500">
                        Para asentar lo que ya pasó. No cambia el estado de la
                        habitación.
                    </p>
                    <div class="space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-sm">
                                    Habitación
                                </label>
                                <FormSelect v-model="manualForm.room_id">
                                    <option value="">Elige</option>
                                    <option
                                        v-for="r in allRooms"
                                        :key="r.id"
                                        :value="r.id"
                                    >
                                        {{ r.number }}
                                    </option>
                                </FormSelect>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">
                                    Camarista
                                </label>
                                <FormSelect v-model="manualForm.housekeeper_id">
                                    <option value="">Elige</option>
                                    <option
                                        v-for="h in housekeepers"
                                        :key="h.id"
                                        :value="h.id"
                                    >
                                        {{ h.name }}
                                    </option>
                                </FormSelect>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-sm">Entró</label>
                                <FormInput
                                    v-model="manualForm.started_at"
                                    type="datetime-local"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Salió</label>
                                <FormInput
                                    v-model="manualForm.ended_at"
                                    type="datetime-local"
                                />
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">
                                Tipo de limpieza
                            </label>
                            <FormSelect v-model="manualForm.kind">
                                <option
                                    v-for="(label, key) in kinds"
                                    :key="key"
                                    :value="key"
                                >
                                    {{ label }}
                                </option>
                            </FormSelect>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Notas</label>
                            <FormTextarea
                                v-model="manualForm.notes"
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
                        @click="manualModal = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="primary"
                        class="w-28"
                        :disabled="busy"
                        @click="saveManual"
                    >
                        Guardar
                    </Button>
                </Dialog.Footer>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
