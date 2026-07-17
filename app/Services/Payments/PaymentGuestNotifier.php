<?php

namespace App\Services\Payments;

use App\Enums\ReservationStatus;
use App\Models\Conversation;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Services\Channels\DirectGuestMessenger;
use App\Services\Channels\OutboundMessenger;

/**
 * Aviso al huésped cuando su pago se confirma o rechaza (spec-pagos §7):
 * deja el mensaje en el hilo de la conversación ligada a la reserva y lo
 * empuja por el transporte del canal. Lo usan la cola de verificación
 * (transferencias) y el webhook de pasarelas. Sin conversación (reservas
 * del wizard web) el aviso sale directo: WhatsApp al teléfono que dejó el
 * huésped y correo si lo capturó.
 */
class PaymentGuestNotifier
{
    public function __construct(
        protected OutboundMessenger $messenger,
        protected DirectGuestMessenger $direct,
    ) {}

    public function paymentReceived(PaymentRequest $request): void
    {
        // Cobro de experiencia: sin conversación ni reserva de habitación —
        // aviso directo al huésped del tour.
        if ($request->isForExperience()) {
            $booking = $request->experienceBooking()->with(['session.experience', 'guest'])->first();

            if ($booking) {
                $when = $booking->session?->starts_at?->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm');
                $this->direct->sendToGuest(
                    $booking->guest,
                    "Recibimos tu pago de {$request->amountLabel()}. Tu lugar en {$booking->session?->experience?->name} ({$booking->displayCode()}) está confirmado para el {$when}. Te esperamos.",
                );
            }

            return;
        }

        // Cobro consolidado de grupo: aviso directo al responsable.
        if ($request->isForGroup()) {
            $group = $request->group()->with(['guest', 'reservations'])->first();

            if ($group) {
                $confirmed = $group->reservations->where('status', \App\Enums\ReservationStatus::Confirmed)->count();
                $this->direct->sendToGuest(
                    $group->guest,
                    "Recibimos tu pago de {$request->amountLabel()}. Tu grupo {$group->displayCode()} ({$group->reservations->count()} habitaciones, {$confirmed} confirmadas) está listo. Te esperamos.",
                );
            }

            return;
        }

        $reservation = $request->reservation()->first();

        $body = "Recibimos tu pago de {$request->amountLabel()} ({$request->conceptLabel()}).";
        $body .= $reservation->status === ReservationStatus::Confirmed
            ? " Tu reserva {$reservation->displayCode()} está confirmada. Te esperamos."
            : " Quedó registrado en tu reserva {$reservation->displayCode()}.";

        $confirmed = $reservation->status === ReservationStatus::Confirmed;

        $this->push($request->reservation_id, $body, wonLead: $confirmed, subject: 'Pago recibido', withCalendar: $confirmed);
    }

    public function paymentRejected(PaymentRequest $request, string $reason): void
    {
        if ($request->isForExperience()) {
            $booking = $request->experienceBooking()->with('guest')->first();
            $this->direct->sendToGuest(
                $booking?->guest,
                "No pudimos validar tu pago de la experiencia {$booking?->displayCode()}: {$reason}. Contacta al hotel y lo revisamos contigo.",
            );

            return;
        }

        $this->push(
            $request->reservation_id,
            "No pudimos validar tu pago: {$reason}. Si crees que es un error, respóndenos por aquí y lo revisamos contigo.",
            subject: 'Sobre tu pago',
        );
    }

    public function refundIssued(\App\Models\Refund $refund): void
    {
        if (! $refund->reservation_id) {
            return;
        }

        $code = $refund->reservation?->displayCode();
        $amount = '$'.number_format((float) $refund->amount, 2);
        $via = $refund->gateway
            ? 'por la misma vía en la que pagaste (puede tardar unos días en reflejarse)'
            : 'directamente por el hotel';

        $this->push(
            $refund->reservation_id,
            "Procesamos tu reembolso de {$amount} de la reserva {$code}, {$via}. Cualquier duda, respóndenos por aquí.",
            subject: 'Reembolso procesado',
        );
    }

    /**
     * Recordatorio de llegada (scheduler, 24 h antes): dónde, cuándo y el
     * código que le van a pedir en recepción.
     */
    public function arrivalReminder(Reservation $reservation): void
    {
        $arrival = $reservation->starts_at->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm');

        $this->push(
            $reservation->id,
            "Te esperamos: tu reserva {$reservation->displayCode()} ({$reservation->roomType?->name}) llega el {$arrival}. Presenta tu código en recepción. Si algo cambió, respóndenos por aquí o llama al hotel.",
            subject: 'Te esperamos pronto',
        );
    }

    /**
     * Reserva confirmada (sin pago de por medio o confirmación manual del
     * staff): el huésped se entera, no solo el panel.
     */
    public function reservationConfirmed(Reservation $reservation): void
    {
        $arrival = $reservation->starts_at->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm');

        $this->push(
            $reservation->id,
            "Tu reserva {$reservation->displayCode()} está confirmada: {$reservation->roomType?->name}, llegada el {$arrival}. Te esperamos.",
            wonLead: true,
            subject: 'Reserva confirmada',
            withCalendar: true,
        );
    }

    protected function push(int $reservationId, string $body, bool $wonLead = false, string $subject = 'Sobre tu reserva', bool $withCalendar = false): void
    {
        $conversation = Conversation::query()
            ->where('reservation_id', $reservationId)
            ->latest('id')
            ->first();

        if (! $conversation) {
            // Reserva sin hilo (wizard web): aviso directo por WhatsApp/correo.
            $reservation = Reservation::find($reservationId);

            if ($reservation) {
                $this->direct->send($reservation, $body, $subject, $withCalendar);
            }

            return;
        }

        $conversation->messages()->create([
            'direction' => 'out',
            'sender_type' => 'system',
            'body' => $body,
            'created_at' => now(),
        ]);
        $conversation->update(['last_message_at' => now()]);

        if ($wonLead) {
            $conversation->markLead(Conversation::LEAD_WON);
        }

        $this->messenger->pushToConversation($conversation, $body);
    }
}
