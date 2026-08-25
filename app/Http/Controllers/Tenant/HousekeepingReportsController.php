<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Housekeeper;
use App\Models\Property;
use App\Models\RoomCleaning;
use App\Models\RoomStatusLog;
use App\Services\HousekeepingChecklist;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rendimiento de limpieza por periodo: cuánto hizo cada camarista, qué tan
 * rápido vuelve a estar vendible una habitación y cuánta ropa se gastó.
 *
 * Mismo molde de periodos que el reporte de incidencias, para que el hotel
 * no aprenda dos filtros distintos.
 */
class HousekeepingReportsController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('tenant/housekeeping/Reports', $this->reportData($request));
    }

    public function pdf(Request $request)
    {
        $data = $this->reportData($request);
        $data['property'] = Property::query()->firstOrFail()->only(['id', 'name']);
        $data['generatedAt'] = now()->format('d/m/Y H:i');

        $slug = $data['filters']['from'].'-a-'.$data['filters']['to'];

        return Pdf::loadView('pdf.housekeeping-report', $data)
            ->setPaper('letter')
            ->download("reporte-limpieza-{$slug}.pdf");
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
            'housekeeper' => ['nullable', 'integer'],
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

        $housekeeperId = $request->integer('housekeeper') ?: null;

        $cleanings = RoomCleaning::query()
            ->with(['housekeeper:id,name', 'room:id,number'])
            ->between($from, $to)
            ->when($housekeeperId, fn ($q) => $q->where('housekeeper_id', $housekeeperId))
            ->get();

        $closed = $cleanings->whereNotNull('ended_at');

        return [
            'filters' => [
                'period' => $period,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'housekeeper' => $housekeeperId,
            ],
            'periodLabel' => $label,
            'kpis' => [
                'rooms' => $closed->count(),
                'in_progress' => $cleanings->whereNull('ended_at')->count(),
                'avg_minutes' => $closed->count() > 0 ? (int) round($closed->avg('minutes')) : null,
                'total_hours' => round($closed->sum('minutes') / 60, 1),
                'turnaround' => $this->turnaround($from, $to),
            ],
            'byHousekeeper' => $this->byHousekeeper($closed),
            'linens' => $this->linenTotals($closed),
            'byKind' => $this->byKind($closed),
            'housekeepers' => Housekeeper::query()->ordered()->get(['id', 'name'])
                ->map(fn (Housekeeper $h) => ['id' => $h->id, 'name' => $h->name]),
        ];
    }

    /**
     * Rendimiento por persona. El promedio se calcula solo sobre limpiezas
     * cerradas: una abierta todavía no dice cuánto tardó.
     *
     * @param  \Illuminate\Support\Collection<int, RoomCleaning>  $cleanings
     * @return array<int, array<string, mixed>>
     */
    protected function byHousekeeper($cleanings): array
    {
        return $cleanings
            ->groupBy(fn (RoomCleaning $c) => $c->housekeeper?->name ?? 'Sin camarista')
            ->map(fn ($group, $name) => [
                'name' => $name,
                'rooms' => $group->count(),
                'avg_minutes' => (int) round($group->avg('minutes')),
                'total_minutes' => (int) $group->sum('minutes'),
                'fastest' => (int) $group->min('minutes'),
                'slowest' => (int) $group->max('minutes'),
                'linens' => $this->linenTotals($group),
            ])
            ->sortByDesc('rooms')
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, RoomCleaning>  $cleanings
     * @return array<int, array<string, mixed>>
     */
    protected function linenTotals($cleanings): array
    {
        $labels = collect(app(HousekeepingChecklist::class)->linens())->pluck('label', 'key');
        $totals = [];

        foreach ($cleanings as $cleaning) {
            foreach ($cleaning->linens ?? [] as $key => $quantity) {
                $totals[$key] = ($totals[$key] ?? 0) + (int) $quantity;
            }
        }

        return collect($totals)
            ->map(fn (int $total, string $key) => [
                'key' => $key,
                'label' => $labels[$key] ?? $key,
                'total' => $total,
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, RoomCleaning>  $cleanings
     * @return array<int, array<string, mixed>>
     */
    protected function byKind($cleanings): array
    {
        return collect(RoomCleaning::KINDS)
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'count' => $cleanings->where('kind', $key)->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * Tiempo de respuesta del hotel: cuánto tarda una habitación en volver a
     * estar vendible desde que se desocupa.
     *
     * Se calcula sobre el semáforo (room_status_logs), no sobre los
     * registros: incluye la espera en "por limpiar" —que no es trabajo de
     * nadie pero sí dinero parado— y cuenta también lo que liberó el reloj.
     *
     * @return array{samples: int, avg_wait: ?int, avg_total: ?int}
     */
    protected function turnaround(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $logs = RoomStatusLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('room_id')
            ->orderBy('id')
            ->get(['room_id', 'from_status', 'to_status', 'created_at']);

        $waits = [];
        $totals = [];

        foreach ($logs->groupBy('room_id') as $roomLogs) {
            $dirtyAt = null;

            foreach ($roomLogs as $log) {
                if ($log->to_status === RoomStatus::Dirty->value) {
                    $dirtyAt = $log->created_at;

                    continue;
                }

                if ($log->to_status === RoomStatus::Cleaning->value && $dirtyAt) {
                    $waits[] = $dirtyAt->diffInMinutes($log->created_at);

                    continue;
                }

                if ($log->to_status === RoomStatus::Available->value && $dirtyAt) {
                    $totals[] = $dirtyAt->diffInMinutes($log->created_at);
                    $dirtyAt = null;
                }
            }
        }

        return [
            'samples' => count($totals),
            'avg_wait' => $waits === [] ? null : (int) round(array_sum($waits) / count($waits)),
            'avg_total' => $totals === [] ? null : (int) round(array_sum($totals) / count($totals)),
        ];
    }
}
