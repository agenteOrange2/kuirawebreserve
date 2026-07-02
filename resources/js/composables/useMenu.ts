import { ref } from 'vue';

export interface MenuItem {
  icon: string;
  title: string;
  pageName?: string;
  subMenu?: MenuItem[];
  ignore?: boolean;
  badge?: string;
}

export const menu = ref<Array<MenuItem | string>>([
  {
    icon: 'Home',
    pageName: 'dashboard',
    title: 'Dashboard',
  },
  'MENÚ',
  {
    icon: 'Settings',
    pageName: 'profile.edit',
    title: 'Configuración',
  },
]);

export function useMenu() {
  return {
    menu,
  };
}
