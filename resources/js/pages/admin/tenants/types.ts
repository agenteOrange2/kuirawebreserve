// Tipos compartidos por la ficha del hotel y sus sub-vistas. Viven aquí
// porque `<script setup>` no admite exports propios.

/** Identidad del hotel: lo que pinta la cabecera en todas las áreas. */
export interface TenantShell {
    id: string;
    name: string;
    plan: string;
    plan_label: string;
    suspended: boolean;
    domain: string | null;
    created_at: string | null;
}

export interface PlanOption {
    value: string;
    label: string;
}
