// Contraste de colores para la apariencia de las páginas públicas: el mismo
// cálculo decide el texto automático de los botones y los avisos de
// /reservas/ajustes (WCAG 2.1: 4.5 texto normal, 3 texto grande).

function channel(v: number): number {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
}

export function relativeLuminance(hex: string): number {
    const n = parseInt(hex.replace('#', ''), 16);
    if (Number.isNaN(n)) return 0;
    return (
        0.2126 * channel((n >> 16) & 255) +
        0.7152 * channel((n >> 8) & 255) +
        0.0722 * channel(n & 255)
    );
}

export function contrastRatio(a: string, b: string): number {
    const la = relativeLuminance(a);
    const lb = relativeLuminance(b);
    const [hi, lo] = la > lb ? [la, lb] : [lb, la];
    return (hi + 0.05) / (lo + 0.05);
}

// Texto de los botones llenos: el elegido, o en 'auto' el que más contraste
// haga con el acento (un acento amarillo con letra blanca no se lee).
export function onAccentColor(accent: string, setting?: string | null): string {
    if (setting && setting !== 'auto') return setting;
    return contrastRatio(accent, '#ffffff') >= contrastRatio(accent, '#0f172a')
        ? '#ffffff'
        : '#0f172a';
}

export const TITLE_FONT_STACKS: Record<string, string> = {
    default: 'inherit',
    serif: "Georgia, 'Times New Roman', Times, serif",
    rounded:
        "ui-rounded, 'SF Pro Rounded', 'Nunito', 'Varela Round', 'Segoe UI', system-ui, sans-serif",
    condensed:
        "'Arial Narrow', 'Roboto Condensed', 'Helvetica Neue', Arial, sans-serif",
};
