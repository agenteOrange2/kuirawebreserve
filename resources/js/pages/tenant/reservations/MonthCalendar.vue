<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';

interface RackEntry {
    kind: 'reservation' | 'stay';
    reservation_id: number | null;
    code: string | null;
    guest: string;
    status: string;
    status_label: string;
    tone: 'warning' | 'info' | 'primary';
    start: string;
    end: string;
    time_range: string;
}

interface RackRoom {
    id: number;
    number: string;
    zone: string | null;
    zone_color: string | null;
    entries: RackEntry[];
}

interface RackGroup {
    type_id: number | null;
    type: string;
    rate_plan_id: number | null;
    rooms: RackRoom[];
}

interface RackData {
    from: string;
    days: string[];
    today: string;
    groups: RackGroup[];
}

defineProps<{ canManage: boolean }>();

const emit = defineEmits<{
    (e: 'open-reservation', reservationId: number): void;
    (e: 'create-date', date: string): void;
}>();

const DAY_MS = 86400000;

function localDateString(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

// Primer día del mes visible; la cuadrícula rellena con días vecinos.
const now = new Date();
const cursor = ref(new Date(now.getFullYear(), now.getMonth(), 1));

// Semana domingo–sábado (convención es-MX).
const gridStart = computed(() => {
    const start = new Date(cursor.value);
    start.setDate(start.getDate() - start.getDay());
    return start;
});

const weekCount = computed(() => {
    const daysInMonth = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + 1, 0).getDate();
    return Math.ceil((cursor.value.getDay() + daysInMonth) / 7);
});

const data = ref<RackData | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const response = await axios.get<RackData>('/api/reservations/rack', {
            params: { from: localDateString(gridStart.value), days: weekCount.value * 7 },
        });
        data.value = response.data;
    } catch (e: any) {
        error.value = e.response?.data?.message ?? 'No se pudo cargar el calendario.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function shiftMonth(delta: number) {
    cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + delta, 1);
    load();
}

function goToday() {
    const today = new Date();
    cursor.value = new Date(today.getFullYear(), today.getMonth(), 1);
    load();
}

const monthFormatter = new Intl.DateTimeFormat('es-MX', { month: 'long', year: 'numeric' });
const monthLabel = computed(() => {
    const label = monthFormatter.format(cursor.value);
    return label.charAt(0).toUpperCase() + label.slice(1);
});

interface DayEvent {
    entry: RackEntry;
    room: string;
    isStart: boolean;
}

// Cada entrada pinta un chip por día que cubre (fin exclusivo, mínimo el
// día de llegada): el día de llegada en sólido, la continuación en suave.
const eventsByDay = computed(() => {
    const map = new Map<string, DayEvent[]>();
    if (!data.value) return map;

    for (const group of data.value.groups) {
        for (const room of group.rooms) {
            for (const entry of room.entries) {
                const start = new Date(`${entry.start}T00:00:00`);
                const last = new Date(Math.max(new Date(`${entry.end}T00:00:00`).getTime() - DAY_MS, start.getTime()));
                for (let day = new Date(start); day <= last; day.setDate(day.getDate() + 1)) {
                    const key = localDateString(day);
                    if (!map.has(key)) map.set(key, []);
                    map.get(key)!.push({ entry, room: room.number, isStart: day.getTime() === start.getTime() });
                }
            }
        }
    }

    for (const list of map.values()) {
        list.sort((a, b) => a.room.localeCompare(b.room, undefined, { numeric: true }));
    }

    return map;
});

const cells = computed(() => {
    const loaded = data.value;
    if (!loaded) return [];

    return loaded.days.map((day) => {
        const date = new Date(`${day}T00:00:00`);
        return {
            day,
            num: date.getDate(),
            inMonth: date.getMonth() === cursor.value.getMonth(),
            isToday: day === loaded.today,
            isWeekend: date.getDay() === 0 || date.getDay() === 6,
            events: eventsByDay.value.get(day) ?? [],
        };
    });
});

const toneSolid: Record<RackEntry['tone'], string> = {
    warning: 'bg-warning text-white',
    info: 'bg-info text-white',
    primary: 'bg-primary text-white',
};

const toneSoft: Record<RackEntry['tone'], string> = {
    warning: 'bg-warning/10 text-warning',
    info: 'bg-info/10 text-info',
    primary: 'bg-primary/10 text-primary',
};

const weekdayLabels = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
</script>

<template>
    <div class="box box--stacked overflow-hidden">
        <!-- Controles -->
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400">
            <div class="flex items-center gap-1.5">
                <Button variant="outline-secondary" class="!px-2.5" title="Mes anterior" @click="shiftMonth(-1)">
                    <Lucide icon="ChevronLeft" class="h-4 w-4" />
                </Button>
                <Button variant="outline-secondary" @click="goToday">Hoy</Button>
                <Button variant="outline-secondary" class="!px-2.5" title="Mes siguiente" @click="shiftMonth(1)">
                    <Lucide icon="ChevronRight" class="h-4 w-4" />
                </Button>
            </div>
            <div class="text-sm font-medium">{{ monthLabel }}</div>
            <Button variant="outline-secondary" class="!px-2.5" title="Refrescar" :disabled="loading" @click="load">
                <Lucide icon="RefreshCw" class="h-4 w-4" :class="loading && 'animate-spin'" />
            </Button>
            <div class="ml-auto flex flex-wrap items-center gap-3 text-xs text-slate-500">
                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-warning" /> Pendiente (hold)</span>
                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-info" /> Confirmada</span>
                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-primary" /> En casa</span>
            </div>
        </div>

        <div v-if="error" class="px-5 py-4 text-sm text-danger">{{ error }}</div>

        <template v-else-if="data">
            <!-- Días de la semana -->
            <div class="grid grid-cols-7 border-b border-slate-200/60 dark:border-darkmode-400">
                <div
                    v-for="label in weekdayLabels"
                    :key="label"
                    class="px-2 py-2 text-center text-xs font-medium uppercase tracking-wide text-slate-400"
                >
                    {{ label }}
                </div>
            </div>

            <!-- Cuadrícula del mes -->
            <div class="grid grid-cols-7 gap-px bg-slate-200/60 dark:bg-darkmode-400">
                <div
                    v-for="cell in cells"
                    :key="cell.day"
                    class="flex min-h-[104px] flex-col gap-1 p-1.5 transition-colors"
                    :class="[
                        cell.isWeekend ? 'bg-slate-50 dark:bg-darkmode-700' : 'bg-white dark:bg-darkmode-600',
                        !cell.inMonth && 'opacity-60',
                        canManage && 'cursor-pointer hover:bg-success/10',
                    ]"
                    :title="canManage ? `Crear reserva llegando el ${cell.day}` : undefined"
                    @click="canManage && emit('create-date', cell.day)"
                >
                    <div class="flex justify-end">
                        <span
                            v-if="cell.isToday"
                            class="flex h-5 w-5 items-center justify-center rounded-full bg-primary text-xs font-medium text-white"
                        >
                            {{ cell.num }}
                        </span>
                        <span v-else class="text-xs" :class="cell.inMonth ? 'text-slate-500' : 'text-slate-400'">{{ cell.num }}</span>
                    </div>
                    <button
                        v-for="(event, index) in cell.events"
                        :key="index"
                        type="button"
                        class="flex w-full items-center gap-1 rounded px-1.5 py-0.5 text-left text-xs font-medium"
                        :class="[
                            event.isStart ? toneSolid[event.entry.tone] : toneSoft[event.entry.tone],
                            event.entry.reservation_id ? 'cursor-pointer hover:brightness-110' : 'cursor-default',
                        ]"
                        :title="`${event.room} · ${event.entry.guest} · ${event.entry.status_label} · ${event.entry.time_range}${event.entry.code ? ` · ${event.entry.code}` : ''}`"
                        @click.stop="event.entry.reservation_id && emit('open-reservation', event.entry.reservation_id)"
                    >
                        <span class="shrink-0">{{ event.room }}</span>
                        <span class="truncate font-normal">{{ event.entry.guest }}</span>
                    </button>
                </div>
            </div>
        </template>

        <div v-else class="flex items-center justify-center gap-2 px-5 py-10 text-sm text-slate-500">
            <Lucide icon="RefreshCw" class="h-4 w-4 animate-spin" /> Cargando calendario…
        </div>

        <div class="border-t border-slate-200/60 px-5 py-3 text-xs text-slate-400 dark:border-darkmode-400">
            Haz clic en una reserva para abrir su detalle; en un día vacío, para crear una reserva llegando esa fecha.
            El chip sólido marca el día de llegada; el suave, la continuación de la estancia.
        </div>
    </div>
</template>
