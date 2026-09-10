<?php

namespace App\Services\Channels;

use App\Models\Conversation;
use App\Services\Agent\VoiceTranscriber;

/**
 * Qué hacer con lo que llega y no es texto (nota de voz, video, sticker).
 *
 * Antes esto se tiraba: en Messenger el evento ni siquiera creaba mensaje,
 * así que el huésped que contestaba con un audio quedaba en silencio total
 * y encima le caía el "¿sigues por ahí?" del seguimiento (caso real cabañas,
 * conversación 32 del 2026-09-04). Ahora siempre queda rastro en la bandeja:
 *
 * - Nota de voz con transcripción disponible: el texto dictado ENTRA al
 *   flujo normal y el bot responde como si lo hubiera escrito.
 * - Todo lo demás (o si la transcripción falla): se guarda el marcador, se
 *   avisa al huésped que por ahora solo se lee texto y la conversación pasa
 *   a espera humana.
 */
class InboundVoiceService
{
    public const KIND_AUDIO = 'audio';

    public const KIND_VIDEO = 'video';

    public const KIND_STICKER = 'sticker';

    /** Etiqueta que ve el staff en la bandeja cuando no hubo texto. */
    public const PLACEHOLDERS = [
        self::KIND_AUDIO => '[Nota de voz]',
        self::KIND_VIDEO => '[Video]',
        self::KIND_STICKER => '[Sticker]',
    ];

    public function __construct(
        protected VoiceTranscriber $transcriber,
        protected OutboundMessenger $messenger,
    ) {}

    /**
     * Etiqueta del mensaje cuando no viene texto. Imagen y documento
     * conservan las suyas de siempre: el flujo de comprobantes se apoya en
     * esas dos cadenas exactas.
     *
     * @param  string  $type  Nombre crudo del canal, para lo que no se reconoce.
     */
    public static function label(?string $kind, string $type = ''): string
    {
        return match ($kind) {
            'image' => '[Imagen]',
            'file' => '[Documento]',
            null => $type !== '' ? '['.$type.' no soportado todavía]' : '[Mensaje sin texto]',
            default => self::PLACEHOLDERS[$kind] ?? '[Mensaje sin texto]',
        };
    }

    /**
     * Resuelve el cuerpo del mensaje entrante ANTES de guardarlo. El
     * descargador se pasa como callable porque cada canal baja el binario a
     * su manera (Graph API, CDN de Meta, base64 de Evolution, Bot API) y no
     * tiene caso bajar nada si ni siquiera hay con qué transcribir.
     *
     * @param  callable():(array{contents: string, mime: string}|null)|null  $download
     * @param  int|null  $seconds  Duración declarada por el canal, si la manda.
     * @return array{body: string, meta: array<string, mixed>, transcribed: bool}
     */
    public function interpret(string $kind, ?callable $download = null, ?int $seconds = null): array
    {
        $placeholder = self::PLACEHOLDERS[$kind] ?? '[Mensaje sin texto]';

        if ($kind !== self::KIND_AUDIO || $download === null || ! $this->transcriber->isConfigured()) {
            return ['body' => $placeholder, 'meta' => ['unsupported_media' => $kind], 'transcribed' => false];
        }

        $binary = $download();
        $text = $binary
            ? $this->transcriber->transcribe($binary['contents'], $binary['mime'], $seconds)
            : null;

        if ($text === null) {
            return ['body' => $placeholder, 'meta' => ['unsupported_media' => $kind], 'transcribed' => false];
        }

        return [
            'body' => $text,
            // La bandeja marca el mensaje como dictado: el staff tiene que
            // saber que ese texto lo escribió una máquina oyendo, no la
            // persona (un "domingo 6" mal oído se paga caro).
            'meta' => ['voice_note' => true, 'seconds' => $seconds],
            'transcribed' => true,
        ];
    }

    /**
     * Aviso de "por aquí solo texto" y la conversación a espera humana: el
     * huésped SÍ contestó, aunque nosotros no podamos leerlo.
     */
    public function askForText(Conversation $conversation, string $kind): void
    {
        $body = match ($kind) {
            self::KIND_AUDIO => 'Recibí tu nota de voz, pero por ahora solo puedo leer mensajes escritos. ¿Me lo escribes por aquí y seguimos? Si prefieres, en un momento te contesta alguien del equipo.',
            self::KIND_VIDEO => 'Recibí tu video, pero por ahora solo puedo leer mensajes escritos. Cuéntame por texto en qué te ayudo y seguimos.',
            default => 'Recibí tu mensaje, pero por ahora solo puedo leer texto. ¿Me lo escribes por aquí y seguimos?',
        };

        $conversation->messages()->create([
            'direction' => 'out',
            'sender_type' => 'system',
            'body' => $body,
            // Marca para el seguimiento: este aviso no cuenta como una
            // cotización enfriándose, así que no debe disparar el nudge.
            'meta' => ['unsupported_media_notice' => $kind],
            'created_at' => now(),
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'status' => Conversation::STATUS_PENDING,
        ]);

        $this->messenger->pushToConversation($conversation, $body);
    }
}
