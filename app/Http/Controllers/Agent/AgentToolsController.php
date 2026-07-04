<?php

namespace App\Http\Controllers\Agent;

use App\Actions\Reservations\CreateReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Herramientas (tools) que consumen los agentes IA vía tool-calling
 * (spec-pendientes §4.1). Contratos JSON estables: montos siempre en crudo
 * + etiqueta formateada para minimizar alucinación de cifras. Reutiliza las
 * mismas actions/servicios que el panel — un solo camino de negocio.
 */
class AgentToolsController extends Controller
{
    /**
     * get_policies: identidad, horarios, contacto y políticas del hotel.
     */
    public function policies(): JsonResponse
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return response()->json([
            'hotel' => [
                'name' => $property->name,
                'address' => $property->address,
                'timezone' => $property->timezone,
                'phone' => $settings['phone'] ?? null,
                'email' => $settings['email'] ?? null,
            ],
            'check_in_time' => $settings['check_in_time'] ?? null,
            'check_out_time' => $settings['check_out_time'] ?? null,
            'currency' => $settings['currency'] ?? 'MXN',
            // Fuente única de verdad: si no está aquí, el agente no lo sabe.
            'policies' => $settings['policies'] ?? null,
            'faqs' => \App\Models\Faq::query()->active()->ordered()
                ->get()
                ->map(fn (\App\Models\Faq $faq) => [
                    'q' => $faq->question,
                    'a' => $faq->answer,
                ])->values(),
            'room_types' => RoomType::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (RoomType $type) => [
                    'name' => $type->name,
                    'description' => $type->description,
                    'capacity' => $type->capacity,
                ])->values(),
        ]);
    }

    /**
     * get_rate_plans: tarifas activas con las que se puede cotizar.
     */
    public function ratePlans(): JsonResponse
    {
        return response()->json([
            'rate_plans' => RatePlan::query()
                ->where('active', true)
                ->with('roomType:id,name,capacity')
                ->orderBy('price')
                ->get()
                ->map(fn (RatePlan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'room_type' => $plan->roomType?->name,
                    'capacity' => $plan->roomType?->capacity,
                    'billing' => $plan->type->value, // night | block
                    'duration_label' => $plan->durationLabel(),
                    'price' => (float) $plan->price,
                    'price_label' => '$'.number_format((float) $plan->price, 2),
                    'deposit_percent' => $plan->deposit_percent !== null ? (float) $plan->deposit_percent : null,
                    'min_advance' => $plan->minAdvanceLabel(),
                ])->values(),
        ]);
    }

    /**
     * check_availability: habitaciones libres y total para tarifa + rango.
     */
    public function availability(Request $request, AvailabilityService $availability): JsonResponse
    {
        $data = $request->validate([
            'rate_plan_id' => ['required', 'exists:rate_plans,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $ratePlan = RatePlan::findOrFail($data['rate_plan_id']);
        $start = Carbon::parse($data['starts_at']);
        $end = ! empty($data['ends_at']) ? Carbon::parse($data['ends_at']) : $ratePlan->suggestedEnd($start);

        $rooms = $availability->availableRooms($ratePlan->room_type_id, $start, $end);
        $total = $ratePlan->priceFor($start, $end);

        return response()->json([
            'available' => $rooms->isNotEmpty(),
            'rooms_count' => $rooms->count(),
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
            'units' => $ratePlan->unitsFor($start, $end),
            'duration_label' => $ratePlan->durationLabel(),
            'total' => $total,
            'total_label' => '$'.number_format($total, 2),
            'advance_error' => $ratePlan->violatesMinAdvance($start)
                ? "Esta tarifa requiere reservar con al menos {$ratePlan->minAdvanceLabel()} de antelación."
                : null,
        ]);
    }

    /**
     * get_reservation: estado de una reserva por su código (RES-AAAA-XXXX).
     */
    public function showReservation(string $code): JsonResponse
    {
        $reservation = Reservation::query()
            ->with(['room:id,number', 'ratePlan:id,name'])
            ->where('code', strtoupper(trim($code)))
            ->first();

        if (! $reservation) {
            return response()->json(['message' => 'No encontramos una reserva con ese código.'], 404);
        }

        // Privacidad: el agente solo confirma datos no sensibles.
        return response()->json([
            'code' => $reservation->displayCode(),
            'status' => $reservation->status->value,
            'status_label' => $reservation->status->label(),
            'guest_first_name' => str($reservation->guest_name ?? '')->before(' ')->toString() ?: null,
            'room' => $reservation->room?->number,
            'rate_plan' => $reservation->ratePlan?->name,
            'starts_at' => $reservation->starts_at->toIso8601String(),
            'ends_at' => $reservation->ends_at->toIso8601String(),
            'total' => (float) $reservation->total_amount,
            'total_label' => '$'.number_format((float) $reservation->total_amount, 2),
            'payment_status' => $reservation->payment_status->value,
            'payment_status_label' => $reservation->payment_status->label(),
            'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
        ]);
    }

    /**
     * create_hold: aparta habitación como reserva pendiente (NUNCA confirma
     * ni cobra). Idempotente vía header Idempotency-Key: el mismo intento
     * reintentado devuelve la respuesta original.
     */
    public function storeHold(Request $request, CreateReservation $action): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key !== '') {
            $hit = DB::table('agent_idempotency_keys')->where('key', $key)->first();
            if ($hit) {
                return response()
                    ->json(json_decode($hit->response, true), $hit->status)
                    ->header('Idempotency-Replayed', 'true');
            }
        }

        $data = $request->validate([
            'rate_plan_id' => ['required', 'exists:rate_plans,id'],
            'starts_at' => ['required', 'date', 'after_or_equal:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $reservation = $action->handle([
                ...$data,
                'confirmed' => false, // hold: lo confirma un humano en el panel
                'source_channel' => 'agent',
                'notes' => $data['notes'] ?? 'Creada por asistente IA',
            ], $request->user());
        } catch (NoAvailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload = [
            'code' => $reservation->displayCode(),
            'status' => ReservationStatus::Pending->value,
            'room' => $reservation->room?->number,
            'starts_at' => $reservation->starts_at->toIso8601String(),
            'ends_at' => $reservation->ends_at->toIso8601String(),
            'total' => (float) $reservation->total_amount,
            'total_label' => '$'.number_format((float) $reservation->total_amount, 2),
            'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
            'hold_minutes' => (int) config('reservations.hold_minutes', 30),
            'message' => 'Apartado creado; el hotel lo confirmará. Si no se confirma, expira solo.',
        ];

        if ($key !== '') {
            // Limpieza perezosa de llaves viejas + registro tolerante a carreras.
            DB::table('agent_idempotency_keys')->where('created_at', '<', now()->subDays(7))->delete();
            DB::table('agent_idempotency_keys')->insertOrIgnore([
                'key' => $key,
                'status' => 201,
                'response' => json_encode($payload),
                'created_at' => now(),
            ]);
        }

        return response()->json($payload, 201);
    }
}
