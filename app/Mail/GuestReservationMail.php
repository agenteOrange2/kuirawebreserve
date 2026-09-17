<?php

namespace App\Mail;

use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo genérico al huésped sobre su reserva (confirmación, pago
 * recibido, recordatorio de saldo...): el cuerpo lo pone quien avisa y el
 * correo agrega siempre el resumen de la reserva. Funciona en cuanto el
 * hotel configure su SMTP (MAIL_*); mientras tanto va al log sin romper.
 */
class GuestReservationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Reservation $reservation,
        public string $bodyText,
        public string $subjectLine,
        public bool $withCalendar = false,
        /** Contrato de hospedaje del hotel con los datos de ESTA reserva. */
        public bool $withContract = false,
    ) {}

    public function envelope(): Envelope
    {
        $hotel = Property::query()->first()?->name;

        return new Envelope(
            subject: trim("{$this->subjectLine} — {$this->reservation->displayCode()}".($hotel ? " · {$hotel}" : '')),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.guest-reservation',
            with: [
                'hotelName' => Property::query()->first()?->name ?? config('app.name'),
            ],
        );
    }

    /**
     * Evento de calendario adjunto (confirmaciones): un toque en el correo
     * y la estancia queda en la agenda del huésped.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];

        if ($this->withCalendar) {
            $attachments[] = Attachment::fromData(fn () => $this->calendarEvent(), 'reserva-'.$this->reservation->displayCode().'.ics')
                ->withMime('text/calendar');
        }

        // El contrato que el bot lleva prometiendo desde siempre: se adjunta
        // solo si el hotel capturó su texto (ver ReservationContract).
        if ($this->withContract) {
            $contract = app(\App\Services\Guests\ReservationContract::class);
            $pdf = $contract->pdf($this->reservation);

            if ($pdf !== null) {
                $attachments[] = Attachment::fromData(fn () => $pdf, $contract->filename($this->reservation))
                    ->withMime('application/pdf');
            }
        }

        return $attachments;
    }

    protected function calendarEvent(): string
    {
        $property = Property::query()->first();
        $code = $this->reservation->displayCode();
        $stamp = fn ($moment) => $moment->copy()->utc()->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//KuiraWebReserve//Reservas//ES',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            "UID:{$code}@kuirawebreserve.com",
            'DTSTAMP:'.$stamp(now()),
            'DTSTART:'.$stamp($this->reservation->starts_at),
            'DTEND:'.$stamp($this->reservation->ends_at),
            'SUMMARY:'.$this->icsEscape("Reserva {$code} — ".($property?->name ?? 'Hotel')),
            'LOCATION:'.$this->icsEscape((string) ($property?->address ?? '')),
            'DESCRIPTION:'.$this->icsEscape(($this->reservation->roomType?->name ?? 'Habitación').". Presenta tu código {$code} en recepción."),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines)."\r\n";
    }

    protected function icsEscape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    }
}
