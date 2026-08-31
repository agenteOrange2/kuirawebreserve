/**
 * Tipos compartidos de la pantalla de reservas. Viven aparte para que
 * los componentes extraídos de Index.vue no tengan que redeclararlos ni
 * importarse entre sí.
 */

export interface PaymentRow {
    id: number;
    amount: string;
    method: string;
    reference: string | null;
    paid_at: string;
    received_by: string | null;
    refunded: number;
    refundable: number;
    via_gateway: boolean;
}

export interface ExtraChargeLine {
    concept: string;
    amount: number;
    kind: string;
}

// Líneas congeladas del wizard: productos POS y add-ons del módulo extras.
export interface FrozenLine {
    name: string;
    qty: number;
    total: number;
}

// Experiencia comprada como plus de la reserva (reserva EXP- ligada).
export interface ExperienceLine {
    code: string | null;
    name: string;
    starts_at: string;
    people: number;
    total: number;
}

export interface OptionalChargeOption {
    concept: string;
    amount: number;
}

// Ficha de cobros del cuarto (viene en /api/availability y en el prefill).
export interface RoomChargeInfo {
    included_occupancy?: number | null;
    extra_guest_fee?: number | null;
    optional_charges?: OptionalChargeOption[];
}

export interface ReservationRow {
    id: number;
    code: string;
    guest_id: number | null;
    guest_name: string | null;
    guest_phone: string | null;
    guest_email: string | null;
    num_people: number;
    adults: number;
    children: number;
    vehicle_plate: string | null;
    vehicle_desc: string | null;
    eta: string | null;
    room: string | null;
    room_id: number | null;
    room_type: string | null;
    rate_plan: string | null;
    rate_plan_id: number;
    starts_at: string;
    starts_at_input: string;
    ends_at: string;
    ends_at_input: string;
    status: string;
    status_label: string;
    hold_expires_at: string | null;
    hold_expires_at_iso: string | null;
    total_amount: string;
    coupon_code: string | null;
    discount_amount: number;
    extra_charges: ExtraChargeLine[];
    products: FrozenLine[];
    extras: FrozenLine[];
    experiences: ExperienceLine[];
    starts_today: boolean;
    source_channel: string;
    notes: string | null;
    guest_notes: string | null;
    cancellation_reason: string | null;
    deposit_amount: string;
    payment_status: string;
    payment_status_label: string;
    pending_transfer_request: boolean;
    payment_due_at: string | null;
    payment_overdue: boolean;
    paid_total: number;
    pending_balance: number;
    // Fianza que le toca a ESTA reserva: con escalones por volumen, una del
    // mismo grupo GRP- no paga lo mismo que una suelta.
    guarantee_amount: number;
    payment_request: {
        id: number;
        concept: string;
        amount_label: string;
        method: string;
        provider_label: string | null;
        checkout_url: string | null;
        public_url: string;
        status_label: string;
        expires_label: string | null;
    } | null;
    payments: PaymentRow[];
    refunded_total: number;
    refund_suggestion: {
        amount: number;
        amount_label: string;
        policy_label: string | null;
    } | null;
    updated_at: string | null;
    timeline: {
        id: string;
        message: string;
        by: string | null;
        at: string | null;
    }[];
}
