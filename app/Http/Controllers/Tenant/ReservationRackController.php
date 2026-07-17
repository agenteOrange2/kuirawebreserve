<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rack de ocupación (spec-plan-maestro E4): habitaciones × días con las
 * reservas y estancias que tocan el rango. Solo lectura; agregación en
 * servidor (3 queries, sin N+1) para que 150 habitaciones × 31 días
 * carguen fluido. Las barras usan fechas-día; la geometría la calcula el
 * cliente. Mismo lenguaje de color que el plano y la lista: pendiente =
 * warning, confirmada = info, en casa = primary.
 */
class ReservationRackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            // Hasta 42: la vista mensual pide la cuadrícula completa (6 semanas).
            'days' => ['nullable', 'integer', 'min:7', 'max:42'],
        ]);

        $property = Property::firstOrFail();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->startOfDay();
        $days = (int) ($data['days'] ?? 14);
        $rangeEnd = $from->copy()->addDays($days);

        $rooms = Room::query()
            ->where('property_id', $property->id)
            ->with(['zone:id,name,color', 'roomType:id,name,sort_order'])
            ->orderBy('number')
            ->get();

        // Reservas que ocupan: pendientes con hold vigente + confirmadas.
        // Las "en casa" (checked_in) se representan por su estancia — así
        // los walk-ins sin reserva también aparecen y no hay barras dobles.
        $reservations = Reservation::query()
            ->whereNotNull('room_id')
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $from)
            ->where(function ($query) {
                $query->where('status', ReservationStatus::Confirmed)
                    ->orWhere(function ($pending) {
                        $pending->where('status', ReservationStatus::Pending)
                            ->where(fn ($hold) => $hold->whereNull('hold_expires_at')->orWhere('hold_expires_at', '>', now()));
                    });
            })
            ->get()
            ->groupBy('room_id');

        $stays = Stay::query()
            ->active()
            ->where('check_in_at', '<', $rangeEnd)
            ->where('planned_end_at', '>', $from)
            ->get()
            ->groupBy('room_id');

        // Tarifa activa más barata por tipo: precarga del alta desde celda.
        $defaultRatePlans = RatePlan::query()
            ->where('active', true)
            ->orderBy('price')
            ->get(['id', 'room_type_id'])
            ->groupBy('room_type_id')
            ->map(fn ($plans) => $plans->first()->id);

        $groups = $rooms
            ->groupBy('room_type_id')
            ->map(function ($typeRooms) use ($reservations, $stays, $defaultRatePlans) {
                $type = $typeRooms->first()->roomType;

                return [
                    'type_id' => $type?->id,
                    'type' => $type?->name ?? 'Sin tipo',
                    'sort_order' => $type?->sort_order ?? 999,
                    'rate_plan_id' => $type ? ($defaultRatePlans[$type->id] ?? null) : null,
                    'rooms' => $typeRooms->map(fn (Room $room) => [
                        'id' => $room->id,
                        'number' => $room->number,
                        'zone' => $room->zone?->name,
                        'zone_color' => $room->zone?->color,
                        'entries' => [
                            ...collect($reservations->get($room->id, collect()))->map(fn (Reservation $r) => [
                                'kind' => 'reservation',
                                'reservation_id' => $r->id,
                                'code' => $r->displayCode(),
                                'guest' => $r->guest_name,
                                'status' => $r->status->value,
                                'status_label' => $r->status->label(),
                                'tone' => $r->status === ReservationStatus::Pending ? 'warning' : 'info',
                                'start' => $r->starts_at->toDateString(),
                                'end' => $r->ends_at->toDateString(),
                                'time_range' => $r->starts_at->format('d/m H:i').' → '.$r->ends_at->format('d/m H:i'),
                            ]),
                            ...collect($stays->get($room->id, collect()))->map(fn (Stay $stay) => [
                                'kind' => 'stay',
                                'reservation_id' => $stay->reservation_id,
                                'code' => null,
                                'guest' => $stay->guest_name,
                                'status' => 'checked_in',
                                'status_label' => 'En casa',
                                'tone' => 'primary',
                                'start' => $stay->check_in_at->toDateString(),
                                'end' => $stay->planned_end_at->toDateString(),
                                'time_range' => $stay->check_in_at->format('d/m H:i').' → '.$stay->planned_end_at->format('d/m H:i'),
                            ]),
                        ],
                    ])->values(),
                ];
            })
            ->sortBy([['sort_order', 'asc'], ['type', 'asc']])
            ->map(fn (array $group) => collect($group)->except('sort_order')->all())
            ->values();

        return response()->json([
            'from' => $from->toDateString(),
            'days' => collect(range(0, $days - 1))->map(fn (int $i) => $from->copy()->addDays($i)->toDateString()),
            'today' => now()->toDateString(),
            'groups' => $groups,
        ]);
    }
}
