<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Conversation;
use Illuminate\Console\Command;

/**
 * Follow-ups de abandono (spec agentes): el bot retoma conversaciones que
 * se enfriaron — recuerda holds por vencer, avisa cuando vencieron (y ofrece
 * retomarlos), felicita reservas confirmadas y reengancha cotizaciones sin
 * respuesta. Mensajes de plantilla (sin LLM: costo cero y sin alucinación),
 * cada uno se envía UNA sola vez. Correr por tenant: tenants:run.
 */
class FollowUpConversations extends Command
{
    protected $signature = 'conversations:follow-up';

    protected $description = 'Envía follow-ups del bot: holds por vencer/vencidos, confirmadas y cotizaciones frías';

    public function handle(): int
    {
        $sent = 0;
        $sent += $this->confirmedReservations();
        $sent += $this->holdsAboutToExpire();
        $sent += $this->expiredHolds();
        $sent += $this->coldQuotes();

        $this->info("Follow-ups enviados: {$sent}");

        return self::SUCCESS;
    }

    /** Reserva confirmada por el hotel → lead ganado + felicitación. */
    protected function confirmedReservations(): int
    {
        $sent = 0;

        $conversations = Conversation::query()
            ->where('lead_status', Conversation::LEAD_HOLD)
            ->whereHas('reservation', fn ($q) => $q->whereIn('status', [
                ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::Completed,
            ]))
            ->with('reservation')
            ->get();

        foreach ($conversations as $conversation) {
            $conversation->markLead(Conversation::LEAD_WON);

            if (! $conversation->bot_enabled || $conversation->followupSent('confirmed')) {
                continue;
            }

            $reservation = $conversation->reservation;
            $this->send($conversation, 'confirmed', sprintf(
                '¡Buenas noticias! Tu reserva %s ya está confirmada para el %s. Te esperamos; si necesitas algo antes de tu llegada, aquí estoy.',
                $reservation->displayCode(),
                $reservation->starts_at->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm'),
            ));
            $sent++;
        }

        return $sent;
    }

    /** Hold pendiente que vence en los próximos minutos → recordatorio. */
    protected function holdsAboutToExpire(): int
    {
        $sent = 0;

        $conversations = Conversation::query()
            ->where('lead_status', Conversation::LEAD_HOLD)
            ->where('bot_enabled', true)
            ->whereHas('reservation', fn ($q) => $q
                ->where('status', ReservationStatus::Pending)
                ->whereBetween('hold_expires_at', [now()->addMinutes(2), now()->addMinutes(12)]))
            ->with('reservation')
            ->get();

        foreach ($conversations as $conversation) {
            if ($conversation->followupSent('hold_reminder')) {
                continue;
            }

            $reservation = $conversation->reservation;
            $this->send($conversation, 'hold_reminder', sprintf(
                'Recuerda: tu apartado %s vence a las %s. Si sigues interesado responde este mensaje y aviso a recepción para que lo confirmen.',
                $reservation->displayCode(),
                $reservation->hold_expires_at->format('H:i'),
            ));
            $sent++;
        }

        return $sent;
    }

    /** Hold que venció sin confirmarse → lead perdido + oferta de retomar. */
    protected function expiredHolds(): int
    {
        $sent = 0;

        $conversations = Conversation::query()
            ->where('lead_status', Conversation::LEAD_HOLD)
            ->whereHas('reservation', fn ($q) => $q
                ->whereIn('status', [ReservationStatus::Cancelled, ReservationStatus::NoShow]))
            ->with('reservation')
            ->get();

        foreach ($conversations as $conversation) {
            $conversation->markLead(Conversation::LEAD_LOST);

            if (! $conversation->bot_enabled || $conversation->followupSent('hold_expired')) {
                continue;
            }

            $this->send($conversation, 'hold_expired', sprintf(
                'Tu apartado %s venció y la habitación se liberó. Si aún te interesa, dime y con gusto te ayudo a hacer uno nuevo (las fechas pueden seguir disponibles).',
                $conversation->reservation->displayCode(),
            ));
            $sent++;
        }

        return $sent;
    }

    /**
     * Cotizó y dejó de responder (el último mensaje es nuestro, 20 min–3 h
     * de silencio) → un solo reenganche amable.
     */
    protected function coldQuotes(): int
    {
        $sent = 0;

        $conversations = Conversation::query()
            ->where('lead_status', Conversation::LEAD_QUOTING)
            ->where('status', Conversation::STATUS_OPEN)
            ->where('bot_enabled', true)
            ->whereBetween('last_message_at', [now()->subHours(3), now()->subMinutes(20)])
            ->get();

        foreach ($conversations as $conversation) {
            if ($conversation->followupSent('quote_nudge')) {
                continue;
            }

            // Solo si el silencio es del huésped (nuestro mensaje quedó al final).
            $last = $conversation->messages()->latest('id')->first();
            if (! $last || $last->direction !== 'out') {
                continue;
            }

            $this->send($conversation, 'quote_nudge',
                '¿Sigues por ahí? Quedé pendiente de ayudarte con tu reserva. Si quieres, te aparto la habitación unos minutos sin compromiso; solo dime la fecha y la tarifa que te interesó.',
            );
            $sent++;
        }

        return $sent;
    }

    protected function send(Conversation $conversation, string $key, string $body): void
    {
        $conversation->messages()->create([
            'direction' => 'out',
            'sender_type' => 'bot',
            'body' => $body,
            'meta' => ['followup' => $key],
            'created_at' => now(),
        ]);

        $conversation->markFollowup($key);
        $conversation->update(['last_message_at' => now()]);

        // Canales Meta: el follow-up también llega al teléfono del huésped.
        // OJO producción WhatsApp: fuera de la ventana de 24 h requerirá
        // plantilla aprobada (por ahora los follow-ups caen dentro).
        app(\App\Services\Meta\MetaApi::class)->pushToConversation($conversation, $body);
    }
}
