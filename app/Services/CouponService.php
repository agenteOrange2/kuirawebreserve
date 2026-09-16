<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Guest;
use App\Models\Reservation;
use Carbon\CarbonInterface;
use Spatie\Activitylog\Models\Activity;

/**
 * Todo lo que se hace con un cupón fuera del modelo: encontrarlo por lo que
 * escribió el huésped, juzgarlo contra una reserva concreta y llevar la
 * cuenta de los canjes.
 *
 * Vivía repartido en cuatro lugares (CreateReservation, BookingCouponController,
 * AgentToolsController y TransitionReservation) y se habían separado: el bot
 * aceptaba "pache pache" y el wizard del sitio lo rechazaba por el espacio,
 * porque buscaba el código exacto. Caso real cabañas 2026-09-12: PACHEPACHE
 * (30%) se anunció en un video y quien lo escribió con espacio pagó completo.
 */
class CouponService
{
    /**
     * El cupón que el huésped quiso escribir: se compara por la llave del
     * código (solo letras y números, en mayúsculas), así que "pache pache",
     * "Pache-Pache" y "PACHEPACHE" llegan al mismo cupón.
     */
    public function find(?string $code): ?Coupon
    {
        $wanted = Coupon::keyOf($code);

        if ($wanted === '') {
            return null;
        }

        return Coupon::query()
            ->get()
            ->first(fn (Coupon $coupon) => Coupon::keyOf($coupon->code) === $wanted);
    }

    /** ¿El hotel tiene contratado el módulo de cupones? */
    public function enabled(): bool
    {
        $tenant = tenant();

        // Sin contexto de tenant (tests, comandos centrales) aplica.
        return ! $tenant instanceof \App\Models\Tenant || $tenant->hasModule('cupones');
    }

    /**
     * El cupón listo para aplicarse a ESTA estancia, o una excepción con el
     * motivo exacto en texto para el huésped. Devuelve null cuando no se
     * mandó código.
     *
     * @throws \InvalidArgumentException
     */
    public function resolve(
        ?string $code,
        ?Guest $guest = null,
        ?CarbonInterface $start = null,
        ?int $nights = null,
        ?int $roomTypeId = null,
        ?CarbonInterface $end = null,
    ): ?Coupon {
        if (Coupon::keyOf($code) === '') {
            return null;
        }

        // Módulo cupones (Empresarial): sin él no se aceptan códigos por
        // ningún canal.
        if (! $this->enabled()) {
            throw new \InvalidArgumentException('Los cupones de descuento no están incluidos en el plan de este hotel.');
        }

        $coupon = $this->find($code);

        if (! $coupon || ! $coupon->isRedeemable()) {
            throw new \InvalidArgumentException('Ese código de descuento no es válido o ya no está disponible.');
        }

        // Condiciones del cupón (estancia larga, tipo, frecuente,
        // cumpleaños): el motivo exacto viaja al huésped, nunca se cobra
        // el total completo en silencio.
        $reason = $coupon->rejectionReason($guest, $start, $nights, $roomTypeId, $end);

        if ($reason !== null) {
            throw new \InvalidArgumentException($reason);
        }

        return $coupon;
    }

    /**
     * ¿El cupón de esta reserva ya se contó como usado? Una reserva reabierta
     * puede salir de Pendiente por segunda vez y no debe gastar dos usos.
     */
    public function wasRedeemed(Reservation $reservation): bool
    {
        return $reservation->coupon_code !== null
            && $this->redeemBalance($reservation, $reservation->coupon_code) > 0;
    }

    /**
     * Canjes menos devoluciones de un código en esta reserva. Se lleva por
     * bitácora y no por una bandera porque el mismo cupón puede quitarse y
     * volverse a poner a mano: con una bandera, la segunda vuelta no
     * volvería a contar el uso.
     */
    protected function redeemBalance(Reservation $reservation, string $code): int
    {
        $entries = Activity::query()
            ->where('log_name', 'coupon')
            ->where('subject_type', $reservation->getMorphClass())
            ->where('subject_id', $reservation->id)
            ->where('properties->code', $code)
            // El "Cupón X aplicado" de la creación no cuenta: ahí todavía no
            // se gasta el uso (el hold puede expirar sin confirmarse).
            ->where(fn ($query) => $query
                ->where('description', 'like', '%canjeado%')
                ->orWhere('description', 'like', '%devuelto%'))
            ->pluck('description');

        return $entries->filter(fn (string $text) => str_contains($text, 'canjeado'))->count()
            - $entries->filter(fn (string $text) => str_contains($text, 'devuelto'))->count();
    }

    /**
     * Cuenta el uso del cupón de la reserva (al confirmarse, o al aplicarlo
     * a mano sobre una reserva que ya salió de Pendiente). Idempotente.
     */
    public function redeem(Reservation $reservation): void
    {
        if (! $reservation->coupon_code || $this->wasRedeemed($reservation)) {
            return;
        }

        Coupon::query()
            ->where('code', $reservation->coupon_code)
            ->increment('used_count');

        // Bitácora del canje: el increment de arriba es query builder (sin
        // eventos de modelo), así que sin esta línea el uso del cupón no
        // dejaría rastro de cuándo ni en qué reserva se consumió. El causer
        // lo resuelve spatie (usuario autenticado; null = huésped web).
        activity('coupon')
            ->performedOn($reservation)
            ->withProperties([
                'code' => $reservation->coupon_code,
                'discount' => (float) $reservation->discount_amount,
            ])
            ->log(sprintf('Cupón %s canjeado al confirmarse la reserva', $reservation->coupon_code));
    }

    /**
     * Devuelve el uso al cupón cuando se le quita a una reserva que ya lo
     * había canjeado. Sin esto, cambiar de cupón a mano gastaría usos de un
     * cupón que ya nadie trae.
     */
    public function release(Reservation $reservation): void
    {
        if (! $reservation->coupon_code || ! $this->wasRedeemed($reservation)) {
            return;
        }

        Coupon::query()
            ->where('code', $reservation->coupon_code)
            ->where('used_count', '>', 0)
            ->decrement('used_count');

        activity('coupon')
            ->performedOn($reservation)
            ->withProperties(['code' => $reservation->coupon_code])
            ->log(sprintf('Uso del cupón %s devuelto al retirarlo de la reserva', $reservation->coupon_code));
    }
}
