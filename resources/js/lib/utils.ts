import type { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}

/**
 * Minutos a texto legible: hasta una hora se dicen en minutos, de ahí en
 * horas y, si se pasa del día, en días. Un promedio de despacho de 30112
 * minutos se leía como "30112 min" y no decía nada.
 */
export function durationLabel(
    minutes: number | null | undefined,
    empty = 'Sin datos',
): string {
    if (minutes === null || minutes === undefined || Number.isNaN(minutes)) {
        return empty;
    }

    const total = Math.max(0, Math.round(minutes));

    if (total < 60) {
        return `${total} min`;
    }

    const hours = Math.floor(total / 60);
    const restMinutes = total % 60;

    if (hours < 24) {
        return restMinutes ? `${hours} h ${restMinutes} min` : `${hours} h`;
    }

    const days = Math.floor(hours / 24);
    const restHours = hours % 24;

    return restHours ? `${days} d ${restHours} h` : `${days} d`;
}
