import { tone } from './chartColors';

/**
 * Los cortes que comparten cada lista y su reporte: mismo nombre, misma
 * explicación y mismo color en el chip, en la dona y en el filtro.
 * Las llaves son las de la URL (?pago=, ?salida=, ?estado=).
 */

export type PaymentKey =
    | 'sin_pago'
    | 'parcial'
    | 'anticipo'
    | 'pagada'
    | 'vencido';
export type DepartureKey = 'vencida' | 'hoy' | 'manana' | 'despues';
export type HoldKey =
    | 'vencido'
    | 'por_vencer'
    | 'en_tiempo'
    | 'sin_vencimiento';

export interface CutMeta {
    label: string;
    hint: string;
    color: string;
}

export const paymentMeta: Record<PaymentKey, CutMeta> = {
    pagada: { label: 'Pagadas', hint: 'No deben nada', color: tone.success },
    anticipo: {
        label: 'Con anticipo',
        hint: 'Falta el resto al llegar',
        color: tone.info,
    },
    parcial: {
        label: 'Anticipo incompleto',
        hint: 'Abonaron menos del anticipo',
        color: tone.pending,
    },
    sin_pago: {
        label: 'Sin pago',
        hint: 'No han dado nada',
        color: tone.slate,
    },
    vencido: {
        label: 'Pago vencido',
        hint: 'Pasó la fecha límite del saldo',
        color: tone.danger,
    },
};

export const departureMeta: Record<DepartureKey, CutMeta> = {
    vencida: {
        label: 'Salida vencida',
        hint: 'Ya pasó su hora y siguen dentro',
        color: tone.danger,
    },
    hoy: {
        label: 'Salen hoy',
        hint: 'Lo que falta del día',
        color: tone.warning,
    },
    manana: {
        label: 'Salen mañana',
        hint: 'Para planear la limpieza',
        color: tone.info,
    },
    despues: {
        label: 'Después',
        hint: 'Estancias de varios días',
        color: tone.success,
    },
};

export const holdMeta: Record<HoldKey, CutMeta> = {
    vencido: {
        label: 'Ya vencieron',
        hint: 'El reloj pasó; se liberan en la próxima vuelta',
        color: tone.danger,
    },
    por_vencer: {
        label: 'Vencen en menos de 30 min',
        hint: 'Lo primero que hay que atender',
        color: tone.warning,
    },
    en_tiempo: {
        label: 'En tiempo',
        hint: 'Todavía aguantan',
        color: tone.success,
    },
    sin_vencimiento: {
        label: 'Sin vencimiento',
        hint: 'No se liberan solos',
        color: tone.slate,
    },
};

/** Rebanadas de dona a partir de un corte y sus cuentas. */
export const slicesOf = <K extends string>(
    meta: Record<K, CutMeta>,
    counts: Partial<Record<K, number>> | undefined,
) =>
    (Object.keys(meta) as K[]).map((key) => ({
        key,
        label: meta[key].label,
        hint: meta[key].hint,
        count: counts?.[key] ?? 0,
        color: meta[key].color,
    }));

export const money = (n: number | string | null | undefined) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(n) || 0);
