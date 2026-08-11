<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard del hotel: hero de ingresos y rejilla de KPIs del periodo
 * elegido (hoy / esta semana / este mes / personalizado), comparados contra
 * el periodo anterior equivalente; series diarias para los mini-charts, y
 * la foto operativa del día (semáforo, llegadas/salidas, actividad).
 *
 * El dinero sigue la misma contabilidad que los cortes: las fianzas no son
 * ingreso, y los consumos cargados a habitación cuentan una sola vez (al
 * liquidarse en el folio, no cuando se levanta la orden).
 */
class DashboardController extends Controller
{
    private const MAX_CUSTOM_DAYS = 92;

    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'range' => ['nullable', 'in:today,week,month,custom'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $now = CarbonImmutable::now();
        $today = $now->startOfDay();

        $range = $data['range'] ?? 'today';
        if ($range === 'custom' && empty($data['from'])) {
            $range = 'today';
        }

        [$start, $end, $periodLabel] = $this->resolvePeriod($range, $data, $today);

        // Un periodo a medias se compara contra el MISMO avance del anterior
        // (miércoles de esta semana vs. miércoles de la pasada), no contra el
        // periodo completo.
        $elapsedEnd = $end->min($now);
        [$prevStart, $prevEnd] = $this->previousPeriod($range, $start, $elapsedEnd);

        $pct = static function (float $cur, float $prev): ?int {
            if ($prev <= 0.0) {
                return $cur > 0 ? 100 : null;
            }

            return (int) round(($cur - $prev) / $prev * 100);
        };

        // --- Semáforo de habitaciones (estado actual, no depende del filtro) ---
        $byStatus = Room::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $statuses = collect(RoomStatus::cases())->map(fn (RoomStatus $status) => [
            'value' => $status->value,
            'label' => $status->label(),
            'color' => $status->color(),
            'count' => (int) ($byStatus[$status->value] ?? 0),
        ]);

        $totalRooms = (int) $byStatus->sum();
        $occupied = (int) ($byStatus[RoomStatus::Occupied->value] ?? 0);
        $reserved = (int) ($byStatus[RoomStatus::Reserved->value] ?? 0);
        $available = (int) ($byStatus[RoomStatus::Available->value] ?? 0);
        $occupancyPct = $totalRooms > 0 ? (int) round($occupied / $totalRooms * 100) : 0;

        // --- Dinero del periodo vs. anterior ---
        $revenue = $this->revenueBetween($start, $elapsedEnd);
        $revenuePrev = $this->revenueBetween($prevStart, $prevEnd);

        // --- Ocupación por día: una sola consulta de estancias que tocan
        // cualquiera de las ventanas involucradas, bucketing en PHP ---
        $seriesStart = $range === 'today' ? $today->subDays(6) : $start;
        $seriesEnd = $end->min($now->endOfDay());

        $stays = Stay::query()
            ->where('check_in_at', '<=', $seriesEnd->max($elapsedEnd))
            ->where(fn ($q) => $q
                ->whereNull('check_out_at')
                ->orWhere('check_out_at', '>=', $prevStart->min($seriesStart)))
            ->get(['check_in_at', 'check_out_at']);

        $occupiedOn = function (CarbonImmutable $day) use ($stays): int {
            $dayEnd = $day->endOfDay();

            return $stays
                ->filter(fn (Stay $s) => $s->check_in_at <= $dayEnd
                    && ($s->check_out_at === null || $s->check_out_at >= $day))
                ->count();
        };

        $roomNights = $this->sumDays($start, $elapsedEnd, $occupiedOn);
        $roomNightsPrev = $this->sumDays($prevStart, $prevEnd, $occupiedOn);

        $periodDays = max(1, (int) $start->diffInDays($elapsedEnd) + 1);
        $prevDays = max(1, (int) $prevStart->diffInDays($prevEnd) + 1);

        $occupancyAvg = $totalRooms > 0 ? (int) round($roomNights / ($periodDays * $totalRooms) * 100) : 0;
        $occupancyAvgPrev = $totalRooms > 0 ? (int) round($roomNightsPrev / ($prevDays * $totalRooms) * 100) : 0;

        // --- Conteos del periodo vs. anterior ---
        $counts = $this->periodCounts($start, $elapsedEnd);
        $countsPrev = $this->periodCounts($prevStart, $prevEnd);

        // --- Series diarias para los mini-charts ---
        [$revenueSeries, $occupancySeries] = $this->buildSeries($seriesStart, $seriesEnd, $occupiedOn, $totalRooms);

        // --- Rejilla de métricas del periodo ---
        $metrics = [
            ['title' => 'Ingresos', 'value' => $this->money($revenue['total']), 'change' => $pct($revenue['total'], $revenuePrev['total']), 'desc' => 'Hospedaje cobrado más ventas de POS en el periodo.'],
            ['title' => 'Hospedaje cobrado', 'value' => $this->money($revenue['lodging']), 'change' => $pct($revenue['lodging'], $revenuePrev['lodging']), 'desc' => 'Pagos de reservas y estancias; las fianzas no cuentan.'],
            ['title' => 'Consumo y POS', 'value' => $this->money($revenue['pos']), 'change' => $pct($revenue['pos'], $revenuePrev['pos']), 'desc' => 'Ventas de barra/cocina y consumos liquidados en folio.'],
            ['title' => 'Ocupación promedio', 'value' => $occupancyAvg.'%', 'change' => $pct($occupancyAvg, $occupancyAvgPrev), 'desc' => 'Promedio diario de habitaciones ocupadas en el periodo.'],
            ['title' => 'Noches vendidas', 'value' => (string) $roomNights, 'change' => $pct($roomNights, $roomNightsPrev), 'desc' => 'Noches-habitación ocupadas en el periodo.'],
            ['title' => 'Reservas nuevas', 'value' => (string) $counts['created'], 'change' => $pct($counts['created'], $countsPrev['created']), 'desc' => 'Reservas capturadas en el periodo, por cualquier canal.'],
            ['title' => 'Llegadas', 'value' => (string) $counts['arrivals'], 'change' => $pct($counts['arrivals'], $countsPrev['arrivals']), 'desc' => 'Reservas con llegada dentro del periodo.'],
            ['title' => 'Check-ins', 'value' => (string) $counts['check_ins'], 'change' => $pct($counts['check_ins'], $countsPrev['check_ins']), 'desc' => 'Huéspedes registrados en el periodo.'],
            ['title' => 'Check-outs', 'value' => (string) $counts['check_outs'], 'change' => $pct($counts['check_outs'], $countsPrev['check_outs']), 'desc' => 'Salidas procesadas en el periodo.'],
            ['title' => 'Canceladas', 'value' => (string) $counts['cancelled'], 'change' => $pct($counts['cancelled'], $countsPrev['cancelled']), 'desc' => 'Reservas canceladas con llegada en el periodo.'],
            ['title' => 'Ticket promedio POS', 'value' => $this->money($counts['avg_order']), 'change' => $pct($counts['avg_order'], $countsPrev['avg_order']), 'desc' => 'Importe medio por orden de POS del periodo.'],
            ['title' => 'Huéspedes nuevos', 'value' => (string) $counts['guests'], 'change' => $pct($counts['guests'], $countsPrev['guests']), 'desc' => 'Huéspedes dados de alta en el periodo.'],
        ];

        // --- Movimiento del día (operación de HOY, no depende del filtro) ---
        $arrivals = Reservation::query()
            ->with(['room:id,number'])
            ->whereDate('starts_at', $today)
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->orderBy('starts_at')
            ->get();

        $departures = Reservation::query()
            ->with(['room:id,number'])
            ->whereDate('ends_at', $today)
            ->where('status', ReservationStatus::CheckedIn)
            ->orderBy('ends_at')
            ->get();

        $inHouse = Reservation::query()->where('status', ReservationStatus::CheckedIn)->count();
        $pendingReservations = Reservation::query()->where('status', ReservationStatus::Pending)->count();
        $checkOutsToday = Stay::query()->whereDate('check_out_at', $today)->count();

        $roomTypeDistribution = RoomType::query()
            ->withCount('rooms')
            ->orderByDesc('rooms_count')
            ->get()
            ->map(fn (RoomType $type) => ['label' => $type->name, 'count' => $type->rooms_count])
            ->filter(fn ($t) => $t['count'] > 0)
            ->values();

        $limits = tenant()?->planLimits() ?? [];

        $recentActivity = RoomStatusLog::query()
            ->with(['room:id,number', 'changedBy:id,name'])
            ->latest('created_at')
            ->take(6)
            ->get()
            ->map(fn (RoomStatusLog $log) => [
                'id' => $log->id,
                'room' => $log->room?->number,
                'from' => $log->from_status ? RoomStatus::tryFrom($log->from_status)?->label() : null,
                'to' => RoomStatus::tryFrom($log->to_status)?->label() ?? $log->to_status,
                'to_color' => RoomStatus::tryFrom($log->to_status)?->color() ?? 'gray',
                'by' => $log->changedBy?->name ?? 'Sistema',
                'at' => $log->created_at->diffForHumans(),
            ]);

        // Holds por vencer (spec-plan-maestro E4): apartados pendientes cuyo
        // hold expira en los próximos 30 minutos.
        $expiringHolds = Reservation::query()
            ->with('room:id,number')
            ->where('status', ReservationStatus::Pending)
            ->whereNotNull('hold_expires_at')
            ->whereBetween('hold_expires_at', [$now, $now->addMinutes(30)])
            ->orderBy('hold_expires_at')
            ->get()
            ->map(fn (Reservation $r) => [
                'id' => $r->id,
                'code' => $r->displayCode(),
                'guest_name' => $r->guest_name,
                'room' => $r->room?->number,
                'expires_at' => $r->hold_expires_at->format('H:i'),
            ]);

        return Inertia::render('tenant/Dashboard', [
            'filters' => [
                'range' => $range,
                'from' => $range === 'custom' ? $start->toDateString() : null,
                'to' => $range === 'custom' ? $end->toDateString() : null,
            ],
            'expiringHolds' => $expiringHolds,
            'hero' => [
                'revenue' => $this->money($revenue['total']),
                'change' => $pct($revenue['total'], $revenuePrev['total']),
                'period' => $periodLabel,
            ],
            'metrics' => $metrics,
            'series' => [
                'label' => $range === 'today' ? 'Últimos 7 días' : ucfirst($periodLabel),
                'revenue' => $revenueSeries,
                'occupancy' => $occupancySeries,
                'revenue_total' => $revenue['total'],
                'revenue_change' => $pct($revenue['total'], $revenuePrev['total']),
                'occupancy_avg' => $occupancyAvg,
                'occupancy_change' => $pct($occupancyAvg, $occupancyAvgPrev),
            ],
            'guestStatus' => [
                'in_house' => $inHouse,
                'checked_out' => $checkOutsToday,
                'pending' => $pendingReservations,
            ],
            'roomTypeDistribution' => $roomTypeDistribution,
            'statuses' => $statuses,
            'occupancy' => ['occupied' => $occupied, 'total' => $totalRooms, 'percent' => $occupancyPct, 'reserved' => $reserved, 'available' => $available],
            'arrivals' => $arrivals->map(fn (Reservation $r) => [
                'id' => $r->id,
                'code' => $r->displayCode(),
                'guest_name' => $r->guest_name,
                'room' => $r->room?->number,
                'eta' => $r->eta ? substr((string) $r->eta, 0, 5) : null,
                'time' => $r->starts_at->format('H:i'),
                'people' => $r->num_people,
                'checked_in' => $r->status === ReservationStatus::CheckedIn,
            ]),
            'departures' => $departures->map(fn (Reservation $r) => [
                'id' => $r->id,
                'code' => $r->displayCode(),
                'guest_name' => $r->guest_name,
                'room' => $r->room?->number,
                'time' => $r->ends_at->format('H:i'),
                'balance' => $r->pendingBalance(),
            ]),
            'totals' => [
                'rooms' => $totalRooms,
                'zones' => Zone::count(),
                'roomTypes' => RoomType::count(),
                'staff' => User::count(),
                'properties' => Property::count(),
            ],
            'plan' => [
                'name' => $limits['label'] ?? tenant('plan'),
                'max_rooms' => $limits['max_rooms'] ?? null,
                'max_users' => $limits['max_users'] ?? null,
            ],
            'recentActivity' => $recentActivity,
        ]);
    }

    /**
     * Resuelve el rango elegido a [inicio, fin, etiqueta]. El personalizado
     * se recorta a MAX_CUSTOM_DAYS para no barrer la base entera.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function resolvePeriod(string $range, array $data, CarbonImmutable $today): array
    {
        if ($range === 'week') {
            return [$today->startOfWeek(), $today->endOfWeek(), 'esta semana'];
        }

        if ($range === 'month') {
            return [
                $today->startOfMonth(),
                $today->endOfMonth(),
                $today->locale('es')->isoFormat('MMMM YYYY'),
            ];
        }

        if ($range === 'custom') {
            $from = CarbonImmutable::parse($data['from'])->startOfDay();
            $to = CarbonImmutable::parse($data['to'] ?? $data['from'])->endOfDay();

            if ($from->diffInDays($to) > self::MAX_CUSTOM_DAYS) {
                $to = $from->addDays(self::MAX_CUSTOM_DAYS)->endOfDay();
            }

            $label = $from->isSameDay($to)
                ? $from->locale('es')->isoFormat('D MMM YYYY')
                : $from->locale('es')->isoFormat('D MMM').' – '.$to->locale('es')->isoFormat('D MMM YYYY');

            return [$from, $to, $label];
        }

        return [$today, $today->endOfDay(), 'hoy'];
    }

    /**
     * Periodo anterior equivalente: misma duración transcurrida, pegada al
     * inicio del rango natural anterior (ayer, semana pasada, mes pasado).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function previousPeriod(string $range, CarbonImmutable $start, CarbonImmutable $elapsedEnd): array
    {
        $prevStart = match ($range) {
            'week' => $start->subWeek(),
            'month' => $start->subMonthNoOverflow(),
            'custom' => $start->subDays((int) $start->diffInDays($elapsedEnd) + 1),
            default => $start->subDay(),
        };

        return [$prevStart, $prevStart->addSeconds((int) $start->diffInSeconds($elapsedEnd))];
    }

    /**
     * Dinero cobrado en la ventana, con la contabilidad de los cortes:
     * hospedaje = abonos de reservas/estancias (sin fianzas), POS = órdenes
     * cobradas en mostrador + consumos liquidados en folio. Lo cargado a
     * habitación (`payment_method = room`) NO suma al ordenarse — suma una
     * sola vez cuando el folio lo liquida (pago kind consumption).
     *
     * @return array{lodging: float, pos: float, total: float}
     */
    private function revenueBetween(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $payments = Payment::query()
            ->whereBetween('paid_at', [$start, $end])
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', Payment::KIND_GUARANTEE))
            ->selectRaw("COALESCE(kind, '') AS kind, SUM(amount) AS total")
            ->groupByRaw("COALESCE(kind, '')")
            ->pluck('total', 'kind');

        $consumption = (float) ($payments[Payment::KIND_CONSUMPTION] ?? 0);
        $lodging = (float) $payments->sum() - $consumption;

        $orders = (float) Order::query()
            ->where('status', Order::STATUS_COMPLETED)
            ->whereBetween('created_at', [$start, $end])
            ->where(fn ($q) => $q->whereNull('payment_method')->orWhere('payment_method', '!=', 'room'))
            ->sum('total');

        $pos = round($orders + $consumption, 2);
        $lodging = round($lodging, 2);

        return [
            'lodging' => $lodging,
            'pos' => $pos,
            'total' => round($lodging + $pos, 2),
        ];
    }

    /**
     * Conteos operativos de la ventana.
     *
     * @return array{created: int, arrivals: int, check_ins: int, check_outs: int, cancelled: int, avg_order: float, guests: int}
     */
    private function periodCounts(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            'created' => Reservation::query()->whereBetween('created_at', [$start, $end])->count(),
            'arrivals' => Reservation::query()
                ->whereBetween('starts_at', [$start, $end])
                ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::Completed])
                ->count(),
            'check_ins' => Stay::query()->whereBetween('check_in_at', [$start, $end])->count(),
            'check_outs' => Stay::query()->whereBetween('check_out_at', [$start, $end])->count(),
            'cancelled' => Reservation::query()
                ->where('status', ReservationStatus::Cancelled)
                ->whereBetween('starts_at', [$start, $end])
                ->count(),
            'avg_order' => (float) Order::query()
                ->where('status', Order::STATUS_COMPLETED)
                ->whereBetween('created_at', [$start, $end])
                ->avg('total'),
            'guests' => Guest::query()->whereBetween('created_at', [$start, $end])->count(),
        ];
    }

    /**
     * Series diarias de ingresos y ocupación para los mini-charts, en dos
     * consultas (los días se arman en PHP).
     *
     * @return array{0: array<int, array{label: string, value: float}>, 1: array<int, array{label: string, value: int}>}
     */
    private function buildSeries(CarbonImmutable $start, CarbonImmutable $end, callable $occupiedOn, int $totalRooms): array
    {
        $paymentsByDay = Payment::query()
            ->whereBetween('paid_at', [$start, $end])
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', Payment::KIND_GUARANTEE))
            ->selectRaw('DATE(paid_at) AS day, SUM(amount) AS total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $ordersByDay = Order::query()
            ->where('status', Order::STATUS_COMPLETED)
            ->whereBetween('created_at', [$start, $end])
            ->where(fn ($q) => $q->whereNull('payment_method')->orWhere('payment_method', '!=', 'room'))
            ->selectRaw('DATE(created_at) AS day, SUM(total) AS total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $spanDays = (int) $start->diffInDays($end) + 1;
        $revenueSeries = [];
        $occupancySeries = [];

        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            $key = $day->toDateString();
            $label = $spanDays <= 7
                ? ucfirst($day->locale('es')->isoFormat('dd'))
                : $day->locale('es')->isoFormat('D MMM');

            $revenueSeries[] = [
                'label' => $label,
                'value' => round((float) ($paymentsByDay[$key] ?? 0) + (float) ($ordersByDay[$key] ?? 0), 2),
            ];
            $occupancySeries[] = [
                'label' => $label,
                'value' => $totalRooms > 0 ? (int) round($occupiedOn($day) / $totalRooms * 100) : 0,
            ];
        }

        return [$revenueSeries, $occupancySeries];
    }

    /** Suma el resultado de $fn para cada día de la ventana (por día natural). */
    private function sumDays(CarbonImmutable $start, CarbonImmutable $end, callable $fn): int
    {
        $total = 0;
        for ($day = $start->startOfDay(); $day <= $end; $day = $day->addDay()) {
            $total += $fn($day);
        }

        return $total;
    }

    private function money(float $amount): string
    {
        return '$'.number_format($amount, 0, '.', ',');
    }
}
