import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';

export interface MenuItem {
    icon: Icon;
    title: string;
    pageName?: string;
    subMenu?: MenuItem[];
    ignore?: boolean;
    badge?: string;
    // Key de config/modules.php: el item solo aparece si el módulo está
    // activo para el hotel (panelTenant.modules del share de Inertia).
    module?: string;
}

// Menú del panel de plataforma (dominio central, rol platform-admin).
const centralMenu: Array<MenuItem | string> = [
    {
        icon: 'LayoutDashboard',
        pageName: 'admin.dashboard',
        title: 'Dashboard',
    },
    'PLATAFORMA',
    {
        icon: 'Building2',
        pageName: 'admin.tenants.index',
        title: 'Hoteles',
    },
    {
        icon: 'Layers',
        pageName: 'admin.plans',
        title: 'Planes',
    },
    {
        icon: 'Bot',
        pageName: 'admin.ai',
        title: 'Agentes IA',
    },
    {
        icon: 'CreditCard',
        pageName: 'admin.payments',
        title: 'Pagos',
    },
    {
        icon: 'Palette',
        pageName: 'admin.branding',
        title: 'Apariencia',
    },
    {
        icon: 'Settings',
        pageName: 'admin.settings.profile.edit',
        title: 'Configuración',
    },
];

// Menú del panel de cada hotel (subdominios de tenant). La operación
// diaria (Dashboard/Plano/Bandeja) queda a un clic; el resto se agrupa
// por área en submenus colapsables.
const tenantMenu: Array<MenuItem | string> = [
    {
        icon: 'LayoutDashboard',
        pageName: 'tenant.dashboard',
        title: 'Dashboard',
    },
    {
        icon: 'Map',
        pageName: 'tenant.plano',
        title: 'Plano',
    },
    {
        icon: 'MessagesSquare',
        pageName: 'tenant.inbox',
        title: 'Bandeja',
    },
    {
        icon: 'CalendarDays',
        title: 'Reservas',
        subMenu: [
            {
                icon: 'CalendarDays',
                pageName: 'tenant.reservations',
                title: 'Reservas',
            },
            {
                icon: 'CalendarRange',
                pageName: 'tenant.reservations.calendar',
                title: 'Calendario',
            },
            {
                icon: 'UsersRound',
                pageName: 'tenant.groups',
                title: 'Reservas grupales',
                module: 'grupos',
            },
            {
                icon: 'Compass',
                pageName: 'tenant.experiences',
                title: 'Experiencias',
                module: 'experiencias',
            },
            {
                icon: 'Gift',
                pageName: 'tenant.extras',
                title: 'Extras de reserva',
                module: 'extras',
            },
        ],
    },
    {
        icon: 'BedDouble',
        title: 'Hotel',
        subMenu: [
            {
                icon: 'Users',
                pageName: 'tenant.guests',
                title: 'Huéspedes',
            },
            {
                icon: 'BedDouble',
                pageName: 'tenant.rooms',
                title: 'Habitaciones',
            },
            {
                icon: 'Shapes',
                pageName: 'tenant.catalog',
                title: 'Zonas y tipos',
            },
        ],
    },
    {
        icon: 'ShoppingCart',
        title: 'Ventas',
        subMenu: [
            {
                icon: 'ShoppingCart',
                pageName: 'tenant.pos',
                title: 'POS',
                module: 'pos',
            },
            {
                icon: 'Clock',
                pageName: 'tenant.shifts',
                title: 'Turnos',
                module: 'pos',
            },
            {
                icon: 'Calculator',
                pageName: 'tenant.cashcuts',
                title: 'Cortes de venta',
                module: 'pos',
            },
            {
                icon: 'Package',
                pageName: 'tenant.inventory',
                title: 'Inventario',
                module: 'pos',
            },
            {
                icon: 'Landmark',
                pageName: 'tenant.online-payments',
                title: 'Cobros en línea',
            },
        ],
    },
    {
        icon: 'Settings',
        title: 'Administración',
        subMenu: [
            {
                icon: 'UserCog',
                pageName: 'tenant.users',
                title: 'Usuarios',
            },
            {
                icon: 'Bot',
                pageName: 'tenant.agent',
                title: 'Asistente IA',
            },
            {
                icon: 'Plug',
                pageName: 'tenant.integration',
                title: 'Integración',
                module: 'motor-web',
            },
            {
                icon: 'Settings',
                pageName: 'tenant.hotel-settings',
                title: 'Ajustes',
            },
        ],
    },
];

export function useMenu() {
    const page = usePage();

    // panelTenant (compartido por el middleware) y NO 'tenant': los props de
    // página del admin llamados 'tenant' no deben cambiar el menú.
    const isTenantPanel = computed(() => Boolean(page.props.panelTenant));

    const menu = computed(() => {
        if (!isTenantPanel.value) {
            return centralMenu;
        }

        // Items de módulos apagados desaparecen del menú (spec-plan-maestro E1).
        // También dentro de los submenus; un grupo que queda vacío no se pinta.
        const modules =
            (page.props.panelTenant as { modules?: string[] } | null)
                ?.modules ?? [];
        const enabled = (item: MenuItem) =>
            !item.module || modules.includes(item.module);

        return tenantMenu.flatMap((item): Array<MenuItem | string> => {
            if (typeof item === 'string') return [item];
            if (!enabled(item)) return [];
            if (!item.subMenu) return [item];

            const subMenu = item.subMenu.filter(enabled);
            return subMenu.length ? [{ ...item, subMenu }] : [];
        });
    });

    return {
        menu,
        isTenantPanel,
    };
}
