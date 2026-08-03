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
import { FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog, Slideover } from '@/components/Base/Headless';
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

interface RoomBlockEntry {
    id: number;
    starts_at: string;
    ends_at: string;
    reason: string | null;
    active: boolean;
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
    blocks: RoomBlockEntry[];
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
    manualCheckinAllowed: boolean;
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

// Retícula del plano: misma separación que los puntitos del fondo; el drag
// se imanta a ella y el botón "Alinear" redondea a la celda más cercana.
const GRID = 24;

interface ZoneNodeData {
    name: string;
    color: string | null;
    width: number;
    height: number;
}

function buildRoomNodes(rooms: RoomData[]): Node[] {
    return rooms.map((room) => ({
        id: String(room.id),
        type: 'room',
        position: { x: room.pos_x, y: room.pos_y },
        draggable: props.canManage && editMode.value,
        data: room,
    }));
}

// Letreros de zona (piso/área): un contenedor suave detrás de cada grupo de
// habitaciones con el nombre y color de su zona. Son decorativos: no se
// arrastran, no se seleccionan y dejan pasar los clics al pane.
function buildZoneNodes(rooms: RoomData[]): Node[] {
    const groups = new Map<number, RoomData[]>();

    rooms.forEach((room) => {
        if (room.zone_id === null || !room.zone) {
            return;
        }

        groups.set(room.zone_id, [...(groups.get(room.zone_id) ?? []), room]);
    });

    const padding = 20;
    const labelSpace = 34;

    return [...groups.entries()].map(([zoneId, members]) => {
        const minX = Math.min(...members.map((r) => r.pos_x)) - padding;
        const minY =
            Math.min(...members.map((r) => r.pos_y)) - padding - labelSpace;
        const maxX =
            Math.max(...members.map((r) => r.pos_x + r.width)) + padding;
        const maxY =
            Math.max(...members.map((r) => r.pos_y + r.height)) + padding;

        return {
            id: `zone-${zoneId}`,
            type: 'zone',
            position: { x: minX, y: minY },
            draggable: false,
            selectable: false,
            focusable: false,
            zIndex: -1,
            // El wrapper del nodo no captura eventos: los clics dentro del
            // contenedor caen al pane (cierra el modal) o a la habitación.
            style: { pointerEvents: 'none' },
            data: {
                name: members[0].zone ?? '',
                color: members[0].zone_color,
                width: maxX - minX,
                height: maxY - minY,
            } satisfies ZoneNodeData,
        };
    });
}

function buildNodes(rooms: RoomData[]): Node[] {
    return [...buildZoneNodes(rooms), ...buildRoomNodes(rooms)];
}

// Tinta translúcida a partir del color hex de la zona (viene de la BD);
// gris neutro cuando la zona no tiene color.
function zoneTint(color: string | null, alpha: number): string {
    if (!color || !/^#[0-9a-f]{6}$/i.test(color)) {
        return `rgba(148, 163, 184, ${alpha})`;
    }

    const r = parseInt(color.slice(1, 3), 16);
    const g = parseInt(color.slice(3, 5), 16);
    const b = parseInt(color.slice(5, 7), 16);

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
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
        if (node.type === 'room') {
            node.draggable = props.canManage && enabled;
        }
    });
});

const selectedRoom = computed<RoomData | null>(() => {
    const node = nodes.value.find(
        (item) =>
            item.type === 'room' &&
            (item.data as RoomData).id === selectedId.value,
    );
    return (node?.data as RoomData) ?? null;
});

/* --- Buscador y filtros ------------------------------------------------
 * Con 40 cuartos el canvas se vuelve un "búscalo a ojo". El buscador mira
 * número, nombre y el huésped de la estancia o de la llegada; los filtros
 * acotan por estado y zona. Sobre el plano NO se oculta nada: se apaga lo
 * que no coincide para que el acomodo físico siga siendo legible.
 */
const search = ref('');
const statusFilter = ref('');
const zoneFilter = ref<string>('');

const zoneOptions = computed(() => {
    const seen = new Map<number, string>();
    props.rooms.forEach((room) => {
        if (room.zone_id !== null && room.zone)
            seen.set(room.zone_id, room.zone);
    });
    return [...seen.entries()].map(([id, name]) => ({ id, name }));
});

const statusOptions = computed(() => {
    const counts = new Map<string, number>();
    props.rooms.forEach((room) => {
        counts.set(room.status, (counts.get(room.status) ?? 0) + 1);
    });
    return Object.entries(statusLabels)
        .filter(([status]) => counts.has(status))
        .map(([status, meta]) => ({
            status,
            label: meta.label,
            color: meta.color,
            count: counts.get(status) ?? 0,
        }));
});

function matchesFilters(room: RoomData): boolean {
    if (statusFilter.value && room.status !== statusFilter.value) return false;
    if (zoneFilter.value && String(room.zone_id ?? '') !== zoneFilter.value)
        return false;

    const term = search.value.trim().toLowerCase();
    if (!term) return true;

    return [
        room.number,
        room.name,
        room.room_type,
        room.active_stay?.guest_name,
        room.upcoming_reservation?.guest_name,
        room.upcoming_reservation?.code,
    ].some((value) => (value ?? '').toLowerCase().includes(term));
}

const filtersActive = computed(
    () =>
        search.value.trim() !== '' ||
        statusFilter.value !== '' ||
        zoneFilter.value !== '',
);

const matchingRooms = computed(() => props.rooms.filter(matchesFilters));

function clearFilters() {
    search.value = '';
    statusFilter.value = '';
    zoneFilter.value = '';
}

/** Sobre el canvas: opaca los cuartos que no cumplen el filtro. */
function isDimmed(room: RoomData): boolean {
    return filtersActive.value && !matchesFilters(room);
}

/**
 * Texto del distintivo de mantenimiento. Un bloqueo vigente impide vender
 * hoy; uno futuro avisa con tiempo — el semáforo no distingue ninguno de
 * los dos porque los bloqueos no lo mueven.
 */
function blockBadge(room: RoomData): string | null {
    const blocks = room.blocks ?? [];
    if (!blocks.length) return null;

    const active = blocks.find((block) => block.active);
    const shown = active ?? blocks[0];
    const rango = `${shown.starts_at} al ${shown.ends_at}`;

    return active
        ? `Bloqueada del ${rango}${shown.reason ? ` · ${shown.reason}` : ''}`
        : `Mantenimiento programado del ${rango}${shown.reason ? ` · ${shown.reason}` : ''}`;
}

/** Bloqueada HOY (no se puede vender ahora) vs. programada a futuro. */
function isBlockedNow(room: RoomData): boolean {
    return (room.blocks ?? []).some((block) => block.active);
}

function blockLabel(room: RoomData): string {
    return isBlockedNow(room) ? 'Bloqueada' : 'Programada';
}

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
                text: `${formatMoney(room.extra_guest_fee)} por persona después de ${room.included_occupancy}`,
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
                room.check_in_time ? `Llegada ${room.check_in_time}` : null,
                room.check_out_time ? `Salida ${room.check_out_time}` : null,
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

interface AmenityGroup {
    title: string;
    icon: Icon;
    items: string[];
}

const amenityGroups = computed<AmenityGroup[]>(() => {
    const amenities = selectedRoom.value?.amenities ?? [];
    const groups: AmenityGroup[] = [
        { title: 'Descanso y comodidad', icon: 'BedDouble', items: [] },
        { title: 'Entretenimiento y conexión', icon: 'Tv', items: [] },
        { title: 'Servicios y acceso', icon: 'ConciergeBell', items: [] },
        { title: 'Otros detalles', icon: 'Sparkles', items: [] },
    ];

    amenities.forEach((amenity) => {
        const normalized = amenity
            .normalize('NFD')
            .replace(/\p{Diacritic}/gu, '')
            .toLowerCase();

        if (
            /(tv|television|teatro|theater|cable|wifi|internet|audio|sonido)/.test(
                normalized,
            )
        ) {
            groups[1].items.push(amenity);
        } else if (
            /(servicio|comida|bebida|cochera|garage|garaje|puerta|estacionamiento)/.test(
                normalized,
            )
        ) {
            groups[2].items.push(amenity);
        } else if (
            /(cama|bano|espejo|iluminacion|piso|acabado|minisplit|clima|aire|colchon|almohada)/.test(
                normalized,
            )
        ) {
            groups[0].items.push(amenity);
        } else {
            groups[3].items.push(amenity);
        }
    });

    return groups.filter((group) => group.items.length > 0);
});

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
    const node = nodes.value.find(
        (item) => item.type === 'room' && (item.data as RoomData).id === id,
    );
    if (node) {
        node.data = { ...(node.data as RoomData), ...data };
    }
}

// Recalcula los contenedores de zona a partir de las posiciones actuales de
// los cuartos (tras un drag o un alineado), sin tocar los nodos de cuarto.
function rebuildZoneNodes() {
    const rooms = nodes.value
        .filter((node) => node.type === 'room')
        .map((node) => ({
            ...(node.data as RoomData),
            pos_x: Math.round(node.position.x),
            pos_y: Math.round(node.position.y),
        }));

    nodes.value = [
        ...buildZoneNodes(rooms),
        ...nodes.value.filter((node) => node.type === 'room'),
    ];
}

function reloadRooms() {
    // router.reload ya preserva el estado del componente por definición.
    router.reload({ only: ['rooms'] });
}

async function onNodeDragStop(event: NodeDragEvent) {
    if (!editMode.value || event.node.type !== 'room') {
        return;
    }

    const room = event.node.data as RoomData;
    const pos_x = Math.round(event.node.position.x);
    const pos_y = Math.round(event.node.position.y);
    patchNode(room.id, { pos_x, pos_y });
    rebuildZoneNodes();

    try {
        await axios.patch(`/api/rooms/${room.id}`, { pos_x, pos_y });
    } catch {
        errorMessage.value = `No se pudo guardar la posición de la habitación ${room.number}.`;
    }
}

function onNodeClick(event: { node: Node }) {
    if (event.node.type === 'room') {
        selectedId.value = (event.node.data as RoomData).id;
    }
}

const aligning = ref(false);

// Endereza el plano sin reacomodarlo: cada cuarto se redondea a la celda de
// la retícula más cercana — quién está junto a quién no cambia.
async function alignToGrid() {
    aligning.value = true;
    errorMessage.value = null;

    const moved: {
        id: number;
        number: string;
        pos_x: number;
        pos_y: number;
    }[] = [];

    nodes.value.forEach((node) => {
        if (node.type !== 'room') {
            return;
        }

        const room = node.data as RoomData;
        const pos_x = Math.round(node.position.x / GRID) * GRID;
        const pos_y = Math.round(node.position.y / GRID) * GRID;

        if (pos_x === room.pos_x && pos_y === room.pos_y) {
            return;
        }

        node.position = { x: pos_x, y: pos_y };
        node.data = { ...room, pos_x, pos_y };
        moved.push({ id: room.id, number: room.number, pos_x, pos_y });
    });

    rebuildZoneNodes();

    if (moved.length === 0) {
        toast.success('Plano alineado', 'Todo ya estaba en la cuadrícula.');
        aligning.value = false;
        return;
    }

    const results = await Promise.allSettled(
        moved.map((room) =>
            axios.patch(`/api/rooms/${room.id}`, {
                pos_x: room.pos_x,
                pos_y: room.pos_y,
            }),
        ),
    );

    const failed = results.filter(
        (result) => result.status === 'rejected',
    ).length;

    if (failed > 0) {
        errorMessage.value = `No se pudo guardar la posición de ${failed} ${failed === 1 ? 'habitación' : 'habitaciones'}.`;
        toast.error('Alineado incompleto', errorMessage.value);
    } else {
        toast.success(
            'Plano alineado',
            `${moved.length} ${moved.length === 1 ? 'habitación ajustada' : 'habitaciones ajustadas'} a la cuadrícula.`,
        );
    }

    aligning.value = false;
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
            successTitle: 'Llegada registrada',
            successMessage: `Habitación ${room.number} ocupada`,
            errorTitle: 'No se pudo registrar la llegada',
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
            successTitle: 'Salida registrada',
            successMessage: `La habitación ${room.number} pasó a sucia; limpieza puede entrar`,
            errorTitle: 'No se pudo registrar la salida',
        },
    );
}

function openReservations(intent: 'reserve' | 'walkin', roomId: number) {
    router.visit(route('tenant.reservations', { intent, room: roomId }));
}

function openReservationDetail(reservationId: number) {
    router.visit(route('tenant.reservations', { reservation: reservationId }));
}

/* --- Extender estancia y cambio de cuarto ------------------------------
 * Las dos operan sobre un huésped que YA está adentro, sin tocar su folio.
 */
const pad = (n: number) => String(n).padStart(2, '0');
const toLocalInput = (d: Date) =>
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;

const extending = ref<RoomData | null>(null);
const extendUntil = ref('');
const extendBusy = ref(false);
const extendError = ref<string | null>(null);

function openExtend(room: RoomData) {
    extending.value = room;
    extendError.value = null;

    // Arranca en un día más que la salida actual, que es el caso típico.
    const current = room.active_stay?.planned_end_at_iso;
    const base = current ? new Date(current) : new Date();
    base.setDate(base.getDate() + 1);
    extendUntil.value = toLocalInput(base);
}

async function submitExtend() {
    const stayId = extending.value?.active_stay?.id;
    if (!stayId || extendBusy.value) return;

    extendBusy.value = true;
    extendError.value = null;
    try {
        await axios.patch(`/api/stays/${stayId}/extend`, {
            planned_end_at: extendUntil.value,
        });
        extending.value = null;
        toast.success(
            'Estancia extendida',
            'La diferencia queda en su cuenta y se cobra al registrar la salida.',
        );
        reloadRooms();
    } catch (e: any) {
        extendError.value =
            e.response?.data?.message ?? 'No se pudo extender la estancia.';
    } finally {
        extendBusy.value = false;
    }
}

const moving = ref<RoomData | null>(null);
const moveTargetId = ref<number | ''>('');
const moveRecalculate = ref(false);
const moveBusy = ref(false);
const moveError = ref<string | null>(null);

/** Cuartos libres del MISMO tipo: la tarifa pertenece a un tipo. */
const moveOptions = computed(() => {
    const origin = moving.value;
    if (!origin) return [];

    return props.rooms.filter(
        (r) =>
            r.id !== origin.id &&
            r.status === 'available' &&
            r.room_type === origin.room_type &&
            !r.blocks.some((b) => b.active),
    );
});

function openMove(room: RoomData) {
    moving.value = room;
    moveTargetId.value = '';
    moveRecalculate.value = false;
    moveError.value = null;
}

async function submitMove() {
    const stayId = moving.value?.active_stay?.id;
    if (!stayId || !moveTargetId.value || moveBusy.value) return;

    moveBusy.value = true;
    moveError.value = null;
    try {
        await axios.patch(`/api/stays/${stayId}/room`, {
            room_id: moveTargetId.value,
            recalculate: moveRecalculate.value,
        });
        moving.value = null;
        selectedId.value = null;
        toast.success(
            'Huésped movido',
            'La habitación que dejó quedó marcada como sucia.',
        );
        reloadRooms();
    } catch (e: any) {
        moveError.value =
            e.response?.data?.message ?? 'No se pudo cambiar la habitación.';
    } finally {
        moveBusy.value = false;
    }
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
                    class="hidden items-center gap-1 text-xs text-slate-600 lg:flex dark:text-slate-400"
                >
                    <span
                        class="h-2.5 w-2.5 rounded-full"
                        :class="statusStyles[meta.color].dot"
                    />
                    {{ meta.label }}
                </span>
                <Button
                    v-if="canManage && editMode"
                    variant="outline-secondary"
                    class="rounded-[0.5rem]"
                    :disabled="aligning"
                    title="Endereza los cuartos a la cuadrícula sin cambiar su acomodo"
                    @click="alignToGrid"
                >
                    <Lucide icon="Grid3x3" class="mr-2 h-4 w-4" />
                    {{ aligning ? 'Alineando…' : 'Alinear' }}
                </Button>
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
            Modo edición: arrastra los cuartos para acomodarlos — se imantan a
            la cuadrícula y la posición se guarda sola. "Alinear" endereza de un
            golpe los que quedaron chuecos. El refresco automático queda pausado
            hasta que presiones "Terminar edición".
        </div>

        <div
            v-if="lastEvent"
            class="fixed top-20 right-8 z-[60] rounded-md bg-dark/90 px-3 py-2 text-sm text-white shadow-lg"
        >
            {{ lastEvent }}
        </div>

        <!-- Buscador y filtros -->
        <div class="box box--stacked mt-4 p-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <div class="relative min-w-0 flex-1">
                    <Lucide
                        icon="Search"
                        class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                    />
                    <FormInput
                        v-model="search"
                        type="text"
                        class="pl-9"
                        placeholder="Buscar por habitación, huésped, tipo o código de reserva"
                    />
                </div>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <FormSelect
                        v-model="statusFilter"
                        class="sm:w-48"
                        aria-label="Filtrar por estado"
                    >
                        <option value="">Todos los estados</option>
                        <option
                            v-for="option in statusOptions"
                            :key="option.status"
                            :value="option.status"
                        >
                            {{ option.label }} ({{ option.count }})
                        </option>
                    </FormSelect>
                    <FormSelect
                        v-if="zoneOptions.length > 1"
                        v-model="zoneFilter"
                        class="sm:w-44"
                        aria-label="Filtrar por zona"
                    >
                        <option value="">Todas las zonas</option>
                        <option
                            v-for="zone in zoneOptions"
                            :key="zone.id"
                            :value="String(zone.id)"
                        >
                            {{ zone.name }}
                        </option>
                    </FormSelect>
                    <Button
                        v-if="filtersActive"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] whitespace-nowrap"
                        @click="clearFilters"
                    >
                        <Lucide icon="X" class="mr-2 h-4 w-4" />
                        Limpiar
                    </Button>
                </div>
            </div>
            <p v-if="filtersActive" class="mt-3 text-sm text-slate-500">
                {{ matchingRooms.length }} de {{ rooms.length }}
                {{ rooms.length === 1 ? 'habitación' : 'habitaciones' }}.
                <span class="hidden lg:inline"
                    >En el plano las demás se ven atenuadas para no perder el
                    acomodo.</span
                >
            </p>
        </div>

        <!-- Plano (canvas) — en móvil el arrastre y el zoom son hostiles, así
             que ahí se muestra la lista de abajo. -->
        <div
            class="box relative mt-4 hidden overflow-hidden lg:block"
            style="height: calc(100vh - 230px)"
        >
            <VueFlow
                v-model:nodes="nodes"
                :edges="[]"
                :min-zoom="0.3"
                :max-zoom="2.5"
                fit-view-on-init
                :nodes-connectable="false"
                :snap-to-grid="true"
                :snap-grid="[GRID, GRID]"
                @node-drag-stop="onNodeDragStop"
                @node-click="onNodeClick"
                @pane-click="selectedId = null"
            >
                <Background :gap="GRID" />
                <MiniMap pannable zoomable />
                <Controls :show-interactive="false" />

                <template #node-zone="{ data }">
                    <div
                        class="relative rounded-2xl border border-dashed"
                        :style="{
                            width: `${data.width}px`,
                            height: `${data.height}px`,
                            borderColor: zoneTint(data.color, 0.45),
                            backgroundColor: zoneTint(data.color, 0.06),
                        }"
                    >
                        <span
                            class="absolute top-2 left-3 inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600 shadow-sm dark:bg-darkmode-600 dark:text-slate-200"
                        >
                            <span
                                class="h-2 w-2 shrink-0 rounded-full"
                                :style="{
                                    backgroundColor: zoneTint(data.color, 1),
                                }"
                            />
                            {{ data.name }}
                        </span>
                    </div>
                </template>

                <template #node-room="{ data }">
                    <div
                        class="relative flex flex-col items-center justify-center overflow-visible rounded-lg px-2 text-center text-white shadow-md ring-2 transition-colors"
                        :class="[
                            statusStyles[data.color]?.bg ?? 'bg-slate-400',
                            selectedId === data.id
                                ? `${statusStyles[data.color]?.ring} ring-4`
                                : 'ring-white/40',
                            data.active_stay?.is_overdue ? 'animate-pulse' : '',
                            isDimmed(data)
                                ? 'opacity-20 grayscale'
                                : 'opacity-100',
                        ]"
                        :style="{
                            width: `${data.width}px`,
                            height: `${data.height}px`,
                        }"
                        :title="nodeTooltip(data)"
                    >
                        <span
                            v-if="blockBadge(data)"
                            class="absolute -top-2 left-1/2 inline-flex -translate-x-1/2 items-center gap-1 rounded-full bg-slate-950 px-2 py-0.5 text-[9px] font-semibold text-white shadow-lg"
                            :title="blockBadge(data) ?? ''"
                        >
                            <Lucide icon="Wrench" class="h-3 w-3" />
                            {{ blockLabel(data) }}
                        </span>
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

        <!-- Vista de lista: la única en móvil (arrastrar y hacer zoom en el
             canvas desde el teléfono es un castigo, y el mostrador y el ama
             de llaves viven ahí). Abre la misma ficha que el plano. -->
        <div class="mt-4 space-y-2.5 lg:hidden">
            <button
                v-for="room in matchingRooms"
                :key="room.id"
                type="button"
                class="box box--stacked flex w-full items-center gap-3.5 p-4 text-left transition active:scale-[0.99]"
                @click="selectedId = room.id"
            >
                <span
                    class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl text-white shadow-sm"
                    :class="statusStyles[room.color]?.bg ?? 'bg-slate-400'"
                >
                    <span class="text-base leading-none font-bold">{{
                        room.number
                    }}</span>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-1.5">
                        <span class="font-medium">{{ room.label }}</span>
                        <span
                            v-if="room.blocks.length"
                            class="inline-flex items-center gap-1 rounded-full bg-slate-950 px-2 py-0.5 text-[10px] font-medium text-white"
                        >
                            <Lucide icon="Wrench" class="h-3 w-3" />
                            {{ blockLabel(room) }}
                        </span>
                        <span
                            v-if="room.active_stay?.is_overdue"
                            class="rounded-full bg-danger/10 px-2 py-0.5 text-[10px] font-medium text-danger"
                            >Excedida</span
                        >
                        <span
                            v-else-if="room.upcoming_reservation?.starts_today"
                            class="rounded-full bg-info/10 px-2 py-0.5 text-[10px] font-medium text-info"
                            >Llega hoy</span
                        >
                        <span
                            v-else-if="endsToday(room)"
                            class="rounded-full bg-warning/10 px-2 py-0.5 text-[10px] font-medium text-warning"
                            >Sale hoy</span
                        >
                    </span>
                    <span
                        class="mt-0.5 block truncate text-sm text-slate-500 dark:text-slate-400"
                    >
                        {{ room.room_type ?? 'Sin tipo' }} ·
                        {{ nodeHint(room) }}
                    </span>
                </span>
                <Lucide
                    icon="ChevronRight"
                    class="h-5 w-5 shrink-0 text-slate-300"
                />
            </button>

            <p
                v-if="!matchingRooms.length"
                class="box box--stacked p-8 text-center text-sm text-slate-500"
            >
                Ninguna habitación coincide con la búsqueda.
            </p>
        </div>

        <p v-if="errorMessage" class="mt-3 text-sm text-danger">
            {{ errorMessage }}
        </p>

        <!-- Extender estancia -->
        <Dialog :open="extending !== null" @close="extending = null">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="text-center">
                        <div
                            class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="CalendarPlus" class="h-6 w-6" />
                        </div>
                        <h2 class="text-base font-medium">
                            Extender la estancia de la
                            {{ extending?.number }}
                        </h2>
                        <p class="mt-2 text-sm text-slate-500">
                            Ahora sale el
                            {{ extending?.active_stay?.planned_end_at }}. Lo que
                            ya pagó no se toca: la diferencia se le cobra al
                            registrar su salida.
                        </p>
                    </div>

                    <div class="mt-4">
                        <label
                            for="extend-until"
                            class="mb-1.5 block text-sm text-slate-500"
                            >Nueva salida</label
                        >
                        <FormInput
                            id="extend-until"
                            v-model="extendUntil"
                            type="datetime-local"
                        />
                    </div>

                    <p
                        v-if="extendError"
                        class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                    >
                        {{ extendError }}
                    </p>

                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            class="min-h-11"
                            @click="extending = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="primary"
                            class="min-h-11"
                            :disabled="extendBusy || !extendUntil"
                            @click="submitExtend"
                            >{{
                                extendBusy ? 'Extendiendo…' : 'Extender'
                            }}</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Cambio de habitación -->
        <Dialog :open="moving !== null" @close="moving = null">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="text-center">
                        <div
                            class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="ArrowRightLeft" class="h-6 w-6" />
                        </div>
                        <h2 class="text-base font-medium">
                            Mover al huésped de la {{ moving?.number }}
                        </h2>
                        <p class="mt-2 text-sm text-slate-500">
                            Su cuenta y sus consumos se van con él. La
                            {{ moving?.number }} queda marcada como sucia.
                        </p>
                    </div>

                    <div class="mt-4">
                        <label
                            for="move-target"
                            class="mb-1.5 block text-sm text-slate-500"
                            >Habitación destino</label
                        >
                        <FormSelect id="move-target" v-model="moveTargetId">
                            <option value="">Elige una habitación</option>
                            <option
                                v-for="r in moveOptions"
                                :key="r.id"
                                :value="r.id"
                            >
                                {{ r.number }}{{ r.name ? ` · ${r.name}` : '' }}
                            </option>
                        </FormSelect>
                        <p
                            v-if="!moveOptions.length"
                            class="mt-2 text-sm text-warning"
                        >
                            No hay habitaciones libres del mismo tipo. Para
                            moverlo a otro tipo hay que registrar su salida y
                            abrir una estancia nueva con su tarifa.
                        </p>
                    </div>

                    <label
                        v-if="moveOptions.length"
                        class="mt-4 flex cursor-pointer items-start gap-2.5 rounded-xl bg-slate-50 p-3.5 dark:bg-darkmode-700"
                    >
                        <input
                            v-model="moveRecalculate"
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 shrink-0"
                        />
                        <span class="text-sm">
                            <span class="font-medium"
                                >Recalcular el precio</span
                            >
                            <span class="mt-0.5 block text-slate-500">
                                Déjalo apagado si lo mueves por una falla o
                                cortesía: así paga lo mismo que ya le dijiste.
                            </span>
                        </span>
                    </label>

                    <p
                        v-if="moveError"
                        class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                    >
                        {{ moveError }}
                    </p>

                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            class="min-h-11"
                            @click="moving = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="primary"
                            class="min-h-11"
                            :disabled="moveBusy || !moveTargetId"
                            @click="submitMove"
                            >{{ moveBusy ? 'Moviendo…' : 'Mover' }}</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <Slideover :open="selectedRoom !== null" @close="selectedId = null">
            <Slideover.Panel
                class="w-full overflow-hidden rounded-[1rem_0_0_1rem/1.25rem_0_0_1.25rem] sm:w-[820px]"
            >
                <template v-if="selectedRoom">
                    <Slideover.Title
                        class="relative border-b border-slate-200/70 px-5 py-5 text-left sm:px-7 sm:py-6 dark:border-darkmode-400"
                    >
                        <button
                            class="absolute top-4 right-4 flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-darkmode-400 dark:hover:text-slate-100"
                            aria-label="Cerrar"
                            @click="selectedId = null"
                        >
                            <Lucide icon="X" class="h-6 w-6" />
                        </button>
                        <div class="pr-12">
                            <div
                                class="flex flex-wrap items-center gap-x-3 gap-y-2"
                            >
                                <div
                                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-primary/10 bg-primary/10 text-primary"
                                >
                                    <Lucide icon="DoorClosed" class="h-6 w-6" />
                                </div>
                                <div>
                                    <div
                                        class="text-sm font-medium text-slate-500"
                                    >
                                        Habitación
                                    </div>
                                    <h2
                                        class="text-2xl font-semibold text-slate-900 dark:text-slate-100"
                                    >
                                        {{ selectedRoom.number }}
                                    </h2>
                                </div>
                                <span
                                    class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-semibold"
                                    :class="
                                        statusStyles[selectedRoom.color]?.soft
                                    "
                                >
                                    <span
                                        class="h-2.5 w-2.5 rounded-full"
                                        :class="
                                            statusStyles[selectedRoom.color]
                                                ?.dot
                                        "
                                    />
                                    {{ selectedRoom.label }}
                                </span>
                            </div>
                            <p
                                v-if="selectedRoom.name"
                                class="mt-3 text-base font-medium text-slate-700 dark:text-slate-200"
                            >
                                {{ selectedRoom.name }}
                            </p>
                            <div
                                class="mt-3 flex flex-wrap gap-2 text-sm text-slate-600 dark:text-slate-300"
                            >
                                <span
                                    class="inline-flex min-h-9 items-center gap-2 rounded-lg bg-slate-100 px-3 dark:bg-darkmode-600"
                                >
                                    <Lucide
                                        icon="BedDouble"
                                        class="h-4 w-4 text-slate-500"
                                    />
                                    {{ selectedRoom.room_type }}
                                </span>
                                <span
                                    v-if="selectedRoom.zone"
                                    class="inline-flex min-h-9 items-center gap-2 rounded-lg bg-slate-100 px-3 dark:bg-darkmode-600"
                                >
                                    <Lucide
                                        icon="MapPin"
                                        class="h-4 w-4 text-slate-500"
                                    />
                                    <span
                                        v-if="selectedRoom.zone_color"
                                        class="h-2.5 w-2.5 shrink-0 rounded-full"
                                        :style="{
                                            backgroundColor:
                                                selectedRoom.zone_color,
                                        }"
                                    />
                                    {{ selectedRoom.zone }}
                                </span>
                                <span
                                    class="inline-flex min-h-9 items-center gap-2 rounded-lg bg-slate-100 px-3 dark:bg-darkmode-600"
                                >
                                    <Lucide
                                        icon="Users"
                                        class="h-4 w-4 text-slate-500"
                                    />
                                    Hasta
                                    {{ selectedRoom.capacity ?? '—' }}
                                    personas
                                </span>
                            </div>
                        </div>
                    </Slideover.Title>

                    <Slideover.Description
                        class="space-y-5 px-4 py-5 sm:px-7 sm:py-6"
                    >
                        <section
                            v-if="
                                selectedRoom.status === 'available' &&
                                canManageReservations
                            "
                            class="rounded-2xl border border-primary/20 bg-primary/5 p-5 dark:border-primary/30 dark:bg-primary/10"
                        >
                            <div class="flex items-start gap-3">
                                <div
                                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                                >
                                    <Lucide
                                        icon="MousePointerClick"
                                        class="h-5 w-5"
                                    />
                                </div>
                                <div>
                                    <h3
                                        class="text-base font-semibold text-slate-900 dark:text-slate-100"
                                    >
                                        ¿Qué necesitas hacer?
                                    </h3>
                                    <p
                                        class="mt-1 text-sm text-slate-600 dark:text-slate-300"
                                    >
                                        La habitación está libre. Elige una de
                                        estas dos acciones.
                                    </p>
                                </div>
                            </div>
                            <div
                                class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2"
                            >
                                <Button
                                    variant="primary"
                                    class="h-auto min-h-14 justify-start rounded-xl px-4 py-3 text-left"
                                    @click="
                                        openReservations(
                                            'walkin',
                                            selectedRoom.id,
                                        )
                                    "
                                >
                                    <Lucide
                                        icon="LogIn"
                                        class="mr-3 h-5 w-5 shrink-0"
                                    />
                                    <span>
                                        <span class="block font-semibold">
                                            Llegó sin reserva
                                        </span>
                                        <span
                                            class="mt-0.5 block text-xs font-normal opacity-80"
                                        >
                                            Registrar su entrada ahora
                                        </span>
                                    </span>
                                </Button>
                                <Button
                                    variant="outline-primary"
                                    class="h-auto min-h-14 justify-start rounded-xl bg-white px-4 py-3 text-left dark:bg-darkmode-600"
                                    @click="
                                        openReservations(
                                            'reserve',
                                            selectedRoom.id,
                                        )
                                    "
                                >
                                    <Lucide
                                        icon="CalendarPlus"
                                        class="mr-3 h-5 w-5 shrink-0"
                                    />
                                    <span>
                                        <span class="block font-semibold">
                                            Crear una reserva
                                        </span>
                                        <span
                                            class="mt-0.5 block text-xs font-normal text-slate-500"
                                        >
                                            Apartarla para otra fecha
                                        </span>
                                    </span>
                                </Button>
                            </div>
                        </section>

                        <section
                            class="rounded-2xl border border-slate-200/70 bg-slate-50/80 p-5 dark:border-darkmode-400 dark:bg-darkmode-700/50"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-3"
                            >
                                <div>
                                    <h3
                                        class="text-base font-semibold text-slate-900 dark:text-slate-100"
                                    >
                                        Información de la habitación
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Lo más importante para explicarle la
                                        habitación al huésped.
                                    </p>
                                </div>
                                <div
                                    v-if="selectedRoom.price_from !== null"
                                    class="rounded-xl bg-white px-4 py-3 text-right shadow-sm dark:bg-darkmode-600"
                                >
                                    <div
                                        class="text-xs font-medium tracking-wide text-slate-500 uppercase"
                                    >
                                        Desde
                                    </div>
                                    <div
                                        class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100"
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
                                    class="flex min-h-20 items-center gap-3 rounded-xl bg-white p-3.5 shadow-sm dark:bg-darkmode-600"
                                >
                                    <div
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                                    >
                                        <Lucide
                                            :icon="item.icon"
                                            class="h-5 w-5"
                                        />
                                    </div>
                                    <div class="min-w-0">
                                        <div
                                            class="text-xs font-medium tracking-wide text-slate-500 uppercase"
                                        >
                                            {{ item.label }}
                                        </div>
                                        <div
                                            class="mt-1 text-base leading-snug font-medium text-slate-800 dark:text-slate-100"
                                        >
                                            {{ item.text }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <p
                                v-if="selectedRoom.description"
                                class="mt-4 rounded-xl bg-white p-3.5 text-sm leading-relaxed text-slate-600 shadow-sm dark:bg-darkmode-600 dark:text-slate-300"
                            >
                                {{ selectedRoom.description }}
                            </p>

                            <div
                                v-if="selectedRoom.amenities.length"
                                class="mt-5 border-t border-slate-200/70 pt-5 dark:border-darkmode-400"
                            >
                                <div>
                                    <h4
                                        class="text-base font-semibold text-slate-900 dark:text-slate-100"
                                    >
                                        Lo que incluye
                                    </h4>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Amenidades agrupadas para encontrarlas
                                        más rápido.
                                    </p>
                                </div>
                                <div
                                    class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2"
                                >
                                    <div
                                        v-for="group in amenityGroups"
                                        :key="group.title"
                                        class="rounded-xl bg-white p-4 shadow-sm dark:bg-darkmode-600"
                                    >
                                        <div
                                            class="flex items-center gap-2.5 text-sm font-semibold text-slate-800 dark:text-slate-100"
                                        >
                                            <div
                                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                                            >
                                                <Lucide
                                                    :icon="group.icon"
                                                    class="h-5 w-5"
                                                />
                                            </div>
                                            {{ group.title }}
                                        </div>
                                        <ul class="mt-3 space-y-2.5">
                                            <li
                                                v-for="amenity in group.items"
                                                :key="amenity"
                                                class="flex items-start gap-2 text-sm leading-snug text-slate-600 dark:text-slate-300"
                                            >
                                                <Lucide
                                                    icon="CircleCheck"
                                                    class="mt-0.5 h-4 w-4 shrink-0 text-success"
                                                />
                                                <span>{{ amenity }}</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="selectedRoom.optional_charges.length"
                                class="mt-4 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
                            >
                                <div
                                    class="text-sm font-semibold text-slate-900 dark:text-slate-100"
                                >
                                    Servicios con costo adicional
                                </div>
                                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                    <div
                                        v-for="charge in selectedRoom.optional_charges"
                                        :key="charge.concept"
                                        class="flex items-center justify-between gap-3 rounded-xl bg-white px-3.5 py-3 text-sm shadow-sm dark:bg-darkmode-600"
                                    >
                                        <span class="flex items-center gap-2">
                                            <Lucide
                                                icon="CirclePlus"
                                                class="h-5 w-5 shrink-0 text-primary"
                                            />
                                            {{ charge.concept }}
                                        </span>
                                        <span class="font-semibold">
                                            {{ formatMoney(charge.amount) }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="selectedRoom.notes"
                                class="mt-4 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
                            >
                                <div
                                    class="text-sm font-semibold text-slate-900 dark:text-slate-100"
                                >
                                    Información adicional
                                </div>
                                <p
                                    class="mt-2 text-sm whitespace-pre-line text-slate-600 dark:text-slate-300"
                                >
                                    {{ selectedRoom.notes }}
                                </p>
                            </div>

                            <!-- Mantenimiento programado: el semáforo no lo
                                 refleja, así que se dice aquí explícitamente. -->
                            <div
                                v-if="selectedRoom.blocks.length"
                                class="mt-4 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
                            >
                                <div
                                    class="text-sm font-semibold text-slate-900 dark:text-slate-100"
                                >
                                    Mantenimiento programado
                                </div>
                                <div class="mt-3 space-y-2">
                                    <div
                                        v-for="block in selectedRoom.blocks"
                                        :key="block.id"
                                        class="rounded-xl px-3.5 py-3 text-sm"
                                        :class="
                                            block.active
                                                ? 'bg-danger/10 text-danger'
                                                : 'bg-white text-slate-600 shadow-sm dark:bg-darkmode-600 dark:text-slate-300'
                                        "
                                    >
                                        <div
                                            class="flex items-center gap-2 font-medium"
                                        >
                                            <Lucide
                                                icon="Wrench"
                                                class="h-4 w-4 shrink-0"
                                            />
                                            {{ block.starts_at }} al
                                            {{ block.ends_at }}
                                            <span
                                                v-if="block.active"
                                                class="rounded-full bg-danger/15 px-2 py-0.5 text-[10px]"
                                                >En curso</span
                                            >
                                        </div>
                                        <p v-if="block.reason" class="mt-1">
                                            {{ block.reason }}
                                        </p>
                                    </div>
                                </div>
                                <p class="mt-2 text-xs text-slate-500">
                                    Estas fechas no se pueden vender. El
                                    semáforo no cambia por un bloqueo.
                                </p>
                            </div>
                        </section>

                        <section
                            v-if="selectedRoom.active_stay"
                            class="rounded-2xl border border-primary/20 bg-primary/5 p-5 dark:border-primary/30 dark:bg-primary/10"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3
                                        class="text-base font-semibold text-slate-900 dark:text-slate-100"
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
                                    <dt class="text-slate-500">
                                        Entrada registrada
                                    </dt>
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
                                class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-3"
                            >
                                <Button
                                    v-if="canManageOrders"
                                    variant="outline-primary"
                                    class="min-h-11 justify-center"
                                    @click="
                                        openPos(selectedRoom.active_stay.id)
                                    "
                                >
                                    <Lucide
                                        icon="ReceiptText"
                                        class="mr-2 h-5 w-5"
                                    />
                                    Cargar consumo
                                </Button>
                                <Button
                                    v-if="
                                        canManageReservations &&
                                        selectedRoom.active_stay.reservation_id
                                    "
                                    variant="outline-primary"
                                    class="min-h-11 justify-center"
                                    @click="
                                        openReservationDetail(
                                            selectedRoom.active_stay
                                                .reservation_id,
                                        )
                                    "
                                >
                                    <Lucide
                                        icon="CalendarSearch"
                                        class="mr-2 h-5 w-5"
                                    />
                                    Ver reserva
                                </Button>
                                <!-- Antes, "una noche más" o mover de cuarto a
                                     alguien que ya está adentro obligaba a
                                     registrar su salida y darle entrada otra
                                     vez, perdiendo el folio. -->
                                <Button
                                    v-if="canManageReservations"
                                    variant="outline-primary"
                                    class="min-h-11 justify-center"
                                    @click="openExtend(selectedRoom)"
                                >
                                    <Lucide
                                        icon="CalendarPlus"
                                        class="mr-2 h-5 w-5"
                                    />
                                    Extender
                                </Button>
                                <Button
                                    v-if="canManageReservations"
                                    variant="outline-primary"
                                    class="min-h-11 justify-center"
                                    @click="openMove(selectedRoom)"
                                >
                                    <Lucide
                                        icon="ArrowRightLeft"
                                        class="mr-2 h-5 w-5"
                                    />
                                    Cambiar de cuarto
                                </Button>
                                <Button
                                    v-if="canManageReservations"
                                    variant="primary"
                                    class="min-h-11 justify-center"
                                    :disabled="
                                        busyAction ===
                                        `stay:${selectedRoom.active_stay.id}`
                                    "
                                    @click="checkOutStay(selectedRoom)"
                                >
                                    <Lucide
                                        icon="LogOut"
                                        class="mr-2 h-5 w-5"
                                    />
                                    {{
                                        busyAction ===
                                        `stay:${selectedRoom.active_stay.id}`
                                            ? 'Procesando…'
                                            : 'Registrar salida'
                                    }}
                                </Button>
                            </div>
                        </section>

                        <section
                            v-if="selectedRoom.upcoming_reservation"
                            class="rounded-2xl border border-info/20 bg-info/5 p-5 dark:border-info/30 dark:bg-info/10"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3
                                        class="text-base font-semibold text-slate-900 dark:text-slate-100"
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
                                class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2"
                            >
                                <Button
                                    v-if="manualCheckinAllowed"
                                    variant="primary"
                                    class="min-h-11 justify-center"
                                    :disabled="
                                        busyAction ===
                                        `reservation:${selectedRoom.upcoming_reservation.id}`
                                    "
                                    @click="checkInReservation(selectedRoom)"
                                >
                                    <Lucide icon="LogIn" class="mr-2 h-5 w-5" />
                                    {{
                                        busyAction ===
                                        `reservation:${selectedRoom.upcoming_reservation.id}`
                                            ? 'Procesando…'
                                            : 'Registrar llegada'
                                    }}
                                </Button>
                                <Button
                                    variant="outline-primary"
                                    class="min-h-11 justify-center"
                                    @click="
                                        openReservationDetail(
                                            selectedRoom.upcoming_reservation
                                                .id,
                                        )
                                    "
                                >
                                    <Lucide
                                        icon="CalendarSearch"
                                        class="mr-2 h-5 w-5"
                                    />
                                    Ver reserva
                                </Button>
                            </div>
                        </section>

                        <section
                            v-if="selectedRoom.status === 'available'"
                            class="rounded-2xl border border-success/20 bg-success/5 p-5 dark:border-success/30 dark:bg-success/10"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3
                                        class="text-base font-semibold text-slate-900 dark:text-slate-100"
                                    >
                                        Precios disponibles
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Elige según el tiempo que ocuparán la
                                        habitación.
                                    </p>
                                </div>
                                <div
                                    class="rounded-xl bg-white px-4 py-3 text-right shadow-sm dark:bg-darkmode-600"
                                >
                                    <div
                                        class="text-xs font-medium tracking-wide text-slate-500 uppercase"
                                    >
                                        Desde
                                    </div>
                                    <div
                                        class="mt-1 text-lg font-semibold text-success"
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
                                    class="flex items-center justify-between gap-4 rounded-xl bg-white/90 p-4 shadow-sm dark:bg-darkmode-600/80"
                                >
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                                        >
                                            <Lucide
                                                icon="Clock"
                                                class="h-5 w-5"
                                            />
                                        </div>
                                        <div>
                                            <div
                                                class="text-base font-semibold text-slate-900 dark:text-slate-100"
                                            >
                                                {{ plan.name }}
                                            </div>
                                            <div
                                                class="mt-0.5 text-sm text-slate-500"
                                            >
                                                Duración:
                                                {{ plan.duration_label }}
                                            </div>
                                        </div>
                                    </div>
                                    <div
                                        class="shrink-0 text-lg font-semibold text-slate-900 dark:text-slate-100"
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
                                por estancia)
                            </p>
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
                                        class="text-base font-semibold text-slate-900 dark:text-slate-100"
                                    >
                                        Cambios de hoy
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Movimientos registrados para esta
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
                                                Automático
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

                            <Button
                                as="a"
                                :href="
                                    route(
                                        'tenant.rooms.history',
                                        selectedRoom.id,
                                    )
                                "
                                variant="outline-secondary"
                                class="mt-4 w-full justify-center"
                            >
                                <Lucide icon="History" class="mr-2 h-5 w-5" />
                                Ver historial completo y próximas reservas
                            </Button>
                        </section>

                        <section
                            v-if="canManage && selectedRoom.transitions.length"
                            class="rounded-2xl border border-slate-200/70 p-4 dark:border-darkmode-400"
                        >
                            <h3
                                class="text-base font-semibold text-slate-900 dark:text-slate-100"
                            >
                                Limpieza y mantenimiento
                            </h3>
                            <p class="mt-1 text-sm text-slate-500">
                                Aquí solo vive la operación física del cuarto
                                (limpieza y mantenimiento). Reservada y ocupada
                                se mueven solas cuando creas una reserva o
                                registras la llegada del huésped.
                            </p>
                            <p
                                v-if="
                                    selectedRoom.status === 'reserved' &&
                                    selectedRoom.upcoming_reservation
                                "
                                class="mt-2 flex items-start gap-2 rounded-xl border border-info/30 bg-info/5 p-3 text-sm text-slate-600 dark:text-slate-300"
                            >
                                <Lucide
                                    icon="Info"
                                    class="mt-0.5 h-4 w-4 shrink-0 text-info"
                                />
                                <span>
                                    Esta habitación está apartada por la reserva
                                    {{
                                        selectedRoom.upcoming_reservation.code
                                    }}; para liberarla, cancela la reserva desde
                                    "Ver reserva".
                                </span>
                            </p>
                            <div class="mt-4 flex flex-col gap-2">
                                <Button
                                    v-for="status in selectedRoom.transitions"
                                    :key="status"
                                    :variant="transitionMeta[status].variant"
                                    :disabled="saving"
                                    class="min-h-11 w-full justify-center py-2.5"
                                    @click="changeStatus(selectedRoom, status)"
                                >
                                    <Lucide
                                        :icon="transitionMeta[status].icon"
                                        class="mr-2 h-5 w-5"
                                    />
                                    {{ transitionMeta[status].label }}
                                </Button>
                            </div>
                        </section>
                    </Slideover.Description>
                    <Slideover.Footer
                        class="flex justify-end bg-slate-50/80 px-5 py-4 sm:px-7 dark:bg-darkmode-700/50"
                    >
                        <Button
                            variant="outline-secondary"
                            class="min-h-11 min-w-28 justify-center rounded-[0.5rem] bg-white text-base dark:bg-darkmode-600"
                            @click="selectedId = null"
                            >Cerrar</Button
                        >
                    </Slideover.Footer>
                </template>
            </Slideover.Panel>
        </Slideover>
    </RazeLayout>
</template>
