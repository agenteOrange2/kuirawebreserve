<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de prueba del SMTP de plataforma (/admin/settings/correo):
 * confirma que la configuración guardada realmente entrega.
 */
class PlatformTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Correo de prueba — '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.platform-test',
            with: [
                'brandName' => config('app.name'),
            ],
        );
    }
}
