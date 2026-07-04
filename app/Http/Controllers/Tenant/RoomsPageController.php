<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Zone;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página de gestión de habitaciones (las mutaciones van por /api/rooms).
 */
class RoomsPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();

        return Inertia::render('tenant/rooms/Index', [
            'property' => $property->only(['id', 'name']),
            'rooms' => Room::query()
                ->where('property_id', $property->id)
                ->with(['zone:id,name', 'roomType:id,name'])
                ->orderBy('number')
                ->get()
                ->map(fn (Room $room) => [
                    'id' => $room->id,
                    'number' => $room->number,
                    'name' => $room->name,
                    'description' => $room->description,
                    'beds' => $room->beds ?? [],
                    'beds_label' => $room->bedsLabel(),
                    'max_occupancy' => $room->max_occupancy,
                    'capacity' => $room->effectiveMaxOccupancy(),
                    'size_m2' => $room->size_m2 !== null ? (float) $room->size_m2 : null,
                    'view' => $room->view,
                    'amenities' => $room->amenities ?? [],
                    'smoking' => $room->smoking,
                    'accessible' => $room->accessible,
                    'price_modifier' => $room->price_modifier !== null ? (float) $room->price_modifier : null,
                    'zone_id' => $room->zone_id,
                    'zone' => $room->zone?->name,
                    'zone_color' => $room->zone?->color,
                    'room_type_id' => $room->room_type_id,
                    'room_type' => $room->roomType->name,
                    'status' => $room->status->getMorphClass(),
                    'status_label' => $room->status->label(),
                    'status_color' => $room->status->color(),
                    'notes' => $room->notes,
                    'maintenance_notes' => $room->maintenance_notes,
                ]),
            'zones' => Zone::where('property_id', $property->id)->orderBy('sort_order')->get(['id', 'name', 'kind', 'color']),
            'roomTypes' => RoomType::where('property_id', $property->id)->orderBy('sort_order')->get(['id', 'name', 'capacity', 'base_price']),
            'bedTypes' => Room::BED_TYPES,
            'maxRooms' => tenant()->planLimit('max_rooms'),
            'canManage' => $request->user()->can('rooms.manage'),
        ]);
    }
}
