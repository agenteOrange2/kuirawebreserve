<?php

namespace App\Http\Controllers\Tenant;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Ficha propia de una reserva (/reservas/{id}).
 *
 * El detalle vivía solo en el panel lateral de /reservas, que se cierra con
 * cualquier clic y no tiene lugar para el dinero. Aquí, en una página que se
 * puede compartir y recargar: cobrar, registrar un pago (también una
 * transferencia ya verificada), confirmar y reabrir. Caso real cabañas
 * 2026-09-11: el huésped depositó, su cobro había vencido y no había dónde
 * registrar ese dinero el día de su llegada.
 *
 * Hereda de la página de reservas para serializar la reserva EXACTAMENTE
 * igual que la lista: los componentes (PaymentModal, ReopenDialog) reciben la
 * misma forma de dato en los dos lados.
 */
class ReservationShowPageController extends ReservationsPageController
{
    /** La ficha cuenta la historia completa, no el asomo de la lista. */
    private const FULL_TIMELINE_LIMIT = 40;

    public function show(Request $request, Reservation $reservation): Response
    {
        $reservation->load([
            'room:id,number',
            'roomType:id,name',
            'ratePlan:id,name,type',
            'guest:id,first_name,last_name,phone,email',
        ])->loadSum('payments', 'amount');

        $activities = Activity::query()
            ->where('subject_type', Reservation::class)
            ->where('subject_id', $reservation->id)
            ->with('causer')
            ->latest()
            ->latest('id')
            ->limit(self::FULL_TIMELINE_LIMIT)
            ->get();

        $row = $this->serializeReservation($reservation, $activities);

        // La ficha es la pantalla del dinero. La lista NO manda los pagos a
        // propósito (serían consultas por fila), pero aquí hacen falta: sin
        // ellos la página se quedaba en blanco al pintar reservation.payments
        // (reserva 1713 de cabañas, 2026-09-11).
        $row += $this->moneyPayload($reservation);

        $row['timeline'] = $activities->map(fn (Activity $activity) => [
            'id' => (string) $activity->id,
            'message' => $this->timelineMessage(
                $activity,
                $activity->properties['old'] ?? [],
                $activity->properties['attributes'] ?? [],
            ),
            'by' => $activity->causer?->name,
            'at' => $activity->created_at?->format('d/m/Y H:i'),
        ])->values()->all();

        // La conversación donde se hizo el trato: ahí están el comprobante y
        // lo que se le prometió al huésped.
        $conversationId = Conversation::query()
            ->where('reservation_id', $reservation->id)
            ->latest('id')
            ->value('id');

        $proofAt = $conversationId ? Message::query()
            ->where('conversation_id', $conversationId)
            ->where('direction', 'in')
            ->whereHas('media')
            ->latest('id')
            ->value('created_at') : null;

        // Una sola consulta de métodos para las dos props del modal de pago.
        $chargeOptions = app(\App\Services\Payments\PaymentMethodGate::class)
            ->panelChargeOptions((string) tenant('id'), $this->policy());

        return Inertia::render('tenant/reservations/Show', [
            'reservation' => $row,
            'conversationId' => $conversationId,
            // Los comprobantes que el huésped ya mandó por el chat: se pueden
            // usar al capturar el pago sin ir a buscarlos a la bandeja.
            'chatReceipts' => $this->chatReceipts($conversationId),
            'proofReceivedAt' => $proofAt ? \Illuminate\Support\Carbon::parse($proofAt)->format('d/m/Y H:i') : null,
            'canManage' => $request->user()->can('reservations.manage'),
            // En check-in "automático" puro la llegada la registra el reloj.
            'manualCheckinAllowed' => app(\App\Services\HousekeepingPolicy::class)->manualCheckInAllowed(),
            'gatewayAvailable' => $chargeOptions['gateway'] !== null,
            // Por dónde se puede generar el cobro (pasarela con su nombre y
            // transferencia): el personal elige en el modal de pago.
            'chargeOptions' => $chargeOptions,
            'holdMinutes' => $this->policy()->holdMinutes(),
            'contract' => $this->contractPayload($reservation),
        ]);
    }

    /**
     * Estado del contrato de hospedaje de ESTA reserva.
     *
     * El contrato ya se adjuntaba al correo de confirmación, pero solo si el
     * huésped tenía correo en su ficha: sin correo, `sendEmail()` regresaba
     * false en silencio y nadie en el hotel se enteraba de que ese huésped
     * nunca lo recibió (cabañas 2026-09-18, Daysi Gómez RES-2026-1773: dictó
     * su correo por WhatsApp y la reserva se capturó sin él). Aquí la ficha
     * lo dice con todas sus letras y deja mandarlo.
     *
     * @return array<string, mixed>
     */
    protected function contractPayload(Reservation $reservation): array
    {
        // Se busca aparte y no en $activities: la historia se corta en las
        // 40 más recientes y un envío viejo desaparecería de la tarjeta.
        $sent = Activity::query()
            ->where('subject_type', Reservation::class)
            ->where('subject_id', $reservation->id)
            ->where('description', 'like', 'Contrato de hospedaje enviado%')
            ->with('causer')
            ->latest('id')
            ->first();

        return [
            // El hotel captura su texto en Ajustes; sin él no hay nada que mandar.
            'available' => app(\App\Services\Guests\ReservationContract::class)->available(),
            'email' => $reservation->guest?->email,
            'pdf_url' => route('tenant.reservations.contract.pdf', $reservation->id),
            'sent_at' => $sent?->created_at?->format('d/m/Y H:i'),
            'sent_by' => $sent?->causer?->name,
        ];
    }

    /**
     * Últimos archivos que mandó el huésped por el chat, con lo que el
     * sistema leyó en ellos. Sirven para capturar el pago desde la ficha
     * cuando el bot no alcanzó a registrarlo: el monto y el folio ya vienen
     * leídos y la foto queda pegada al abono.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function chatReceipts(?int $conversationId): array
    {
        if (! $conversationId) {
            return [];
        }

        return Message::query()
            ->where('conversation_id', $conversationId)
            ->where('direction', 'in')
            ->whereHas('media')
            ->with('media')
            ->latest('id')
            ->limit(5)
            ->get()
            ->flatMap(function (Message $message) use ($conversationId) {
                $lectura = is_array($message->meta['media_reading'] ?? null) ? $message->meta['media_reading'] : null;

                return $message->getMedia('attachments')->map(fn ($media) => [
                    'media_id' => $media->id,
                    'name' => $media->file_name,
                    'is_image' => str_starts_with((string) $media->mime_type, 'image/'),
                    'url' => route('tenant.inbox.attachment', [$conversationId, $media->id]),
                    'at' => $message->created_at->format('d/m H:i'),
                    'verdict' => $lectura['check']['verdict'] ?? null,
                    'summary' => $lectura['check']['summary'] ?? null,
                    'amount' => $lectura['amount'] ?? null,
                    'reference' => $lectura['tracking_key'] ?? ($lectura['reference'] ?? null),
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * Pagos, reembolsos y cobro en curso de ESTA reserva.
     *
     * Misma forma que ReservationController::serialize(): es lo que comen
     * PaymentModal y ReopenDialog en los dos lados, así que si cambia allá
     * tiene que cambiar aquí. Está duplicado porque aquella vive en otra
     * jerarquía de controladores; el día que se unifiquen, esto se borra.
     *
     * @return array<string, mixed>
     */
    protected function moneyPayload(Reservation $reservation): array
    {
        return [
            'payments' => $reservation->payments()
                ->latest('paid_at')
                ->get()
                ->map(fn (Payment $p) => [
                    'id' => $p->id,
                    'amount' => $p->amount,
                    'method' => Payment::methodLabel($p->method),
                    'reference' => $p->reference,
                    'paid_at' => $p->paid_at->format('d/m/Y H:i'),
                    'received_by' => $p->receivedBy?->name,
                    'refunded' => $p->refundedTotal(),
                    'refundable' => $p->refundableAmount(),
                    'via_gateway' => $p->gateway !== null,
                    'removable' => \App\Actions\Payments\RemoveMistakenPayment::blockedReason($p) === null,
                ])
                ->values()
                ->all(),
            'refunded_total' => $reservation->refundedTotal(),
            // "Si se cancela ahora, correspondería X" según la política.
            'refund_suggestion' => ($suggestion = $reservation->suggestedRefund()) !== null ? [
                'amount' => $suggestion,
                'amount_label' => '$'.number_format($suggestion, 2),
                'policy_label' => app(\App\Services\ReservationPolicy::class)
                    ->cancellationPolicyLabel($reservation->ratePlan),
            ] : null,
            'stay_id' => $reservation->stay?->id,
            // Cobro en curso (spec-pagos §7.5): link vivo para copiar o enviar.
            'payment_request' => ($pr = $reservation->paymentRequests()->active()->latest('id')->first()) ? [
                'id' => $pr->id,
                'concept' => $pr->conceptLabel(),
                'amount_label' => $pr->amountLabel(),
                'method' => $pr->method,
                'provider_label' => $pr->provider
                    ? (\App\Models\Central\PaymentGatewayLink::PROVIDERS[$pr->provider] ?? $pr->provider)
                    : null,
                'checkout_url' => $pr->checkout_url,
                'public_url' => route('tenant.payment.return', $pr->uuid),
                'status_label' => $pr->statusLabel(),
                'expires_label' => $pr->expires_at?->diffForHumans(),
            ] : null,
        ];
    }
}
