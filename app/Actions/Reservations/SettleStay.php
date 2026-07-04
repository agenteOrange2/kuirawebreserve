<?php

namespace App\Actions\Reservations;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Liquida la cuenta final de una estancia (folio): cobra el hospedaje
 * pendiente y los consumos POS cargados a la habitación, dejando todo
 * registrado como pagos del cobrador (entra a su corte de venta).
 */
class SettleStay
{
    public function __construct(protected RegisterReservationPayment $reservationPayment) {}

    /**
     * @param  array{method: string, reference?: string|null, notes?: string|null}  $data
     * @return array<string, mixed> Folio ya liquidado.
     */
    public function handle(Stay $stay, array $data, ?User $user = null): array
    {
        if (! in_array($data['method'], Payment::METHODS, true)) {
            throw new InvalidArgumentException('Método de pago inválido.');
        }

        return DB::transaction(function () use ($stay, $data, $user) {
            $stay = Stay::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $folio = $stay->folio();

            if ($folio['grand_pending'] <= 0) {
                return $folio;
            }

            // Hospedaje pendiente.
            if ($folio['lodging_pending'] > 0) {
                if ($stay->reservation) {
                    // Reutiliza la action existente: valida y re-deriva estado de pago.
                    $payment = $this->reservationPayment->handle($stay->reservation, [
                        'amount' => $folio['lodging_pending'],
                        'method' => $data['method'],
                        'reference' => $data['reference'] ?? null,
                        'notes' => 'Liquidación en check-out',
                    ], $user);

                    // Liga el pago también a la estancia para el folio.
                    $payment->update(['stay_id' => $stay->id, 'kind' => Payment::KIND_LODGING]);
                } else {
                    $stay->payments()->create([
                        'amount' => $folio['lodging_pending'],
                        'method' => $data['method'],
                        'kind' => Payment::KIND_LODGING,
                        'reference' => $data['reference'] ?? null,
                        'notes' => 'Hospedaje walk-in liquidado en check-out',
                        'received_by' => $user?->id,
                        'paid_at' => now(),
                        'created_at' => now(),
                    ]);
                }
            }

            // Consumos cargados a la habitación.
            if ($folio['consumption_pending'] > 0) {
                $stay->payments()->create([
                    'amount' => $folio['consumption_pending'],
                    'method' => $data['method'],
                    'kind' => Payment::KIND_CONSUMPTION,
                    'reference' => $data['reference'] ?? null,
                    'notes' => 'Consumos liquidados en check-out',
                    'received_by' => $user?->id,
                    'paid_at' => now(),
                    'created_at' => now(),
                ]);

                Order::query()
                    ->whereIn('id', $folio['orders']->pluck('id'))
                    ->update(['settled_at' => now(), 'settled_by' => $user?->id]);
            }

            return $stay->refresh()->folio();
        });
    }
}
