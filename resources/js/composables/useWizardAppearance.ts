import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePublicTheme } from '@/composables/usePublicTheme';
import { onAccentColor, TITLE_FONT_STACKS } from '@/lib/colorContrast';

/** Apariencia elegida por el hotel en /reservas/ajustes (o defaults). */
export interface WizardAppearance {
    bg_from: string;
    bg_to: string;
    accent: string;
    theme: 'light' | 'dark' | 'auto';
    logo_url: string | null;
    // Texto sobre el degradado (nombre, pasos, pie).
    header_text?: string;
    // Títulos de las tarjetas en modo claro; null = el del theme.
    title_color?: string | null;
    // Texto de los botones llenos: 'auto' o un hex.
    button_text?: string;
    title_font?: string;
    texts?: {
        tagline: string;
        title: string;
        subtitle: string;
        search_button: string;
    };
}

/**
 * Aplica la apariencia del hotel en cualquier página pública de reserva
 * (wizard, experiencias, grupos, consulta) — UNA sola configuración para
 * todas, sin duplicar:
 *
 * - `rootStyle` va en el div raíz: gradiente de fondo inline (pisa las
 *   clases from/to-theme-*) y el acento sobreescribiendo las variables
 *   del theme, así todos los bg/text-primary y -theme-1 toman el color
 *   del hotel sin tocar el markup.
 * - `isDark` decide la piel oscura: fija (light/dark) o automática según
 *   el dispositivo del huésped, reaccionando si cambia en vivo. Ponla
 *   como clase `booking-dark` en la(s) tarjeta(s) de la página.
 * - El `dark` de <html> (variantes de los componentes Base) también se
 *   gobierna aquí vía usePublicTheme.
 */
export function useWizardAppearance(appearance: WizardAppearance) {
    const prefersDark = ref(false);
    let media: MediaQueryList | null = null;
    const onChange = (e: MediaQueryListEvent) => {
        prefersDark.value = e.matches;
    };

    onMounted(() => {
        if (appearance.theme !== 'auto' || !window.matchMedia) return;
        media = window.matchMedia('(prefers-color-scheme: dark)');
        prefersDark.value = media.matches;
        media.addEventListener('change', onChange);
    });
    onBeforeUnmount(() => media?.removeEventListener('change', onChange));

    const isDark = computed(
        () =>
            appearance.theme === 'dark' ||
            (appearance.theme === 'auto' && prefersDark.value),
    );

    usePublicTheme(isDark);

    const rootStyle = computed(() => ({
        backgroundImage: `linear-gradient(to bottom, ${appearance.bg_from}, ${appearance.bg_to})`,
        '--color-theme-1': appearance.accent,
        '--color-primary': appearance.accent,
        '--booking-header-text': appearance.header_text ?? '#ffffff',
        '--booking-bg-from': appearance.bg_from,
        '--booking-on-accent': onAccentColor(
            appearance.accent,
            appearance.button_text,
        ),
        '--booking-title-font':
            TITLE_FONT_STACKS[appearance.title_font ?? 'default'] ?? 'inherit',
        // El color de títulos es del modo claro: en la piel oscura no
        // se leería, así que ahí se queda el del theme.
        ...(appearance.title_color && !isDark.value
            ? { '--booking-title-color': appearance.title_color }
            : {}),
    }));

    return { isDark, rootStyle };
}
