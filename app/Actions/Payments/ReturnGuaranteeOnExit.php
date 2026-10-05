<?php

namespace App\Actions\Payments;

use App\Models\Payment;
use App\Models\Stay;
use Carbon\CarbonInterface;

/**
 * La fianza se devuelve al salir, siempre y cuando no quede nada por cobrar.
 * Es la regla del hotel y la que ya aplicaba la salida MANUAL (devolver es lo
 * predeterminado); la salida automática de las 11:15 cerraba la estancia sin
 * tocar la fianza, y el sistema la daba por retenida aunque se hubiera
 * regresado — el corte de caja esperaba dinero que ya no estaba.
 *
 * Con saldo pendiente no se hace nada: la estancia queda en Cuentas por
 * cerrar y ahí decide una persona si la fianza cubre lo que se debe.
 */
class ReturnGuaranteeOnExit
{
    public const REASON = 'Fianza devuelta al salir: no quedó nada por cobrar';

    public function __construct(protected RefundPayment $refund) {}

    /**
     * Fianzas vivas de la estancia (cobradas y sin devolver del todo).
     *
     * @return \Illuminate\Support\Collection<int, Payment>
     */
    public function liveGuarantees(Stay $stay): \Illuminate\Support\Collection
    {
        return $stay->payments()
            ->where('kind', Payment::KIND_GUARANTEE)
            ->get()
            ->filter(fn (Payment $payment) => $payment->refundableAmount() > 0)
            ->values();
    }

    /**
     * ¿Aplica la regla? Hay fianza viva y la cuenta quedó en ceros.
     */
    public function applies(Stay $stay): bool
    {
        return $this->liveGuarantees($stay)->isNotEmpty()
            && $stay->folio()['grand_pending'] <= 0;
    }

    /**
     * Registra la devolución. Con `$at` la fecha es la de la salida (para
     * acomodar las que quedaron colgadas); sin ella, ahora.
     *
     * @return float lo devuelto
     */
    public function handle(Stay $stay, ?CarbonInterface $at = null): float
    {
        if (! $this->applies($stay)) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($this->liveGuarantees($stay) as $payment) {
            $amount = $payment->refundableAmount();
            // A nombre de quien la cobró: el corte resta las devoluciones del
            // cajón de cada encargado, y esa fianza estaba en el suyo.
            $refund = $this->refund->handle($payment, $amount, self::REASON, $payment->receivedBy, manual: true);

            if ($at !== null) {
                $refund->forceFill(['refunded_at' => $at])->save();
            }

            $total += $amount;
        }

        return round($total, 2);
    }
}
