<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Property;
use App\Models\Room;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reportes de incidencias de mantenimiento: resumen por periodo (hoy /
 * semana / mes / año / rango), general o filtrado por habitación, con
 * tiempos de resolución, desglose por habitación y prioridad, serie
 * temporal y descarga en PDF.
 */
class IncidentReportsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('tenant/incidents/Reports', $this->reportData($request) + [
            'property' => Property::query()->firstOrFail()->only(['id', 'name']),
            'rooms' => Room::query()->orderBy('number')->get(['id', 'number', 'name'])
                ->map(fn (Room $room) => [
                    'id' => $room->id,
                    'label' => trim($room->number.' '.($room->name ?? '')),
                ]),
        ]);
    }

    public function pdf(Request $request)
    {
        $data = $this->reportData($request);
        $data['property'] = Property::query()->firstOrFail()->only(['id', 'name']);
        $data['generatedAt'] = now()->format('d/m/Y H:i');

        $slug = $data['filters']['from'].'-a-'.$data['filters']['to'];

        return Pdf::loadView('pdf.incidents-report', $data)
            ->setPaper('letter')
            ->download("reporte-incidencias-{$slug}.pdf");
    }

    /**
     * @return array<string, mixed>
     */
    protected function reportData(Request $request): array
    {
        $request->validate([
            'period' => ['nullable', 'in:day,week,month,year,custom'],
            'from' => ['nullable', 'date', 'required_if:period,custom'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'required_if:period,custom'],
            'room' => ['nullable', 'integer'],
        ]);

        $period = $request->query('period', 'month');
        $today = CarbonImmutable::today();

        [$from, $to, $label] = match ($period) {
            'day' => [$today, $today->endOfDay(), 'Hoy, '.$today->locale('es')->isoFormat('D [de] MMMM')],
            'week' => [$today->startOfWeek(), $today->endOfWeek(), 'Semana del '.$today->startOfWeek()->format('d/m/Y')],
            'year' => [$today->startOfYear(), $today->endOfYear(), 'Año '.$today->year],
            'custom' => [
                CarbonImmutable::parse($request->query('from'))->startOfDay(),
                CarbonImmutable::parse($request->query('to'))->endOfDay(),
                CarbonImmutable::parse($request->query('from'))->format('d/m/Y').' – '.CarbonImmutable::parse($request->query('to'))->format('d/m/Y'),
            ],
            default => [$today->startOfMonth(), $today->endOfMonth(), ucfirst($today->locale('es')->isoFormat('MMMM YYYY'))],
        };

        $roomId = $request->integer('room') ?: null;
        $room = $roomId ? Room::find($roomId) : null;

        // Reportadas en el periodo (el "trabajo nuevo") — con filtro
        // opcional por habitación para el reporte de un cuarto conflictivo.
        $reported = Incident::query()
            ->with(['room:id,number,name'])
            ->whereBetween('created_at', [$from, $to])
            ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
            ->get();

        // Resueltas en el periodo (aunque se hayan reportado antes): mide
        // el trabajo que el equipo SACÓ, no solo el que entró.
        $resolved = Incident::query()
            ->with(['room:id,number,name', 'technician:id,name,external', 'stay:id,extra_charges'])
            ->where('status', Incident::STATUS_RESOLVED)
            ->whereBetween('resolved_at', [$from, $to])
            ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
            ->get();

        $reportedResolved = $reported->where('status', Incident::STATUS_RESOLVED)->whereNotNull('resolved_at');
        $avgHours = $reportedResolved->isNotEmpty()
            ? round($reportedResolved->avg(fn (Incident $i) => $i->created_at->diffInMinutes($i->resolved_at)) / 60, 1)
            : null;

        $priorityLabels = ['high' => 'Alta', 'medium' => 'Media', 'low' => 'Baja'];
        $statusLabels = ['open' => 'Abiertas', 'in_progress' => 'En proceso', 'resolved' => 'Resueltas'];

        return [
            'filters' => [
                'period' => $period,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'room' => $roomId,
            ],
            'period' => [
                'label' => $label.($room ? ' · Habitación '.trim($room->number.' '.($room->name ?? '')) : ''),
                'from' => $from->format('d/m/Y'),
                'to' => $to->format('d/m/Y'),
            ],
            'kpis' => [
                'reported' => $reported->count(),
                'resolved' => $resolved->count(),
                'pending' => $reported->whereIn('status', [Incident::STATUS_OPEN, Incident::STATUS_IN_PROGRESS])->count(),
                'high' => $reported->where('priority', 'high')->count(),
                'resolution_rate' => $reported->count() > 0
                    ? round($reportedResolved->count() / $reported->count() * 100, 1)
                    : 0,
                'avg_hours' => $avgHours,
                'rooms_affected' => $reported->pluck('room_id')->filter()->unique()->count(),
                // Pendientes TOTALES hoy (sin importar cuándo se reportaron):
                // el pizarrón real del equipo de mantenimiento.
                'open_now' => Incident::query()->active()
                    ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
                    ->count(),
            ],
            'costs' => $this->costs($resolved),
            'series' => $this->buildSeries($from, $to, $reported, $resolved),
            'byPriority' => collect(['high', 'medium', 'low'])->map(fn (string $priority) => [
                'priority' => $priority,
                'label' => $priorityLabels[$priority],
                'count' => $reported->where('priority', $priority)->count(),
            ])->filter(fn (array $row) => $row['count'] > 0)->values(),
            'byStatus' => collect([Incident::STATUS_OPEN, Incident::STATUS_IN_PROGRESS, Incident::STATUS_RESOLVED])
                ->map(fn (string $status) => [
                    'status' => $status,
                    'label' => $statusLabels[$status],
                    'count' => $reported->where('status', $status)->count(),
                ])->filter(fn (array $row) => $row['count'] > 0)->values(),
            // Tipo de falla: la vista que delata la falla repetitiva (tres
            // "clima" en el mes = revisar los minisplits, no parchar).
            'byCategory' => $reported
                ->groupBy(fn (Incident $i) => $i->categoryLabel() ?? 'Sin clasificar')
                ->map(fn (Collection $group, string $name) => [
                    'name' => $name,
                    'total' => $group->count(),
                    'high' => $group->where('priority', 'high')->count(),
                    'guest' => $group->filter(fn (Incident $i) => $i->isGuestReported())->count(),
                ])->sortByDesc('total')->values(),
            // Cuántas del periodo las levantó un huésped.
            'guestReported' => $reported->filter(fn (Incident $i) => $i->isGuestReported())->count(),
            'byRoom' => $reported->groupBy(fn (Incident $i) => $i->room
                ? trim($i->room->number.' '.($i->room->name ?? ''))
                : 'Área general')
                ->map(function (Collection $group, string $name) {
                    $groupResolved = $group->where('status', Incident::STATUS_RESOLVED)->whereNotNull('resolved_at');

                    return [
                        'name' => $name,
                        'total' => $group->count(),
                        'high' => $group->where('priority', 'high')->count(),
                        'resolved' => $groupResolved->count(),
                        'avg_hours' => $groupResolved->isNotEmpty()
                            ? round($groupResolved->avg(fn (Incident $i) => $i->created_at->diffInMinutes($i->resolved_at)) / 60, 1)
                            : null,
                    ];
                })->sortByDesc('total')->values(),
        ];
    }

    /**
     * El dinero del periodo, medido sobre lo RESUELTO (que es cuando se
     * paga la reparación, no cuando se reporta la falla): cuánto se gastó,
     * qué habitación sale cara, quién hizo el trabajo y cuánto de eso se
     * le alcanzó a cobrar al huésped que lo rompió.
     *
     * @param  Collection<int, Incident>  $resolved
     * @return array<string, mixed>
     */
    protected function costs(Collection $resolved): array
    {
        $withCost = $resolved->filter(fn (Incident $i) => $i->cost !== null);
        $charged = $resolved->map(fn (Incident $i) => $i->chargedToGuest())->filter();

        return [
            'total' => round((float) $withCost->sum(fn (Incident $i) => (float) $i->cost), 2),
            'jobs' => $withCost->count(),
            'missing' => $resolved->count() - $withCost->count(),
            'charged' => round((float) $charged->sum(), 2),
            'charged_jobs' => $charged->count(),
            'byRoom' => $withCost
                ->groupBy(fn (Incident $i) => $i->room
                    ? trim($i->room->number.' '.($i->room->name ?? ''))
                    : 'Área general')
                ->map(fn (Collection $group, string $name) => [
                    'name' => $name,
                    'jobs' => $group->count(),
                    'cost' => round((float) $group->sum(fn (Incident $i) => (float) $i->cost), 2),
                ])
                ->sortByDesc('cost')
                ->values(),
            'byTechnician' => $withCost
                ->groupBy(fn (Incident $i) => $i->technician?->name ?? 'Sin registrar')
                ->map(fn (Collection $group, string $name) => [
                    'name' => $name,
                    'kind' => $group->first()->technician?->kindLabel(),
                    'jobs' => $group->count(),
                    'cost' => round((float) $group->sum(fn (Incident $i) => (float) $i->cost), 2),
                ])
                ->sortByDesc('cost')
                ->values(),
        ];
    }

    /**
     * Serie temporal con cubetas según el tamaño del rango: día (≤31),
     * semana (≤120 días) o mes — reportadas vs resueltas por cubeta.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildSeries(CarbonImmutable $from, CarbonImmutable $to, Collection $reported, Collection $resolved): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $step = match (true) {
            $days <= 31 => 'day',
            $days <= 120 => 'week',
            default => 'month',
        };

        $series = [];
        $cursor = $from;

        while ($cursor <= $to) {
            $bucketEnd = match ($step) {
                'day' => $cursor->endOfDay(),
                'week' => $cursor->addDays(6)->endOfDay(),
                default => $cursor->endOfMonth(),
            };
            if ($bucketEnd > $to) {
                $bucketEnd = $to;
            }

            $inBucket = fn ($moment) => $moment !== null && $moment >= $cursor && $moment <= $bucketEnd;

            $series[] = [
                'label' => match ($step) {
                    'day' => $cursor->format('d/m'),
                    'week' => $cursor->format('d/m').' +',
                    default => ucfirst($cursor->locale('es')->isoFormat('MMM')),
                },
                'reported' => $reported->filter(fn (Incident $i) => $inBucket($i->created_at))->count(),
                'resolved' => $resolved->filter(fn (Incident $i) => $inBucket($i->resolved_at))->count(),
            ];

            $cursor = match ($step) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                default => $cursor->addMonthNoOverflow()->startOfMonth(),
            };
        }

        return $series;
    }
}
