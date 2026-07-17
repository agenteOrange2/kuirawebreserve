<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\RatePlan;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    /**
     * Habitaciones libres para un rate plan y rango. Es la misma consulta
     * que usará la API pública (fase 6) y los agentes IA (fase 4).
     */
    public function __invoke(Request $request, AvailabilityService $availability): JsonResponse
    {
        $data = $request->validate([
            'rate_plan_id' => ['required', 'exists:rate_plans,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'ignore_reservation_id' => ['nullable', 'exists:reservations,id'],
        ]);

        $ratePlan = RatePlan::findOrFail($data['rate_plan_id']);
        $start = Carbon::parse($data['starts_at']);
        $end = ! empty($data['ends_at']) ? Carbon::parse($data['ends_at']) : $ratePlan->suggestedEnd($start);

        $rooms = $availability->availableRooms(
            $ratePlan->room_type_id,
            $start,
            $end,
            $request->integer('ignore_reservation_id') ?: null,
        );

        // Aviso temprano de antelación mínima (spec §2.6.2): el panel/bot lo
        // muestran antes de intentar crear la reserva.
        $advanceError = $ratePlan->violatesMinAdvance($start)
            ? "Esta tarifa requiere reservar con al menos {$ratePlan->minAdvanceLabel()} de antelación."
            : null;

        return response()->json([
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
            'units' => $ratePlan->unitsFor($start, $end),
            'duration_label' => $ratePlan->durationLabel(),
            'total' => $ratePlan->priceFor($start, $end),
            'advance_error' => $advanceError,
            'rooms' => $rooms->map(fn ($room) => [
                'id' => $room->id,
                'number' => $room->number,
                'status' => $room->status->getMorphClass(),
                // Ficha de cobros del cuarto: el panel estima personas extra
                // y ofrece los cargos opcionales antes de crear la reserva.
                'included_occupancy' => $room->included_occupancy,
                'extra_guest_fee' => $room->extra_guest_fee !== null ? (float) $room->extra_guest_fee : null,
                'optional_charges' => collect($room->optional_charges ?? [])
                    ->map(fn (array $charge) => [
                        'concept' => (string) ($charge['concept'] ?? ''),
                        'amount' => round((float) ($charge['amount'] ?? 0), 2),
                    ])
                    ->values()
                    ->all(),
            ])->values(),
        ]);
    }
}
