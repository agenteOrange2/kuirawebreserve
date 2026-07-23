<?php

namespace App\Mail;

use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo genérico al huésped NO atado a una reserva de habitación
 * (experiencias, grupos, avisos sueltos): asunto + cuerpo + una lista
 * opcional de "detalles" (folio, fecha, personas, total). Funciona en
 * cuanto el hotel configure su SMTP; mientras tanto va al log sin romper.
 * El de habitaciones sigue siendo GuestReservationMail (adjunta .ics).
 */
class GuestNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{label: string, value: string}>  $details
     */
    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public string $code = '',
        public array $details = [],
    ) {}

    public function envelope(): Envelope
    {
        $hotel = Property::query()->first()?->name;

        return new Envelope(
            subject: trim($this->subjectLine.($this->code ? " — {$this->code}" : '').($hotel ? " · {$hotel}" : '')),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.guest-notice',
            with: [
                'hotelName' => Property::query()->first()?->name ?? config('app.name'),
            ],
        );
    }
}
