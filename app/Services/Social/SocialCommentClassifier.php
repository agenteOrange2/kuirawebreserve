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
    protected ?string $policies = null;

    /** Lo que las reglas fijas ya saben del comentario (ver withHint). */
    protected ?string $hint = null;

    public function __construct(
        protected AgentBrain $brain,
        protected AgentToolsController $tools,
        protected PlatformAgentGate $gate,
    ) {}

    /**
     * @return array{clasificacion: string, respuesta_publica: string, mensaje_privado: string, meta: array<string, mixed>}|null
     *                                                                                                                           null si ningún proveedor respondió o la salida no se pudo interpretar:
     *                                                                                                                           en ese caso el comentario va a manos del staff, nunca se adivina.
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

    /**
     * Cuando SocialIntentRules ya decidió que es compra, se le dice al
     * modelo para que redacte el privado como tal: si lo adivina como spam
     * deja el privado vacío y el cliente se queda sin respuesta.
     */
    public function withHint(?string $classification): static
    {
        $this->hint = $classification;

        return $this;
    }

    protected function systemPrompt(): string
    {
        $policies = $this->policiesJson();
        $rates = json_encode($this->rateReference(), JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
        Eres el community manager de un hotel. Clasificas comentarios públicos de Facebook e Instagram y redactas dos textos: uno para responder en el hilo público y otro para el mensaje privado.

        DATOS DEL HOTEL (única fuente de verdad):
        ```json
        {$policies}
        ```

        TARIFAS (los ÚNICOS precios que puedes dar; si viene vacío, no des ninguno):
        ```json
        {$rates}
        ```

        Clasifica el comentario en EXACTAMENTE una de estas categorías:
        - compra: pregunta precios, disponibilidad, ubicación o cómo reservar.
        - pregunta: duda general del hotel (servicios, reglas, horarios) sin intención clara de reservar.
        - queja: reclamo, mala experiencia o inconformidad.
        - elogio: SOLO si habla bien del lugar, del servicio o de su estancia ("hermoso lugar", "excelente atención", "la pasamos increíble"). Una broma o un sarcasmo NO es elogio.
        - interes: quiere venir o admira el lugar como quien todavía no lo conoce ("se ve hermoso", "quiero ir", "me encantaría conocer", "algún día", "qué ganas"). Aunque etiquete a alguien, si dice que quiere ir es interes.
        - otro_lugar: dice que prefiere otro lugar o que mejor se va a otra parte ("mejor vamos a otro lado", "prefiero ir a X", "hay mejores").
        - etiqueta: etiqueta o le habla a otra persona ("Karla Franco", "Raquel vamos", "mira amor", "hay que ir"), o contesta la dinámica de la publicación ("1", "opción 2"). La plática no es con el hotel.
        - spam: publicidad ajena, ligas sospechosas u ofensas.

        Si dudas entre elogio y etiqueta, elige etiqueta. Si dudas entre elogio e interes (no dice que ya vino), elige interes. Si alguien pide información, precio, ubicación o fechas, aunque sea con una sola palabra ("Inf", "Inbox", "Precio"), es compra.

        REGLAS DE REDACCIÓN:
        - NUNCA AFIRMES DISPONIBILIDAD. No tienes forma de consultarla: no digas "tenemos lugar", "hay cabañas disponibles" ni "sí hay para ese fin de semana". Invita a decir sus fechas y ofrece confirmárselo. (Caso real: se contestó "varias cabañas disponibles este fin de semana" con el hotel lleno.)
        - SI PREGUNTAN PRECIO, DA EL PRECIO del bloque TARIFAS, con lo que incluye y el costo de la persona extra. "Depende de la cabaña y la temporada" no es una respuesta: es perder al cliente. Si el bloque TARIFAS viene vacío, pide las fechas y ofrece confirmarle la tarifa; NO inventes ninguna cifra.
        - NUNCA escribas una cantidad de dinero que no esté en los datos de arriba. (Caso real: se contestó "desde $1,800 la noche" cuando la cabaña más barata son $3,000.)
        - respuesta_publica: máximo 140 caracteres, cálida y breve. NUNCA incluyas precios, teléfonos, ligas ni datos personales: eso va en el privado. No prometas nada que no esté en los datos del hotel.
        - mensaje_privado: 2 o 3 oraciones. Retoma lo que preguntó, ofrece ayuda concreta con tarifas o disponibilidad y deja abierta la conversación. Si en los datos del hotel está la respuesta (por ejemplo en faqs), dala.
        - Escribe en el idioma del comentario (español por defecto). NUNCA mezcles palabras ni caracteres de otro alfabeto.
        - Sin emojis, sin markdown, sin asteriscos, sin tablas: se muestran como texto plano.
        - elogio: respuesta_publica es solo un agradecimiento breve. NO supongas que ya vino: nada de "gracias por visitarnos", "por recomendarnos" ni "te esperamos de vuelta".
        - interes: respuesta_publica agradece e invita a venir; mensaje_privado lo invita a reservar y le pregunta para qué fechas y cuántas personas.
        - Si la categoría es queja, spam, etiqueta u otro_lugar, deja ambos textos vacíos ("").

        Responde ÚNICAMENTE con este JSON, sin explicaciones ni ```:
        {"clasificacion":"compra|pregunta|queja|elogio|interes|otro_lugar|etiqueta|spam","respuesta_publica":"...","mensaje_privado":"..."}
        PROMPT;
    }

    /** Los datos del hotel se arman una vez por comentario, no tres. */
    protected function policiesJson(): string
    {
        return $this->policies ??= (string) $this->tools->policies()->getContent();
    }

    /**
     * Tarifa base por tipo de habitación.
     *
     * `policies()` NO trae precios —el bot de chat los cotiza en vivo con sus
     * herramientas, por fecha— pero este servicio no tiene herramientas y el
     * prompt le pedía los precios "de room_types y rate_plans", que ahí no
     * existen. El modelo hacía lo único que podía: inventarlos. Caso real
     * cabañas 2026-09-22 (Octavio, Messenger): "las cabañas van desde $1,800
     * la noche" cuando la más barata son $3,000.
     *
     * Es la tarifa BASE, no una cotización: el precio final depende de la
     * fecha y de la temporada, y eso se dice en la nota.
     *
     * @return array<string, mixed>
     */
    protected function rateReference(): array
    {
        $plans = \App\Models\RatePlan::query()
            ->sellableOnline()
            ->with('roomType')
            ->get()
            ->filter(fn (\App\Models\RatePlan $plan) => (bool) $plan->roomType?->active);

        if ($plans->isEmpty()) {
            return [];
        }

        $rooms = $plans
            ->groupBy(fn (\App\Models\RatePlan $plan) => (string) $plan->roomType->name)
            ->map(function ($delTipo, string $nombre) {
                $barata = $delTipo->sortBy(fn (\App\Models\RatePlan $plan) => (float) $plan->price)->first();

                return [
                    'habitacion' => $nombre,
                    'desde' => (float) $barata->price,
                    'unidad' => $barata->type === \App\Enums\RatePlanType::Night ? 'noche' : 'periodo',
                ];
            })
            ->sortBy('desde')
            ->values()
            ->all();

        return [
            'moneda' => 'MXN',
            'nota' => 'Tarifa base por habitación. El precio final depende de la fecha y la temporada: confírmalo pidiendo las fechas.',
            'habitaciones' => $rooms,
        ];
    }

    protected function userPrompt(SocialPost $post, SocialComment $comment): string
    {
        $publication = trim((string) $post->message) !== ''
            ? mb_substr((string) $post->message, 0, 500)
            : '(sin texto)';

        $prompt = "PUBLICACIÓN ({$post->networkLabel()}): {$publication}\n\n"
            .'COMENTARIO de '.($comment->author_name ?: 'un usuario').': '.trim((string) $comment->body);

        if ($this->hint === SocialComment::CLASS_PURCHASE) {
            $prompt .= "\n\nEsta persona pide información para hospedarse: clasifícalo como compra y escribe el mensaje privado.";
        }

        if ($this->hint === SocialComment::CLASS_INTEREST) {
            $prompt .= "\n\nEsta persona quiere venir y todavía no conoce el lugar: clasifícalo como interes y escribe el mensaje privado que la invita a reservar.";
        }

        return $prompt;
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
            'respuesta_publica' => $this->withoutInventedPrices(
                $this->withoutAvailabilityClaims(
                    $this->brain->sanitizeChatText((string) ($decoded['respuesta_publica'] ?? '')),
                ),
            ),
            'mensaje_privado' => $this->withoutInventedPrices(
                $this->withoutAvailabilityClaims(
                    $this->brain->sanitizeChatText((string) ($decoded['mensaje_privado'] ?? '')),
                ),
            ),
        ];
    }

    /**
     * Una cifra de dinero que el hotel no tiene escrita en ningún lado es
     * inventada, y en una red pública queda a la vista de todos. La oración
     * que la trae se cae y se cambia por la pregunta que sí corresponde;
     * el resto del texto se respeta, igual que con la disponibilidad.
     *
     * Se permite cualquier cantidad que aparezca en los datos del hotel
     * (tarifas, persona extra, fianza, anticipos, FAQs): el candado es
     * contra lo que el modelo se saca de la manga, no contra el hotel.
     */
    protected function withoutInventedPrices(string $text): string
    {
        if (trim($text) === '' || $this->amountsIn($text) === []) {
            return $text;
        }

        $permitidas = $this->amountsIn($this->policiesJson().' '.json_encode($this->rateReference()));
        $safe = 'Dime tus fechas y te confirmo la tarifa exacta.';
        $changed = false;

        $sentences = preg_split('/(?<=[.!?\n])/u', $text) ?: [];

        $kept = array_filter(array_map(function (string $sentence) use ($permitidas, &$changed): string {
            $inventadas = array_diff($this->amountsIn($sentence), $permitidas);

            if ($inventadas === []) {
                return $sentence;
            }

            \Illuminate\Support\Facades\Log::warning('Redes: precio inventado en la respuesta, se quita la oración', [
                'cantidades' => array_values($inventadas),
                'oracion' => trim($sentence),
            ]);

            $changed = true;

            return '';
        }, $sentences), fn (string $sentence) => trim($sentence) !== '');

        if (! $changed) {
            return $text;
        }

        $rebuilt = trim(implode(' ', array_map('trim', $kept)));

        // Si lo que quedó ya pide las fechas, no se repite: "¿Qué fechas
        // tenías en mente? Dime tus fechas y te confirmo la tarifa" es el
        // mismo favor pedido dos veces.
        if (str_contains($rebuilt, '?') && preg_match('/fecha/iu', $rebuilt) === 1) {
            return $rebuilt;
        }

        return trim($rebuilt.' '.$safe);
    }

    /**
     * Las cantidades de dinero del texto, normalizadas a número: "$1,800",
     * "$3,500.00" y "3500 pesos" son la misma familia de cifra.
     *
     * @return list<float>
     */
    protected function amountsIn(string $text): array
    {
        preg_match_all('/(?:\$\s*|\b)(\d{1,3}(?:[,\s]\d{3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)(?=\s*(?:pesos|mxn)?\b)/iu', $text, $m);

        $amounts = [];

        foreach ($m[1] as $raw) {
            $amounts[] = round((float) str_replace([',', ' '], '', $raw), 2);
        }

        return array_values(array_unique($amounts));
    }

    /**
     * Aquí no hay herramientas: este servicio NO puede consultar el
     * calendario, así que cualquier afirmación de disponibilidad es
     * inventada. Caso real cabañas 2026-09-14 (comentario 642): "varias
     * cabañas disponibles este fin de semana" con el hotel lleno.
     *
     * La oración que la afirma se cambia por la pregunta que sí corresponde;
     * el resto del texto se respeta.
     */
    protected function withoutAvailabilityClaims(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $claim = '/(tenemos|hay|quedan|contamos con|s[íi] hay|a[úu]n hay|todav[íi]a hay|est[áa]n? libres?|disponibles?\s+(?:este|ese|el)|disponibilidad\s+(?:para|este|ese|el))/iu';
        $safe = '¿Para qué fechas te interesa? Con gusto te confirmo si tenemos lugar.';
        $changed = false;

        $sentences = preg_split('/(?<=[.!?\n])/u', $text) ?: [];

        $kept = array_filter(array_map(function (string $sentence) use ($claim, &$changed): string {
            if (trim($sentence) === '' || ! preg_match($claim, $sentence)) {
                return $sentence;
            }

            // Preguntar por disponibilidad no es afirmarla, y ofrecer
            // revisarla ("con gusto reviso si tenemos lugar") tampoco.
            if (str_contains($sentence, '?') || preg_match('/\b(si\s+(?:tenemos|hay|queda|contamos)|confirm\w*|revis\w*|verific\w*|checa\w*)/iu', $sentence)) {
                return $sentence;
            }

            $changed = true;

            return '';
        }, $sentences), fn (string $sentence) => trim($sentence) !== '');

        if (! $changed) {
            return $text;
        }

        $rebuilt = trim(implode(' ', array_map('trim', $kept)));

        return trim($rebuilt.' '.$safe);
    }
}
