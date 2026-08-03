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
 * Calcula el corte de caja de un encargado en un periodo: ventas POS +
 * abonos de reservas que él registró, con desglose por método de pago y
 * el efectivo esperado para arqueo.
 */
class CashCutService
{
    /**
     * Inicio sugerido del periodo: fin del último corte del usuario, o su
     * actividad más antigua sin cortar, o el inicio del día.
     */
    public function defaultOpenedAt(User $user): CarbonInterface
    {
        $lastCut = CashCut::query()
            ->where('user_id', $user->id)
            ->latest('closed_at')
            ->first();

        if ($lastCut) {
            return $lastCut->closed_at;
        }

        $firstOrder = Order::query()->where('created_by', $user->id)->min('created_at');
        $firstPayment = Payment::query()->where('received_by', $user->id)->min('paid_at');

        $earliest = collect([$firstOrder, $firstPayment])->filter()->map(fn ($d) => Carbon::parse($d))->min();

        // Primer corte: arranca al inicio del día de la actividad más antigua
        // (el límite inferior es exclusivo, así que no se pierde nada).
        return $earliest?->startOfDay() ?? Carbon::today();
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
    public function compute(User $user, CarbonInterface $from, CarbonInterface $to, ?Shift $shift = null): array
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

        // Ventas POS del encargado en el periodo.
        $orders = Order::query()
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

        // Abonos de reservas que recibió el encargado.
        $allPayments = Payment::query()
            ->where('received_by', $user->id)
            ->tap(fn ($q) => $inPeriod($q, 'paid_at'))
            ->get(['id', 'method', 'amount', 'kind']);

        // Fianzas (depósito en garantía): NO son venta ni ingreso — se
        // devuelven al check-out. Van FUERA de los totales de cobro
        // (payments_total, métodos, grand_total) pero el efectivo cobrado
        // en fianza SÍ está físicamente en el cajón, así que el arqueo
        // (expected_cash) lo suma como línea aparte, restando las fianzas
        // en efectivo que este encargado ya devolvió en el periodo.
        $guarantees = $allPayments->where('kind', Payment::KIND_GUARANTEE);
        $payments = $allPayments->where('kind', '!==', Payment::KIND_GUARANTEE)->values();

        $guaranteesCollected = round((float) $guarantees->sum('amount'), 2);
        $guaranteesCashIn = round((float) $guarantees->where('method', 'cash')->sum('amount'), 2);

        $guaranteesCashOut = round((float) \App\Models\Refund::query()
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

        return [
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
            // Arqueo: el cajón contiene también las fianzas en efectivo
            // cobradas (menos las devueltas), aunque no sean venta.
            'expected_cash' => round($posCash + $payCash + $guaranteesCashIn - $guaranteesCashOut, 2),
            // Fianzas del periodo, como línea informativa aparte (pasivo).
            'guarantees_count' => $guarantees->count(),
            'guarantees_collected' => $guaranteesCollected,
            'guarantees_cash_in' => $guaranteesCashIn,
            'guarantees_cash_out' => $guaranteesCashOut,
            'sources' => [
                ['key' => 'pos', 'label' => 'Ventas POS', 'count' => $orders->count(), 'total' => round($ordersCollected, 2)],
                ['key' => 'payments', 'label' => 'Cobros de reservas', 'count' => $payments->count(), 'total' => round($paymentsTotal, 2)],
            ],
            'methods' => [
                ['key' => 'cash', 'label' => 'Efectivo', 'total' => $cashTotal],
                ['key' => 'card', 'label' => 'Tarjeta', 'total' => $cardTotal],
                ['key' => 'transfer', 'label' => 'Transferencia', 'total' => $transferTotal],
            ],
        ];
    }
}
