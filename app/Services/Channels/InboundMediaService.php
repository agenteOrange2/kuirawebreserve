<?php

namespace App\Services\Channels;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\PaymentRequest;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Medios entrantes de WhatsApp (spec-pendientes §4.4 P2, flujo-bot-pagos
 * §7.6): la imagen o PDF que manda el huésped se guarda como adjunto del
 * mensaje y, si su reserva tiene una transferencia POR VERIFICAR sin
 * comprobante, se copia solita como comprobante de esa solicitud — el
 * staff la ve directo en /pagos sin tocar nada. El bot NUNCA intenta
 * "leer" una foto: o se agradece el comprobante o se pasa a un humano.
 */
class InboundMediaService
{
    public const OUTCOME_RECEIPT = 'receipt';

    public const OUTCOME_STORED = 'stored';

    public function __construct(protected OutboundMessenger $messenger) {}

    /**
     * Guarda el binario y encadena el destino del adjunto. Devuelve el
     * desenlace (receipt|stored) o null si el tipo no se soporta o falló.
     */
    public function handle(Conversation $conversation, Message $message, string $contents, string $mime, ?string $filename = null): ?string
    {
        $media = $this->attach($message, $contents, $mime, $filename);

        if (! $media) {
            return null;
        }

        if ($this->attachAsReceipt($conversation, $media)) {
            $this->acknowledgeReceipt($conversation);

            return self::OUTCOME_RECEIPT;
        }

        // Sin cobro que verificar, una foto requiere ojos humanos: la
        // conversación pasa a "espera humano" (el bot no ve imágenes).
        if ($conversation->status !== Conversation::STATUS_PENDING) {
            $conversation->update(['status' => Conversation::STATUS_PENDING]);
        }

        return self::OUTCOME_STORED;
    }

    protected function attach(Message $message, string $contents, string $mime, ?string $filename): ?Media
    {
        $extension = match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'pdf') => 'pdf',
            default => null,
        };

        if ($extension === null || $contents === '') {
            return null;
        }

        try {
            return $message->addMediaFromString($contents)
                ->usingFileName($filename ?: 'whatsapp-'.now()->format('YmdHis').'.'.$extension)
                ->toMediaCollection('attachments');
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * ¿Este adjunto es el comprobante que se estaba esperando? Solo si la
     * conversación está ligada a una reserva con transferencia pendiente
     * y la solicitud aún no tiene comprobante (el primero gana; el staff
     * puede reemplazarlo al aprobar si llegó otro mejor).
     */
    protected function attachAsReceipt(Conversation $conversation, Media $media): bool
    {
        if (! $conversation->reservation_id) {
            return false;
        }

        $request = PaymentRequest::query()
            ->where('reservation_id', $conversation->reservation_id)
            ->where('method', PaymentRequest::METHOD_TRANSFER)
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->latest('id')
            ->first();

        if (! $request || $request->getFirstMedia('receipt')) {
            return false;
        }

        try {
            $request->addMedia($media->getPath())
                ->preservingOriginal()
                ->usingFileName($media->file_name)
                ->toMediaCollection('receipt');

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Rescate post-rechazo: el huésped mandó el comprobante bueno por el
     * chat ANTES de que el staff reemitiera el cobro (la solicitud
     * rechazada ya no adjunta nada). Al reemitir, la última imagen/PDF
     * del hilo (72 h) se adjunta a la solicitud nueva — aprobar queda a
     * un clic, sin descargar ni resubir.
     */
    public function rescueLatestAttachment(PaymentRequest $request): bool
    {
        if (! $request->reservation_id || $request->getFirstMedia('receipt')) {
            return false;
        }

        $conversation = Conversation::query()
            ->where('reservation_id', $request->reservation_id)
            ->latest('id')
            ->first();

        if (! $conversation) {
            return false;
        }

        $message = $conversation->messages()
            ->where('direction', 'in')
            ->where('created_at', '>=', now()->subHours(72))
            ->whereHas('media')
            ->latest('id')
            ->first();

        $media = $message?->getFirstMedia('attachments');

        if (! $media) {
            return false;
        }

        try {
            $request->addMedia($media->getPath())
                ->preservingOriginal()
                ->usingFileName($media->file_name)
                ->toMediaCollection('receipt');

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Acuse SIN dar el pago por recibido (regla dura de spec-pagos): solo
     * se confirma que el comprobante llegó y que un humano lo verificará.
     */
    protected function acknowledgeReceipt(Conversation $conversation): void
    {
        $code = $conversation->reservation?->displayCode();
        $body = 'Recibimos tu comprobante'.($code ? " de la reserva {$code}" : '').'. El hotel lo verificará y te confirmaremos por aquí en cuanto quede registrado.';

        $conversation->messages()->create([
            'direction' => 'out',
            'sender_type' => 'system',
            'body' => $body,
            'created_at' => now(),
        ]);
        $conversation->update(['last_message_at' => now()]);

        $this->messenger->pushToConversation($conversation, $body);
    }
}
