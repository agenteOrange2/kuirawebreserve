<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Reservations\CreateGroupReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Reservas grupales desde el panel (módulo `grupos`): crear el grupo
 * (todo-o-nada) y cancelarlo completo. Las reservas individuales se
 * siguen operando una por una en /reservas, como siempre.
 */
class GroupReservationController extends Controller
{
    public function store(Request $request, CreateGroupReservation $action): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['night', 'block'])],
            'starts_at' => ['required', 'date', 'after_or_equal:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'confirmed' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:10'],
            'lines.*.room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'lines.*.rooms' => ['required', 'integer', 'min:1', 'max:30'],
            'lines.*.adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'lines.*.children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            // Experiencias como plus del grupo (módulo `experiencias`).
            'experiences' => ['sometimes', 'array', 'max:5'],
            'experiences.*.session_id' => ['required_with:experiences', 'integer', 'exists:experience_sessions,id'],
            'experiences.*.people' => ['required_with:experiences', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $group = $action->handle($data, $request->user());
        } catch (NoAvailabilityException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(self::serialize($group->fresh()->load('reservations.roomType', 'reservations.room', 'experienceBookings.session.experience')), 201);
    }

    /**
     * Cancela el grupo completo: cada reserva viva pasa por la transición
     * normal (libera habitación y deja rastro); las que ya avanzaron
     * (check-in) no se tocan.
     */
    public function cancel(Request $request, ReservationGroup $group, TransitionReservation $transition): JsonResponse
    {
        $cancelled = 0;

        foreach ($group->reservations as $reservation) {
            if (in_array($reservation->status, [ReservationStatus::Pending, ReservationStatus::Confirmed], true)) {
                $transition->cancel($reservation, $request->user(), reason: "Cancelación del grupo {$group->displayCode()}.");
                $cancelled++;
            }
        }

        // Las experiencias colgadas del grupo sueltan su cupo con él.
        $group->liveExperienceBookings()
            ->update(['status' => \App\Models\ExperienceBooking::STATUS_CANCELLED, 'updated_at' => now()]);

        return response()->json([
            ...self::serialize($group->fresh()->load('reservations.roomType', 'reservations.room', 'experienceBookings.session.experience')),
            'cancelled' => $cancelled,
        ]);
    }

    /** @return array<string, mixed> */
    public static function serialize(ReservationGroup $group): array
    {
        $reservations = $group->reservations;
        $experienceBookings = $group->relationLoaded('experienceBookings')
            ? $group->experienceBookings
            : $group->experienceBookings()->with('session.experience')->get();
        $liveExperiences = $experienceBookings->whereIn('status', [
            \App\Models\ExperienceBooking::STATUS_PENDING,
            \App\Models\ExperienceBooking::STATUS_CONFIRMED,
        ]);

        return [
            'id' => $group->id,
            'code' => $group->displayCode(),
            'guest_name' => $group->guest_name,
            'notes' => $group->notes,
            'rooms' => $reservations->count(),
            'experiences' => $experienceBookings->map(fn (\App\Models\ExperienceBooking $booking) => [
                'id' => $booking->id,
                'code' => $booking->displayCode(),
                'name' => $booking->session?->experience?->name,
                'starts_at' => $booking->session?->starts_at?->toIso8601String(),
                'people' => $booking->people,
                'total' => (float) $booking->total,
                'status' => $booking->status,
                'status_label' => $booking->statusLabel(),
            ])->values(),
            'total' => round((float) $reservations->sum('total_amount') + (float) $liveExperiences->sum('total'), 2),
            'starts_at' => $reservations->min('starts_at')?->toIso8601String(),
            'ends_at' => $reservations->max('ends_at')?->toIso8601String(),
            'created_at' => $group->created_at->toIso8601String(),
            'reservations' => $reservations->map(fn (Reservation $reservation) => [
                'id' => $reservation->id,
                'code' => $reservation->displayCode(),
                'room_type' => $reservation->roomType?->name,
                'room' => $reservation->room?->number,
                'adults' => (int) $reservation->adults,
                'children' => (int) $reservation->children,
                'total' => (float) $reservation->total_amount,
                'status' => $reservation->status->value,
                'status_label' => $reservation->status->label(),
            ])->values(),
        ];
    }
}
