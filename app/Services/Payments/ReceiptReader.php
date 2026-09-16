<?php

namespace App\Services\Payments;

use App\Models\Central\PlatformAiProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lee la foto que manda el huésped y dice QUÉ ES antes de que el sistema la
 * trate como comprobante.
 *
 * Hasta el 2026-09-15 cualquier imagen que llegara a una conversación con
 * apartado sostenía la habitación 24 horas, se pegaba al cobro como
 * "comprobante" y el huésped recibía "Recibimos tu comprobante": una selfie,
 * la foto de la INE o la captura de la cabaña valían lo mismo que una
 * transferencia. El bot no ve imágenes (MiniMax-M2.7 es solo texto), así que
 * nadie la miraba hasta que alguien del hotel abría la bandeja.
 *
 * Aquí un modelo de visión devuelve el tipo y los datos que se leen (monto,
 * fecha, clave de rastreo, cuenta destino). NUNCA confirma un pago: eso lo
 * sigue haciendo el personal en /pagos. Solo decide si la imagen merece el
 * trato de comprobante, y le da al personal los datos para verificar sin
 * abrir la foto.
 *
 * null = no se pudo leer (sin key, PDF, archivo grande o falla del
 * proveedor): quien llama cae al flujo de siempre.
 */
class ReceiptReader
{
    public const KIND_TRANSFER = 'transfer_receipt';

    public const KIND_OTHER_PAYMENT = 'other_payment';

    public const KIND_NOT_RECEIPT = 'not_receipt';

    /** Formatos que aceptan los endpoints de visión compatibles con OpenAI. */
    protected const MIMES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

    public function isConfigured(): bool
    {
        return (bool) config('services.receipt_reader.enabled', true)
            && $this->apiKey() !== null;
    }

    /**
     * @return array{kind: string, description: string|null, amount: float|null, date: string|null, time: string|null, tracking_key: string|null, reference: string|null, destination_bank: string|null, destination_account_last4: string|null, beneficiary: string|null, status: string|null, model: string}|null
     */
    public function read(string $contents, string $mime): ?array
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        if (! in_array($mime, self::MIMES, true)
            || $contents === ''
            || strlen($contents) > (int) config('services.receipt_reader.max_bytes', 6291456)
            || ! $this->isConfigured()) {
            return null;
        }

        $url = rtrim((string) config('services.receipt_reader.url'), '/').'/chat/completions';
        $model = (string) config('services.receipt_reader.model');
        $started = microtime(true);

        try {
            $response = Http::withToken((string) $this->apiKey())
                ->timeout((int) config('services.receipt_reader.timeout', 25))
                ->post($url, [
                    'model' => $model,
                    // Los modelos que razonan gastan cientos de tokens antes
                    // del JSON; con menos se corta la respuesta a la mitad.
                    'max_tokens' => 1500,
                    'temperature' => 0,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $this->prompt()],
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:'.($mime === 'image/jpg' ? 'image/jpeg' : $mime).';base64,'.base64_encode($contents)]],
                        ],
                    ]],
                ]);

            if ($response->failed()) {
                Log::warning('Comprobante: la lectura falló', [
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ]);

                return null;
            }

            $reading = $this->parse((string) $response->json('choices.0.message.content'));

            Log::info('Comprobante: imagen leída', [
                'model' => $model,
                'kind' => $reading['kind'] ?? null,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'tokens' => $response->json('usage.total_tokens'),
            ]);

            return $reading === null ? null : $reading + ['model' => $model];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Normaliza lo que devolvió el modelo: sin bloque de razonamiento, sin
     * cercas de código, y cada campo con su tipo. Un JSON ilegible es "no se
     * pudo leer", nunca "no es comprobante".
     *
     * @return array<string, mixed>|null
     */
    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('~<think>.*?</think>~su', '', $text);

        if (! preg_match('~\{.*\}~su', $text, $json)) {
            return null;
        }

        $data = json_decode($json[0], true);

        if (! is_array($data)) {
            return null;
        }

        $kind = in_array($data['kind'] ?? null, [self::KIND_TRANSFER, self::KIND_OTHER_PAYMENT, self::KIND_NOT_RECEIPT], true)
            ? $data['kind']
            : null;

        if ($kind === null) {
            return null;
        }

        $string = fn (string $key, int $max = 120): ?string => is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== ''
            ? mb_substr(trim((string) $data[$key]), 0, $max)
            : null;

        $amount = $data['amount'] ?? null;

        if (is_string($amount)) {
            $amount = preg_replace('/[^\d.]/', '', str_replace(',', '', $amount));
        }

        $date = $string('date', 10);
        $time = $string('time', 5);
        $last4 = preg_replace('/\D/', '', (string) ($data['destination_account_last4'] ?? ''));
        $tracking = $string('tracking_key', 40);

        return [
            'kind' => $kind,
            'description' => $string('description', 160),
            'amount' => is_numeric($amount) && (float) $amount > 0 ? round((float) $amount, 2) : null,
            'date' => $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
            'time' => $time !== null && preg_match('/^\d{2}:\d{2}$/', $time) ? $time : null,
            'tracking_key' => $tracking !== null ? strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $tracking)) ?: null : null,
            'reference' => $string('reference', 40),
            'destination_bank' => $string('destination_bank', 60),
            'destination_account_last4' => strlen((string) $last4) >= 4 ? substr((string) $last4, -4) : null,
            'beneficiary' => $string('beneficiary', 120),
            'status' => $string('status', 80),
        ];
    }

    protected function prompt(): string
    {
        $today = now()->format('Y-m-d');

        return <<<PROMPT
Eres el verificador de pagos de un hotel en México. Hoy es {$today}. Mira la imagen que mandó un huésped por WhatsApp.
Responde SOLO un objeto JSON, sin texto antes ni después, con estas llaves:
- kind: "transfer_receipt" si es un comprobante o captura de transferencia o depósito bancario (SPEI, app del banco, ticket de depósito, OXXO a cuenta); "other_payment" si es otro comprobante de pago (voucher de tarjeta, recibo de pasarela); "not_receipt" si NO es un comprobante (foto de persona, identificación, lugar, captura de chat, meme, documento cualquiera).
- description: qué se ve, en español, máximo 15 palabras.
- amount: monto transferido como número, o null.
- date: fecha de la operación YYYY-MM-DD, o null.
- time: hora HH:MM, o null.
- tracking_key: clave de rastreo o folio de la operación, o null.
- reference: referencia numérica o concepto corto, o null.
- destination_bank: banco destino, o null.
- destination_account_last4: últimos 4 dígitos de la cuenta, CLABE o tarjeta destino, o null.
- beneficiary: nombre del beneficiario, o null.
- status: el estado que dice la imagen (exitosa, en proceso, programada, rechazada...), o null.
No inventes datos que no se lean con claridad: usa null.
PROMPT;
    }

    /**
     * Key propia (RECEIPT_READER_API_KEY) o la del proveedor de plataforma
     * que corresponde al endpoint: mandarle la key de MiniMax a OpenAI sería
     * un 401 con el huésped esperando.
     */
    protected function apiKey(): ?string
    {
        $own = trim((string) config('services.receipt_reader.api_key'));

        if ($own !== '') {
            return $own;
        }

        $host = (string) parse_url((string) config('services.receipt_reader.url'), PHP_URL_HOST);
        $provider = match (true) {
            str_contains($host, 'minimax') => 'minimax',
            str_contains($host, 'openai.com') => 'openai',
            str_contains($host, 'moonshot') => 'kimi',
            default => null,
        };

        if ($provider === null) {
            return null;
        }

        try {
            $key = PlatformAiProvider::query()
                ->where('provider', $provider)
                ->orderByDesc('active')
                ->orderBy('id')
                ->value('api_key');
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $key = trim((string) $key);

        return $key !== '' ? $key : null;
    }
}
