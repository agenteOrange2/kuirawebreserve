<?php

namespace App\Services\Channels;

use App\Mail\GuestReservationMail;
use App\Models\Central\EvolutionChannelLink;
use App\Models\Central\MetaChannelLink;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Evolution\EvolutionApi;
use App\Services\Meta\MetaApi;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Aviso directo al huésped cuando NO hay conversación de por medio (el
 * caso del wizard web: deja teléfono y quizá correo, pero nunca escribió
 * por un canal). WhatsApp como vía principal — el hotel elige el canal en
 * /ajustes/metodos-pago: la API oficial de Meta, Evolution, o automático
 * (Meta primero y Evolution de respaldo; la Cloud API rechaza mensajes
 * libres fuera de la ventana de 24 h, y un huésped del wizard nunca ha
 * escrito, así que el respaldo importa). Correo como complemento si lo
 * dejó. Nunca truena el flujo que avisa: notificar es cortesía, no parte
 * de la transacción.
 */
class DirectGuestMessenger
{
    public function __construct(
        protected EvolutionApi $evolution,
        protected MetaApi $meta,
    ) {}

    public function send(Reservation $reservation, string $body, string $subject = 'Sobre tu reserva', bool $withCalendar = false, bool $withContract = false): bool
    {
        $whatsapp = $this->sendWhatsApp($reservation, $body);
        $correo = $this->sendEmail($reservation, $body, $subject, $withCalendar, $withContract);

        if (! $whatsapp && ! $correo) {
            $this->alertUndelivered(
                $reservation->guest_name ?: 'El huésped',
                (string) $reservation->guest?->phone,
                $body,
                $reservation,
            );
        }

        return $correo || $whatsapp;
    }

    /**
     * WhatsApp directo a un huésped del CRM sin reserva de habitación de
     * por medio (experiencias, avisos sueltos). Solo WhatsApp.
     */
    public function sendToGuest(?\App\Models\Guest $guest, string $body): bool
    {
        return $this->whatsAppTo((string) $guest?->phone, $body);
    }

    /**
     * WhatsApp + correo a un huésped sin reserva de habitación de por medio
     * (experiencias, grupos): mismo doble canal que send() pero con un
     * correo genérico (GuestNoticeMail), no el de reserva de habitación.
     * El WhatsApp sale si hay canal conectado; el correo si el huésped dejó
     * email y hay SMTP. Ninguno rompe el flujo.
     *
     * @param  array<int, array{label: string, value: string}>  $details
     */
    public function sendToGuestFull(?\App\Models\Guest $guest, string $subject, string $body, string $code = '', array $details = []): bool
    {
        $whatsapp = $this->whatsAppTo((string) $guest?->phone, $body);
        $correo = $this->noticeEmailTo($guest?->email, $subject, $body, $code, $details);

        if (! $whatsapp && ! $correo) {
            $this->alertUndelivered(
                $guest?->full_name ?: 'El huésped',
                (string) $guest?->phone,
                $body,
            );
        }

        return $correo || $whatsapp;
    }

    /**
     * WhatsApp + correo a un contacto suelto que NO existe (todavía) en el
     * CRM — el caso de la lista de espera: dejó nombre y teléfono/correo en
     * el wizard sin llegar a reservar. Mismo doble canal que
     * sendToGuestFull, sin Guest de por medio.
     */
    public function sendToContact(?string $phone, ?string $email, string $subject, string $body): bool
    {
        return in_array(true, $this->sendToContactDetailed($phone, $email, $subject, $body), true);
    }

    /**
     * Igual que sendToContact pero dice QUÉ canal salió. Lo usa la lista de
     * espera: sellar "Avisado" sin saber si el mensaje salió convierte un
     * prospecto perdido en uno que la pantalla da por atendido.
     *
     * @return array{whatsapp: bool, email: bool}
     */
    public function sendToContactDetailed(?string $phone, ?string $email, string $subject, string $body): array
    {
        return [
            'whatsapp' => $this->whatsAppTo((string) $phone, $body),
            'email' => $this->noticeEmailTo($email ?: null, $subject, $body, '', []),
        ];
    }

    /**
     * Ni WhatsApp ni correo: hasta hoy eso solo dejaba una línea en el log y
     * nadie en el hotel se enteraba de que el huésped jamás recibió su
     * confirmación, su cobro o su recordatorio. Ahora suena la campana con
     * el teléfono a la vista para hablarle por otra vía.
     */
    protected function alertUndelivered(string $quien, ?string $phone, string $body, ?Reservation $reservation = null): void
    {
        try {
            app(\App\Services\StaffNotifier::class)->notify(
                type: \App\Models\StaffNotification::TYPE_MESSAGE,
                title: 'Aviso no entregado',
                body: $quien.' no recibió el mensaje'.($phone ? " (tel. {$phone})" : '').': '
                    .\Illuminate\Support\Str::limit(trim($body), 90).' Contáctalo por otra vía.',
                url: $reservation ? '/reservas/'.$reservation->id : '/bandeja',
                subject: $reservation,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function noticeEmailTo(?string $email, string $subject, string $body, string $code, array $details): bool
    {
        if (! $email) {
            return false;
        }

        try {
            $mailer = app(\App\Services\TenantMailer::class)->mailer();

            ($mailer ?? Mail::mailer())->to($email)
                ->send(new \App\Mail\GuestNoticeMail($subject, $body, $code, $details));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    protected function sendWhatsApp(Reservation $reservation, string $body): bool
    {
        // El contacto vive en el Guest ligado a la reserva.
        return $this->whatsAppTo((string) $reservation->guest?->phone, $body);
    }

    /**
     * El número con el que esa persona YA nos escribió por WhatsApp.
     *
     * `contact_phone` de una conversación de WhatsApp es el wa_id que manda
     * Meta: trae la lada de país de verdad (1 para Estados Unidos, 52 para
     * México) y no hay nada que adivinar. Se busca por los últimos 10
     * dígitos, que es lo único que el hotel teclea en la ficha.
     */
    protected function knownWhatsAppNumber(string $digits): ?string
    {
        if (strlen($digits) < 10) {
            return null;
        }

        $ultimos = substr($digits, -10);

        $conversation = \App\Models\Conversation::query()
            ->whereHas('channel', fn ($query) => $query->whereIn('type', ['whatsapp', \App\Models\Channel::TYPE_WHATSAPP_EVOLUTION]))
            ->where('contact_phone', 'like', '%'.$ultimos)
            ->latest('last_message_at')
            ->value('contact_phone');

        $limpio = $conversation === null ? '' : (preg_replace('/\D+/', '', $conversation) ?? '');

        return strlen($limpio) >= 11 ? $limpio : null;
    }

    protected function whatsAppTo(string $rawPhone, string $body): bool
    {
        $phone = preg_replace('/\D+/', '', $rawPhone);

        if ($phone === '') {
            return false;
        }

        $settings = Property::query()->first()?->settings ?? [];

        // El wizard pide "10 dígitos": sin lada de país WhatsApp no enruta.
        // ANTES de adivinar la lada se busca el número REAL con el que esa
        // persona nos escribe por WhatsApp: ese no se discute, lo dio Meta.
        // Sin esto, a los huéspedes de El Paso se les armaba "52 + 915…" y
        // sus avisos morían con el error 131026 (88 entregas rechazadas entre
        // el 12 y el 24 de septiembre).
        $phone = $this->knownWhatsAppNumber($phone)
            ?? \App\Support\Phone::whatsapp($phone, (string) ($settings['phone_country_code'] ?? '52'));

        // Un número imposible ni siquiera llega a la API: la Cloud API
        // contesta "(#131009) el formato del número de teléfono es
        // incorrecto" y el aviso se pierde en el log. En cabañas hay fichas
        // con "656" y "+5213" (2026-09-15).
        if (strlen($phone) < 11 || strlen($phone) > 15) {
            \Illuminate\Support\Facades\Log::warning('Aviso directo: teléfono no enviable', [
                'telefono' => $rawPhone,
            ]);

            return false;
        }

        $preference = $settings['direct_notify_channel'] ?? 'auto';

        foreach ($this->transports($preference) as $try) {
            if ($try($phone, $body)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Transportes en orden de intento según la preferencia del hotel. Solo
     * el canal Meta tipo `whatsapp` sirve aquí: Messenger e Instagram no
     * pueden iniciar chat con un número de teléfono.
     *
     * @return array<int, callable(string, string): bool>
     */
    protected function transports(string $preference): array
    {
        $viaMeta = function (string $phone, string $body): bool {
            $link = MetaChannelLink::query()
                ->where('tenant_id', (string) tenant('id'))
                ->where('type', 'whatsapp')
                ->where('active', true)
                ->orderBy('id')
                ->first();

            if (! $link) {
                return false;
            }

            // Sin ventana de 24 h abierta con ese número, la Cloud API acepta
            // y luego rechaza (#131047): "salió" por WhatsApp, el correo y la
            // campana ya no se activaban y el aviso moría sin que nadie lo
            // supiera. Se salta Meta y se prueba lo siguiente.
            if (! $this->metaWindowOpen($phone)) {
                return false;
            }

            try {
                return $this->meta->sendText($link, $phone, $body);
            } catch (Throwable $e) {
                report($e);

                return false;
            }
        };

        $viaEvolution = function (string $phone, string $body): bool {
            $link = EvolutionChannelLink::query()
                ->where('tenant_id', (string) tenant('id'))
                ->where('active', true)
                ->orderBy('id')
                ->first();

            if (! $link) {
                return false;
            }

            try {
                return $this->evolution->sendText($link, $phone, $body);
            } catch (Throwable $e) {
                report($e);

                return false;
            }
        };

        return match ($preference) {
            'meta' => [$viaMeta],
            'evolution' => [$viaEvolution],
            default => [$viaMeta, $viaEvolution],
        };
    }

    /** ¿Ese número le escribió al WhatsApp oficial en las últimas 24 h? */
    protected function metaWindowOpen(string $phone): bool
    {
        $last10 = substr(preg_replace('/\D+/', '', $phone) ?? '', -10);

        if (strlen($last10) < 10) {
            return false;
        }

        return \App\Models\Conversation::query()
            ->whereHas('channel', fn ($q) => $q->where('type', 'whatsapp'))
            ->where('contact_phone', 'like', '%'.$last10)
            ->whereHas('messages', fn ($q) => $q
                ->where('direction', 'in')
                ->where('created_at', '>=', now()->subHours(24)))
            ->exists();
    }

    /**
     * Solo correo, sin WhatsApp: el aviso ya salió por el chat y lo que falta
     * es el respaldo escrito con su contrato adjunto.
     */
    public function mailTo(Reservation $reservation, string $body, string $subject, bool $withCalendar = false, bool $withContract = false): bool
    {
        return $this->sendEmail($reservation, $body, $subject, $withCalendar, $withContract);
    }

    protected function sendEmail(Reservation $reservation, string $body, string $subject, bool $withCalendar = false, bool $withContract = false): bool
    {
        $email = $reservation->guest?->email;

        if (! $email) {
            // El contrato SOLO viaja por correo: sin correo no sale, y hasta
            // hoy eso no dejaba rastro en ninguna parte. El WhatsApp de la
            // confirmación sí salía, así que alertUndelivered() tampoco se
            // disparaba y el hotel daba por enviado un contrato que nunca
            // existió (cabañas 2026-09-18, Daysi Gómez RES-2026-1773).
            if ($withContract) {
                $this->alertContractNotSent($reservation, 'no tiene correo en su ficha');
            }

            return false;
        }

        try {
            // SMTP propio del hotel si lo configuró; si no, el default.
            $mailer = app(\App\Services\TenantMailer::class)->mailer();

            ($mailer ?? Mail::mailer())->to($email)->send(new GuestReservationMail($reservation, $body, $subject, $withCalendar, $withContract));

            if ($withContract) {
                // Queda en la historia de la reserva, y de ahí lo lee la
                // tarjeta de la ficha. Se registra AQUÍ y no en quien manda
                // porque el contrato sale por dos caminos —la confirmación
                // automática y el botón de recepción—: si solo se anotara el
                // botón, la ficha diría "Sin enviar" de un contrato que ya
                // llegó, y alguien lo mandaría dos veces.
                $this->logContractSent($reservation, $email);
            }

            return true;
        } catch (Throwable $e) {
            report($e);

            if ($withContract) {
                $this->alertContractNotSent($reservation, "no recibió el correo ({$email}): el envío falló");
            }

            return false;
        }
    }

    /**
     * Constancia de que el contrato salió: quién lo mandó (o el sistema, si
     * fue la confirmación automática) y a qué correo.
     */
    protected function logContractSent(Reservation $reservation, string $email): void
    {
        try {
            activity('reservation')
                ->performedOn($reservation)
                ->causedBy(auth()->user())
                ->log("Contrato de hospedaje enviado a {$email}");
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Campana cuando un contrato de hospedaje se quedó sin salir. Solo para
     * correos que lo llevaban adjunto: que un aviso cualquiera no tenga
     * correo es normal y no merece interrumpir a nadie; que un huésped con
     * reserva confirmada se quede sin su contrato, sí. Desde la ficha se
     * captura el correo y se manda (ReservationContractController).
     */
    protected function alertContractNotSent(Reservation $reservation, string $motivo): void
    {
        try {
            if (! app(\App\Services\Guests\ReservationContract::class)->available()) {
                // El hotel no tiene contrato capturado: no faltó nada.
                return;
            }

            app(\App\Services\StaffNotifier::class)->notify(
                type: \App\Models\StaffNotification::TYPE_RESERVATION,
                title: 'Contrato sin enviar · '.$reservation->displayCode(),
                body: ($reservation->guest_name ?: 'El huésped').' '.$motivo
                    .', así que su contrato de hospedaje no salió. Ábrela para capturar el correo y enviarlo.',
                url: '/reservas/'.$reservation->id,
                subject: $reservation,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
