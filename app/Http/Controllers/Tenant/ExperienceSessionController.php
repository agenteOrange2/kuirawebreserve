<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Experience;
use App\Models\ExperienceBooking;
use App\Models\ExperienceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sesiones de una experiencia (fecha/hora + cupo). v1: se crean a mano;
 * la recurrencia ("cada martes 10am") queda para una siguiente iteración
 * (spec-reservas-avanzado §3.5.5).
 */
class ExperienceSessionController extends Controller
{
    public function store(Request $request, Experience $experience): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date', 'after:now'],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
        ]);

        $session = $experience->sessions()->create([
            ...$data,
            'status' => ExperienceSession::STATUS_SCHEDULED,
        ]);

        return response()->json($this->serialize($session), 201);
    }

    public function update(Request $request, Experience $experience, ExperienceSession $session): JsonResponse
    {
        abort_unless($session->experience_id === $experience->id, 404);

        $data = $request->validate([
            'starts_at' => ['sometimes', 'date'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'status' => ['sometimes', Rule::in([ExperienceSession::STATUS_SCHEDULED, ExperienceSession::STATUS_CANCELLED, ExperienceSession::STATUS_COMPLETED])],
        ]);

        // El cupo no puede bajar de lo ya vendido: eso inventaría overbooking.
        if (isset($data['capacity']) && $data['capacity'] < $session->peopleBooked()) {
            return response()->json([
                'message' => "Ya hay {$session->peopleBooked()} persona(s) reservadas; el cupo no puede ser menor.",
            ], 422);
        }

        $session->update($data);

        // Cancelar la sesión cancela sus reservas vivas — el hotel avisa a
        // los inscritos por sus canales (reembolsos: decisión humana,
        // spec-reservas-avanzado §3.5.4).
        $cancelled = 0;
        if (($data['status'] ?? null) === ExperienceSession::STATUS_CANCELLED) {
            $cancelled = $session->bookings()
                ->whereIn('status', [ExperienceBooking::STATUS_PENDING, ExperienceBooking::STATUS_CONFIRMED])
                ->update(['status' => ExperienceBooking::STATUS_CANCELLED, 'updated_at' => now()]);
        }

        return response()->json([...$this->serialize($session->fresh()), 'cancelled_bookings' => $cancelled]);
    }

    public function destroy(Experience $experience, ExperienceSession $session): JsonResponse
    {
        abort_unless($session->experience_id === $experience->id, 404);

        if ($session->bookings()->whereIn('status', [ExperienceBooking::STATUS_PENDING, ExperienceBooking::STATUS_CONFIRMED])->exists()) {
            return response()->json([
                'message' => 'La sesión tiene reservas vivas: cancélala en lugar de borrarla, para que quede el rastro.',
            ], 422);
        }

        $session->delete();

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    protected function serialize(ExperienceSession $session): array
    {
        return [
            'id' => $session->id,
            'experience_id' => $session->experience_id,
            'starts_at' => $session->starts_at->toIso8601String(),
            'capacity' => $session->capacity,
            'people_booked' => $session->peopleBooked(),
            'remaining' => $session->remainingSpots(),
            'status' => $session->status,
        ];
    }
}
