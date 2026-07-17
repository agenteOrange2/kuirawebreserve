<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormSelect } from '@/components/Base/Form';
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
    (
        e: 'create',
        payload: {
            room: {
                id: number;
                number: string;
                room_type: string | null;
                rate_plan_id: number | null;
            };
            date: string;
        },
    ): void;
}>();

const DAY_MS = 86400000;

function localDateString(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

const data = ref<RackData | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const from = ref(localDateString(new Date()));
const days = ref(14);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const response = await axios.get<RackData>('/api/reservations/rack', {
            params: { from: from.value, days: days.value },
        });
        data.value = response.data;
    } catch (e: any) {
        error.value =
            e.response?.data?.message ?? 'No se pudo cargar el calendario.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function shiftDays(delta: number) {
    const next = new Date(`${from.value}T00:00:00`);
    next.setDate(next.getDate() + delta);
    from.value = localDateString(next);
    load();
}

function goToday() {
    from.value = localDateString(new Date());
    load();
}

const gridStyle = computed(() => ({
    gridTemplateColumns: `repeat(${data.value?.days.length ?? days.value}, minmax(56px, 1fr))`,
}));

const dayFormatter = new Intl.DateTimeFormat('es-MX', {
    weekday: 'short',
    day: 'numeric',
});
const dayLabel = (date: string) =>
    dayFormatter.format(new Date(`${date}T00:00:00`));

// Fines de semana sombreados: la cuadrícula se lee por semanas de un vistazo.
const isWeekend = (date: string) => {
    const dow = new Date(`${date}T00:00:00`).getDay();
    return dow === 0 || dow === 6;
};

// Fondo de celda por día: hoy > fin de semana > normal.
const dayCellBg = (day: string) =>
    day === data.value?.today
        ? 'bg-primary/5'
        : isWeekend(day)
          ? 'bg-slate-50/70 dark:bg-darkmode-700/40'
          : '';

const dayIndex = (date: string) =>
    Math.round(
        (Date.parse(`${date}T00:00:00`) -
            Date.parse(`${data.value?.from}T00:00:00`)) /
            DAY_MS,
    );

// Barras por día calendario: la salida de un huésped y la llegada del
// siguiente en el mismo día no se enciman (el fin es exclusivo).
function barsFor(room: RackRoom) {
    const total = data.value?.days.length ?? 0;

    return room.entries
        .map((entry) => {
            const startIdx = Math.max(0, dayIndex(entry.start));
            const endIdx = Math.min(
                total,
                Math.max(dayIndex(entry.end), dayIndex(entry.start) + 1),
            );
            return { entry, startIdx, endIdx };
        })
        .filter(
            (bar) =>
                bar.endIdx > 0 &&
                bar.startIdx < total &&
                bar.endIdx > bar.startIdx,
        );
}

const toneBg: Record<RackEntry['tone'], string> = {
    warning: 'bg-warning',
    info: 'bg-info',
    primary: 'bg-primary',
};

const totalRooms = computed(
    () =>
        data.value?.groups.reduce(
            (sum, group) => sum + group.rooms.length,
            0,
        ) ?? 0,
);
</script>

<template>
    <div class="box box--stacked overflow-hidden">
        <!-- Controles -->
        <div
            class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
        >
            <div class="flex items-center gap-1.5">
                <Button
                    variant="outline-secondary"
                    class="!px-2.5"
                    title="Semana anterior"
                    @click="shiftDays(-7)"
                >
                    <Lucide icon="ChevronLeft" class="h-4 w-4" />
                </Button>
                <Button variant="outline-secondary" @click="goToday"
                    >Hoy</Button
                >
                <Button
                    variant="outline-secondary"
                    class="!px-2.5"
                    title="Semana siguiente"
                    @click="shiftDays(7)"
                >
                    <Lucide icon="ChevronRight" class="h-4 w-4" />
                </Button>
            </div>
            <FormSelect v-model.number="days" class="!w-32" @change="load">
                <option :value="7">7 días</option>
                <option :value="14">14 días</option>
                <option :value="30">30 días</option>
            </FormSelect>
            <Button
                variant="outline-secondary"
                class="!px-2.5"
                title="Refrescar"
                :disabled="loading"
                @click="load"
            >
                <Lucide
                    icon="RefreshCw"
                    class="h-4 w-4"
                    :class="loading && 'animate-spin'"
                />
            </Button>
            <div
                class="ml-auto flex flex-wrap items-center gap-3 text-xs text-slate-500"
            >
                <span class="flex items-center gap-1.5"
                    ><span class="h-2.5 w-2.5 rounded-full bg-warning" />
                    Pendiente (hold)</span
                >
                <span class="flex items-center gap-1.5"
                    ><span class="h-2.5 w-2.5 rounded-full bg-info" />
                    Confirmada</span
                >
                <span class="flex items-center gap-1.5"
                    ><span class="h-2.5 w-2.5 rounded-full bg-primary" /> En
                    casa</span
                >
            </div>
        </div>

        <div v-if="error" class="px-5 py-4 text-sm text-danger">
            {{ error }}
        </div>

        <div
            v-else-if="data && !totalRooms"
            class="px-5 py-10 text-center text-sm text-slate-500"
        >
            Sin habitaciones aún: el calendario se llena solo cuando des de alta
            habitaciones.
        </div>

        <div v-else-if="data" class="overflow-x-auto">
            <div
                :style="{ minWidth: `${160 + (data.days.length ?? 0) * 56}px` }"
            >
                <!-- Encabezado de días -->
                <div
                    class="flex border-b border-slate-200/60 dark:border-darkmode-400"
                >
                    <div
                        class="sticky left-0 z-20 w-40 shrink-0 border-r border-slate-200/60 bg-white px-4 py-2.5 text-xs font-medium tracking-wide text-slate-400 uppercase dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        Habitación
                    </div>
                    <div class="grid flex-1" :style="gridStyle">
                        <div
                            v-for="day in data.days"
                            :key="day"
                            class="border-l border-slate-200/60 px-1 py-2.5 text-center text-xs capitalize first:border-l-0 dark:border-darkmode-400"
                            :class="[
                                dayCellBg(day),
                                day === data.today
                                    ? 'font-semibold text-primary'
                                    : 'text-slate-500',
                            ]"
                        >
                            {{ dayLabel(day) }}
                        </div>
                    </div>
                </div>

                <template
                    v-for="group in data.groups"
                    :key="group.type_id ?? group.type"
                >
                    <!-- Encabezado del tipo: la cuadrícula de días sigue de fondo -->
                    <div
                        class="flex border-b border-slate-200/60 dark:border-darkmode-400"
                    >
                        <div
                            class="sticky left-0 z-20 w-40 shrink-0 truncate border-r border-slate-200/60 bg-slate-50 px-4 py-1.5 text-xs font-medium text-slate-600 dark:border-darkmode-400 dark:bg-darkmode-700 dark:text-slate-300"
                            :title="group.type"
                        >
                            {{ group.type }}
                        </div>
                        <div
                            class="grid flex-1 bg-slate-50 dark:bg-darkmode-700/60"
                            :style="gridStyle"
                        >
                            <div
                                v-for="day in data.days"
                                :key="day"
                                class="border-l border-slate-200/60 first:border-l-0 dark:border-darkmode-400"
                                :class="day === data.today && 'bg-primary/5'"
                            />
                        </div>
                    </div>

                    <!-- Filas de habitaciones -->
                    <div
                        v-for="room in group.rooms"
                        :key="room.id"
                        class="flex border-b border-slate-200/60 last:border-b-0 dark:border-darkmode-400"
                    >
                        <div
                            class="sticky left-0 z-20 flex w-40 shrink-0 items-center gap-1.5 border-r border-slate-200/60 bg-white px-4 py-2 text-sm font-medium dark:border-darkmode-400 dark:bg-darkmode-600"
                        >
                            <span
                                v-if="room.zone_color"
                                class="h-2 w-2 shrink-0 rounded-full"
                                :style="{ backgroundColor: room.zone_color }"
                            />
                            {{ room.number }}
                            <span
                                v-if="room.zone"
                                class="truncate text-xs font-normal text-slate-400"
                                >{{ room.zone }}</span
                            >
                        </div>
                        <div class="relative grid flex-1" :style="gridStyle">
                            <!-- Celdas (crear reserva desde el hueco) -->
                            <button
                                v-for="(day, i) in data.days"
                                :key="day"
                                type="button"
                                class="h-11 border-l border-slate-200/60 transition-colors first:border-l-0 dark:border-darkmode-400"
                                :class="[
                                    dayCellBg(day),
                                    canManage
                                        ? 'cursor-pointer hover:bg-success/10'
                                        : 'cursor-default',
                                ]"
                                :style="{
                                    gridColumn: `${i + 1} / ${i + 2}`,
                                    gridRow: '1',
                                }"
                                :title="
                                    canManage
                                        ? `Reservar la ${room.number} llegando el ${day}`
                                        : undefined
                                "
                                @click="
                                    canManage &&
                                    emit('create', {
                                        room: {
                                            id: room.id,
                                            number: room.number,
                                            room_type: group.type,
                                            rate_plan_id: group.rate_plan_id,
                                        },
                                        date: day,
                                    })
                                "
                            />
                            <!-- Barras de reservas y estancias -->
                            <div
                                v-for="(bar, bi) in barsFor(room)"
                                :key="`${bar.entry.kind}-${bi}`"
                                class="z-10 my-1.5 flex items-center gap-1 self-stretch truncate rounded-md px-2 text-xs font-medium text-white shadow-sm"
                                :class="[
                                    toneBg[bar.entry.tone],
                                    bar.entry.reservation_id
                                        ? 'cursor-pointer hover:brightness-110'
                                        : 'cursor-default',
                                ]"
                                :style="{
                                    gridColumn: `${bar.startIdx + 1} / ${bar.endIdx + 1}`,
                                    gridRow: '1',
                                }"
                                :title="`${bar.entry.guest} · ${bar.entry.status_label} · ${bar.entry.time_range}${bar.entry.code ? ` · ${bar.entry.code}` : ''}`"
                                @click.stop="
                                    bar.entry.reservation_id &&
                                    emit(
                                        'open-reservation',
                                        bar.entry.reservation_id,
                                    )
                                "
                            >
                                <span class="truncate">{{
                                    bar.entry.guest
                                }}</span>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div
            v-else
            class="flex items-center justify-center gap-2 px-5 py-10 text-sm text-slate-500"
        >
            <Lucide icon="RefreshCw" class="h-4 w-4 animate-spin" /> Cargando
            calendario…
        </div>

        <div
            class="border-t border-slate-200/60 px-5 py-3 text-xs text-slate-400 dark:border-darkmode-400"
        >
            Haz clic en una barra para ver el detalle de la reserva; en un
            hueco, para crear una reserva con esa habitación y fecha. Los
            walk-ins en casa se muestran pero su detalle vive en la sección "En
            casa ahora" de la lista de reservas.
        </div>
    </div>
</template>
