<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Console\Command;

/**
 * Higiene de holds vencidos: el motor de disponibilidad ya los ignora por
 * query (scope blocking), esto solo los marca cancelados para que el panel
 * no muestre pendientes muertos. Correr por tenant: tenants:run.
 */
class ExpireReservationHolds extends Command
{
    protected $signature = 'reservations:expire-holds';

    protected $description = 'Cancela reservas pendientes cuyo hold ya venció';

    public function handle(): int
    {
        $ids = Reservation::query()
            ->where('status', ReservationStatus::Pending)
            ->where('hold_expires_at', '<=', now())
            ->pluck('id');

        $expired = Reservation::query()
            ->whereIn('id', $ids)
            ->update(['status' => ReservationStatus::Cancelled]);

        // Los tours comprados como plus de esos holds liberan su cupo junto
        // con la habitación — mismos estados vivos que TransitionReservation.
        if ($ids->isNotEmpty()) {
            \App\Models\ExperienceBooking::query()
                ->whereIn('reservation_id', $ids)
                ->whereIn('status', [
                    \App\Models\ExperienceBooking::STATUS_PENDING,
                    \App\Models\ExperienceBooking::STATUS_CONFIRMED,
                ])
                ->update(['status' => \App\Models\ExperienceBooking::STATUS_CANCELLED, 'updated_at' => now()]);

            // Tours colgados de un GRP- cuyo hold murió completo: si al
            // grupo no le queda ninguna reserva viva, sus experiencias
            // también sueltan el cupo.
            $groupIds = Reservation::query()
                ->whereIn('id', $ids)
                ->whereNotNull('reservation_group_id')
                ->pluck('reservation_group_id')
                ->unique();

            $deadGroups = $groupIds->reject(fn ($groupId) => Reservation::query()
                ->where('reservation_group_id', $groupId)
                ->whereIn('status', [
                    ReservationStatus::Pending,
                    ReservationStatus::Confirmed,
                    ReservationStatus::CheckedIn,
                ])
                ->exists());

            if ($deadGroups->isNotEmpty()) {
                \App\Models\ExperienceBooking::query()
                    ->whereIn('reservation_group_id', $deadGroups)
                    ->whereIn('status', [
                        \App\Models\ExperienceBooking::STATUS_PENDING,
                        \App\Models\ExperienceBooking::STATUS_CONFIRMED,
                    ])
                    ->update(['status' => \App\Models\ExperienceBooking::STATUS_CANCELLED, 'updated_at' => now()]);
            }
        }

        $this->info("Holds vencidos cancelados: {$expired}");

        return self::SUCCESS;
    }
}
