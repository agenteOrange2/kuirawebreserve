<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Housekeeper;
use App\Models\Room;
use App\Models\RoomCleaning;
use App\Services\HousekeepingChecklist;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel del día del módulo de limpieza: qué falta, qué se está limpiando
 * ahorita y la bitácora de lo registrado, con el nombre de quien lo trabajó.
 *
 * El semáforo sigue siendo la verdad del estado; esto le pone encima el
 * registro de personas y tiempos.
 *
 * Las dos listas viajan PAGINADAS y filtradas en el servidor, cada una con su
 * propio parámetro de página para que moverse en una no reinicie la otra.
 * Antes traían TODAS las habitaciones sucias y TODAS las limpiezas del día en
 * tres columnas sin tope: en un hotel con temporada eso son cientos de filas
 * en cada carga.
 */
class HousekeepingPageController extends Controller
{
    /** Filas por página en cada una de las dos tablas. */
    private const PER_PAGE = 15;

    /** Estados que aparecen en el tablero del día. */
    private const BOARD_STATUSES = [
        RoomStatus::Dirty->value,
        RoomStatus::Cleaning->value,
    ];

    public function index(Request $request): Response
    {
        $checklist = new HousekeepingChecklist;

        return Inertia::render('tenant/housekeeping/Index', [
            'rooms' => $this->board($request),
            'boardFilters' => [
                'estado' => $this->boardStatus($request),
                'q' => trim($request->string('q')->toString()),
                'orden' => $this->boardOrder($request),
            ],
            'cleanings' => $this->log($request),
            'logFilters' => [
                'rango' => $this->logRange($request),
                'camarista' => $request->integer('camarista') ?: null,
                'tipo' => $this->logKind($request),
                'situacion' => $this->logState($request),
                'hab' => trim($request->string('hab')->toString()),
                'orden' => $this->logOrder($request),
            ],
            'housekeepers' => Housekeeper::query()->active()->ordered()->get()
                ->map(fn (Housekeeper $h) => HousekeeperController::present($h)),
            // Quién está programado hoy y quién de ellas está en turno
            // ahorita: el registro de una limpieza casi siempre es de
            // alguien que está trabajando en este momento.
            'onDuty' => app(\App\Services\ShiftRoster::class)->today('housekeeper'),
            'allRooms' => Room::query()->orderBy('number')->get(['id', 'number'])
                ->map(fn (Room $r) => ['id' => $r->id, 'number' => $r->number]),
            'checklist' => $checklist->tasks(onlyActive: true),
            'linens' => $checklist->linens(),
            'kinds' => RoomCleaning::KINDS,
            // Los contadores miran TODA la operación del día, no la página
            // que se está viendo: si no, "por limpiar" cambiaría al paginar.
            'stats' => $this->stats(),
            'canManage' => $request->user()?->can('housekeeping.manage') ?? false,
            // El reporte mide desempeño de personas: no lo ve quien solo
            // registra el trabajo.
            'canViewReports' => $request->user()?->can('reports.view') ?? false,
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
            'onDuty' => app(\App\Services\ShiftRoster::class)->today('housekeeper'),
            'periodLabel' => $from->locale('es')->isoFormat('MMMM [de] YYYY'),
            'canManage' => $request->user()?->can('housekeeping.manage') ?? false,
        ]);
    }

    /**
     * Tablero del día: las habitaciones sucias y en limpieza en UNA sola
     * tabla ordenable, en vez de dos columnas paralelas.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    protected function board(Request $request): LengthAwarePaginator
    {
        $estado = $this->boardStatus($request);
        $search = trim($request->string('q')->toString());
        $orden = $this->boardOrder($request);

        return Room::query()
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
            // Para poder ORDENAR por antigüedad hace falta la fecha en SQL:
            // la relación cargada solo sirve para pintarla.
            ->withMax('statusLogs as last_status_at', 'created_at')
            ->whereIn('status', $estado !== '' ? [$estado] : self::BOARD_STATUSES)
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $q) => $q
                    ->where('number', 'like', "%{$search}%")
                    ->orWhereHas('roomType', fn ($t) => $t->where('name', 'like', "%{$search}%")),
            ))
            // Por espera: la que lleva más tiempo parada va primero, porque
            // es la que está costando dinero. Las que no tienen historial de
            // semáforo se van al final en vez de encabezar la lista.
            ->when(
                $orden === 'espera',
                fn (Builder $query) => $query->orderByRaw('last_status_at is null asc')->orderBy('last_status_at'),
                fn (Builder $query) => $query->orderBy('number'),
            )
            ->paginate(self::PER_PAGE, ['*'], 'pagina')
            ->withQueryString()
            ->through(fn (Room $room) => [
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
    }

    /**
     * Bitácora de limpiezas registradas. Por omisión son las de hoy —que es
     * lo que la recepción mira— pero se puede abrir a la semana, al mes o a
     * todo el historial.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    protected function log(Request $request): LengthAwarePaginator
    {
        [$from, $to] = $this->logWindow($this->logRange($request));

        $camarista = $request->integer('camarista') ?: null;
        $tipo = $this->logKind($request);
        $situacion = $this->logState($request);
        $hab = trim($request->string('hab')->toString());
        $orden = $this->logOrder($request);

        return RoomCleaning::query()
            ->with(['room:id,number', 'housekeeper:id,name'])
            ->when($from, fn (Builder $query) => $query->where('started_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('started_at', '<=', $to))
            ->when($camarista, fn (Builder $query) => $query->where('housekeeper_id', $camarista))
            ->when($tipo !== '', fn (Builder $query) => $query->where('kind', $tipo))
            ->when($situacion === 'abiertas', fn (Builder $query) => $query->open())
            ->when($situacion === 'cerradas', fn (Builder $query) => $query->closed())
            ->when($hab !== '', fn (Builder $query) => $query->whereHas(
                'room',
                fn ($r) => $r->where('number', 'like', "%{$hab}%"),
            ))
            // Por duración solo tiene sentido sobre lo cerrado; las abiertas
            // no tienen minutos sellados y caen al final solas.
            ->when(
                $orden === 'duracion',
                fn (Builder $query) => $query->orderByDesc('minutes')->orderByDesc('started_at'),
                fn (Builder $query) => $query->orderByDesc('started_at'),
            )
            ->paginate(self::PER_PAGE, ['*'], 'bitacora')
            ->withQueryString()
            ->through(fn (RoomCleaning $cleaning) => RoomCleaningController::present($cleaning) + [
                // La ventana puede abarcar varios días: sin el día, un
                // "08:30" no dice de cuándo es.
                'started_day' => $cleaning->started_at?->format('d/m/Y'),
            ]);
    }

    /**
     * Ventana de fechas de la bitácora. `todo` no acota: es el historial
     * completo, y por eso la tabla va paginada.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    protected function logWindow(string $rango): array
    {
        return match ($rango) {
            'semana' => [now()->startOfWeek(), now()->endOfWeek()],
            'mes' => [now()->startOfMonth(), now()->endOfMonth()],
            'todo' => [null, null],
            default => [now()->startOfDay(), now()->endOfDay()],
        };
    }

    protected function boardStatus(Request $request): string
    {
        $estado = $request->string('estado')->toString();

        return in_array($estado, self::BOARD_STATUSES, true) ? $estado : '';
    }

    protected function boardOrder(Request $request): string
    {
        return $request->string('orden')->toString() === 'numero' ? 'numero' : 'espera';
    }

    protected function logRange(Request $request): string
    {
        $rango = $request->string('rango')->toString();

        return in_array($rango, ['hoy', 'semana', 'mes', 'todo'], true) ? $rango : 'hoy';
    }

    protected function logKind(Request $request): string
    {
        $tipo = $request->string('tipo')->toString();

        return array_key_exists($tipo, RoomCleaning::KINDS) ? $tipo : '';
    }

    protected function logState(Request $request): string
    {
        $situacion = $request->string('situacion')->toString();

        return in_array($situacion, ['abiertas', 'cerradas'], true) ? $situacion : '';
    }

    protected function logOrder(Request $request): string
    {
        return $request->string('lorden')->toString() === 'duracion' ? 'duracion' : 'reciente';
    }

    /**
     * Contadores del día sobre TODA la operación (no sobre la página).
     *
     * @return array<string, mixed>
     */
    protected function stats(): array
    {
        $today = now()->startOfDay();

        $closedToday = RoomCleaning::query()
            ->whereNotNull('ended_at')
            ->where('started_at', '>=', $today);

        return [
            'cleaned_today' => (clone $closedToday)->count(),
            'in_progress' => RoomCleaning::query()
                ->whereNull('ended_at')
                ->where('started_at', '>=', $today)
                ->count(),
            'pending' => Room::query()->where('status', RoomStatus::Dirty->value)->count(),
            'cleaning_rooms' => Room::query()->where('status', RoomStatus::Cleaning->value)->count(),
            'avg_minutes' => (clone $closedToday)->count() > 0
                ? (int) round((clone $closedToday)->avg('minutes'))
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
