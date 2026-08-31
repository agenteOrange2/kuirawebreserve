<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use App\Services\Agent\AgentBrain;
use App\Services\Evolution\EvolutionApi;
use Illuminate\Support\Facades\DB;

/**
 * Aviso de la lista de espera (módulo lista-espera): cuando una
 * cancelación o no-show libera fechas, se busca a quienes esperaban un
 * hueco solapado (del mismo tipo de habitación, o de cualquiera si no
 * eligieron tipo) y se les avisa por WhatsApp/correo con el link al wizard
 * para reservar. Cada entrada se reclama de forma atómica ANTES de mandar
 * — un aviso automático por entrada, nunca spam. Lo dispara
 * TransitionReservation::cancel fuera de su transacción: avisar es
 * cortesía, no puede revertir una cancelación.
 *
 * El sello solo se queda si el mensaje SALIÓ: si ningún canal lo tomó
 * (sin WhatsApp conectado, sin SMTP), la entrada regresa a "En espera"
 * con el motivo del fallo para que recepción lo vea y reintente a mano
 * (notifyNow) desde /lista-espera.
 *
 * Con el asistente activo el aviso lo manda ÉL: nace como conversación en
 * la bandeja, con el contexto de la espera en el resumen, y a partir de
 * ahí el bot sigue la plática solo (cotiza, aparta, cobra). Sin asistente
 * el aviso sale directo por WhatsApp/correo y nadie da seguimiento.
 */
class WaitlistNotifier
{
    public const SUBJECT = 'Se liberó espacio para tus fechas';

    /** Memo del nombre del hotel y del link al wizard: messageFor() se
     * llama una vez por renglón al pintar /lista-espera y no puede pegarle
     * a la base por fila. */
    protected ?string $hotelName = null;

    protected ?string $wizardUrl = null;

    protected bool $memoized = false;

    public function __construct(
        protected DirectGuestMessenger $direct,
        protected OutboundMessenger $outbound,
    ) {}

    public function roomFreed(Reservation $reservation): void
    {
        // Sin contexto de tenant no hay forma de resolver el módulo (solo
        // pasa en tests/CLI suelto — en producción cancel() siempre corre
        // bajo tenancy); con tenant, el módulo manda.
        $tenant = tenant();
        if ($tenant instanceof Tenant && ! $tenant->hasModule('lista-espera')) {
            return;
        }

        $entries = WaitlistEntry::query()
            ->waiting()
            ->where(fn ($q) => $q
                ->whereNull('room_type_id')
                ->orWhere('room_type_id', $reservation->room_type_id))
            ->overlappingDates($reservation->starts_at, $reservation->ends_at)
            ->get();

        foreach ($entries as $entry) {
            // Claim atómico: si otra corrida ya la selló, esta no manda.
            $claimed = WaitlistEntry::query()
                ->whereKey($entry->id)
                ->where('status', WaitlistEntry::STATUS_WAITING)
                ->update([
                    'status' => WaitlistEntry::STATUS_NOTIFIED,
                    'notified_at' => now(),
                    'notify_attempts' => DB::raw('notify_attempts + 1'),
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                continue;
            }

            // El claim se hizo por query builder: el modelo en memoria
            // todavía cree que está en espera y sin él la reversión no
            // sería un cambio para Eloquent.
            $entry->refresh();

            // Si no sale por ningún canal, vuelve a la espera: el hueco
            // sigue libre y el prospecto sigue sin enterarse.
            $this->deliver($entry, [
                'status' => WaitlistEntry::STATUS_WAITING,
                'notified_at' => null,
            ]);
        }
    }

    /**
     * Aviso a mano desde /lista-espera: reintento cuando el automático no
     * salió, o segundo toque cuando el interesado no contestó. A
     * diferencia del automático no exige estado "en espera" — lo pide una
     * persona que ya vio el renglón.
     */
    public function notifyNow(WaitlistEntry $entry): bool
    {
        $restore = [
            'status' => $entry->status,
            'notified_at' => $entry->notified_at,
        ];

        $entry->increment('notify_attempts');

        return $this->deliver($entry, $restore);
    }

    /**
     * Manda y deja constancia. Devuelve si salió por algún canal; si no,
     * devuelve la entrada al estado previo ($onFailure) con el motivo.
     *
     * @param  array{status: string, notified_at: mixed}  $onFailure
     */
    protected function deliver(WaitlistEntry $entry, array $onFailure): bool
    {
        $body = $this->messageFor($entry);
        $channels = [];

        // Primero el asistente: si contesta él, la plática queda abierta en
        // la bandeja en vez de morir en un mensaje suelto.
        $conversation = $this->deliverThroughAgent($entry, $body);

        if ($conversation) {
            $channels[] = 'agente';
        }

        $result = $this->direct->sendToContactDetailed(
            // El WhatsApp ya salió por el asistente: no mandarlo dos veces.
            $channels === [] ? $entry->guest_phone : null,
            $entry->guest_email,
            self::SUBJECT,
            $body,
        );

        $channels = [...$channels, ...array_keys(array_filter($result))];

        if ($channels === []) {
            $entry->forceFill([
                ...$onFailure,
                'notify_failed_at' => now(),
                'notify_error' => $this->failureReason($entry),
            ])->save();

            return false;
        }

        $entry->forceFill([
            // Una conversión ya cerrada no se degrada por un recordatorio.
            'status' => $entry->status === WaitlistEntry::STATUS_CONVERTED
                ? WaitlistEntry::STATUS_CONVERTED
                : WaitlistEntry::STATUS_NOTIFIED,
            'notified_at' => now(),
            'notified_channel' => implode('+', $channels),
            'notify_failed_at' => null,
            'notify_error' => null,
            // El hilo de la bandeja es la prueba de que el aviso salió.
            'conversation_id' => $conversation?->id ?? $entry->conversation_id,
        ])->save();

        return true;
    }

    /**
     * Entrega por el asistente: el aviso sale del WhatsApp del hotel y
     * queda como conversación en la bandeja con el bot encendido, así que
     * si la persona contesta "¿todavía hay?" le responde solo.
     *
     * Solo cuenta si el mensaje SALIÓ de verdad: primero se empuja al
     * transporte y apenas entonces se guarda en el hilo — un mensaje en la
     * bandeja que nunca salió es la misma mentira que sellar "Avisado".
     */
    protected function deliverThroughAgent(WaitlistEntry $entry, string $body): ?Conversation
    {
        $channel = $this->agentChannel();

        if (! $channel) {
            return null;
        }

        $phone = $entry->whatsappNumber($this->countryCode());

        if (! $phone) {
            return null;
        }

        $conversation = $this->conversationFor($channel, $entry, $phone);

        if (! $this->outbound->pushToConversation($conversation, $body, EvolutionApi::humanDelay($body))) {
            return null;
        }

        $conversation->messages()->create([
            'direction' => 'out',
            'sender_type' => 'bot',
            'body' => $body,
            'meta' => ['waitlist_entry_id' => $entry->id],
            'created_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);
        $conversation->markLead(Conversation::LEAD_QUOTING);

        return $conversation;
    }

    /**
     * Canal por el que puede hablar el asistente. Evolution primero: la
     * Cloud API oficial rechaza mensajes libres fuera de la ventana de 24 h
     * y quien se anotó en la lista de espera nunca escribió por WhatsApp.
     * Un canal en "off" no es asistente: ahí el aviso sale directo.
     */
    protected function agentChannel(): ?Channel
    {
        $tenant = tenant();

        if ($tenant instanceof Tenant && ! $tenant->hasModule('mensajeria')) {
            return null;
        }

        $channel = Channel::query()
            ->where('active', true)
            ->whereIn('type', [Channel::TYPE_WHATSAPP_EVOLUTION, 'whatsapp'])
            ->where('mode', '!=', 'off')
            ->orderByRaw('CASE WHEN type = ? THEN 0 ELSE 1 END', [Channel::TYPE_WHATSAPP_EVOLUTION])
            ->first();

        // Sin cerebro configurado el hilo quedaría abierto sin quien lo
        // conteste: mejor el aviso directo de siempre.
        return $channel && app(AgentBrain::class)->isConfigured() ? $channel : null;
    }

    /**
     * El hilo de esa persona en la bandeja. Si ya escribió alguna vez se
     * sigue EL SUYO — WhatsApp guarda los números de México con y sin el 1
     * (52 vs 521) y abrir otro hilo le borraría el historial al asistente.
     */
    protected function conversationFor(Channel $channel, WaitlistEntry $entry, string $phone): Conversation
    {
        $conversation = Conversation::query()
            ->where('channel_id', $channel->id)
            ->where('contact_phone', 'like', '%'.substr($phone, -10))
            ->latest('id')
            ->first();

        $conversation ??= Conversation::create([
            'channel_id' => $channel->id,
            'contact_phone' => $phone,
            'contact_name' => $entry->guest_name,
            'status' => Conversation::STATUS_OPEN,
            // El bot contesta solo donde el hotel lo dejó en automático.
            'bot_enabled' => $channel->mode === 'auto',
            'last_message_at' => now(),
        ]);

        if ($conversation->status === Conversation::STATUS_RESOLVED) {
            $conversation->update(['status' => Conversation::STATUS_OPEN]);
        }

        // El asistente lee el resumen en su prompt: que sepa de dónde sale
        // la plática y qué fechas buscaba esta persona.
        $context = 'Viene de la lista de espera: buscaba del '
            .$entry->starts_at->format('d/m/Y').' al '.$entry->ends_at->format('d/m/Y')
            .($entry->roomType?->name ? " en {$entry->roomType->name}" : ' en cualquier tipo de habitación')
            .', y se le avisó que se liberó espacio para esas fechas.';

        if (! str_contains((string) $conversation->summary, 'lista de espera')) {
            $conversation->update([
                'summary' => trim((string) $conversation->summary."\n".$context),
            ]);
        }

        return $conversation;
    }

    /** Lada del hotel para normalizar el teléfono (México por defecto). */
    protected function countryCode(): string
    {
        $settings = Property::query()->first()?->settings ?? [];

        return (string) ($settings['phone_country_code'] ?? '52');
    }

    /** Motivo corto y en español para la bitácora del renglón. */
    protected function failureReason(WaitlistEntry $entry): string
    {
        $tried = [];

        if (filled($entry->guest_phone)) {
            $tried[] = 'WhatsApp';
        }

        if (filled($entry->guest_email)) {
            $tried[] = 'correo';
        }

        if ($tried === []) {
            return 'La entrada no tiene teléfono ni correo';
        }

        return 'No salió por '.implode(' ni por ', $tried)
            .'. Revisa el canal en Ajustes / Métodos de pago y el correo del hotel.';
    }

    /**
     * El texto exacto que recibe el interesado. Público a propósito: el
     * panel lo usa para el link de WhatsApp manual (wa.me) y para que
     * recepción vea qué se manda, sin escribir dos versiones del mensaje.
     */
    public function messageFor(WaitlistEntry $entry): string
    {
        if (! $this->memoized) {
            $this->hotelName = Property::query()->value('name');
            $this->wizardUrl = $this->wizardUrl();
            $this->memoized = true;
        }

        $hotel = $this->hotelName;
        $link = $this->wizardUrl;

        $name = trim((string) $entry->guest_name);
        $body = ($name !== '' ? "Hola {$name}, buenas noticias" : 'Buenas noticias')
            .($hotel ? " de {$hotel}" : '')
            .': se liberó espacio para tus fechas ('
            .$entry->starts_at->locale('es')->isoFormat('D [de] MMMM')
            .' al '
            .$entry->ends_at->locale('es')->isoFormat('D [de] MMMM')
            .').';

        return $body.($link
            ? " Reserva aquí: {$link} — la disponibilidad vuela, te recomendamos apartar pronto."
            : ' Contáctanos para apartar tu lugar — la disponibilidad vuela.');
    }

    /**
     * URL pública del wizard (/reservar), SIEMPRE en el dominio del hotel —
     * mismo criterio que PaymentGuestNotifier::bookingLookupUrl (esto corre
     * también desde schedulers, donde route() hereda un host equivocado).
     * null si el hotel no tiene motor-web: la página respondería 403.
     */
    protected function wizardUrl(): ?string
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant || ! $tenant->hasModule('motor-web')) {
            return null;
        }

        $relative = route('tenant.booking.wizard', [], false);
        $domain = $tenant->domains()->value('domain');

        if (! $domain) {
            return url($relative);
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://{$domain}{$relative}";
    }
}
