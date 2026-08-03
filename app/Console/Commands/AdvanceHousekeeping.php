<?php

namespace App\Console\Commands;

use App\Actions\Reservations\TransitionReservation;
use App\Actions\Rooms\ChangeRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\HousekeepingPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Dos relojes del semáforo que antes no existían (el hueco que dejaba
 * habitaciones "reservada" para siempre cuando el hotel no registra
 * check-ins):
 *
 * 1. Cierre de día: reservas confirmadas cuya salida ya pasó (+ gracia)
 *    sin check-in. Según /ajustes/limpieza se asume ocupada (reserva
 *    completada, habitación a sucia), se asume no-show (habitación libre)
 *    o se deja para gestión manual.
 * 2. Limpieza automática: sucia → en limpieza → disponible por tiempo,
 *    cuando el hotel eligió modo automático o ambos.
 *
 * Correr por tenant: tenants:run.
 */
class AdvanceHousekeeping extends Command
{
    protected $signature = 'rooms:advance-housekeeping';

    protected $description = 'Cierra el día de reservas vencidas sin check-in y avanza la limpieza automática';

    public function handle(ChangeRoomStatus $changeRoomStatus, TransitionReservation $transition): int
    {
        $policy = app(HousekeepingPolicy::class);

        $this->closeDay($policy, $changeRoomStatus, $transition);

        if ($policy->autoAdvances()) {
            $advanced = $this->advance(RoomStatus::Dirty, RoomStatus::Cleaning, $policy->dirtyMinutes(), $changeRoomStatus)
                + $this->advance(RoomStatus::Cleaning, RoomStatus::Available, $policy->cleaningMinutes(), $changeRoomStatus);

            $this->info("Limpieza automática: {$advanced} habitación(es) avanzada(s).");
        }

        return self::SUCCESS;
    }

    protected function closeDay(HousekeepingPolicy $policy, ChangeRoomStatus $changeRoomStatus, TransitionReservation $transition): void
    {
        $action = $policy->dayCloseAction();

        if ($action === HousekeepingPolicy::DAY_CLOSE_NONE) {
            return;
        }

        $overdue = Reservation::query()
            ->where('status', ReservationStatus::Confirmed)
            ->where('ends_at', '<=', now()->subMinutes($policy->dayCloseGraceMinutes()))
            ->get();

        $closed = 0;

        foreach ($overdue as $reservation) {
            try {
                if ($action === HousekeepingPolicy::DAY_CLOSE_AVAILABLE) {
                    // No llegó: no-show. cancel() también libera el semáforo
                    // y suelta el cupo de los tours ligados.
                    $transition->cancel(
                        $reservation,
                        null,
                        ReservationStatus::NoShow,
                        'Cierre de día automático: la salida pasó sin registro de llegada.',
                    );
                    $closed++;

                    continue;
                }

                // Se asume que se ocupó: la reserva se completa y la
                // habitación cae a sucia para housekeeping.
                DB::transaction(function () use ($reservation, $changeRoomStatus) {
                    $reservation->update([
                        'status' => ReservationStatus::Completed,
                        'hold_expires_at' => null,
                    ]);

                    $room = $reservation->room_id
                        ? Room::whereKey($reservation->room_id)->lockForUpdate()->first()
                        : null;

                    // Solo si el semáforo sigue apartado por ESTA reserva:
                    // con otra reserva viva encima (llegada de hoy ya
                    // apartada), la habitación es de esa reserva, no se toca.
                    if ($room
                        && $room->status->getMorphClass() === RoomStatus::Reserved->value
                        && ! $room->hasLiveReservation()) {
                        $changeRoomStatus->handle($room, RoomStatus::Dirty->value, null, [
                            'reservation_id' => $reservation->id,
                            'auto' => true,
                        ]);
                    }
                });
                $closed++;
            } catch (Throwable $e) {
                // Una reserva atorada no debe frenar el cierre de las demás.
                $this->warn("Reserva {$reservation->displayCode()}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Cierre de día: {$closed} de {$overdue->count()} reserva(s) vencida(s) cerradas.");
    }

    protected function advance(RoomStatus $from, RoomStatus $to, int $minutes, ChangeRoomStatus $changeRoomStatus): int
    {
        $rooms = Room::query()
            ->where('status', $from->value)
            ->with('latestStatusLog')
            ->get();

        $advanced = 0;

        foreach ($rooms as $room) {
            // Desde cuándo está en el estado actual: el último movimiento
            // del semáforo (o updated_at si nunca se ha registrado uno).
            $since = $room->latestStatusLog?->created_at ?? $room->updated_at;

            if ($since === null || $since->gt(now()->subMinutes($minutes))) {
                continue;
            }

            try {
                $changeRoomStatus->handle($room, $to->value, null, ['auto' => true]);
                $advanced++;
            } catch (Throwable $e) {
                $this->warn("Habitación {$room->number}: {$e->getMessage()}");
                report($e);
            }
        }

        return $advanced;
    }
}
