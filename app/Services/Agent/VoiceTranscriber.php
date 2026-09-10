<?php

namespace App\Services\Agent;

use App\Models\Central\PlatformAiProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notas de voz a texto (STT). El huésped manda audio mucho más de lo que
 * escribe, y hasta hoy ese audio moría en el webhook: ni mensaje en la
 * bandeja ni respuesta (caso real cabañas, conversación 32 del 2026-09-04).
 *
 * Habla con cualquier endpoint compatible con OpenAI
 * (POST /audio/transcriptions): la propia OpenAI o Groq, que corre
 * whisper-large-v3-turbo por una fracción del precio. NO se paga en tokens
 * del bot sino por minuto de audio; el texto que sale entra al prompt como
 * cualquier mensaje escrito.
 *
 * Si no hay key, el formato no se soporta o el audio viene largo, devuelve
 * null y quien llama avisa al huésped que por ahora solo se lee texto.
 */
class VoiceTranscriber
{
    /**
     * Formatos que aceptan Whisper y compatibles. El amr de los WhatsApp
     * viejos NO está en la lista a propósito: la API lo rechaza y sale más
     * barato mandarlo al aviso de "solo texto" que gastar la llamada.
     */
    public const EXTENSIONS = [
        'audio/ogg' => 'ogg',
        'audio/opus' => 'ogg',
        'audio/oga' => 'oga',
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/mp4' => 'mp4',
        'audio/m4a' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/aac' => 'm4a',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave' => 'wav',
        'audio/webm' => 'webm',
        'audio/flac' => 'flac',
        'audio/x-flac' => 'flac',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    /** ¿Hay con qué transcribir? (encendido + key resoluble) */
    public function isConfigured(): bool
    {
        return (bool) config('services.transcription.enabled', true)
            && $this->apiKey() !== null;
    }

    /**
     * Devuelve el texto dictado, o null si no se pudo (sin key, formato
     * ajeno, audio demasiado largo/pesado o falla del proveedor).
     *
     * @param  int|null  $seconds  Duración declarada por el canal, cuando la manda.
     */
    public function transcribe(string $contents, string $mime, ?int $seconds = null): ?string
    {
        $key = $this->apiKey();

        if (! $this->isConfigured() || $key === null || $contents === '') {
            return null;
        }

        $extension = self::EXTENSIONS[$this->normalizeMime($mime)] ?? null;
        $maxSeconds = (int) config('services.transcription.max_seconds', 180);
        $maxBytes = (int) config('services.transcription.max_bytes', 8388608);

        if ($extension === null
            || strlen($contents) > $maxBytes
            || ($seconds !== null && $seconds > $maxSeconds)) {
            Log::info('STT: nota de voz fuera de rango', [
                'mime' => $mime,
                'bytes' => strlen($contents),
                'seconds' => $seconds,
            ]);

            return null;
        }

        $url = rtrim((string) config('services.transcription.url'), '/').'/audio/transcriptions';
        $model = (string) config('services.transcription.model');
        $started = microtime(true);

        try {
            $response = Http::withToken($key)
                ->timeout((int) config('services.transcription.timeout', 20))
                ->attach('file', $contents, 'nota-de-voz.'.$extension)
                ->asMultipart()
                ->post($url, array_filter([
                    'model' => $model,
                    // El idioma fijo sube bastante la precisión con nombres
                    // y fechas en español ("domingo 6" vs "domingo 16").
                    'language' => (string) config('services.transcription.language', 'es'),
                    'response_format' => 'json',
                ]));

            if ($response->failed()) {
                Log::warning('STT: transcripción fallida', [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ]);

                return null;
            }

            $text = trim((string) ($response->json('text') ?? $response->body()));

            Log::info('STT: nota de voz transcrita', [
                'model' => $model,
                'bytes' => strlen($contents),
                'seconds' => $seconds,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'chars' => mb_strlen($text),
            ]);

            return $text !== '' ? $text : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** "audio/ogg; codecs=opus" llega así de WhatsApp. */
    protected function normalizeMime(string $mime): string
    {
        return strtolower(trim(explode(';', $mime)[0]));
    }

    /**
     * Key propia (TRANSCRIPTION_API_KEY) y, si no hay, la de OpenAI que ya
     * vive en la plataforma. El repesque solo aplica cuando el endpoint
     * ES el de OpenAI: mandarle una key de OpenAI a Groq sería un 401 con
     * el huésped esperando.
     */
    protected function apiKey(): ?string
    {
        $own = trim((string) config('services.transcription.api_key'));

        if ($own !== '') {
            return $own;
        }

        if (! str_contains((string) config('services.transcription.url'), 'api.openai.com')) {
            return null;
        }

        try {
            $platform = PlatformAiProvider::query()
                ->where('provider', 'openai')
                ->orderByDesc('active')
                ->orderBy('id')
                ->first();
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $key = trim((string) $platform?->api_key);

        return $key !== '' ? $key : null;
    }
}
