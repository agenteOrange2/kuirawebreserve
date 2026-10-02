import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import type { TenantShell } from './types';

// Modo de operación (spec-modo-motel). Lo administra SOLO la plataforma, al
// crear y al editar. "Ambos" opera hotel y motel a la vez: no apaga nada,
// suma los atajos de caseta a la operación de hotel.
export type PropertyMode = TenantShell['mode'];

export const modeOptions: {
    value: PropertyMode;
    label: string;
    icon: Icon;
    description: string;
}[] = [
    {
        value: 'hotel',
        label: 'Hotel',
        icon: 'Building2',
        description: 'Flujo clásico de reservas y recepción.',
    },
    {
        value: 'motel',
        label: 'Motel',
        icon: 'CarFront',
        description:
            'Registro exprés en el plano con placa o identificación y cobro en la llegada.',
    },
    {
        value: 'both',
        label: 'Ambos',
        // No "Layers": ese icono ya es el de Planes.
        icon: 'Blend',
        description:
            'Opera como hotel y como motel: conserva las dos funcionalidades.',
    },
];

export const modeOption = (mode: string) =>
    modeOptions.find((option) => option.value === mode) ?? modeOptions[0];

// El sitio vive detrás del túnel con HTTPS: un http:// suelto rebota.
export const tenantUrl = (domain: string) => `https://${domain}`;
