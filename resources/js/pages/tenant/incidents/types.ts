// Tipos compartidos entre la página de incidencias y sus modales. Viven
// aquí porque `<script setup>` no admite exports propios.

/** Horas objetivo por prioridad antes de marcar una falla como vencida. */
export interface SlaHours {
    high: number;
    medium: number;
    low: number;
}

/** Quién repara: personal de casa o proveedor externo. */
export interface TechnicianRow {
    id: number;
    name: string;
    phone: string | null;
    specialty: string | null;
    external: boolean;
    kind_label: string;
    active: boolean;
    notes: string | null;
}
