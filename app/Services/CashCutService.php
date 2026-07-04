<?php

namespace App\Services;

use App\Models\CashCut;
use App\Models\Order;
use App\Models\Payment;
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
     * @return array<string, mixed>
     */
    public function compute(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        // Ventas POS del encargado en el periodo.
        // Límite inferior exclusivo: evita recontar la venta justo en el
        // instante de cierre del corte anterior.
        $orders = Order::query()
            ->where('created_by', $user->id)
            ->where('status', Order::STATUS_COMPLETED)
            ->where('created_at', '>', $from)
            ->where('created_at', '<=', $to)
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
        $payments = Payment::query()
            ->where('received_by', $user->id)
            ->where('paid_at', '>', $from)
            ->where('paid_at', '<=', $to)
            ->get(['id', 'method', 'amount']);

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
            'expected_cash' => round($posCash + $payCash, 2),
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
