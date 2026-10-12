/**
 * Cortesía (módulo cortesias): lo que manda Courtesy::toPayload().
 */
export type CourtesyKind = 'full' | 'percent' | 'amount';

export type CourtesyStatus =
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'canceled'
    | 'revoked';

export interface CourtesyPayload {
    id: number;
    status: CourtesyStatus;
    status_label: string;
    kind: CourtesyKind;
    kind_label: string;
    value: number;
    lodging_base: number;
    amount: number;
    category: string;
    category_label: string;
    reason: string;
    requested_by: number | null;
    requested_by_name: string | null;
    decided_by_name: string | null;
    decided_at: string | null;
    decision_note: string | null;
    over_cap: boolean;
    created_at: string | null;
}

/** Quién puede qué, para pintar los botones sin preguntarle al servidor. */
export interface CourtesyAccess {
    enabled: boolean;
    is_owner: boolean;
    user_id: number;
    categories: Record<string, string>;
}

/** Sobre qué cuenta se da: la reserva, o la estancia de mostrador. */
export interface CourtesyTarget {
    reservation_id?: number | null;
    stay_id?: number | null;
}

export interface CourtesyForm {
    kind: CourtesyKind;
    value: string | number;
    category: string;
    reason: string;
}

export interface CourtesyPreview {
    gross: number;
    lodging_base: number;
    amount: number;
    coupon_code: string | null;
    coupon_discount: number;
    total_before: number;
    total_after: number;
    paid: number;
    refund_due: number;
    cap: number | null;
    month_used: number;
    over_cap: boolean;
    is_owner: boolean;
}

export const courtesyMoney = (value: number | string | null | undefined) =>
    new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
    }).format(Number(value ?? 0));

export const emptyCourtesyForm = (): CourtesyForm => ({
    kind: 'full',
    value: '',
    category: 'cliente_importante',
    reason: '',
});

/** Lo que el servidor pide para crear o aprobar. */
export const courtesyBody = (form: CourtesyForm) => ({
    kind: form.kind,
    value: form.kind === 'full' ? null : Number(String(form.value ?? '') || 0),
    category: form.category,
    reason: String(form.reason ?? '').trim(),
});

/** Listo para mandar: motivo escrito y valor cuando el tipo lo pide. */
export const courtesyFormReady = (form: CourtesyForm, needsReason = true) => {
    const value = Number(String(form.value ?? '') || 0);

    if (form.kind === 'percent' && (value <= 0 || value > 100)) return false;
    if (form.kind === 'amount' && value <= 0) return false;

    return !needsReason || String(form.reason ?? '').trim().length >= 4;
};

/**
 * Estimado de lo que descuenta una cortesía sobre el hospedaje, para los
 * totales de los formularios de alta. El servidor calcula la cifra exacta.
 */
export const estimateCourtesy = (form: CourtesyForm, lodging: number) => {
    const base = Math.max(0, lodging);
    const value = Number(String(form.value ?? '') || 0);
    const discount =
        form.kind === 'full'
            ? base
            : form.kind === 'percent'
              ? (base * Math.min(100, Math.max(0, value))) / 100
              : Math.min(Math.max(0, value), base);

    return Math.round(discount * 100) / 100;
};

/** Lo que viaja en el alta: nada si el bloque está apagado. */
export const courtesyPayload = (
    on: boolean,
    form: CourtesyForm,
    confirmOverCap: boolean,
) =>
    on
        ? { ...courtesyBody(form), confirm_over_cap: confirmOverCap }
        : undefined;
