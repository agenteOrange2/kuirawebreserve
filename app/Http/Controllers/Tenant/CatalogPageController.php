<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\Zone;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página de zonas, tipos de habitación y tarifas (las mutaciones van por
 * /api/zones, /api/room-types y /api/rate-plans).
 */
class CatalogPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();

        return Inertia::render('tenant/catalog/Index', [
            'property' => $property->only(['id', 'name']),
            'zones' => Zone::query()
                ->where('property_id', $property->id)
                ->withCount('rooms')
                ->orderBy('sort_order')
                ->get(['id', 'name', 'kind', 'color', 'sort_order'])
                ->map(fn (Zone $zone) => [
                    ...$zone->toArray(),
                    'kind_label' => $zone->kindLabel(),
                ]),
            'zoneKinds' => Zone::KINDS,
            'roomTypes' => RoomType::query()
                ->where('property_id', $property->id)
                ->withCount('rooms')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get([
                    'id', 'name', 'description', 'capacity', 'max_adults', 'max_children',
                    'base_price', 'check_in_time', 'check_out_time', 'amenities',
                    'sort_order', 'active',
                ])
                ->map(fn (RoomType $type) => [
                    ...$type->toArray(),
                    'check_in_time' => $type->check_in_time ? substr($type->check_in_time, 0, 5) : null,
                    'check_out_time' => $type->check_out_time ? substr($type->check_out_time, 0, 5) : null,
                    'amenities' => $type->amenities ?? [],
                ]),
            'ratePlans' => RatePlan::query()
                ->where('property_id', $property->id)
                ->with('roomType:id,name')
                ->orderBy('room_type_id')
                ->orderBy('price')
                ->get()
                ->map(fn (RatePlan $plan) => [
                    'id' => $plan->id,
                    'room_type_id' => $plan->room_type_id,
                    'room_type' => $plan->roomType?->name,
                    'name' => $plan->name,
                    'type' => $plan->type->value,
                    'duration_unit' => $plan->duration_unit?->value,
                    'duration_value' => $plan->duration_value,
                    'duration_label' => $plan->durationLabel(),
                    'price' => $plan->price,
                    'min_advance_unit' => $plan->min_advance_unit?->value,
                    'min_advance_value' => $plan->min_advance_value,
                    'min_advance_label' => $plan->minAdvanceLabel(),
                    'deposit_percent' => $plan->deposit_percent,
                    'payment_due_unit' => $plan->payment_due_unit?->value,
                    'payment_due_value' => $plan->payment_due_value,
                    'payment_due_label' => $plan->paymentDueLabel(),
                    'active' => $plan->active,
                ]),
            'canManage' => $request->user()->can('rooms.manage'),
        ]);
    }
}
