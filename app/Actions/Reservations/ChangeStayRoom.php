<?php

namespace App\Actions\Reservations;

use App\Actions\Rooms\ChangeRoomStatus;
use App\Enums\RoomStatus;
use App\Exceptions\NoAvailabilityException;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cambia de habitación a un huésped que YA está adentro (aire descompuesto,
 * ruido, una queja). Sin esto había que registrar su salida y volver a darle
 * entrada, perdiendo el folio y partiendo su historial.
 *
 * El cuarto que deja pasa a sucia — se usó, aunque haya sido diez minutos.
 *
 * Por defecto NO se recalcula el precio: mover a alguien suele ser cortesía
 * del hotel y cobrarle la diferencia sería una sorpresa. Con `recalculate`
 * se aplica la tarifa con el modificador y los cargos del cuarto nuevo.
 */
class ChangeStayRoom
{
    public function __construct(
        protected AvailabilityService $availability,
        protected ChangeRoomStatus $changeRoomStatus,
    ) {}

    /**
     * @throws NoAvailabilityException
     */
    public function handle(Stay $stay, Room $target, ?User $user = null, bool $recalculate = false): Stay
    {
        return DB::transaction(function () use ($stay, $target, $user, $recalculate) {
            /** @var Stay $stay */
            $stay = Stay::query()->whereKey($stay->getKey())->lockForUpdate()->firstOrFail();

            if ($stay->status !== Stay::STATUS_ACTIVE) {
                throw new InvalidArgumentException('Solo se puede mover una estancia en curso.');
            }

            if ((int) $stay->room_id === (int) $target->getKey()) {
                throw new InvalidArgumentException('El huésped ya está en esa habitación.');
            }

            /** @var Room $origin */
            $origin = Room::query()->whereKey($stay->room_id)->lockForUpdate()->firstOrFail();
            /** @var Room $destination */
            $destination = Room::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $ratePlan = $stay->ratePlan;

            // La tarifa pertenece a un tipo de habitación: moverlo a otro tipo
            // dejaría el precio colgado de una tarifa que no le corresponde.
            if ($ratePlan !== null && (int) $destination->room_type_id !== (int) $ratePlan->room_type_id) {
                throw new InvalidArgumentException(
                    'La habitación destino es de otro tipo. Para cambiar de tipo hay que registrar la salida y una estancia nueva con su tarifa.',
                );
            }

            // Se valida lo que le queda de estancia, de ahora en adelante.
            if ($destination->status->getMorphClass() !== RoomStatus::Available->value
                || ! $this->availability->isRoomAvailable($destination, now(), $stay->planned_end_at)) {
                throw NoAvailabilityException::forRoom($destination->number);
            }

            $changes = ['room_id' => $destination->id];

            if ($recalculate && $ratePlan !== null) {
                $selectedConcepts = collect($stay->extra_charges ?? [])
                    ->where('kind', 'optional')
                    ->pluck('concept')
                    ->all();

                $extraCharges = $destination->extraChargeLines(
                    max(1, (int) $stay->num_people),
                    $ratePlan->unitsFor($stay->check_in_at, $stay->planned_end_at),
                    $selectedConcepts,
                    $ratePlan,
                );

                $gross = round(
                    $ratePlan->priceFor($stay->check_in_at, $stay->planned_end_at, $destination)
                    + array_sum(array_column($extraCharges, 'amount')),
                    2,
                );

                // Walk-in con cortesía aprobada: se vuelve a medir sobre el
                // hospedaje nuevo (con reserva, la cortesía vive en ella).
                $courtesy = $stay->reservation_id
                    ? 0.0
                    : app(\App\Services\Courtesies\CourtesyService::class)->recalculate($stay, $gross, $extraCharges);

                $changes['amount'] = round(max(0, $gross - $courtesy), 2);
                $changes['courtesy_amount'] = $courtesy;
                $changes['extra_charges'] = $extraCharges ?: null;
            }

            $previousAmount = (float) $stay->amount;
            $stay->update($changes);

            // La reserva de origen apunta al cuarto nuevo: si no, el rack y
            // el plano seguirían mostrando al huésped donde ya no está. Si se
            // recalculó el precio, la diferencia va a SU total: el folio de
            // una estancia con reserva cobra el de la reserva.
            if ($stay->reservation) {
                $reservationChanges = ['room_id' => $destination->id];

                if (array_key_exists('amount', $changes)) {
                    $reservationChanges['total_amount'] = max(0, round(
                        (float) $stay->reservation->total_amount + ((float) $stay->amount - $previousAmount),
                        2,
                    ));
                }

                $stay->reservation->update($reservationChanges);

                if (array_key_exists('total_amount', $reservationChanges)) {
                    $stay->reservation->syncPaymentStatus();
                }
            }

            $this->changeRoomStatus->handle($destination, RoomStatus::Occupied->value, $user, [
                'stay_id' => $stay->id,
                'moved_from' => $origin->number,
            ]);

            // El cuarto que deja queda sucio aunque el huésped haya estado
            // diez minutos: alguien tiene que entrar a revisarlo.
            $this->changeRoomStatus->handle($origin, RoomStatus::Dirty->value, $user, [
                'stay_id' => $stay->id,
                'moved_to' => $destination->number,
            ]);

            activity('stay')
                ->performedOn($stay)
                ->causedBy($user)
                ->withProperties([
                    'from_room' => $origin->number,
                    'to_room' => $destination->number,
                    'recalculated' => $recalculate,
                ])
                ->log('Cambio de habitación');

            return $stay->refresh();
        });
    }
}
