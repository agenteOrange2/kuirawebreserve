<?php

namespace App\Services;

use App\Models\CashCut;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Calcula el corte de caja de un encargado en un periodo, POR ÁMBITO:
 * 'rooms' = cobros de recepción (abonos de reservas + fianzas), 'pos' =
 * ventas del punto de venta, 'all' = ambos combinados (formato histórico,
 * lo conservan los cortes viejos). Cada ámbito trae su desglose por
 * método de pago y su efectivo esperado para arqueo.
 */
class CashCutService
{
    /**
     * Ámbitos que ESTE usuario puede ver: Recepción exige ver reservas (la
     * cocina corta solo su venta) y Punto de venta exige el módulo. Sin
     * contexto de tenant (tests) el módulo no aplica.
     *
     * Vive aquí y no en el controlador porque lo consultan la página de
     * cortes y el panel de caja del plano; duplicado, un permiso nuevo se
     * arreglaría en un lado y no en el otro.
     *
     * @return list<string>
     */
    public function availableScopes(User $viewer): array
    {
        $tenant = tenant();

        return array_values(array_filter([
            $viewer->can('reservations.view') ? CashCut::SCOPE_ROOMS : null,
            $tenant === null || $tenant->hasModule('pos') ? CashCut::SCOPE_POS : null,
        ]));
    }

    /**
     * Periodo "en curso" de un ámbito: si el encargado tiene turno abierto,
     * el del turno; si no, desde el último corte hasta ahora.
     *
     * @return array{shift: ?Shift, from: CarbonInterface, to: CarbonInterface}
     */
    public function openContext(User $user, string $scope): array
    {
        $shift = Shift::query()
            ->open()
            ->where('user_id', $user->id)
            ->latest('started_at')
            ->first();

        return [
            'shift' => $shift,
            'from' => $shift?->started_at ?? $this->defaultOpenedAt($user, $scope),
            'to' => Carbon::now(),
        ];
    }

    /**
     * Inicio sugerido del periodo PARA UN ÁMBITO: fin del último corte del
     * usuario que haya cubierto ese ámbito (uno 'all' viejo cuenta para
     * ambos — ya contabilizó todo), o su actividad más antigua sin cortar,
     * o el inicio del día.
     */
    public function defaultOpenedAt(User $user, string $scope = CashCut::SCOPE_ALL): CarbonInterface
    {
        $lastCut = CashCut::query()
            ->where('user_id', $user->id)
            ->whereIn('scope', $this->coveringScopes($scope))
            ->latest('closed_at')
            ->first();

        if ($lastCut) {
            return $lastCut->closed_at;
        }

        $firstOrder = $scope === CashCut::SCOPE_ROOMS
            ? null
            : Order::query()->where('created_by', $user->id)->min('created_at');
        $firstPayment = $scope === CashCut::SCOPE_POS
            ? null
            : Payment::query()->where('received_by', $user->id)->min('paid_at');

        $earliest = collect([$firstOrder, $firstPayment])->filter()->map(fn ($d) => Carbon::parse($d))->min();

        // Primer corte: arranca al inicio del día de la actividad más antigua
        // (el límite inferior es exclusivo, así que no se pierde nada).
        return $earliest?->startOfDay() ?? Carbon::today();
    }

    /**
     * ¿El periodo ya está cubierto por otro corte del mismo encargado y
     * ámbito? Los 'all' viejos cubren ambos ámbitos. Desigualdades
     * estrictas: cortes contiguos (from == closed_at anterior) sí caben.
     */
    public function overlaps(User $user, string $scope, CarbonInterface $from, CarbonInterface $to): bool
    {
        return CashCut::query()
            ->where('user_id', $user->id)
            ->whereIn('scope', $this->coveringScopes($scope))
            ->where('opened_at', '<', $to)
            ->where('closed_at', '>', $from)
            ->exists();
    }

    /**
     * Agregados del periodo (sin guardar nada).
     *
     * Con `$shift`, el corte se arma por turno y no por reloj: es lo exacto
     * cuando alguien cierra tarde o dos personas comparten usuario. Las
     * ventas y cobros sin turno (los anteriores a que existiera el enlace)
     * se siguen tomando por fecha para no perderlos.
     *
     * @return array<string, mixed>
     */
    public function compute(User $user, CarbonInterface $from, CarbonInterface $to, ?Shift $shift = null, string $scope = CashCut::SCOPE_ALL): array
    {
        // Límite inferior exclusivo: evita recontar la venta justo en el
        // instante de cierre del corte anterior.
        $inPeriod = function ($query, string $dateColumn) use ($from, $to, $shift) {
            if ($shift === null) {
                return $query->where($dateColumn, '>', $from)->where($dateColumn, '<=', $to);
            }

            return $query->where(fn ($q) => $q
                ->where('shift_id', $shift->id)
                ->orWhere(fn ($legacy) => $legacy
                    ->whereNull('shift_id')
                    ->where($dateColumn, '>', $from)
                    ->where($dateColumn, '<=', $to)));
        };

        // Ventas POS del encargado en el periodo (ámbitos pos y all).
        $orders = $scope === CashCut::SCOPE_ROOMS
            ? collect()
            : Order::query()
                ->where('created_by', $user->id)
                ->where('status', Order::STATUS_COMPLETED)
                ->tap(fn ($q) => $inPeriod($q, 'created_at'))
                ->get(['id', 'payment_method', 'total', 'total_cost']);

        $ordersByMethod = $orders->groupBy('payment_method');
        $posCash = (float) ($ordersByMethod->get('cash')?->sum('total') ?? 0);
        $posCard = (float) ($ordersByMethod->get('card')?->sum('total') ?? 0);
        $posTransfer = (float) ($ordersByMethod->get('transfer')?->sum('total') ?? 0);
        $posRoom = (float) ($ordersByMethod->get('room')?->sum('total') ?? 0);

        // Cobrado en el mostrador (excluye lo cargado a habitación).
        $ordersCollected = $posCash + $posCard + $posTransfer;
        $ordersCost = (float) $orders->sum('total_cost');

        // Abonos de reservas que recibió el encargado (ámbitos rooms y all).
        $allPayments = $scope === CashCut::SCOPE_POS
            ? collect()
            : Payment::query()
                ->where('received_by', $user->id)
                ->tap(fn ($q) => $inPeriod($q, 'paid_at'))
                ->get(['id', 'method', 'amount', 'kind']);

        // Fianzas (depósito en garantía): NO son venta ni ingreso — se
        // devuelven al check-out. Van FUERA de los totales de cobro
        // (payments_total, métodos, grand_total) pero el efectivo cobrado
        // en fianza SÍ está físicamente en el cajón de RECEPCIÓN, así que
        // el arqueo (expected_cash) lo suma como línea aparte, restando las
        // fianzas en efectivo que este encargado ya devolvió en el periodo.
        $guarantees = $allPayments->where('kind', Payment::KIND_GUARANTEE);
        $payments = $allPayments->where('kind', '!==', Payment::KIND_GUARANTEE)->values();

        $guaranteesCollected = round((float) $guarantees->sum('amount'), 2);
        $guaranteesCashIn = round((float) $guarantees->where('method', 'cash')->sum('amount'), 2);

        $guaranteesCashOut = $scope === CashCut::SCOPE_POS
            ? 0.0
            : round((float) \App\Models\Refund::query()
                ->where('created_by', $user->id)
                ->where('status', \App\Models\Refund::STATUS_COMPLETED)
                ->where('refunded_at', '>', $from)
                ->where('refunded_at', '<=', $to)
                ->whereHas('payment', fn ($q) => $q
                    ->where('kind', Payment::KIND_GUARANTEE)
                    ->where('method', 'cash'))
                ->sum('amount'), 2);

        $payByMethod = $payments->groupBy('method');
        $payCash = (float) ($payByMethod->get('cash')?->sum('amount') ?? 0);
        $payCard = (float) ($payByMethod->get('card')?->sum('amount') ?? 0);
        $payTransfer = (float) ($payByMethod->get('transfer')?->sum('amount') ?? 0);
        $paymentsTotal = $payCash + $payCard + $payTransfer;

        $cashTotal = round($posCash + $payCash, 2);
        $cardTotal = round($posCard + $payCard, 2);
        $transferTotal = round($posTransfer + $payTransfer, 2);
        $grandTotal = round($cashTotal + $cardTotal + $transferTotal, 2);

        // Fondo de caja inicial del turno: quien arquea cuenta el cajón
        // COMPLETO, así que el esperado debe incluirlo. Va en el corte de
        // recepción (el cajón del turno); el de punto de venta se queda
        // solo con su venta para no contarlo dos veces cuando el mismo
        // turno corta ambos ámbitos.
        $openingCash = $shift !== null && $scope !== CashCut::SCOPE_POS
            ? round((float) $shift->opening_cash, 2)
            : 0.0;

        $sources = array_values(array_filter([
            $scope === CashCut::SCOPE_ROOMS ? null : ['key' => 'pos', 'label' => 'Ventas POS', 'count' => $orders->count(), 'total' => round($ordersCollected, 2)],
            $scope === CashCut::SCOPE_POS ? null : ['key' => 'payments', 'label' => 'Cobros de reservas', 'count' => $payments->count(), 'total' => round($paymentsTotal, 2)],
        ]));

        return [
            'scope' => $scope,
            'orders_count' => $orders->count(),
            'orders_total' => round($ordersCollected, 2),
            'orders_cost' => round($ordersCost, 2),
            'orders_profit' => round($ordersCollected - $ordersCost, 2),
            'orders_room' => round($posRoom, 2),
            'payments_count' => $payments->count(),
            'payments_total' => round($paymentsTotal, 2),
            'cash_total' => $cashTotal,
            'card_total' => $cardTotal,
            'transfer_total' => $transferTotal,
            'grand_total' => $grandTotal,
            'opening_cash' => $openingCash,
            // Arqueo: el cajón de recepción contiene el fondo inicial del
            // turno y también las fianzas en efectivo cobradas (menos las
            // devueltas), aunque no sean venta.
            'expected_cash' => round($openingCash + $posCash + $payCash + $guaranteesCashIn - $guaranteesCashOut, 2),
            // Fianzas del periodo, como línea informativa aparte (pasivo).
            'guarantees_count' => $guarantees->count(),
            'guarantees_collected' => $guaranteesCollected,
            'guarantees_cash_in' => $guaranteesCashIn,
            'guarantees_cash_out' => $guaranteesCashOut,
            'sources' => $sources,
            'methods' => [
                ['key' => 'cash', 'label' => 'Efectivo', 'total' => $cashTotal],
                ['key' => 'card', 'label' => 'Tarjeta', 'total' => $cardTotal],
                ['key' => 'transfer', 'label' => 'Transferencia', 'total' => $transferTotal],
            ],
        ];
    }

    /**
     * Lista transacción por transacción de lo que pasó en el periodo: cada
     * venta POS, cada cobro, cada fianza y cada devolución, en orden
     * cronológico. Es el respaldo del corte — el número de "movimientos"
     * deja de ser solo un contador.
     *
     * Se reconstruye de los registros vivos (los pagos son append-only), no
     * se congela en el corte: para cortes viejos refleja el estado actual.
     *
     * @return array<int, array<string, mixed>>
     */
    public function movements(User $user, CarbonInterface $from, CarbonInterface $to, ?Shift $shift = null, string $scope = CashCut::SCOPE_ALL): array
    {
        $inPeriod = function ($query, string $dateColumn) use ($from, $to, $shift) {
            if ($shift === null) {
                return $query->where($dateColumn, '>', $from)->where($dateColumn, '<=', $to);
            }

            return $query->where(fn ($q) => $q
                ->where('shift_id', $shift->id)
                ->orWhere(fn ($legacy) => $legacy
                    ->whereNull('shift_id')
                    ->where($dateColumn, '>', $from)
                    ->where($dateColumn, '<=', $to)));
        };

        $items = collect();

        if ($scope !== CashCut::SCOPE_ROOMS) {
            $orders = Order::query()
                ->with('stay.room:id,number')
                ->where('created_by', $user->id)
                ->where('status', Order::STATUS_COMPLETED)
                ->tap(fn ($q) => $inPeriod($q, 'created_at'))
                ->get(['id', 'stay_id', 'payment_method', 'total', 'created_at']);

            foreach ($orders as $order) {
                $room = $order->stay?->room?->number;
                $toRoom = $order->payment_method === 'room';

                $items->push([
                    'at' => $order->created_at,
                    'concept' => 'Venta POS #'.$order->id,
                    'detail' => $room !== null ? 'Habitación '.$room : null,
                    'method' => $toRoom ? 'A habitación (folio)' : Payment::methodLabel($order->payment_method),
                    'amount' => (float) $order->total,
                    // Lo cargado a habitación no entró a esta caja: se cobra
                    // en el check-out. Se lista para el rastro, sin sumar.
                    'collected' => ! $toRoom,
                ]);
            }
        }

        if ($scope !== CashCut::SCOPE_POS) {
            $payments = Payment::query()
                ->with([
                    'reservation:id,code,guest_name,created_at',
                    'stay:id,room_id,guest_name',
                    'stay.room:id,number',
                ])
                ->where('received_by', $user->id)
                ->tap(fn ($q) => $inPeriod($q, 'paid_at'))
                ->get(['id', 'reservation_id', 'stay_id', 'method', 'amount', 'kind', 'paid_at']);

            foreach ($payments as $payment) {
                $concept = match ($payment->kind) {
                    Payment::KIND_GUARANTEE => 'Fianza en garantía',
                    Payment::KIND_LODGING => 'Hospedaje en folio',
                    Payment::KIND_CONSUMPTION => 'Consumos del folio',
                    default => 'Abono de reserva',
                };

                $who = $payment->reservation?->guest_name ?? $payment->stay?->guest_name;
                $ref = $payment->reservation?->displayCode();
                $room = $payment->stay?->room?->number;

                $items->push([
                    'at' => $payment->paid_at,
                    'concept' => $concept.($ref !== null ? ' '.$ref : ''),
                    'detail' => collect([$who, $room !== null ? 'Habitación '.$room : null])->filter()->implode(' · ') ?: null,
                    'method' => Payment::methodLabel($payment->method),
                    'amount' => (float) $payment->amount,
                    'collected' => $payment->kind !== Payment::KIND_GUARANTEE,
                ]);
            }

            $refunds = \App\Models\Refund::query()
                ->with(['reservation:id,code,guest_name,created_at'])
                ->where('created_by', $user->id)
                ->where('status', \App\Models\Refund::STATUS_COMPLETED)
                ->where('refunded_at', '>', $from)
                ->where('refunded_at', '<=', $to)
                ->whereHas('payment', fn ($q) => $q->where('kind', Payment::KIND_GUARANTEE))
                ->get(['id', 'payment_id', 'reservation_id', 'amount', 'gateway', 'refunded_at']);

            foreach ($refunds as $refund) {
                $items->push([
                    'at' => $refund->refunded_at,
                    'concept' => 'Devolución de fianza'.($refund->reservation !== null ? ' '.$refund->reservation->displayCode() : ''),
                    'detail' => $refund->reservation?->guest_name,
                    'method' => $refund->gateway === null ? 'Efectivo' : 'Pasarela',
                    'amount' => -(float) $refund->amount,
                    'collected' => false,
                ]);
            }
        }

        return $items
            ->sortBy('at')
            ->values()
            ->map(fn (array $item) => [
                ...$item,
                'at' => $item['at']?->format('d/m H:i'),
                'amount' => round($item['amount'], 2),
            ])
            ->all();
    }

    /**
     * Pagos pendientes al momento del corte, para dejarlos por escrito en
     * el relevo de turno: recepción reporta a los huéspedes en casa con
     * saldo y las reservas con pago vencido; punto de venta reporta sus
     * consumos cargados a habitación que aún no se liquidan en folio.
     *
     * Es una FOTO del instante (el estado vivo cambia después), por eso el
     * corte la congela en pending_items al guardarse.
     *
     * @return array{count: int, total: float, items: array<int, array<string, mixed>>}
     */
    public function pendingSnapshot(User $user, CarbonInterface $from, CarbonInterface $to, ?Shift $shift = null, string $scope = CashCut::SCOPE_ALL): array
    {
        $items = collect();

        if ($scope !== CashCut::SCOPE_POS) {
            // Huéspedes en casa con saldo (hospedaje + consumos del folio).
            $stays = \App\Models\Stay::query()
                ->active()
                ->with(['room:id,number', 'reservation:id,code,guest_name,total_amount,created_at'])
                ->get();

            foreach ($stays as $stay) {
                $folio = $stay->folio();

                if ($folio['grand_pending'] <= 0) {
                    continue;
                }

                $parts = collect([
                    $folio['lodging_pending'] > 0 ? 'Hospedaje $'.number_format($folio['lodging_pending'], 2) : null,
                    $folio['consumption_pending'] > 0 ? 'Consumos $'.number_format($folio['consumption_pending'], 2) : null,
                ])->filter()->implode(' · ');

                $items->push([
                    'kind' => 'stay',
                    'label' => collect([
                        $stay->room !== null ? 'Habitación '.$stay->room->number : null,
                        $stay->reservation?->guest_name ?? $stay->guest_name,
                    ])->filter()->implode(' · ') ?: 'Estancia #'.$stay->id,
                    'detail' => 'En casa · '.$parts,
                    'amount' => $folio['grand_pending'],
                ]);
            }

            // Reservas con fecha de pago vencida que siguen debiendo.
            $overdue = \App\Models\Reservation::query()
                ->whereIn('status', [\App\Enums\ReservationStatus::Pending, \App\Enums\ReservationStatus::Confirmed])
                ->whereNotNull('payment_due_at')
                ->where('payment_due_at', '<=', $to)
                ->where('payment_status', '!=', \App\Enums\PaymentStatus::Paid)
                ->orderBy('payment_due_at')
                ->take(50)
                ->get();

            foreach ($overdue as $reservation) {
                $balance = $reservation->pendingBalance();

                if ($balance <= 0) {
                    continue;
                }

                $items->push([
                    'kind' => 'reservation',
                    'label' => $reservation->displayCode().' · '.$reservation->guest_name,
                    'detail' => 'Pago vencido desde el '.$reservation->payment_due_at->format('d/m H:i'),
                    'amount' => $balance,
                ]);
            }
        }

        if ($scope !== CashCut::SCOPE_ROOMS) {
            // Ventas del encargado cargadas a habitación sin liquidar: es el
            // dinero de SU periodo que sigue pendiente de cobro en folio.
            $inPeriod = function ($query) use ($from, $to, $shift) {
                if ($shift === null) {
                    return $query->where('created_at', '>', $from)->where('created_at', '<=', $to);
                }

                return $query->where(fn ($q) => $q
                    ->where('shift_id', $shift->id)
                    ->orWhere(fn ($legacy) => $legacy
                        ->whereNull('shift_id')
                        ->where('created_at', '>', $from)
                        ->where('created_at', '<=', $to)));
            };

            $roomOrders = Order::query()
                ->with('stay.room:id,number')
                ->where('created_by', $user->id)
                ->where('status', Order::STATUS_COMPLETED)
                ->where('payment_method', 'room')
                ->whereNull('settled_at')
                ->tap($inPeriod)
                ->get(['id', 'stay_id', 'total', 'created_at']);

            foreach ($roomOrders as $order) {
                $room = $order->stay?->room?->number;

                $items->push([
                    'kind' => 'order',
                    'label' => 'Venta POS #'.$order->id.($room !== null ? ' · Habitación '.$room : ''),
                    'detail' => 'Cargada a habitación, se cobra en el check-out',
                    'amount' => (float) $order->total,
                ]);
            }
        }

        $items = $items->map(fn (array $item) => [...$item, 'amount' => round($item['amount'], 2)])->values();

        return [
            'count' => $items->count(),
            'total' => round($items->sum('amount'), 2),
            'items' => $items->take(50)->all(),
        ];
    }

    /**
     * Ámbitos cuyos cortes cubren al ámbito dado: el propio + 'all' (un
     * corte combinado viejo ya contó ambas cajas).
     *
     * @return array<int, string>
     */
    protected function coveringScopes(string $scope): array
    {
        return $scope === CashCut::SCOPE_ALL
            ? [CashCut::SCOPE_ROOMS, CashCut::SCOPE_POS, CashCut::SCOPE_ALL]
            : [$scope, CashCut::SCOPE_ALL];
    }
}
