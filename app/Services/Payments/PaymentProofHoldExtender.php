<?php

namespace App\Services\Payments;

use App\Actions\Payments\IssueGroupPayment;
use App\Actions\Payments\IssuePaymentRequest;
use App\Enums\ReservationStatus;
use App\Models\Message;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Services\Channels\InboundMediaService;
use App\Services\ReservationPolicy;
use Carbon\CarbonInterface;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

/**
 * El comprobante detiene el reloj del apartado.
 *
 * Caso real cabañas 2026-09-10 (conv. 69): el apartado vencía a las 20:27,
 * el huésped mandó su comprobante a las 20:19 y aun así el barrido lo
 * canceló; el bot le dijo "venció" a quien ya había depositado. Recibir un
 * archivo por el chat no movía nada.
 *
 * Ahora, cuando el huésped manda un comprobante a una conversación con un
 * apartado VIGENTE, el apartado —y los de su mismo grupo GRP-— se sostiene
 * mientras el hotel verifica (ReservationPolicy::proofReviewMinutes). No
 * confirma nada ni da el pago por recibido: solo evita que se libere la
 * habitación de alguien que ya pagó.
 *
 * Los adjuntos que entran por los canales los decide InboundMediaService:
 * primero lee la imagen y solo si es comprobante llama a extendFor(). Una
 * selfie ya no sostiene la habitación de nadie (cabañas 2026-09-15). El
 * evento de Media Library queda para los adjuntos que no pasan por ahí.
 *
 * Y si el comprobante llega TARDE —el apartado ya venció porque depositó
 * 10 minutos después del plazo— el apartado se REABRE con su mismo código,
 * siempre y cuando nadie más haya apartado esa habitación (pedido del
 * hotel de cabañas, 2026-09-11). Queda igual: esperando que el hotel
 * confirme el pago.
 *
 * En los dos casos el comprobante queda para aprobarse en Pagos: esa cola
 * solo lista cobros por transferencia PENDIENTES, y el del huésped casi
 * siempre ya había vencido (20 min en cabañas) o ni existía — el bot le
 * pasó las cuentas sin emitirlo (RES-2026-1693). En un grupo es UN cobro
 * consolidado, no uno por cabaña (GRP-2026-0152 amaneció con dos).
 */
class PaymentProofHoldExtender
{
    public function __construct(protected ReservationPolicy $policy) {}

    public function handle(MediaHasBeenAddedEvent $event): void
    {
        $model = $event->media->model;

        if (! $model instanceof Message || $event->media->collection_name !== 'attachments') {
            return;
        }

        // Adjunto de canal: InboundMediaService lo lee y decide.
        if ($event->media->getCustomProperty('proof_review') === InboundMediaService::DEFERRED) {
            return;
        }

        try {
            $this->extendFor($model);
        } catch (\Throwable $e) {
            // Nunca tumbar la entrada de un mensaje del huésped por esto.
            report($e);
        }
    }

    /**
     * @return int Apartados sostenidos.
     */
    public function extendFor(Message $message): int
    {
        if ($message->direction !== 'in') {
            return 0;
        }

        $conversation = $message->conversation;
        $reservation = $conversation?->reservation;

        if (! $reservation) {
            return 0;
        }

        // Comprobante tardío: el apartado ya venció. Se reabre si la
        // habitación sigue libre; si ya la ganó otro, el personal decide.
        if ($reservation->isExpiredHold()) {
            $reopened = $this->reopenLate($reservation);

            if ($reopened > 0) {
                $conversation->markLead(\App\Models\Conversation::LEAD_HOLD);
            }

            return $reopened;
        }

        $holds = Reservation::query()
            ->where('status', ReservationStatus::Pending)
            ->where('hold_expires_at', '>', now())
            ->when(
                $reservation->reservation_group_id,
                fn ($query, $groupId) => $query->where('reservation_group_id', $groupId),
                fn ($query) => $query->whereKey($reservation->id),
            )
            ->get();

        $until = now()->addMinutes($this->policy->proofReviewMinutes());
        $extended = 0;

        foreach ($holds as $hold) {
            if ($hold->hold_expires_at->lt($until)) {
                $hold->update(['hold_expires_at' => $until]);

                activity('reservation')
                    ->performedOn($hold)
                    ->log(sprintf(
                        'Comprobante recibido por chat: el apartado se sostiene hasta el %s mientras el hotel verifica el pago',
                        $until->format('d/m/Y H:i'),
                    ));

                $extended++;
            }
        }

        if ($holds->isNotEmpty()) {
            $this->ensureTransferRequest($holds, $until);
        }

        // Ya confirmada y con saldo (el comprobante del resto, o uno que el
        // hotel prometió verificar): también tiene que verse en Pagos.
        if ($holds->isEmpty()
            && $reservation->status === ReservationStatus::Confirmed
            && $reservation->pendingBalance() > 0) {
            $this->ensureTransferRequest(collect([$reservation]), $until);
        }

        return $extended;
    }

    /**
     * Reabre el apartado vencido (y los vencidos de su grupo GRP-) con la
     * ventana de verificación. Solo si venció hace menos que esa misma
     * ventana: una foto cualquiera no revive un apartado de hace una semana.
     */
    protected function reopenLate(Reservation $reservation): int
    {
        $window = $this->policy->proofReviewMinutes();

        if ($reservation->hold_expires_at->lt(now()->subMinutes($window))) {
            return 0;
        }

        $expired = $reservation->reservation_group_id
            ? Reservation::query()
                ->where('reservation_group_id', $reservation->reservation_group_id)
                ->where('status', ReservationStatus::Cancelled)
                ->get()
                ->filter(fn (Reservation $member) => $member->isExpiredHold())
            : collect([$reservation]);

        $reopened = collect();

        foreach ($expired as $hold) {
            try {
                app(\App\Actions\Reservations\TransitionReservation::class)->reopen($hold, null, [
                    'hold_minutes' => $window,
                ]);
            } catch (\App\Exceptions\NoAvailabilityException|\InvalidArgumentException $e) {
                // Ya la apartó alguien más (o la llegada pasó): no se toca.
                activity('reservation')
                    ->performedOn($hold)
                    ->log('Llegó un comprobante después del vencimiento, pero no se pudo reabrir: '.$e->getMessage());

                continue;
            }

            activity('reservation')
                ->performedOn($hold)
                ->log('Comprobante recibido después del vencimiento: el apartado se reabrió y espera que el hotel verifique el pago');

            $reopened->push($hold->refresh());
        }

        if ($reopened->isNotEmpty()) {
            $this->ensureTransferRequest($reopened, now()->addMinutes($window));
        }

        return $reopened->count();
    }

    /**
     * Deja un cobro por transferencia PENDIENTE que viva al menos hasta
     * $until: extiende el que haya o emite uno (IssuePaymentRequest e
     * IssueGroupPayment no le escriben al huésped). En un grupo, uno solo
     * consolidado por el GRP-. Sin saldo pendiente no hay nada que aprobar.
     *
     * @param  \Illuminate\Support\Collection<int, Reservation>  $reservations
     */
    protected function ensureTransferRequest(\Illuminate\Support\Collection $reservations, CarbonInterface $until): void
    {
        $groups = $reservations->whereNotNull('reservation_group_id')->pluck('reservation_group_id')->unique();
        $singles = $reservations->whereNull('reservation_group_id');

        foreach ($groups as $groupId) {
            $this->keepRequestAlive(fn () => PaymentRequest::query()
                ->where('reservation_group_id', $groupId)
                ->where('method', PaymentRequest::METHOD_TRANSFER)
                ->where('status', PaymentRequest::STATUS_PENDING)
                ->latest('id')
                ->first()
                ?? app(IssueGroupPayment::class)->handle(
                    \App\Models\ReservationGroup::query()->findOrFail($groupId),
                    PaymentRequest::METHOD_TRANSFER,
                ), $until);
        }

        foreach ($singles as $reservation) {
            $this->keepRequestAlive(fn () => $reservation->paymentRequests()
                ->where('method', PaymentRequest::METHOD_TRANSFER)
                ->where('status', PaymentRequest::STATUS_PENDING)
                ->latest('id')
                ->first()
                ?? app(IssuePaymentRequest::class)->handle($reservation, PaymentRequest::METHOD_TRANSFER), $until);
        }
    }

    /** @param  callable(): PaymentRequest  $resolve */
    protected function keepRequestAlive(callable $resolve, CarbonInterface $until): void
    {
        try {
            $request = $resolve();

            if ($request->expires_at === null || $request->expires_at->lt($until)) {
                $request->update(['expires_at' => $until]);
            }

            // La foto va pegada al cobro: en Pagos se aprueba viéndola, sin
            // ir a buscarla a la bandeja (caso real RES-2026-1693).
            app(InboundMediaService::class)->rescueLatestAttachment($request);
        } catch (\InvalidArgumentException) {
            // Sin saldo, o la reserva ya no admite cobros: nada que aprobar.
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
