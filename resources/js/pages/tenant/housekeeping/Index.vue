<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormDateTime,
    FormHelp,
    FormInput,
    FormLabel,
    FormSelect,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

/**
 * Panel de limpieza con la estructura de Reservas grupales: cabecera
 * compacta, contadores en fila y DOS tablas con su propio buscador, su orden
 * y su paginación — el tablero del día (lo que falta) y la bitácora (lo que
 * ya se registró).
 */
interface Cleaning {
    id: number;
    room_id: number;
    room: string | null;
    housekeeper_id: number | null;
    housekeeper: string | null;
    kind: string;
    kind_label: string;
    started_day?: string | null;
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

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    total: number;
    from: number | null;
    to: number | null;
    current_page: number;
}

const props = defineProps<{
    rooms: Paginated<RoomRow>;
    boardFilters: { estado: string; q: string; orden: string };
    cleanings: Paginated<Cleaning>;
    logFilters: {
        rango: string;
        camarista: number | null;
        tipo: string;
        situacion: string;
        hab: string;
        orden: string;
    };
    housekeepers: { id: number; name: string }[];
    // Rol del día (turnos): quién debería estar y quién está ahorita.
    onDuty: {
        id: number;
        name: string;
        shift: string | null;
        time: string | null;
        color: string;
        now: boolean;
    }[];
    allRooms: { id: number; number: string }[];
    checklist: { key: string; label: string }[];
    linens: { key: string; label: string }[];
    kinds: Record<string, string>;
    stats: {
        cleaned_today: number;
        in_progress: number;
        pending: number;
        cleaning_rooms: number;
        avg_minutes: number | null;
        unregistered: number;
        housekeepers: number;
    };
    canManage: boolean;
    canViewReports: boolean;
}>();

const toast = useToasts();
const busy = ref(false);

const statusTone: Record<string, string> = {
    dirty: 'bg-pending/10 text-pending',
    cleaning: 'bg-warning/10 text-warning',
};

const sourceLabels: Record<string, string> = {
    plano: 'Cronómetro',
    manual: 'Captura manual',
};

const rangeLabels: Record<string, string> = {
    hoy: 'de hoy',
    semana: 'de esta semana',
    mes: 'de este mes',
    todo: 'de todo el historial',
};

const waitLabel = (minutes: number | null) => {
    if (minutes === null) return '';
    if (minutes < 60) return `${minutes} min`;
    const h = Math.floor(minutes / 60);
    return `${h} h ${minutes % 60} min`;
};

// ── Filtros ──
// Las dos tablas comparten la URL, así que cada una manda también el estado
// de la otra; lo único que se reinicia es la página de la tabla que cambió.
const board = reactive({
    estado: props.boardFilters.estado,
    q: props.boardFilters.q,
    orden: props.boardFilters.orden,
});

const log = reactive({
    rango: props.logFilters.rango,
    camarista: props.logFilters.camarista ?? ('' as number | string),
    tipo: props.logFilters.tipo,
    situacion: props.logFilters.situacion,
    hab: props.logFilters.hab,
    orden: props.logFilters.orden,
});

const boardFiltersActive = computed(
    () =>
        board.estado !== '' ||
        board.q.trim() !== '' ||
        board.orden !== 'espera',
);

const logFiltersActive = computed(
    () =>
        log.rango !== 'hoy' ||
        log.camarista !== '' ||
        log.tipo !== '' ||
        log.situacion !== '' ||
        log.hab.trim() !== '' ||
        log.orden !== 'reciente',
);

function go(pages: { pagina?: number; bitacora?: number }): void {
    router.get(
        route('tenant.housekeeping'),
        {
            estado: board.estado || undefined,
            q: board.q.trim() || undefined,
            orden: board.orden !== 'espera' ? board.orden : undefined,
            rango: log.rango !== 'hoy' ? log.rango : undefined,
            camarista: log.camarista || undefined,
            tipo: log.tipo || undefined,
            situacion: log.situacion || undefined,
            hab: log.hab.trim() || undefined,
            lorden: log.orden !== 'reciente' ? log.orden : undefined,
            pagina: pages.pagina,
            bitacora: pages.bitacora,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['rooms', 'cleanings', 'boardFilters', 'logFilters', 'stats'],
        },
    );
}

/** Página actual de la tabla que NO cambió, para no reiniciarla. */
const keep = (page: number) => (page > 1 ? page : undefined);

let boardTimer: ReturnType<typeof setTimeout> | null = null;

watch(board, () => {
    if (boardTimer) clearTimeout(boardTimer);
    boardTimer = setTimeout(
        () =>
            go({
                pagina: undefined,
                bitacora: keep(props.cleanings.current_page),
            }),
        350,
    );
});

let logTimer: ReturnType<typeof setTimeout> | null = null;

watch(log, () => {
    if (logTimer) clearTimeout(logTimer);
    logTimer = setTimeout(
        () =>
            go({ bitacora: undefined, pagina: keep(props.rooms.current_page) }),
        350,
    );
});

function clearBoardFilters(): void {
    board.estado = '';
    board.q = '';
    board.orden = 'espera';
}

function clearLogFilters(): void {
    log.rango = 'hoy';
    log.camarista = '';
    log.tipo = '';
    log.situacion = '';
    log.hab = '';
    log.orden = 'reciente';
}

/** Recarga las dos tablas y los contadores tras una acción. */
function refreshLists(): void {
    router.reload({ only: ['rooms', 'cleanings', 'stats'] });
}

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
        refreshLists();
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
        refreshLists();
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
        refreshLists();
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
</script>

<template>
    <RazeLayout title="Limpieza">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Brush" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Limpieza</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Qué falta, qué se está limpiando ahorita y quién lo
                            trabajó.
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2.5"
                >
                    <Button
                        v-if="canViewReports"
                        :as="Link"
                        :href="route('tenant.housekeeping.reports')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="ChartColumn"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.5]"
                        />
                        Rendimiento
                    </Button>
                    <Button
                        :as="Link"
                        :href="route('tenant.housekeeping.staff')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="Users"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.5]"
                        />
                        Camaristas ({{ stats.housekeepers }})
                    </Button>
                    <Button
                        v-if="canManage"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        @click="openManual"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Registrar limpieza
                    </Button>
                </div>
            </div>

            <div
                v-if="stats.unregistered > 0"
                class="box mt-4 border-l-4 border-l-warning p-4"
            >
                <div class="flex items-start gap-3">
                    <Lucide
                        icon="TriangleAlert"
                        class="mt-0.5 h-5 w-5 shrink-0 text-warning"
                    />
                    <div class="min-w-0">
                        <p class="text-sm font-medium">
                            {{ stats.unregistered }} limpieza(s) de hoy sin
                            registrar
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Habitaciones que quedaron disponibles sin que nadie
                            capturara quién las limpió, normalmente porque el
                            sistema las liberó solo por tiempo. Usa "Registrar
                            limpieza" para asentarlas y que cuenten en el
                            reporte.
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-12 gap-4">
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                    >
                        <Lucide icon="Brush" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.pending }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Por limpiar
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                    >
                        <Lucide icon="Sparkles" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.in_progress }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            En limpieza ahora
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="CircleCheck" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.cleaned_today }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Limpiadas hoy
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Clock" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{
                                stats.avg_minutes !== null
                                    ? `${stats.avg_minutes} min`
                                    : 'Sin dato'
                            }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Tiempo promedio de hoy
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rol del día: el turno de las camaristas se arma en Turnos,
                 aquí solo se lee para saber a quién buscar. -->
            <div class="box box--stacked mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                <div class="flex items-center gap-2">
                    <Lucide
                        icon="CalendarClock"
                        class="h-4 w-4 text-primary"
                    />
                    <span class="text-sm font-medium">Turno de hoy</span>
                </div>
                <template v-if="onDuty.length">
                    <span
                        v-for="person in onDuty"
                        :key="person.id"
                        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                        :class="
                            person.now
                                ? 'border-success/30 bg-success/5 text-success'
                                : 'border-slate-200/70 text-slate-500 dark:border-darkmode-400'
                        "
                    >
                        <span
                            v-if="person.now"
                            class="h-1.5 w-1.5 rounded-full bg-success"
                        />
                        {{ person.name }}
                        <span class="text-slate-400">
                            {{ person.shift }} {{ person.time }}
                        </span>
                    </span>
                </template>
                <span v-else class="text-xs text-slate-400">
                    Nadie de limpieza está programado hoy. El rol se arma en
                    Turnos, igual que el de recepción.
                </span>
            </div>

            <!-- ── Tablero del día ── -->
            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <Lucide icon="Brush" class="h-4 w-4 text-slate-400" />
                        Tablero del día
                        <span
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-darkmode-400"
                        >
                            {{ rooms.total }}
                        </span>
                    </div>
                    <div
                        class="ml-auto flex items-center gap-2 text-xs text-slate-500"
                    >
                        <span v-if="rooms.data.length">
                            Mostrando {{ rooms.from }}-{{ rooms.to }} de
                            {{ rooms.total }}
                        </span>
                        <button
                            v-if="boardFiltersActive"
                            type="button"
                            class="font-medium text-primary hover:underline"
                            @click="clearBoardFilters"
                        >
                            Limpiar filtros
                        </button>
                    </div>
                </div>

                <div
                    class="border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="mb-3 flex items-center gap-2.5">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Filter" class="h-4 w-4" />
                        </div>
                        <div>
                            <div class="text-sm font-medium">
                                Qué falta ahorita
                            </div>
                            <div class="text-xs text-slate-500">
                                Sucias y en limpieza en una sola lista, con la
                                que lleva más tiempo esperando arriba.
                            </div>
                        </div>
                    </div>
                    <div
                        class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-[minmax(14rem,1.5fr)_12rem_13rem_auto]"
                    >
                        <div>
                            <FormLabel htmlFor="board-search">
                                Búsqueda rápida
                            </FormLabel>
                            <div class="relative">
                                <Lucide
                                    icon="Search"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                />
                                <FormInput
                                    id="board-search"
                                    v-model="board.q"
                                    type="search"
                                    class="h-9 pl-9 text-xs"
                                    placeholder="Habitación o tipo"
                                />
                            </div>
                        </div>
                        <div>
                            <FormLabel htmlFor="board-status">Estado</FormLabel>
                            <FormSelect
                                id="board-status"
                                v-model="board.estado"
                                class="h-9 text-xs"
                            >
                                <option value="">Sucias y en limpieza</option>
                                <option value="dirty">
                                    Por limpiar ({{ stats.pending }})
                                </option>
                                <option value="cleaning">
                                    En limpieza ({{ stats.cleaning_rooms }})
                                </option>
                            </FormSelect>
                        </div>
                        <div>
                            <FormLabel htmlFor="board-order">Orden</FormLabel>
                            <FormSelect
                                id="board-order"
                                v-model="board.orden"
                                class="h-9 text-xs"
                            >
                                <option value="espera">
                                    La que lleva más esperando
                                </option>
                                <option value="numero">
                                    Número de habitación
                                </option>
                            </FormSelect>
                        </div>
                        <div class="flex items-end">
                            <Button
                                v-if="boardFiltersActive"
                                type="button"
                                variant="outline-secondary"
                                class="h-9 w-full text-xs whitespace-nowrap xl:w-auto"
                                @click="clearBoardFilters"
                            >
                                <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                                Limpiar
                            </Button>
                        </div>
                    </div>
                </div>

                <div v-if="rooms.data.length" class="overflow-auto">
                    <Table sm hover class="text-xs">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th class="whitespace-nowrap">
                                    Habitación
                                </Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Estado
                                </Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Esperando
                                </Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Camarista
                                </Table.Th>
                                <Table.Th
                                    v-if="canManage"
                                    class="text-right whitespace-nowrap"
                                >
                                    Acción
                                </Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="room in rooms.data" :key="room.id">
                                <Table.Td>
                                    <div class="flex items-center gap-2.5">
                                        <div
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                            :class="
                                                room.status === 'cleaning'
                                                    ? 'bg-warning/10 text-warning'
                                                    : 'bg-pending/10 text-pending'
                                            "
                                        >
                                            <Lucide
                                                :icon="
                                                    room.status === 'cleaning'
                                                        ? 'Sparkles'
                                                        : 'Brush'
                                                "
                                                class="h-4 w-4"
                                            />
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-sm font-medium">
                                                {{ room.number }}
                                            </div>
                                            <div
                                                class="truncate text-slate-500"
                                            >
                                                {{ room.type ?? 'Sin tipo' }}
                                            </div>
                                        </div>
                                    </div>
                                </Table.Td>
                                <Table.Td class="whitespace-nowrap">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="
                                            statusTone[room.status] ??
                                            'bg-slate-100 text-slate-500'
                                        "
                                    >
                                        {{ room.status_label }}
                                    </span>
                                </Table.Td>
                                <Table.Td class="whitespace-nowrap">
                                    <span
                                        v-if="room.since_minutes !== null"
                                        class="font-medium"
                                    >
                                        {{ waitLabel(room.since_minutes) }}
                                    </span>
                                    <span v-else class="text-slate-400">
                                        Sin dato
                                    </span>
                                </Table.Td>
                                <Table.Td>
                                    <template v-if="room.cleaning">
                                        <div class="font-medium">
                                            {{
                                                room.cleaning.housekeeper ??
                                                'Sin camarista'
                                            }}
                                        </div>
                                        <div class="text-slate-500">
                                            {{ room.cleaning.kind_label }} ·
                                            {{ room.cleaning.minutes }} min
                                        </div>
                                    </template>
                                    <span
                                        v-else-if="room.status === 'cleaning'"
                                        class="text-slate-400"
                                    >
                                        Sin registro
                                    </span>
                                    <span v-else class="text-slate-400">
                                        Nadie asignado
                                    </span>
                                </Table.Td>
                                <Table.Td
                                    v-if="canManage"
                                    class="text-right whitespace-nowrap"
                                >
                                    <Button
                                        v-if="room.status === 'dirty'"
                                        variant="outline-primary"
                                        class="h-8 rounded-[0.5rem] text-xs"
                                        :disabled="busy"
                                        @click="openStart(room)"
                                    >
                                        <Lucide
                                            icon="Play"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Iniciar
                                    </Button>
                                    <Button
                                        v-else-if="room.cleaning"
                                        variant="outline-primary"
                                        class="h-8 rounded-[0.5rem] text-xs"
                                        :disabled="busy"
                                        @click="openClose(room)"
                                    >
                                        <Lucide
                                            icon="Check"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Terminar
                                    </Button>
                                    <span v-else class="text-slate-400">
                                        Usa Registrar limpieza
                                    </span>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </div>

                <div
                    v-else-if="boardFiltersActive"
                    class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="SearchX" class="h-7 w-7" />
                    </div>
                    <div>
                        <p class="text-sm font-medium">
                            Ninguna habitación coincide con los filtros
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cambia la búsqueda o vuelve a ver sucias y en
                            limpieza.
                        </p>
                    </div>
                    <Button
                        variant="outline-secondary"
                        @click="clearBoardFilters"
                    >
                        <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                        Limpiar filtros
                    </Button>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-full bg-success/10 text-success"
                    >
                        <Lucide icon="CircleCheck" class="h-7 w-7" />
                    </div>
                    <div>
                        <p class="text-sm font-medium">
                            Nada pendiente de limpiar
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Ninguna habitación está sucia ni en limpieza en este
                            momento.
                        </p>
                    </div>
                </div>

                <div
                    v-if="rooms.links.length > 3"
                    class="flex flex-wrap justify-center gap-1 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <template v-for="(link, i) in rooms.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-state
                            preserve-scroll
                            class="rounded-md px-2.5 py-1 text-xs"
                            :class="
                                link.active
                                    ? 'bg-primary text-white'
                                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-darkmode-400'
                            "
                        >
                            <span v-html="link.label" />
                        </Link>
                        <span
                            v-else
                            class="px-2.5 py-1 text-xs text-slate-400"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>

            <!-- ── Bitácora de limpiezas ── -->
            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <Lucide
                            icon="NotebookPen"
                            class="h-4 w-4 text-slate-400"
                        />
                        Limpiezas registradas
                        <span
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-darkmode-400"
                        >
                            {{ cleanings.total }}
                        </span>
                    </div>
                    <div
                        class="ml-auto flex items-center gap-2 text-xs text-slate-500"
                    >
                        <span v-if="cleanings.data.length">
                            Mostrando {{ cleanings.from }}-{{ cleanings.to }} de
                            {{ cleanings.total }}
                        </span>
                        <button
                            v-if="logFiltersActive"
                            type="button"
                            class="font-medium text-primary hover:underline"
                            @click="clearLogFilters"
                        >
                            Limpiar filtros
                        </button>
                    </div>
                </div>

                <div
                    class="border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="mb-3 flex items-center gap-2.5">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                        >
                            <Lucide icon="ListFilter" class="h-4 w-4" />
                        </div>
                        <div>
                            <div class="text-sm font-medium">
                                Busca en la bitácora
                            </div>
                            <div class="text-xs text-slate-500">
                                Por omisión son las limpiezas de hoy; abre el
                                rango para revisar la semana, el mes o todo.
                            </div>
                        </div>
                    </div>
                    <div
                        class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6"
                    >
                        <div>
                            <FormLabel htmlFor="log-room">Habitación</FormLabel>
                            <div class="relative">
                                <Lucide
                                    icon="Search"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                />
                                <FormInput
                                    id="log-room"
                                    v-model="log.hab"
                                    type="search"
                                    class="h-9 pl-9 text-xs"
                                    placeholder="Número"
                                />
                            </div>
                        </div>
                        <div>
                            <FormLabel htmlFor="log-range">Rango</FormLabel>
                            <FormSelect
                                id="log-range"
                                v-model="log.rango"
                                class="h-9 text-xs"
                            >
                                <option value="hoy">Hoy</option>
                                <option value="semana">Esta semana</option>
                                <option value="mes">Este mes</option>
                                <option value="todo">Todo el historial</option>
                            </FormSelect>
                        </div>
                        <div>
                            <FormLabel htmlFor="log-housekeeper">
                                Camarista
                            </FormLabel>
                            <FormSelect
                                id="log-housekeeper"
                                v-model="log.camarista"
                                class="h-9 text-xs"
                            >
                                <option value="">Todas</option>
                                <option
                                    v-for="h in housekeepers"
                                    :key="h.id"
                                    :value="h.id"
                                >
                                    {{ h.name }}
                                </option>
                            </FormSelect>
                        </div>
                        <div>
                            <FormLabel htmlFor="log-kind">Tipo</FormLabel>
                            <FormSelect
                                id="log-kind"
                                v-model="log.tipo"
                                class="h-9 text-xs"
                            >
                                <option value="">Todos los tipos</option>
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
                            <FormLabel htmlFor="log-state">Situación</FormLabel>
                            <FormSelect
                                id="log-state"
                                v-model="log.situacion"
                                class="h-9 text-xs"
                            >
                                <option value="">Abiertas y cerradas</option>
                                <option value="abiertas">En curso</option>
                                <option value="cerradas">Terminadas</option>
                            </FormSelect>
                        </div>
                        <div>
                            <FormLabel htmlFor="log-order">Orden</FormLabel>
                            <FormSelect
                                id="log-order"
                                v-model="log.orden"
                                class="h-9 text-xs"
                            >
                                <option value="reciente">Más reciente</option>
                                <option value="duracion">
                                    La que más tardó
                                </option>
                            </FormSelect>
                        </div>
                    </div>
                </div>

                <div v-if="cleanings.data.length" class="overflow-auto">
                    <Table sm hover class="text-xs">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th class="whitespace-nowrap">
                                    Habitación
                                </Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Camarista
                                </Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Tipo
                                </Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Horario
                                </Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Origen
                                </Table.Th>
                                <Table.Th class="text-right whitespace-nowrap">
                                    Duración
                                </Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr
                                v-for="cleaning in cleanings.data"
                                :key="cleaning.id"
                            >
                                <Table.Td>
                                    <div class="flex items-center gap-2.5">
                                        <div
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                            :class="
                                                cleaning.open
                                                    ? 'bg-warning/10 text-warning'
                                                    : 'bg-success/10 text-success'
                                            "
                                        >
                                            <Lucide
                                                :icon="
                                                    cleaning.open
                                                        ? 'Sparkles'
                                                        : 'CircleCheck'
                                                "
                                                class="h-4 w-4"
                                            />
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-sm font-medium">
                                                {{
                                                    cleaning.room ??
                                                    'Sin habitación'
                                                }}
                                            </div>
                                            <div class="text-slate-500">
                                                {{ cleaning.started_day }}
                                            </div>
                                        </div>
                                    </div>
                                </Table.Td>
                                <Table.Td>
                                    {{
                                        cleaning.housekeeper ?? 'Sin camarista'
                                    }}
                                </Table.Td>
                                <Table.Td class="whitespace-nowrap">
                                    <span
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                                    >
                                        {{ cleaning.kind_label }}
                                    </span>
                                </Table.Td>
                                <Table.Td class="whitespace-nowrap">
                                    {{ cleaning.started_label }}
                                    <template v-if="cleaning.ended_label">
                                        – {{ cleaning.ended_label }}
                                    </template>
                                    <span v-else class="text-slate-400">
                                        en curso
                                    </span>
                                </Table.Td>
                                <Table.Td
                                    class="whitespace-nowrap text-slate-500"
                                >
                                    {{
                                        sourceLabels[cleaning.source] ??
                                        cleaning.source
                                    }}
                                </Table.Td>
                                <Table.Td class="text-right whitespace-nowrap">
                                    <span class="font-medium">
                                        {{ cleaning.minutes }} min
                                    </span>
                                    <span
                                        v-if="cleaning.open"
                                        class="ml-1.5 rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                                    >
                                        Abierta
                                    </span>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                    >
                        <Lucide
                            :icon="logFiltersActive ? 'SearchX' : 'NotebookPen'"
                            class="h-7 w-7"
                        />
                    </div>
                    <div>
                        <p class="text-sm font-medium">
                            Sin limpiezas {{ rangeLabels[log.rango] }}
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cada limpieza que se inicia o se captura a mano
                            aparece aquí con su tiempo real.
                        </p>
                    </div>
                    <Button
                        v-if="logFiltersActive"
                        variant="outline-secondary"
                        @click="clearLogFilters"
                    >
                        <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                        Limpiar filtros
                    </Button>
                </div>

                <div
                    v-if="cleanings.links.length > 3"
                    class="flex flex-wrap justify-center gap-1 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <template v-for="(link, i) in cleanings.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-state
                            preserve-scroll
                            class="rounded-md px-2.5 py-1 text-xs"
                            :class="
                                link.active
                                    ? 'bg-primary text-white'
                                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-darkmode-400'
                            "
                        >
                            <span v-html="link.label" />
                        </Link>
                        <span
                            v-else
                            class="px-2.5 py-1 text-xs text-slate-400"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>

        <!-- Iniciar limpieza -->
        <Dialog :open="startModal" @close="startModal = false">
            <Dialog.Panel>
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                    >
                        <Lucide icon="Sparkles" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-medium">
                            Iniciar limpieza · {{ startRoom?.number }}
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            El cronómetro arranca al guardar.
                        </p>
                    </div>
                </div>
                <div class="space-y-4 px-5 py-4">
                    <div>
                        <FormLabel htmlFor="start-housekeeper">
                            Camarista
                        </FormLabel>
                        <FormSelect
                            id="start-housekeeper"
                            v-model="startForm.housekeeper_id"
                            class="h-9 text-xs"
                        >
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
                        <FormLabel htmlFor="start-kind">
                            Tipo de limpieza
                        </FormLabel>
                        <FormSelect
                            id="start-kind"
                            v-model="startForm.kind"
                            class="h-9 text-xs"
                        >
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
                <div
                    class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <Button
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-4 text-xs"
                        @click="startModal = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] px-4 text-xs"
                        :disabled="busy"
                        @click="startCleaning"
                    >
                        <Lucide icon="Play" class="mr-1.5 h-3.5 w-3.5" />
                        Iniciar
                    </Button>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Terminar limpieza -->
        <Dialog :open="closeModal" size="lg" @close="closeModal = false">
            <Dialog.Panel>
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="CircleCheck" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-medium">
                            Terminar limpieza · {{ closeRoom?.number }}
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Al guardar, la habitación queda disponible.
                        </p>
                    </div>
                </div>
                <div class="max-h-[65vh] space-y-4 overflow-y-auto px-5 py-4">
                    <div>
                        <div class="mb-2 text-sm font-medium">
                            ¿Qué se hizo?
                        </div>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <button
                                v-for="task in checklist"
                                :key="task.key"
                                type="button"
                                class="flex items-center gap-2 rounded-lg border p-2.5 text-left text-xs transition"
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
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <div v-for="item in linens" :key="item.key">
                                <FormLabel :htmlFor="`linen-${item.key}`">
                                    {{ item.label }}
                                </FormLabel>
                                <FormInput
                                    :id="`linen-${item.key}`"
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

                    <div>
                        <FormLabel htmlFor="close-incident">
                            ¿Encontró algo roto o faltante?
                        </FormLabel>
                        <FormInput
                            id="close-incident"
                            v-model="closeForm.incident_title"
                            type="text"
                            class="h-9 text-xs"
                            placeholder="Opcional: regadera goteando, control sin pilas..."
                        />
                        <FormHelp>
                            Si escribes algo, se levanta una incidencia de
                            mantenimiento para esta habitación.
                        </FormHelp>
                    </div>

                    <div>
                        <FormLabel htmlFor="close-notes">Notas</FormLabel>
                        <FormTextarea
                            id="close-notes"
                            v-model="closeForm.notes"
                            rows="2"
                            placeholder="Opcional"
                        />
                    </div>
                </div>
                <div
                    class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <Button
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-4 text-xs"
                        @click="closeModal = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] px-4 text-xs"
                        :disabled="busy"
                        @click="finishCleaning"
                    >
                        <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                        Terminar y liberar
                    </Button>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Captura manual -->
        <Dialog :open="manualModal" size="lg" @close="manualModal = false">
            <Dialog.Panel>
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Brush" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-medium">
                            Registrar limpieza
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Para asentar lo que ya pasó. No cambia el estado de
                            la habitación.
                        </p>
                    </div>
                </div>
                <div class="space-y-4 px-5 py-4">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <FormLabel htmlFor="manual-room">
                                Habitación
                            </FormLabel>
                            <FormSelect
                                id="manual-room"
                                v-model="manualForm.room_id"
                                class="h-9 text-xs"
                            >
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
                            <FormLabel htmlFor="manual-housekeeper">
                                Camarista
                            </FormLabel>
                            <FormSelect
                                id="manual-housekeeper"
                                v-model="manualForm.housekeeper_id"
                                class="h-9 text-xs"
                            >
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
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <FormLabel htmlFor="manual-start">Entró</FormLabel>
                            <FormDateTime
                                id="manual-start"
                                v-model="manualForm.started_at"
                            />
                        </div>
                        <div>
                            <FormLabel htmlFor="manual-end">Salió</FormLabel>
                            <FormDateTime
                                id="manual-end"
                                v-model="manualForm.ended_at"
                            />
                        </div>
                    </div>
                    <div>
                        <FormLabel htmlFor="manual-kind">
                            Tipo de limpieza
                        </FormLabel>
                        <FormSelect
                            id="manual-kind"
                            v-model="manualForm.kind"
                            class="h-9 text-xs"
                        >
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
                        <FormLabel htmlFor="manual-notes">Notas</FormLabel>
                        <FormTextarea
                            id="manual-notes"
                            v-model="manualForm.notes"
                            rows="2"
                            placeholder="Opcional"
                        />
                    </div>
                </div>
                <div
                    class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <Button
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-4 text-xs"
                        @click="manualModal = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] px-4 text-xs"
                        :disabled="busy"
                        @click="saveManual"
                    >
                        <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                        Guardar
                    </Button>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
