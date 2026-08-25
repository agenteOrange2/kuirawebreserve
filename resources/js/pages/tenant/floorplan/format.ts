import type { RoomData } from './types';

/**
 * Formateadores del plano. Compartidos por el plano y por el modal de
 * habitación: el mismo importe no puede escribirse de dos maneras en dos
 * pantallas que el cajero mira seguidas.
 *
 * `countdownLabel` y `stayTone` reciben el reloj (`nowMs`) en vez de leerlo de
 * un ref propio: el plano ya tiene un solo tic para toda la página, y dos
 * relojes distintos harían que la ficha y la tarjeta discrepen por un minuto.
 */
const currencyFormatter = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    maximumFractionDigits: 2,
});

export function formatMoney(value: number | string | null | undefined): string {
    return currencyFormatter.format(Number(value ?? 0));
}

export function priceModifierLabel(value: number): string {
    const sign = value < 0 ? '-' : '+';

    return `${sign}${formatMoney(Math.abs(value))}`;
}

export function guestCountLabel(adults: number, children: number): string {
    const parts = [`${adults} ${adults === 1 ? 'adulto' : 'adultos'}`];

    if (children > 0) {
        parts.push(`${children} ${children === 1 ? 'niño' : 'niños'}`);
    }

    return parts.join(' · ');
}

export function formatChannel(channel: string | null | undefined): string {
    if (!channel) {
        return 'Mostrador';
    }

    return channel
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function countdownLabel(
    iso: string | null | undefined,
    nowMs: number,
): string {
    if (!iso) {
        return 'Sin hora';
    }

    const diff = new Date(iso).getTime() - nowMs;
    const totalMinutes = Math.round(Math.abs(diff) / 60000);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    const formatted =
        hours > 0 ? `${hours} h ${minutes} min` : `${minutes} min`;

    return diff >= 0 ? `Quedan ${formatted}` : `Excedida por ${formatted}`;
}

export function stayTone(
    iso: string | null | undefined,
    nowMs: number,
): string {
    if (!iso) {
        return 'text-slate-500 dark:text-slate-400';
    }

    return new Date(iso).getTime() >= nowMs ? 'text-success' : 'text-danger';
}

/** Qué dice el contador de usos de una habitación (candado de rotación). */
export function usageBadgeTitle(room: RoomData): string {
    if (room.usage_locked) {
        return `Bloqueada por usos: llegó a ${room.usage_count} de ${room.usage_limit}. Resetea el contador en Habitaciones para liberarla.`;
    }

    return room.usage_limit
        ? `${room.usage_count} de ${room.usage_limit} usos para bloquearse`
        : `${room.usage_count} usos desde el último reseteo`;
}
