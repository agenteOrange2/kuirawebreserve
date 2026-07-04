<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Property;
use App\Models\Room;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plano visual de habitaciones (fase 1): canvas drag-and-drop con el
 * semáforo en vivo.
 */
class FloorPlanController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $property = Property::query()
            ->when($request->integer('property_id'), fn ($q, $id) => $q->whereKey($id))
            ->firstOrFail();

        $rooms = Room::query()
            ->where('property_id', $property->id)
            ->with([
                'zone:id,name,kind,color',
                'roomType:id,name,capacity,base_price,amenities,check_in_time,check_out_time',
                'roomType.ratePlans' => fn ($query) => $query
                    ->select(['id', 'room_type_id', 'name', 'type', 'price', 'duration_minutes', 'duration_unit', 'duration_value', 'active'])
                    ->where('active', true)
                    ->orderBy('price'),
                'activeStay' => fn ($query) => $query
                    ->with(['guest:id,first_name,last_name', 'ratePlan:id,name'])
                    ->withSum(
                        ['orders as consumos_total' => fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)],
                        'total',
                    ),
                'upcomingReservation' => fn ($query) => $query
                    ->with(['guest:id,first_name,last_name', 'ratePlan:id,name']),
                'statusLogs' => fn ($query) => $query
                    ->where('created_at', '>=', now()->startOfDay())
                    ->latest('created_at')
                    ->limit(8)
                    ->with('changedBy:id,name'),
            ])
            ->orderBy('number')
            ->get()
            ->map(fn (Room $room) => $room->toFloorPlanPayload());

        return Inertia::render('tenant/FloorPlan', [
            'tenantId' => tenant('id'),
            'property' => $property->only(['id', 'name']),
            'properties' => Property::query()->get(['id', 'name']),
            'rooms' => $rooms,
            'canManage' => $request->user()->can('rooms.update-status'),
            'canManageReservations' => $request->user()->can('reservations.manage'),
            'canManageOrders' => $request->user()->can('orders.manage'),
        ]);
    }
}
