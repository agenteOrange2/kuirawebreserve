import type { ComputedRef } from 'vue';
import { onBeforeUnmount, onMounted, watch } from 'vue';

/**
 * Las páginas públicas (wizard de reservas) definen su modo claro/oscuro
 * por la configuración del hotel, no por la preferencia del visitante:
 * app.ts pone `dark` en <html> según localStorage/SO al cargar CUALQUIER
 * página, y eso mezclaba inputs oscuros sobre la tarjeta clara del wizard.
 * Este composable re-impone la decisión del hotel mientras la página vive.
 */
export function usePublicTheme(isDark: ComputedRef<boolean>) {
    const apply = () =>
        document.documentElement.classList.toggle('dark', isDark.value);

    let media: MediaQueryList | null = null;

    onMounted(() => {
        apply();
        // El listener global del panel re-aplica SU tema cuando cambia el
        // del sistema; el nuestro se registra después y vuelve a ganar.
        if (window.matchMedia) {
            media = window.matchMedia('(prefers-color-scheme: dark)');
            media.addEventListener('change', apply);
        }
    });

    watch(isDark, apply);

    onBeforeUnmount(() => media?.removeEventListener('change', apply));
}
