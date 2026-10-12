import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * ¿El usuario tiene este permiso? Para no pintar un enlace que termina en
 * 403 (la ficha de la habitación pide rooms.view, el plano también). Lee la
 * lista que HandleInertiaRequests comparte en panelTenant.permissions.
 */
export function usePanelCan() {
    const page = usePage();
    const permissions = computed(
        () =>
            (page.props.panelTenant as { permissions?: string[] } | undefined)
                ?.permissions ?? [],
    );

    return (permission: string) => permissions.value.includes(permission);
}
