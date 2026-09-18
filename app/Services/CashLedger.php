<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * El dinero que entró en una ventana, con UNA sola contabilidad: la de los
 * cortes de caja.
 *
 * Tres reglas que no se negocian y que antes se reescribían en cada pantalla
 * (el dashboard, los reportes y el centro de pagos), con el resultado
 * previsible de que ninguna cuadraba con la otra:
 *
 *  1. La FIANZA no es ingreso: es un pasivo en garantía que se devuelve al
 *     salir. En cabañas eran $38,000 inflando los reportes de septiembre.
 *  2. Lo cargado a habitación (`payment_method = room`) NO suma al ordenarse:
 *     suma una sola vez cuando el folio lo liquida como pago de consumos.
 *     Sumar ambas cosas contaba el POS dos veces.
 *  3. Las DEVOLUCIONES se restan — menos la de una fianza, que nunca entró.
 *
 * Quien necesite "cuánto entró" pide aquí. Si hay que cambiar la regla, se
 * cambia en un solo lugar.
 */
class CashLedger
{
    /**
     * @param  int|null  $roomId  Acota a una habitación: abonos de sus
     *                            reservas o de su folio, y consumos de su
     *                            estancia.
     * @return array{
     *     lodging: float, pos: float, collected: float, refunds: float, net: float,
     *     guarantees: float, guarantees_count: int, payments_count: int,
     *     by_method: array<int, array{method: string, label: string, amount: float, count: int}>
     * }
     */
    public function summary(CarbonInterface $from, CarbonInterface $to, ?int $roomId = null): array
    {
        $payments = $this->payments($from, $to, $roomId);
        $guarantees = $payments->filter(fn (Payment $p) => $p->kind === Payment::KIND_GUARANTEE);
        $income = $payments->reject(fn (Payment $p) => $p->kind === Payment::KIND_GUARANTEE)->values();

        $consumption = round((float) $income->where('kind', Payment::KIND_CONSUMPTION)->sum('amount'), 2);
        $lodging = round((float) $income->sum('amount') - $consumption, 2);
        $pos = round($this->counterOrders($from, $to, $roomId) + $consumption, 2);
        $refunds = $this->refunds($from, $to, $roomId);
        $collected = round($lodging + $pos, 2);

        return [
            'lodging' => $lodging,
            'pos' => $pos,
            'collected' => $collected,
            'refunds' => $refunds,
            'net' => round($collected - $refunds, 2),
            'guarantees' => round((float) $guarantees->sum('amount'), 2),
            'guarantees_count' => $guarantees->count(),
            'payments_count' => $income->count(),
            'by_method' => $this->byMethod($income),
        ];
    }

    /**
     * Abonos de la ventana (incluida la fianza: quien la quiera fuera, que
     * la descarte — aquí se devuelve todo para no consultar dos veces).
     *
     * @return Collection<int, Payment>
     */
    public function payments(CarbonInterface $from, CarbonInterface $to, ?int $roomId = null): Collection
    {
        return Payment::query()
            ->whereBetween('paid_at', [$from, $to])
            ->when($roomId, fn ($q) => $q->where(fn ($qq) => $qq
                ->whereHas('reservation', fn ($r) => $r->where('room_id', $roomId))
                ->orWhereHas('stay', fn ($s) => $s->where('room_id', $roomId))))
            ->get(['id', 'amount', 'method', 'kind', 'paid_at']);
    }

    /**
     * Ventas cobradas en el mostrador. Lo cargado a habitación se excluye a
     * propósito: entra cuando el folio lo liquida.
     */
    public function counterOrders(CarbonInterface $from, CarbonInterface $to, ?int $roomId = null): float
    {
        return round((float) Order::query()
            ->where('status', Order::STATUS_COMPLETED)
            ->whereBetween('created_at', [$from, $to])
            ->where(fn ($q) => $q->whereNull('payment_method')->orWhere('payment_method', '!=', 'room'))
            ->when($roomId, fn ($q) => $q->whereHas('stay', fn ($s) => $s->where('room_id', $roomId)))
            ->sum('total'), 2);
    }

    /**
     * Dinero que SALIÓ en la ventana. La devolución de una fianza no cuenta:
     * esa plata nunca fue ingreso.
     */
    public function refunds(CarbonInterface $from, CarbonInterface $to, ?int $roomId = null): float
    {
        return round((float) Refund::query()
            ->where('status', Refund::STATUS_COMPLETED)
            ->whereBetween('refunded_at', [$from, $to])
            ->whereDoesntHave('payment', fn ($q) => $q->where('kind', Payment::KIND_GUARANTEE))
            ->when($roomId, fn ($q) => $q->where(fn ($qq) => $qq
                ->whereHas('reservation', fn ($r) => $r->where('room_id', $roomId))
                ->orWhereHas('payment.stay', fn ($s) => $s->where('room_id', $roomId))))
            ->sum('amount'), 2);
    }

    /**
     * Cuánto entró cada día del rango, en DOS consultas agrupadas (no una
     * por día): los días se arman en PHP y los que no tuvieron movimiento
     * salen en cero, para que la gráfica no tenga huecos.
     *
     * Misma contabilidad que summary(): sin fianzas y sin contar dos veces
     * lo cargado a habitación.
     *
     * @return array<int, array{date: string, label: string, total: float}>
     */
    public function dailySeries(CarbonInterface $from, CarbonInterface $to): array
    {
        $paymentsByDay = Payment::query()
            ->whereBetween('paid_at', [$from, $to])
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', Payment::KIND_GUARANTEE))
            ->selectRaw('DATE(paid_at) AS day, SUM(amount) AS total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $ordersByDay = Order::query()
            ->where('status', Order::STATUS_COMPLETED)
            ->whereBetween('created_at', [$from, $to])
            ->where(fn ($q) => $q->whereNull('payment_method')->orWhere('payment_method', '!=', 'room'))
            ->selectRaw('DATE(created_at) AS day, SUM(total) AS total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = [];
        $day = \Carbon\CarbonImmutable::parse($from)->startOfDay();
        $last = \Carbon\CarbonImmutable::parse($to)->startOfDay();

        while ($day <= $last) {
            $key = $day->toDateString();

            $series[] = [
                'date' => $key,
                'label' => $day->locale('es')->isoFormat('D MMM'),
                'total' => round((float) ($paymentsByDay[$key] ?? 0) + (float) ($ordersByDay[$key] ?? 0), 2),
            ];

            $day = $day->addDay();
        }

        return $series;
    }

    /**
     * Desglose por método de pago, de mayor a menor. Los métodos conocidos
     * van primero; si aparece uno nuevo en la base, no se pierde.
     *
     * @param  Collection<int, Payment>  $income
     * @return array<int, array{method: string, label: string, amount: float, count: int}>
     */
    protected function byMethod(Collection $income): array
    {
        $known = [...Payment::METHODS, Payment::METHOD_ONLINE];

        return collect($known)
            ->map(fn (string $method) => [
                'method' => $method,
                'label' => Payment::methodLabel($method),
                'amount' => round((float) $income->where('method', $method)->sum('amount'), 2),
                'count' => $income->where('method', $method)->count(),
            ])
            ->concat($income
                ->reject(fn (Payment $p) => in_array($p->method, $known, true))
                ->groupBy('method')
                ->map(fn (Collection $group, string $method) => [
                    'method' => $method,
                    'label' => Payment::methodLabel($method),
                    'amount' => round((float) $group->sum('amount'), 2),
                    'count' => $group->count(),
                ])->values())
            ->filter(fn (array $row) => $row['count'] > 0)
            ->sortByDesc('amount')
            ->values()
            ->all();
    }
}
