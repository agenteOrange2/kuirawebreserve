import type { Icon } from '@/components/Base/Lucide/Lucide.vue';

// Lo que arma StaffAccess::editorPayload() en el servidor: lo comparten el
// editor del propietario (/usuarios/roles) y el del admin
// (/admin/tenants/{id}/roles).

export interface StaffRole {
    id: number;
    name: string;
    label: string;
    description: string;
    // De fábrica (owner, manager…): no se elimina, sí se edita.
    system: boolean;
    // El propietario: ve y puede todo, no se edita.
    locked: boolean;
    users: number;
    permissions: string[];
    hidden_areas: string[];
}

export interface PanelArea {
    key: string;
    group: string;
    label: string;
    description: string;
    icon: Icon;
    permission: string;
}

export interface Capability {
    key: string;
    group: string;
    label: string;
    description: string | null;
}

export interface NamedGroup {
    key: string;
    label: string;
}

export interface StaffAccessPayload {
    roles: StaffRole[];
    areas: PanelArea[];
    areaGroups: NamedGroup[];
    capabilities: Capability[];
    capabilityGroups: NamedGroup[];
}

// A dónde guarda cada panel: el hotel por /api/roles, el admin por
// /admin/tenants/{id}/roles.
export interface RoleEndpoints {
    store: string;
    update: (id: number) => string;
    destroy: (id: number) => string;
}

// Cada rol de fábrica con su tono, el mismo que usa la lista de Equipo.
const roleTones: Record<string, string> = {
    owner: 'border-primary/10 bg-primary/10 text-primary',
    manager: 'border-info/10 bg-info/10 text-info',
    'front-desk': 'border-success/10 bg-success/10 text-success',
    housekeeping: 'border-warning/10 bg-warning/10 text-warning',
    kitchen: 'border-pending/10 bg-pending/10 text-pending',
};

export const roleTone = (name: string) =>
    roleTones[name] ??
    'border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400 dark:text-slate-300';

const roleIcons: Record<string, Icon> = {
    owner: 'Crown',
    manager: 'Briefcase',
    'front-desk': 'ConciergeBell',
    housekeeping: 'Brush',
    kitchen: 'ChefHat',
};

export const roleIcon = (name: string): Icon => roleIcons[name] ?? 'UserRound';
