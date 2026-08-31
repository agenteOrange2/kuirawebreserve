<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import type { Node, NodeDragEvent } from '@vue-flow/core';
import { useVueFlow, VueFlow } from '@vue-flow/core';
import { MiniMap } from '@vue-flow/minimap';
import axios from 'axios';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    provide,
    ref,
    watch,
} from 'vue';
import type { Ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormDateTime, FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog, Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import { useCashSnapshot } from '@/composables/useCashSnapshot';
import { useModules } from '@/composables/useModules';
import { usePlanPanels } from '@/composables/usePlanPanels';
import { usePropertyMode } from '@/composables/usePropertyMode';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import { FloorPlanKey } from '@/pages/tenant/floorplan/context';
import CleaningDialogs from '@/pages/tenant/floorplan/CleaningDialogs.vue';
import ExpressCheckInModal from '@/pages/tenant/floorplan/ExpressCheckInModal.vue';
import PlanDock from '@/pages/tenant/floorplan/PlanDock.vue';
import CheckInGuaranteeDialog from '@/pages/tenant/floorplan/CheckInGuaranteeDialog.vue';
import CheckoutDialog from '@/pages/tenant/floorplan/CheckoutDialog.vue';
import CompleteArrivalDialog from '@/pages/tenant/floorplan/CompleteArrivalDialog.vue';
import PlanFiltersDialog from '@/pages/tenant/floorplan/PlanFiltersDialog.vue';
import CashDialog from '@/pages/tenant/floorplan/CashDialog.vue';
import ReserveModal from '@/pages/tenant/floorplan/ReserveModal.vue';
import WalkInModal from '@/pages/tenant/floorplan/WalkInModal.vue';
import RoomActionSheet from '@/pages/tenant/floorplan/RoomActionSheet.vue';
import NewRoomDialog from '@/pages/tenant/floorplan/room/NewRoomDialog.vue';
import RoomDialog from '@/pages/tenant/floorplan/room/RoomDialog.vue';
import {
    countdownLabel,
    formatMoney,
    usageBadgeTitle,
} from '@/pages/tenant/floorplan/format';
import {
    statusLabels,
    statusStyles,
    transitionMeta,
} from '@/pages/tenant/floorplan/status';
import type {
    ActiveStaySummary,
    ArrivalAction,
    CheckoutFolio,
    RoomData,
} from '@/pages/tenant/floorplan/types';

import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/minimap/dist/style.css';

interface RoomStatusChangedPayload {
    id: number;
    number: string;
    property_id: number;
    status: string;
    color: string;
    label: string;
    transitions: string[];
    usage_count: number;
    usage_limit: number | null;
    usage_locked: boolean;
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
    // Fianza configurada (0 = apagada): la piden los registros de llegada.
    guaranteeAmount: number;
    // walkin_charge=checkin (/ajustes/metodos-pago): el registro de llegada
    // cobra el hospedaje en el momento en vez de dejarlo al check-out.
    walkinChargeOnCheckin: boolean;
    // Fotos de identificación en la ficha (mismo permiso que el CRM).
    canViewDocuments: boolean;
    // Ver la cuenta de una estancia (folio) exige ver reservas.
    canViewStays: boolean;
    // Conceptos de daño y su precio sugerido (/ajustes/danos).
    damageCatalog: { concept: string; amount: number }[];
    // Tipos de falla para reportar incidencias (vacío sin el módulo).
    incidentCategories: { key: string; label: string }[];
    // Catálogos para el panel de habitación (vacíos sin rooms.manage).
    roomTypes: { id: number; name: string }[];
    zones: { id: number; name: string }[];
    // Registro de limpieza (módulo limpieza): con él, iniciar y terminar
    // piden camarista y checklist en vez de solo mover el color.
    housekeepingEnabled: boolean;
    housekeepers: { id: number; name: string }[];
    cleaningChecklist: { key: string; label: string }[];
    cleaningLinens: { key: string; label: string }[];
    cleaningKinds: Record<string, string>;
}>();

// Candado de edición (spec-plan-maestro E5): por defecto NADIE mueve
// cuartos; "Editar plano" habilita el drag explícitamente y evita el plano
// desacomodado por accidente.
const editMode = ref(false);

// Retícula del plano: misma separación que los puntitos del fondo; el drag
// se imanta a ella y el botón "Alinear" redondea a la celda más cercana.
const GRID = 24;

interface ZoneNodeData {
    id: string;
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
    // Franja de aire arriba para el letrero de la zona. Como el letrero
    // conserva su tamaño en pantalla (no escala con el zoom), esta franja es
    // la que decide hasta qué zoom puede hacerlo sin taparle la primera
    // habitación: alcanza para 2.5 veces su tamaño natural.
    const labelSpace = 56;

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
                id: `zone-${zoneId}`,
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
// Tablero personalizado (Empresarial): editar el acomodo del plano.
const { hasModule } = useModules();

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

// ── Modo presentación (docs/spec-plano-pantalla-completa.md) ──
// El plano ocupa toda la pantalla y TAPA el chrome del panel (menú lateral,
// top bar y la barra de filtros); nada se elimina, todo vuelve al salir.
// El overlay va en z-[55] a propósito: el menú lateral es z-50 y la ficha y
// los modales del theme son z-[60], y tienen que seguir apareciendo encima.
// Y NUNCA se pide requestFullscreen sobre el contenedor del plano: los
// paneles de HeadlessUI se portalean a <body> y quedarían fuera del elemento
// en pantalla completa (invisibles). El único fullscreen nativo seguro es el
// de document.documentElement, y es solo un extra: en iOS no existe.
const PRESENTING_KEY = 'floorplan:presenting';
const presenting = ref(false);

const { fitView, zoomIn, zoomOut, setMinZoom, viewport, dimensions } =
    useVueFlow();

// Encuadre "todo el plano con margen". Se llama al entrar y salir de
// presentación y cuando cambia el tamaño real del contenedor (rotar el
// teléfono, redimensionar la ventana): `fit-view-on-init` corre una sola vez
// y por eso el zoom se quedaba donde lo dejó el último que lo movió.
// El tope de acercamiento existe para los hoteles chicos: sin él, nueve
// cuartos en una pantalla de mostrador se ven como espectacular.
function fitPlan(duration = 220, nodes?: string[]) {
    void fitView({
        padding: presenting.value ? 0.08 : 0.14,
        maxZoom: 1.3,
        duration,
        ...(nodes?.length ? { nodes } : {}),
    });
}

// Salto por zona: con muchas habitaciones el plano completo sirve como mapa
// de estado y se opera entrando a una planta. Encuadrar el marco de la zona
// alcanza, porque ese marco es justo la caja que envuelve a sus cuartos.
const zoneJump = ref<number | null>(null);

// Modal de búsqueda, filtros y salto de zona (solo en presentación: en el
// panel esas mismas herramientas viven en la barra de arriba).
const filtersOpen = ref(false);

// Piezas del módulo Plano operativo: el contador flotante, el modal de la
// habitación y el de caja. Quién puede usar cada una vive en el composable.
const { enabled: planPanelsEnabled, canSee } = usePlanPanels();

// Dar de alta una habitación desde la barra: es donde uno lo busca. El del tab
// Cuarto sigue existiendo, pero solo se ve con el candado abierto.
const newRoomOpen = ref(false);
const canCreateRooms = computed(
    () => planPanelsEnabled.value && canSee('habitacion'),
);

// Caja del turno: el chip de la barra y el modal miran el mismo estado.
const cash = useCashSnapshot();
const cashOpen = ref(false);
const showCashChip = computed(() => planPanelsEnabled.value && canSee('caja'));

const activeZoneName = computed(
    () => zoneOptions.value.find((zone) => zone.id === zoneJump.value)?.name,
);

function jumpToZone(zoneId: number | null) {
    zoneJump.value = zoneId;
    filtersOpen.value = false;

    if (zoneId === null) {
        fitPlan(320);

        return;
    }

    // En un teléfono una planta entera es más ancha que la pantalla: si se
    // encuadra tal cual, el zoom queda igual que el del plano completo y el
    // salto no sirve de nada. Con piso de zoom se entra a un nivel legible,
    // centrado en la planta, y se recorre con el dedo.
    void fitView({
        nodes: [`zone-${zoneId}`],
        padding: 0.08,
        minZoom: 0.55,
        maxZoom: 1.3,
        duration: 320,
    });
}

// El número y la marca de atención conservan un tamaño casi constante EN
// PANTALLA: como el canvas escala todo por igual, la letra se compensa hacia
// arriba conforme te alejas (igual que las etiquetas de un mapa). Se escribe
// una variable CSS en el contenedor —una escritura por cuadro— en vez de
// volver a pintar las tarjetas en cada paso del zoom.
const planViewport = ref<HTMLElement | null>(null);

function syncPlanScale(zoom: number) {
    const el = planViewport.value;

    if (!el) {
        return;
    }

    const safe = Math.max(zoom, 0.12);
    // Número e icono compensan el zoom por separado, cada uno con su propio
    // tamaño objetivo EN PANTALLA (15px y 14px). Atarlo el icono a una
    // fracción del número lo dejaba en ~9px justo al alejarse, que es cuando
    // más se necesita para reconocer el tipo de un vistazo.
    el.style.setProperty(
        '--room-number-size',
        `${Math.min(38, Math.max(18, 15 / safe))}px`,
    );
    el.style.setProperty(
        '--room-icon-size',
        `${Math.min(30, Math.max(12, 14 / safe))}px`,
    );
    el.style.setProperty(
        '--room-alert-size',
        `${Math.min(26, Math.max(10, 9 / safe))}px`,
    );
    // La etiqueta de zona se dibuja dentro del canvas, así que sin esto
    // encoge al alejarse (cuando más falta hace) y se infla al acercarse.
    // El tope de 2.5 es el que evita que, muy alejado, el letrero se monte
    // sobre la primera habitación: es el espacio que le reserva su marco.
    el.style.setProperty(
        '--zone-label-scale',
        `${Math.min(2.5, Math.max(0.5, 1 / safe))}`,
    );
}

watch(() => viewport.value?.zoom ?? 1, syncPlanScale);

// La etiqueta vive en la esquina del marco de su zona: al acercarse, esa
// esquina se sale de la pantalla y el letrero desaparece justo cuando hay
// más cuartos a la vista y menos contexto. Aquí la etiqueta persigue la
// parte visible de su zona, sin salirse nunca de su marco.
const zoneLabelOffsets = ref<Record<string, { x: number; y: number }>>({});

function syncZoneLabels() {
    const vp = viewport.value;
    const dim = dimensions.value;

    if (!vp || !dim.width) {
        return;
    }

    const zoom = vp.zoom || 1;
    // Esquina superior izquierda de lo que se ve, en coordenadas del plano.
    const visibleX = -vp.x / zoom;
    const visibleY = -vp.y / zoom;
    const pad = 12 / zoom;
    const labelWidth = 170 / zoom;
    const labelHeight = 34 / zoom;
    const offsets: Record<string, { x: number; y: number }> = {};

    nodes.value.forEach((node) => {
        if (node.type !== 'zone') {
            return;
        }

        const data = node.data as ZoneNodeData;
        const slide = (
            visible: number,
            origin: number,
            room: number,
        ): number => {
            // Con la zona entera a la vista, la etiqueta se queda en su
            // esquina con un poco de aire; si no, avanza con la pantalla.
            const raw = Math.max(pad, visible + pad - origin);

            // Se redondea para no re-renderizar en cada pixel del arrastre.
            return Math.round(Math.min(raw, Math.max(pad, room)) / 4) * 4;
        };

        offsets[data.id] = {
            x: slide(visibleX, node.position.x, data.width - labelWidth),
            y: slide(visibleY, node.position.y, data.height - labelHeight),
        };
    });

    zoneLabelOffsets.value = offsets;
}

let zoneLabelFrame: number | null = null;

watch(
    () => viewport.value,
    () => {
        if (zoneLabelFrame !== null) {
            return;
        }

        zoneLabelFrame = window.requestAnimationFrame(() => {
            zoneLabelFrame = null;
            syncZoneLabels();
        });
    },
    { deep: true },
);

let fitTimer: number | null = null;

function scheduleFit() {
    if (fitTimer) {
        window.clearTimeout(fitTimer);
    }

    fitTimer = window.setTimeout(() => fitPlan(), 160);
}

// En móvil el canvas vive oculto (`hidden lg:block`) hasta que se entra a
// presentación: mide 0×0 y encuadrarlo ahí no haría nada. Se espera a que el
// contenedor tenga tamaño real en vez de apostarle a un setTimeout.
function fitWhenMeasured(attempt = 0) {
    if (dimensions.value.width > 0 && dimensions.value.height > 0) {
        fitPlan(0);
        syncPlanScale(viewport.value?.zoom ?? 1);
        syncZoneLabels();

        return;
    }

    if (attempt < 12) {
        window.setTimeout(() => fitWhenMeasured(attempt + 1), 60);
    }
}

// Acomodar el plano pide arrastre preciso: con el dedo el gesto casi siempre
// empieza encima de una tarjeta, así que en vez de mover el plano movería el
// cuarto — y el acomodo se guarda solo. Por eso editar vive donde hay ratón.
const finePointer = ref(
    typeof window !== 'undefined' &&
        window.matchMedia('(pointer: fine)').matches,
);

const canEditLayout = computed(
    () => props.canManage && finePointer.value && hasModule('tablero-avanzado'),
);

async function enterPresentation(withNativeFullscreen = true) {
    // En táctil se apaga el modo edición al entrar; con ratón se respeta lo
    // que el usuario ya tenía puesto (puede seguir acomodando en grande).
    if (!finePointer.value) {
        editMode.value = false;
    }

    presenting.value = true;
    window.localStorage.setItem(PRESENTING_KEY, '1');
    document.body.style.overflow = 'hidden';
    setMinZoom(0.15);

    if (withNativeFullscreen) {
        try {
            await document.documentElement.requestFullscreen?.();
        } catch {
            // El navegador puede negarla (iOS, permisos): el overlay basta.
        }
    }

    await nextTick();
    // El teleport mueve el contenedor de sitio: se encuadra en cuanto midió.
    fitWhenMeasured();
}

function exitPresentation() {
    // Sin esto, el cuarto que quedó seleccionado abre su ficha de golpe al
    // volver al panel: fuera de presentación la selección ES la ficha.
    closeRoom();
    zoneJump.value = null;
    presenting.value = false;
    window.localStorage.removeItem(PRESENTING_KEY);
    document.body.style.removeProperty('overflow');
    setMinZoom(0.3);

    if (document.fullscreenElement) {
        void document.exitFullscreen?.();
    }

    void nextTick(() => fitWhenMeasured());
}

function onPresentationKeydown(event: KeyboardEvent) {
    if (event.key !== 'Escape' || !presenting.value) {
        return;
    }

    // Con un diálogo abierto, Esc es suyo: cerrarlo Y salir de pantalla
    // completa de un solo golpe deja al mostrador fuera del plano sin querer.
    if (
        expressOpen.value ||
        walkInOpen.value ||
        reserveOpen.value ||
        newRoomOpen.value ||
        cashOpen.value ||
        filtersOpen.value ||
        checkoutRoom.value !== null
    ) {
        return;
    }

    // Esc retrocede un paso: primero suelta el cuarto que esté abierto y
    // solo entonces sale de pantalla completa. (Con pantalla completa nativa
    // el navegador se queda la primera tecla para salir de ella; ahí manda
    // el evento fullscreenchange.)
    if (selectedId.value !== null) {
        closeRoom();
        return;
    }

    exitPresentation();
}

// Salir del fullscreen nativo (Esc del navegador, gesto del sistema) cierra
// también el modo presentación: una sola tecla para volver al panel.
function onFullscreenChange() {
    if (!document.fullscreenElement && presenting.value) {
        exitPresentation();
    }
}

// Densidad de la tarjeta según el zoom: alejarse tiene que significar leer
// menos cosas, no ver manchas ilegibles. Es un computed grueso a propósito —
// las tarjetas se vuelven a pintar solo al cruzar un umbral, no en cada
// cuadro del pinch.
const nodeDensity = computed<'min' | 'medium' | 'full'>(() => {
    const zoom = viewport.value?.zoom ?? 1;

    // Debajo de 0.5 la tarjeta solo aguanta icono y número: la línea de
    // abajo ya no cabe cuando los dos crecen para compensar el zoom.
    if (zoom < 0.5) {
        return 'min';
    }

    // El umbral medio deja pasar la línea de abajo aunque el texto ya sea
    // chico: en rojo, "Debe" o "Excedida" se lee como alarma desde lejos
    // aunque no se lea la palabra, y eso es justo lo que se busca en el
    // plano completo de un teléfono.
    return zoom < 0.75 ? 'medium' : 'full';
});

// El minimapa dibuja SVG y necesita colores reales, no clases del theme. Se
// leen de las variables CSS (que el hotel puede repintar con su color en
// /ajustes/general/apariencia, aplicadas sobre <html>) y se memorizan: el
// minimapa pregunta por cada nodo en cada repintado.
const STATUS_COLOR_TOKENS: Record<string, string> = {
    green: 'success',
    cyan: 'info',
    // Ver status.ts: ocupada es rojo, no el color de marca del hotel.
    red: 'danger',
    orange: 'pending',
    blue: 'warning',
    gray: 'dark',
};

const themeColorCache = new Map<string, string>();

function themeColor(token: string): string {
    const cached = themeColorCache.get(token);

    if (cached) {
        return cached;
    }

    const value =
        getComputedStyle(document.documentElement)
            .getPropertyValue(`--color-${token}`)
            .trim() || '#94a3b8';
    themeColorCache.set(token, value);

    return value;
}

function minimapNodeColor(node: { type?: string; data?: RoomData }): string {
    if (node.type !== 'room') {
        return 'rgba(148, 163, 184, 0.15)';
    }

    return themeColor(STATUS_COLOR_TOKENS[node.data?.color ?? ''] ?? 'dark');
}

// El modal de habitación es el default en el panel. En presentación el tap
// abre primero la hoja de acciones —el pulgar llega abajo— y el modal se abre
// solo si lo piden.
const fichaForced = ref(false);

/**
 * El modal se apaga SIN soltar el cuarto. Hace falta porque los flujos que
 * viven en su propio diálogo (registro exprés, reserva, cuenta de salida)
 * necesitan el cuarto seleccionado pero NO pueden abrirse encima: los dos
 * diálogos son hermanos en z-[60] y HeadlessUI solo trata como anidados los
 * que viven dentro del panel del otro, así que el clic en el fondo del de
 * adentro cerraba también el de afuera. Nunca dos modales encimados.
 */
const roomDialogHidden = ref(false);

const fichaOpen = computed(
    () =>
        selectedRoom.value !== null &&
        !roomDialogHidden.value &&
        (!presenting.value || fichaForced.value),
);

const actionSheetRoom = computed<RoomData | null>(() =>
    presenting.value && !fichaForced.value && !roomDialogHidden.value
        ? selectedRoom.value
        : null,
);

function closeRoom() {
    selectedId.value = null;
    fichaForced.value = false;
    roomDialogHidden.value = false;
}

function openFichaFromSheet() {
    fichaForced.value = true;
}

/** Abre un flujo que vive en su propio diálogo, sin encimarlo al de habitación. */
function openOverRoom(open: () => void) {
    roomDialogHidden.value = true;
    open();
}

/** Vuelve al modal de habitación cuando ese flujo se cierra sin registrar. */
function backToRoom() {
    roomDialogHidden.value = false;
}

// ── Acciones de venta de un cuarto libre, en un solo lugar ──
// Antes esta decisión estaba escrita dos veces (ternarios en la ficha y
// funciones para la hoja) y con un solo booleano, así que el modo "ambos" se
// comportaba como motel puro: el flujo completo de hotel quedaba inalcanzable
// desde el plano. Ahora el modo manda aquí y las dos superficies leen lo
// mismo. Regla: en modo puro decide la configuración; en "ambos" decide quien
// atiende, venta por venta, porque nadie más sabe si el que llegó es un
// cliente de paso o un huésped que se queda.
const { isBoth, hasMotel, isMotel } = usePropertyMode();

// Con quién nace el selector del panel de consumos: el motel puro entrega y
// cobra en la puerta; el hotel carga a la cuenta. En "ambos" manda quien
// atiende, así que el default es el del hotel y el selector queda a la vista.
const chargeToRoomByDefault = computed(() => !isMotel.value);

// En un motel el consumo se cobra al entregarlo: no hay "a la cuenta" que
// ofrecer. En hotel y en "ambos" sí, y ahí decide quien atiende.
const roomCreditEnabled = computed(() => !isMotel.value);

const arrivalActions = computed<ArrivalAction[]>(() => {
    const reserve: ArrivalAction = {
        key: 'reserve',
        label: 'Crear una reserva',
        hint: hasMotel.value
            ? 'Para otra fecha, aquí mismo'
            : 'Apartarla para otra fecha',
        icon: 'CalendarPlus',
        primary: false,
    };

    if (isBoth.value) {
        return [
            {
                key: 'express',
                label: 'Registro exprés',
                hint: 'Placa o identificación y cobro, sin pedir datos',
                icon: 'Zap',
                primary: true,
            },
            {
                key: 'walkin',
                label: 'Llegó sin reserva',
                hint: 'Con nombre y contacto, registro completo',
                icon: 'LogIn',
                primary: false,
            },
            reserve,
        ];
    }

    return [
        hasMotel.value
            ? {
                  key: 'express',
                  label: 'Registrar llegada',
                  hint: 'Placa o identificación y cobro, aquí mismo',
                  icon: 'Zap',
                  primary: true,
              }
            : {
                  key: 'walkin',
                  label: 'Llegó sin reserva',
                  hint: 'Registrar su entrada ahora',
                  icon: 'LogIn',
                  primary: true,
              },
        reserve,
    ];
});

// Recibe string y no la unión: la hoja de acciones es un componente tonto
// que solo devuelve la llave que le pasamos.
function dispatchArrival(key: string, room: RoomData) {
    if (key === 'express') {
        openOverRoom(() => (expressOpen.value = true));

        return;
    }

    if (key === 'walkin') {
        // Siempre el flujo completo: es justo lo que lo distingue del exprés.
        // Vive en el plano, no en /reservas: quien vende está parado aquí.
        openOverRoom(() => (walkInOpen.value = true));

        return;
    }

    openOverRoom(() => (reserveOpen.value = true));
}

function sheetCheckIn() {
    if (selectedRoom.value) {
        checkInReservation(selectedRoom.value);
    }
}

function sheetCheckOut() {
    if (selectedRoom.value) {
        checkOutStay(selectedRoom.value);
    }
}

function sheetExtend() {
    if (selectedRoom.value) {
        openExtend(selectedRoom.value);
    }
}

function sheetMove() {
    if (selectedRoom.value) {
        openMove(selectedRoom.value);
    }
}

function sheetPos() {
    if (selectedRoom.value?.active_stay) {
        openPos(selectedRoom.value.active_stay.id);
    }
}

// Limpieza y mantenimiento desde la hoja: son las transiciones que autoriza
// el servidor (las mismas de la ficha), y es lo que más se toca desde el
// teléfono mientras se recorre el hotel.
const sheetTransitions = computed(() =>
    props.canManage && selectedRoom.value
        ? selectedRoom.value.transitions.map((status) => ({
              status,
              label: transitionMeta[status].label,
              icon: transitionMeta[status].icon,
          }))
        : [],
);

function sheetStatus(status: string) {
    const room = selectedRoom.value;
    if (!room) return;

    // Con el módulo de limpieza, empezar y terminar dejan de ser un cambio
    // de color: piden camarista al iniciar y checklist al cerrar, para que
    // el trabajo quede con nombre y tiempo.
    if (props.housekeepingEnabled && status === 'cleaning') {
        openStartCleaning(room);
        return;
    }

    if (props.housekeepingEnabled && status === 'available' && room.cleaning) {
        openCloseCleaning(room);
        return;
    }

    void changeStatus(room, status);
}

// ── Registro de limpieza desde el plano ──
const startCleaningRoom = ref<RoomData | null>(null);
const closeCleaningRoom = ref<RoomData | null>(null);

function openStartCleaning(room: RoomData) {
    startCleaningRoom.value = room;
}

function openCloseCleaning(room: RoomData) {
    closeCleaningRoom.value = room;
}

function onCleaningSaved() {
    startCleaningRoom.value = null;
    closeCleaningRoom.value = null;
    closeRoom();
    reloadRooms();
}

// Registro exprés (modo motel): el modal vende y cobra sin salir del plano.
const expressOpen = ref(false);

function onExpressRegistered() {
    expressOpen.value = false;
    // Registrada la llegada, el cuarto ya es otro: se suelta la selección en
    // vez de volver al modal con datos viejos.
    closeRoom();
    router.reload({ only: ['rooms'] });
}

// Llegó sin reserva, registro completo y sin salir del plano. Antes esto
// navegaba a /reservas?intent=walkin y le quitaba el plano de enfrente a
// quien está vendiendo el cuarto.
const walkInOpen = ref(false);

function onWalkInRegistered() {
    walkInOpen.value = false;
    // Registrada la llegada el cuarto ya es otro: se suelta la selección en
    // vez de volver al modal con datos viejos.
    closeRoom();
    router.reload({ only: ['rooms'] });
    // Con cobro al llegar, el hospedaje entra al corte del turno.
    void cash.load();
}

// Reserva para otra fecha sin salir del plano.
const reserveOpen = ref(false);

function onReserved() {
    reserveOpen.value = false;
    closeRoom();
    router.reload({ only: ['rooms'] });
}

/* --- Buscador y filtros ------------------------------------------------
 * Con 40 cuartos el canvas se vuelve un "búscalo a ojo". El buscador mira
 * número, nombre y el huésped de la estancia o de la llegada; los filtros
 * acotan por estado y zona. Sobre el plano NO se oculta nada: se apaga lo
 * que no coincide para que el acomodo físico siga siendo legible.
 */
const search = ref('');
const statusFilter = ref('');
const zoneFilter = ref<string>('');
const typeFilter = ref<string>('');

const zoneOptions = computed(() => {
    const seen = new Map<number, string>();
    props.rooms.forEach((room) => {
        if (room.zone_id !== null && room.zone)
            seen.set(room.zone_id, room.zone);
    });
    return [...seen.entries()].map(([id, name]) => ({ id, name }));
});

const typeOptions = computed(() => {
    const seen = new Set<string>();
    props.rooms.forEach((room) => {
        if (room.room_type) seen.add(room.room_type);
    });
    return [...seen.values()];
});

const statusOptions = computed(() => {
    const counts = new Map<string, number>();
    props.rooms.forEach((room) => {
        counts.set(room.status, (counts.get(room.status) ?? 0) + 1);
    });
    const options = Object.entries(statusLabels)
        .filter(([status]) => counts.has(status))
        .map(([status, meta]) => ({
            status,
            label: meta.label,
            color: meta.color,
            count: counts.get(status) ?? 0,
        }));

    // "Salida próxima" no es estado del semáforo: es la estancia activa que
    // termina hoy (o ya se excedió). Entra al filtro como opción derivada
    // para que recepción vea de un vistazo qué cuartos se liberan.
    const departing = props.rooms.filter((room) => departingSoon(room)).length;
    if (departing > 0) {
        options.push({
            status: 'departing',
            label: 'Salida próxima',
            color: 'amber',
            count: departing,
        });
    }

    // "Pago pendiente" tampoco es estado del semáforo: huésped en casa con
    // saldo o reserva con pago vencido. Derivado, igual que salida próxima.
    const due = props.rooms.filter((room) => hasPendingPayment(room)).length;
    if (due > 0) {
        options.push({
            status: 'due',
            label: 'Pago pendiente',
            color: 'red',
            count: due,
        });
    }

    return options;
});

/**
 * Conteos para el panel "Estado de la casa". A diferencia del filtro, aquí
 * SÍ se listan los estados en cero: "0 en mantenimiento" es información que
 * el dueño quiere ver, y una lista que cambia de largo sola se lee mal.
 */
const panelCounts = computed(() => {
    const counts = new Map<string, number>();
    props.rooms.forEach((room) => {
        counts.set(room.status, (counts.get(room.status) ?? 0) + 1);
    });

    return Object.entries(statusLabels).map(([status, meta]) => ({
        status,
        label: meta.label,
        count: counts.get(status) ?? 0,
        dot: statusStyles[meta.color].dot,
    }));
});

/** Tocar un renglón del panel filtra el plano; volver a tocarlo lo limpia. */
function filterByStatus(status: string) {
    statusFilter.value = statusFilter.value === status ? '' : status;
}

function matchesFilters(room: RoomData): boolean {
    if (statusFilter.value === 'departing') {
        if (!departingSoon(room)) return false;
    } else if (statusFilter.value === 'due') {
        if (!hasPendingPayment(room)) return false;
    } else if (statusFilter.value && room.status !== statusFilter.value) {
        return false;
    }
    if (zoneFilter.value && String(room.zone_id ?? '') !== zoneFilter.value)
        return false;
    if (typeFilter.value && (room.room_type ?? '') !== typeFilter.value)
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
        zoneFilter.value !== '' ||
        typeFilter.value !== '',
);

const matchingRooms = computed(() => props.rooms.filter(matchesFilters));

// Agrupar la vista de lista por zona/piso o por tipo de habitación (el
// canvas no se reordena: ahí manda el acomodo físico que dibujó el hotel).
const groupBy = ref<'' | 'zone' | 'type'>('');

const groupedRooms = computed(() => {
    if (!groupBy.value) {
        return [{ title: '', rooms: matchingRooms.value }];
    }

    const map = new Map<string, RoomData[]>();
    matchingRooms.value.forEach((room) => {
        const key =
            groupBy.value === 'zone'
                ? (room.zone ?? 'Sin zona')
                : (room.room_type ?? 'Sin tipo');
        map.set(key, [...(map.get(key) ?? []), room]);
    });

    return [...map.entries()].map(([title, rooms]) => ({ title, rooms }));
});

function clearFilters() {
    search.value = '';
    statusFilter.value = '';
    zoneFilter.value = '';
    typeFilter.value = '';
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
    // El chip de caja lee el corte en curso desde que se abre el plano: es el
    // número que más se pregunta y no puede esperar a que alguien abra el modal.
    if (showCashChip.value) {
        cash.start();
    }

    clockTimer = window.setInterval(() => {
        nowMs.value = Date.now();
    }, 30000);

    refreshTimer = window.setInterval(refreshIfIdle, 60000);
    document.addEventListener('visibilitychange', onVisibilityChange);
    document.addEventListener('keydown', onPresentationKeydown);
    document.addEventListener('fullscreenchange', onFullscreenChange);
    window.addEventListener('resize', scheduleFit);
    window.addEventListener('orientationchange', scheduleFit);

    // Kiosco: la pantalla del mostrador puede arrancar directo en el plano
    // (?presentacion=1) y el modo se recuerda por dispositivo. Sin pedir
    // fullscreen nativo: al cargar no hay gesto del usuario y el navegador
    // lo rechazaría.
    const wantsPresentation =
        new URLSearchParams(window.location.search).get('presentacion') ===
            '1' || window.localStorage.getItem(PRESENTING_KEY) === '1';

    if (wantsPresentation) {
        void enterPresentation(false);
    }
});

onBeforeUnmount(() => {
    cash.stop();

    if (clockTimer) {
        window.clearInterval(clockTimer);
    }
    if (lastEventTimer) {
        window.clearTimeout(lastEventTimer);
    }
    if (refreshTimer) {
        window.clearInterval(refreshTimer);
    }
    if (fitTimer) {
        window.clearTimeout(fitTimer);
    }
    document.removeEventListener('visibilitychange', onVisibilityChange);
    document.removeEventListener('keydown', onPresentationKeydown);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    window.removeEventListener('resize', scheduleFit);
    window.removeEventListener('orientationchange', scheduleFit);
    // Salir del plano navegando (menú lateral) no debe dejar el <body>
    // bloqueado ni la pestaña en pantalla completa.
    document.body.style.removeProperty('overflow');
    if (document.fullscreenElement) {
        void document.exitFullscreen?.();
    }
});

// "Salida próxima" para el filtro del tablero: sale hoy o ya se excedió.
function departingSoon(room: RoomData): boolean {
    return endsToday(room) || Boolean(room.active_stay?.is_overdue);
}

// "Pago pendiente" para el filtro y el badge: huésped en casa con saldo o
// reserva próxima cuya fecha límite de pago ya venció.
function hasPendingPayment(room: RoomData): boolean {
    return (
        (room.active_stay?.balance_due ?? 0) > 0 ||
        Boolean(room.upcoming_reservation?.payment_overdue)
    );
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
        return countdownLabel(room.active_stay.planned_end_at_iso, nowMs.value);
    }

    if (room.status === 'reserved' && room.upcoming_reservation) {
        return room.upcoming_reservation.starts_at.slice(-5);
    }

    if (room.rate_plans.length > 0) {
        return `Desde ${formatMoney(room.rate_plans[0].price)}`;
    }

    return room.label;
}

// ── Badges de la tarjeta (spec-plano-pantalla-completa, E2) ──
// Regla: UN badge por esquina y UNA sola línea abajo. Antes había hasta seis
// badges absolutos sobre una tarjeta de 120×80 y el de dinero se encimaba
// con el chip del precio: la tarjeta no cambia de tamaño, cambia el reparto.

// Cuando hay badge de bloqueo (arriba al centro) no cabe además el contador
// de usos/consumos: en 120px de ancho se montan uno sobre otro.
function showsBlockBadge(room: RoomData): boolean {
    return Boolean(blockBadge(room)) || room.usage_locked;
}

// El badge de bloqueo se dibuja arriba al centro, exactamente donde va el
// icono del tipo: cuando aparece manda el bloqueo, que urge más que saber
// si el cuarto es sencillo o suite.
function showsTypeIcon(room: RoomData): boolean {
    if (!room.room_type_icon) {
        return false;
    }

    return nodeDensity.value === 'min' || !showsBlockBadge(room);
}

// Fallas de mantenimiento sin resolver. Una habitación con una fuga
// reportada no debería venderse, y el plano es donde se vende: aquí es
// donde tiene que verse (caso real: fuga alta con 18 días abierta y el
// cuarto en verde).
function incidentLabel(room: RoomData): string | null {
    const incidents = room.incidents ?? [];
    if (!incidents.length) return null;

    const worst = incidents[0]; // ya vienen ordenadas por prioridad
    const rest = incidents.length - 1;

    return (
        `Falla ${worst.priority_label.toLowerCase()}: ${worst.title}` +
        (worst.overdue ? ' (sin atender)' : '') +
        (rest > 0 ? ` y ${rest} más` : '')
    );
}

// Marca de atención: UNA sola señal que sobrevive a cualquier zoom para lo
// que hay que atender. De lejos las palabras no se leen, pero un punto rojo
// sí: con 80 cuartos, ver de un vistazo cuáles tres piden algo vale más que
// poder leer los 80 números.
function attentionLabel(room: RoomData): string | null {
    if (room.active_stay?.is_overdue) {
        return 'La estancia ya pasó su hora de salida';
    }

    if (room.active_stay?.arrival_pending) {
        return 'Falta capturar la llegada: placa o identificación y el cobro';
    }

    if ((room.active_stay?.balance_due ?? 0) > 0) {
        return `Pago pendiente: debe ${formatMoney(room.active_stay?.balance_due)}`;
    }

    if (room.upcoming_reservation?.payment_overdue) {
        return 'La fecha límite de pago de la reserva ya venció';
    }

    return (
        blockBadge(room) ??
        incidentLabel(room) ??
        (room.usage_locked ? 'Habitación bloqueada' : null)
    );
}

// Línea inferior: lo más urgente gana y desplaza al resto (NUNCA dos cosas
// a la vez, que era justo el defecto). Excedida > saldo pendiente > pago de
// la reserva vencido > pista normal. "Sale hoy" dejó de ser un badge suelto
// en la esquina —ahí se encimaba— y viaja como icono de esta misma línea.
function bottomBand(room: RoomData): {
    icon: Icon | null;
    text: string;
    tone: string;
    title: string;
} {
    if (room.active_stay?.is_overdue) {
        return {
            icon: 'TriangleAlert',
            text: 'Excedida',
            tone: 'bg-white text-danger',
            title: 'La estancia ya pasó su hora de salida',
        };
    }

    // Caseta de motel: mientras no regrese el papel SIEMPRE hay saldo, así que
    // "Debe $X" solo no dice qué hacer. Se dicen las dos cosas en una línea.
    if (room.active_stay?.arrival_pending) {
        const owed = room.active_stay.balance_due ?? 0;

        return {
            icon: 'ClipboardPen',
            text:
                owed > 0
                    ? `Capturar ${formatMoney(owed).replace('.00', '')}`
                    : 'Falta capturar',
            tone: 'bg-white text-pending',
            title:
                owed > 0
                    ? `Falta capturar la llegada y cobrar ${formatMoney(owed)}`
                    : 'Falta capturar la llegada: placa o identificación',
        };
    }

    if ((room.active_stay?.balance_due ?? 0) > 0) {
        return {
            icon: null,
            text: `Debe ${formatMoney(room.active_stay?.balance_due).replace('.00', '')}`,
            tone: 'bg-white text-danger',
            title: `Pago pendiente: debe ${formatMoney(room.active_stay?.balance_due)}`,
        };
    }

    if (room.upcoming_reservation?.payment_overdue) {
        return {
            icon: null,
            text: 'Pago vencido',
            tone: 'bg-white text-danger',
            title: 'La fecha límite de pago de la reserva ya venció',
        };
    }

    // Después del dinero, lo que impide vender el cuarto: una falla abierta
    // pesa más que la pista normal (tarifa, salida de hoy).
    const incident = incidentLabel(room);
    if (incident) {
        const incidents = room.incidents ?? [];

        return {
            icon: 'Wrench',
            text:
                incidents.length > 1
                    ? `${incidents.length} fallas`
                    : incidents[0].priority === 'high'
                      ? 'Falla urgente'
                      : 'Con falla',
            tone: incidents.some((i) => i.overdue)
                ? 'bg-white text-danger'
                : 'bg-white text-pending',
            title: incident,
        };
    }

    const hint = nodeHint(room);
    const leaving = endsToday(room);

    return {
        icon: leaving ? 'LogOut' : null,
        text: hint,
        tone: 'bg-white/15',
        title: leaving ? `Sale hoy · ${hint}` : hint,
    };
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
    if (event.node.type !== 'room') {
        return;
    }

    // Acomodando en pantalla completa, cada clic soltaría la hoja de
    // acciones encima del plano: mientras se edita, el clic no selecciona.
    if (presenting.value && editMode.value) {
        return;
    }

    selectedId.value = (event.node.data as RoomData).id;
    // Cada tap vuelve a empezar por la hoja de acciones: la ficha completa
    // solo se abre si la piden desde ahí.
    fichaForced.value = false;
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

/* --- Panel de habitación (módulo plano-operativo) ----------------------
 * Alta, edición, duplicado y borrado desde el plano. El panel solo emite:
 * aquí se ejecuta con runRoomAction para heredar el toast, el candado de
 * "acción en curso" y el refresco que ya usan la hoja y la ficha.
 */
interface RoomFormPayload {
    number: string;
    room_type_id: number;
    zone_id: number | null;
}

/**
 * Centro de lo que se está viendo, en coordenadas del plano: una habitación
 * nueva tiene que nacer a la vista, no en el origen del lienzo (que con el
 * plano desplazado queda fuera de pantalla y parece que no se creó).
 */
function viewportCenter(): { pos_x: number; pos_y: number } {
    const { x, y, zoom } = viewport.value;
    const width = dimensions.value.width || 800;
    const height = dimensions.value.height || 600;
    const toGrid = (value: number) => Math.round(value / GRID) * GRID;

    return {
        pos_x: toGrid((-x + width / 2) / zoom),
        pos_y: toGrid((-y + height / 2) / zoom),
    };
}

function panelCreateRoom(payload: RoomFormPayload) {
    return runRoomAction(
        'panel:create',
        async () => {
            await axios.post('/api/rooms', {
                property_id: props.property.id,
                ...payload,
                ...viewportCenter(),
            });
        },
        {
            successTitle: 'Habitación creada',
            successMessage: `La ${payload.number} ya está en el plano`,
            errorTitle: 'No se pudo crear la habitación',
        },
    );
}

function panelUpdateRoom(payload: RoomFormPayload) {
    const room = selectedRoom.value;

    if (!room) {
        return;
    }

    return runRoomAction(
        `panel:update:${room.id}`,
        async () => {
            await axios.patch(`/api/rooms/${room.id}`, payload);
        },
        {
            successTitle: 'Habitación actualizada',
            successMessage: `Guardado en la ${payload.number}`,
            errorTitle: 'No se pudo guardar',
        },
    );
}

function panelDuplicateRoom() {
    const room = selectedRoom.value;

    if (!room) {
        return;
    }

    return runRoomAction(
        `panel:duplicate:${room.id}`,
        async () => {
            await axios.post(`/api/rooms/${room.id}/duplicate`);
        },
        {
            successTitle: 'Habitación duplicada',
            successMessage: `Copia de la ${room.number} con el siguiente número libre`,
            errorTitle: 'No se pudo duplicar',
        },
    );
}

async function panelDeleteRoom() {
    const room = selectedRoom.value;

    if (!room) {
        return;
    }

    // Borrar no se deshace y el servidor solo frena las que tienen huésped o
    // reservas: la confirmación es la única red para un clic distraído.
    if (
        !window.confirm(
            `¿Quitar la habitación ${room.number} del plano y del inventario?`,
        )
    ) {
        return;
    }

    await runRoomAction(
        `panel:delete:${room.id}`,
        async () => {
            await axios.delete(`/api/rooms/${room.id}`);
        },
        {
            successTitle: 'Habitación eliminada',
            successMessage: `La ${room.number} salió del plano`,
            errorTitle: 'No se pudo eliminar',
        },
    );

    closeRoom();
}

/**
 * Consumo cargado o cobrado desde el modal: toast, refresco del plano y relectura
 * de la caja — un cobro cambia el corte en el acto, y esperar al siguiente
 * minuto dejaría el chip mintiendo justo después de cobrar.
 */
function onPanelSold(message: string) {
    toast.success('Consumo registrado', message);
    reloadRooms();
    void reloadFolio();
    void cash.load();
}

/* --- Llegada de una reserva ------------------------------------------
 * Con fianza activa hace escala en un diálogo: este botón mandaba el
 * check-in sin cuerpo y el servidor entiende "sin método de fianza" como
 * "no se cobró". La misma llegada registrada desde /reservas sí la cobraba
 * y desde aquí no — y el plano es donde de verdad se trabaja, así que el
 * depósito se perdía casi siempre.
 */
const checkInRoom = ref<RoomData | null>(null);

function checkInReservation(room: RoomData) {
    if (!room.upcoming_reservation) {
        return;
    }

    if ((room.upcoming_reservation.guarantee_amount ?? 0) > 0) {
        checkInRoom.value = room;

        return;
    }

    return runCheckIn(room, {});
}

function runCheckIn(
    room: RoomData,
    body: Record<string, string | number | null>,
) {
    return runRoomAction(
        `reservation:${room.upcoming_reservation?.id}`,
        async () => {
            await axios.patch(
                `/api/reservations/${room.upcoming_reservation?.id}/check-in`,
                body,
            );
        },
        {
            successTitle: 'Llegada registrada',
            successMessage: `Habitación ${room.number} ocupada`,
            errorTitle: 'No se pudo registrar la llegada',
        },
    );
}

async function confirmCheckInWithGuarantee(payload: {
    method: 'cash' | 'card';
    amount: number | null;
    reason: string | null;
}) {
    const room = checkInRoom.value;

    if (!room) {
        return;
    }

    checkInRoom.value = null;

    await runCheckIn(room, {
        guarantee_method: payload.method,
        // Solo viajan cuando el mostrador ajustó el monto: el servidor exige
        // motivo para cualquier cifra distinta a la de la política.
        ...(payload.amount !== null
            ? {
                  guarantee_amount: payload.amount,
                  guarantee_reason: payload.reason,
              }
            : {}),
    });
}

/* --- Salida con cuenta ------------------------------------------------
 * El check-out del plano se mandaba sin cuerpo: con saldo pendiente el
 * servidor respondía 422 y el mostrador se quedaba sin salida. Ahora se
 * abre la cuenta, se cobra y se registra la salida en un solo paso — el
 * endpoint siempre aceptó método de pago y fianza.
 */
const checkoutRoom = ref<RoomData | null>(null);
const checkoutBusy = ref(false);

/**
 * La cuenta del cuarto abierto. Se pide UNA vez —al abrir el modal— y la miran
 * los tabs y el diálogo de salida: dos consultas darían dos cifras distintas
 * con dinero enfrente. Se relee después de cada movimiento propio.
 */
const roomFolio = ref<CheckoutFolio | null>(null);
const folioLoading = ref(false);

async function reloadFolio() {
    const stayId = selectedRoom.value?.active_stay?.id;

    if (stayId === undefined) {
        roomFolio.value = null;

        return;
    }

    folioLoading.value = true;

    try {
        const { data } = await axios.get(`/api/stays/${stayId}/folio`);
        roomFolio.value = data;
    } catch {
        roomFolio.value = null;
    } finally {
        folioLoading.value = false;
    }
}

// Al cambiar de cuarto (o cerrar) la cuenta se vuelve a pedir: dejar la del
// anterior a la vista es cómo alguien cobra lo que no era.
watch(
    () => selectedRoom.value?.active_stay?.id,
    () => {
        roomFolio.value = null;
        void reloadFolio();
    },
);

async function checkOutStay(room: RoomData) {
    if (!room.active_stay) {
        return;
    }

    checkoutRoom.value = room;

    // La cuenta ya está en mano si el modal estuvo abierto; si no (la salida
    // se pidió desde la hoja de acciones), se pide ahora.
    if (roomFolio.value === null) {
        await reloadFolio();
    }

    if (roomFolio.value === null) {
        // Sin folio a la vista no se cobra a ciegas: se avisa y se cierra.
        checkoutRoom.value = null;
        toast.error(
            'No se pudo abrir la cuenta',
            'Intenta de nuevo o registra la salida desde la reserva.',
        );
    }
}

/* --- Mantenimiento del cuarto desde el modal --------------------------
 * Incidencias, bloqueos por fechas y el candado de usos ya existían en sus
 * secciones; aquí solo se llaman desde donde se descubre el problema.
 */
async function reportIncident(payload: {
    title: string;
    category: string | null;
    priority: string;
    description: string | null;
    source: string;
    set_maintenance: boolean;
    photo: File | null;
}) {
    const room = selectedRoom.value;

    if (!room) {
        return;
    }

    await runRoomAction(
        'incident',
        async () => {
            const { data } = await axios.post('/api/incidents', {
                room_id: room.id,
                title: payload.title,
                category: payload.category,
                priority: payload.priority,
                description: payload.description,
                source: payload.source,
                set_maintenance: payload.set_maintenance,
            });

            // La foto va en un POST aparte: el alta es JSON transaccional y no
            // carga con multipart (mismo camino que el documento del huésped).
            if (payload.photo && data?.id) {
                const form = new FormData();
                form.append('file', payload.photo);
                await axios.post(`/api/incidents/${data.id}/photos`, form);
            }
        },
        {
            successTitle: 'Incidencia reportada',
            successMessage: payload.set_maintenance
                ? `La ${room.number} pasó a mantenimiento`
                : `Queda registrada para la ${room.number}`,
            errorTitle: 'No se pudo reportar la falla',
        },
    );
}

async function createBlock(payload: {
    starts_at: string;
    ends_at: string;
    reason: string | null;
}) {
    const room = selectedRoom.value;

    if (!room) {
        return;
    }

    await runRoomAction(
        'block',
        async () => {
            await axios.post(`/api/rooms/${room.id}/blocks`, payload);
        },
        {
            successTitle: 'Mantenimiento programado',
            successMessage: `La ${room.number} no se vende esas fechas`,
            errorTitle: 'No se pudo programar',
        },
    );
}

async function deleteBlock(blockId: number) {
    const room = selectedRoom.value;

    if (!room) {
        return;
    }

    await runRoomAction(
        `block:${blockId}`,
        async () => {
            await axios.delete(`/api/rooms/${room.id}/blocks/${blockId}`);
        },
        {
            successTitle: 'Bloqueo retirado',
            successMessage: `La ${room.number} vuelve a venderse esas fechas`,
            errorTitle: 'No se pudo retirar el bloqueo',
        },
    );
}

async function resetUsage() {
    const room = selectedRoom.value;

    if (!room) {
        return;
    }

    await runRoomAction(
        'usage',
        async () => {
            await axios.post(`/api/rooms/${room.id}/usage-reset`);
        },
        {
            successTitle: 'Contador reiniciado',
            successMessage: `La ${room.number} vuelve a la rotación`,
            errorTitle: 'No se pudo reiniciar el contador',
        },
    );
}

/* --- Revisión de la habitación al salir ---------------------------------
 * Aplica a cualquier propiedad (hotel, cabañas o motel): antes de dejar salir
 * al huésped se revisa el cuarto. El daño sube la cuenta, queda como
 * incidencia y —si lo deciden— veta la placa y la ficha del huésped.
 */
async function addDamage(payload: { concept: string; amount: number }) {
    const stayId = checkoutRoom.value?.active_stay?.id;

    if (stayId === undefined) {
        return;
    }

    await runRoomAction(
        'damage',
        async () => {
            const { data } = await axios.post(`/api/stays/${stayId}/charges`, {
                concept: payload.concept,
                amount: payload.amount,
                kind: 'damage',
            });
            roomFolio.value = data;

            // Queda en incidencias, que es donde el hotel revisa lo que se
            // rompe: sin esto el daño solo existiría como una línea de cobro.
            if (hasModule('incidencias')) {
                await axios.post('/api/incidents', {
                    room_id: checkoutRoom.value?.id,
                    // La estancia que lo causó: sin esto el daño queda como
                    // ticket suelto y no se sabe a quién se le cobró.
                    stay_id: stayId,
                    title: `Daño: ${payload.concept}`,
                    category: 'mobiliario',
                    priority: 'medium',
                    source: 'guest',
                    description: `Cobrado ${formatMoney(payload.amount)} al registrar la salida.`,
                });
            }
        },
        {
            successTitle: 'Daño cargado a la cuenta',
            successMessage: `${payload.concept} · ${formatMoney(payload.amount)}`,
            errorTitle: 'No se pudo cargar el daño',
        },
    );
}

/** Veta la placa y la ficha del huésped; la caseta lo verá al siguiente tap. */
async function blacklistStay(stay: ActiveStaySummary, reason: string) {
    const requests: Promise<unknown>[] = [];

    if (stay.vehicle_id) {
        requests.push(
            axios.patch(`/api/vehicles/${stay.vehicle_id}`, {
                is_blacklisted: true,
                blacklist_reason: reason,
            }),
        );
    }

    if (stay.guest_id) {
        requests.push(
            axios.patch(`/api/guests/${stay.guest_id}`, {
                is_blacklisted: true,
                blacklist_reason: reason,
            }),
        );
    }

    if (!requests.length) {
        return;
    }

    try {
        await Promise.all(requests);
        toast.success(
            'Cliente vetado',
            'La caseta verá el aviso en su próxima visita',
        );
    } catch {
        toast.error(
            'No se pudo vetar',
            'El cobro sí quedó; veta a mano desde la ficha del vehículo.',
        );
    }
}

/* --- Completar la llegada (caseta de motel) --------------------------- */
const arrivalRoom = ref<RoomData | null>(null);

function openCompleteArrival(room: RoomData) {
    openOverRoom(() => (arrivalRoom.value = room));
}

function onArrivalCompleted(message: string) {
    arrivalRoom.value = null;
    closeRoom();
    toast.success('Registro completado', message);
    reloadRooms();
    // El cobro del encargado entra al corte de quien lo capturó.
    void cash.load();
}

/** Cancelar una venta de la estancia: devuelve la mercancía al inventario. */
async function voidStayOrder(orderId: number) {
    await runRoomAction(
        `order:${orderId}`,
        async () => {
            await axios.post(`/api/orders/${orderId}/void`, {
                reason: 'Cancelada desde el plano',
            });
        },
        {
            successTitle: 'Venta cancelada',
            successMessage: 'La mercancía volvió al inventario',
            errorTitle: 'No se pudo cancelar la venta',
        },
    );

    await reloadFolio();
    void cash.load();
}

async function confirmCheckout(payload: {
    payment_method: string | null;
    reference: string | null;
    force: boolean;
    guarantee_refund: boolean;
    guarantee_retain_reason: string | null;
    blacklist?: boolean;
    blacklist_reason?: string | null;
}) {
    const room = checkoutRoom.value;

    if (!room?.active_stay) {
        return;
    }

    checkoutBusy.value = true;

    // El veto va ANTES de la salida: después el payload ya no trae la ficha.
    if (payload.blacklist && room.active_stay) {
        await blacklistStay(
            room.active_stay,
            payload.blacklist_reason?.trim() || 'Daños en la habitación',
        );
    }

    await runRoomAction(
        `stay:${room.active_stay.id}`,
        async () => {
            await axios.patch(
                `/api/stays/${room.active_stay?.id}/check-out`,
                payload,
            );
        },
        {
            successTitle: 'Salida registrada',
            successMessage: `La habitación ${room.number} quedó por limpiar; el equipo puede entrar`,
            errorTitle: 'No se pudo registrar la salida',
        },
    );

    checkoutBusy.value = false;
    checkoutRoom.value = null;
    roomFolio.value = null;
    closeRoom();
    // Cobrar en la salida mueve el corte del turno.
    void cash.load();
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
        closeRoom();
        toast.success(
            'Huésped movido',
            'La habitación que dejó quedó marcada por limpiar.',
        );
        reloadRooms();
    } catch (e: any) {
        moveError.value =
            e.response?.data?.message ?? 'No se pudo cambiar la habitación.';
    } finally {
        moveBusy.value = false;
    }
}

// Cargar consumo exige permiso Y el módulo del punto de venta: sin el
// módulo la ruta /pos responde 403 y el botón era un callejón sin salida.
const canChargeConsumption = computed(
    () => props.canManageOrders && hasModule('pos'),
);

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
        if (room.active_stay.balance_due > 0) {
            lines.push(`Debe: ${formatMoney(room.active_stay.balance_due)}`);
        }
    } else if (room.upcoming_reservation) {
        lines.push(room.upcoming_reservation.guest_name);
        lines.push(`Llega: ${room.upcoming_reservation.starts_at}`);
        lines.push(
            `Reserva: ${formatMoney(room.upcoming_reservation.total_amount)}`,
        );
    } else if (room.rate_plans[0]) {
        lines.push(`Desde ${formatMoney(room.rate_plans[0].price)}`);
    }

    if (room.usage_locked) {
        lines.push(
            `Bloqueada por usos: ${room.usage_count} de ${room.usage_limit}. Resetea el contador para liberarla.`,
        );
    } else if (room.usage_limit) {
        lines.push(`Usos: ${room.usage_count} de ${room.usage_limit}`);
    } else if (room.usage_count) {
        lines.push(`Usos: ${room.usage_count}`);
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
            usage_count: payload.usage_count,
            usage_limit: payload.usage_limit,
            usage_locked: payload.usage_locked,
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
/* --- Contexto para el modal de habitación -------------------------------
 * Un solo `provide` en vez de veinte props atravesando el modal y sus cuatro
 * tabs. Y viaja por el árbol de componentes, no por el DOM, así que sobrevive
 * al <Teleport> de pantalla completa.
 *
 * Todo lo que ejecuta acciones vive AQUÍ: los tabs solo llaman, y así heredan
 * el toast, el candado de "acción en curso" y el refresco que ya usan la hoja
 * de acciones y el resto de la página.
 */
provide(FloorPlanKey, {
    // El modal se abre con la misma regla que tenía la ficha: en el panel, el
    // clic lo abre directo; en pantalla completa el tap abre primero la hoja de
    // acciones —el pulgar llega abajo— y de ahí se pide "ver ficha completa".
    room: computed(() => (fichaOpen.value ? selectedRoom.value : null)),
    nowMs,
    folio: roomFolio,
    folioLoading,
    reloadFolio,

    canManage: props.canManage,
    canManageReservations: props.canManageReservations,
    canViewDocuments: props.canViewDocuments,
    canViewStays: props.canViewStays,
    manualCheckinAllowed: props.manualCheckinAllowed,
    canChargeConsumption,
    canChargeHere: computed(() => canSee('consumos')),
    // Editar el inventario de cuartos es un permiso aparte del semáforo.
    canManageRooms: computed(() => canSee('habitacion')),
    editMode,
    canToggleEdit: computed(
        () => props.canManage && hasModule('tablero-avanzado'),
    ),
    busyAction,
    saving,

    propertyId: props.property.id,
    roomTypes: props.roomTypes,
    zones: props.zones,
    incidentCategories: props.incidentCategories,
    chargeToRoomByDefault,
    roomCreditEnabled,
    arrivalActions,
    transitions: sheetTransitions,

    changeStatus: (room, status) => void changeStatus(room, status),
    dispatchArrival,
    checkInReservation: (room) => void checkInReservation(room),
    requestCheckout: (room) => {
        // Nada de modal sobre modal: se apaga el de habitación —sin soltar el
        // cuarto, que la cuenta lo necesita— y sube el de la salida.
        openOverRoom(() => void checkOutStay(room));
    },
    openExtend,
    openMove,
    openPos,
    openReservationDetail,
    completeArrival: openCompleteArrival,
    createRoom: (payload) => void panelCreateRoom(payload),
    updateRoom: (payload) => void panelUpdateRoom(payload),
    duplicateRoom: () => void panelDuplicateRoom(),
    deleteRoom: () => void panelDeleteRoom(),
    onSold: onPanelSold,
    voidOrder: voidStayOrder,
    reportIncident,
    createBlock,
    deleteBlock,
    resetUsage,
    onError: (message) => toast.error('No se pudo cobrar', message),
    close: closeRoom,
});
</script>

<template>
    <RazeLayout title="Plano">
        <!-- Encabezado estándar del panel (el de Habitaciones y Bandeja):
             icono, título, subtítulo y las acciones agrupadas a la derecha.
             Antes era un título suelto con seis chips de leyenda y cuatro
             botones peleando la misma fila. -->
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Map" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Plano operativo</h1>
                        <p class="mt-0.5 truncate text-xs text-slate-500">
                            {{ property.name }} · {{ rooms.length }}
                            {{
                                rooms.length === 1
                                    ? 'habitación'
                                    : 'habitaciones'
                            }}
                            · tiempo real
                        </p>
                    </div>
                </div>

                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2.5"
                >
                    <!-- Cómo va la caja del turno, con el total a la vista: el
                         número que más se pregunta en el mostrador. -->
                    <Button
                        v-if="showCashChip"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] text-xs whitespace-nowrap"
                        title="Ver el corte en curso y el turno abierto"
                        @click="cashOpen = true"
                    >
                        <Lucide icon="Wallet" class="mr-1.5 h-3.5 w-3.5" />
                        Caja
                        <span class="ml-2 font-semibold">{{
                            formatMoney(cash.total.value)
                        }}</span>
                    </Button>
                    <Button
                        v-if="canCreateRooms"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] text-xs whitespace-nowrap"
                        title="Da de alta una habitación y nace en el centro del plano"
                        @click="newRoomOpen = true"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Nueva habitación
                    </Button>
                    <Button
                        v-if="canManage && hasModule('tablero-avanzado')"
                        :variant="editMode ? 'primary' : 'outline-secondary'"
                        class="rounded-[0.5rem] text-xs whitespace-nowrap"
                        :title="
                            editMode
                                ? 'Al terminar, bloquea para que nadie mueva cuartos por accidente'
                                : 'Habilita mover los cuartos para acomodar el plano'
                        "
                        @click="editMode = !editMode"
                    >
                        <Lucide
                            :icon="editMode ? 'LockOpen' : 'Lock'"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        {{ editMode ? 'Terminar' : 'Editar plano' }}
                    </Button>
                    <!-- La acción principal, a lo ancho en el teléfono. -->
                    <Button
                        variant="primary"
                        class="col-span-2 rounded-[0.5rem] text-xs whitespace-nowrap shadow-md shadow-primary/20 md:col-auto"
                        title="Ve el plano completo sin el menú ni los filtros; sales con Esc"
                        @click="enterPresentation()"
                    >
                        <Lucide icon="Maximize" class="mr-1.5 h-3.5 w-3.5" />
                        Pantalla completa
                    </Button>
                </div>
            </div>

            <!-- Modo edición: el aviso y su única acción viven juntos. Antes
                 "Alinear" andaba suelto en la fila de botones y solo servía
                 aquí. -->
            <div
                v-if="editMode"
                class="mt-3 flex flex-col gap-3 rounded-md border border-warning/30 bg-warning/5 px-3.5 py-3 text-xs text-slate-600 sm:flex-row sm:items-center dark:text-slate-300"
            >
                <Lucide
                    icon="Move"
                    class="hidden h-4 w-4 shrink-0 text-warning sm:block"
                />
                <p class="min-w-0 flex-1">
                    Arrastra los cuartos para acomodarlos: se imantan a la
                    cuadrícula y la posición se guarda sola. El refresco
                    automático queda pausado hasta que termines.
                </p>
                <Button
                    variant="outline-secondary"
                    class="shrink-0 rounded-[0.5rem] bg-white text-xs whitespace-nowrap dark:bg-darkmode-600"
                    :disabled="aligning"
                    title="Endereza los cuartos a la cuadrícula sin cambiar su acomodo"
                    @click="alignToGrid"
                >
                    <Lucide icon="Grid3x3" class="mr-1.5 h-3.5 w-3.5" />
                    {{ aligning ? 'Alineando…' : 'Alinear' }}
                </Button>
            </div>
        </div>

        <div
            v-if="lastEvent"
            class="fixed top-20 right-8 z-[60] rounded-md bg-dark/90 px-3 py-2 text-sm text-white shadow-lg"
        >
            {{ lastEvent }}
        </div>

        <!-- Buscador, filtros y leyenda -->
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
                        class="pl-9 text-xs"
                        placeholder="Buscar por habitación, huésped, tipo o código de reserva"
                    />
                </div>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <FormSelect
                        v-model="statusFilter"
                        class="text-xs sm:w-48"
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
                        class="text-xs sm:w-44"
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
                    <FormSelect
                        v-if="typeOptions.length > 1"
                        v-model="typeFilter"
                        class="text-xs sm:w-44"
                        aria-label="Filtrar por tipo de habitación"
                    >
                        <option value="">Todos los tipos</option>
                        <option v-for="t in typeOptions" :key="t" :value="t">
                            {{ t }}
                        </option>
                    </FormSelect>
                    <Button
                        v-if="filtersActive"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] text-xs whitespace-nowrap"
                        @click="clearFilters"
                    >
                        <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                        Limpiar
                    </Button>
                </div>
            </div>
            <p v-if="filtersActive" class="mt-3 text-xs text-slate-500">
                {{ matchingRooms.length }} de {{ rooms.length }}
                {{ rooms.length === 1 ? 'habitación' : 'habitaciones' }}.
                <span class="hidden lg:inline"
                    >En el plano las demás se ven atenuadas para no perder el
                    acomodo.</span
                >
            </p>

            <!-- Qué significa cada color. Vive aquí y no entre los botones:
                 es una referencia, no una acción, y allá arriba solo competía
                 por el espacio de lo que sí se toca. -->
            <div
                class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-slate-200/70 pt-3 dark:border-darkmode-400"
            >
                <button
                    v-for="(meta, status) in statusLabels"
                    :key="status"
                    type="button"
                    class="flex items-center gap-1.5 text-xs transition"
                    :class="
                        statusFilter === status
                            ? 'font-medium text-primary'
                            : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                    "
                    :title="`Ver solo ${meta.label.toLowerCase()}`"
                    @click="filterByStatus(status)"
                >
                    <span
                        class="h-2.5 w-2.5 rounded-full"
                        :class="statusStyles[meta.color].dot"
                    />
                    {{ meta.label }}
                </button>
            </div>
        </div>

        <!-- Plano (canvas). En modo presentación el MISMO VueFlow se teleporta
             a <body> y ocupa toda la pantalla (Teleport con :disabled mueve el
             DOM sin volver a montar: no se pierde el zoom, la selección ni el
             tiempo real). Fuera de presentación sigue siendo de escritorio —
             dentro de una página con scroll el canvas pelea contra el dedo, y
             para el mostrador está la lista de abajo. -->
        <Teleport to="body" :disabled="!presenting">
            <div
                :class="
                    presenting
                        ? 'fixed inset-x-0 top-0 z-[55] flex h-dvh flex-col bg-slate-100 dark:bg-darkmode-800'
                        : 'box relative mt-4 hidden overflow-hidden lg:block'
                "
                :style="
                    presenting
                        ? undefined
                        : {
                              height: 'clamp(420px, calc(100dvh - 320px), 100dvh)',
                          }
                "
            >
                <!-- Barra del modo presentación: lo mínimo para operar
                     (qué hotel es, el semáforo y cómo salir). El buscador y
                     los filtros quedan fuera a propósito: es justo lo que
                     pidieron no ver en pantalla completa. -->
                <div
                    v-if="presenting"
                    class="flex shrink-0 items-center gap-3 border-b border-slate-200/70 bg-white px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600"
                >
                    <!-- En un teléfono, con chips de zona el nombre del hotel
                         es lo primero que sobra: quien mira ya sabe en qué
                         panel está, y los chips necesitan el ancho. -->
                    <div
                        class="min-w-0 shrink-0"
                        :class="zoneOptions.length > 1 ? 'hidden sm:block' : ''"
                    >
                        <div class="truncate text-sm font-medium">
                            {{ property.name }}
                        </div>
                        <div class="text-xs text-slate-500">
                            Plano operativo · tiempo real
                        </div>
                    </div>
                    <!-- Buscar, filtrar y saltar de zona detrás de un botón:
                         como pastillas en la barra, los nombres reales de
                         zona ("Habitaciones Jacuzzi VIP") se amontonaban y la
                         última quedaba cortada contra el semáforo. -->
                    <Button
                        variant="outline-secondary"
                        class="ml-3 shrink-0 rounded-[0.5rem] text-xs whitespace-nowrap"
                        title="Buscar, filtrar y saltar a una zona"
                        @click="filtersOpen = true"
                    >
                        <Lucide
                            icon="SlidersHorizontal"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        <!-- Con la zona activa en la etiqueta, un nombre
                             largo volvería a estirar la barra: se recorta. -->
                        <span
                            class="hidden max-w-56 truncate sm:inline-block"
                            >{{ activeZoneName ?? 'Buscar y filtrar' }}</span
                        >
                        <span class="sm:hidden">Filtros</span>
                        <span
                            v-if="filtersActive"
                            class="ml-2 h-2 w-2 rounded-full bg-primary"
                            title="Hay filtros aplicados"
                        />
                    </Button>
                    <!-- Lado derecho junto: un solo ml-auto manda, sin que se
                         peleen varios cuando aparecen o desaparecen botones. -->
                    <div class="ml-auto flex shrink-0 items-center gap-2">
                        <Button
                            v-if="showCashChip"
                            variant="outline-secondary"
                            class="rounded-[0.5rem] text-xs whitespace-nowrap"
                            title="Ver el corte en curso y el turno abierto"
                            @click="cashOpen = true"
                        >
                            <Lucide
                                icon="Wallet"
                                class="h-3.5 w-3.5 sm:mr-1.5"
                            />
                            <span class="hidden sm:inline">Caja</span>
                            <span class="ml-1 font-semibold sm:ml-2">{{
                                formatMoney(cash.total.value)
                            }}</span>
                        </Button>
                        <!-- Dar de alta también desde aquí: en pantalla
                             completa es donde el mostrador vive, y no tenerlo
                             obligaba a salirse. -->
                        <Button
                            v-if="canCreateRooms"
                            variant="outline-secondary"
                            class="rounded-[0.5rem] text-xs whitespace-nowrap"
                            title="Nueva habitación"
                            @click="newRoomOpen = true"
                        >
                            <Lucide icon="Plus" class="h-3.5 w-3.5 sm:mr-1.5" />
                            <span class="hidden sm:inline">Nueva</span>
                        </Button>

                        <!-- Lo de vez en cuando, detrás de un menú: acomodar el
                             plano, alinear y la leyenda. Los seis chips de
                             colores sueltos eran justo lo que amontonaba la
                             barra. -->
                        <Menu>
                            <Menu.Button
                                class="flex h-9 w-9 items-center justify-center rounded-[0.5rem] border border-slate-200 text-slate-500 transition hover:bg-slate-100 dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                                title="Más opciones"
                            >
                                <Lucide icon="MoreVertical" class="h-4 w-4" />
                            </Menu.Button>
                            <Menu.Items class="w-64">
                                <Menu.Item
                                    v-if="canEditLayout"
                                    as="button"
                                    type="button"
                                    @click="editMode = !editMode"
                                >
                                    <Lucide
                                        :icon="editMode ? 'LockOpen' : 'Lock'"
                                        class="mr-2 h-4 w-4"
                                    />
                                    {{
                                        editMode
                                            ? 'Terminar edición'
                                            : 'Editar plano'
                                    }}
                                </Menu.Item>
                                <Menu.Item
                                    v-if="canEditLayout && editMode"
                                    as="button"
                                    type="button"
                                    :disabled="aligning"
                                    @click="alignToGrid"
                                >
                                    <Lucide
                                        icon="Grid3x3"
                                        class="mr-2 h-4 w-4"
                                    />
                                    {{ aligning ? 'Alineando…' : 'Alinear' }}
                                </Menu.Item>
                                <Menu.Divider />
                                <div class="px-3 py-2">
                                    <div
                                        class="mb-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                                    >
                                        Colores
                                    </div>
                                    <div class="space-y-1.5">
                                        <div
                                            v-for="(
                                                meta, status
                                            ) in statusLabels"
                                            :key="status"
                                            class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300"
                                        >
                                            <span
                                                class="h-2.5 w-2.5 shrink-0 rounded-full"
                                                :class="
                                                    statusStyles[meta.color].dot
                                                "
                                            />
                                            {{ meta.label }}
                                        </div>
                                    </div>
                                </div>
                            </Menu.Items>
                        </Menu>
                        <Button
                            variant="outline-secondary"
                            class="rounded-[0.5rem] text-xs whitespace-nowrap"
                            title="Volver al panel (también con Esc)"
                            @click="exitPresentation"
                        >
                            <Lucide
                                icon="Minimize"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Salir
                        </Button>
                    </div>
                </div>

                <!-- Mismo aviso que en el panel: el de allá queda tapado por
                     el overlay y sin él nadie sabe que el refresco se pausó. -->
                <div
                    v-if="presenting && editMode"
                    class="flex shrink-0 items-center gap-2 border-b border-warning/30 bg-warning/5 px-4 py-2 text-xs text-slate-600 dark:text-slate-300"
                >
                    <Lucide icon="Move" class="h-4 w-4 shrink-0 text-warning" />
                    Modo edición: arrastra los cuartos para acomodarlos — se
                    imantan a la cuadrícula y la posición se guarda sola. El
                    refresco automático queda en pausa hasta que termines.
                </div>

                <div
                    ref="planViewport"
                    :class="
                        presenting
                            ? 'relative min-h-0 flex-1'
                            : 'relative h-full w-full'
                    "
                >
                    <!-- El canvas va en su propia capa: `touch-action: none`
                         apaga el scroll táctil de TODO lo que cuelgue debajo,
                         y los paneles llevan listas que el dedo debe mover. -->
                    <div
                        class="absolute inset-0"
                        :style="
                            presenting ? { touchAction: 'none' } : undefined
                        "
                    >
                        <VueFlow
                            v-model:nodes="nodes"
                            :edges="[]"
                            :min-zoom="presenting ? 0.15 : 0.3"
                            :max-zoom="2.5"
                            fit-view-on-init
                            :nodes-connectable="false"
                            :snap-to-grid="true"
                            :snap-grid="[GRID, GRID]"
                            @node-drag-stop="onNodeDragStop"
                            @node-click="onNodeClick"
                            @pane-click="closeRoom"
                        >
                            <Background :gap="GRID" />
                            <!-- Minimapa: con los estilos de fábrica quedaba un
                             recuadro gris enorme; acotado y con el color del
                             semáforo sirve, y en móvil sobra (el pinch ya
                             navega). -->
                            <MiniMap
                                pannable
                                zoomable
                                :width="180"
                                :height="120"
                                :node-color="minimapNodeColor"
                                mask-color="rgba(148, 163, 184, 0.18)"
                                class="hidden overflow-hidden rounded-lg border border-slate-200/70 shadow-sm lg:block dark:border-darkmode-400"
                            />
                            <Controls
                                v-if="!presenting"
                                :show-interactive="false"
                            />

                            <template #node-zone="{ data }">
                                <div
                                    class="relative rounded-2xl border border-dashed"
                                    :style="{
                                        width: `${data.width}px`,
                                        height: `${data.height}px`,
                                        borderColor: zoneTint(data.color, 0.45),
                                        backgroundColor: zoneTint(
                                            data.color,
                                            0.06,
                                        ),
                                    }"
                                >
                                    <span
                                        class="absolute inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-xs font-medium whitespace-nowrap text-slate-600 shadow-sm dark:bg-darkmode-600 dark:text-slate-200"
                                        :style="{
                                            left: `${zoneLabelOffsets[data.id]?.x ?? 0}px`,
                                            top: `${zoneLabelOffsets[data.id]?.y ?? 0}px`,
                                            transform:
                                                'scale(var(--zone-label-scale, 1))',
                                            transformOrigin: 'top left',
                                        }"
                                    >
                                        <span
                                            class="h-2 w-2 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor: zoneTint(
                                                    data.color,
                                                    1,
                                                ),
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
                                        statusStyles[data.color]?.bg ??
                                            'bg-slate-400',
                                        selectedId === data.id
                                            ? `${statusStyles[data.color]?.ring} ring-4`
                                            : 'ring-white/40',
                                        data.active_stay?.is_overdue
                                            ? 'animate-pulse'
                                            : '',
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
                                    <!-- Badges: arriba UNO por esquina y abajo UNA sola
                             línea dentro de la tarjeta. Antes el badge de
                             dinero vivía en la esquina inferior derecha y se
                             encimaba con el precio; ahora comparten línea por
                             prioridad y no pueden chocar. Con el zoom lejos se
                             apagan por niveles: alejarse quita ruido, no deja
                             manchas ilegibles. -->
                                    <span
                                        v-if="
                                            nodeDensity !== 'min' &&
                                            blockBadge(data)
                                        "
                                        class="absolute -top-2 left-1/2 inline-flex -translate-x-1/2 items-center gap-1 rounded-full bg-slate-950 px-2 py-0.5 text-[9px] font-semibold text-white shadow-lg"
                                        :title="blockBadge(data) ?? ''"
                                    >
                                        <Lucide icon="Wrench" class="h-3 w-3" />
                                        {{ blockLabel(data) }}
                                    </span>
                                    <span
                                        v-else-if="
                                            nodeDensity !== 'min' &&
                                            data.usage_locked
                                        "
                                        class="absolute -top-2 left-1/2 inline-flex -translate-x-1/2 items-center gap-1 rounded-full bg-slate-950 px-2 py-0.5 text-[9px] font-semibold text-white shadow-lg"
                                        :title="usageBadgeTitle(data)"
                                    >
                                        <Lucide icon="Lock" class="h-3 w-3" />
                                        Bloqueada
                                    </span>
                                    <span
                                        v-if="
                                            nodeDensity === 'full' &&
                                            data.upcoming_reservation
                                                ?.starts_today
                                        "
                                        class="absolute -top-2 -left-2 inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-info px-1.5 text-[10px] font-semibold text-white shadow-lg"
                                        title="Llega hoy"
                                    >
                                        <Lucide
                                            icon="CalendarDays"
                                            class="h-3.5 w-3.5"
                                        />
                                    </span>
                                    <!-- Consumos o usos: en 120px de ancho no caben junto
                             al badge de bloqueo, así que le ceden el lugar. -->
                                    <template
                                        v-if="
                                            nodeDensity === 'full' &&
                                            !showsBlockBadge(data)
                                        "
                                    >
                                        <span
                                            v-if="
                                                (data.active_stay
                                                    ?.consumos_total ?? 0) > 0
                                            "
                                            class="absolute -top-2 -right-2 rounded-full bg-slate-950 px-2 py-1 text-[10px] font-semibold text-white shadow-lg"
                                            :title="`Consumos cargados: ${formatMoney(data.active_stay?.consumos_total)}`"
                                        >
                                            {{
                                                formatMoney(
                                                    data.active_stay
                                                        ?.consumos_total,
                                                ).replace('.00', '')
                                            }}
                                        </span>
                                        <span
                                            v-else-if="
                                                data.usage_count > 0 ||
                                                data.usage_limit
                                            "
                                            class="absolute -top-2 -right-2 inline-flex items-center gap-1 rounded-full bg-slate-950 px-2 py-1 text-[10px] font-semibold text-white shadow-lg"
                                            :title="usageBadgeTitle(data)"
                                        >
                                            <Lucide
                                                icon="Repeat"
                                                class="h-3 w-3"
                                            />
                                            {{ data.usage_count
                                            }}<template v-if="data.usage_limit"
                                                >/{{
                                                    data.usage_limit
                                                }}</template
                                            >
                                        </span>
                                    </template>
                                    <!-- Punto de atención: cuando el zoom se lleva
                                     las palabras, esto es lo único que sigue
                                     diciendo "aquí hay algo que hacer". Crece
                                     al alejarse para conservar su tamaño en
                                     pantalla. Va blanco con anillo rojo: en
                                     una tarjeta ocupada (roja) un punto rojo
                                     desaparecía justo donde más se ocupa. -->
                                    <span
                                        v-if="
                                            nodeDensity !== 'full' &&
                                            attentionLabel(data)
                                        "
                                        class="absolute -top-1 -right-1 rounded-full bg-white ring-2 ring-danger"
                                        :style="{
                                            width: 'var(--room-alert-size, 10px)',
                                            height: 'var(--room-alert-size, 10px)',
                                        }"
                                        :title="attentionLabel(data) ?? ''"
                                    />
                                    <!-- Icono del tipo arriba y centrado, con el
                                     número debajo: el nombre del tipo se
                                     pierde al alejarse, el icono no. Los dos
                                     compensan el zoom. -->
                                    <Lucide
                                        v-if="showsTypeIcon(data)"
                                        :icon="data.room_type_icon"
                                        class="shrink-0 stroke-[2.5]"
                                        :style="{
                                            width: 'var(--room-icon-size, 12px)',
                                            height: 'var(--room-icon-size, 12px)',
                                        }"
                                        :title="data.room_type ?? ''"
                                    />
                                    <!-- leading-none: los números no tienen
                                     descendentes y ese 25% de interlineado es
                                     justo lo que le falta a la tarjeta cuando
                                     icono y número crecen para compensar el
                                     zoom. -->
                                    <span
                                        class="leading-none font-bold"
                                        :style="{
                                            fontSize:
                                                'var(--room-number-size, 18px)',
                                        }"
                                        >{{ data.number }}</span
                                    >
                                    <!-- El tipo solo de cerca: a media distancia
                                     ya no se lee y solo ensucia la tarjeta. -->
                                    <span
                                        v-if="nodeDensity === 'full'"
                                        class="text-[10px] leading-tight opacity-90"
                                        >{{ data.room_type }}</span
                                    >
                                    <span
                                        v-if="nodeDensity !== 'min'"
                                        class="inline-flex max-w-full items-center gap-1 rounded-full px-2 py-0.5 text-[9px] leading-tight font-medium"
                                        :class="[
                                            bottomBand(data).tone,
                                            // Con el número grande del zoom lejano
                                            // el aire de arriba ya no cabe.
                                            nodeDensity === 'full'
                                                ? 'mt-1'
                                                : '',
                                        ]"
                                        :title="bottomBand(data).title"
                                    >
                                        <Lucide
                                            v-if="bottomBand(data).icon"
                                            :icon="
                                                bottomBand(data).icon ?? 'Info'
                                            "
                                            class="h-2.5 w-2.5 shrink-0"
                                        />
                                        <span class="truncate">{{
                                            bottomBand(data).text
                                        }}</span>
                                    </span>
                                    <span
                                        v-if="data.zone_color"
                                        class="pointer-events-none absolute inset-x-0 bottom-0 h-[3px] rounded-b-lg"
                                        :style="{
                                            backgroundColor: data.zone_color,
                                        }"
                                    />
                                </div>
                            </template>
                        </VueFlow>

                        <!-- Controles de zoom del modo presentación: los del
                         paquete son diminutos para un dedo. Se quitan cuando
                         sube la hoja de acciones para no estorbarla. -->
                        <div
                            v-if="presenting && !actionSheetRoom"
                            class="absolute bottom-4 left-4 z-30 flex flex-col gap-2"
                        >
                            <button
                                type="button"
                                class="flex h-12 w-12 items-center justify-center rounded-full bg-white text-slate-600 shadow-lg transition hover:bg-slate-100 dark:bg-darkmode-600 dark:text-slate-200 dark:hover:bg-darkmode-400"
                                title="Acercar"
                                aria-label="Acercar"
                                @click="zoomIn()"
                            >
                                <Lucide icon="Plus" class="h-5 w-5" />
                            </button>
                            <button
                                type="button"
                                class="flex h-12 w-12 items-center justify-center rounded-full bg-white text-slate-600 shadow-lg transition hover:bg-slate-100 dark:bg-darkmode-600 dark:text-slate-200 dark:hover:bg-darkmode-400"
                                title="Alejar"
                                aria-label="Alejar"
                                @click="zoomOut()"
                            >
                                <Lucide icon="Minus" class="h-5 w-5" />
                            </button>
                            <button
                                type="button"
                                class="flex h-12 w-12 items-center justify-center rounded-full bg-white text-slate-600 shadow-lg transition hover:bg-slate-100 dark:bg-darkmode-600 dark:text-slate-200 dark:hover:bg-darkmode-400"
                                title="Ver el plano completo"
                                aria-label="Ver el plano completo"
                                @click="fitPlan()"
                            >
                                <Lucide icon="Scan" class="h-5 w-5" />
                            </button>
                        </div>
                    </div>

                    <!-- Contador del módulo Plano operativo. Cuelga del mismo
                         contenedor del canvas, así que viaja con el Teleport a
                         pantalla completa sin volver a montarse. Lo demás vive
                         en el modal de la habitación y en el de caja: una
                         tarjeta por cuarto era hablar del mismo sujeto dos
                         veces. -->
                    <PlanDock
                        v-if="planPanelsEnabled"
                        :counts="panelCounts"
                        :total="rooms.length"
                        :active-status="statusFilter"
                        @filter="filterByStatus"
                    />
                </div>

                <!-- Acciones sin salir del plano: el tap abre esta hoja (el
                     pulgar llega abajo) y la ficha completa queda a un clic. -->
                <RoomActionSheet
                    :room="actionSheetRoom"
                    :can-manage-reservations="canManageReservations"
                    :manual-checkin-allowed="manualCheckinAllowed"
                    :arrival-actions="arrivalActions"
                    :busy-action="busyAction"
                    :saving="saving"
                    :transitions="sheetTransitions"
                    :can-charge-consumption="canChargeConsumption"
                    @close="closeRoom"
                    @status="sheetStatus"
                    @ficha="openFichaFromSheet"
                    @arrival="
                        (key) =>
                            selectedRoom && dispatchArrival(key, selectedRoom)
                    "
                    @checkin="sheetCheckIn"
                    @checkout="sheetCheckOut"
                    @extend="sheetExtend"
                    @move="sheetMove"
                    @pos="sheetPos"
                />
            </div>
        </Teleport>

        <!-- Buscar, filtrar y saltar de zona sin ensuciar la barra. -->
        <PlanFiltersDialog
            v-model:search="search"
            v-model:status="statusFilter"
            v-model:type="typeFilter"
            :open="filtersOpen"
            :zones="zoneOptions"
            :status-options="statusOptions"
            :type-options="typeOptions"
            :active-zone="zoneJump"
            :matching="matchingRooms.length"
            :total="rooms.length"
            :filters-active="filtersActive"
            @close="filtersOpen = false"
            @zone="jumpToZone"
            @clear="clearFilters"
        />

        <!-- Caja del turno: no es de una habitación, así que va por su lado. -->
        <CashDialog
            :open="cashOpen"
            @close="cashOpen = false"
            @error="(message) => toast.error('Caja del turno', message)"
        />

        <!-- Cuenta final y cobro al registrar la salida. -->
        <CheckoutDialog
            :open="checkoutRoom !== null"
            :room-number="checkoutRoom?.number ?? ''"
            :guest-name="checkoutRoom?.active_stay?.guest_name ?? null"
            :folio="roomFolio"
            :busy="checkoutBusy"
            :damage-catalog="damageCatalog"
            :can-blacklist="
                Boolean(
                    checkoutRoom?.active_stay?.vehicle_id ||
                    checkoutRoom?.active_stay?.guest_id,
                )
            "
            @damage="addDamage"
            @close="
                checkoutRoom = null;
                backToRoom();
            "
            @confirm="confirmCheckout"
        />

        <!-- Vista de lista: la de default en móvil (dentro de una página con
             scroll, arrastrar y hacer zoom en el canvas es un castigo; el
             plano táctil vive en el modo presentación). Abre la misma ficha. -->
        <div class="mt-4 lg:hidden">
            <div class="mb-3 flex items-center justify-end gap-2">
                <span class="text-xs text-slate-500">Agrupar por</span>
                <FormSelect
                    v-model="groupBy"
                    class="w-36"
                    aria-label="Agrupar la lista"
                >
                    <option value="">Número</option>
                    <option value="zone">Zona o piso</option>
                    <option value="type">Tipo</option>
                </FormSelect>
            </div>
        </div>
        <div
            v-for="group in groupedRooms"
            :key="group.title || 'all'"
            class="mt-4 space-y-2.5 first-of-type:mt-0 lg:hidden"
        >
            <div
                v-if="group.title"
                class="flex items-center gap-2 px-1 text-xs font-medium tracking-wide text-slate-500 uppercase"
            >
                <Lucide
                    :icon="groupBy === 'type' ? 'BedDouble' : 'MapPin'"
                    class="h-3.5 w-3.5"
                />
                {{ group.title }}
                <span class="text-slate-400">({{ group.rooms.length }})</span>
            </div>
            <button
                v-for="room in group.rooms"
                :key="room.id"
                type="button"
                class="box box--stacked flex w-full items-center gap-3 p-3.5 text-left transition active:scale-[0.99]"
                @click="selectedId = room.id"
            >
                <span
                    class="flex h-10 w-10 shrink-0 flex-col items-center justify-center rounded-lg text-white shadow-sm"
                    :class="statusStyles[room.color]?.bg ?? 'bg-slate-400'"
                >
                    <span class="text-sm leading-none font-bold">{{
                        room.number
                    }}</span>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-1.5">
                        <span class="text-sm font-medium">{{
                            room.label
                        }}</span>
                        <span
                            v-if="room.blocks.length"
                            class="inline-flex items-center gap-1 rounded-full bg-slate-950 px-2 py-0.5 text-[10px] font-medium text-white"
                        >
                            <Lucide icon="Wrench" class="h-3 w-3" />
                            {{ blockLabel(room) }}
                        </span>
                        <span
                            v-if="room.usage_count > 0 || room.usage_limit"
                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium"
                            :class="
                                room.usage_locked
                                    ? 'bg-danger/10 text-danger'
                                    : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400 dark:text-slate-300'
                            "
                            :title="usageBadgeTitle(room)"
                        >
                            <Lucide
                                :icon="room.usage_locked ? 'Lock' : 'Repeat'"
                                class="h-3 w-3"
                            />
                            {{ room.usage_count
                            }}<template v-if="room.usage_limit"
                                >/{{ room.usage_limit }}</template
                            >
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
                        <span
                            v-if="(room.active_stay?.balance_due ?? 0) > 0"
                            class="rounded-full bg-danger/10 px-2 py-0.5 text-[10px] font-medium text-danger"
                            >Debe
                            {{
                                formatMoney(
                                    room.active_stay?.balance_due,
                                ).replace('.00', '')
                            }}</span
                        >
                        <span
                            v-else-if="
                                room.upcoming_reservation?.payment_overdue
                            "
                            class="rounded-full bg-danger/10 px-2 py-0.5 text-[10px] font-medium text-danger"
                            >Pago vencido</span
                        >
                    </span>
                    <span
                        class="mt-0.5 block truncate text-xs text-slate-500 dark:text-slate-400"
                    >
                        {{ room.room_type ?? 'Sin tipo' }} ·
                        {{ nodeHint(room) }}
                    </span>
                </span>
                <Lucide
                    icon="ChevronRight"
                    class="h-4 w-4 shrink-0 text-slate-300"
                />
            </button>
        </div>

        <p
            v-if="!matchingRooms.length"
            class="box box--stacked mt-4 p-8 text-center text-sm text-slate-500 lg:hidden"
        >
            Ninguna habitación coincide con la búsqueda.
        </p>

        <p v-if="errorMessage" class="mt-3 text-sm text-danger">
            {{ errorMessage }}
        </p>

        <!-- Registro exprés (modo motel): vende y cobra sin salir del plano -->
        <!-- Registro de limpieza (módulo limpieza): iniciar y terminar sin
             salir del plano, con camarista y checklist. -->
        <CleaningDialogs
            v-if="housekeepingEnabled"
            :start-room="startCleaningRoom"
            :close-room="closeCleaningRoom"
            :housekeepers="housekeepers"
            :checklist="cleaningChecklist"
            :linens="cleaningLinens"
            :kinds="cleaningKinds"
            @saved="onCleaningSaved"
            @close="
                startCleaningRoom = null;
                closeCleaningRoom = null;
            "
        />

        <!-- Llegada de reserva con fianza: el plano no puede registrarla a
             ciegas o el depósito se pierde -->
        <CheckInGuaranteeDialog
            :open="checkInRoom !== null"
            :room="checkInRoom"
            :amount="checkInRoom?.upcoming_reservation?.guarantee_amount ?? 0"
            @close="checkInRoom = null"
            @confirm="confirmCheckInWithGuarantee"
        />

        <ExpressCheckInModal
            :open="expressOpen"
            :room="selectedRoom"
            :guarantee-amount="guaranteeAmount"
            :collector-default="isMotel ? 'encargado' : 'caseta'"
            @close="
                expressOpen = false;
                backToRoom();
            "
            @registered="onExpressRegistered"
        />

        <!-- Llegó sin reserva: registro completo sin salir del plano -->
        <WalkInModal
            :open="walkInOpen"
            :room="selectedRoom"
            :guarantee-amount="guaranteeAmount"
            :charge-on-checkin="walkinChargeOnCheckin"
            @close="
                walkInOpen = false;
                backToRoom();
            "
            @registered="onWalkInRegistered"
        />

        <!-- Reserva para otra fecha, también sin salir -->
        <ReserveModal
            :open="reserveOpen"
            :room="selectedRoom"
            @close="
                reserveOpen = false;
                backToRoom();
            "
            @reserved="onReserved"
        />

        <!-- Extender estancia -->
        <Dialog :open="extending !== null" @close="extending = null">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="text-center">
                        <div
                            class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="CalendarPlus" class="h-6 w-6" />
                        </div>
                        <h2 class="text-base font-medium">
                            Extender la estancia de la
                            {{ extending?.number }}
                        </h2>
                        <p class="mt-2 text-xs text-slate-500">
                            Ahora sale el
                            {{ extending?.active_stay?.planned_end_at }}. Lo que
                            ya pagó no se toca: la diferencia se le cobra al
                            registrar su salida.
                        </p>
                    </div>

                    <div class="mt-4">
                        <label
                            for="extend-until"
                            class="mb-1.5 block text-xs text-slate-500"
                            >Nueva salida</label
                        >
                        <FormDateTime id="extend-until" v-model="extendUntil" />
                    </div>

                    <p
                        v-if="extendError"
                        class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                    >
                        {{ extendError }}
                    </p>

                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-10 text-xs"
                            @click="extending = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="primary"
                            class="h-10 text-xs"
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
                            class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="ArrowRightLeft" class="h-6 w-6" />
                        </div>
                        <h2 class="text-base font-medium">
                            Mover al huésped de la {{ moving?.number }}
                        </h2>
                        <p class="mt-2 text-xs text-slate-500">
                            Su cuenta y sus consumos se van con él. La
                            {{ moving?.number }} queda marcada por limpiar.
                        </p>
                    </div>

                    <div class="mt-4">
                        <label
                            for="move-target"
                            class="mb-1.5 block text-xs text-slate-500"
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
                            class="mt-2 text-xs text-warning"
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
                        class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                    >
                        {{ moveError }}
                    </p>

                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-10 text-xs"
                            @click="moving = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="primary"
                            class="h-10 text-xs"
                            :disabled="moveBusy || !moveTargetId"
                            @click="submitMove"
                            >{{ moveBusy ? 'Moviendo…' : 'Mover' }}</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- LA superficie de una habitación: un modal con tabs. Antes la
             ficha vivía aquí dentro, con 1.200 líneas de scroll. -->
        <RoomDialog />

        <!-- Segundo momento de la caseta: regresó el papel con la placa. -->
        <CompleteArrivalDialog
            :open="arrivalRoom !== null"
            :room="arrivalRoom"
            :pending="roomFolio?.lodging_pending ?? 0"
            @close="
                arrivalRoom = null;
                backToRoom();
            "
            @done="onArrivalCompleted"
            @error="(message) => toast.error('No se pudo completar', message)"
        />

        <!-- Alta desde la barra: mismo formulario corto que el tab Cuarto. -->
        <NewRoomDialog
            :open="newRoomOpen"
            :room-types="roomTypes"
            :zones="zones"
            :busy="busyAction !== null || saving"
            :seed="
                selectedRoom
                    ? {
                          room_type_id: selectedRoom.room_type_id,
                          zone_id: selectedRoom.zone_id,
                      }
                    : null
            "
            @close="newRoomOpen = false"
            @create="
                (payload) => {
                    newRoomOpen = false;
                    panelCreateRoom(payload);
                }
            "
        />
    </RazeLayout>
</template>
