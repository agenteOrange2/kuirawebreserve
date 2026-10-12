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
use Carbon\CarbonInterface;
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
 *
 * La ocupación cuenta noches, no estancias: un hotel que vende por chat y
 * cobra por transferencia casi nunca abre el plano, así que medirla solo
 * con los check-ins registrados le mostraba 0% con la casa llena. Se suman
 * las reservas vendidas que no tienen estancia; las que sí la tienen se
 * cuentan una sola vez.
 */
class DashboardController extends Controller
{
    private const MAX_CUSTOM_DAYS = 92;

    /** Tope de renglones de llegadas y salidas: el resto vive en su lista. */
    private const LIST_LIMIT = 8;

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

        $windowStart = $prevStart->min($seriesStart);
        $windowEnd = $seriesEnd->max($elapsedEnd);

        $stays = Stay::query()
            ->where('check_in_at', '<=', $windowEnd)
            ->where(fn ($q) => $q
                ->whereNull('check_out_at')
                ->orWhere('check_out_at', '>=', $windowStart))
            ->get(['reservation_id', 'check_in_at', 'check_out_at']);

        // Reservas vendidas a las que nadie les registró la llegada. Sin
        // ellas la ocupación sale en cero en un hotel que cobra por chat y
        // nunca abre el plano: el cierre de día completa la reserva, pero
        // como no hubo check-in no existe estancia que contar. Se excluyen
        // las que SÍ tienen estancia para no contar la misma noche dos
        // veces.
        $stayed = $stays->pluck('reservation_id')->filter()->unique()->all();

        $soldStays = Reservation::query()
            ->whereIn('status', [
                ReservationStatus::Confirmed,
                ReservationStatus::CheckedIn,
                ReservationStatus::Completed,
            ])
            ->where('starts_at', '<=', $windowEnd)
            ->where('ends_at', '>=', $windowStart)
            ->when($stayed !== [], fn ($q) => $q->whereKeyNot($stayed))
            ->get(['starts_at', 'ends_at']);

        // Una estancia ocupa el día D si ENTRÓ ese día, o si entró antes y
        // sigue ahí al terminar D. Lo segundo evita contar la salida de las
        // 11 AM como una noche más; lo primero es lo que no se puede perder:
        // en un motel el bloque de 3 horas entra y sale el mismo día, y con
        // solo "sigue ahí al cerrar el día" desaparecía del dashboard (en La
        // Cúpula eran 7 de cada 28 estancias).
        $occupies = static function (CarbonInterface $from, ?CarbonInterface $to, CarbonImmutable $day): bool {
            $dayEnd = $day->endOfDay();

            return $from <= $dayEnd
                && ($from >= $day || $to === null || $to > $dayEnd);
        };

        $occupiedOn = function (CarbonImmutable $day) use ($stays, $soldStays, $occupies): int {
            $registered = $stays
                ->filter(fn (Stay $s) => $occupies($s->check_in_at, $s->check_out_at, $day))
                ->count();

            $sold = $soldStays
                ->filter(fn (Reservation $r) => $occupies($r->starts_at, $r->ends_at, $day))
                ->count();

            return $registered + $sold;
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
            ['key' => 'revenue', 'title' => 'Ingresos', 'value' => $this->money($revenue['total']), 'change' => $pct($revenue['total'], $revenuePrev['total']), 'desc' => 'Hospedaje cobrado más ventas de POS en el periodo.'],
            ['key' => 'lodging', 'title' => 'Hospedaje cobrado', 'value' => $this->money($revenue['lodging']), 'change' => $pct($revenue['lodging'], $revenuePrev['lodging']), 'desc' => 'Pagos de reservas y estancias; las fianzas no cuentan.'],
            ['key' => 'pos', 'title' => 'Consumo y POS', 'value' => $this->money($revenue['pos']), 'change' => $pct($revenue['pos'], $revenuePrev['pos']), 'desc' => 'Ventas de barra/cocina y consumos liquidados en folio.'],
            ['key' => 'occupancy', 'title' => 'Ocupación promedio', 'value' => $occupancyAvg.'%', 'change' => $pct($occupancyAvg, $occupancyAvgPrev), 'desc' => 'Promedio diario de habitaciones ocupadas: estancias registradas más reservas vendidas sin check-in.'],
            ['key' => 'nights', 'title' => 'Noches vendidas', 'value' => (string) $roomNights, 'change' => $pct($roomNights, $roomNightsPrev), 'desc' => 'Noches-habitación vendidas en el periodo, se haya registrado la llegada o no.'],
            ['key' => 'created', 'title' => 'Reservas nuevas', 'value' => (string) $counts['created'], 'change' => $pct($counts['created'], $countsPrev['created']), 'desc' => 'Reservas capturadas en el periodo, por cualquier canal.'],
            ['key' => 'arrivals', 'title' => 'Llegadas', 'value' => (string) $counts['arrivals'], 'change' => $pct($counts['arrivals'], $countsPrev['arrivals']), 'desc' => 'Reservas con llegada dentro del periodo.'],
            ['key' => 'check_ins', 'title' => 'Check-ins', 'value' => (string) $counts['check_ins'], 'change' => $pct($counts['check_ins'], $countsPrev['check_ins']), 'desc' => 'Huéspedes registrados en el periodo.'],
            ['key' => 'check_outs', 'title' => 'Check-outs', 'value' => (string) $counts['check_outs'], 'change' => $pct($counts['check_outs'], $countsPrev['check_outs']), 'desc' => 'Salidas procesadas en el periodo.'],
            ['key' => 'cancelled', 'title' => 'Canceladas', 'value' => (string) $counts['cancelled'], 'change' => $pct($counts['cancelled'], $countsPrev['cancelled']), 'desc' => 'Reservas canceladas con llegada en el periodo.'],
            ['key' => 'avg_order', 'title' => 'Ticket promedio POS', 'value' => $this->money($counts['avg_order']), 'change' => $pct($counts['avg_order'], $countsPrev['avg_order']), 'desc' => 'Importe medio por orden de POS del periodo.'],
            ['key' => 'guests', 'title' => 'Huéspedes nuevos', 'value' => (string) $counts['guests'], 'change' => $pct($counts['guests'], $countsPrev['guests']), 'desc' => 'Huéspedes dados de alta en el periodo.'],
        ];

        // --- Movimiento del día (operación de HOY, no depende del filtro) ---
        $arrivals = Reservation::query()
            ->with(['room:id,number'])
            ->whereDate('starts_at', $today)
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->orderBy('starts_at')
            ->get();

        // Salidas: se leen de las ESTANCIAS abiertas, no de las reservas. En
        // un motel casi todo entra por mostrador sin reserva (La Cúpula: 21
        // de 28 estancias) y con reservas el tablero decía "nadie sale hoy"
        // con la casa llena. Incluye las que ya se pasaron de su hora: son
        // justo las que hay que ir a tocar.
        $departuresQuery = Stay::query()
            ->active()
            ->whereNotNull('planned_end_at')
            ->where('planned_end_at', '<=', $today->endOfDay());

        $departuresTotal = (clone $departuresQuery)->count();
        $overdueStays = (clone $departuresQuery)->where('planned_end_at', '<', $now)->count();

        $departures = $departuresQuery
            ->with(['room:id,number', 'reservation'])
            ->orderBy('planned_end_at')
            ->take(self::LIST_LIMIT)
            ->get();

        // En casa = estancias abiertas (con o sin reserva).
        $inHouse = Stay::query()->active()->count();
        $pendingReservations = Reservation::query()->where('status', ReservationStatus::Pending)->count();
        $arrivalsTotal = $arrivals->count();
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
            ->take(5)
            ->get()
            ->map(fn (RoomStatusLog $log) => [
                'id' => $log->id,
                'room' => $log->room?->number,
                'room_id' => $log->room_id,
                'from' => $log->from_status ? RoomStatus::tryFrom($log->from_status)?->label() : null,
                'to' => RoomStatus::tryFrom($log->to_status)?->label() ?? $log->to_status,
                'to_color' => RoomStatus::tryFrom($log->to_status)?->color() ?? 'gray',
                'by' => $log->changedBy?->name ?? 'Sistema',
                'at' => $log->created_at->diffForHumans(short: true),
                'at_full' => $log->created_at->isoFormat('D MMM YYYY, HH:mm'),
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
            // Mantenimiento pendiente: sin esto una falla puede quedarse
            // semanas abierta sin que nadie la vea al entrar al panel.
            'maintenance' => $this->maintenance(),
            'statuses' => $statuses,
            'occupancy' => ['occupied' => $occupied, 'total' => $totalRooms, 'percent' => $occupancyPct, 'reserved' => $reserved, 'available' => $available],
            'arrivals' => $arrivals->take(self::LIST_LIMIT)->map(fn (Reservation $r) => [
                'id' => $r->id,
                'code' => $r->displayCode(),
                'guest_name' => $r->guest_name,
                'room' => $r->room?->number,
                'eta' => $r->eta ? substr((string) $r->eta, 0, 5) : null,
                'time' => $r->starts_at->format('H:i'),
                'people' => $r->num_people,
                'checked_in' => $r->status === ReservationStatus::CheckedIn,
                'pending' => $r->status === ReservationStatus::Pending,
            ])->values(),
            'departures' => $departures->map(fn (Stay $s) => [
                'id' => $s->id,
                'reservation_id' => $s->reservation_id,
                'room_id' => $s->room_id,
                'code' => $s->reservation?->displayCode(),
                'guest_name' => $s->guest_name ?: ($s->reservation?->guest_name ?? 'Sin nombre'),
                'room' => $s->room?->number,
                'time' => $s->planned_end_at->format('H:i'),
                'overdue' => $s->planned_end_at < $now,
                'balance' => $s->folio()['grand_pending'],
            ]),
            'today' => [
                'arrivals' => $arrivalsTotal,
                'arrivals_pending' => $arrivals->filter(fn (Reservation $r) => $r->status !== ReservationStatus::CheckedIn)->count(),
                'departures' => $departuresTotal,
                'overdue' => $overdueStays,
                // Cuentas por cerrar: estancias cerradas a las que les quedó
                // dinero sin registrar (/reservas/cuentas).
                'settlements' => Stay::query()->pendingSettlement()->count(),
            ],
            'channels' => $this->channelMix($start, $elapsedEnd),
            'byMethod' => $revenue['by_method'],
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
     * Dinero cobrado en la ventana. La contabilidad vive en CashLedger: la
     * misma que usan los cortes, los reportes y el centro de pagos, para que
     * las tres pantallas nunca digan cifras distintas del mismo día.
     *
     * @return array{lodging: float, pos: float, total: float, by_method: array<int, array<string, mixed>>}
     */
    private function revenueBetween(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $summary = app(\App\Services\CashLedger::class)->summary($start, $end);

        return [
            'lodging' => $summary['lodging'],
            'pos' => $summary['pos'],
            'total' => $summary['collected'],
            'by_method' => $summary['by_method'],
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
     * @return array{0: array<int, array{label: string, value: float, lodging: float, pos: float}>, 1: array<int, array{label: string, value: int}>}
     */
    private function buildSeries(CarbonImmutable $start, CarbonImmutable $end, callable $occupiedOn, int $totalRooms): array
    {
        // Partido igual que CashLedger: el consumo liquidado en folio es
        // POS, lo demás es hospedaje, y la fianza no es ingreso.
        $paymentsByDay = Payment::query()
            ->whereBetween('paid_at', [$start, $end])
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', Payment::KIND_GUARANTEE))
            ->selectRaw('DATE(paid_at) AS day, SUM(CASE WHEN kind = ? THEN 0 ELSE amount END) AS lodging, SUM(CASE WHEN kind = ? THEN amount ELSE 0 END) AS consumption', [Payment::KIND_CONSUMPTION, Payment::KIND_CONSUMPTION])
            ->groupBy('day')
            ->get()
            ->keyBy('day');

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

            $lodging = (float) ($paymentsByDay[$key]->lodging ?? 0);
            $pos = (float) ($paymentsByDay[$key]->consumption ?? 0) + (float) ($ordersByDay[$key] ?? 0);

            $revenueSeries[] = [
                'label' => $label,
                'value' => round($lodging + $pos, 2),
                'lodging' => round($lodging, 2),
                'pos' => round($pos, 2),
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

    /**
     * Por dónde entró el trabajo del periodo: reservas capturadas por canal
     * más las estancias de mostrador que nunca tuvieron reserva. Sin esas
     * últimas un motel veía solo sus reservas web (3 de 28 entradas).
     *
     * @return array<int, array{channel: string, label: string, count: int}>
     */
    private function channelMix(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $byChannel = Reservation::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('source_channel, COUNT(*) AS total')
            ->groupBy('source_channel')
            ->pluck('total', 'source_channel')
            ->map(fn ($total) => (int) $total)
            ->all();

        $walkIns = Stay::query()
            ->whereNull('reservation_id')
            ->whereBetween('check_in_at', [$start, $end])
            ->count();

        if ($walkIns > 0) {
            $byChannel['walk_in'] = ($byChannel['walk_in'] ?? 0) + $walkIns;
        }

        // Mostrador tiene dos llaves históricas; se juntan en un renglón.
        if (isset($byChannel['counter'])) {
            $byChannel['front_desk'] = ($byChannel['front_desk'] ?? 0) + $byChannel['counter'];
            unset($byChannel['counter']);
        }

        return collect($byChannel)
            ->map(fn (int $count, string $channel) => [
                'channel' => $channel,
                'label' => Reservation::labelForChannel($channel),
                'count' => $count,
            ])
            ->filter(fn (array $row) => $row['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    private function money(float $amount): string
    {
        return '$'.number_format($amount, 0, '.', ',');
    }

    /**
     * Mantenimiento pendiente para la portada del panel: cuántas fallas hay
     * abiertas, cuántas se pasaron de su tiempo y las tres más urgentes.
     *
     * Vacío sin el módulo `incidencias`: el bloque no se pinta.
     *
     * @return array<string, mixed>|null
     */
    protected function maintenance(): ?array
    {
        if (! (tenant()?->hasModule('incidencias') ?? false)) {
            return null;
        }

        $pending = \App\Models\Incident::query()
            ->with('room:id,number')
            ->whereIn('status', [\App\Models\Incident::STATUS_OPEN, \App\Models\Incident::STATUS_IN_PROGRESS])
            ->get();

        $overdue = $pending->filter(fn (\App\Models\Incident $i) => $i->isOverdue());

        return [
            'open' => $pending->count(),
            'overdue' => $overdue->count(),
            'high' => $pending->where('priority', 'high')->count(),
            // Primero lo vencido, luego lo urgente, luego lo más viejo: el
            // orden en que conviene atenderlas.
            'items' => $pending
                ->sortByDesc(fn (\App\Models\Incident $i) => [
                    $i->isOverdue() ? 1 : 0,
                    $i->priority === 'high' ? 1 : 0,
                    -$i->created_at->timestamp,
                ])
                ->take(3)
                ->map(fn (\App\Models\Incident $i) => [
                    'id' => $i->id,
                    'title' => $i->title,
                    'room' => $i->room?->number,
                    'priority' => $i->priority,
                    'priority_label' => $i->priorityLabel(),
                    'overdue' => $i->isOverdue(),
                    'age_hours' => $i->ageHours(),
                ])
                ->values(),
        ];
    }
}
