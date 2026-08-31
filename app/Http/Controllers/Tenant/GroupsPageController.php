<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\ExperienceBooking;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página del módulo Reservas grupales: los grupos con su composición y
 * el alta de grupos nuevos (todo-o-nada).
 *
 * La lista viaja PAGINADA y filtrada en el servidor. Antes traía los
 * últimos 100 grupos completos —cada uno con todas sus habitaciones y sus
 * recorridos— y filtraba en el navegador: un hotel con temporada de
 * grupos mandaba miles de filas en cada carga para pintar quince.
 */
class GroupsPageController extends Controller
{
    /** Grupos por página. */
    private const PER_PAGE = 15;

    /** Estados vivos de una reserva: los que hacen que el grupo cuente. */
    private const LIVE = [
        ReservationStatus::Pending,
        ReservationStatus::Confirmed,
        ReservationStatus::CheckedIn,
    ];

    /** Estados que ya no llegan (el grupo entero así es un cancelado). */
    private const DEAD = [
        ReservationStatus::Cancelled,
        ReservationStatus::NoShow,
    ];

    public function __invoke(Request $request): Response
    {
        $search = trim($request->string('q')->toString());
        $status = $request->string('status')->toString();
        $from = trim($request->string('from')->toString());
        $to = trim($request->string('to')->toString());

        $paginator = ReservationGroup::query()
            ->with(['reservations.roomType', 'reservations.room', 'experienceBookings.session.experience'])
            ->when($search !== '', fn (Builder $query) => $this->search($query, $search))
            ->when($status !== '', fn (Builder $query) => $this->filterByStatus($query, $status))
            // La llegada del grupo es la primera de sus habitaciones, igual
            // que la que muestra la tarjeta.
            ->when($from !== '', fn (Builder $query) => $query->whereRaw(
                '(select min(starts_at) from reservations where reservations.reservation_group_id = reservation_groups.id) >= ?',
                [$from.' 00:00:00'],
            ))
            ->when($to !== '', fn (Builder $query) => $query->whereRaw(
                '(select min(starts_at) from reservations where reservations.reservation_group_id = reservation_groups.id) <= ?',
                [$to.' 23:59:59'],
            ))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $paginator->through(fn (ReservationGroup $group) => GroupReservationController::serialize($group));

        return Inertia::render('tenant/groups/Index', [
            'groups' => $paginator,
            'filters' => ['q' => $search, 'status' => $status, 'from' => $from, 'to' => $to],
            // Los contadores miran TODOS los grupos, no la página que se
            // está viendo: si no, "grupos activos" cambiaría al paginar.
            'stats' => $this->stats(),
            // Para el alta: tipos activos con qué modalidades venden y
            // cuántos cuartos físicos tienen (tope visual del selector).
            'roomTypes' => RoomType::query()
                ->where('active', true)
                ->withCount('rooms')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (RoomType $type) => [
                    'id' => $type->id,
                    'name' => $type->name,
                    'capacity' => $type->capacity,
                    'rooms_count' => $type->rooms_count,
                    'has_night' => $type->ratePlans()->where('active', true)->where('type', 'night')->exists(),
                    'has_block' => $type->ratePlans()->where('active', true)->where('type', 'block')->exists(),
                ]),
            'canManage' => $request->user()->can('reservations.manage'),
        ]);
    }

    /**
     * Busca por el grupo (folio, responsable, nota) y por lo que trae
     * dentro: folio de una habitación, número de cuarto o tipo — lo mismo
     * que buscaba el filtro del navegador.
     */
    protected function search(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $q) use ($search) {
            $q->where('code', 'like', "%{$search}%")
                ->orWhere('guest_name', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")
                ->orWhereHas('reservations', fn ($r) => $r
                    ->where('code', 'like', "%{$search}%")
                    ->orWhereHas('room', fn ($room) => $room->where('number', 'like', "%{$search}%"))
                    ->orWhereHas('roomType', fn ($type) => $type->where('name', 'like', "%{$search}%")))
                ->orWhereHas('experienceBookings.session.experience', fn ($e) => $e
                    ->where('name', 'like', "%{$search}%"));
        });
    }

    /**
     * El estado del grupo no es una columna: sale de sus habitaciones
     * (alojado > confirmado > por confirmar > cancelado > finalizado),
     * igual que lo pinta la tarjeta. Aquí va en SQL para que el filtro
     * pueda paginar.
     */
    protected function filterByStatus(Builder $query, string $status): Builder
    {
        $has = fn (array $statuses) => fn (Builder $q) => $q->whereHas(
            'reservations',
            fn ($r) => $r->whereIn('status', $statuses),
        );
        $hasNot = fn (array $statuses) => fn (Builder $q) => $q->whereDoesntHave(
            'reservations',
            fn ($r) => $r->whereIn('status', $statuses),
        );

        return match ($status) {
            'checked_in' => $query->tap($has([ReservationStatus::CheckedIn->value])),
            'confirmed' => $query
                ->tap($has([ReservationStatus::Confirmed->value]))
                ->tap($hasNot([ReservationStatus::CheckedIn->value])),
            'pending' => $query
                ->tap($has([ReservationStatus::Pending->value]))
                ->tap($hasNot([
                    ReservationStatus::CheckedIn->value,
                    ReservationStatus::Confirmed->value,
                ])),
            // Cancelado = tiene habitaciones y NINGUNA sigue en pie.
            'cancelled' => $query
                ->whereHas('reservations')
                ->whereDoesntHave('reservations', fn ($r) => $r->whereNotIn('status', $this->values(self::DEAD))),
            // Finalizado = ya no queda nada vivo y no es un cancelado
            // completo (un grupo vacío también cae aquí).
            'completed' => $query
                ->tap($hasNot($this->values(self::LIVE)))
                ->where(fn (Builder $g) => $g
                    ->whereDoesntHave('reservations')
                    ->orWhereHas('reservations', fn ($r) => $r->whereNotIn('status', $this->values(self::DEAD)))),
            default => $query,
        };
    }

    /**
     * Contadores de la cabecera sobre TODOS los grupos (no sobre la página).
     *
     * @return array<string, float|int>
     */
    protected function stats(): array
    {
        $live = $this->values(self::LIVE);

        $liveGroupIds = ReservationGroup::query()
            ->select('id')
            ->whereHas('reservations', fn ($r) => $r->whereIn('status', $live));

        $rooms = Reservation::query()
            ->whereNotNull('reservation_group_id')
            ->whereIn('status', $live);

        // Mismo total que muestra la tarjeta del grupo: sus habitaciones
        // más los recorridos que siguen en pie.
        $value = (float) Reservation::query()
            ->whereIn('reservation_group_id', $liveGroupIds)
            ->sum('total_amount')
            + (float) ExperienceBooking::query()
                ->whereIn('reservation_group_id', $liveGroupIds)
                ->whereIn('status', [ExperienceBooking::STATUS_PENDING, ExperienceBooking::STATUS_CONFIRMED])
                ->sum('total');

        return [
            'total' => ReservationGroup::query()->count(),
            'active' => ReservationGroup::query()->whereHas('reservations', fn ($r) => $r->whereIn('status', $live))->count(),
            'pending' => $this->filterByStatus(ReservationGroup::query(), 'pending')->count(),
            'rooms' => $rooms->count(),
            'value' => round($value, 2),
        ];
    }

    /**
     * @param  array<int, ReservationStatus>  $statuses
     * @return array<int, string>
     */
    protected function values(array $statuses): array
    {
        return array_map(fn (ReservationStatus $status) => $status->value, $statuses);
    }
}
