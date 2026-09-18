<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomCleaning;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\Zone;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reportes de habitaciones (/habitaciones/reportes): la habitación vista
 * como lo que es para el dueño, un activo que se renta.
 *
 * Responde las cuatro preguntas que se hacen en un motel o unas cabañas:
 *
 *  1. ¿Cuánto se usó cada habitación? — rotaciones (entradas registradas),
 *     noches ocupadas y porcentaje de uso sobre los días del periodo.
 *  2. ¿Cuánto dejó? — hospedaje vendido, consumos cargados al cuarto,
 *     tarifa promedio por noche e ingreso por habitación disponible.
 *  3. ¿Cuánto costó tenerla? — incidencias, su costo, y los días que
 *     estuvo fuera de servicio (bloqueada o en mantenimiento).
 *  4. ¿Cuál trabaja y cuál está dormida? — el ranking, y las que no se
 *     rentaron ni una vez en todo el periodo.
 *
 * El dinero es el HOSPEDAJE VENDIDO del periodo, no lo cobrado: quién pagó
 * y cuándo vive en los cortes de caja y en el reporte de reservas. Aquí
 * interesa qué produjo el cuarto, no cuándo entró el billete.
 */
class RoomReportsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('tenant/rooms/Reports', $this->reportData($request) + [
            'property' => Property::query()->firstOrFail()->only(['id', 'name']),
            'catalog' => [
                'rooms' => Room::query()->orderBy('number')->get(['id', 'number', 'name'])
                    ->map(fn (Room $room) => ['id' => $room->id, 'label' => $this->roomLabel($room)])
                    ->values(),
                'types' => RoomType::query()->orderBy('sort_order')->get(['id', 'name'])
                    ->map(fn (RoomType $type) => ['id' => $type->id, 'label' => $type->name])
                    ->values(),
                'zones' => Zone::query()->orderBy('sort_order')->get(['id', 'name'])
                    ->map(fn (Zone $zone) => ['id' => $zone->id, 'label' => $zone->name])
                    ->values(),
            ],
        ]);
    }

    public function pdf(Request $request)
    {
        $data = $this->reportData($request);
        $data['property'] = Property::query()->firstOrFail()->only(['id', 'name']);
        $data['generatedAt'] = now()->format('d/m/Y H:i');

        $slug = $data['filters']['from'].'-a-'.$data['filters']['to'];

        return Pdf::loadView('pdf.rooms-report', $data)
            ->setPaper('letter', 'landscape')
            ->download("reporte-habitaciones-{$slug}.pdf");
    }

    /**
     * @return array<string, mixed>
     */
    protected function reportData(Request $request): array
    {
        $request->validate([
            'period' => ['nullable', 'in:week,month,year,custom'],
            'from' => ['nullable', 'date', 'required_if:period,custom'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'required_if:period,custom'],
            'room' => ['nullable', 'integer'],
            'type' => ['nullable', 'integer'],
            'zone' => ['nullable', 'integer'],
        ]);

        $period = $request->query('period', 'month');
        $today = CarbonImmutable::today();

        [$from, $to, $label] = match ($period) {
            'week' => [$today->startOfWeek(), $today->endOfWeek(), 'Semana del '.$today->startOfWeek()->format('d/m/Y')],
            'year' => [$today->startOfYear(), $today->endOfYear(), 'Año '.$today->year],
            'custom' => [
                CarbonImmutable::parse($request->query('from'))->startOfDay(),
                CarbonImmutable::parse($request->query('to'))->endOfDay(),
                CarbonImmutable::parse($request->query('from'))->format('d/m/Y').' – '
                    .CarbonImmutable::parse($request->query('to'))->format('d/m/Y'),
            ],
            default => [$today->startOfMonth(), $today->endOfMonth(), ucfirst($today->locale('es')->isoFormat('MMMM YYYY'))],
        };

        // Semana, mes y año en curso se miden HASTA HOY: contra el periodo
        // completo, un año a la mitad salía con 5% de uso y parecía un
        // desastre. El rango personalizado se respeta tal cual se pidió.
        $now = CarbonImmutable::now();

        if ($period !== 'custom' && $to->greaterThan($now)) {
            $to = $now->endOfDay();
            $label .= ' · hasta hoy';
        }

        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;

        // Catálogo filtrado: las habitaciones que entran al reporte. Las que
        // no se rentaron TIENEN que estar — son justo el dato que se busca.
        $rooms = Room::query()
            ->with(['roomType:id,name', 'zone:id,name'])
            ->when($request->integer('room'), fn ($q, $id) => $q->whereKey($id))
            ->when($request->integer('type'), fn ($q, $id) => $q->where('room_type_id', $id))
            ->when($request->integer('zone'), fn ($q, $id) => $q->where('zone_id', $id))
            ->orderBy('number')
            ->get();

        $roomIds = $rooms->pluck('id')->all();

        $usage = $this->usageByRoom($from, $to, $days, $roomIds);
        $care = $this->careByRoom($from, $to, $roomIds);

        $rows = $rooms->map(function (Room $room) use ($usage, $care, $days) {
            $use = $usage['rooms'][$room->id] ?? [];
            $keep = $care['rooms'][$room->id] ?? [];

            $nights = (int) ($use['nights'] ?? 0);
            $uses = (int) ($use['uses'] ?? 0);
            $revenue = round((float) ($use['revenue'] ?? 0), 2);
            $consumos = round((float) ($use['consumos'] ?? 0), 2);
            $minutes = (int) ($use['minutes'] ?? 0);

            return [
                'id' => $room->id,
                'name' => $this->roomLabel($room),
                'type' => $room->roomType?->name,
                'zone' => $room->zone?->name,
                'status_label' => $room->status->label(),
                'uses' => $uses,
                'nights' => $nights,
                'percent' => $days > 0 ? round($nights / $days * 100, 1) : 0.0,
                'revenue' => $revenue,
                'consumos' => $consumos,
                'total' => round($revenue + $consumos, 2),
                // Tarifa promedio por noche vendida de ESA habitación.
                'adr' => $nights > 0 ? round($revenue / $nights, 2) : 0.0,
                // Lo que dejó por día del periodo, se haya rentado o no: es
                // lo que permite comparar una suite con una sencilla.
                'revpar' => $days > 0 ? round($revenue / $days, 2) : 0.0,
                'avg_stay_label' => $this->spellMinutes($uses > 0 ? (int) round($minutes / $uses) : 0),
                'incidents' => (int) ($keep['incidents'] ?? 0),
                'incidents_open' => (int) ($keep['open'] ?? 0),
                'incident_cost' => round((float) ($keep['cost'] ?? 0), 2),
                'cleanings' => (int) ($keep['cleanings'] ?? 0),
                'cleaning_minutes' => (int) ($keep['cleaning_minutes'] ?? 0),
                'out_of_service_days' => (int) ($keep['blocked_days'] ?? 0),
                'usage_count' => (int) $room->usage_count,
                'usage_limit' => $room->usage_limit,
            ];
        })->values();

        // Periodo anterior del mismo largo: sin comparación, una cifra sola
        // no dice si el mes fue bueno o malo.
        $prevTo = $from->subDay()->endOfDay();
        $prevFrom = $prevTo->subDays($days - 1)->startOfDay();
        $prev = $this->usageByRoom($prevFrom, $prevTo, $days, $roomIds);

        $nights = (int) $rows->sum('nights');
        $uses = (int) $rows->sum('uses');
        $revenue = round((float) $rows->sum('revenue'), 2);
        $consumos = round((float) $rows->sum('consumos'), 2);
        $available = $rooms->count() * $days;
        $stayMinutes = (int) array_sum(array_column($usage['rooms'], 'minutes'));

        return [
            'filters' => [
                'period' => $period,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $label,
                'room' => $request->integer('room') ?: null,
                'type' => $request->integer('type') ?: null,
                'zone' => $request->integer('zone') ?: null,
            ],
            'summary' => [
                'rooms' => $rooms->count(),
                'days' => $days,
                'uses' => $uses,
                'uses_prev' => (int) array_sum(array_column($prev['rooms'], 'uses')),
                'nights' => $nights,
                'available' => $available,
                'percent' => $available > 0 ? round($nights / $available * 100, 1) : 0.0,
                'revenue' => $revenue,
                'revenue_label' => $this->money($revenue),
                'revenue_prev' => round((float) array_sum(array_column($prev['rooms'], 'revenue')), 2),
                'consumos_label' => $this->money($consumos),
                'total_label' => $this->money($revenue + $consumos),
                'adr_label' => $this->money($nights > 0 ? $revenue / $nights : 0),
                'revpar_label' => $this->money($available > 0 ? $revenue / $available : 0),
                'avg_stay_label' => $this->spellMinutes($uses > 0 ? (int) round($stayMinutes / $uses) : 0),
                'idle_rooms' => $rows->where('nights', 0)->count(),
                'out_of_service_days' => (int) $rows->sum('out_of_service_days'),
                'incidents' => (int) $rows->sum('incidents'),
                'incidents_open' => (int) $rows->sum('incidents_open'),
                'incident_cost_label' => $this->money((float) $rows->sum('incident_cost')),
                'cleanings' => (int) $rows->sum('cleanings'),
                'avg_cleaning_label' => $this->spellDuration(
                    ($c = (int) $rows->sum('cleanings')) > 0
                        ? (int) round((int) $rows->sum('cleaning_minutes') / $c)
                        : 0
                ),
            ],
            'rooms' => $rows->sortByDesc('nights')->values()->all(),
            'idle' => $rows->where('nights', 0)->sortBy('name')->values()->all(),
            'daily' => $usage['daily'],
            'weekdays' => $usage['weekdays'],
            'types' => $this->groupBy($rows, 'type'),
            'zones' => $this->groupBy($rows, 'zone'),
            'maintenance' => $care['summary'],
        ];
    }

    /**
     * Uso del periodo por habitación: rotaciones, noches, minutos dentro y
     * dinero, más la serie por día para la gráfica.
     *
     * Una "rotación" es una entrada registrada en el rango — en un motel se
     * renta el mismo cuarto tres veces el mismo día y eso no son tres
     * noches, son tres usos.
     *
     * @param  array<int, int>  $roomIds
     * @return array<string, mixed>
     */
    protected function usageByRoom(CarbonImmutable $from, CarbonImmutable $to, int $days, array $roomIds): array
    {
        $stays = Stay::query()
            ->whereIn('room_id', $roomIds)
            ->where('check_in_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('check_out_at')->orWhere('check_out_at', '>=', $from))
            ->get(['id', 'room_id', 'reservation_id', 'check_in_at', 'check_out_at', 'planned_end_at', 'amount']);

        // Reservas que ocupan el cuarto sin estancia registrada (el hotel
        // que no marca llegadas, y todo el historial migrado).
        $stayed = $stays->pluck('reservation_id')->filter()->unique()->all();

        $reservations = Reservation::query()
            ->whereIn('room_id', $roomIds)
            ->whereIn('status', [
                ReservationStatus::Confirmed,
                ReservationStatus::CheckedIn,
                ReservationStatus::Completed,
            ])
            ->where('starts_at', '<=', $to)
            ->where('ends_at', '>=', $from)
            ->when($stayed !== [], fn ($q) => $q->whereKeyNot($stayed))
            ->get(['id', 'room_id', 'starts_at', 'ends_at', 'total_amount']);

        // Consumos cargados al cuarto durante esas estancias.
        $consumos = $stays->isEmpty()
            ? collect()
            : Order::query()
                ->whereIn('stay_id', $stays->pluck('id'))
                ->where('status', Order::STATUS_COMPLETED)
                ->selectRaw('stay_id, sum(total) as total')
                ->groupBy('stay_id')
                ->pluck('total', 'stay_id');

        $occupies = static fn (CarbonInterface $start, ?CarbonInterface $end, CarbonImmutable $day): bool => $start <= $day->endOfDay()
            && ($start >= $day || $end === null || $end > $day->endOfDay());

        $byRoom = [];
        $daily = [];

        $touch = function (int $roomId) use (&$byRoom): void {
            $byRoom[$roomId] ??= [
                'uses' => 0, 'nights' => 0, 'minutes' => 0,
                'revenue' => 0.0, 'consumos' => 0.0,
            ];
        };

        for ($day = $from->startOfDay(); $day <= $to; $day = $day->addDay()) {
            $seen = [];

            foreach ($stays as $stay) {
                if ($occupies($stay->check_in_at, $stay->check_out_at, $day)) {
                    $seen[$stay->room_id] = true;
                }
            }

            foreach ($reservations as $reservation) {
                if ($occupies($reservation->starts_at, $reservation->ends_at, $day)) {
                    $seen[$reservation->room_id] = true;
                }
            }

            foreach (array_keys($seen) as $id) {
                $touch($id);
                $byRoom[$id]['nights']++;
            }

            $daily[] = [
                'date' => $day->toDateString(),
                'label' => $day->locale('es')->isoFormat('D MMM'),
                'month' => $day->format('Y-m'),
                'month_label' => ucfirst($day->locale('es')->isoFormat('MMM YY')),
                'occupied' => count($seen),
                'uses' => 0,
            ];
        }

        $dayIndex = array_flip(array_column($daily, 'date'));

        // Rotaciones y dinero: cuentan las que ENTRARON en el rango, para
        // que una estancia larga no se cobre dos veces en dos reportes.
        foreach ($stays as $stay) {
            if ($stay->check_in_at < $from || $stay->check_in_at > $to) {
                continue;
            }

            $touch($stay->room_id);
            $byRoom[$stay->room_id]['uses']++;
            $byRoom[$stay->room_id]['revenue'] += (float) $stay->amount;
            $byRoom[$stay->room_id]['consumos'] += (float) ($consumos[$stay->id] ?? 0);
            $byRoom[$stay->room_id]['minutes'] += max(1, (int) round(
                $stay->check_in_at->diffInMinutes($stay->check_out_at ?? $stay->planned_end_at)
            ));

            $key = $stay->check_in_at->toDateString();
            if (isset($dayIndex[$key])) {
                $daily[$dayIndex[$key]]['uses']++;
            }
        }

        foreach ($reservations as $reservation) {
            if ($reservation->starts_at < $from || $reservation->starts_at > $to) {
                continue;
            }

            $touch($reservation->room_id);
            $byRoom[$reservation->room_id]['uses']++;
            $byRoom[$reservation->room_id]['revenue'] += (float) $reservation->total_amount;
            $byRoom[$reservation->room_id]['minutes'] += max(1, (int) round(
                $reservation->starts_at->diffInMinutes($reservation->ends_at)
            ));

            $key = $reservation->starts_at->toDateString();
            if (isset($dayIndex[$key])) {
                $daily[$dayIndex[$key]]['uses']++;
            }
        }

        // Qué día de la semana trabaja el hotel: se calcula ANTES de
        // agrupar por mes, que es cuando todavía hay detalle diario. Es
        // con lo que se decide la tarifa de fin de semana y el personal.
        $names = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
        $weekdays = [];

        foreach ($daily as $row) {
            $index = CarbonImmutable::parse($row['date'])->dayOfWeekIso - 1;
            $weekdays[$index] ??= ['label' => $names[$index], 'days' => 0, 'occupied' => 0, 'uses' => 0];
            $weekdays[$index]['days']++;
            $weekdays[$index]['occupied'] += $row['occupied'];
            $weekdays[$index]['uses'] += $row['uses'];
        }

        ksort($weekdays);
        $weekdays = array_values(array_map(fn (array $row) => $row + [
            // Promedio de habitaciones ocupadas ese día de la semana.
            'average' => $row['days'] > 0 ? round($row['occupied'] / $row['days'], 1) : 0.0,
        ], $weekdays));

        // Más de dos meses: se agrupa por mes. Trescientas sesenta y cinco
        // barras en una gráfica no son un dato, son un peine.
        if (count($daily) > 62) {
            $daily = collect($daily)
                ->groupBy('month')
                ->map(fn (Collection $group) => [
                    'date' => $group->first()['date'],
                    'label' => $group->first()['month_label'],
                    // El mes ocupa lo que sumaron sus días (noches-cuarto).
                    'occupied' => (int) $group->sum('occupied'),
                    'uses' => (int) $group->sum('uses'),
                ])
                ->values()
                ->all();
        }

        return ['rooms' => $byRoom, 'daily' => $daily, 'weekdays' => $weekdays];
    }

    /**
     * Lo que costó tener la habitación: incidencias, limpiezas y días fuera
     * de servicio dentro del periodo.
     *
     * @param  array<int, int>  $roomIds
     * @return array<string, mixed>
     */
    protected function careByRoom(CarbonImmutable $from, CarbonImmutable $to, array $roomIds): array
    {
        $incidents = Incident::query()
            ->whereIn('room_id', $roomIds)
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'room_id', 'category', 'status', 'cost', 'created_at', 'resolved_at']);

        $cleanings = RoomCleaning::query()
            ->whereIn('room_id', $roomIds)
            ->whereBetween('started_at', [$from, $to])
            ->get(['id', 'room_id', 'minutes', 'started_at', 'ended_at']);

        $blocks = RoomBlock::query()
            ->whereIn('room_id', $roomIds)
            ->where('starts_at', '<=', $to)
            ->where('ends_at', '>=', $from)
            ->get(['id', 'room_id', 'starts_at', 'ends_at', 'reason']);

        $byRoom = [];
        $touch = function (int $roomId) use (&$byRoom): void {
            $byRoom[$roomId] ??= [
                'incidents' => 0, 'open' => 0, 'cost' => 0.0,
                'cleanings' => 0, 'cleaning_minutes' => 0, 'blocked_days' => 0,
            ];
        };

        foreach ($incidents as $incident) {
            $touch($incident->room_id);
            $byRoom[$incident->room_id]['incidents']++;
            $byRoom[$incident->room_id]['cost'] += (float) $incident->cost;

            if ($incident->status !== Incident::STATUS_RESOLVED) {
                $byRoom[$incident->room_id]['open']++;
            }
        }

        foreach ($cleanings as $cleaning) {
            $touch($cleaning->room_id);
            $byRoom[$cleaning->room_id]['cleanings']++;
            $byRoom[$cleaning->room_id]['cleaning_minutes'] += (int) ($cleaning->minutes
                ?? ($cleaning->ended_at ? $cleaning->started_at->diffInMinutes($cleaning->ended_at) : 0));
        }

        foreach ($blocks as $block) {
            $touch($block->room_id);
            // Solo los días del bloqueo que caen DENTRO del periodo.
            $start = $block->starts_at->greaterThan($from) ? $block->starts_at : $from;
            $end = $block->ends_at->lessThan($to) ? $block->ends_at : $to;
            $byRoom[$block->room_id]['blocked_days'] += max(1, (int) $start->startOfDay()->diffInDays($end->startOfDay()) + 1);
        }

        $resolved = $incidents->whereNotNull('resolved_at');

        return [
            'rooms' => $byRoom,
            'summary' => [
                'categories' => $incidents
                    ->groupBy('category')
                    ->map(fn (Collection $group, $category) => [
                        'category' => $category,
                        'label' => Incident::CATEGORIES[$category] ?? ucfirst((string) $category),
                        'count' => $group->count(),
                        'cost' => round((float) $group->sum('cost'), 2),
                        'cost_label' => $this->money((float) $group->sum('cost')),
                    ])
                    ->sortByDesc('count')
                    ->values()
                    ->all(),
                // abs(): una incidencia con fechas cruzadas (se resolvió
                // "antes" de levantarse, que pasa con capturas a mano) no
                // puede dejar el promedio en negativo.
                'avg_resolution_label' => $this->spellDuration(
                    $resolved->isEmpty()
                        ? 0
                        : (int) round($resolved->avg(fn (Incident $i) => abs(
                            $i->created_at->diffInMinutes($i->resolved_at)
                        )))
                ),
                'blocked_rooms' => collect($byRoom)->filter(fn (array $row) => $row['blocked_days'] > 0)->count(),
            ],
        ];
    }

    /**
     * Suma por tipo o por zona, para ver qué clase de cuarto trabaja mejor.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function groupBy(Collection $rows, string $key): array
    {
        return $rows
            ->groupBy(fn (array $row) => $row[$key] ?? 'Sin asignar')
            ->map(fn (Collection $group, $name) => [
                'name' => (string) $name,
                'rooms' => $group->count(),
                'uses' => (int) $group->sum('uses'),
                'nights' => (int) $group->sum('nights'),
                'revenue' => round((float) $group->sum('revenue'), 2),
                'revenue_label' => $this->money((float) $group->sum('revenue')),
                'percent' => round((float) $group->avg('percent'), 1),
            ])
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * Lo que dura una renta: minutos en un motel, noches en unas cabañas.
     * Por eso pasa a "noches" a partir de las 20 horas.
     */
    protected function spellMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return '—';
        }

        if ($minutes < 60) {
            return $minutes.' min';
        }

        if ($minutes < 60 * 20) {
            $hours = intdiv($minutes, 60);
            $rest = $minutes % 60;

            return $hours.' h'.($rest > 0 ? ' '.$rest.' min' : '');
        }

        $nights = max(1, (int) round($minutes / (60 * 24)));

        return $nights.($nights === 1 ? ' noche' : ' noches');
    }

    /**
     * Lo que TARDA algo (una reparación, una limpieza): eso se cuenta en
     * horas y días. "1 noche en resolverse" no lo dice nadie.
     */
    protected function spellDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '—';
        }

        if ($minutes < 60) {
            return $minutes.' min';
        }

        if ($minutes < 60 * 24) {
            $hours = intdiv($minutes, 60);
            $rest = $minutes % 60;

            return $hours.' h'.($rest > 0 ? ' '.$rest.' min' : '');
        }

        $days = max(1, (int) round($minutes / (60 * 24)));

        return $days.($days === 1 ? ' día' : ' días');
    }

    protected function money(float $amount): string
    {
        return '$'.number_format(round($amount, 2), 2);
    }

    protected function roomLabel(Room $room): string
    {
        return trim($room->number.($room->name ? ' · '.$room->name : ''));
    }
}
