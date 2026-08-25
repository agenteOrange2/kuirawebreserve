import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Modo de operación de la propiedad (hotel | motel | both), compartido por
 * HandleInertiaRequests en panelTenant.property_mode. Lo administra la
 * plataforma en /admin; el hotel no lo puede cambiar.
 *
 * Mismos nombres que App\Services\PropertyMode a propósito: los "puros"
 * (isMotel/isHotel) sirven para decidir cuál flujo mostrar, y los de unión
 * (hasMotel/hasHotel) para ENCENDER funcionalidad — en modo "ambos" las dos
 * son verdaderas porque ese modo suma, nunca resta.
 */
export type PropertyModeValue = 'hotel' | 'motel' | 'both';

export function usePropertyMode() {
    const page = usePage();

    const mode = computed<PropertyModeValue>(() => {
        const shared = page.props.panelTenant as
            | { property_mode?: PropertyModeValue }
            | undefined;

        return shared?.property_mode ?? 'hotel';
    });

    const isHotel = computed(() => mode.value === 'hotel');
    const isMotel = computed(() => mode.value === 'motel');
    const isBoth = computed(() => mode.value === 'both');
    const hasMotel = computed(() => isMotel.value || isBoth.value);
    const hasHotel = computed(() => isHotel.value || isBoth.value);

    return { mode, isHotel, isMotel, isBoth, hasMotel, hasHotel };
}
