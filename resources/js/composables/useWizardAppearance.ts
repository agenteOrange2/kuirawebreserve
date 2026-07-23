import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePublicTheme } from '@/composables/usePublicTheme';

/** Apariencia elegida por el hotel en /reservas/ajustes (o defaults). */
export interface WizardAppearance {
    bg_from: string;
    bg_to: string;
    accent: string;
    theme: 'light' | 'dark' | 'auto';
    logo_url: string | null;
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
    }));

    return { isDark, rootStyle };
}
