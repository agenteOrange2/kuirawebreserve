<?php

namespace App\Actions\Reservations;

use App\Exceptions\NoAvailabilityException;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Alarga una estancia en curso: mueve su salida prevista y recalcula lo que
 * se cobra por el hospedaje.
 *
 * Sin esto, "una noche más" obligaba a registrar la salida y volver a dar
 * entrada al huésped, partiendo su historial en dos y perdiendo el folio.
 *
 * Lo ya pagado no se toca: al subir el monto, Stay::folio() deja la
 * diferencia como saldo pendiente y se cobra al registrar la salida.
 */
class ExtendStay
{
    public function __construct(protected AvailabilityService $availability) {}

    /**
     * @throws NoAvailabilityException
     */
    public function handle(Stay $stay, DateTimeInterface $newEnd, ?User $user = null): Stay
    {
        return DB::transaction(function () use ($stay, $newEnd, $user) {
            /** @var Stay $stay */
            $stay = Stay::query()->whereKey($stay->getKey())->lockForUpdate()->firstOrFail();

            if ($stay->status !== Stay::STATUS_ACTIVE) {
                throw new InvalidArgumentException('Solo se puede extender una estancia en curso.');
            }

            $end = Carbon::parse($newEnd);
            $currentEnd = $stay->planned_end_at;

            if (! $end->isAfter($currentEnd)) {
                throw new InvalidArgumentException(
                    'La nueva salida debe ser posterior a la actual ('.$currentEnd->format('d/m/Y H:i').').',
                );
            }

            /** @var Room $room */
            $room = Room::query()->whereKey($stay->room_id)->lockForUpdate()->firstOrFail();

            // Solo se valida el tramo NUEVO: lo que ya ocupa esta estancia es
            // suyo, y así no hace falta excluirse a sí misma del choque.
            if (! $this->availability->isRoomAvailable($room, $currentEnd, $end)) {
                throw NoAvailabilityException::forRoom($room->number);
            }

            $ratePlan = $stay->ratePlan;

            if ($ratePlan === null) {
                throw new InvalidArgumentException('La estancia no tiene tarifa; no se puede recalcular el hospedaje.');
            }

            // Los cargos opcionales elegidos se conservan; los de personas
            // extra se recalculan porque dependen de cuántas unidades dura.
            $selectedConcepts = collect($stay->extra_charges ?? [])
                ->where('kind', 'optional')
                ->pluck('concept')
                ->all();

            $extraCharges = $room->extraChargeLines(
                max(1, (int) $stay->num_people),
                $ratePlan->unitsFor($stay->check_in_at, $end),
                $selectedConcepts,
            );

            $stay->update([
                'planned_end_at' => $end,
                'amount' => round(
                    $ratePlan->priceFor($stay->check_in_at, $end, $room)
                    + array_sum(array_column($extraCharges, 'amount')),
                    2,
                ),
                'extra_charges' => $extraCharges ?: null,
            ]);

            // La reserva de origen se mueve con la estancia: si no, el rack y
            // la disponibilidad seguirían creyendo que el cuarto se libera a
            // la hora vieja.
            $stay->reservation?->update(['ends_at' => $end]);

            activity('stay')
                ->performedOn($stay)
                ->causedBy($user)
                ->withProperties([
                    'from' => $currentEnd->toIso8601String(),
                    'to' => $end->toIso8601String(),
                ])
                ->log('Estancia extendida');

            return $stay->refresh();
        });
    }
}
