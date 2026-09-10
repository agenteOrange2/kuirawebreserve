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
 * Los relojes del semáforo, que sin ellos dejaba habitaciones "reservada"
 * para siempre cuando el hotel no registra check-ins:
 *
 * 1. Cierre de día: reservas confirmadas cuya salida ya pasó (+ gracia)
 *    sin check-in. Según /ajustes/limpieza se asume ocupada (reserva
 *    completada, habitación a sucia), se asume no-show (habitación libre)
 *    o se deja para gestión manual.
 * 2. Ventana de llegada: llegadas que no aparecieron pasadas N horas de la
 *    hora de entrada. Opcional y apagada por default; sin ella, una reserva
 *    de tres noches que nadie ocupó aparta el cuarto las tres.
 * 3. Reparación del semáforo: habitaciones apartadas que ya ninguna reserva
 *    justifica. El cierre de día itera reservas, así que jamás volvía a
 *    mirar un cuarto cuya reserva ya se cerró: eso congeló hoteles enteros
 *    durante días.
 * 4. Limpieza automática: sucia → en limpieza → disponible por tiempo,
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
        // Antes del barrido: al marcar el no-show, cancel() ya suelta el
        // semáforo por la vía normal y el barrido solo queda de red.
        $this->markNoShows($policy, $transition);
        // Siempre, no solo en modo automático: un semáforo apartado que ya
        // nadie justifica no es limpieza, es un cuarto invendible.
        $this->releaseOrphanReserved($changeRoomStatus);

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
                    // Queda como completada, que es lo que el hotel eligió
                    // asumir, pero con el motivo escrito: nadie registró la
                    // llegada. Sin esta línea, los reportes de ocupación no
                    // distinguen al que se hospedó del que nunca apareció.
                    $reservation->update([
                        'status' => ReservationStatus::Completed,
                        'hold_expires_at' => null,
                        'cancellation_reason' => $reservation->cancellation_reason
                            ?: 'Cierre de día automático: se asumió ocupada, sin registro de llegada.',
                    ]);

                    $room = $reservation->room_id
                        ? Room::whereKey($reservation->room_id)->lockForUpdate()->first()
                        : null;

                    // Solo si el semáforo sigue apartado por ESTA reserva:
                    // con otra reserva apartándola hoy (una llegada ya
                    // marcada), la habitación es de esa reserva, no se toca.
                    // Ojo: se mide con holdingReservation() y no con la
                    // próxima reserva del cuarto — preguntar "¿tiene alguna
                    // reserva futura?" es lo que dejaba el semáforo pegado
                    // para siempre en un hotel con agenda cargada.
                    if ($room
                        && $room->status->getMorphClass() === RoomStatus::Reserved->value
                        && ! $room->heldByReservation()) {
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

    /**
     * Llegadas que nunca aparecieron.
     *
     * El cierre de día mira la SALIDA, así que una reserva de tres noches
     * que nadie ocupó mantenía el cuarto apartado las tres noches: el
     * mostrador veía "reservada" para un huésped que no iba a llegar y no
     * podía venderlo. Esto mira la ENTRADA, pasada la ventana que el hotel
     * configuró en /ajustes/limpieza.
     *
     * Apagado por default: prenderlo cambia la operación y eso lo decide
     * cada hotel, no un deploy.
     *
     * Se marca no-show en vez de solo soltar el semáforo porque soltarlo a
     * secas deja un cuarto verde INVENDIBLE: la reserva confirmada sigue
     * apartando esas fechas en el motor de disponibilidad, así que el
     * walk-in reventaría igual. cancel() suelta las dos cosas —semáforo y
     * cupo— y avisa a la lista de espera.
     */
    protected function markNoShows(HousekeepingPolicy $policy, TransitionReservation $transition): void
    {
        $minutes = $policy->noShowAfterMinutes();

        if ($minutes < 1) {
            return;
        }

        $lost = Reservation::query()
            ->arrivalWindowClosed($minutes)
            // Sin estancia: si alguien registró la llegada, esto no aplica
            // aunque la reserva siga marcada como confirmada.
            ->whereDoesntHave('stay')
            ->get();

        $closed = 0;

        foreach ($lost as $reservation) {
            try {
                $transition->cancel(
                    $reservation,
                    null,
                    ReservationStatus::NoShow,
                    'No llegó dentro de la ventana de llegada configurada por el hotel.',
                );
                $closed++;
            } catch (Throwable $e) {
                $this->warn("Reserva {$reservation->displayCode()}: {$e->getMessage()}");
                report($e);
            }
        }

        if ($closed > 0) {
            $this->info("Llegadas dadas por perdidas: {$closed}.");
        }
    }

    /**
     * Semáforos apartados que ya nadie justifica.
     *
     * Nacen de dos formas. La común: la reserva que encendió el semáforo
     * terminó (completada, no-show, cancelada) y closeDay(), que itera
     * RESERVAS, nunca vuelve a mirar ese cuarto — así se congelaron hoteles
     * enteros durante días, con el plano entero en "reservada" y sin poder
     * vender nada. La otra: cualquier carrera perdida entre el cron que
     * enciende y el mostrador.
     *
     * Dos cuartos NO se tocan: el que una reserva aparta ahora mismo, y
     * aquel cuya salida venció y espera el cierre de día manual
     * (day_close_no_checkin = none), donde el semáforo no miente: aguarda
     * una decisión humana.
     *
     * Cae a DISPONIBLE y nunca a sucia, por dos razones. ChangeRoomStatus
     * cuenta un uso en reservada → sucia y puede disparar el candado por
     * usos, o sea que "reparar" dejaría el cuarto fuera de disponibilidad; y
     * si alguien hubiera entrado, el cuarto habría pasado por ocupada →
     * sucia y no estaría aquí. Asumir uso sin un solo dato que lo respalde
     * es inventar operación.
     */
    protected function releaseOrphanReserved(ChangeRoomStatus $changeRoomStatus): int
    {
        $rooms = Room::query()
            ->where('status', RoomStatus::Reserved->value)
            ->with('latestStatusLog')
            ->get();

        $released = 0;

        foreach ($rooms as $room) {
            if ($room->heldByReservation() || $room->hasReservationAwaitingDayClose()) {
                continue;
            }

            try {
                $changeRoomStatus->handle($room, RoomStatus::Available->value, null, [
                    'auto' => true,
                    'repair' => 'orphan_reserved',
                    // Qué reserva lo había encendido: es lo único que va a
                    // leer quien audite el movimiento meses después.
                    'reservation_id' => $room->latestStatusLog?->context['reservation_id'] ?? null,
                ]);
                $released++;
            } catch (Throwable $e) {
                $this->warn("Habitación {$room->number}: {$e->getMessage()}");
                report($e);
            }
        }

        if ($released > 0) {
            $this->info("Semáforos apartados sin reserva que los respalde, liberados: {$released}.");
        }

        return $released;
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
