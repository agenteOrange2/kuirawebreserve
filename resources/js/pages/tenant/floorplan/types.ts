/**
 * Formas del payload del plano (`Room::toFloorPlanPayload`). Viven aquí y no
 * dentro de FloorPlan.vue porque el modal de habitación y sus tabs son
 * componentes aparte y necesitan los mismos tipos.
 */
export interface RatePlanSummary {
    id: number;
    name: string;
    type: string;
    price: number;
    duration_minutes: number | null;
    duration_label: string;
    duration_unit: string | null;
    duration_value: number | null;
}

export interface ActiveStaySummary {
    id: number;
    guest_name: string;
    rate_plan: string | null;
    channel: string;
    amount: number;
    consumos_total: number;
    total_due: number;
    // Saldo REAL pendiente (hospedaje no pagado + consumos a habitación sin
    // liquidar): alimenta el badge "Debe $X" y el filtro Pago pendiente.
    balance_due: number;
    check_in_at: string | null;
    check_in_at_iso: string | null;
    planned_end_at: string | null;
    planned_end_at_iso: string | null;
    is_overdue: boolean;
    reservation_id: number | null;
    num_people: number;
    vehicle_plate: string | null;
    vehicle_desc: string | null;
    vehicle_id: number | null;
    guest_id: number | null;
    /** Caseta de motel: falta terminar de capturar la llegada. */
    arrival_pending: boolean;
    /** En carro o a pie, tal como lo eligió la caseta. */
    arrival_mode: 'vehicle' | 'foot' | null;
    id_document_type: string | null;
    id_document_photos: string[];
}

export interface UpcomingReservationSummary {
    id: number;
    code: string;
    guest_name: string;
    rate_plan: string | null;
    status: string;
    status_label: string;
    total_amount: number;
    payment_overdue: boolean;
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

export interface RoomBlockEntry {
    id: number;
    starts_at: string;
    ends_at: string;
    reason: string | null;
    active: boolean;
}

export interface HistoryEntry {
    id: number;
    from_status: string | null;
    from_label: string | null;
    to_status: string;
    to_label: string;
    changed_by: string | null;
    created_at: string | null;
    auto: boolean;
}

export interface RoomData {
    id: number;
    number: string;
    name: string | null;
    description: string | null;
    zone: string | null;
    zone_id: number | null;
    zone_color: string | null;
    room_type: string | null;
    room_type_id: number | null;
    room_type_photos: { id: number; url: string; thumb_url: string }[];
    /** Icono del tipo elegido en el catálogo; null = solo el número. */
    room_type_icon: string | null;
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
    // Fallas de mantenimiento sin resolver (módulo incidencias).
    incidents: {
        id: number;
        title: string;
        priority: string;
        priority_label: string;
        category_label: string | null;
        overdue: boolean;
        age_hours: number;
    }[];
    // Limpieza abierta en esta habitación (módulo limpieza).
    cleaning: {
        id: number;
        housekeeper: string | null;
        minutes: number;
    } | null;
    usage_count: number;
    usage_limit: number | null;
    usage_locked: boolean;
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

/** Caminos de venta que ofrece el modo de operación; los arma el plano. */
export interface ArrivalAction {
    key: 'express' | 'walkin' | 'reserve';
    label: string;
    hint: string;
    icon: import('@/components/Base/Lucide').Icon;
    primary: boolean;
}

/** Un consumo de la estancia (todo lo completado, cobrado o a la cuenta). */
export interface FolioConsumption {
    id: number;
    total: number;
    created_at: string;
    method: string;
    method_label: string;
    settled: boolean;
    can_void: boolean;
    summary: string;
}

/** Un pago ya hecho por el huésped (anticipo, abono, consumo o fianza). */
export interface FolioPayment {
    id: number;
    amount: number;
    kind: string;
    kind_label: string;
    method_label: string;
    reference: string | null;
    paid_at: string | null;
}

/**
 * Cuenta de una estancia (`GET /api/stays/{stay}/folio`): lo que falta cobrar,
 * lo que ya se consumió y lo que ya se pagó. La usan el modal de habitación
 * (tabs de consumos y resumen) y el diálogo de salida.
 */
export interface CheckoutFolio {
    /** Quién y dónde: lo usan el PDF y el mensaje al huésped. */
    stay: {
        id: number;
        room: string | null;
        guest_name: string;
        guest_phone: string | null;
        rate_plan: string | null;
        check_in_at: string | null;
        check_out_at: string | null;
    };
    lodging_total: number;
    lodging_paid: number;
    lodging_pending: number;
    consumption_pending: number;
    grand_pending: number;
    guarantee_refundable: number;
    consumption: FolioConsumption[];
    payments: FolioPayment[];
}
