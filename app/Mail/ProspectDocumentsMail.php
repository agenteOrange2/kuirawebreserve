<?php

namespace App\Mail;

use App\Models\Central\PlanProspect;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Correo de plataforma (dominio central) con los documentos comerciales
 * de los servicios que eligió el prospecto en el registro por QR.
 * Adjunta los PDF y además lista sus links públicos por si el cliente
 * de correo bloquea adjuntos; esos mismos links se usan por WhatsApp.
 */
class ProspectDocumentsMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, \App\Models\Central\ProspectDocument>  $documents
     */
    public function __construct(
        public PlanProspect $prospect,
        public Collection $documents,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Información de nuestros servicios — '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.prospect-documents',
            with: [
                'brandName' => config('app.name'),
                'serviceLabels' => $this->prospect->serviceLabels(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return $this->documents
            ->map(fn ($document) => Attachment::fromStorageDisk('local', $document->path)
                ->as($document->original_name)
                ->withMime($document->mime))
            ->all();
    }
}
