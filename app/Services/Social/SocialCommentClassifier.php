<?php

namespace App\Services\Social;

use App\Http\Controllers\Agent\AgentToolsController;
use App\Models\SocialComment;
use App\Models\SocialPost;
use App\Services\Agent\AgentBrain;
use App\Services\Agent\PlatformAgentGate;
use Throwable;

/**
 * Clasifica un comentario público y redacta, de una sola pasada, la respuesta
 * pública breve y el mensaje privado. Una llamada al LLM por comentario, sin
 * herramientas: es la operación más barata posible y se ejecuta muchas veces.
 *
 * Reutiliza la cadena de proveedores del hotel (BYOK → plataforma) y el mismo
 * registro de consumo que una respuesta de chat.
 */
class SocialCommentClassifier
{
    public function __construct(
        protected AgentBrain $brain,
        protected AgentToolsController $tools,
        protected PlatformAgentGate $gate,
    ) {}

    /**
     * @return array{clasificacion: string, respuesta_publica: string, mensaje_privado: string, meta: array<string, mixed>}|null
     *                                                                                                                            null si ningún proveedor respondió o la salida no se pudo interpretar:
     *                                                                                                                            en ese caso el comentario va a manos del staff, nunca se adivina.
     */
    public function classify(SocialPost $post, SocialComment $comment): ?array
    {
        foreach ($this->brain->providers() as $provider) {
            $started = microtime(true);

            try {
                $response = $this->brain->run($provider, fn ($request) => $request
                    ->withSystemPrompt($this->systemPrompt())
                    ->withPrompt($this->userPrompt($post, $comment)));

                $parsed = $this->parse($response->text);

                if (! $parsed) {
                    continue; // otro proveedor puede sí devolver JSON limpio
                }

                $meta = [
                    'provider' => $provider->provider,
                    'model' => $provider->model,
                    'platform' => (bool) ($provider->platform ?? false),
                    'ms' => (int) round((microtime(true) - $started) * 1000),
                    'prompt_tokens' => $response->usage->promptTokens ?? null,
                    'completion_tokens' => $response->usage->completionTokens ?? null,
                ];

                if ($meta['platform']) {
                    $this->gate->recordReply($meta);
                }

                return $parsed + ['meta' => $meta];
            } catch (Throwable $e) {
                report($e);
            }
        }

        return null;
    }

    protected function systemPrompt(): string
    {
        $policies = $this->tools->policies()->getContent();

        return <<<PROMPT
        Eres el community manager de un hotel. Clasificas comentarios públicos de Facebook e Instagram y redactas dos textos: uno para responder en el hilo público y otro para el mensaje privado.

        DATOS DEL HOTEL (única fuente de verdad):
        ```json
        {$policies}
        ```

        Clasifica el comentario en EXACTAMENTE una de estas categorías:
        - compra: pregunta precios, disponibilidad, ubicación o cómo reservar.
        - pregunta: duda general del hotel (servicios, reglas, horarios) sin intención clara de reservar.
        - queja: reclamo, mala experiencia o inconformidad.
        - elogio: felicitación o comentario positivo sin pregunta.
        - spam: publicidad ajena, ligas sospechosas, texto sin relación u ofensas.

        REGLAS DE REDACCIÓN:
        - respuesta_publica: máximo 140 caracteres, cálida y breve. NUNCA incluyas precios, teléfonos, ligas ni datos personales: eso va en el privado. No prometas nada que no esté en los datos del hotel.
        - mensaje_privado: 2 o 3 oraciones. Retoma lo que preguntó, ofrece ayuda concreta con tarifas o disponibilidad y deja abierta la conversación. Si en los datos del hotel está la respuesta (por ejemplo en faqs), dala.
        - Escribe en el idioma del comentario (español por defecto). NUNCA mezcles palabras ni caracteres de otro alfabeto.
        - Sin emojis, sin markdown, sin asteriscos, sin tablas: se muestran como texto plano.
        - Si la categoría es queja o spam, deja ambos textos vacíos ("").

        Responde ÚNICAMENTE con este JSON, sin explicaciones ni ```:
        {"clasificacion":"compra|pregunta|queja|elogio|spam","respuesta_publica":"...","mensaje_privado":"..."}
        PROMPT;
    }

    protected function userPrompt(SocialPost $post, SocialComment $comment): string
    {
        $publication = trim((string) $post->message) !== ''
            ? mb_substr((string) $post->message, 0, 500)
            : '(sin texto)';

        return "PUBLICACIÓN ({$post->networkLabel()}): {$publication}\n\n"
            ."COMENTARIO de ".($comment->author_name ?: 'un usuario').": ".trim((string) $comment->body);
    }

    /**
     * Parseo tolerante: los modelos baratos envuelven el JSON en texto o en
     * cercas de código aunque se les prohíba. Se extrae el primer bloque
     * {...} y se valida la categoría; cualquier cosa rara devuelve null.
     *
     * @return array{clasificacion: string, respuesta_publica: string, mensaje_privado: string}|null
     */
    public function parse(?string $text): ?array
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        if (! is_array($decoded)) {
            return null;
        }

        $classification = mb_strtolower(trim((string) ($decoded['clasificacion'] ?? '')));

        if (! in_array($classification, SocialComment::CLASSIFICATIONS, true)) {
            return null;
        }

        // El saneador del bot: quita markdown, caracteres CJK fugados y
        // emojis antes de que el texto llegue a una red pública.
        return [
            'clasificacion' => $classification,
            'respuesta_publica' => $this->brain->sanitizeChatText((string) ($decoded['respuesta_publica'] ?? '')),
            'mensaje_privado' => $this->brain->sanitizeChatText((string) ($decoded['mensaje_privado'] ?? '')),
        ];
    }
}
