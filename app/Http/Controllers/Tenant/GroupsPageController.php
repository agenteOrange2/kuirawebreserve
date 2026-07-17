<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ReservationGroup;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página del módulo Reservas grupales: los grupos con su composición y
 * el alta de grupos nuevos (todo-o-nada).
 */
class GroupsPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('tenant/groups/Index', [
            'groups' => ReservationGroup::query()
                ->with(['reservations.roomType', 'reservations.room', 'experienceBookings.session.experience'])
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (ReservationGroup $group) => GroupReservationController::serialize($group)),
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
}
