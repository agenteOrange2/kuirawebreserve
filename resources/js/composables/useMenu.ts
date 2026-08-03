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
    // El TÍTULO de sección es el toggle: se pinta como divider (uppercase)
    // con chevron y colapsa sus items — sin renglón duplicado debajo.
    sectionToggle?: boolean;
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
        icon: 'UserCog',
        pageName: 'admin.users',
        title: 'Usuarios',
    },
    {
        icon: 'Layers',
        pageName: 'admin.plans',
        title: 'Planes',
    },
    {
        icon: 'ContactRound',
        pageName: 'admin.prospects',
        title: 'Prospectos',
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

// Menú del panel de cada hotel (subdominios de tenant): títulos de
// sección que separan cada área Y grupos colapsables debajo, para que el
// menú no crezca al agregar páginas (pedido explícito). Solo la operación
// diaria (Dashboard/Plano/Bandeja) va plana, a un clic; el grupo de la
// página activa se abre solo.
const tenantMenu: Array<MenuItem | string> = [
    'OPERACIÓN',
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
            {
                icon: 'BellRing',
                pageName: 'tenant.waitlist',
                title: 'Lista de espera',
                module: 'lista-espera',
            },
            {
                icon: 'TicketPercent',
                pageName: 'tenant.coupons',
                title: 'Cupones',
                module: 'cupones',
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
                icon: 'Wallet',
                pageName: 'tenant.payments',
                title: 'Pagos',
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
        // También dentro de submenus (si los hubiera) y un título de sección
        // que se queda sin items debajo tampoco se pinta.
        const modules =
            (page.props.panelTenant as { modules?: string[] } | null)
                ?.modules ?? [];
        const enabled = (item: MenuItem) =>
            !item.module || modules.includes(item.module);

        const filtered = tenantMenu.flatMap(
            (item): Array<MenuItem | string> => {
                if (typeof item === 'string') return [item];
                if (!enabled(item)) return [];
                if (!item.subMenu) return [item];

                const subMenu = item.subMenu.filter(enabled);
                return subMenu.length ? [{ ...item, subMenu }] : [];
            },
        );

        const pruned: Array<MenuItem | string> = [];
        filtered.forEach((item) => {
            if (
                typeof item === 'string' &&
                typeof pruned[pruned.length - 1] === 'string'
            ) {
                pruned.pop(); // título sin items: lo reemplaza el siguiente
            }
            pruned.push(item);
        });
        if (typeof pruned[pruned.length - 1] === 'string') {
            pruned.pop();
        }

        return pruned;
    });

    return {
        menu,
        isTenantPanel,
    };
}
