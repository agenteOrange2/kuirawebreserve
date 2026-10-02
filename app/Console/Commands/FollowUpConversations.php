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
            if ($conversation->followupSent('hold_reminder') || $this->staffTookOver($conversation)) {
                continue;
            }

            // La verdad, con la misma frase que dicen el bot y solicitar_pago.
            // Antes decía "responde y aviso a recepción para que lo
            // confirmen": no existía ningún aviso, y a las 8 de la noche
            // nadie de recepción iba a confirmar nada (cabañas 2026-09-13).
            $notice = app(\App\Services\ReservationPolicy::class)->holdDeadlineNotice($conversation->reservation);

            if ($notice === null) {
                continue;
            }

            $formal = app(\App\Services\ReservationPolicy::class)->formalAddress();
            $this->send($conversation, 'hold_reminder', ($formal ? 'Le recuerdo: ' : 'Recuerda: ').lcfirst($notice));
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

            // Solo un apartado que venció solo lleva este aviso: una reserva
            // que canceló el hotel (o un "no llegó") no "venció", y decírselo
            // al huésped es darle información falsa.
            // Tampoco si una persona del hotel ya habló con el huésped de este
            // apartado. Caso real cabañas conv. 605 (2026-09-12): el personal
            // escribió "si depositas mañana se te confirma" a las 23:03 y a
            // las 23:05 el aviso automático le dijo que venció.
            if (! $conversation->reservation->isExpiredHold()
                || ! $conversation->bot_enabled
                || $conversation->followupSent('hold_expired')
                || $this->staffTookOver($conversation)) {
                continue;
            }

            // El asistente puede reactivarlo con el mismo código
            // (reactivar_apartado): se le ofrece eso, no "hacer uno nuevo".
            $this->send($conversation, 'hold_expired', sprintf(
                app(\App\Services\ReservationPolicy::class)->formalAddress()
                    ? 'Su apartado %s venció y la habitación se liberó. Si ya realizó su pago o aún le interesa, respóndame y lo reactivo con el mismo código si la habitación sigue libre.'
                    : 'Tu apartado %s venció y la habitación se liberó. Si ya hiciste tu depósito o aún te interesa, respóndeme y lo reactivo con el mismo código si la habitación sigue libre.',
                $conversation->reservation->displayCode(),
            ));
            $sent++;
        }

        return $sent;
    }

    /**
     * ¿Una persona del hotel ya habló con el huésped desde que se hizo el
     * apartado? Entonces el apartado es suyo: los avisos automáticos no
     * contradicen lo que el personal le prometió.
     */
    protected function staffTookOver(Conversation $conversation): bool
    {
        $since = $conversation->reservation?->created_at;

        return $conversation->messages()
            ->where('sender_type', 'staff')
            ->when($since !== null, fn ($query) => $query->where('created_at', '>=', $since))
            ->exists();
    }

    /**
     * Cotizó y dejó de responder (el último mensaje es nuestro) → un solo
     * reenganche amable. Cuánto silencio se espera y a quién vale la pena
     * perseguir los decide cada hotel (ReservationPolicy).
     */
    protected function coldQuotes(): int
    {
        $sent = 0;

        // Este es el único seguimiento que NO es transaccional: es empujar
        // una venta. Mandarlo a las 3 de la mañana molesta al huésped y no
        // vende nada, así que respeta el horario de atención del hotel (los
        // avisos de apartado por vencer sí salen a cualquier hora: ahí el
        // silencio le cuesta la habitación).
        if (app(\App\Services\SupportHours::class)->isClosed()) {
            return 0;
        }

        $policy = app(\App\Services\ReservationPolicy::class);
        $silence = $policy->nudgeSilenceMinutes();
        $minMessages = $policy->nudgeMinVisitorMessages();

        $conversations = Conversation::query()
            ->where('lead_status', Conversation::LEAD_QUOTING)
            ->where('status', Conversation::STATUS_OPEN)
            ->where('bot_enabled', true)
            // La ventana conserva su ancho de 3 h por encima del silencio
            // exigido: el comando corre cada 5 minutos, pero el horario de
            // atención puede cerrar en medio y tragarse la oportunidad.
            ->whereBetween('last_message_at', [now()->subMinutes($silence + 180), now()->subMinutes($silence)])
            // Una sola consulta para todas: contar mensajes por fila sería
            // un N+1 en la lista completa de cotizaciones abiertas.
            ->withCount(['messages as visitor_messages_count' => fn ($query) => $query->where('direction', 'in')])
            ->get();

        foreach ($conversations as $conversation) {
            if ($conversation->followupSent('quote_nudge')) {
                continue;
            }

            // Solo se reengancha a quien llegó a una COTIZACIÓN REAL: la
            // herramienta confirmó una habitación libre para fechas
            // concretas. Contar mensajes no servía —aquí se escribe en
            // fragmentos, y "Buenas tardes"/"Para 2 personas"/"Mañana" ya
            // son tres— así que el filtro por conteo seguía persiguiendo a
            // quien solo preguntó (cabañas 2026-09-12, conv. 550 y 569).
            if (! $conversation->followupSent(\App\Services\Agent\AgentBrain::REAL_QUOTE)) {
                continue;
            }

            // Tope opcional por hotel; sirve además de apagador (un número
            // alto deja el reenganche sin nadie a quien escribirle).
            if ($minMessages > 0 && $conversation->visitor_messages_count < $minMessages) {
                continue;
            }

            // Solo si el silencio es del huésped (nuestro mensaje quedó al final).
            $last = $conversation->messages()->latest('id')->first();
            if (! $last || $last->direction !== 'out') {
                continue;
            }

            // Salvo que lo último nuestro haya sido el aviso de "por aquí
            // solo texto": ahí el huésped SÍ contestó (con una nota de voz
            // o un video) y preguntarle "¿sigues por ahí?" es justo lo que
            // lo hace sentir ignorado — la pelota es nuestra, no suya.
            if ($last->meta['unsupported_media_notice'] ?? false) {
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

            // Si lo último que le dijimos fue que NO hay disponibilidad, no
            // se le escribe: insistirle a quien acabamos de rechazar es lo
            // que más molesta (cabañas 2026-09-12, conv. 550 — se le dijo
            // dos veces que no había nada y el bot volvió a tocarle la
            // puerta dos horas después). Si el hotel quiere recuperar esas
            // fechas, es trabajo de una persona, no de una plantilla.
            $noVacancy = (bool) preg_match(
                '/no (hay|tenemos|contamos con|queda|quedan)[^.]{0,40}disponib|sin disponibilidad|todas[^.]{0,40}(reservadas|ocupadas)/iu',
                $last->body,
            );

            if ($noVacancy) {
                continue;
            }

            $this->send(
                $conversation,
                'quote_nudge',
                $policy->formalAddress()
                    ? '¿Sigue por ahí? Quedé pendiente de ayudarle con su reserva. Si me dice la fecha y la habitación que le interesó, reviso la disponibilidad y le ayudo a apartarla.'
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
