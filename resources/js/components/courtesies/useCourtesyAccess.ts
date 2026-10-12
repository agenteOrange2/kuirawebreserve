import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { CourtesyAccess } from './types';

/**
 * Quién puede dar o pedir cortesías, leído de lo que comparte el panel
 * (HandleInertiaRequests): módulo, permiso, si es propietario y las
 * categorías. Lo usan los formularios de alta para no recibir otra prop.
 */
export function useCourtesyAccess() {
    const page = usePage();

    return computed<CourtesyAccess>(() => {
        const shared = page.props.panelTenant as
            | {
                  permissions?: string[];
                  is_owner?: boolean;
                  courtesy_categories?: Record<string, string> | null;
              }
            | undefined;
        const user = (page.props.auth as { user?: { id?: number } } | undefined)
            ?.user;

        return {
            enabled:
                !!shared?.courtesy_categories &&
                !!shared?.permissions?.includes('courtesies.request'),
            is_owner: !!shared?.is_owner,
            user_id: user?.id ?? 0,
            categories: shared?.courtesy_categories ?? {},
        };
    });
}
