<?php

namespace App\Console\Commands;

use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Models\Reservation;
use App\Services\HousekeepingPolicy;
use Illuminate\Console\Command;
use Throwable;

/**
 * Check-in automático (/ajustes/limpieza, modos auto/ambos): cuando la
 * hora de llegada de una reserva confirmada pasa sin que el personal
 * registre el check-in, el sistema lo hace — crea la estancia y ocupa la
 * habitación, para que el resto de relojes (auto-checkout, limpieza)
 * lleven el ciclo completo sin tocar el panel (flujo motel/bot).
 *
 * Si la habitación sigue sucia/ocupada a la hora, se reintenta en cada
 * corrida hasta que se libere. Correr por tenant: tenants:run.
 */
class AutoCheckInArrivals extends Command
{
    protected $signature = 'reservations:auto-checkin';

    protected $description = 'Registra el check-in de reservas confirmadas cuya hora de llegada ya pasó';

    public function handle(TransitionReservation $transition): int
    {
        if (! app(HousekeepingPolicy::class)->autoCheckIn()) {
            $this->info('Check-in automático apagado (checkin_mode).');

            return self::SUCCESS;
        }

        $due = Reservation::query()
            ->where('status', ReservationStatus::Confirmed)
            ->whereNotNull('room_id')
            ->where('starts_at', '<=', now())
            // Con la salida ya vencida es asunto del cierre de día, no de
            // un check-in que nacería vencido.
            ->where('ends_at', '>', now())
            ->with('room')
            ->get();

        $checkedIn = 0;
        $waiting = 0;

        foreach ($due as $reservation) {
            $room = $reservation->room;
            $state = $room?->status->getMorphClass();

            // Habitación aún no lista (sucia, en limpieza, ocupada por otro,
            // mantenimiento): se espera sin alarmar — al liberarse cae solo.
            if (! in_array($state, [RoomStatus::Available->value, RoomStatus::Reserved->value], true)) {
                $waiting++;

                continue;
            }

            try {
                $transition->checkIn($reservation, null, ['auto' => true]);
                $checkedIn++;
            } catch (Throwable $e) {
                $this->warn("Reserva {$reservation->displayCode()} (hab. {$room?->number}): {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Check-in automático: {$checkedIn} registrada(s), {$waiting} esperando habitación.");

        return self::SUCCESS;
    }
}
