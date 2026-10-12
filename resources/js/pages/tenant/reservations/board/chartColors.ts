/**
 * Colores de las gráficas de reservas. Chart.js pinta en canvas y no lee
 * clases de Tailwind, así que los tokens del theme se leen de la variable
 * CSS (cada hotel cambia el primario en Apariencia) con un respaldo fijo,
 * igual que el dashboard.
 */
export const themeHex = (token: string, fallback: string): string => {
    if (typeof window === 'undefined') return fallback;
    const value = getComputedStyle(document.documentElement)
        .getPropertyValue(`--color-${token}`)
        .trim();

    return /^#[0-9a-f]{6}$/i.test(value) ? value : fallback;
};

/** Los tonos del semáforo del panel, en hex para el canvas. */
export const tone = {
    primary: () => themeHex('primary', '#03045e'),
    success: '#0d9488',
    info: '#0891b2',
    pending: '#c2410c',
    warning: '#ca8a04',
    danger: '#b91c1c',
    dark: '#1e293b',
    slate: '#94a3b8',
} as const;

/** Paleta para series sin color propio (canales, tipos). */
export const palette = (): string[] => [
    tone.primary(),
    tone.info,
    tone.success,
    tone.warning,
    tone.pending,
    tone.danger,
    tone.dark,
    '#0c4a6e',
    '#64748b',
    tone.slate,
];

/** Por dónde llegó la reserva, con su color fijo en todas las gráficas. */
export const channelMeta: Record<
    string,
    { label: string; color: () => string }
> = {
    web: { label: 'Sitio web', color: () => tone.primary() },
    agent: { label: 'Asistente', color: () => tone.info },
    whatsapp: { label: 'WhatsApp', color: () => tone.success },
    front_desk: { label: 'Mostrador', color: () => tone.dark },
    counter: { label: 'Mostrador', color: () => tone.dark },
    walk_in: { label: 'Llegó sin reserva', color: () => tone.pending },
    phone: { label: 'Teléfono', color: () => tone.warning },
};

export const channelLabel = (channel: string) =>
    channelMeta[channel]?.label ?? (channel || 'Sin canal');

export const channelColor = (channel: string, index: number) =>
    channelMeta[channel]?.color() ?? palette()[(index + 6) % 10];
