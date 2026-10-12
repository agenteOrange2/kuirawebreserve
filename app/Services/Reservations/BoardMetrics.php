<?php

namespace App\Services\Reservations;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Las cifras de las pantallas de trabajo de reservas (operación, próximas,
 * en casa y pendientes) y de sus reportes. Viven juntas para que la franja
 * de cifras de la lista y las gráficas de /reservas/<pantalla>/reporte
 * cuenten con el mismo corte: si la lista dice "3 por vencer", la dona del
 * reporte dice 3.
 *
 * Todo describe el conjunto ENTERO, nunca la página ni el filtro que se ve.
 */
class BoardMetrics
{
    /** Lo que sigue apartado a futuro: confirmado o esperando confirmación. */
    public const UPCOMING_STATUSES = [ReservationStatus::Pending, ReservationStatus::Confirmed];

    /** Cortes de salida de "en casa" (chips, dona y filtro ?salida=). */
    public const DEPARTURES = ['vencida', 'hoy', 'manana', 'despues'];

    /** Cortes del reloj del apartado (chips, dona y filtro ?estado=). */
    public const HOLD_STATES = ['vencido', 'por_vencer', 'en_tiempo', 'sin_vencimiento'];

    /** Cómo viene pagada (dona y filtro ?pago=). */
    public const PAYMENTS = ['sin_pago', 'parcial', 'anticipo', 'pagada', 'vencido'];

    /** Días que pinta la gráfica de llegadas. */
    public const CHART_DAYS = 14;

    // ── Próximas ──────────────────────────────────────────────

    public function upcomingBase(?CarbonInterface $now = null): Builder
    {
        return Reservation::query()
            ->whereIn('status', self::UPCOMING_STATUSES)
            ->where('ends_at', '>=', $now ?? now());
    }

    /** Cómo viene pagada: el estado del cobro, o el plazo ya vencido. */
    public function wherePayment(Builder $query, string $payment, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return match ($payment) {
            'vencido' => $query->whereNotNull('payment_due_at')
                ->where('payment_due_at', '<', $now)
                ->where('payment_status', '<>', PaymentStatus::Paid),
            'sin_pago' => $query->where('payment_status', PaymentStatus::Unpaid),
            'parcial' => $query->where('payment_status', PaymentStatus::Partial),
            'anticipo' => $query->where('payment_status', PaymentStatus::DepositPaid),
            default => $query->where('payment_status', PaymentStatus::Paid),
        };
    }

    /**
     * La franja de próximas (y con $charts, las gráficas del reporte):
     * cuántas vienen, hoy y mañana, sin confirmar, cuánto falta cobrar.
     *
     * @return array<string, mixed>
     */
    public function upcoming(bool $charts = false): array
    {
        $now = now();
        $base = fn () => $this->upcomingBase($now);

        $total = (float) $base()->sum('total_amount');
        // La fianza no es del hospedaje: si se cuenta, el saldo sale corto.
        $paid = (float) Payment::query()
            ->whereIn('reservation_id', $base()->select('reservations.id'))
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '<>', 'guarantee'))
            ->sum('amount');
        $balance = round(max(0, $total - $paid), 2);

        // Una sola consulta para los días: alimenta los chips (hoy, mañana),
        // los grupos de la tabla y, en el reporte, las barras.
        $byDay = $base()
            ->whereBetween('starts_at', [$now->copy()->startOfDay(), $now->copy()->addDays(self::CHART_DAYS - 1)->endOfDay()])
            ->pluck('starts_at')
            ->countBy(fn ($start) => Carbon::parse($start)->format('Y-m-d'));

        $days = collect(range(0, self::CHART_DAYS - 1))->map(function (int $offset) use ($now, $byDay) {
            $day = $now->copy()->addDays($offset);

            return [
                'date' => $day->format('Y-m-d'),
                'label' => $offset === 0 ? 'Hoy' : ($offset === 1 ? 'Mañana' : str_replace('.', '', $day->locale('es')->isoFormat('ddd D'))),
                'count' => (int) ($byDay[$day->format('Y-m-d')] ?? 0),
            ];
        })->values();

        $overdue = $this->wherePayment($base(), 'vencido', $now)->count();

        $out = [
            'total' => $base()->count(),
            'today' => $days[0]['count'],
            'tomorrow' => $days[1]['count'],
            'pending' => $base()->where('status', ReservationStatus::Pending)->count(),
            'overdue_payments' => $overdue,
            'balance' => $balance,
            'balance_label' => '$'.number_format($balance, 2),
            'days' => $days,
        ];

        if ($charts) {
            // "Vencido" va aparte aunque su estado sea otro: es lo que hay
            // que perseguir, y así ninguna reserva sale dos veces.
            $byPayment = $base()
                ->whereNotIn('reservations.id', $this->wherePayment($base(), 'vencido', $now)->select('reservations.id'))
                ->selectRaw('payment_status, count(*) as total')
                ->groupBy('payment_status')
                ->pluck('total', 'payment_status');

            $out['payments'] = [
                'pagada' => (int) ($byPayment[PaymentStatus::Paid->value] ?? 0),
                'anticipo' => (int) ($byPayment[PaymentStatus::DepositPaid->value] ?? 0),
                'parcial' => (int) ($byPayment[PaymentStatus::Partial->value] ?? 0),
                'sin_pago' => (int) ($byPayment[PaymentStatus::Unpaid->value] ?? 0),
                'vencido' => $overdue,
            ];
        }

        return $out;
    }

    // ── En casa ───────────────────────────────────────────────

    /**
     * Saldo de la estancia en SQL (hospedaje que falta, nunca negativo, más
     * los consumos cargados a la habitación sin liquidar). Mismas cuentas
     * que Stay::scopeWithBalance, para poder filtrar por él.
     */
    public function stayBalanceSql(): string
    {
        $payments = fn (string $where) => "(select coalesce(sum(p.amount), 0) from payments p where {$where})";
        $lodgingTotal = 'coalesce((select r.total_amount from reservations r where r.id = stays.reservation_id), stays.amount)';
        $lodgingPaid = '(case when stays.reservation_id is null then '
            .$payments("p.stay_id = stays.id and p.kind = 'lodging'")
            .' else '.$payments('p.reservation_id = stays.reservation_id').' end)';
        $consumption = "(select coalesce(sum(o.total), 0) from orders o where o.stay_id = stays.id and o.status = 'completed'"
            ." and o.payment_method = 'room' and o.settled_at is null)";

        return "((case when {$lodgingTotal} - {$lodgingPaid} > 0 then {$lodgingTotal} - {$lodgingPaid} else 0 end) + {$consumption})";
    }

    /** Vencida = ya pasó su hora; hoy / mañana / después, por día calendario. */
    public function whereDeparture(Builder $query, string $when, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return match ($when) {
            'vencida' => $query->where('planned_end_at', '<', $now),
            'hoy' => $query->whereBetween('planned_end_at', [$now, $now->copy()->endOfDay()]),
            'manana' => $query->whereBetween('planned_end_at', [
                $now->copy()->addDay()->startOfDay(),
                $now->copy()->addDay()->endOfDay(),
            ]),
            default => $query->where('planned_end_at', '>', $now->copy()->addDay()->endOfDay()),
        };
    }

    /** El corte de salida de una estancia (mismo que whereDeparture). */
    public function departureOf(Stay $stay, ?CarbonInterface $now = null): string
    {
        $now ??= now();
        $end = $stay->planned_end_at;

        return match (true) {
            $end->lt($now) => 'vencida',
            $end->isSameDay($now) => 'hoy',
            $end->isSameDay($now->copy()->addDay()) => 'manana',
            default => 'despues',
        };
    }

    /**
     * El estado de la casa: habitaciones en uso, gente dentro, salidas por
     * corte, cuánto se debe y (reporte) por dónde llegaron. Las estancias
     * vivas son tantas como habitaciones: caben en una sola consulta.
     *
     * @return array<string, mixed>
     */
    public function inHouse(bool $charts = false): array
    {
        $now = now();
        $all = Stay::query()->active()->withBalance()->get();

        $buckets = array_fill_keys(self::DEPARTURES, 0);
        foreach ($all as $stay) {
            $buckets[$this->departureOf($stay, $now)]++;
        }

        $balance = round($all->sum(fn (Stay $stay) => $stay->pendingSettlementAmount()), 2);

        $out = [
            'rooms' => $all->count(),
            'guests' => (int) $all->sum('num_people'),
            'departures_today' => $buckets['hoy'],
            'overdue' => $buckets['vencida'],
            'with_balance' => $all->filter(fn (Stay $stay) => $stay->pendingSettlementAmount() > 0.009)->count(),
            'balance' => $balance,
            'balance_label' => '$'.number_format($balance, 2),
            'departures' => $buckets,
        ];

        if ($charts) {
            // Con reserva o sin ella, y por dónde: "¿cuántos vienen del
            // sitio y cuántos llegan a la puerta?".
            $out['channels'] = $all
                ->groupBy(fn (Stay $stay) => $stay->reservation_id ? ($stay->channel ?: 'front_desk') : 'walk_in')
                ->map(fn ($group, $channel) => ['channel' => (string) $channel, 'count' => $group->count()])
                ->sortByDesc('count')
                ->values();
        }

        return $out;
    }

    // ── Pendientes ────────────────────────────────────────────

    public function pendingBase(?CarbonInterface $now = null): Builder
    {
        // Un apartado de una fecha que ya pasó no es trabajo pendiente: lo
        // resolvió el vencimiento o el no-show.
        return Reservation::query()
            ->where('status', ReservationStatus::Pending)
            ->where('ends_at', '>=', $now ?? now());
    }

    /** El reloj del apartado: el mismo corte de 30 min que holdClock(). */
    public function whereHoldState(Builder $query, string $state, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return match ($state) {
            'vencido' => $query->whereNotNull('hold_expires_at')->where('hold_expires_at', '<', $now),
            'por_vencer' => $query->whereBetween('hold_expires_at', [$now, $now->copy()->addMinutes(30)]),
            'en_tiempo' => $query->where('hold_expires_at', '>', $now->copy()->addMinutes(30)),
            'sin_vencimiento' => $query->whereNull('hold_expires_at'),
            default => $query->whereHas('paymentRequests', fn ($q) => $q
                ->where('method', PaymentRequest::METHOD_TRANSFER)
                ->where('status', PaymentRequest::STATUS_PENDING)),
        };
    }

    /**
     * Lo que está en juego: apartados por corte del reloj, transferencias
     * por verificar, lo que falta cobrar de ellos, las cuentas cerradas con
     * saldo y (reporte) de qué canal vienen.
     *
     * @return array<string, mixed>
     */
    public function pending(bool $charts = false): array
    {
        $now = now();
        $base = fn () => $this->pendingBase($now);

        $total = (float) $base()->sum('total_amount');
        $paid = (float) Payment::query()
            ->whereIn('reservation_id', $base()->select('reservations.id'))
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '<>', 'guarantee'))
            ->sum('amount');
        $balance = round(max(0, $total - $paid), 2);

        // El saldo de las cuentas cerradas: contarlas no dice cuánto dinero
        // hay ahí afuera, y es la cifra por la que pregunta el dueño.
        $settlements = Stay::query()->pendingSettlement()->get();
        $settlementAmount = round((float) $settlements->sum(fn (Stay $stay) => $stay->pendingSettlementAmount()), 2);

        $states = [];
        foreach (self::HOLD_STATES as $key) {
            $states[$key] = $this->whereHoldState($base(), $key, $now)->count();
        }

        $out = [
            'holds' => $base()->count(),
            'expiring' => $states['por_vencer'],
            'expired' => $states['vencido'],
            'states' => $states,
            'transfers' => $this->whereHoldState($base(), 'transferencia', $now)->count(),
            'balance' => $balance,
            'balance_label' => '$'.number_format($balance, 2),
            'settlements' => $settlements->count(),
            'settlement_amount' => $settlementAmount,
            'settlement_amount_label' => '$'.number_format($settlementAmount, 2),
        ];

        if ($charts) {
            // Si el sitio o el asistente dejan muchos colgados, ahí está el
            // trabajo.
            $out['channels'] = $base()
                ->selectRaw('source_channel, count(*) as total')
                ->groupBy('source_channel')
                ->pluck('total', 'source_channel')
                ->map(fn ($n, $channel) => ['channel' => (string) $channel, 'count' => (int) $n])
                ->sortByDesc('count')
                ->values();
        }

        return $out;
    }

    // ── Operación del día ─────────────────────────────────────

    /**
     * Cómo va el día en el mostrador. Llegadas = reservas con entrada hoy
     * (las recibidas cuentan como hechas) más quien llegó sin reserva hoy;
     * salidas = estancias que cerraron hoy más las vivas que todavía deben
     * salir hoy (o ya debían). Dos consultas agregadas.
     *
     * @return array<string, int>
     */
    public function dayProgress(): array
    {
        $start = now()->startOfDay();
        $end = now()->endOfDay();
        $done = [ReservationStatus::CheckedIn->value, ReservationStatus::Completed->value];

        $arrivals = Reservation::query()
            ->whereBetween('starts_at', [$start, $end])
            ->whereIn('status', [
                ReservationStatus::Pending,
                ReservationStatus::Confirmed,
                ReservationStatus::CheckedIn,
                ReservationStatus::Completed,
            ])
            ->selectRaw('count(*) as expected')
            ->selectRaw('sum(case when status in (?, ?) then 1 else 0 end) as done', $done)
            ->first();

        $stays = Stay::query()
            ->where(fn ($q) => $q
                ->where('status', Stay::STATUS_ACTIVE)
                ->orWhere('check_in_at', '>=', $start)
                ->orWhere('check_out_at', '>=', $start))
            ->selectRaw('sum(case when reservation_id is null and check_in_at between ? and ? then 1 else 0 end) as walk_ins', [$start, $end])
            ->selectRaw('sum(case when status = ? and check_out_at between ? and ? then 1 else 0 end) as departures_done', [Stay::STATUS_COMPLETED, $start, $end])
            ->selectRaw('sum(case when status = ? and planned_end_at <= ? then 1 else 0 end) as departures_left', [Stay::STATUS_ACTIVE, $end])
            ->first();

        return [
            'arrivals_expected' => (int) ($arrivals?->expected ?? 0),
            'arrivals_done' => (int) ($arrivals?->done ?? 0),
            'walk_ins' => (int) ($stays?->walk_ins ?? 0),
            'departures_done' => (int) ($stays?->departures_done ?? 0),
            'departures_left' => (int) ($stays?->departures_left ?? 0),
        ];
    }

    /**
     * El semáforo de habitaciones ahora (mismo conteo que el dashboard).
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    public function roomStatuses(): array
    {
        $byStatus = Room::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(RoomStatus::cases())
            ->map(fn (RoomStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($byStatus[$status->value] ?? 0),
            ])
            ->values()
            ->all();
    }
}
