import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useModules } from './useModules';

/**
 * Piezas del módulo `plano-operativo`: lo que se puede operar sin salir de
 * /plano. Hoy son el contador flotante "Estado de la casa", los tabs del modal
 * de habitación (consumos y cobro, edición del cuarto) y la caja del turno.
 *
 * OJO con el nombre: "widget" en este proyecto es el embebido del sitio web
 * (/widget.js). Estas piezas no son widgets.
 *
 * El módulo abre el tablero, pero cada pieza se gatea por su cuenta con el
 * permiso y el módulo de SU dominio (el POS no se ve sin `pos`, la caja no se
 * ve sin `corte-caja`), porque /plano solo exige `rooms.view` y eso no alcanza
 * para cobrar ni para ver dinero.
 *
 * La única preferencia que se recuerda es si el contador está a la vista: es
 * lo único que ocupa espacio permanente sobre el plano. Va por usuario porque
 * el mostrador comparte tablet.
 */
export type PieceKey = 'estado' | 'habitacion' | 'consumos' | 'caja';

export interface PieceDefinition {
    key: PieceKey;
    /** Permiso spatie que exige el dominio de la pieza. */
    permission: string;
    /** Módulo del catálogo que exige el dominio de la pieza (opcional). */
    module?: string;
}

export const PIECES: PieceDefinition[] = [
    { key: 'estado', permission: 'rooms.view' },
    { key: 'habitacion', permission: 'rooms.manage' },
    { key: 'consumos', permission: 'orders.manage', module: 'pos' },
    { key: 'caja', permission: 'orders.manage', module: 'corte-caja' },
];

const storageKey = (userId: number | string): string =>
    `kuira.plan.counters.v1.${userId}`;

const countersVisible = ref(true);
let restoredFor: string | null = null;

function restore(key: string): boolean {
    try {
        const raw = window.localStorage.getItem(key);

        return raw === null ? true : raw === '1';
    } catch {
        // Storage bloqueado: el contador se ve, que es lo de fábrica.
        return true;
    }
}

export function usePlanPanels() {
    const page = usePage();
    const { hasModule } = useModules();

    const userId = computed<number | string>(() => {
        const auth = page.props.auth as { user?: { id?: number } } | undefined;

        return auth?.user?.id ?? 'anon';
    });

    const key = computed(() => storageKey(userId.value));

    if (restoredFor !== key.value && typeof window !== 'undefined') {
        countersVisible.value = restore(key.value);
        restoredFor = key.value;
    }

    watch(countersVisible, (visible) => {
        try {
            window.localStorage.setItem(key.value, visible ? '1' : '0');
        } catch {
            // Sin storage sigue funcionando, solo no se recuerda.
        }
    });

    const permissions = computed<string[]>(() => {
        const shared = page.props.panelTenant as
            | { permissions?: string[] }
            | undefined;

        return shared?.permissions ?? [];
    });

    /** El módulo que abre el tablero completo. */
    const enabled = computed(() => hasModule('plano-operativo'));

    /**
     * ¿Este usuario puede usar esta pieza? Nunca se confía en lo guardado: si
     * al hotel le quitan el POS, el tab de consumos desaparece en vez de
     * quedar como un botón que responde 403.
     */
    const canSee = (pieceKey: PieceKey): boolean => {
        if (!enabled.value) {
            return false;
        }

        const piece = PIECES.find((item) => item.key === pieceKey);

        if (!piece) {
            return false;
        }

        return (
            permissions.value.includes(piece.permission) &&
            (!piece.module || hasModule(piece.module))
        );
    };

    return { enabled, canSee, countersVisible };
}
