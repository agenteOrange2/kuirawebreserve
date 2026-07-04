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
        $expired = Reservation::query()
            ->where('status', ReservationStatus::Pending)
            ->where('hold_expires_at', '<=', now())
            ->update(['status' => ReservationStatus::Cancelled]);

        $this->info("Holds vencidos cancelados: {$expired}");

        return self::SUCCESS;
    }
}
