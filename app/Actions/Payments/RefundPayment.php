<?php

namespace App\Actions\Payments;

use App\Models\Central\PaymentGatewayLink;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\Payments\Gateways;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reembolsa un pago, total o parcial (spec-pagos F4/§6.6). El dinero
 * regresa por donde entró: pagos de pasarela via API del proveedor; el
 * resto (efectivo/tarjeta/transferencia) es devolución manual del hotel y
 * aquí solo se registra. `manual = true` fuerza solo-registro también para
 * pagos de pasarela (el hotel lo hizo en el dashboard del proveedor).
 * SIEMPRE lo dispara un humano — nunca el bot ni un webhook.
 */
class RefundPayment
{
    public function handle(
        Payment $payment,
        float $amount,
        ?string $reason = null,
        ?User $user = null,
        bool $manual = false,
    ): Refund {
        return DB::transaction(function () use ($payment, $amount, $reason, $user, $manual) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $amount = round($amount, 2);
            $refundable = $payment->refundableAmount();

            if ($amount <= 0) {
                throw new InvalidArgumentException('El monto a reembolsar debe ser mayor a cero.');
            }

            if ($amount > $refundable) {
                throw new InvalidArgumentException(
                    'El reembolso ($'.number_format($amount, 2).') excede lo reembolsable ($'.number_format($refundable, 2).').',
                );
            }

            $gatewayRef = null;
            $viaGateway = ! $manual && $payment->gateway !== null && $payment->gateway_ref !== null;

            if ($viaGateway) {
                $link = PaymentGatewayLink::query()
                    ->where('tenant_id', (string) tenant('id'))
                    ->where('provider', $payment->gateway)
                    ->first();

                if (! $link) {
                    throw new InvalidArgumentException(
                        'La pasarela de este pago ya no está conectada; hazlo desde el dashboard del proveedor y regístralo como manual.',
                    );
                }

                $result = Gateways::for($payment->gateway)->refund($payment, $link, $amount);

                if (! $result['ok']) {
                    throw new InvalidArgumentException('El proveedor rechazó el reembolso: '.$result['detail']);
                }

                $gatewayRef = $result['ref'];
            }

            return Refund::create([
                'payment_id' => $payment->id,
                'reservation_id' => $payment->reservation_id,
                'amount' => $amount,
                'status' => Refund::STATUS_COMPLETED,
                'gateway' => $viaGateway ? $payment->gateway : null,
                'gateway_ref' => $gatewayRef,
                'reason' => $reason,
                'created_by' => $user?->id,
                'refunded_at' => now(),
            ]);
        });
    }
}
