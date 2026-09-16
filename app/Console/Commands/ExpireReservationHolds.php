<?php

namespace App\Console\Commands;

use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\NoAvailabilityException;
use App\Models\Reservation;
use Illuminate\Console\Command;

/**
 * Higiene de holds vencidos: el motor de disponibilidad ya los ignora por
 * query (scope blocking), esto solo los marca cancelados para que el panel
 * no muestre pendientes muertos. Correr por tenant: tenants:run.
 *
 * Un apartado CON dinero encima no es un apartado abandonado: en vez de
 * cancelarlo se confirma, y el saldo queda pendiente. Caso real cabañas
 * 2026-09-12: la huésped depositó y el reloj la canceló igual. Y no se puede
 * dejar simplemente "pendiente sin cancelar": un pendiente con hold vencido
 * NO bloquea la habitación (scopeBlocking), así que otro la reservaría
 * encima — por eso pasa a Confirmada, que es lo único que aparta de verdad.
 */
class ExpireReservationHolds extends Command
{
    protected $signature = 'reservations:expire-holds';

    protected $description = 'Cancela reservas pendientes cuyo hold ya venció';

    public function handle(TransitionReservation $transition): int
    {
        $confirmed = $this->keepPaidHolds($transition);
        $waiting = $this->keepHoldsWithReceipt();

        $ids = Reservation::query()
            ->where('status', ReservationStatus::Pending)
            ->where('hold_expires_at', '<=', now())
            // Los pagados ya se sostuvieron arriba; aquí solo mueren los
            // apartados sin un peso encima.
            ->whereDoesntHave('payments')
            ->pluck('id');

        // Con motivo: en el Historial se ve que venció el plazo (y no que
        // alguien la canceló), y así se sabe que se puede reabrir.
        $expired = Reservation::query()
            ->whereIn('id', $ids)
            ->update([
                'status' => ReservationStatus::Cancelled,
                'cancellation_reason' => Reservation::EXPIRED_HOLD_REASON,
            ]);

        // Los tours comprados como plus de esos holds liberan su cupo junto
        // con la habitación — mismos estados vivos que TransitionReservation.
        if ($ids->isNotEmpty()) {
            \App\Models\ExperienceBooking::query()
                ->whereIn('reservation_id', $ids)
                ->whereIn('status', [
                    \App\Models\ExperienceBooking::STATUS_PENDING,
                    \App\Models\ExperienceBooking::STATUS_CONFIRMED,
                ])
                ->update(['status' => \App\Models\ExperienceBooking::STATUS_CANCELLED, 'updated_at' => now()]);

            // Tours colgados de un GRP- cuyo hold murió completo: si al
            // grupo no le queda ninguna reserva viva, sus experiencias
            // también sueltan el cupo.
            $groupIds = Reservation::query()
                ->whereIn('id', $ids)
                ->whereNotNull('reservation_group_id')
                ->pluck('reservation_group_id')
                ->unique();

            $deadGroups = $groupIds->reject(fn ($groupId) => Reservation::query()
                ->where('reservation_group_id', $groupId)
                ->whereIn('status', [
                    ReservationStatus::Pending,
                    ReservationStatus::Confirmed,
                    ReservationStatus::CheckedIn,
                ])
                ->exists());

            if ($deadGroups->isNotEmpty()) {
                \App\Models\ExperienceBooking::query()
                    ->whereIn('reservation_group_id', $deadGroups)
                    ->whereIn('status', [
                        \App\Models\ExperienceBooking::STATUS_PENDING,
                        \App\Models\ExperienceBooking::STATUS_CONFIRMED,
                    ])
                    ->update(['status' => \App\Models\ExperienceBooking::STATUS_CANCELLED, 'updated_at' => now()]);
            }
        }

        $this->info("Holds vencidos cancelados: {$expired} · sostenidos por tener pago: {$confirmed} · esperando verificar comprobante: {$waiting}");

        return self::SUCCESS;
    }

    /**
     * Apartados vencidos cuyo comprobante nadie ha verificado: se sostienen
     * en tramos de 12 h en lugar de cancelarse, y el personal recibe el
     * aviso otra vez.
     *
     * Caso real cabañas 2026-09-15 (GRP-2026-0152): el huésped mandó su
     * comprobante a las 18:13, el sistema sostuvo el grupo 24 h y, como nadie
     * lo abrió en /pagos, el barrido lo iba a cancelar con la foto adentro.
     * Un pendiente con hold vencido NO bloquea la habitación, así que no
     * basta con saltarlo: hay que moverle el plazo.
     *
     * Tope: 72 h desde que llegó el comprobante y nunca después de la
     * llegada. Una imagen que se leyó y NO es comprobante, o una clave de
     * rastreo repetida, no sostienen nada.
     */
    protected function keepHoldsWithReceipt(): int
    {
        $holds = Reservation::query()
            ->where('status', ReservationStatus::Pending)
            ->where('hold_expires_at', '<=', now())
            ->where('starts_at', '>', now())
            ->whereDoesntHave('payments')
            ->get();

        $kept = 0;
        $alerted = [];

        foreach ($holds as $hold) {
            // En un grupo el comprobante de CUALQUIER habitación sostiene a
            // todas: el grupo es todo o nada. Los cobros del bot nacen por
            // cabaña (GRP-2026-0152 tenía dos, y solo uno con la foto), así
            // que mirar solo el cobro propio partía el grupo a la mitad.
            $hermanas = $hold->reservation_group_id
                ? Reservation::query()
                    ->where('reservation_group_id', $hold->reservation_group_id)
                    ->pluck('id')
                    ->all()
                : [$hold->id];

            $request = \App\Models\PaymentRequest::query()
                ->where('method', \App\Models\PaymentRequest::METHOD_TRANSFER)
                ->where('status', \App\Models\PaymentRequest::STATUS_PENDING)
                ->where(fn ($query) => $query
                    ->whereIn('reservation_id', $hermanas)
                    ->when($hold->reservation_group_id, fn ($query, $group) => $query->orWhere('reservation_group_id', $group)))
                ->whereHas('media', fn ($query) => $query->where('collection_name', 'receipt'))
                ->latest('id')
                ->first();

            $verdict = $request?->meta['receipt_check']['verdict'] ?? null;
            $receivedAt = $request?->getFirstMedia('receipt')?->created_at;

            if ($request === null
                || in_array($verdict, [\App\Services\Payments\ReceiptCheck::NOT_RECEIPT, \App\Services\Payments\ReceiptCheck::DUPLICATE], true)
                || $receivedAt === null
                || $receivedAt->lt(now()->subHours(72))) {
                continue;
            }

            $until = now()->addHours(12)->min($hold->starts_at);

            $hold->update(['hold_expires_at' => $until]);

            if ($request->expires_at === null || $request->expires_at->lt($until)) {
                $request->update(['expires_at' => $until]);
            }

            activity('reservation')
                ->performedOn($hold)
                ->log('El comprobante sigue sin verificarse: el apartado se sostiene hasta el '.$until->format('d/m/Y H:i'));

            $kept++;

            if (isset($alerted[$request->id])) {
                continue;
            }

            $alerted[$request->id] = true;
            $code = $hold->group?->displayCode() ?? $hold->displayCode();

            try {
                app(\App\Services\StaffNotifier::class)->notify(
                    type: \App\Models\StaffNotification::TYPE_PAYMENT,
                    title: 'Comprobante sin verificar',
                    body: "{$hold->guest_name} mandó su comprobante de {$code} hace ".$receivedAt->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE)
                        .'. Se sostiene hasta el '.$until->format('d/m H:i').': apruébalo o recházalo en Pagos.',
                    url: '/pagos',
                    subject: $hold,
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $kept;
    }

    /**
     * Apartados vencidos que YA tienen dinero: se confirman en vez de
     * cancelarse. El interruptor de auto-confirmar al pagar no aplica aquí:
     * a esta altura la única alternativa a confirmar es tirar a la basura una
     * reserva pagada.
     *
     * Si la habitación se vendió mientras tanto, la reserva se queda
     * pendiente y queda el motivo en su bitácora: ese choque lo resuelve
     * recepción (reubicar o devolver), no un comando.
     */
    protected function keepPaidHolds(TransitionReservation $transition): int
    {
        $paid = Reservation::query()
            ->where('status', ReservationStatus::Pending)
            ->where('hold_expires_at', '<=', now())
            ->whereHas('payments')
            ->get();

        $kept = 0;

        foreach ($paid as $reservation) {
            try {
                $transition->confirm($reservation);
                $kept++;
            } catch (NoAvailabilityException $e) {
                activity('reservation')
                    ->performedOn($reservation)
                    ->log('Apartado pagado que no se pudo confirmar al vencer su plazo: '.$e->getMessage());

                $this->warn("Reserva {$reservation->displayCode()} tiene pago pero su habitación ya no está libre.");
            }
        }

        return $kept;
    }
}
