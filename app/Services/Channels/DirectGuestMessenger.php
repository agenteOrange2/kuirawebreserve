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

    public function send(Reservation $reservation, string $body, string $subject = 'Sobre tu reserva', bool $withCalendar = false): bool
    {
        $sent = $this->sendWhatsApp($reservation, $body);

        return $this->sendEmail($reservation, $body, $subject, $withCalendar) || $sent;
    }

    /**
     * WhatsApp directo a un huésped del CRM sin reserva de habitación de
     * por medio (experiencias, avisos sueltos). Solo WhatsApp: el correo
     * con resumen es específico de reservas de habitación.
     */
    public function sendToGuest(?\App\Models\Guest $guest, string $body): bool
    {
        return $this->whatsAppTo((string) $guest?->phone, $body);
    }

    protected function sendWhatsApp(Reservation $reservation, string $body): bool
    {
        // El contacto vive en el Guest ligado a la reserva.
        return $this->whatsAppTo((string) $reservation->guest?->phone, $body);
    }

    protected function whatsAppTo(string $rawPhone, string $body): bool
    {
        $phone = preg_replace('/\D+/', '', $rawPhone);

        if ($phone === '') {
            return false;
        }

        $settings = Property::query()->first()?->settings ?? [];

        // El wizard pide "10 dígitos": sin lada de país WhatsApp no enruta.
        // La lada default del hotel (México si no dice otra cosa) se antepone
        // solo cuando falta.
        if (strlen($phone) === 10) {
            $code = preg_replace('/\D+/', '', (string) ($settings['phone_country_code'] ?? '52'));
            $phone = $code.$phone;
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

    protected function sendEmail(Reservation $reservation, string $body, string $subject, bool $withCalendar = false): bool
    {
        $email = $reservation->guest?->email;

        if (! $email) {
            return false;
        }

        try {
            // SMTP propio del hotel si lo configuró; si no, el default.
            $mailer = app(\App\Services\TenantMailer::class)->mailer();

            ($mailer ?? Mail::mailer())->to($email)->send(new GuestReservationMail($reservation, $body, $subject, $withCalendar));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
