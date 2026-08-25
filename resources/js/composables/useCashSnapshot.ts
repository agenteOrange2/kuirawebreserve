import axios from 'axios';
import { computed, ref } from 'vue';

/**
 * Cómo va la caja del turno (`GET /api/cash-cuts/current`), en un solo lugar.
 *
 * El chip de la barra del plano y el modal de caja miran lo mismo: si cada uno
 * consultara por su cuenta habría dos relojes y dos cifras que se contradicen
 * por unos segundos, justo con dinero a la vista. El estado es del módulo, no
 * del componente, así que abrir y cerrar el modal no vuelve a pedir nada.
 */
export interface CashMovement {
    at: string | null;
    concept: string;
    detail: string | null;
    method: string;
    amount: number;
    /** Falso en lo cargado a habitación: se lista para el rastro, sin sumar. */
    collected: boolean;
}

export interface CashScopeTotals {
    key: string;
    label: string;
    from: string;
    to: string;
    /** El periodo en ISO: es lo que se manda de vuelta para cerrar la caja. */
    from_iso: string;
    to_iso: string;
    shift_id: number | null;
    orders_count: number;
    payments_count: number;
    cash_total: number;
    card_total: number;
    transfer_total: number;
    grand_total: number;
    expected_cash: number;
    /** Solo cuando se pide el detalle de ESTE ámbito (?detail=). */
    movements: CashMovement[] | null;
    pending: {
        count: number;
        total: number;
        items: {
            kind: string;
            label: string;
            detail: string | null;
            amount: number;
        }[];
    } | null;
}

export interface CashSnapshot {
    shift: { id: number; started_at: string; opening_cash: number } | null;
    scopes: CashScopeTotals[];
    recent_cuts: {
        id: number;
        scope_label: string;
        closed_at: string | null;
        grand_total: number;
        difference: number | null;
    }[];
}

const state = ref<CashSnapshot | null>(null);
const loading = ref(false);
let timer: number | null = null;

/**
 * Ámbito cuyo rastro se muestra expandido. Se guarda aquí para que el reloj
 * siga trayendo el detalle sin que el modal tenga que volver a pedirlo.
 */
const detailScope = ref<string | null>(null);

async function load(): Promise<string | null> {
    if (loading.value) {
        return null;
    }

    loading.value = true;

    try {
        const { data } = await axios.get('/api/cash-cuts/current', {
            params: detailScope.value ? { detail: detailScope.value } : {},
        });
        state.value = data;

        return null;
    } catch {
        return 'No se pudo leer la caja del turno.';
    } finally {
        loading.value = false;
    }
}

export function useCashSnapshot() {
    /** Lo cobrado en el periodo en curso, sumando los ámbitos visibles. */
    const total = computed(() =>
        (state.value?.scopes ?? []).reduce(
            (sum, scope) => sum + Number(scope.grand_total ?? 0),
            0,
        ),
    );

    /**
     * Arranca el reloj de la página. Se detiene con la pestaña oculta, misma
     * regla que el refresco del plano: encuestar de a gratis cuesta batería en
     * la tablet del mostrador.
     */
    function start(): void {
        void load();

        if (timer !== null) {
            return;
        }

        timer = window.setInterval(() => {
            if (!document.hidden) {
                void load();
            }
        }, 60000);
    }

    function stop(): void {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    }

    async function openShift(
        userId: number | undefined,
        openingCash: number,
    ): Promise<string | null> {
        try {
            await axios.post('/api/shifts', {
                user_id: userId,
                opening_cash: openingCash,
            });
            await load();

            return null;
        } catch (error: any) {
            const message = error.response?.data?.message ?? '';

            // "Ya tiene un turno abierto" no es un error para quien mira: es
            // que alguien más lo abrió en otra pantalla. Se recarga y ya.
            if (message.includes('ya tiene un turno abierto')) {
                await load();

                return null;
            }

            return message || 'No se pudo abrir el turno.';
        }
    }

    /** Expandir o cerrar el rastro de un ámbito (movimientos y pendientes). */
    async function toggleDetail(scope: string): Promise<string | null> {
        detailScope.value = detailScope.value === scope ? null : scope;

        return load();
    }

    /**
     * Cierra la caja de un ámbito: el corte se recalcula en el servidor (es la
     * fuente de verdad) y el motivo queda en sus notas. El efectivo contado es
     * opcional — sin él el corte se guarda sin arqueo, con él queda la
     * diferencia. El periodo va en ISO, tal como lo entregó el endpoint.
     */
    async function closeScope(
        scope: CashScopeTotals,
        userId: number | undefined,
        reason: string,
        countedCash: number | null,
    ): Promise<string | null> {
        try {
            await axios.post('/api/cash-cuts', {
                user_id: userId,
                scope: scope.key,
                shift_id: scope.shift_id,
                from: scope.from_iso,
                to: scope.to_iso,
                counted_cash: countedCash,
                notes: reason,
            });
            await load();

            return null;
        } catch (error: any) {
            return (
                error.response?.data?.message ?? 'No se pudo cerrar la caja.'
            );
        }
    }

    /**
     * Cierra el turno y con él genera los cortes por ámbito del periodo exacto
     * del turno (lo mismo que hace /turnos con auto-corte): es el gesto del
     * cambio de guardia.
     */
    async function closeShift(
        shiftId: number,
        reason: string,
    ): Promise<string | null> {
        try {
            await axios.patch(`/api/shifts/${shiftId}/close`, {
                auto_cut: true,
                notes: reason,
            });
            await load();

            return null;
        } catch (error: any) {
            return (
                error.response?.data?.message ?? 'No se pudo cerrar el turno.'
            );
        }
    }

    return {
        state,
        loading,
        total,
        detailScope,
        load,
        start,
        stop,
        openShift,
        toggleDetail,
        closeScope,
        closeShift,
    };
}
