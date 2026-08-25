import type { ComputedRef, InjectionKey, Ref } from 'vue';
import type { ArrivalAction, CheckoutFolio, RoomData } from './types';

/**
 * Lo que el plano le ofrece al modal de habitación y a sus tabs.
 *
 * Se pasa por `provide`/`inject` y no por props porque son cuatro tabs que
 * necesitan lo mismo: con props serían veinte atravesando tres niveles. Y
 * `provide` viaja por el árbol de COMPONENTES, no por el DOM, así que sobrevive
 * al `<Teleport>` de pantalla completa sin lógica extra.
 *
 * Los tabs no deciden nada por su cuenta: llaman al handler del plano y así
 * heredan el toast, el candado de "acción en curso" y el refresco que ya usan
 * la hoja de acciones y el resto de la página. Misma regla que RoomActionSheet.
 */
export interface FloorPlanContext {
    /** El cuarto abierto en el modal. */
    room: ComputedRef<RoomData | null>;
    /** Reloj único de la página, para las cuentas regresivas. */
    nowMs: Ref<number>;
    /**
     * Cuenta de la estancia abierta: lo consumido, lo pagado y lo que falta.
     * La pide el plano una sola vez al abrir el cuarto — los tabs y el diálogo
     * de salida miran la misma, para que no digan cifras distintas.
     */
    folio: Ref<CheckoutFolio | null>;
    folioLoading: Ref<boolean>;
    reloadFolio: () => Promise<void>;

    // Permisos y estado, ya resueltos por el plano.
    canManage: boolean;
    canManageReservations: boolean;
    canViewDocuments: boolean;
    /** Puede abrir la cuenta de una estancia (folio); exige ver reservas. */
    canViewStays: boolean;
    manualCheckinAllowed: boolean;
    /**
     * Permiso Y módulo del punto de venta: habilita el atajo "Cargar consumo",
     * que navega a /pos. Existe desde antes del módulo del plano operativo.
     */
    canChargeConsumption: ComputedRef<boolean>;
    /**
     * Cobrar DESDE el plano (el tab de consumos). Exige además el módulo
     * `plano-operativo`: sin él, el plano se comporta como antes y el consumo
     * se carga en la página del POS.
     */
    canChargeHere: ComputedRef<boolean>;
    /** Permiso para dar de alta, editar y quitar habitaciones. */
    canManageRooms: ComputedRef<boolean>;
    /** Candado "Editar plano" abierto. */
    editMode: Ref<boolean>;
    /** ¿Este usuario puede abrir el candado? */
    canToggleEdit: ComputedRef<boolean>;
    busyAction: Ref<string | null>;
    saving: Ref<boolean>;

    // Catálogos y ajustes que necesitan los tabs.
    propertyId: number;
    roomTypes: { id: number; name: string }[];
    zones: { id: number; name: string }[];
    /** Tipos de falla para reportar una incidencia (vacío sin el módulo). */
    incidentCategories: { key: string; label: string }[];
    /** Motel puro cobra al entregar; hotel carga a la cuenta. */
    chargeToRoomByDefault: ComputedRef<boolean>;
    /**
     * ¿Existe el crédito a la habitación? En un motel NO: se cobra al
     * entregar, en efectivo o terminal. En hotel y en "ambos" sí, y ahí decide
     * quien atiende.
     */
    roomCreditEnabled: ComputedRef<boolean>;
    /** Caminos de venta de un cuarto libre según el modo de operación. */
    arrivalActions: ComputedRef<ArrivalAction[]>;
    /** Transiciones de limpieza y mantenimiento que autoriza el servidor. */
    transitions: ComputedRef<{ status: string; label: string; icon: string }[]>;

    // Acciones. Todas viven en el plano.
    changeStatus: (room: RoomData, status: string) => void;
    dispatchArrival: (key: string, room: RoomData) => void;
    checkInReservation: (room: RoomData) => void;
    requestCheckout: (room: RoomData) => void;
    openExtend: (room: RoomData) => void;
    openMove: (room: RoomData) => void;
    openPos: (stayId: number) => void;
    openReservationDetail: (reservationId: number) => void;
    createRoom: (payload: RoomFormPayload) => void;
    updateRoom: (payload: RoomFormPayload) => void;
    duplicateRoom: () => void;
    deleteRoom: () => void;
    /** Consumo cargado o cobrado desde el tab: toast y refresco. */
    onSold: (message: string) => void;
    /** Cancelar una venta de la estancia (devuelve la mercancía al inventario). */
    voidOrder: (orderId: number) => Promise<void>;
    /** Caseta de motel: terminar de capturar la llegada y marcar el cobro. */
    completeArrival: (room: RoomData) => void;
    /** Levantar una incidencia de mantenimiento sobre este cuarto. */
    reportIncident: (payload: {
        title: string;
        category: string | null;
        priority: string;
        description: string | null;
        source: string;
        set_maintenance: boolean;
        photo: File | null;
    }) => Promise<void>;
    /** Programar mantenimiento: bloquea fechas sin tocar el semáforo de hoy. */
    createBlock: (payload: {
        starts_at: string;
        ends_at: string;
        reason: string | null;
    }) => Promise<void>;
    deleteBlock: (blockId: number) => Promise<void>;
    /** Contador de usos a cero y candado de rotación fuera (motel). */
    resetUsage: () => Promise<void>;
    onError: (message: string) => void;
    close: () => void;
}

export interface RoomFormPayload {
    number: string;
    room_type_id: number;
    zone_id: number | null;
}

export const FloorPlanKey: InjectionKey<FloorPlanContext> =
    Symbol('floorPlanContext');
