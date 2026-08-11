// Copys humanos compartidos por las páginas públicas de reserva (Wizard,
// Experiences, Groups, Lookup): el huésped lee "Del lun 4 al mar 5 de
// agosto · 1 noche", nunca "04-ago, 08:00 → 05-ago, 05:00".

// 'YYYY-MM-DD' a secas se interpreta como UTC por spec de Date; forzamos
// hora local para que el día de la semana no se corra un día.
export function parseLocalDate(iso: string): Date {
    return iso.length === 10 ? new Date(`${iso}T00:00`) : new Date(iso);
}

export function formatFriendlyDateTime(iso: string): string {
    return new Date(iso).toLocaleString('es-MX', {
        weekday: 'short',
        day: 'numeric',
        month: 'long',
        hour: 'numeric',
        minute: '2-digit',
    });
}

export function nightsBetween(startIso: string, endIso: string): number {
    const start = parseLocalDate(startIso).setHours(0, 0, 0, 0);
    const end = parseLocalDate(endIso).setHours(0, 0, 0, 0);
    return Math.max(1, Math.round((end - start) / 86400000));
}

export function formatStayRange(startIso: string, endIso: string): string {
    const start = parseLocalDate(startIso);
    const end = parseLocalDate(endIso);
    const nights = nightsBetween(startIso, endIso);
    const dayName = (d: Date) =>
        d.toLocaleDateString('es-MX', { weekday: 'short' });
    const monthName = (d: Date) =>
        d.toLocaleDateString('es-MX', { month: 'long' });
    const sameMonth =
        start.getMonth() === end.getMonth() &&
        start.getFullYear() === end.getFullYear();
    const startLabel = sameMonth
        ? `${dayName(start)} ${start.getDate()}`
        : `${dayName(start)} ${start.getDate()} de ${monthName(start)}`;
    return `Del ${startLabel} al ${dayName(end)} ${end.getDate()} de ${monthName(end)} · ${nights} ${nights === 1 ? 'noche' : 'noches'}`;
}

export function humanizeMinutes(min: number): string {
    if (min < 60) return `${min} minuto${min === 1 ? '' : 's'}`;
    if (min === 90) return 'hora y media';
    const h = Math.floor(min / 60);
    const rest = min % 60;
    if (rest === 0) return `${h} hora${h === 1 ? '' : 's'}`;
    if (rest === 30) return `${h} horas y media`;
    return `${h} h ${rest} min`;
}

// "180 minutos" (crudo del backend, el panel lo usa tal cual) → "3 horas".
// Cualquier otra etiqueta ("por noche", "12 horas") pasa intacta.
export function humanizeDurationLabel(label: string): string {
    const m = label.match(/^(\d+)\s+minutos?$/);
    return m ? humanizeMinutes(Number(m[1])) : label;
}

// "15:00" → "3:00 p.m." para los avisos de entrada y salida.
export function formatTime(hhmm: string): string {
    const [h, m] = hhmm.split(':').map(Number);
    if (Number.isNaN(h)) return hhmm;
    const d = new Date();
    d.setHours(h, m || 0, 0, 0);
    return d.toLocaleTimeString('es-MX', {
        hour: 'numeric',
        minute: '2-digit',
    });
}
