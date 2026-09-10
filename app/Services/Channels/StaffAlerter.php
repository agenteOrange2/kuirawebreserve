<?php

namespace App\Services\Channels;

use App\Models\Conversation;
use App\Models\StaffNotification;
use App\Services\StaffNotifier;
use App\Services\SupportHours;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Aviso AL HOTEL de que una conversación necesita gente: el bot transfirió,
 * o entró una cotización fuera del horario de atención.
 *
 * La campana del panel ya existe, pero de noche nadie tiene el panel
 * abierto: esto sale además por WhatsApp (y correo de respaldo) al número
 * que el hotel puso en Datos generales — el mismo al que se mandan los
 * comprobantes. Avisar es cortesía: si falla, jamás rompe la conversación.
 */
class StaffAlerter
{
    /** El bot pasó la conversación a una persona. */
    public const KIND_HANDOFF = 'handoff';

    /** Alguien cotizó cuando ya no hay quien atienda. */
    public const KIND_AFTER_HOURS = 'after_hours_quote';

    public function __construct(
        protected DirectGuestMessenger $messenger,
        protected SupportHours $hours,
    ) {}

    /**
     * @return array{whatsapp: bool, email: bool, bell: bool}
     */
    public function alert(Conversation $conversation, string $kind, string $reason = ''): array
    {
        $result = ['whatsapp' => false, 'email' => false, 'bell' => false];

        // Un aviso por conversación, por tipo y por día: el hotel necesita
        // enterarse, no que le vibre el teléfono con cada mensaje del hilo.
        $key = "alert:{$kind}:".now()->toDateString();

        if ($conversation->followupSent($key)) {
            return $result;
        }

        $conversation->markFollowup($key);

        $title = $kind === self::KIND_HANDOFF
            ? 'El asistente pasó una conversación a recepción'
            : 'Cotización fuera de horario';

        try {
            $result['bell'] = (bool) app(StaffNotifier::class)->notify(
                type: StaffNotification::TYPE_MESSAGE,
                title: $title,
                body: $this->who($conversation).($reason !== '' ? ' — '.$reason : ''),
                url: '/bandeja',
                subject: $conversation,
            );
        } catch (Throwable $e) {
            report($e);
        }

        $sent = $this->messenger->sendToContactDetailed(
            $this->hours->alertPhone(),
            $this->hours->alertEmail(),
            $title,
            $this->body($conversation, $kind, $reason),
        );

        // Rastro: "no me llegó el aviso" se contesta mirando el log, no
        // adivinando si el canal de WhatsApp estaba conectado esa noche.
        Log::info('Aviso al hotel', [
            'conversation_id' => $conversation->id,
            'kind' => $kind,
            'phone' => $this->hours->alertPhone(),
            'whatsapp' => $sent['whatsapp'],
            'email' => $sent['email'],
        ]);

        return $sent + $result;
    }

    /** Texto que le llega al hotel por WhatsApp. */
    protected function body(Conversation $conversation, string $kind, string $reason): string
    {
        $last = $conversation->messages()
            ->where('direction', 'in')
            ->latest('id')
            ->value('body');

        $lines = [
            $kind === self::KIND_HANDOFF
                ? 'El asistente pasó una conversación a recepción.'
                : 'Entró una cotización fuera del horario de atención.',
            '',
            'Huésped: '.$this->who($conversation),
        ];

        if ($reason !== '') {
            $lines[] = 'Motivo: '.$reason;
        }

        if ($last) {
            $lines[] = 'Último mensaje: '.Str::limit(trim(preg_replace('/\s+/', ' ', $last)), 160);
        }

        if ($kind === self::KIND_AFTER_HOURS) {
            $lines[] = 'Ya se le avisó que el equipo retoma '.$this->hours->nextOpeningLabel().'.';
        }

        $lines[] = '';
        $lines[] = 'Contéstale en la bandeja: '.$this->inboxUrl();

        return implode("\n", $lines);
    }

    protected function who(Conversation $conversation): string
    {
        $name = $conversation->guest?->full_name
            ?: $conversation->contact_name
            ?: 'Sin nombre';

        $channel = $conversation->channel?->name ?: $conversation->channel?->type;

        return trim($name.($channel ? " ({$channel})" : ''));
    }

    /**
     * URL absoluta del panel: el aviso sale por WhatsApp, donde un link
     * relativo no sirve de nada. Mismo criterio que PaymentRequest.
     */
    protected function inboxUrl(): string
    {
        $domain = tenant()?->domains()->value('domain');

        if (! $domain) {
            return url('/bandeja');
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://{$domain}/bandeja";
    }
}
