<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import type { Node, NodeDragEvent } from '@vue-flow/core';
import { VueFlow } from '@vue-flow/core';
import { MiniMap } from '@vue-flow/minimap';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import Button from '@/components/Base/Button';
import { Slideover } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/minimap/dist/style.css';

interface RatePlanSummary {
    id: number;
    name: string;
    type: string;
    price: number;
    duration_minutes: number | null;
    duration_label: string;
}

interface ActiveStaySummary {
    id: number;
    guest_name: string;
    rate_plan: string | null;
    channel: string;
    amount: number;
    consumos_total: number;
    total_due: number;
    check_in_at: string | null;
    check_in_at_iso: string | null;
    planned_end_at: string | null;
    planned_end_at_iso: string | null;
    is_overdue: boolean;
    reservation_id: number | null;
    num_people: number;
    vehicle_plate: string | null;
    vehicle_desc: string | null;
}

interface UpcomingReservationSummary {
    id: number;
    code: string;
    guest_name: string;
    rate_plan: string | null;
    status: string;
    status_label: string;
    total_amount: number;
    starts_at: string;
    starts_at_iso: string;
    starts_today: boolean;
    ends_at: string;
    ends_at_iso: string;
    eta: string | null;
    vehicle_plate: string | null;
    adults: number;
    children: number;
}

interface HistoryEntry {
    id: number;
    from_status: string | null;
    from_label: string | null;
    to_status: string;
    to_label: string;
    changed_by: string | null;
    created_at: string | null;
    auto: boolean;
}

interface RoomData {
    id: number;
    number: string;
    name: string | null;
    description: string | null;
    zone: string | null;
    zone_id: number | null;
    zone_color: string | null;
    room_type: string | null;
    capacity: number | null;
    amenities: string[];
    beds_label: string | null;
    size_m2: number | null;
    view: string | null;
    smoking: boolean;
    accessible: boolean;
    price_modifier: number | null;
    included_occupancy: number | null;
    extra_guest_fee: number | null;
    optional_charges: { concept: string; amount: number }[];
    check_in_time: string | null;
    check_out_time: string | null;
    maintenance_notes: string | null;
    price_from: number | null;
    status: string;
    color: string;
    label: string;
    transitions: string[];
    pos_x: number;
    pos_y: number;
    width: number;
    height: number;
    notes: string | null;
    rate_plans: RatePlanSummary[];
    active_stay: ActiveStaySummary | null;
    upcoming_reservation: UpcomingReservationSummary | null;
    today_history: HistoryEntry[];
}

interface RoomStatusChangedPayload {
    id: number;
    number: string;
    property_id: number;
    status: string;
    color: string;
    label: string;
    transitions: string[];
    changed_by: number | null;
    changed_at: string;
}

const props = defineProps<{
    tenantId: string;
    property: { id: number; name: string };
    properties: { id: number; name: string }[];
    rooms: RoomData[];
    canManage: boolean;
    canManageReservations: boolean;
    canManageOrders: boolean;
}>();

const statusStyles: Record<
    string,
    { bg: string; ring: string; dot: string; soft: string }
> = {
    green: {
        bg: 'bg-success',
        ring: 'ring-success/40',
        dot: 'bg-success',
        soft: 'bg-success/10 text-success',
    },
    cyan: {
        bg: 'bg-info',
        ring: 'ring-info/40',
        dot: 'bg-info',
        soft: 'bg-info/10 text-info',
    },
    red: {
        bg: 'bg-primary',
        ring: 'ring-primary/40',
        dot: 'bg-primary',
        soft: 'bg-primary/10 text-primary dark:bg-primary/20 dark:text-slate-200',
    },
    orange: {
        bg: 'bg-pending',
        ring: 'ring-pending/40',
        dot: 'bg-pending',
        soft: 'bg-pending/10 text-pending',
    },
    blue: {
        bg: 'bg-warning',
        ring: 'ring-warning/40',
        dot: 'bg-warning',
        soft: 'bg-warning/10 text-warning',
    },
    gray: {
        bg: 'bg-dark',
        ring: 'ring-dark/40',
        dot: 'bg-dark',
        soft: 'bg-dark/10 text-dark dark:bg-darkmode-400 dark:text-slate-300',
    },
};

const statusLabels: Record<string, { label: string; color: string }> = {
    available: { label: 'Disponible', color: 'green' },
    reserved: { label: 'Reservada', color: 'cyan' },
    occupied: { label: 'Ocupada', color: 'red' },
    dirty: { label: 'Sucia', color: 'orange' },
    cleaning: { label: 'En limpieza', color: 'blue' },
    maintenance: { label: 'Mantenimiento', color: 'gray' },
};

type TransitionVariant =
    | 'success'
    | 'outline-primary'
    | 'outline-danger'
    | 'outline-warning'
    | 'outline-secondary';

const transitionMeta: Record<
    string,
    { icon: Icon; label: string; variant: TransitionVariant }
> = {
    available: {
        icon: 'CircleCheck',
        label: 'Marcar disponible',
        variant: 'success',
    },
    reserved: {
        icon: 'CalendarClock',
        label: 'Marcar reservada',
        variant: 'outline-primary',
    },
    occupied: {
        icon: 'DoorOpen',
        label: 'Marcar ocupada',
        variant: 'outline-danger',
    },
    dirty: {
        icon: 'Paintbrush',
        label: 'Marcar sucia',
        variant: 'outline-warning',
    },
    cleaning: {
        icon: 'Sparkles',
        label: 'Iniciar limpieza',
        variant: 'outline-primary',
    },
    maintenance: {
        icon: 'Wrench',
        label: 'A mantenimiento',
        variant: 'outline-secondary',
    },
};

const currencyFormatter = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    maximumFractionDigits: 2,
});

// Candado de edición (spec-plan-maestro E5): por defecto NADIE mueve
// cuartos; "Editar plano" habilita el drag explícitamente y evita el plano
// desacomodado por accidente.
const editMode = ref(false);

function buildNodes(rooms: RoomData[]): Node[] {
    return rooms.map((room) => ({
        id: String(room.id),
        type: 'room',
        position: { x: room.pos_x, y: room.pos_y },
        draggable: props.canManage && editMode.value,
        data: room,
    }));
}

const toast = useToasts();

const nodes = ref(buildNodes(props.rooms)) as Ref<Node[]>;
const selectedId = ref<number | null>(null);
const saving = ref(false);
const busyAction = ref<string | null>(null);
const errorMessage = ref<string | null>(null);
const lastEvent = ref<string | null>(null);
const nowMs = ref(Date.now());

let clockTimer: number | null = null;
let lastEventTimer: number | null = null;

watch(
    () => props.rooms,
    (rooms) => {
        nodes.value = buildNodes(rooms);
    },
    { deep: true },
);

watch(editMode, (enabled) => {
    nodes.value.forEach((node) => {
        node.draggable = props.canManage && enabled;
    });
});

const selectedRoom = computed<RoomData | null>(() => {
    const node = nodes.value.find(
        (item) => (item.data as RoomData).id === selectedId.value,
    );
    return (node?.data as RoomData) ?? null;
});

const fichaItems = computed<{ icon: Icon; label: string; text: string }[]>(
    () => {
        const room = selectedRoom.value;

        if (!room) {
            return [];
        }

        const items: { icon: Icon; label: string; text: string }[] = [];

        if (room.beds_label) {
            items.push({
                icon: 'BedDouble',
                label: 'Camas',
                text: room.beds_label,
            });
        }

        if (room.capacity) {
            items.push({
                icon: 'Users',
                label: 'Capacidad',
                text: `Hasta ${room.capacity} personas`,
            });
        }

        if (room.included_occupancy && room.extra_guest_fee) {
            items.push({
                icon: 'UserPlus',
                label: 'Persona extra',
                text: `${formatMoney(room.extra_guest_fee)} c/u después de ${room.included_occupancy}`,
            });
        }

        if (room.size_m2) {
            items.push({
                icon: 'Ruler',
                label: 'Superficie',
                text: `${room.size_m2} m²`,
            });
        }

        if (room.view) {
            items.push({ icon: 'Eye', label: 'Vista', text: room.view });
        }

        items.push(
            room.smoking
                ? {
                      icon: 'Cigarette',
                      label: 'Fumar',
                      text: 'Permitido',
                  }
                : {
                      icon: 'CigaretteOff',
                      label: 'Fumar',
                      text: 'No permitido',
                  },
        );

        if (room.accessible) {
            items.push({
                icon: 'Accessibility',
                label: 'Accesibilidad',
                text: 'Accesible',
            });
        }

        if (room.check_in_time || room.check_out_time) {
            const times = [
                room.check_in_time ? `Check-in ${room.check_in_time}` : null,
                room.check_out_time ? `Check-out ${room.check_out_time}` : null,
            ].filter((part): part is string => part !== null);

            items.push({
                icon: 'Clock',
                label: 'Horarios',
                text: times.join(' · '),
            });
        }

        return items;
    },
);

// Refresco de respaldo (spec-plan-maestro E5): Echo empuja los cambios de
// ESTADO, pero estancias, reservas próximas y consumos solo viajan con el
// prop `rooms` — se refrescan cada minuto y al volver el foco a la pestaña
// (también cubre el caso de websocket caído). Nunca mientras se edita el
// plano o corre una acción, para no pisar al usuario.
let refreshTimer: number | null = null;

function refreshIfIdle() {
    if (document.hidden || editMode.value || saving.value || busyAction.value) {
        return;
    }

    reloadRooms();
}

function onVisibilityChange() {
    if (!document.hidden) {
        refreshIfIdle();
    }
}

onMounted(() => {
    clockTimer = window.setInterval(() => {
        nowMs.value = Date.now();
    }, 30000);

    refreshTimer = window.setInterval(refreshIfIdle, 60000);
    document.addEventListener('visibilitychange', onVisibilityChange);
});

onBeforeUnmount(() => {
    if (clockTimer) {
        window.clearInterval(clockTimer);
    }
    if (lastEventTimer) {
        window.clearTimeout(lastEventTimer);
    }
    if (refreshTimer) {
        window.clearInterval(refreshTimer);
    }
    document.removeEventListener('visibilitychange', onVisibilityChange);
});

function formatMoney(value: number | string | null | undefined): string {
    return currencyFormatter.format(Number(value ?? 0));
}

function priceModifierLabel(value: number): string {
    const sign = value < 0 ? '-' : '+';
    return `${sign}${formatMoney(Math.abs(value))}`;
}

function guestCountLabel(adults: number, children: number): string {
    const parts = [`${adults} ${adults === 1 ? 'adulto' : 'adultos'}`];

    if (children > 0) {
        parts.push(`${children} ${children === 1 ? 'niño' : 'niños'}`);
    }

    return parts.join(' · ');
}

function formatChannel(channel: string | null | undefined): string {
    if (!channel) {
        return 'Mostrador';
    }

    return channel
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function countdownLabel(iso: string | null | undefined): string {
    if (!iso) {
        return 'Sin hora';
    }

    const diff = new Date(iso).getTime() - nowMs.value;
    const totalMinutes = Math.round(Math.abs(diff) / 60000);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    const formatted =
        hours > 0 ? `${hours} h ${minutes} min` : `${minutes} min`;

    return diff >= 0 ? `Quedan ${formatted}` : `Excedida por ${formatted}`;
}

function stayTone(iso: string | null | undefined): string {
    if (!iso) {
        return 'text-slate-500 dark:text-slate-400';
    }

    return new Date(iso).getTime() >= nowMs.value
        ? 'text-success'
        : 'text-danger';
}

// "Sale hoy": la estancia activa termina hoy (si ya se excedió, el nodo ya
// trae su propio badge "Excedida" y este no se muestra).
function endsToday(room: RoomData): boolean {
    const iso = room.active_stay?.planned_end_at_iso;

    if (!iso || room.active_stay?.is_overdue) {
        return false;
    }

    const end = new Date(iso);
    const now = new Date(nowMs.value);

    return (
        end.getFullYear() === now.getFullYear() &&
        end.getMonth() === now.getMonth() &&
        end.getDate() === now.getDate()
    );
}

function nodeHint(room: RoomData): string {
    if (room.status === 'occupied' && room.active_stay?.planned_end_at_iso) {
        return countdownLabel(room.active_stay.planned_end_at_iso);
    }

    if (room.status === 'reserved' && room.upcoming_reservation) {
        return room.upcoming_reservation.starts_at.slice(-5);
    }

    if (room.rate_plans.length > 0) {
        return `Desde ${formatMoney(room.rate_plans[0].price)}`;
    }

    return room.label;
}

function patchNode(id: number, data: Partial<RoomData>) {
    const node = nodes.value.find((item) => (item.data as RoomData).id === id);
    if (node) {
        node.data = { ...(node.data as RoomData), ...data };
    }
}

function reloadRooms() {
    // router.reload ya preserva el estado del componente por definición.
    router.reload({ only: ['rooms'] });
}

async function onNodeDragStop(event: NodeDragEvent) {
    if (!editMode.value) {
        return;
    }

    const room = event.node.data as RoomData;
    const pos_x = Math.round(event.node.position.x);
    const pos_y = Math.round(event.node.position.y);
    patchNode(room.id, { pos_x, pos_y });

    try {
        await axios.patch(`/api/rooms/${room.id}`, { pos_x, pos_y });
    } catch {
        errorMessage.value = `No se pudo guardar la posición de la habitación ${room.number}.`;
    }
}

async function changeStatus(room: RoomData, status: string) {
    saving.value = true;
    errorMessage.value = null;

    try {
        await axios.patch(`/api/rooms/${room.id}/status`, { status });
        toast.success(
            'Estado actualizado',
            `Habitación ${room.number}: «${statusLabels[status]?.label ?? status}»`,
        );
        reloadRooms();
    } catch (error: any) {
        const serverMessage =
            error.response?.data?.message ?? 'No se pudo cambiar el estado.';
        errorMessage.value = serverMessage;
        toast.error('No se pudo cambiar el estado', serverMessage);
    } finally {
        saving.value = false;
    }
}

async function runRoomAction(
    key: string,
    callback: () => Promise<void>,
    feedback: {
        successTitle: string;
        successMessage: string;
        errorTitle: string;
    },
) {
    busyAction.value = key;
    errorMessage.value = null;

    try {
        await callback();
        toast.success(feedback.successTitle, feedback.successMessage);
        reloadRooms();
    } catch (error: any) {
        const serverMessage =
            error.response?.data?.message ?? 'No se pudo completar la acción.';
        errorMessage.value = serverMessage;
        toast.error(feedback.errorTitle, serverMessage);
    } finally {
        busyAction.value = null;
    }
}

function checkInReservation(room: RoomData) {
    if (!room.upcoming_reservation) {
        return;
    }

    return runRoomAction(
        `reservation:${room.upcoming_reservation.id}`,
        async () => {
            await axios.patch(
                `/api/reservations/${room.upcoming_reservation?.id}/check-in`,
            );
        },
        {
            successTitle: 'Check-in realizado',
            successMessage: `Habitación ${room.number} ocupada`,
            errorTitle: 'No se pudo hacer el check-in',
        },
    );
}

function checkOutStay(room: RoomData) {
    if (!room.active_stay) {
        return;
    }

    return runRoomAction(
        `stay:${room.active_stay.id}`,
        async () => {
            await axios.patch(`/api/stays/${room.active_stay?.id}/check-out`);
        },
        {
            successTitle: 'Check-out realizado',
            successMessage: `La habitación ${room.number} pasó a sucia; limpieza puede entrar`,
            errorTitle: 'No se pudo hacer el check-out',
        },
    );
}

function openReservations(intent: 'reserve' | 'walkin', roomId: number) {
    router.visit(route('tenant.reservations', { intent, room: roomId }));
}

function openReservationDetail(reservationId: number) {
    router.visit(route('tenant.reservations', { reservation: reservationId }));
}

function openPos(stayId: number) {
    router.visit(route('tenant.pos', { stay: stayId }));
}

function nodeTooltip(room: RoomData): string {
    const title = room.name
        ? `Hab. ${room.number} · ${room.name} · ${room.label}`
        : `Hab. ${room.number} · ${room.label}`;
    const lines = [title];

    if (room.active_stay) {
        lines.push(room.active_stay.guest_name);
        lines.push(`Salida: ${room.active_stay.planned_end_at ?? 'Sin hora'}`);
        lines.push(`Total: ${formatMoney(room.active_stay.total_due)}`);
    } else if (room.upcoming_reservation) {
        lines.push(room.upcoming_reservation.guest_name);
        lines.push(`Llega: ${room.upcoming_reservation.starts_at}`);
        lines.push(
            `Reserva: ${formatMoney(room.upcoming_reservation.total_amount)}`,
        );
    } else if (room.rate_plans[0]) {
        lines.push(`Desde ${formatMoney(room.rate_plans[0].price)}`);
    }

    return lines.join('\n');
}

useEcho<RoomStatusChangedPayload>(
    `tenant.${props.tenantId}.property.${props.property.id}.rooms`,
    '.room.status.changed',
    (payload) => {
        patchNode(payload.id, {
            status: payload.status,
            color: payload.color,
            label: payload.label,
            transitions: payload.transitions,
        });
        lastEvent.value = `Hab. ${payload.number} → ${payload.label}`;

        if (lastEventTimer) {
            window.clearTimeout(lastEventTimer);
        }

        lastEventTimer = window.setTimeout(() => {
            lastEvent.value = null;
        }, 4000);
    },
);
</script>

<template>
    <RazeLayout title="Plano">
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-medium">{{ property.name }}</h1>
                <p class="text-sm text-slate-500">
                    Plano operativo de habitaciones · tiempo real
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <span
                    v-for="(meta, status) in statusLabels"
                    :key="status"
                    class="flex items-center gap-1 text-xs text-slate-600 dark:text-slate-400"
                >
                    <span
                        class="h-2.5 w-2.5 rounded-full"
                        :class="statusStyles[meta.color].dot"
                    />
                    {{ meta.label }}
                </span>
                <Button
                    v-if="canManage"
                    :variant="editMode ? 'primary' : 'outline-secondary'"
                    class="rounded-[0.5rem]"
                    :title="
                        editMode
                            ? 'Al terminar, bloquea para que nadie mueva cuartos por accidente'
                            : 'Habilita mover los cuartos para acomodar el plano'
                    "
                    @click="editMode = !editMode"
                >
                    <Lucide
                        :icon="editMode ? 'LockOpen' : 'Lock'"
                        class="mr-2 h-4 w-4"
                    />
                    {{ editMode ? 'Terminar edición' : 'Editar plano' }}
                </Button>
            </div>
        </div>

        <div
            v-if="editMode"
            class="mt-3 flex items-center gap-2 rounded-md border border-warning/30 bg-warning/5 px-3 py-2 text-xs text-slate-600 dark:text-slate-300"
        >
            <Lucide icon="Move" class="h-4 w-4 shrink-0 text-warning" />
            Modo edición: arrastra los cuartos para acomodarlos; la posición se
            guarda sola. El refresco automático queda pausado hasta que
            presiones "Terminar edición".
        </div>

        <div
            v-if="lastEvent"
            class="fixed top-20 right-8 z-[60] rounded-md bg-dark/90 px-3 py-2 text-sm text-white shadow-lg"
        >
            {{ lastEvent }}
        </div>

        <div
            class="box relative mt-4 overflow-hidden"
            style="height: calc(100vh - 230px)"
        >
            <VueFlow
                v-model:nodes="nodes"
                :edges="[]"
                :min-zoom="0.3"
                :max-zoom="2.5"
                fit-view-on-init
                :nodes-connectable="false"
                @node-drag-stop="onNodeDragStop"
                @node-click="selectedId = ($event.node.data as RoomData).id"
                @pane-click="selectedId = null"
            >
                <Background :gap="24" />
                <MiniMap pannable zoomable />
                <Controls :show-interactive="false" />

                <template #node-room="{ data }">
                    <div
                        class="relative flex flex-col items-center justify-center overflow-visible rounded-lg px-2 text-center text-white shadow-md ring-2 transition-colors"
                        :class="[
                            statusStyles[data.color]?.bg ?? 'bg-slate-400',
                            selectedId === data.id
                                ? `${statusStyles[data.color]?.ring} ring-4`
                                : 'ring-white/40',
                            data.active_stay?.is_overdue ? 'animate-pulse' : '',
                        ]"
                        :style="{
                            width: `${data.width}px`,
                            height: `${data.height}px`,
                        }"
                        :title="nodeTooltip(data)"
                    >
                        <span
                            v-if="data.upcoming_reservation?.starts_today"
                            class="absolute -top-2 -left-2 inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-info px-1.5 text-[10px] font-semibold text-white shadow-lg"
                            title="Llega hoy"
                        >
                            <Lucide icon="CalendarDays" class="h-3.5 w-3.5" />
                        </span>
                        <span
                            v-if="(data.active_stay?.consumos_total ?? 0) > 0"
                            class="absolute -top-2 -right-2 rounded-full bg-slate-950 px-2 py-1 text-[10px] font-semibold text-white shadow-lg"
                        >
                            {{
                                formatMoney(
                                    data.active_stay?.consumos_total,
                                ).replace('.00', '')
                            }}
                        </span>
                        <span class="text-lg leading-tight font-bold">{{
                            data.number
                        }}</span>
                        <span class="text-[10px] leading-tight opacity-90">{{
                            data.room_type
                        }}</span>
                        <span
                            class="mt-1 max-w-full truncate rounded-full bg-white/15 px-2 py-0.5 text-[9px] leading-tight font-medium"
                        >
                            {{ nodeHint(data) }}
                        </span>
                        <span
                            v-if="data.active_stay?.is_overdue"
                            class="absolute -bottom-2 rounded-full bg-danger px-2 py-1 text-[9px] font-semibold text-white shadow-lg"
                        >
                            Excedida
                        </span>
                        <span
                            v-if="endsToday(data)"
                            class="absolute -bottom-2 -left-2 inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-warning px-1.5 text-[10px] font-semibold text-white shadow-lg"
                            title="Sale hoy"
                        >
                            <Lucide icon="LogOut" class="h-3.5 w-3.5" />
                        </span>
                        <span
                            v-if="data.zone_color"
                            class="pointer-events-none absolute inset-x-0 bottom-0 h-[3px] rounded-b-lg"
                            :style="{ backgroundColor: data.zone_color }"
                        />
                    </div>
                </template>
            </VueFlow>
        </div>

        <p v-if="errorMessage" class="mt-3 text-sm text-danger">
            {{ errorMessage }}
        </p>

        <Slideover :open="selectedRoom !== null" @close="selectedId = null">
            <Slideover.Panel
                class="w-full overflow-hidden rounded-[1rem_0_0_1rem/1.25rem_0_0_1.25rem] sm:w-[720px]"
            >
                <template v-if="selectedRoom">
                    <Slideover.Title
                        class="relative border-b border-slate-200/70 px-6 py-5 text-left dark:border-darkmode-400"
                    >
                        <button
                            class="absolute top-4 right-4 flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-darkmode-400 dark:hover:text-slate-200"
                            aria-label="Cerrar"
                            @click="selectedId = null"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                        <div class="pr-10">
                            <div
                                class="flex flex-wrap items-center gap-x-3 gap-y-2 text-xl font-medium"
                            >
                                <span>
                                    Habitación {{ selectedRoom.number
                                    }}<template v-if="selectedRoom.name">
                                        · {{ selectedRoom.name }}</template
                                    >
                                </span>
                                <span
                                    class="rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="
                                        statusStyles[selectedRoom.color]?.soft
                                    "
                                >
                                    {{ selectedRoom.label }}
                                </span>
                            </div>
                            <div
                                class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-500"
                            >
                                <span>{{ selectedRoom.room_type }}</span>
                                <template v-if="selectedRoom.zone">
                                    <span
                                        class="text-slate-300 dark:text-slate-600"
                                        >·</span
                                    >
                                    <span
                                        class="inline-flex items-center gap-1.5"
                                    >
                                        <span
                                            v-if="selectedRoom.zone_color"
                                            class="h-2 w-2 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    selectedRoom.zone_color,
                                            }"
                                        />
                                        {{ selectedRoom.zone }}
                                    </span>
                                </template>
                                <span class="text-slate-300 dark:text-slate-600"
                                    >·</span
                                >
                                <span class="inline-flex items-center gap-1">
                                    <Lucide icon="Users" class="h-3.5 w-3.5" />
                                    {{ selectedRoom.capacity ?? '—' }} pax
                                </span>
                            </div>
                        </div>
                    </Slideover.Title>

                    <Slideover.Description class="space-y-5 px-6 py-5">
                        <section
                            class="rounded-2xl border border-slate-200/70 bg-slate-50/80 p-4 dark:border-darkmode-400 dark:bg-darkmode-700/50"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-3"
                            >
                                <div>
                                    <h3
                                        class="text-sm font-medium text-slate-900 dark:text-slate-100"
                                    >
                                        Resumen
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Estado actual, notas y atributos
                                        comerciales.
                                    </p>
                                </div>
                                <div
                                    v-if="selectedRoom.price_from !== null"
                                    class="rounded-xl bg-white px-3 py-2 text-right shadow-sm dark:bg-darkmode-600"
                                >
                                    <div
                                        class="text-[11px] tracking-wide text-slate-500 uppercase"
                                    >
                                        Desde
                                    </div>
                                    <div
                                        class="text-sm font-semibold text-slate-900 dark:text-slate-100"
                                    >
                                        {{
                                            formatMoney(selectedRoom.price_from)
                                        }}
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="fichaItems.length"
                                class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2"
                            >
                                <div
                                    v-for="item in fichaItems"
                                    :key="item.label"
                                    class="flex items-start gap-2.5 rounded-xl bg-white px-3 py-2.5 shadow-sm dark:bg-darkmode-600"
                                >
                                    <Lucide
                                        :icon="item.icon"
                                        class="mt-0.5 h-4 w-4 shrink-0 text-slate-400"
                                    />
                                    <div class="min-w-0">
                                        <div
                                            class="text-[11px] tracking-wide text-slate-500 uppercase"
                                        >
                                            {{ item.label }}
                                        </div>
                                        <div
                                            class="mt-0.5 text-sm font-medium text-slate-700 dark:text-slate-200"
                                        >
                                            {{ item.text }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <p
                                v-if="selectedRoom.description"
                                class="mt-3 text-xs text-slate-500"
                            >
                                {{ selectedRoom.description }}
                            </p>

                            <div
                                v-if="selectedRoom.amenities.length"
                                class="mt-4 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
                            >
                                <div
                                    class="text-[11px] tracking-wide text-slate-500 uppercase"
                                >
                                    Amenidades
                                </div>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    <span
                                        v-for="amenity in selectedRoom.amenities"
                                        :key="amenity"
                                        class="rounded-full bg-white px-3 py-1 text-xs font-medium text-slate-600 shadow-sm dark:bg-darkmode-600 dark:text-slate-200"
                                    >
                                        {{ amenity }}
                                    </span>
                                </div>
                            </div>

                            <div
                                v-if="selectedRoom.optional_charges.length"
                                class="mt-4 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
                            >
                                <div
                                    class="text-[11px] tracking-wide text-slate-500 uppercase"
                                >
                                    Cargos opcionales
                                </div>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    <span
                                        v-for="charge in selectedRoom.optional_charges"
                                        :key="charge.concept"
                                        class="rounded-full bg-white px-3 py-1 text-xs font-medium text-slate-600 shadow-sm dark:bg-darkmode-600 dark:text-slate-200"
                                    >
                                        {{ charge.concept }} ·
                                        {{ formatMoney(charge.amount) }}
                                    </span>
                                </div>
                            </div>

                            <div
                                v-if="selectedRoom.notes"
                                class="mt-4 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
                            >
                                <div
                                    class="text-[11px] tracking-wide text-slate-500 uppercase"
                                >
                                    Notas
                                </div>
                                <p
                                    class="mt-2 text-sm whitespace-pre-line text-slate-600 dark:text-slate-300"
                                >
                                    {{ selectedRoom.notes }}
                                </p>
                            </div>
                        </section>

                        <section
                            v-if="selectedRoom.active_stay"
                            class="rounded-2xl border border-primary/20 bg-primary/5 p-4 dark:border-primary/30 dark:bg-primary/10"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3
                                        class="text-sm font-medium text-slate-900 dark:text-slate-100"
                                    >
                                        Estancia activa
                                    </h3>
                                    <p class="mt-1 text-lg font-semibold">
                                        {{
                                            selectedRoom.active_stay.guest_name
                                        }}
                                    </p>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{
                                            selectedRoom.active_stay
                                                .rate_plan ?? 'Sin tarifa'
                                        }}
                                        ·
                                        {{
                                            formatChannel(
                                                selectedRoom.active_stay
                                                    .channel,
                                            )
                                        }}
                                    </p>
                                </div>
                                <div
                                    class="rounded-xl bg-white px-3 py-2 text-right shadow-sm dark:bg-darkmode-600"
                                >
                                    <div
                                        class="text-[11px] tracking-wide text-slate-500 uppercase"
                                    >
                                        Salida prevista
                                    </div>
                                    <div
                                        class="mt-1 text-sm font-semibold"
                                        :class="
                                            stayTone(
                                                selectedRoom.active_stay
                                                    .planned_end_at_iso,
                                            )
                                        "
                                    >
                                        {{
                                            countdownLabel(
                                                selectedRoom.active_stay
                                                    .planned_end_at_iso,
                                            )
                                        }}
                                    </div>
                                </div>
                            </div>

                            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <div
                                    class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80"
                                >
                                    <dt class="text-slate-500">Check-in</dt>
                                    <dd
                                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                                    >
                                        {{
                                            selectedRoom.active_stay
                                                .check_in_at ?? '—'
                                        }}
                                    </dd>
                                </div>
                                <div
                                    class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80"
                                >
                                    <dt class="text-slate-500">Fin estimado</dt>
                                    <dd
                                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                                    >
                                        {{
                                            selectedRoom.active_stay
                                                .planned_end_at ?? '—'
                                        }}
                                    </dd>
                                </div>
                                <div
                                    class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80"
                                >
                                    <dt class="text-slate-500">Hospedaje</dt>
                                    <dd
                                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                                    >
                                        {{
                                            formatMoney(
                                                selectedRoom.active_stay.amount,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div
                                    class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80"
                                >
                                    <dt class="text-slate-500">Consumos</dt>
                                    <dd
                                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                                    >
                                        {{
                                            formatMoney(
                                                selectedRoom.active_stay
                                                    .consumos_total,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div
                                    class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80"
                                >
                                    <dt class="text-slate-500">
                                        Total acumulado
                                    </dt>
                                    <dd
                                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                                    >
                                        {{
                                            formatMoney(
                                                selectedRoom.active_stay
                                                    .total_due,
                                            )
                                        }}
                                    </dd>
                                </div>
                            </dl>

                            <div
                                v-if="
                                    selectedRoom.active_stay.num_people > 0 ||
                                    selectedRoom.active_stay.vehicle_plate
                                "
                                class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600 dark:text-slate-300"
                            >
                                <span
                                    v-if="
                                        selectedRoom.active_stay.num_people > 0
                                    "
                                    class="inline-flex items-center gap-1.5"
                                >
                                    <Lucide
                                        icon="Users"
                                        class="h-4 w-4 shrink-0 text-slate-400"
                                    />
                                    {{ selectedRoom.active_stay.num_people }}
                                    {{
                                        selectedRoom.active_stay.num_people ===
                                        1
                                            ? 'persona'
                                            : 'personas'
                                    }}
                                </span>
                                <span
                                    v-if="
                                        selectedRoom.active_stay.vehicle_plate
                                    "
                                    class="inline-flex items-center gap-1.5"
                                >
                                    <Lucide
                                        icon="Car"
                                        class="h-4 w-4 shrink-0 text-slate-400"
                                    />
                                    {{ selectedRoom.active_stay.vehicle_plate
                                    }}<template
                                        v-if="
                                            selectedRoom.active_stay
                                                .vehicle_desc
                                        "
                                    >
                                        ·
                                        {{
                                            selectedRoom.active_stay
                                                .vehicle_desc
                                        }}</template
                                    >
                                </span>
                            </div>

                            <div
                                v-if="canManageReservations || canManageOrders"
                                class="mt-4 flex flex-wrap gap-2"
                            >
                                <Button
                                    v-if="canManageOrders"
                                    variant="outline-primary"
                                    @click="
                                        openPos(selectedRoom.active_stay.id)
                                    "
                                >
                                    <Lucide
                                        icon="ReceiptText"
                                        class="mr-2 h-4 w-4"
                                    />
                                    Cargar consumo
                                </Button>
                                <Button
                                    v-if="
                                        canManageReservations &&
                                        selectedRoom.active_stay.reservation_id
                                    "
                                    variant="outline-primary"
                                    @click="
                                        openReservationDetail(
                                            selectedRoom.active_stay
                                                .reservation_id,
                                        )
                                    "
                                >
                                    <Lucide
                                        icon="CalendarSearch"
                                        class="mr-2 h-4 w-4"
                                    />
                                    Ver reserva
                                </Button>
                                <Button
                                    v-if="canManageReservations"
                                    variant="primary"
                                    :disabled="
                                        busyAction ===
                                        `stay:${selectedRoom.active_stay.id}`
                                    "
                                    @click="checkOutStay(selectedRoom)"
                                >
                                    <Lucide
                                        icon="LogOut"
                                        class="mr-2 h-4 w-4"
                                    />
                                    {{
                                        busyAction ===
                                        `stay:${selectedRoom.active_stay.id}`
                                            ? 'Procesando…'
                                            : 'Check-out'
                                    }}
                                </Button>
                            </div>
                        </section>

                        <section
                            v-if="selectedRoom.upcoming_reservation"
                            class="rounded-2xl border border-info/20 bg-info/5 p-4 dark:border-info/30 dark:bg-info/10"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3
                                        class="text-sm font-medium text-slate-900 dark:text-slate-100"
                                    >
                                        Reserva próxima
                                    </h3>
                                    <p class="mt-1 text-lg font-semibold">
                                        {{
                                            selectedRoom.upcoming_reservation
                                                .guest_name
                                        }}
                                    </p>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{
                                            selectedRoom.upcoming_reservation
                                                .rate_plan ?? 'Sin tarifa'
                                        }}
                                        ·
                                        {{
                                            selectedRoom.upcoming_reservation
                                                .status_label
                                        }}
                                    </p>
                                </div>
                                <div
                                    class="rounded-xl bg-white px-3 py-2 text-right shadow-sm dark:bg-darkmode-600"
                                >
                                    <div
                                        class="text-[11px] tracking-wide text-slate-500 uppercase"
                                    >
                                        Llegada
                                    </div>
                                    <div
                                        class="mt-1 text-sm font-semibold text-info"
                                    >
                                        {{
                                            selectedRoom.upcoming_reservation
                                                .starts_at
                                        }}
                                    </div>
                                    <div
                                        v-if="
                                            selectedRoom.upcoming_reservation
                                                .starts_today
                                        "
                                        class="mt-1 text-[11px] font-medium tracking-wide text-info uppercase"
                                    >
                                        Llega hoy
                                    </div>
                                </div>
                            </div>

                            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <div
                                    class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80"
                                >
                                    <dt class="text-slate-500">Entrada</dt>
                                    <dd
                                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                                    >
                                        {{
                                            selectedRoom.upcoming_reservation
                                                .starts_at
                                        }}
                                    </dd>
                                </div>
                                <div
                                    class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80"
                                >
                                    <dt class="text-slate-500">Salida</dt>
                                    <dd
                                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                                    >
                                        {{
                                            selectedRoom.upcoming_reservation
                                                .ends_at
                                        }}
                                    </dd>
                                </div>
                                <div
                                    class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80"
                                >
                                    <dt class="text-slate-500">Folio</dt>
                                    <dd
                                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                                    >
                                        {{
                                            selectedRoom.upcoming_reservation
                                                .code
                                        }}
                                    </dd>
                                </div>
                                <div
                                    class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80"
                                >
                                    <dt class="text-slate-500">Monto</dt>
                                    <dd
                                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                                    >
                                        {{
                                            formatMoney(
                                                selectedRoom
                                                    .upcoming_reservation
                                                    .total_amount,
                                            )
                                        }}
                                    </dd>
                                </div>
                            </dl>

                            <div
                                v-if="
                                    selectedRoom.upcoming_reservation.eta ||
                                    selectedRoom.upcoming_reservation
                                        .vehicle_plate ||
                                    selectedRoom.upcoming_reservation.adults +
                                        selectedRoom.upcoming_reservation
                                            .children >
                                        0
                                "
                                class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600 dark:text-slate-300"
                            >
                                <span
                                    v-if="selectedRoom.upcoming_reservation.eta"
                                    class="inline-flex items-center gap-1.5"
                                >
                                    <Lucide
                                        icon="Clock"
                                        class="h-4 w-4 shrink-0 text-slate-400"
                                    />
                                    Llegada estimada
                                    {{ selectedRoom.upcoming_reservation.eta }}
                                </span>
                                <span
                                    v-if="
                                        selectedRoom.upcoming_reservation
                                            .vehicle_plate
                                    "
                                    class="inline-flex items-center gap-1.5"
                                >
                                    <Lucide
                                        icon="Car"
                                        class="h-4 w-4 shrink-0 text-slate-400"
                                    />
                                    {{
                                        selectedRoom.upcoming_reservation
                                            .vehicle_plate
                                    }}
                                </span>
                                <span
                                    v-if="
                                        selectedRoom.upcoming_reservation
                                            .adults +
                                            selectedRoom.upcoming_reservation
                                                .children >
                                        0
                                    "
                                    class="inline-flex items-center gap-1.5"
                                >
                                    <Lucide
                                        icon="Users"
                                        class="h-4 w-4 shrink-0 text-slate-400"
                                    />
                                    {{
                                        guestCountLabel(
                                            selectedRoom.upcoming_reservation
                                                .adults,
                                            selectedRoom.upcoming_reservation
                                                .children,
                                        )
                                    }}
                                </span>
                            </div>

                            <div
                                v-if="canManageReservations"
                                class="mt-4 flex flex-wrap gap-2"
                            >
                                <Button
                                    variant="primary"
                                    :disabled="
                                        busyAction ===
                                        `reservation:${selectedRoom.upcoming_reservation.id}`
                                    "
                                    @click="checkInReservation(selectedRoom)"
                                >
                                    <Lucide icon="LogIn" class="mr-2 h-4 w-4" />
                                    {{
                                        busyAction ===
                                        `reservation:${selectedRoom.upcoming_reservation.id}`
                                            ? 'Procesando…'
                                            : 'Check-in'
                                    }}
                                </Button>
                                <Button
                                    variant="outline-primary"
                                    @click="
                                        openReservationDetail(
                                            selectedRoom.upcoming_reservation
                                                .id,
                                        )
                                    "
                                >
                                    <Lucide
                                        icon="CalendarSearch"
                                        class="mr-2 h-4 w-4"
                                    />
                                    Ver reserva
                                </Button>
                            </div>
                        </section>

                        <section
                            v-if="selectedRoom.status === 'available'"
                            class="rounded-2xl border border-success/20 bg-success/5 p-4 dark:border-success/30 dark:bg-success/10"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3
                                        class="text-sm font-medium text-slate-900 dark:text-slate-100"
                                    >
                                        Tarifas disponibles
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Listas para un walk-in o una reserva
                                        nueva.
                                    </p>
                                </div>
                                <div
                                    class="rounded-xl bg-white px-3 py-2 text-right shadow-sm dark:bg-darkmode-600"
                                >
                                    <div
                                        class="text-[11px] tracking-wide text-slate-500 uppercase"
                                    >
                                        Desde
                                    </div>
                                    <div
                                        class="mt-1 text-sm font-semibold text-success"
                                    >
                                        {{
                                            selectedRoom.rate_plans.length
                                                ? formatMoney(
                                                      selectedRoom.rate_plans[0]
                                                          .price,
                                                  )
                                                : '—'
                                        }}
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 space-y-2">
                                <div
                                    v-for="plan in selectedRoom.rate_plans"
                                    :key="plan.id"
                                    class="flex items-center justify-between rounded-xl bg-white/80 px-3 py-2 text-sm dark:bg-darkmode-600/80"
                                >
                                    <div>
                                        <div
                                            class="font-medium text-slate-900 dark:text-slate-100"
                                        >
                                            {{ plan.name }}
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            {{ plan.duration_label }}
                                        </div>
                                    </div>
                                    <div
                                        class="font-semibold text-slate-900 dark:text-slate-100"
                                    >
                                        {{ formatMoney(plan.price) }}
                                    </div>
                                </div>
                            </div>

                            <p
                                v-if="selectedRoom.price_modifier !== null"
                                class="mt-3 flex items-center gap-1.5 text-xs"
                                :class="
                                    selectedRoom.price_modifier < 0
                                        ? 'text-success'
                                        : 'text-slate-500'
                                "
                            >
                                <Lucide
                                    icon="Tag"
                                    class="h-3.5 w-3.5 shrink-0"
                                />
                                Los precios incluyen el ajuste de esta
                                habitación ({{
                                    priceModifierLabel(
                                        selectedRoom.price_modifier,
                                    )
                                }}
                                / unidad)
                            </p>

                            <div
                                v-if="canManageReservations"
                                class="mt-4 flex flex-wrap gap-2"
                            >
                                <Button
                                    variant="outline-primary"
                                    @click="
                                        openReservations(
                                            'walkin',
                                            selectedRoom.id,
                                        )
                                    "
                                >
                                    <Lucide icon="Zap" class="mr-2 h-4 w-4" />
                                    Walk-in
                                </Button>
                                <Button
                                    variant="primary"
                                    @click="
                                        openReservations(
                                            'reserve',
                                            selectedRoom.id,
                                        )
                                    "
                                >
                                    <Lucide
                                        icon="CalendarPlus"
                                        class="mr-2 h-4 w-4"
                                    />
                                    Reservar
                                </Button>
                            </div>
                        </section>

                        <section
                            v-if="
                                selectedRoom.status === 'dirty' ||
                                selectedRoom.status === 'cleaning' ||
                                selectedRoom.status === 'maintenance'
                            "
                            class="rounded-2xl border border-slate-200/70 p-4 dark:border-darkmode-400"
                        >
                            <h3
                                class="text-sm font-medium text-slate-900 dark:text-slate-100"
                            >
                                Contexto operativo
                            </h3>
                            <p class="mt-2 text-sm text-slate-500">
                                <span v-if="selectedRoom.status === 'dirty'"
                                    >La habitación está pendiente de limpieza
                                    antes de volver a venderse.</span
                                >
                                <span
                                    v-else-if="
                                        selectedRoom.status === 'cleaning'
                                    "
                                    >El cuarto está en proceso de limpieza; al
                                    terminar, el semáforo puede volver a
                                    disponible.</span
                                >
                                <span v-else
                                    >La habitación está fuera de servicio por
                                    mantenimiento o bloqueo manual.</span
                                >
                            </p>

                            <div
                                v-if="
                                    selectedRoom.status === 'maintenance' &&
                                    selectedRoom.maintenance_notes
                                "
                                class="mt-3 flex items-start gap-2 rounded-xl border border-warning/30 bg-warning/10 p-3 text-sm text-slate-700 dark:text-slate-200"
                            >
                                <Lucide
                                    icon="Wrench"
                                    class="mt-0.5 h-4 w-4 shrink-0 text-warning"
                                />
                                <span class="whitespace-pre-line">{{
                                    selectedRoom.maintenance_notes
                                }}</span>
                            </div>
                        </section>

                        <section
                            class="rounded-2xl border border-slate-200/70 p-4 dark:border-darkmode-400"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <div>
                                    <h3
                                        class="text-sm font-medium text-slate-900 dark:text-slate-100"
                                    >
                                        Historial del día
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Mini timeline del semáforo para esta
                                        habitación.
                                    </p>
                                </div>
                                <span
                                    class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-darkmode-500 dark:text-slate-300"
                                >
                                    {{ selectedRoom.today_history.length }}
                                    eventos
                                </span>
                            </div>

                            <div
                                v-if="selectedRoom.today_history.length"
                                class="mt-4 space-y-3"
                            >
                                <div
                                    v-for="entry in selectedRoom.today_history"
                                    :key="entry.id"
                                    class="flex items-start justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-darkmode-700/50"
                                >
                                    <div>
                                        <div
                                            class="flex flex-wrap items-center gap-x-1.5 gap-y-1 font-medium text-slate-900 dark:text-slate-100"
                                        >
                                            <span>
                                                {{
                                                    entry.from_label ?? 'Inicio'
                                                }}
                                                <span class="text-slate-400"
                                                    >→</span
                                                >
                                                {{ entry.to_label }}
                                            </span>
                                            <span
                                                v-if="entry.auto"
                                                class="inline-flex items-center gap-0.5 rounded-full bg-warning/10 px-1.5 text-[10px] font-medium text-warning"
                                            >
                                                <Lucide
                                                    icon="Zap"
                                                    class="h-3 w-3"
                                                />
                                                auto
                                            </span>
                                        </div>
                                        <div
                                            class="mt-1 text-xs text-slate-500"
                                        >
                                            {{ entry.changed_by ?? 'Sistema' }}
                                        </div>
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ entry.created_at ?? '—' }}
                                    </div>
                                </div>
                            </div>
                            <p v-else class="mt-4 text-sm text-slate-500">
                                Sin cambios registrados hoy.
                            </p>
                        </section>

                        <section
                            v-if="canManage && selectedRoom.transitions.length"
                            class="rounded-2xl border border-slate-200/70 p-4 dark:border-darkmode-400"
                        >
                            <h3
                                class="text-sm font-medium text-slate-900 dark:text-slate-100"
                            >
                                Cambiar estado
                            </h3>
                            <p class="mt-1 text-sm text-slate-500">
                                Transiciones válidas desde el estado actual.
                            </p>
                            <div class="mt-4 flex flex-col gap-2">
                                <Button
                                    v-for="status in selectedRoom.transitions"
                                    :key="status"
                                    :variant="transitionMeta[status].variant"
                                    :disabled="saving"
                                    class="w-full justify-center py-2.5"
                                    @click="changeStatus(selectedRoom, status)"
                                >
                                    <Lucide
                                        :icon="transitionMeta[status].icon"
                                        class="mr-2 h-4 w-4"
                                    />
                                    {{ transitionMeta[status].label }}
                                </Button>
                            </div>
                        </section>
                    </Slideover.Description>
                    <Slideover.Footer
                        class="flex justify-end bg-slate-50/80 dark:bg-darkmode-700/50"
                    >
                        <Button
                            variant="outline-secondary"
                            @click="selectedId = null"
                            >Cerrar</Button
                        >
                    </Slideover.Footer>
                </template>
            </Slideover.Panel>
        </Slideover>
    </RazeLayout>
</template>
