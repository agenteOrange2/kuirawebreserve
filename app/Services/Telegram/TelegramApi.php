<?php

namespace App\Services\Telegram;

use App\Models\Central\TelegramChannelLink;
use App\Models\Channel;
use App\Models\Conversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envío saliente por la Bot API de Telegram — el equivalente de MetaApi
 * para el tipo de canal telegram. Nunca lanza: si Telegram falla, el
 * mensaje queda en la conversación y se reporta.
 */
class TelegramApi
{
    public const BASE_URL = 'https://api.telegram.org';

    public function sendText(TelegramChannelLink $link, string $chatId, string $text): bool
    {
        $trozos = \App\Services\Channels\MessageChunker::split(
            $text,
            \App\Services\Channels\MessageChunker::limitFor('telegram'),
        );

        if ($trozos === []) {
            return false;
        }

        foreach ($trozos as $trozo) {
            if (! $this->sendTextChunk($link, $chatId, $trozo)) {
                return false;
            }
        }

        return true;
    }

    protected function sendTextChunk(TelegramChannelLink $link, string $chatId, string $text): bool
    {
        try {
            $response = $this->http()->post($this->url($link, 'sendMessage'), [
                'chat_id' => $chatId,
                'text' => $text,
            ]);

            if ($response->failed() || $response->json('ok') !== true) {
                Log::warning('Telegram: envío fallido', [
                    'link_id' => $link->id,
                    'tenant' => $link->tenant_id,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * "escribiendo..." mientras el bot piensa. Telegram lo apaga solo a los
     * 5 segundos o cuando llega el mensaje; para respuestas más lentas se
     * vuelve a mandar, pero con una vez basta para que no parezca muerto.
     */
    public function sendChatAction(TelegramChannelLink $link, string $chatId, string $action = 'typing'): bool
    {
        try {
            // Timeout corto: corre ANTES del modelo, dentro del webhook.
            $response = Http::timeout(5)->acceptJson()->post($this->url($link, 'sendChatAction'), [
                'chat_id' => $chatId,
                'action' => $action,
            ]);

            return $response->successful();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Adjunto saliente (foto o PDF que manda el staff desde la bandeja).
     * La Bot API recibe el archivo en el propio POST (multipart), así que
     * no hace falta exponerlo públicamente.
     */
    public function sendMedia(
        TelegramChannelLink $link,
        string $chatId,
        string $path,
        string $mime,
        string $fileName,
        ?string $caption = null,
    ): bool {
        try {
            $binary = @file_get_contents($path);

            if ($binary === false) {
                Log::warning('Telegram: adjunto ilegible', ['path' => $path]);

                return false;
            }

            $isImage = str_starts_with($mime, 'image/');
            $method = $isImage ? 'sendPhoto' : 'sendDocument';
            $field = $isImage ? 'photo' : 'document';

            $response = $this->http()
                ->attach($field, $binary, $fileName)
                ->post($this->url($link, $method), array_filter([
                    'chat_id' => $chatId,
                    'caption' => $caption,
                ], fn ($value) => $value !== null && $value !== ''));

            if ($response->failed() || $response->json('ok') !== true) {
                Log::warning('Telegram: adjunto no enviado', [
                    'link_id' => $link->id,
                    'tenant' => $link->tenant_id,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Identidad del bot (getMe): valida el token al conectar y da el
     *
     * @username que se muestra en el panel.
     *
     * @return array{ok: bool, username: string|null}
     */
    public function getMe(TelegramChannelLink $link): array
    {
        try {
            $response = $this->http()->get($this->url($link, 'getMe'));

            if ($response->failed() || $response->json('ok') !== true) {
                return ['ok' => false, 'username' => null];
            }

            return ['ok' => true, 'username' => $response->json('result.username')];
        } catch (Throwable) {
            return ['ok' => false, 'username' => null];
        }
    }

    /** Registra el webhook del bot hacia la plataforma (solo mensajes). */
    public function configureWebhook(TelegramChannelLink $link): bool
    {
        try {
            $response = $this->http()->post($this->url($link, 'setWebhook'), [
                'url' => $link->webhookUrl(),
                'allowed_updates' => ['message'],
            ]);

            if ($response->failed() || $response->json('ok') !== true) {
                Log::warning('Telegram: no se pudo configurar el webhook', [
                    'link_id' => $link->id,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Descarga el binario de un archivo entrante (foto/documento) en dos
     * pasos: getFile → file_path → descarga del CDN de la Bot API.
     *
     * @return array{contents: string, mime: string}|null
     */
    public function downloadFile(TelegramChannelLink $link, string $fileId): ?array
    {
        try {
            $response = $this->http()->get($this->url($link, 'getFile'), ['file_id' => $fileId]);

            $filePath = $response->json('result.file_path');

            if ($response->failed() || ! $filePath) {
                return null;
            }

            $file = Http::timeout(20)->get(
                self::BASE_URL.'/file/bot'.$link->bot_token.'/'.$filePath,
            );

            if ($file->failed()) {
                return null;
            }

            return [
                'contents' => $file->body(),
                'mime' => $file->header('Content-Type') ?: 'application/octet-stream',
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Envía a la persona detrás de una conversación de un canal Telegram.
     * El bot exacto se resuelve por channels.external_id (id del link
     * central), igual que Evolution.
     */
    public function pushToConversation(Conversation $conversation, string $text): bool
    {
        $link = $this->linkFor($conversation);

        return $link && $conversation->contact_phone
            ? $this->sendText($link, $conversation->contact_phone, $text)
            : false;
    }

    /** Adjunto por conversación (resuelve el bot del canal). */
    public function pushMediaToConversation(
        Conversation $conversation,
        string $path,
        string $mime,
        string $fileName,
        ?string $caption = null,
    ): bool {
        $link = $this->linkFor($conversation);

        return $link && $conversation->contact_phone
            ? $this->sendMedia($link, $conversation->contact_phone, $path, $mime, $fileName, $caption)
            : false;
    }

    protected function linkFor(Conversation $conversation): ?TelegramChannelLink
    {
        $channel = $conversation->channel;

        if ($channel?->type !== Channel::TYPE_TELEGRAM) {
            return null;
        }

        return TelegramChannelLink::query()
            ->whereKey($channel->external_id)
            ->where('active', true)
            ->first();
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(15)->acceptJson();
    }

    protected function url(TelegramChannelLink $link, string $method): string
    {
        return self::BASE_URL.'/bot'.$link->bot_token.'/'.$method;
    }
}
