<?php

namespace App\Console\Commands;

use App\Actions\Rooms\ChangeRoomStatus;
use App\Enums\RoomStatus;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Throwable;

/**
 * Aparta en el semáforo las habitaciones cuya reserva confirmada (ya pagada
 * o validada) llega hoy. TransitionReservation::confirm ya lo hace cuando la
 * confirmación cae el mismo día de la llegada; este comando cubre el resto:
 * reservas pagadas con días de anticipación cuando les llega su fecha, y
 * habitaciones que housekeeping libera durante el día teniendo llegada
 * confirmada. Correr por tenant: tenants:run.
 */
class ReserveArrivalRooms extends Command
{
    protected $signature = 'rooms:reserve-arrivals';

    protected $description = 'Marca como reservada la habitación de cada reserva confirmada cuya llegada es hoy';

    public function handle(ChangeRoomStatus $changeRoomStatus): int
    {
        // Mismo predicado con el que rooms:advance-housekeeping suelta el
        // semáforo (Reservation::scopeHoldsRoomAt): si encender y apagar
        // usan definiciones distintas, el cuarto parpadea entre corridas o
        // se queda apartado para siempre.
        $arrivals = Reservation::query()
            ->holdsRoomAt()
            ->with('room')
            ->get();

        $reserved = 0;

        foreach ($arrivals as $reservation) {
            $room = $reservation->room;

            if (! $room || $room->status->getMorphClass() !== RoomStatus::Available->value) {
                continue;
            }

            try {
                $changeRoomStatus->handle($room, RoomStatus::Reserved->value, null, [
                    'reservation_id' => $reservation->id,
                    'auto' => true,
                ]);
                $reserved++;
            } catch (Throwable $e) {
                // Una habitación atorada no debe frenar al resto de llegadas.
                $this->warn("Reserva {$reservation->displayCode()} (hab. {$room->number}): {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Habitaciones apartadas por llegada confirmada: {$reserved} de {$arrivals->count()}.");

        return self::SUCCESS;
    }
}
