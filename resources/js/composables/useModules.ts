import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Módulos activos del hotel (config/modules.php resuelto por el plan +
 * overrides del admin), compartidos por HandleInertiaRequests en
 * panelTenant.modules. Para gatear piezas DENTRO de una página — las rutas
 * y el menú ya se gatean con `module:` y el campo `module` de useMenu.
 */
export function useModules() {
    const page = usePage();

    const modules = computed<string[]>(() => {
        const shared = page.props.panelTenant as
            | { modules?: string[] }
            | undefined;

        return shared?.modules ?? [];
    });

    // Sin panelTenant (páginas públicas) no hay gating de UI interna: esas
    // páginas ya viven detrás de su propio middleware de módulo.
    const hasModule = (key: string): boolean =>
        page.props.panelTenant === undefined || modules.value.includes(key);

    return { modules, hasModule };
}
