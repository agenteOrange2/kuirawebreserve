// Copys humanos compartidos por las páginas públicas de reserva (Wizard,
// Experiences, Groups, Lookup): el huésped lee "Del lun 4 al mar 5 de
// agosto · 1 noche", nunca "04-ago, 08:00 → 05-ago, 05:00".

// 'YYYY-MM-DD' a secas se interpreta como UTC por spec de Date; forzamos
// hora local para que el día de la semana no se corra un día.
export function parseLocalDate(iso: string): Date {
    return iso.length === 10 ? new Date(`${iso}T00:00`) : new Date(iso);
}

// timeZone: la del hotel. Sin ella manda la del navegador, y quien
// consulta desde otra zona veía la llegada corrida una o varias horas.
export function formatFriendlyDateTime(iso: string, timeZone?: string): string {
    return new Date(iso).toLocaleString('es-MX', {
        weekday: 'short',
        day: 'numeric',
        month: 'long',
        hour: 'numeric',
        minute: '2-digit',
        timeZone,
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

// "$1,250.00 MXN": el mismo formato en las cuatro páginas públicas.
export function formatMoney(n: number, currency: string): string {
    return `$${Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

// Sin centavos para ejes y etiquetas cortas de las gráficas: "$1,250".
export function formatMoneyShort(n: number): string {
    return `$${Math.round(Number(n)).toLocaleString('es-MX')}`;
}

// Referencia en la segunda divisa ("≈ $62.50 USD"). El cobro es siempre en
// la primaria; rate = cuántas de la primaria equivalen a 1 de la segunda.
export function formatSecondaryMoney(
    n: number,
    secondary: string | null | undefined,
    rate: number | null | undefined,
): string {
    if (!secondary || !rate || rate <= 0) return '';
    const converted = n / rate;
    return `≈ $${converted.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${secondary}`;
}

// "vie 9 oct" para ejes de la gráfica por noche.
export function formatShortDay(ymd: string): string {
    return parseLocalDate(ymd).toLocaleDateString('es-MX', {
        weekday: 'short',
        day: 'numeric',
    });
}

// Teléfono del huésped: México y USA/Canadá son 10 dígitos; otro país,
// entre 6 y 14 y con lada. Devuelve el error a mostrar o null.
export function phoneError(
    country: '52' | '1' | 'otro',
    number: string,
    lada: string,
): string | null {
    const digits = number.replace(/\D/g, '');
    if (!digits) return 'Escribe tu teléfono.';
    if (country !== 'otro') {
        return digits.length === 10
            ? null
            : `Deben ser 10 dígitos (llevas ${digits.length}).`;
    }
    if (!lada.replace(/\D/g, '')) return 'Escribe la lada de tu país.';
    return digits.length >= 6 && digits.length <= 14
        ? null
        : 'Revisa el número: parece incompleto.';
}

export function emailError(email: string): string | null {
    const value = email.trim();
    if (!value) return null;
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)
        ? null
        : 'Revisa el correo: le falta algo.';
}

// "+5216561234567" listo para el servidor (mismo formato que el panel).
export function internationalPhone(
    country: '52' | '1' | 'otro',
    number: string,
    lada: string,
): string {
    const digits = number.replace(/\D/g, '');
    if (!digits) return number.trim();
    const code = country === 'otro' ? lada.replace(/\D/g, '') : country;
    return code ? `+${code}${digits}` : digits;
}

// Archivo .ics para "Agregar a mi calendario": se arma en el navegador, sin
// pasar datos del huésped por ningún servicio.
export function downloadIcs(event: {
    uid: string;
    title: string;
    start: string;
    end: string;
    description?: string;
    location?: string;
}): void {
    const stamp = (iso: string) =>
        new Date(iso)
            .toISOString()
            .replace(/[-:]/g, '')
            .replace(/\.\d{3}/, '');
    const escape = (s: string) =>
        s
            .replace(/\\/g, '\\\\')
            .replace(/\n/g, '\\n')
            .replace(/[,;]/g, (m) => `\\${m}`);
    const lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//KuiraWebReserve//Reserva//ES',
        'BEGIN:VEVENT',
        `UID:${event.uid}`,
        `DTSTAMP:${stamp(new Date().toISOString())}`,
        `DTSTART:${stamp(event.start)}`,
        `DTEND:${stamp(event.end)}`,
        `SUMMARY:${escape(event.title)}`,
        event.description ? `DESCRIPTION:${escape(event.description)}` : '',
        event.location ? `LOCATION:${escape(event.location)}` : '',
        'END:VEVENT',
        'END:VCALENDAR',
    ].filter(Boolean);
    const blob = new Blob([lines.join('\r\n')], { type: 'text/calendar' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${event.uid}.ics`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

// "jue 8 de octubre" para la llegada y la salida del calendario.
export function formatLongDay(ymd: string): string {
    const d = parseLocalDate(ymd);
    const weekday = d.toLocaleDateString('es-MX', { weekday: 'short' });
    const month = d.toLocaleDateString('es-MX', { month: 'long' });
    return `${weekday} ${d.getDate()} de ${month}`;
}
