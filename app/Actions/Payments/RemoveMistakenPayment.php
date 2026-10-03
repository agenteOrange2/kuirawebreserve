<?php

namespace App\Actions\Payments;

use App\Models\CashCut;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Quita de una reserva un pago que se registró por error — típicamente el
 * mismo dinero dos veces: se capturó a mano y luego se aprobó su comprobante
 * en /pagos. Caso real cabañas 2026-10-03 (reserva 1789): el anticipo quedó
 * doble, la reserva "Pagada" y ya no dejaba emitir el link del saldo.
 *
 * NO es un reembolso: ese dinero nunca entró dos veces, así que no se avisa
 * al huésped ni cuenta como devolución en CashLedger. La fila se borra (como
 * la limpieza a mano del 17-sep) en vez de marcarse anulada: hay decenas de
 * sumas sobre `payments` y la que olvidara filtrar un "anulado" volvería a
 * contar el dinero. Lo que queda es la foto completa en la bitácora de la
 * reserva. Siempre lo hace una persona y con motivo.
 */
class RemoveMistakenPayment
{
    /**
     * @return array{payment: array<string, mixed>, closed_cut: CashCut|null}
     */
    public function handle(Reservation $reservation, Payment $payment, string $reason, User $user): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Escribe por qué se quita este pago: queda en la bitácora de la reserva.');
        }

        return DB::transaction(function () use ($reservation, $payment, $reason, $user) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->reservation_id !== $reservation->id) {
                throw new InvalidArgumentException('Ese pago no es de esta reserva.');
            }

            if ($blocked = self::blockedReason($payment)) {
                throw new InvalidArgumentException($blocked);
            }

            $request = $payment->paymentRequest;
            $closedCut = $this->closedCut($payment);

            $snapshot = [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'method_label' => Payment::methodLabel($payment->method),
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'paid_at' => $payment->paid_at?->toDateTimeString(),
                'received_by' => $payment->received_by,
                'received_by_name' => $payment->receivedBy?->name,
                'shift_id' => $payment->shift_id,
                'payment_request_id' => $payment->payment_request_id,
                'receipt_file' => $payment->getFirstMedia('receipt')?->file_name,
                'closed_cut_id' => $closedCut?->id,
            ];

            // El comprobante que originó este pago ya no puede volver a la
            // cola ni aprobarse otra vez: rechazado no es cobrable (los
            // cancelados sí). Si otro pago real quedó ligado a él, se respeta.
            if ($request && (int) $request->payment_id === $payment->id) {
                $request->update([
                    'status' => PaymentRequest::STATUS_REJECTED,
                    'payment_id' => null,
                    'meta' => array_merge($request->meta ?? [], [
                        'payment_removed' => [
                            'payment_id' => $payment->id,
                            'reason' => $reason,
                            'by' => $user->id,
                            'at' => now()->toDateTimeString(),
                        ],
                    ]),
                ]);
            }

            $payment->delete();

            $reservation->syncPaymentStatus();

            activity('payment')
                ->performedOn($reservation)
                ->causedBy($user)
                ->withProperties(['removed_payment' => $snapshot, 'reason' => $reason])
                ->log(sprintf(
                    'Se quitó un pago de $%s (%s, %s) registrado por error: %s',
                    number_format($snapshot['amount'], 2),
                    $snapshot['method_label'],
                    $payment->paid_at?->format('d/m/Y H:i'),
                    $reason,
                ));

            return ['payment' => $snapshot, 'closed_cut' => $closedCut];
        });
    }

    /**
     * Por qué no se puede quitar este pago desde la ficha, o null si se
     * puede. La pasarela sí cobró (eso es Reembolsar); los del folio y la
     * fianza viven en la estancia; el de un grupo es parte de un reparto.
     */
    public static function blockedReason(Payment $payment): ?string
    {
        return match (true) {
            $payment->reservation_id === null => 'Ese pago no está ligado a una reserva.',
            $payment->method === Payment::METHOD_ONLINE || $payment->gateway_ref !== null => 'Este pago entró por la pasarela: ese dinero sí se cobró. Si hay que devolverlo, usa Reembolsar.',
            $payment->kind !== null => 'Este cobro es del folio de la estancia; corrígelo desde la estancia.',
            $payment->refunds()->exists() => 'Este pago ya tiene reembolsos registrados; no se puede quitar.',
            $payment->paymentRequest?->isForGroup() === true => 'Este pago es parte de un cobro de grupo repartido entre varias habitaciones; pide ayuda a soporte para corregirlo.',
            default => null,
        };
    }

    /** El corte ya cerrado que contó este pago: su foto no cambia al quitarlo. */
    protected function closedCut(Payment $payment): ?CashCut
    {
        if ($payment->received_by === null || $payment->paid_at === null) {
            return null;
        }

        return CashCut::query()
            ->whereNotNull('closed_at')
            ->where('scope', '!=', CashCut::SCOPE_POS)
            ->where(fn ($query) => $query
                ->when($payment->shift_id, fn ($query, $shift) => $query->where('shift_id', $shift))
                ->orWhere(fn ($query) => $query
                    ->where('user_id', $payment->received_by)
                    ->where('opened_at', '<', $payment->paid_at)
                    ->where('closed_at', '>=', $payment->paid_at)))
            ->latest('closed_at')
            ->first();
    }
}
