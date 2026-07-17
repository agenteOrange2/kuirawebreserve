<?php

namespace App\Actions\Experiences;

use App\Exceptions\NoAvailabilityException;
use App\Models\ExperienceBooking;
use App\Models\ExperienceSession;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reserva una experiencia (spec-reservas-avanzado §3.3): cupo DURO bajo
 * lock pesimista — misma filosofía anti-doble-venta que las habitaciones.
 * El total se congela al reservar según el pricing_mode de la
 * experiencia; el precio nunca viene del cliente.
 */
class CreateExperienceBooking
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws NoAvailabilityException
     */
    public function handle(array $data, ?User $user = null): ExperienceBooking
    {
        return DB::transaction(function () use ($data, $user) {
            $session = ExperienceSession::query()
                ->whereKey($data['experience_session_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $session->isBookable()) {
                throw new InvalidArgumentException('Esa sesión ya no admite reservas (cancelada o pasada).');
            }

            $experience = $session->experience;
            $people = max(1, (int) ($data['people'] ?? 1));

            if ($people < $experience->min_people) {
                throw new InvalidArgumentException("Esta experiencia requiere mínimo {$experience->min_people} persona(s) por reserva.");
            }

            if ($experience->max_people !== null && $people > $experience->max_people) {
                throw new InvalidArgumentException("Esta experiencia admite máximo {$experience->max_people} persona(s) por reserva.");
            }

            if ($people > $session->remainingSpots()) {
                throw NoAvailabilityException::forExperienceSession($session->remainingSpots());
            }

            $guest = $this->resolveGuest($data);

            $booking = ExperienceBooking::create([
                'experience_session_id' => $session->id,
                'guest_id' => $guest?->id,
                'reservation_id' => $data['reservation_id'] ?? null,
                'reservation_group_id' => $data['reservation_group_id'] ?? null,
                'guest_name' => $data['guest_name'] ?? $guest?->full_name,
                'people' => $people,
                'total' => $experience->totalFor($people),
                'status' => ($data['confirmed'] ?? false) ? ExperienceBooking::STATUS_CONFIRMED : ExperienceBooking::STATUS_PENDING,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user?->id,
            ]);

            $booking->forceFill([
                'code' => ExperienceBooking::formatCode($booking->id, $booking->created_at),
            ])->saveQuietly();

            return $booking;
        });
    }

    /**
     * Mismo criterio que CreateReservation: por id, o encontrado/creado
     * por teléfono/email — las experiencias alimentan el mismo CRM.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveGuest(array $data): ?Guest
    {
        if (! empty($data['guest_id'])) {
            return Guest::findOrFail($data['guest_id']);
        }

        $phone = $data['guest_phone'] ?? null;
        $email = $data['guest_email'] ?? null;

        if (! $phone && ! $email) {
            return null;
        }

        return Guest::firstOrCreate(
            $phone ? ['phone' => $phone] : ['email' => $email],
            ['first_name' => $data['guest_name'] ?? null, 'email' => $email, 'phone' => $phone],
        );
    }
}
