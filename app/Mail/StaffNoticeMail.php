<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso al HOTEL (StaffAlerts): reserva nueva, pago, cancelación o salida.
 * Mismo encabezado con logo que el correo al huésped; el cuerpo es una
 * lista de datos y un botón a la ficha.
 */
class StaffNoticeMail extends Mailable
{
    use Queueable;

    /** @param  array<string, string|null>  $lines  renglones vacíos no se pintan */
    public function __construct(
        public string $subjectLine,
        public string $intro,
        public array $lines,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        $brand = TenantBranding::resolve();

        return new Envelope(
            from: $brand->fromAddress(),
            subject: trim("{$this->subjectLine} · {$brand->name}"),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.staff-notice',
            with: [
                'rows' => array_filter($this->lines, fn ($value) => $value !== null && $value !== ''),
                'hotelName' => TenantBranding::resolve()->name,
            ],
        );
    }
}
