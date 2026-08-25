<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Housekeeper;
use App\Models\Room;
use App\Models\RoomCleaning;
use App\Services\HousekeepingChecklist;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel del día del módulo de limpieza: qué falta, qué se está limpiando
 * ahorita y qué se hizo hoy, con el nombre de quien lo trabajó.
 *
 * El semáforo sigue siendo la verdad del estado; esto le pone encima el
 * registro de personas y tiempos.
 */
class HousekeepingPageController extends Controller
{
    public function index(): Response
    {
        $checklist = new HousekeepingChecklist;
        $today = now()->startOfDay();

        $rooms = Room::query()
            ->with([
                'roomType:id,name',
                'openCleaning.housekeeper:id,name',
                // Columnas CALIFICADAS: latestOfMany se une contra un
                // subquery que también trae room_id, y el atajo
                // "relacion:col" deja el select ambiguo (error 1052).
                'latestStatusLog' => fn ($query) => $query->select(
                    'room_status_logs.id',
                    'room_status_logs.room_id',
                    'room_status_logs.to_status',
                    'room_status_logs.created_at',
                ),
            ])
            ->whereIn('status', [RoomStatus::Dirty->value, RoomStatus::Cleaning->value])
            ->orderBy('number')
            ->get()
            ->map(fn (Room $room) => [
                'id' => $room->id,
                'number' => $room->number,
                'type' => $room->roomType?->name,
                'status' => $room->status->getMorphClass(),
                'status_label' => $room->status->label(),
                // Desde cuándo está así: es lo que decide a cuál entrarle
                // primero cuando hay diez esperando.
                'since_minutes' => $room->latestStatusLog
                    ? (int) $room->latestStatusLog->created_at->diffInMinutes(now())
                    : null,
                'cleaning' => $room->openCleaning
                    ? RoomCleaningController::present($room->openCleaning)
                    : null,
            ]);

        $todayCleanings = RoomCleaning::query()
            ->with(['room:id,number', 'housekeeper:id,name'])
            ->where('started_at', '>=', $today)
            ->orderByDesc('started_at')
            ->get();

        return Inertia::render('tenant/housekeeping/Index', [
            'rooms' => $rooms,
            'cleanings' => $todayCleanings->map(fn (RoomCleaning $c) => RoomCleaningController::present($c)),
            'housekeepers' => Housekeeper::query()->active()->ordered()->get()
                ->map(fn (Housekeeper $h) => HousekeeperController::present($h)),
            'allRooms' => Room::query()->orderBy('number')->get(['id', 'number'])
                ->map(fn (Room $r) => ['id' => $r->id, 'number' => $r->number]),
            'checklist' => $checklist->tasks(onlyActive: true),
            'linens' => $checklist->linens(),
            'kinds' => RoomCleaning::KINDS,
            'stats' => $this->stats($todayCleanings, $rooms),
            'canManage' => request()->user()?->can('housekeeping.manage') ?? false,
            // El reporte mide desempeño de personas: no lo ve quien solo
            // registra el trabajo.
            'canViewReports' => request()->user()?->can('reports.view') ?? false,
        ]);
    }

    /** Camaristas y su carga del periodo. */
    public function staff(Request $request): Response
    {
        $from = now()->startOfMonth();

        return Inertia::render('tenant/housekeeping/Staff', [
            'housekeepers' => Housekeeper::query()
                ->withCount(['cleanings as month_count' => fn ($q) => $q->where('started_at', '>=', $from)])
                ->ordered()
                ->get()
                ->map(fn (Housekeeper $h) => HousekeeperController::present($h) + [
                    'month_count' => $h->month_count,
                ]),
            'periodLabel' => $from->locale('es')->isoFormat('MMMM [de] YYYY'),
            'canManage' => $request->user()?->can('housekeeping.manage') ?? false,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, RoomCleaning>  $cleanings
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rooms
     * @return array<string, mixed>
     */
    protected function stats($cleanings, $rooms): array
    {
        $closed = $cleanings->whereNotNull('ended_at');

        return [
            'cleaned_today' => $closed->count(),
            'in_progress' => $cleanings->whereNull('ended_at')->count(),
            'pending' => $rooms->where('status', RoomStatus::Dirty->value)->count(),
            'avg_minutes' => $closed->count() > 0
                ? (int) round($closed->avg('minutes'))
                : null,
            // Habitaciones que el reloj liberó sin que nadie registrara:
            // el trabajo se hizo pero no tiene dueño en el reporte.
            'unregistered' => $this->unregisteredToday(),
            'housekeepers' => Housekeeper::query()->active()->count(),
        ];
    }

    /**
     * Limpiezas que ocurrieron hoy según el semáforo pero sin registro: se
     * cuentan comparando los logs de entrada a "disponible" con las filas de
     * room_cleanings cerradas hoy.
     */
    protected function unregisteredToday(): int
    {
        $released = \App\Models\RoomStatusLog::query()
            ->where('to_status', RoomStatus::Available->value)
            ->where('from_status', RoomStatus::Cleaning->value)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        $registered = RoomCleaning::query()
            ->whereNotNull('ended_at')
            ->where('ended_at', '>=', now()->startOfDay())
            ->count();

        return max(0, $released - $registered);
    }
}
