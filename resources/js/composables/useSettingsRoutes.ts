import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';

export interface SettingsNavItem {
    label: string;
    icon: Icon;
    routeName: string;
}

/**
 * Las mismas pantallas de perfil sirven a los dos paneles: el super-admin
 * en el dominio central y el staff en el panel del hotel. Lo único que
 * cambia son los nombres de ruta y qué secciones existen —el correo de
 * plataforma es solo del admin—, así que vive aquí y no repetido en cada
 * página.
 */
export function useSettingsRoutes() {
    const page = usePage();
    const isTenantPanel = computed(() => Boolean(page.props.panelTenant));

    const routes = computed(() => ({
        profileEdit: isTenantPanel.value
            ? 'tenant.profile.edit'
            : 'admin.settings.profile.edit',
        profileUpdate: isTenantPanel.value
            ? 'tenant.profile.update'
            : 'admin.settings.profile.update',
        profileDestroy: 'admin.settings.profile.destroy',
        passwordEdit: isTenantPanel.value
            ? 'tenant.profile.password'
            : 'admin.settings.password.edit',
        passwordUpdate: isTenantPanel.value
            ? 'tenant.profile.password.update'
            : 'admin.settings.password.update',
        twoFactor: isTenantPanel.value
            ? 'tenant.profile.two-factor'
            : 'admin.settings.two-factor.show',
        appearance: 'admin.settings.appearance.edit',
        email: 'admin.settings.email.edit',
    }));

    const items = computed<SettingsNavItem[]>(() => {
        const base: SettingsNavItem[] = [
            {
                label: 'Perfil',
                icon: 'User',
                routeName: routes.value.profileEdit,
            },
            {
                label: 'Contraseña',
                icon: 'Lock',
                routeName: routes.value.passwordEdit,
            },
            {
                label: 'Dos pasos',
                icon: 'ShieldCheck',
                routeName: routes.value.twoFactor,
            },
        ];

        // Apariencia de la cuenta y correo de salida son de plataforma: el
        // panel del hotel tiene su propio tema en /ajustes/general/apariencia.
        return isTenantPanel.value
            ? base
            : [
                  ...base,
                  {
                      label: 'Apariencia',
                      icon: 'Sun',
                      routeName: 'admin.settings.appearance.edit',
                  },
                  {
                      label: 'Correo',
                      icon: 'Mail',
                      routeName: 'admin.settings.email.edit',
                  },
              ];
    });

    return { isTenantPanel, routes, items };
}
