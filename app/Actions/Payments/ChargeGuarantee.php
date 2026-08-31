<?php

namespace App\Actions\Payments;

use App\Models\Payment;
use App\Models\Stay;
use App\Models\User;
use App\Services\ReservationPolicy;
use InvalidArgumentException;

/**
 * Cobro de la fianza (depósito en garantía) al registrar la llegada. Un
 * solo punto para los tres mostradores que la cobran —check-in de reserva
 * desde la lista, check-in desde el plano y walk-in—, que antes repetían
 * el mismo bloque con el monto cableado al ajuste.
 *
 * Lo que aporta sobre aquel bloque:
 *
 *  - El monto sale del escalón que corresponde a la partida
 *    (ReservationPolicy::guaranteeAmountFor), no del monto base a secas.
 *  - El personal puede ajustarlo en el momento, pero SOLO con motivo: un
 *    depósito distinto al de la política sin explicación es justo lo que
 *    hace impagable una devolución tres días después.
 *
 * Sigue siendo un pasivo: kind `guarantee` la deja fuera del folio de
 * hospedaje y de los totales de venta del corte (ver CashCutService), y se
 * devuelve al registrar la salida salvo retención explícita por daños.
 */
class ChargeGuarantee
{
    public function __construct(protected ReservationPolicy $policy) {}

    /**
     * @param  string|null  $method  Efectivo o terminal; null = no se cobró
     *                               fianza (ajuste apagado, check-in
     *                               automático sin personal, o el hotel
     *                               decidió no pedirla en esta llegada).
     * @param  float|null  $override  Monto capturado a mano; null = el de
     *                                la política.
     * @param  string|null  $reason  Por qué se ajustó. Obligatorio cuando
     *                               el override difiere de la política.
     * @param  int  $rooms  Habitaciones de la misma partida, para
     *                      elegir el escalón.
     *
     * @throws InvalidArgumentException
     */
    public function handle(
        Stay $stay,
        ?string $method,
        ?User $user = null,
        ?float $override = null,
        ?string $reason = null,
        int $rooms = 1,
    ): ?Payment {
        if ($method === null || ! $this->policy->guaranteeEnabled()) {
            return null;
        }

        $expected = $this->policy->guaranteeAmountFor($rooms);
        $reason = trim((string) $reason);
        // Comparar en centavos: 1500.0 y 1500.00 son el mismo depósito y no
        // deben disparar la exigencia de motivo.
        $adjusted = $override !== null && (int) round($override * 100) !== (int) round($expected * 100);

        if ($adjusted && $reason === '') {
            throw new InvalidArgumentException(
                'Para cobrar una fianza distinta a la de la política indica el motivo.',
            );
        }

        $amount = round($adjusted ? $override : $expected, 2);

        if ($amount <= 0) {
            return null;
        }

        return $stay->payments()->create([
            'amount' => $amount,
            'method' => $method,
            'kind' => Payment::KIND_GUARANTEE,
            'notes' => $this->notes($rooms, $expected, $adjusted, $reason),
            'received_by' => $user?->id,
            'paid_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * La nota es lo único que va a leer quien devuelva el dinero días
     * después, así que carga el porqué del monto: el escalón que aplicó y,
     * si se ajustó a mano, de cuánto venía y quién dijo por qué.
     */
    protected function notes(int $rooms, float $expected, bool $adjusted, string $reason): string
    {
        $notes = 'Fianza (depósito en garantía) cobrada al registrar la llegada';

        if ($rooms > 1) {
            $notes .= ' · '.$rooms.' habitaciones a $'.number_format($expected, 2).' cada una';
        }

        if ($adjusted) {
            $notes .= ' · Monto ajustado (la política pedía $'.number_format($expected, 2).'): '.$reason;
        }

        return $notes;
    }
}
