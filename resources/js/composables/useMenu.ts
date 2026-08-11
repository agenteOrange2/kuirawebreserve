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
    // Permiso (spatie) que exige la ruta del item: sin él, el item se
    // esconde (panelTenant.permissions) — mismo gate que el middleware.
    permission?: string;
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
        icon: 'PackagePlus',
        pageName: 'admin.services',
        title: 'Servicios adicionales',
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
        permission: 'rooms.view',
    },
    {
        icon: 'MessagesSquare',
        pageName: 'tenant.inbox',
        title: 'Bandeja',
        permission: 'reservations.view',
        module: 'mensajeria',
    },
    {
        icon: 'CalendarDays',
        title: 'Reservas',
        subMenu: [
            {
                icon: 'CalendarDays',
                pageName: 'tenant.reservations',
                title: 'Reservas',
                permission: 'reservations.view',
            },
            {
                icon: 'CalendarRange',
                pageName: 'tenant.reservations.calendar',
                title: 'Calendario',
                permission: 'reservations.view',
            },
            {
                icon: 'UsersRound',
                pageName: 'tenant.groups',
                title: 'Reservas grupales',
                module: 'grupos',
                permission: 'reservations.view',
            },
            {
                icon: 'Compass',
                pageName: 'tenant.experiences',
                title: 'Experiencias',
                module: 'experiencias',
                permission: 'reservations.view',
            },
            {
                icon: 'Gift',
                pageName: 'tenant.extras',
                title: 'Extras de reserva',
                module: 'extras',
                permission: 'properties.manage',
            },
            {
                icon: 'BellRing',
                pageName: 'tenant.waitlist',
                title: 'Lista de espera',
                module: 'lista-espera',
                permission: 'reservations.manage',
            },
            {
                icon: 'TicketPercent',
                pageName: 'tenant.coupons',
                title: 'Cupones',
                module: 'cupones',
                permission: 'properties.manage',
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
                permission: 'guests.view',
            },
            {
                icon: 'BedDouble',
                pageName: 'tenant.rooms',
                title: 'Habitaciones',
                permission: 'rooms.view',
            },
            {
                icon: 'Wrench',
                pageName: 'tenant.incidents',
                title: 'Incidencias',
                module: 'incidencias',
                permission: 'rooms.view',
            },
            {
                icon: 'Smile',
                pageName: 'tenant.surveys',
                title: 'Encuestas',
                module: 'encuestas',
                permission: 'reports.view',
            },
            {
                icon: 'Shapes',
                pageName: 'tenant.catalog',
                title: 'Zonas y tipos',
                permission: 'rooms.view',
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
                permission: 'orders.manage',
            },
            {
                icon: 'Clock',
                pageName: 'tenant.shifts',
                title: 'Turnos',
                module: 'corte-caja',
                permission: 'orders.manage',
            },
            {
                icon: 'Calculator',
                pageName: 'tenant.cashcuts',
                title: 'Cortes de caja',
                module: 'corte-caja',
                permission: 'orders.manage',
            },
            {
                icon: 'Package',
                pageName: 'tenant.inventory',
                title: 'Inventario',
                module: 'pos',
                permission: 'inventory.manage',
            },
            {
                icon: 'UtensilsCrossed',
                pageName: 'tenant.menu-digital',
                title: 'Menú digital',
                module: 'menu-digital',
                permission: 'orders.manage',
            },
            {
                icon: 'ChefHat',
                pageName: 'tenant.menu-kitchen',
                title: 'Cocina',
                module: 'menu-digital',
                permission: 'orders.manage',
            },
            {
                icon: 'Wallet',
                pageName: 'tenant.payments',
                title: 'Pagos',
                permission: 'reservations.view',
            },
            {
                icon: 'Landmark',
                pageName: 'tenant.online-payments',
                title: 'Cobros en línea',
                permission: 'reservations.view',
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
                permission: 'users.manage',
            },
            {
                icon: 'History',
                pageName: 'tenant.activity',
                title: 'Actividad',
                module: 'bitacora',
                permission: 'reports.view',
            },
            {
                icon: 'Bot',
                pageName: 'tenant.agent',
                title: 'Asistente IA',
                permission: 'properties.manage',
                module: 'mensajeria',
            },
            {
                icon: 'Plug',
                pageName: 'tenant.integration',
                title: 'Integración',
                module: 'motor-web',
                permission: 'properties.manage',
            },
            {
                icon: 'Settings',
                pageName: 'tenant.hotel-settings',
                title: 'Ajustes',
                permission: 'properties.manage',
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

        // Items de módulos apagados desaparecen del menú (spec-plan-maestro E1)
        // y también los que exigen un permiso que el usuario no tiene (mismo
        // gate `can:` de la ruta — sin esto, un rol limitado veía opciones
        // que respondían 403). Un título de sección que se queda sin items
        // debajo tampoco se pinta.
        const shared = page.props.panelTenant as {
            modules?: string[];
            permissions?: string[];
        } | null;
        const modules = shared?.modules ?? [];
        const permissions = shared?.permissions ?? [];
        const enabled = (item: MenuItem) =>
            (!item.module || modules.includes(item.module)) &&
            (!item.permission || permissions.includes(item.permission));

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
