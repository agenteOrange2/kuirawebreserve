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
    icon: 'Settings',
    pageName: 'admin.settings.profile.edit',
    title: 'Configuración',
  },
];

// Menú del panel de cada hotel (subdominios de tenant).
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
    icon: 'CalendarDays',
    pageName: 'tenant.reservations',
    title: 'Reservas',
  },
  {
    icon: 'MessagesSquare',
    pageName: 'tenant.inbox',
    title: 'Bandeja',
  },
  {
    icon: 'ShoppingCart',
    pageName: 'tenant.pos',
    title: 'POS',
  },
  {
    icon: 'Clock',
    pageName: 'tenant.shifts',
    title: 'Turnos',
  },
  {
    icon: 'Calculator',
    pageName: 'tenant.cashcuts',
    title: 'Cortes de venta',
  },
  'HOTEL',
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
  {
    icon: 'Package',
    pageName: 'tenant.inventory',
    title: 'Inventario',
  },
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
    icon: 'Settings',
    pageName: 'tenant.hotel-settings',
    title: 'Ajustes',
  },
];

export function useMenu() {
  const page = usePage();

  const isTenantPanel = computed(() => Boolean(page.props.tenant));
  const menu = computed(() => (isTenantPanel.value ? tenantMenu : centralMenu));

  return {
    menu,
    isTenantPanel,
  };
}
