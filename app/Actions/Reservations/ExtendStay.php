<?php

namespace App\Actions\Reservations;

use App\Enums\RatePlanType;
use App\Exceptions\NoAvailabilityException;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\ReservationPolicy;
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
 *
 * Escalera de tarifas (ReservationPolicy::extensionRateLadder, opt-in por
 * hotel; Hotel México 2026-10-09): una estancia POR HORAS no se alarga
 * multiplicando su tarifa (1 hora extendida a 2 cobraba 2 × 1 hora), sino
 * pasando a la siguiente tarifa por horas de su tipo (1 → 2 → 3 h) y, cuando
 * ya no hay escalón, a la noche completa con la salida que marque el horario
 * del hotel. Se cobra la diferencia contra lo que ya traía.
 */
class ExtendStay
{
    public function __construct(
        protected AvailabilityService $availability,
        protected ReservationPolicy $policy,
    ) {}

    /**
     * @param  ?int  $ratePlanId  escalón elegido en el plano (solo escalera)
     *
     * @throws NoAvailabilityException
     */
    public function handle(Stay $stay, ?DateTimeInterface $newEnd, ?User $user = null, ?int $ratePlanId = null): Stay
    {
        return DB::transaction(function () use ($stay, $newEnd, $user, $ratePlanId) {
            /** @var Stay $stay */
            $stay = Stay::query()->whereKey($stay->getKey())->lockForUpdate()->firstOrFail();

            if ($stay->status !== Stay::STATUS_ACTIVE) {
                throw new InvalidArgumentException('Solo se puede extender una estancia en curso.');
            }

            $currentEnd = $stay->planned_end_at;
            $ratePlan = $stay->ratePlan;

            if ($ratePlan === null) {
                throw new InvalidArgumentException('La estancia no tiene tarifa; no se puede recalcular el hospedaje.');
            }

            /** @var Room $room */
            $room = Room::query()->whereKey($stay->room_id)->lockForUpdate()->firstOrFail();

            [$newPlan, $end] = $this->usesLadder($ratePlan)
                ? $this->ladderStep($stay, $ratePlan, $newEnd, $ratePlanId)
                : [$ratePlan, Carbon::parse($newEnd ?? throw new InvalidArgumentException('Indica la nueva hora de salida.'))];

            if (! $end->isAfter($currentEnd)) {
                throw new InvalidArgumentException(
                    'La nueva salida debe ser posterior a la actual ('.$currentEnd->format('d/m/Y H:i').').',
                );
            }

            // Pasar a otra tarifa puede cambiar cuánta gente cabe (la
            // Sencilla por horas admite 3; su noche, 2).
            $capacity = $room->effectiveMaxOccupancy($newPlan);
            if ($newPlan->id !== $ratePlan->id && $capacity !== null && (int) $stay->num_people > $capacity) {
                throw new InvalidArgumentException(sprintf(
                    '%s admite máximo %d %s y esta estancia trae %d.',
                    $newPlan->name,
                    $capacity,
                    $capacity === 1 ? 'persona' : 'personas',
                    (int) $stay->num_people,
                ));
            }

            // Solo se valida el tramo NUEVO: lo que ya ocupa esta estancia es
            // suyo, y así no hace falta excluirse a sí misma del choque.
            if (! $this->availability->isRoomAvailable($room, $currentEnd, $end)) {
                throw NoAvailabilityException::forRoom($room->number);
            }

            // Los cargos opcionales elegidos se conservan; los de personas
            // extra se recalculan porque dependen de cuántas unidades dura
            // y de la tarifa (cada una puede incluir distinta gente).
            $selectedConcepts = collect($stay->extra_charges ?? [])
                ->where('kind', 'optional')
                ->pluck('concept')
                ->all();

            $extraCharges = $room->extraChargeLines(
                max(1, (int) $stay->num_people),
                $newPlan->unitsFor($stay->check_in_at, $end),
                $selectedConcepts,
                $newPlan,
            );

            $gross = round(
                $newPlan->priceFor($stay->check_in_at, $end, $room)
                + array_sum(array_column($extraCharges, 'amount')),
                2,
            );

            // Walk-in con cortesía aprobada: gratis o porcentaje siguen al
            // hospedaje nuevo; un monto fijo se queda (con reserva, la
            // cortesía vive en ella).
            $courtesy = $stay->reservation_id
                ? 0.0
                : app(\App\Services\Courtesies\CourtesyService::class)->recalculate($stay, $gross, $extraCharges);

            $previousAmount = (float) $stay->amount;

            $stay->update([
                'planned_end_at' => $end,
                'rate_plan_id' => $newPlan->id,
                'amount' => round(max(0, $gross - $courtesy), 2),
                'courtesy_amount' => $courtesy,
                'extra_charges' => $extraCharges ?: null,
            ]);

            // La reserva de origen se mueve con la estancia: si no, el rack y
            // la disponibilidad seguirían creyendo que el cuarto se libera a
            // la hora vieja.
            if ($stay->reservation) {
                // El folio de una estancia con reserva cobra el total DE LA
                // RESERVA: lo extendido tiene que sumarse ahí. Antes solo
                // subía el monto de la estancia y la extensión salía gratis
                // (todos los hoteles; visto en Hotel México 2026-10-09).
                $stay->reservation->update([
                    'ends_at' => $end,
                    'rate_plan_id' => $newPlan->id,
                    'total_amount' => max(0, round(
                        (float) $stay->reservation->total_amount + ((float) $stay->amount - $previousAmount),
                        2,
                    )),
                ]);
                $stay->reservation->syncPaymentStatus();
            }

            activity('stay')
                ->performedOn($stay)
                ->causedBy($user)
                ->withProperties([
                    'from' => $currentEnd->toIso8601String(),
                    'to' => $end->toIso8601String(),
                    'rate_plan_from' => $ratePlan->id,
                    'rate_plan_to' => $newPlan->id,
                ])
                ->log($newPlan->id === $ratePlan->id
                    ? 'Estancia extendida'
                    : sprintf('Estancia extendida: %s → %s', $ratePlan->name, $newPlan->name));

            return $stay->refresh();
        });
    }

    /**
     * Escalones disponibles para extender esta estancia, del más corto al
     * más largo, terminando en la noche. Vacío si la escalera no aplica
     * (hotel sin el ajuste, o estancia que no es por horas).
     *
     * @return array<int, array{rate_plan_id: int, name: string, kind: string, ends_at: string, price: float, amount: float, difference: float}>
     */
    public function ladderOptions(Stay $stay): array
    {
        $ratePlan = $stay->ratePlan;

        if ($ratePlan === null || ! $this->usesLadder($ratePlan)) {
            return [];
        }

        $options = $this->rawLadderOptions($stay, $ratePlan);
        $room = $stay->room;

        // Cuánto quedaría el hospedaje y la diferencia contra lo que ya
        // trae: es lo que el mostrador le dice al huésped antes de extender.
        return array_map(function (array $option) use ($stay, $room) {
            $plan = RatePlan::find($option['rate_plan_id']);
            $end = Carbon::parse($option['ends_at']);
            $extras = $room?->extraChargeLines(max(1, (int) $stay->num_people), $plan->unitsFor($stay->check_in_at, $end), [], $plan) ?? [];
            $amount = round($plan->priceFor($stay->check_in_at, $end, $room) + array_sum(array_column($extras, 'amount')), 2);
            $capacity = $room?->effectiveMaxOccupancy($plan);

            return [
                ...$option,
                'amount' => $amount,
                'difference' => round($amount - (float) $stay->amount, 2),
                'fits' => $capacity === null || (int) $stay->num_people <= $capacity,
                'capacity' => $capacity,
            ];
        }, $options);
    }

    /** @return array<int, array{rate_plan_id: int, name: string, kind: string, ends_at: string, price: float}> */
    protected function rawLadderOptions(Stay $stay, RatePlan $ratePlan): array
    {
        $current = $ratePlan->blockMinutes();
        $options = [];

        foreach ($this->hourlyPlans($ratePlan) as $plan) {
            if ($plan->blockMinutes() <= $current) {
                continue;
            }

            $end = $stay->check_in_at->copy()->addMinutes($plan->blockMinutes());

            // Un escalón que ya quedó atrás (llegó a las 4, son las 6:30 y
            // pide "2 horas") no se ofrece: la salida estaría en el pasado.
            if ($end->isPast()) {
                continue;
            }

            $options[] = ['rate_plan_id' => $plan->id, 'name' => $plan->name, 'kind' => 'block', 'ends_at' => $end->toIso8601String(), 'price' => (float) $plan->price];
        }

        if ($night = $this->nightPlan($ratePlan)) {
            $options[] = [
                'rate_plan_id' => $night->id,
                'name' => $night->name,
                'kind' => 'night',
                'ends_at' => $this->nightEnd($stay, $night)->toIso8601String(),
                'price' => (float) $night->price,
            ];
        }

        return $options;
    }

    protected function usesLadder(RatePlan $ratePlan): bool
    {
        return $this->policy->extensionRateLadder() && $ratePlan->blockMinutes() !== null;
    }

    /**
     * @return array{0: RatePlan, 1: Carbon}
     */
    protected function ladderStep(Stay $stay, RatePlan $current, ?DateTimeInterface $newEnd, ?int $ratePlanId): array
    {
        $hourly = $this->hourlyPlans($current);
        $night = $this->nightPlan($current);

        // El plano manda el escalón elegido.
        if ($ratePlanId !== null) {
            if ($night !== null && $ratePlanId === $night->id) {
                return [$night, $this->nightEnd($stay, $night, $newEnd)];
            }

            $plan = $hourly->firstWhere('id', $ratePlanId);

            if ($plan === null || $plan->blockMinutes() <= $current->blockMinutes()) {
                throw new InvalidArgumentException('Esa tarifa no es un escalón para extender esta estancia.');
            }

            $end = $stay->check_in_at->copy()->addMinutes($plan->blockMinutes());

            if ($end->isPast()) {
                throw new InvalidArgumentException(sprintf(
                    '%s ya se cumplió (salía a las %s): elige un escalón más largo o la noche.',
                    $plan->name,
                    $end->format('H:i'),
                ));
            }

            return [$plan, $end];
        }

        // Solo la hora: el escalón más corto que la cubra, o la noche.
        $end = Carbon::parse($newEnd ?? throw new InvalidArgumentException('Indica hasta cuándo se queda.'));
        $minutes = $stay->check_in_at->diffInMinutes($end);

        $plan = $hourly->first(fn (RatePlan $p) => $p->blockMinutes() > $current->blockMinutes() && $p->blockMinutes() >= $minutes);

        if ($plan !== null) {
            return [$plan, $stay->check_in_at->copy()->addMinutes($plan->blockMinutes())];
        }

        if ($night === null) {
            throw new InvalidArgumentException('Este tipo de habitación no tiene tarifa de noche para pasar a ella.');
        }

        return [$night, $this->nightEnd($stay, $night, $end)];
    }

    /**
     * Salida de la noche: la que marca el horario del hotel desde la hora
     * de llegada (con su corte de madrugada); si pidieron más, lo que
     * pidieron.
     */
    protected function nightEnd(Stay $stay, RatePlan $night, ?DateTimeInterface $requested = null): Carbon
    {
        $end = Carbon::parse($night->suggestedEnd($stay->check_in_at->copy()));

        if ($requested !== null && Carbon::parse($requested)->isAfter($end)) {
            return Carbon::parse($requested);
        }

        return $end;
    }

    /** @return \Illuminate\Support\Collection<int, RatePlan> */
    protected function hourlyPlans(RatePlan $current): \Illuminate\Support\Collection
    {
        return RatePlan::query()
            ->where('room_type_id', $current->room_type_id)
            ->where('active', true)
            ->where('type', '!=', RatePlanType::Night->value)
            ->get()
            ->filter(fn (RatePlan $plan) => $plan->blockMinutes() !== null)
            ->sortBy(fn (RatePlan $plan) => $plan->blockMinutes())
            ->values();
    }

    protected function nightPlan(RatePlan $current): ?RatePlan
    {
        return RatePlan::query()
            ->where('room_type_id', $current->room_type_id)
            ->where('active', true)
            ->where('type', RatePlanType::Night->value)
            ->orderBy('price')
            ->first();
    }
}
