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

            // Y solo si el huésped llegó a escribir: hay hilos que abre el
            // bot (respuesta privada a un comentario de redes) donde nadie
            // contestó nunca — ahí el "¿sigues por ahí?" es spam puro
            // (caso real cabañas, conversación 15 del 2026-08-28).
            $lastIn = $conversation->messages()->where('direction', 'in')->latest('id')->first();
            if (! $lastIn) {
                continue;
            }

            // Se despidió o dijo que él avisa cuando tenga fechas: no se
            // persigue a quien ya cerró la conversación por su cuenta.
            if ($this->closedTheChat($lastIn->body)) {
                continue;
            }

            // Si lo último que le dijimos fue que NO hay disponibilidad,
            // ofrecerle apartar "la habitación" es contradictorio: se
            // reengancha por la puerta correcta, que son otras fechas.
            $noVacancy = (bool) preg_match(
                '/no (hay|tenemos|contamos con|queda|quedan)[^.]{0,40}disponib|sin disponibilidad|todas[^.]{0,40}(reservadas|ocupadas)/iu',
                $last->body,
            );

            $this->send($conversation, 'quote_nudge', $noVacancy
                ? '¿Sigues por ahí? Para esas fechas no me quedó nada libre, pero con gusto reviso otras: dime qué días te acomodan y te digo lo que hay disponible.'
                : '¿Sigues por ahí? Quedé pendiente de ayudarte con tu reserva. Si me dices la fecha y la habitación que te interesó, reviso la disponibilidad y te ayudo a apartarla.',
            );
            $sent++;
        }

        return $sent;
    }

    /**
     * ¿El huésped ya cerró la conversación? ("gracias", "sería todo", "yo
     * le aviso cuando tenga la fecha"). Se mide sobre mensajes cortos para
     * no confundir un "buen día, ¿me da precios?" con una despedida.
     */
    protected function closedTheChat(string $body): bool
    {
        $body = trim($body);

        if (mb_strlen($body) > 140) {
            return false;
        }

        return (bool) preg_match(
            '/(gracias|ser[ií]a todo|es todo por (el momento|ahora|hoy)|hasta luego|nos vemos|'
            .'(te|le|les) aviso|(se )?l[oe] hago saber|luego (te|le) (aviso|escribo|marco)|'
            .'ah[ií] (te|le) (aviso|escribo)|quedamos as[ií])/iu',
            $body,
        );
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

        // El follow-up también llega al teléfono del huésped por el
        // transporte del canal (Meta o Evolution). OJO producción WhatsApp
        // Cloud: fuera de la ventana de 24 h requerirá plantilla aprobada
        // (por ahora los follow-ups caen dentro). Evolution no tiene esa
        // restricción de plantillas; ahí va con retraso humanizado (anti-ban:
        // es el bot iniciando contacto, el caso más delicado).
        app(\App\Services\Channels\OutboundMessenger::class)->pushToConversation(
            $conversation,
            $body,
            \App\Services\Evolution\EvolutionApi::humanDelay($body),
        );
    }
}
