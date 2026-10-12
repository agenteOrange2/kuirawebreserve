import { formatMoney } from './bookingFormat';

/**
 * Línea del desglose que manda el servidor (RatePlan::priceBreakdown). Las
 * de temporada más barata que el precio base (descuento por semana o mes,
 * promo) traen además el precio normal para tacharlo.
 */
export interface PriceLine {
    concept: string;
    amount: number;
    units?: number;
    unit_price?: number;
    regular_unit_price?: number;
    regular_amount?: number;
}

/** Descuento por estancia larga que la búsqueda aún no alcanza. */
export interface LongStayOffer {
    min_nights: number;
    unit_price: number;
    percent: number;
    name: string;
}

function isDiscounted(line: PriceLine): boolean {
    return (
        line.regular_amount !== undefined &&
        Number(line.regular_amount) > Number(line.amount)
    );
}

/** Cuánto se ahorra contra el precio normal (0 si no hay temporada barata). */
export function breakdownSavings(lines: PriceLine[] | undefined): number {
    return (
        Math.round(
            (lines ?? []).reduce(
                (sum, line) =>
                    sum +
                    (isDiscounted(line)
                        ? Number(line.regular_amount) - Number(line.amount)
                        : 0),
                0,
            ) * 100,
        ) / 100
    );
}

/** Porcentaje de la línea con descuento más grande ("20% menos"). */
export function breakdownPercent(lines: PriceLine[] | undefined): number {
    return (lines ?? []).reduce((best, line) => {
        if (!isDiscounted(line) || !line.regular_unit_price) return best;
        const pct = Math.round(
            (1 - Number(line.unit_price) / Number(line.regular_unit_price)) *
                100,
        );
        return Math.max(best, pct);
    }, 0);
}

/**
 * Renglón de apoyo bajo la línea con descuento:
 * "30 noches × $600.00 MXN · normal $750.00 MXN".
 */
export function breakdownDetail(
    line: PriceLine,
    currency: string,
): string | null {
    if (!isDiscounted(line) || !line.units) return null;
    return `${line.units} × ${formatMoney(Number(line.unit_price), currency)} · normal ${formatMoney(Number(line.regular_unit_price), currency)}`;
}

/** "Desde 7 noches, la noche baja a $637.50 MXN (15% menos)." */
export function longStayHint(
    offers: LongStayOffer[] | undefined,
    currency: string,
): string | null {
    const next = offers?.[0];
    if (!next) return null;
    return `Desde ${next.min_nights} noches, la noche baja a ${formatMoney(next.unit_price, currency)} (${next.percent}% menos).`;
}

/**
 * Concepto sin el "(30 noches)" del final cuando el renglón de apoyo ya
 * dice "30 × $600": "Por mes (20% de descuento) (30 noches)" se leía doble.
 */
export function breakdownLabel(line: PriceLine): string {
    return isDiscounted(line) && line.units
        ? line.concept.replace(/\s*\(\d+ [^)]*\)$/, '')
        : line.concept;
}
