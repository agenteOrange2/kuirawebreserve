// Tipos compartidos por la ficha del hotel y sus sub-vistas. Viven aquí
// porque `<script setup>` no admite exports propios.

/** Identidad del hotel: lo que pinta la cabecera en todas las áreas. */
export interface TenantShell {
    id: string;
    name: string;
    plan: string;
    plan_label: string;
    suspended: boolean;
    suspended_since?: string | null;
    domain: string | null;
    created_at: string | null;
    mode: 'hotel' | 'motel' | 'both';
    module_requests?: number;
}

export interface PlanOption {
    value: string;
    label: string;
    active?: boolean;
    price_monthly?: number;
}
