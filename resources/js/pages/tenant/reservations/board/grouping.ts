/**
 * Cómo se agrupan y se marcan los renglones de las tablas de reservas. El
 * servidor ya manda la página ordenada (por llegada, por salida, por reloj)
 * y las fechas en palabras; aquí solo se parte en grupos consecutivos y se
 * decide la raya de urgencia.
 */

export type Urgency = 'danger' | 'warning' | null;

export interface RowGroup<T> {
    key: string;
    label: string;
    count: string;
    tone: 'danger' | 'warning' | 'info' | 'neutral';
    rows: T[];
}

/** Clase de la raya izquierda de la primera celda. */
export const urgencyBorder = (u: Urgency) =>
    u === 'danger'
        ? 'border-l-2 border-l-danger'
        : u === 'warning'
          ? 'border-l-2 border-l-warning'
          : 'border-l-2 border-l-transparent';

/** Parte una lista ya ordenada en grupos consecutivos por una llave. */
function consecutive<T>(
    rows: T[],
    keyOf: (row: T) => string,
    make: (key: string, rows: T[]) => Omit<RowGroup<T>, 'rows' | 'key'>,
): RowGroup<T>[] {
    const groups: { key: string; rows: T[] }[] = [];
    for (const row of rows) {
        const key = keyOf(row);
        const last = groups[groups.length - 1];
        if (last && last.key === key) last.rows.push(row);
        else groups.push({ key, rows: [row] });
    }
    return groups.map((g) => ({
        ...make(g.key, g.rows),
        key: g.key,
        rows: g.rows,
    }));
}

// ── Reservas por llegar ──

export interface ArrivalRow {
    day_key: string;
    day_label: string;
    starts_today: boolean;
    arrival_pending: boolean;
    status: string;
    hold_state?: 'expired' | 'urgent' | 'live' | null;
    payment_overdue?: boolean;
}

/**
 * Por día de llegada. La cuenta del grupo sale de `dayCounts` (todo lo
 * apartado, no la página) cuando el día cae en el horizonte de la gráfica;
 * fuera de él no se pone número para no decir "1 llega" de un día con 5.
 */
export function groupByArrivalDay<T extends ArrivalRow>(
    rows: T[],
    dayCounts: Record<string, number> = {},
): RowGroup<T>[] {
    return consecutive(
        rows,
        (r) => (r.arrival_pending && !r.starts_today ? 'atrasadas' : r.day_key),
        (key, group) => {
            if (key === 'atrasadas') {
                return {
                    label: 'Ya debían haber llegado',
                    count: `${group.length} sin registrar`,
                    tone: 'danger',
                };
            }
            const n = dayCounts[key];
            return {
                label: group[0].day_label,
                count: n ? `${n} ${n === 1 ? 'llega' : 'llegan'}` : '',
                tone: group[0].starts_today ? 'info' : 'neutral',
            };
        },
    );
}

export const arrivalUrgency = (r: ArrivalRow): Urgency =>
    r.arrival_pending || r.hold_state === 'expired' || r.payment_overdue
        ? 'danger'
        : r.starts_today || r.hold_state === 'urgent'
          ? 'warning'
          : null;

// ── Estancias en casa ──

export interface StayRowLike {
    overdue: boolean;
    departs_today: boolean;
    departs_tomorrow: boolean;
}

export const departureOf = (s: StayRowLike) =>
    s.overdue
        ? 'vencida'
        : s.departs_today
          ? 'hoy'
          : s.departs_tomorrow
            ? 'manana'
            : 'despues';

const departureLabel: Record<
    string,
    { label: string; tone: RowGroup<unknown>['tone']; verb: [string, string] }
> = {
    vencida: {
        label: 'Salida vencida',
        tone: 'danger',
        verb: ['ya debía salir', 'ya debían salir'],
    },
    hoy: { label: 'Salen hoy', tone: 'warning', verb: ['sale', 'salen'] },
    manana: { label: 'Salen mañana', tone: 'info', verb: ['sale', 'salen'] },
    despues: {
        label: 'Después',
        tone: 'neutral',
        verb: ['estancia', 'estancias'],
    },
};

/** Por corte de salida; `counts` = todo en casa, no la página. */
export function groupByDeparture<T extends StayRowLike>(
    rows: T[],
    counts: Record<string, number> = {},
): RowGroup<T>[] {
    return consecutive(rows, departureOf, (key, group) => {
        const meta = departureLabel[key];
        const n = counts[key] ?? group.length;
        return {
            label: meta.label,
            count: `${n} ${n === 1 ? meta.verb[0] : meta.verb[1]}`,
            tone: meta.tone,
        };
    });
}

export const stayUrgency = (s: StayRowLike): Urgency =>
    s.overdue ? 'danger' : s.departs_today ? 'warning' : null;

// ── Apartados por confirmar ──

export interface HoldRowLike {
    hold_state: 'expired' | 'urgent' | 'live' | null;
}

const holdKey = (r: HoldRowLike) =>
    r.hold_state === 'expired'
        ? 'vencido'
        : r.hold_state === 'urgent'
          ? 'por_vencer'
          : r.hold_state === 'live'
            ? 'en_tiempo'
            : 'sin_vencimiento';

const holdLabel: Record<
    string,
    { label: string; tone: RowGroup<unknown>['tone'] }
> = {
    vencido: { label: 'Ya vencieron', tone: 'danger' },
    por_vencer: { label: 'Vencen en menos de 30 min', tone: 'warning' },
    en_tiempo: { label: 'En tiempo', tone: 'neutral' },
    sin_vencimiento: { label: 'Sin vencimiento', tone: 'neutral' },
};

/** Por el reloj del apartado; `counts` = todos los pendientes. */
export function groupByHold<T extends HoldRowLike>(
    rows: T[],
    counts: Record<string, number> = {},
): RowGroup<T>[] {
    return consecutive(rows, holdKey, (key, group) => {
        const n = counts[key] ?? group.length;
        return {
            label: holdLabel[key].label,
            count: `${n} ${n === 1 ? 'apartado' : 'apartados'}`,
            tone: holdLabel[key].tone,
        };
    });
}

export const holdUrgency = (r: HoldRowLike): Urgency =>
    r.hold_state === 'expired'
        ? 'danger'
        : r.hold_state === 'urgent'
          ? 'warning'
          : null;
