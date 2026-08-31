import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Icon } from '@/components/Base/Lucide';

/**
 * Formas de cobro que acepta el MOSTRADOR, compartidas por
 * HandleInertiaRequests en panelTenant.counter_methods y configuradas por el
 * hotel en /ajustes/metodos-pago → Políticas.
 *
 * NO es lo mismo que los métodos EN LÍNEA de /admin (PaymentMethodGate):
 * aquellos son lo que se le ofrece al huésped en el wizard público
 * (pasarelas, transferencia con comprobante, "pago al llegar"). Esto es la
 * caja y la terminal de recepción — un hotel puede tener terminal bancaria
 * sin ninguna pasarela, o al revés.
 *
 * Todas las pantallas que registran un cobro presencial —plano, POS, salida,
 * abonos al folio, pagos de reserva— leen de aquí. El histórico NO se filtra:
 * un corte o un folio siguen mostrando el método con el que se cobró en su
 * momento, aunque el hotel ya no lo acepte.
 */
export type CounterMethod = 'cash' | 'card' | 'transfer';

export interface CounterMethodOption {
    key: CounterMethod;
    label: string;
    /** Para columnas angostas (el carrito del POS, el folio de salida). */
    short: string;
    /** Qué significa de verdad; lo usa la pantalla de ajustes. */
    hint: string;
    icon: Icon;
}

const CATALOG: CounterMethodOption[] = [
    {
        key: 'cash',
        label: 'Efectivo',
        short: 'Efectivo',
        hint: 'Billete en la caja de recepción.',
        icon: 'Banknote',
    },
    {
        key: 'card',
        label: 'Tarjeta',
        short: 'Tarjeta',
        hint: 'Terminal bancaria física en el mostrador. No es el cobro con tarjeta por internet: ese depende de las pasarelas.',
        icon: 'CreditCard',
    },
    {
        key: 'transfer',
        label: 'Transferencia',
        short: 'Transfer.',
        hint: 'Depósito o transferencia con el comprobante a la vista al momento de atender.',
        icon: 'Landmark',
    },
];

export const ALL_COUNTER_METHODS: CounterMethod[] = CATALOG.map((m) => m.key);

export function useCounterMethods() {
    const page = usePage();

    const enabled = computed<CounterMethod[]>(() => {
        const shared = page.props.panelTenant as
            | { counter_methods?: CounterMethod[] }
            | undefined;
        const saved = shared?.counter_methods;

        // Sin share (páginas públicas) o lista vacía: las tres, que es como
        // operaba el panel antes de existir el ajuste.
        return Array.isArray(saved) && saved.length
            ? saved
            : ALL_COUNTER_METHODS;
    });

    const methods = computed(() =>
        CATALOG.filter((m) => enabled.value.includes(m.key)),
    );

    const has = (method: CounterMethod) => enabled.value.includes(method);

    /** El default de cualquier selector: el primero que el hotel acepta. */
    const first = computed<CounterMethod>(() => enabled.value[0] ?? 'cash');

    /**
     * Endereza un método guardado o preseleccionado: si el hotel dejó de
     * aceptarlo, cae al primero válido en vez de mandar algo que el servidor
     * va a rechazar.
     */
    const coerce = (method: CounterMethod): CounterMethod =>
        has(method) ? method : first.value;

    /**
     * Para selectores con menú propio más corto (la fianza solo admite
     * efectivo o terminal): intersección de ese menú con lo que acepta el
     * hotel. Puede quedar vacía, y ahí la pantalla dice por qué.
     */
    const subset = (keys: CounterMethod[]) =>
        computed(() =>
            CATALOG.filter(
                (m) => keys.includes(m.key) && enabled.value.includes(m.key),
            ),
        );

    const labelFor = (method: string) =>
        CATALOG.find((m) => m.key === method)?.label ?? method;

    return { enabled, methods, has, first, coerce, subset, labelFor };
}
