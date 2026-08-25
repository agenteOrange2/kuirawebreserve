import type { Icon } from '@/components/Base/Lucide';

/**
 * Colores del semáforo y etiquetas de las transiciones manuales. Compartidos
 * por el plano, el modal de habitación y sus tabs: un cuarto tiene que verse
 * del mismo color en todas partes.
 */
export const statusStyles: Record<
    string,
    { bg: string; ring: string; dot: string; soft: string }
> = {
    green: {
        bg: 'bg-success',
        ring: 'ring-success/40',
        dot: 'bg-success',
        soft: 'bg-success/10 text-success',
    },
    cyan: {
        bg: 'bg-info',
        ring: 'ring-info/40',
        dot: 'bg-info',
        soft: 'bg-info/10 text-info',
    },
    // Ocupada va en rojo y NO en el color de marca: `primary` lo elige cada
    // hotel en Apariencia, y en cuanto alguien pone un azul o un vino oscuro
    // queda idéntico al gris de mantenimiento. El rojo del theme no depende
    // del tenant y además es lo que un mostrador espera de "ocupada".
    red: {
        bg: 'bg-danger',
        ring: 'ring-danger/40',
        dot: 'bg-danger',
        soft: 'bg-danger/10 text-danger dark:bg-danger/20 dark:text-slate-200',
    },
    orange: {
        bg: 'bg-pending',
        ring: 'ring-pending/40',
        dot: 'bg-pending',
        soft: 'bg-pending/10 text-pending',
    },
    blue: {
        bg: 'bg-warning',
        ring: 'ring-warning/40',
        dot: 'bg-warning',
        soft: 'bg-warning/10 text-warning',
    },
    gray: {
        bg: 'bg-dark',
        ring: 'ring-dark/40',
        dot: 'bg-dark',
        soft: 'bg-dark/10 text-dark dark:bg-darkmode-400 dark:text-slate-300',
    },
};

export const statusLabels: Record<string, { label: string; color: string }> = {
    available: { label: 'Disponible', color: 'green' },
    reserved: { label: 'Reservada', color: 'cyan' },
    occupied: { label: 'Ocupada', color: 'red' },
    dirty: { label: 'Por limpiar', color: 'orange' },
    cleaning: { label: 'En limpieza', color: 'blue' },
    maintenance: { label: 'Mantenimiento', color: 'gray' },
};

export type TransitionVariant =
    | 'success'
    | 'outline-primary'
    | 'outline-danger'
    | 'outline-warning'
    | 'outline-secondary';

export const transitionMeta: Record<
    string,
    { icon: Icon; label: string; variant: TransitionVariant }
> = {
    available: {
        icon: 'CircleCheck',
        label: 'Marcar disponible',
        variant: 'success',
    },
    reserved: {
        icon: 'CalendarClock',
        label: 'Marcar reservada',
        variant: 'outline-primary',
    },
    occupied: {
        icon: 'DoorOpen',
        label: 'Marcar ocupada',
        variant: 'outline-danger',
    },
    dirty: {
        icon: 'Paintbrush',
        label: 'Marcar por limpiar',
        variant: 'outline-warning',
    },
    cleaning: {
        icon: 'Sparkles',
        label: 'Iniciar limpieza',
        variant: 'outline-primary',
    },
    maintenance: {
        icon: 'Wrench',
        label: 'A mantenimiento',
        variant: 'outline-secondary',
    },
};
