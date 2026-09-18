<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reportes de reservas: semana / mes / año / rango, en cuatro bloques que
 * NO mezclan bases de cálculo (era el "no cuadran" de sep-2026):
 *
 *  1. Reservas — las que LLEGAN en el rango, por estado, tipo y canal.
 *  2. Dinero — lo VENDIDO (valor de esas reservas) frente a lo COBRADO
 *     (abonos con fecha de pago en el rango) y el saldo que falta. Son tres
 *     cifras distintas a propósito: un anticipo de agosto por una llegada de
 *     septiembre entra en "vendido" de septiembre y en "cobrado" de agosto.
 *  3. Ocupación — porcentaje de uso por habitación (noches ocupadas sobre
 *     noches disponibles), con las habitaciones que no se rentaron.
 *  4. Anticipación y pago — cuándo se hizo cada reserva y si está pagada.
 *
 * La contabilidad del dinero es la de los cortes de caja
 * (CashCutService / DashboardController::revenueBetween): la fianza no es
 * ingreso, lo cargado a habitación suma una sola vez cuando el folio lo
 * liquida, y las devoluciones se restan.
 */
class ReservationReportsController extends Controller
{
    /** Tope de renglones del detalle en pantalla; el PDF trae todos. */
    private const DETAIL_LIMIT = 50;

    public function __invoke(Request $request): Response
    {
        return Inertia::render('tenant/reservations/Reports', $this->reportData($request) + [
            'property' => Property::query()->firstOrFail()->only(['id', 'name']),
            // Catálogo para el filtro por habitación (mismo patrón que el
            // reporte de incidencias).
            'rooms' => Room::query()
                ->orderBy('number')
                ->get(['id', 'number', 'name'])
                ->map(fn (Room $room) => [
                    'id' => $room->id,
                    'label' => $this->roomLabel($room),
                ]),
        ]);
    }

    public function pdf(Request $request)
    {
        $data = $this->reportData($request, detailLimit: null);
        $data['property'] = Property::query()->firstOrFail()->only(['id', 'name']);
        $data['generatedAt'] = now()->format('d/m/Y H:i');

        $slug = $data['filters']['from'].'-a-'.$data['filters']['to'];

        return Pdf::loadView('pdf.reservations-report', $data)
            ->setPaper('letter')
            ->download("reporte-reservas-{$slug}.pdf");
    }

    /**
     * @return array<string, mixed>
     */
    protected function reportData(Request $request, ?int $detailLimit = self::DETAIL_LIMIT): array
    {
        $request->validate([
            'period' => ['nullable', 'in:week,month,year,custom'],
            'from' => ['nullable', 'date', 'required_if:period,custom'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'required_if:period,custom'],
            'room' => ['nullable', 'integer'],
        ]);

        $period = $request->query('period', 'month');
        $roomId = $request->integer('room') ?: null;
        $room = $roomId ? Room::query()->find($roomId) : null;
        $roomId = $room?->id;
        $today = CarbonImmutable::today();

        [$from, $to, $label] = match ($period) {
            'week' => [$today->startOfWeek(), $today->endOfWeek(), 'Semana del '.$today->startOfWeek()->format('d/m/Y')],
            'year' => [$today->startOfYear(), $today->endOfYear(), 'Año '.$today->year],
            'custom' => [
                CarbonImmutable::parse($request->query('from'))->startOfDay(),
                CarbonImmutable::parse($request->query('to'))->endOfDay(),
                CarbonImmutable::parse($request->query('from'))->format('d/m/Y').' – '.CarbonImmutable::parse($request->query('to'))->format('d/m/Y'),
            ],
            default => [$today->startOfMonth(), $today->endOfMonth(), ucfirst($today->locale('es')->isoFormat('MMMM YYYY'))],
        };

        if ($room) {
            $label .= ' · Habitación '.$this->roomLabel($room);
        }

        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;

        // Catálogo de habitaciones del reporte de uso: con filtro, solo esa;
        // sin filtro, TODAS — las que no se rentaron son justo el dato que
        // se busca en un reporte de porcentaje de uso.
        $roomCatalog = Room::query()
            ->when($roomId, fn ($q) => $q->whereKey($roomId))
            ->orderBy('number')
            ->get(['id', 'number', 'name']);

        // ── 1. Reservas que LLEGAN en el rango ────────────────────────────
        $reservations = Reservation::query()
            ->with(['roomType:id,name', 'room:id,number,name', 'guest:id,first_name,last_name', 'stay:id,reservation_id,room_id'])
            ->withSum(['payments as paid_amount' => fn ($q) => $q->where(
                fn ($qq) => $qq->whereNull('kind')->orWhere('kind', '!=', Payment::KIND_GUARANTEE)
            )], 'amount')
            ->whereBetween('starts_at', [$from, $to])
            ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
            ->get();

        $byStatus = $reservations->countBy(fn (Reservation $r) => $r->status->value);
        $total = $reservations->count();
        $cancelled = (int) ($byStatus[ReservationStatus::Cancelled->value] ?? 0);
        $noShow = (int) ($byStatus[ReservationStatus::NoShow->value] ?? 0);

        $sold = $reservations->reject(fn (Reservation $r) => in_array(
            $r->status,
            [ReservationStatus::Cancelled, ReservationStatus::NoShow],
            true,
        ))->values();

        // Walk-ins del rango: hospedaje vendido que nunca fue reserva. Se
        // suman aparte para que el uso por habitación cuadre con el total.
        $walkIns = Stay::query()
            ->whereNull('reservation_id')
            ->whereBetween('check_in_at', [$from, $to])
            ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
            ->get(['id', 'room_id', 'amount', 'check_in_at']);

        $reservedValue = round((float) $sold->sum('total_amount'), 2);
        $walkInValue = round((float) $walkIns->sum('amount'), 2);

        // ── 2. Dinero cobrado en el rango ─────────────────────────────────
        $money = $this->moneyBlock($from, $to, $roomId, $sold, $reservedValue, $walkInValue);

        // ── 3. Ocupación y uso por habitación ─────────────────────────────
        $occupancy = $this->occupancyBlock($from, $to, $days, $roomId, $roomCatalog, $sold, $walkIns);

        // ── 4. Anticipación y estado de pago ──────────────────────────────
        $leadDays = $sold->map(fn (Reservation $r) => $this->leadDays($r))->values();

        return [
            'filters' => [
                'period' => $period,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'room' => $roomId,
            ],
            'period' => [
                'label' => $label,
                'from' => $from->format('d/m/Y'),
                'to' => $to->format('d/m/Y'),
                'days' => $days,
            ],
            'kpis' => [
                'total' => $total,
                'created' => Reservation::query()
                    ->whereBetween('created_at', [$from, $to])
                    ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
                    ->count(),
                'sold' => $sold->count(),
                'confirmed' => (int) ($byStatus[ReservationStatus::Confirmed->value] ?? 0),
                'checked_in' => (int) ($byStatus[ReservationStatus::CheckedIn->value] ?? 0),
                'completed' => (int) ($byStatus[ReservationStatus::Completed->value] ?? 0),
                'pending' => (int) ($byStatus[ReservationStatus::Pending->value] ?? 0),
                'cancelled' => $cancelled,
                'no_show' => $noShow,
                'cancel_rate' => $total > 0 ? round($cancelled / $total * 100, 1) : 0,
                'no_show_rate' => $total > 0 ? round($noShow / $total * 100, 1) : 0,
                'avg_reservation' => $sold->count() ? round($reservedValue / $sold->count(), 2) : 0,
                'avg_lead_days' => $leadDays->count() ? (int) round($leadDays->avg()) : 0,
                'check_ins' => Stay::query()
                    ->whereBetween('check_in_at', [$from, $to])
                    ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
                    ->count(),
                'check_outs' => Stay::query()
                    ->whereBetween('check_out_at', [$from, $to])
                    ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
                    ->count(),
            ],
            'money' => $money,
            'occupancy' => $occupancy['summary'],
            'byRoom' => $occupancy['rooms'],
            'series' => $this->buildSeries($from, $to, $reservations, $occupancy['daily'], $occupancy['summary']['rooms']),
            'byStatus' => collect(ReservationStatus::cases())->map(fn (ReservationStatus $status) => [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($byStatus[$status->value] ?? 0),
            ])->filter(fn ($row) => $row['count'] > 0)->values(),
            'byRoomType' => $reservations->groupBy(fn (Reservation $r) => $r->roomType?->name ?? 'Sin tipo')
                ->map(fn (Collection $group, string $name) => [
                    'name' => $name,
                    'total' => $group->count(),
                    'cancelled' => $group->filter(fn (Reservation $r) => in_array($r->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true))->count(),
                    'revenue' => round((float) $group->reject(fn (Reservation $r) => in_array($r->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true))->sum('total_amount'), 2),
                ])->sortByDesc('total')->values(),
            'byChannel' => $reservations->countBy('source_channel')
                ->map(fn (int $count, string $channel) => [
                    'channel' => $channel,
                    'count' => $count,
                ])->sortByDesc('count')->values(),
            'lead' => $this->leadBuckets($leadDays),
            'paymentStatus' => $this->paymentStatusRows($sold),
            'detail' => $this->detailRows($reservations, $detailLimit),
            'detailTotal' => $total,
            'detailLimit' => $detailLimit,
        ];
    }

    /**
     * Cobrado, vendido y saldo del rango, con la contabilidad de los cortes.
     *
     * @param  Collection<int, Reservation>  $sold
     * @return array<string, mixed>
     */
    protected function moneyBlock(CarbonImmutable $from, CarbonImmutable $to, ?int $roomId, Collection $sold, float $reservedValue, float $walkInValue): array
    {
        // La contabilidad del dinero vive en CashLedger (fianza fuera, lo
        // cargado a habitación una sola vez, devoluciones restadas): la misma
        // que usan el dashboard, los cortes y el centro de pagos.
        $cash = app(\App\Services\CashLedger::class)->summary($from, $to, $roomId);

        $reservedPaid = round((float) $sold->sum(fn (Reservation $r) => min(
            (float) ($r->paid_amount ?? 0),
            (float) $r->total_amount,
        )), 2);
        $reservedPending = round((float) $sold->sum(fn (Reservation $r) => $this->pendingOf($r)), 2);
        $withDebt = $sold->filter(fn (Reservation $r) => $this->pendingOf($r) > 0.009)->count();

        return [
            'lodging' => $cash['lodging'],
            'pos' => $cash['pos'],
            'collected' => $cash['collected'],
            'refunds' => $cash['refunds'],
            'net' => $cash['net'],
            'guarantees' => $cash['guarantees'],
            'guarantees_count' => $cash['guarantees_count'],
            'payments_count' => $cash['payments_count'],
            'reserved' => $reservedValue,
            'walkin' => $walkInValue,
            'sold_value' => round($reservedValue + $walkInValue, 2),
            'reserved_paid' => $reservedPaid,
            'reserved_pending' => $reservedPending,
            'reserved_paid_pct' => $reservedValue > 0 ? round($reservedPaid / $reservedValue * 100, 1) : 0,
            'with_debt' => $withDebt,
            'by_method' => $cash['by_method'],
            'by_concept' => collect([
                [
                    'key' => 'lodging',
                    'label' => 'Hospedaje',
                    'amount' => $cash['lodging'],
                    'note' => 'Abonos de reservas y estancias',
                ],
                [
                    'key' => 'pos',
                    'label' => 'Consumos y punto de venta',
                    'amount' => $cash['pos'],
                    'note' => 'Mostrador más lo liquidado en folio',
                ],
                [
                    'key' => 'refunds',
                    'label' => 'Devoluciones',
                    'amount' => -$cash['refunds'],
                    'note' => 'Dinero que salió en el periodo',
                ],
            ])->filter(fn (array $row) => abs($row['amount']) > 0.009)->values(),
        ];
    }

    /**
     * Porcentaje de uso: noches-habitación ocupadas sobre noches
     * disponibles del rango.
     *
     * Una habitación ocupa el día D si una estancia ENTRÓ ese día o entró
     * antes y sigue ahí al cerrar D (misma regla que el dashboard: en un
     * motel el bloque de 3 horas entra y sale el mismo día). Se cuenta como
     * ocupada UNA vez por día aunque rote varias veces — la rotación se ve
     * en "usos", así el porcentaje nunca pasa de 100.
     *
     * Las reservas vendidas sin check-in también ocupan: el hotel cobra por
     * chat y nadie abre el plano, y medirlo solo con estancias daba 0% con
     * la casa llena.
     *
     * @param  Collection<int, Room>  $roomCatalog
     * @param  Collection<int, Reservation>  $sold
     * @param  Collection<int, Stay>  $walkIns
     * @return array{summary: array<string, mixed>, rooms: array<int, array<string, mixed>>, daily: array<string, int>}
     */
    protected function occupancyBlock(CarbonImmutable $from, CarbonImmutable $to, int $days, ?int $roomId, Collection $roomCatalog, Collection $sold, Collection $walkIns): array
    {
        $stays = Stay::query()
            ->where('check_in_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('check_out_at')->orWhere('check_out_at', '>=', $from))
            ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
            ->get(['id', 'room_id', 'reservation_id', 'check_in_at', 'check_out_at']);

        $stayed = $stays->pluck('reservation_id')->filter()->unique()->all();

        $soldNights = Reservation::query()
            ->whereIn('status', [
                ReservationStatus::Confirmed,
                ReservationStatus::CheckedIn,
                ReservationStatus::Completed,
            ])
            ->where('starts_at', '<=', $to)
            ->where('ends_at', '>=', $from)
            ->when($stayed !== [], fn ($q) => $q->whereKeyNot($stayed))
            ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
            ->get(['id', 'room_id', 'starts_at', 'ends_at']);

        $occupies = static function (CarbonInterface $start, ?CarbonInterface $end, CarbonImmutable $day): bool {
            $dayEnd = $day->endOfDay();

            return $start <= $dayEnd
                && ($start >= $day || $end === null || $end > $dayEnd);
        };

        $nightsByRoom = [];
        $unassignedNights = 0;
        $daily = [];

        for ($day = $from->startOfDay(); $day <= $to; $day = $day->addDay()) {
            $seen = [];
            $unassigned = 0;

            foreach ($stays as $stay) {
                if ($stay->room_id !== null && $occupies($stay->check_in_at, $stay->check_out_at, $day)) {
                    $seen[$stay->room_id] = true;
                }
            }

            foreach ($soldNights as $reservation) {
                if (! $occupies($reservation->starts_at, $reservation->ends_at, $day)) {
                    continue;
                }
                if ($reservation->room_id !== null) {
                    $seen[$reservation->room_id] = true;
                } else {
                    // Sin habitación asignada cada reserva consume una unidad
                    // del inventario: no se pueden colapsar en una sola.
                    $unassigned++;
                }
            }

            foreach (array_keys($seen) as $id) {
                $nightsByRoom[$id] = ($nightsByRoom[$id] ?? 0) + 1;
            }

            $unassignedNights += $unassigned;
            $daily[$day->toDateString()] = count($seen) + $unassigned;
        }

        // Usos y hospedaje vendido del rango, sin doble conteo: la reserva
        // manda (su total es el hospedaje) y el walk-in aporta su estancia.
        // La habitación efectiva es la del check-in si ya entró.
        $uses = [];
        $revenue = [];
        $bump = function (?int $id, float $amount) use (&$uses, &$revenue): void {
            $key = $id ?? 0;
            $uses[$key] = ($uses[$key] ?? 0) + 1;
            $revenue[$key] = ($revenue[$key] ?? 0) + $amount;
        };

        foreach ($sold as $reservation) {
            $bump($reservation->stay?->room_id ?? $reservation->room_id, (float) $reservation->total_amount);
        }
        foreach ($walkIns as $stay) {
            $bump($stay->room_id, (float) $stay->amount);
        }

        $rows = $roomCatalog->map(fn (Room $room) => [
            'id' => $room->id,
            'name' => $this->roomLabel($room),
            'uses' => (int) ($uses[$room->id] ?? 0),
            'nights' => (int) ($nightsByRoom[$room->id] ?? 0),
            'capacity' => $days,
            'percent' => $days > 0 ? round(((int) ($nightsByRoom[$room->id] ?? 0)) / $days * 100, 1) : 0,
            'revenue' => round((float) ($revenue[$room->id] ?? 0), 2),
        ])->values();

        // Renglón de lo que no tiene habitación: reservas sin asignar y
        // estancias de habitaciones ya borradas. Sin él, los renglones no
        // suman el total y el reporte "no cuadra".
        $looseUses = (int) ($uses[0] ?? 0);
        $looseRevenue = round((float) ($revenue[0] ?? 0), 2);

        if ($roomId === null && ($looseUses > 0 || $unassignedNights > 0)) {
            $rows = $rows->push([
                'id' => null,
                'name' => 'Sin habitación asignada',
                'uses' => $looseUses,
                'nights' => $unassignedNights,
                'capacity' => 0,
                'percent' => null,
                'revenue' => $looseRevenue,
            ]);
        }

        $roomsCount = $roomCatalog->count();
        $available = $roomsCount * $days;
        $occupied = array_sum($daily);

        return [
            'summary' => [
                'rooms' => $roomsCount,
                'days' => $days,
                'available' => $available,
                'occupied' => $occupied,
                'percent' => $available > 0 ? round($occupied / $available * 100, 1) : 0,
                'uses' => array_sum($uses),
                'idle_rooms' => $rows->filter(fn (array $row) => $row['id'] !== null && $row['nights'] === 0)->count(),
                'unassigned_nights' => $unassignedNights,
                'best' => $rows->filter(fn (array $row) => $row['id'] !== null)->sortByDesc('nights')->first(),
            ],
            'rooms' => $rows->sortByDesc('nights')->values()->all(),
            'daily' => $daily,
        ];
    }

    /**
     * Anticipación con la que se hizo cada reserva: días completos entre el
     * día en que se capturó y el día de llegada.
     */
    protected function leadDays(Reservation $reservation): int
    {
        if ($reservation->created_at === null) {
            return 0;
        }

        return max(0, (int) round(
            CarbonImmutable::parse($reservation->created_at)->startOfDay()
                ->diffInDays(CarbonImmutable::parse($reservation->starts_at)->startOfDay())
        ));
    }

    /**
     * @param  Collection<int, int>  $leadDays
     * @return array<int, array<string, mixed>>
     */
    protected function leadBuckets(Collection $leadDays): array
    {
        $buckets = [
            ['label' => 'Mismo día', 'min' => 0, 'max' => 0],
            ['label' => '1 a 3 días', 'min' => 1, 'max' => 3],
            ['label' => '4 a 7 días', 'min' => 4, 'max' => 7],
            ['label' => '8 a 30 días', 'min' => 8, 'max' => 30],
            ['label' => 'Más de 30 días', 'min' => 31, 'max' => null],
        ];

        return collect($buckets)->map(fn (array $bucket) => [
            'label' => $bucket['label'],
            'count' => $leadDays->filter(fn (int $lead) => $lead >= $bucket['min']
                && ($bucket['max'] === null || $lead <= $bucket['max']))->count(),
        ])->all();
    }

    /**
     * Cuántas reservas del rango están pagadas y cuánto falta en cada
     * estado de pago.
     *
     * @param  Collection<int, Reservation>  $sold
     * @return array<int, array<string, mixed>>
     */
    protected function paymentStatusRows(Collection $sold): array
    {
        return collect(PaymentStatus::cases())->map(function (PaymentStatus $status) use ($sold) {
            $group = $sold->filter(fn (Reservation $r) => $r->payment_status === $status);

            return [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => $group->count(),
                'value' => round((float) $group->sum('total_amount'), 2),
                'pending' => round((float) $group->sum(fn (Reservation $r) => $this->pendingOf($r)), 2),
            ];
        })->all();
    }

    /**
     * Detalle reserva por reserva: cuándo se hizo, con cuánta anticipación,
     * cuánto pagó y qué falta. Primero lo que debe dinero.
     *
     * @param  Collection<int, Reservation>  $reservations
     * @return array<int, array<string, mixed>>
     */
    protected function detailRows(Collection $reservations, ?int $limit): array
    {
        // Primero lo que debe dinero, y dentro de eso por llegada: es el
        // orden con el que se persigue el cobro. Se ordena con los objetos
        // (fechas Carbon) porque las cadenas d/m/Y no ordenan.
        $rows = $reservations
            ->sortBy([
                fn (Reservation $a, Reservation $b) => $this->pendingOf($b) <=> $this->pendingOf($a),
                fn (Reservation $a, Reservation $b) => $a->starts_at <=> $b->starts_at,
            ])
            ->values()
            ->map(function (Reservation $r) {
                $live = $this->isLive($r);

                return [
                    'id' => $r->id,
                    'code' => $r->displayCode(),
                    'guest' => $r->guest_name
                        ?: $r->guest?->full_name
                        ?: 'Sin nombre',
                    'room' => $r->room ? $this->roomLabel($r->room) : ($r->roomType?->name ?? 'Sin asignar'),
                    'channel' => $r->source_channel,
                    'created_at' => $r->created_at?->format('d/m/Y H:i') ?? '—',
                    'lead_days' => $live ? $this->leadDays($r) : null,
                    'starts_at' => CarbonImmutable::parse($r->starts_at)->format('d/m/Y'),
                    'ends_at' => CarbonImmutable::parse($r->ends_at)->format('d/m/Y'),
                    'status' => $r->status->value,
                    'status_label' => $r->status->label(),
                    'payment_status' => $r->payment_status?->value,
                    'payment_label' => $r->payment_status?->label() ?? 'Sin pago',
                    'total' => round((float) $r->total_amount, 2),
                    'paid' => round((float) ($r->paid_amount ?? 0), 2),
                    'pending' => $this->pendingOf($r),
                ];
            });

        return ($limit === null ? $rows : $rows->take($limit))->all();
    }

    /** Una reserva cancelada o no-show ya no debe nada: no arrastra saldo. */
    protected function isLive(Reservation $reservation): bool
    {
        return ! in_array(
            $reservation->status,
            [ReservationStatus::Cancelled, ReservationStatus::NoShow],
            true,
        );
    }

    protected function pendingOf(Reservation $reservation): float
    {
        if (! $this->isLive($reservation)) {
            return 0.0;
        }

        return max(0, round(
            (float) $reservation->total_amount - (float) ($reservation->paid_amount ?? 0),
            2,
        ));
    }

    /**
     * Serie temporal con cubetas según el tamaño del rango: día (≤31),
     * semana (≤120 días) o mes.
     *
     * @param  Collection<int, Reservation>  $reservations
     * @param  array<string, int>  $daily
     * @return array<int, array<string, mixed>>
     */
    protected function buildSeries(CarbonImmutable $from, CarbonImmutable $to, Collection $reservations, array $daily, int $rooms): array
    {
        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;
        $step = match (true) {
            $days <= 31 => 'day',
            $days <= 120 => 'week',
            default => 'month',
        };

        $series = [];
        $cursor = $from->startOfDay();

        while ($cursor <= $to) {
            $bucketEnd = match ($step) {
                'day' => $cursor->endOfDay(),
                'week' => $cursor->addDays(6)->endOfDay(),
                default => $cursor->endOfMonth(),
            };
            if ($bucketEnd > $to) {
                $bucketEnd = $to;
            }

            $inBucket = fn ($moment) => $moment >= $cursor && $moment <= $bucketEnd;

            // Ocupación de la cubeta: noches ocupadas sobre las disponibles
            // de esos mismos días (no se promedian porcentajes).
            $bucketDays = 0;
            $bucketOccupied = 0;
            for ($day = $cursor; $day <= $bucketEnd; $day = $day->addDay()) {
                $bucketDays++;
                $bucketOccupied += $daily[$day->toDateString()] ?? 0;
            }
            $capacity = $bucketDays * $rooms;

            $series[] = [
                'label' => match ($step) {
                    'day' => $cursor->format('d/m'),
                    'week' => $cursor->format('d/m').' +',
                    default => ucfirst($cursor->locale('es')->isoFormat('MMM')),
                },
                'reservations' => $reservations->filter(fn (Reservation $r) => $inBucket($r->starts_at))->count(),
                'cancelled' => $reservations->filter(fn (Reservation $r) => $inBucket($r->starts_at) && in_array($r->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true))->count(),
                'sold_value' => round((float) $reservations
                    ->filter(fn (Reservation $r) => $inBucket($r->starts_at) && ! in_array($r->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true))
                    ->sum('total_amount'), 2),
                'occupancy' => $capacity > 0 ? round($bucketOccupied / $capacity * 100, 1) : 0,
            ];

            $cursor = match ($step) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                default => $cursor->addMonthNoOverflow()->startOfMonth(),
            };
        }

        return $series;
    }

    protected function roomLabel(Room $room): string
    {
        return trim($room->number.' '.($room->name ?? ''));
    }
}
