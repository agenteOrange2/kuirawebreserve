<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ficha detallada de una habitación: perfil completo, uso por periodo
 * (semana / mes / 3 meses / año), serie mensual, estancias recientes e
 * historial del semáforo.
 */
class RoomShowController extends Controller
{
    public function __invoke(Room $room): Response
    {
        $room->load(['zone:id,name,color', 'roomType:id,name,capacity,base_price']);

        $now = Carbon::now();

        // "Uso" = estancias cuyo check-in cae en el periodo.
        $usage = fn (Carbon $from) => Stay::query()
            ->where('room_id', $room->id)
            ->where('check_in_at', '>=', $from)
            ->count();

        $revenue = fn (Carbon $from) => round((float) Stay::query()
            ->where('room_id', $room->id)
            ->where('check_in_at', '>=', $from)
            ->sum('amount'), 2);

        $periods = [
            ['key' => 'week', 'label' => 'Esta semana', 'from' => $now->copy()->startOfWeek()],
            ['key' => 'month', 'label' => 'Este mes', 'from' => $now->copy()->startOfMonth()],
            ['key' => 'quarter', 'label' => 'Últimos 3 meses', 'from' => $now->copy()->subMonthsNoOverflow(3)->startOfDay()],
            ['key' => 'year', 'label' => 'Este año', 'from' => $now->copy()->startOfYear()],
        ];

        $usageStats = collect($periods)->map(fn (array $p) => [
            'key' => $p['key'],
            'label' => $p['label'],
            'count' => $usage($p['from']),
            'revenue' => $revenue($p['from']),
        ])->values();

        $totalStays = Stay::query()->where('room_id', $room->id)->count();
        $totalRevenue = round((float) Stay::query()->where('room_id', $room->id)->sum('amount'), 2);
        $lastStayAt = Stay::query()->where('room_id', $room->id)->max('check_in_at');

        return Inertia::render('tenant/rooms/Show', [
            'room' => [
                'id' => $room->id,
                'number' => $room->number,
                'name' => $room->name,
                'description' => $room->description,
                'room_type' => $room->roomType->name,
                'base_price' => (float) $room->roomType->base_price,
                'zone' => $room->zone?->name,
                'zone_color' => $room->zone?->color,
                'status' => $room->status->getMorphClass(),
                'status_label' => $room->status->label(),
                'status_color' => $room->status->color(),
                'beds_label' => $room->bedsLabel(),
                'capacity' => $room->effectiveMaxOccupancy(),
                'size_m2' => $room->size_m2 !== null ? (float) $room->size_m2 : null,
                'view' => $room->view,
                'amenities' => $room->effectiveAmenities(),
                'smoking' => $room->smoking,
                'accessible' => $room->accessible,
                'price_modifier' => $room->price_modifier !== null ? (float) $room->price_modifier : null,
                'notes' => $room->notes,
                'maintenance_notes' => $room->maintenance_notes,
            ],
            'usage' => $usageStats,
            'totals' => [
                'stays' => $totalStays,
                'revenue' => $totalRevenue,
                'last_stay_at' => $lastStayAt ? Carbon::parse($lastStayAt)->format('d/m/Y') : null,
            ],
            'canManage' => request()->user()->can('rooms.manage'),
        ]);
    }
}
