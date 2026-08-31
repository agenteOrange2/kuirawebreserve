<?php

namespace App\Services\Agent;

use App\Http\Controllers\Agent\AgentToolsController;
use App\Models\AiProvider;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

/**
 * Cerebro del asistente (multitenant): usa los proveedores LLM que EL HOTEL
 * dio de alta (AiProvider) en cadena de fallback — el primero que responde
 * gana. Registra proveedor/modelo/tokens/latencia por mensaje para medir
 * costo-beneficio. Herramientas = las mismas de la Agent API.
 */
class AgentBrain
{
    public function __construct(
        protected AgentToolsController $tools,
        protected PlatformAgentGate $gate,
    ) {}

    /**
     * Cadena de proveedores del tenant:
     * 1) BYOK (keys propias del hotel, si la plataforma se lo permite) —
     *    su consumo no cuenta contra la cuota.
     * 2) Keys de PLATAFORMA según plan/asignación/cuota (PlatformAgentGate).
     *
     * @return Collection<int, AiProvider>
     */
    public function providers(): Collection
    {
        $status = $this->gate->status();

        if ($status['byok_allowed']) {
            $own = AiProvider::query()->active()->orderBy('sort_order')->orderBy('id')->get();

            if ($own->isNotEmpty()) {
                return $own;
            }
        }

        return $status['chain'];
    }

    public function gateStatus(): array
    {
        return $this->gate->status();
    }

    public function isConfigured(): bool
    {
        return $this->providers()->isNotEmpty();
    }

    /**
     * Ejecuta una llamada con un proveedor concreto (aplica su key/URL del
     * tenant en runtime). Lo usa reply() y el botón "Probar" del panel.
     */
    public function run(AiProvider $provider, callable $build): TextResponse
    {
        $driver = $provider->driver();

        config()->set("prism.providers.{$driver}.api_key", $provider->api_key);
        if ($provider->baseUrl()) {
            config()->set("prism.providers.{$driver}.url", $provider->baseUrl());
        }

        /** @var \Prism\Prism\Text\PendingRequest $request */
        $request = $build(Prism::text()->using(Provider::from($driver), $provider->model));

        return $request->asText();
    }

    /**
     * Genera y guarda la respuesta del bot probando la cadena de proveedores;
     * si todos fallan (o pide humano), hace handoff a la bandeja.
     */
    public function reply(Conversation $conversation): ?Message
    {
        $handoff = false;
        $text = '';
        $meta = [];

        foreach ($this->providers() as $provider) {
            $started = microtime(true);

            try {
                $response = $this->run($provider, fn ($request) => $request
                    ->withSystemPrompt($this->systemPrompt($conversation))
                    ->withMessages($this->history($conversation))
                    ->withTools($this->toolset($handoff, $conversation))
                    ->withMaxSteps(6));

                $text = trim($response->text);
                $meta = [
                    'provider' => $provider->provider,
                    'model' => $provider->model,
                    'platform' => (bool) ($provider->platform ?? false),
                    'ms' => (int) round((microtime(true) - $started) * 1000),
                    'prompt_tokens' => $response->usage->promptTokens ?? null,
                    'completion_tokens' => $response->usage->completionTokens ?? null,
                ];

                // Consumo con keys de plataforma → rollup central (cuota/costos).
                if ($meta['platform']) {
                    $this->gate->recordReply($meta);
                }

                break; // el primero que responde gana
            } catch (Throwable $e) {
                report($e);

                if ($handoff) {
                    break; // el traspaso ya se decidió; no probar otro proveedor
                }
            }
        }

        if ($handoff || $text === '') {
            $conversation->update(['bot_enabled' => false, 'status' => Conversation::STATUS_PENDING]);

            return $conversation->messages()->create([
                'direction' => 'out',
                'sender_type' => 'system',
                'body' => 'Te comunicamos con una persona del hotel; en un momento te atienden.',
                'meta' => $meta ?: null,
                'created_at' => now(),
            ]);
        }

        $conversation->update(['last_message_at' => now()]);

        return $conversation->messages()->create([
            'direction' => 'out',
            'sender_type' => 'bot',
            'body' => $this->sanitizeChatText($this->sanitizeGatewayLinks($text)),
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }

    /**
     * Red de seguridad DETERMINISTA para links de pago (bug real 2026-08-12,
     * bandeja motellacupula): el checkout crudo de Stripe mide ~470 chars con
     * un #fragmento obligatorio que el modelo recorta a veces — y aunque el
     * tool ya devuelve el link corto /pago/{uuid}, el modelo puede re-citar
     * un link roto de un mensaje ANTERIOR del historial. Aquí cualquier URL
     * de pasarela que aparezca en la respuesta se sustituye por el link corto
     * del cobro correspondiente (el id de sesión sobrevive al recorte y ubica
     * el cobro exacto). Si no se encuentra el cobro, se deja tal cual.
     */
    public function sanitizeGatewayLinks(string $text): string
    {
        $pattern = '~https?://(?:checkout\.stripe\.com|www\.mercadopago\.com(?:\.\w{2})?|www\.(?:sandbox\.)?paypal\.com)/\S+~i';

        return preg_replace_callback($pattern, function (array $match) {
            $url = rtrim($match[0], '.,;:)]');
            $trail = substr($match[0], strlen($url));

            if (preg_match('~cs_(?:test|live)_[A-Za-z0-9]+~', $url, $session)) {
                $needle = $session[0];
            } else {
                $needle = mb_substr($url, 0, 90);
            }

            // Match exacto en PHP sobre los cobros recientes (LIKE escapado
            // se comporta distinto entre motores de BD).
            $request = \App\Models\PaymentRequest::query()
                ->whereNotNull('checkout_url')
                ->latest('id')
                ->limit(50)
                ->get()
                ->first(fn ($candidate) => str_contains((string) $candidate->checkout_url, $needle));

            return ($request ? $request->publicReturnUrl() : $url).$trail;
        }, $text) ?? $text;
    }

    /**
     * Red de seguridad DETERMINISTA de formato (bug real 2026-08-20, bandeja
     * motellacupula con MiniMax): los canales muestran el mensaje como TEXTO
     * PLANO (Telegram/WhatsApp/webchat/bandeja renderizan {{ body }} tal
     * cual), así que una tabla markdown llega como sopa de barras `|` y las
     * negritas como asteriscos literales; además los modelos entrenados en
     * chino a veces fugan caracteres CJK a media frase ("Si告诉我 qué
     * fecha..."). El prompt ya lo prohíbe, pero esos modelos lo ignoran:
     * aquí se corrige siempre, sin depender del LLM.
     */
    public function sanitizeChatText(string $text): string
    {
        // Tablas markdown → un renglón "- celda — celda" por fila (las filas
        // separadoras |---|---| se descartan).
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s*\|.*\|\s*$/u', $line)) {
                if (preg_match('/^\s*\|[\s\-:|]+\|\s*$/u', $line)) {
                    continue;
                }
                $cells = array_values(array_filter(
                    array_map('trim', explode('|', trim($line, " \t|"))),
                    fn (string $cell) => $cell !== '',
                ));
                $lines[] = $cells ? '- '.implode(' — ', $cells) : '';

                continue;
            }
            $lines[] = $line;
        }
        $text = implode("\n", $lines);

        // Marcas markdown que el huésped vería literales.
        $text = preg_replace('/(\*\*|__)(.+?)\1/su', '$2', $text) ?? $text;
        $text = preg_replace('/^#{1,6}\s+/mu', '', $text) ?? $text;
        $text = preg_replace('/^(\s*)\*\s+/mu', '$1- ', $text) ?? $text;
        $text = str_replace('`', '', $text);

        $text = $this->stripForeignScriptLeaks($text);

        // Emojis y pictogramas (la política del producto es chat sin emojis).
        $text = preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{20E3}]/u',
            '',
            $text,
        ) ?? $text;

        // Huecos que dejan los recortes.
        $text = preg_replace('/ {2,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+$/mu', '', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Quita palabras fugadas de otro alfabeto SIN romper una respuesta que
     * legítimamente va en ese idioma.
     *
     * Los modelos multilingües baratos meten una palabra suelta en su lengua
     * de entrenamiento a media frase: "Si告诉我 qué fecha" (MiniMax, chino) o
     * "habitaciones классик" (ruso, en la primera respuesta automática a un
     * comentario real, 2026-08-20). Pero el bot SÍ debe poder contestar en
     * ruso a quien escribe en ruso.
     *
     * De ahí el criterio: solo se limpia cuando el texto es claramente
     * latino y lo otro son unas cuantas letras infiltradas. Si el mensaje
     * está mayormente en el otro alfabeto, se respeta tal cual.
     */
    protected function stripForeignScriptLeaks(string $text): string
    {
        $foreign = '\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{F900}-\x{FAFF}'  // han
            .'\x{3040}-\x{30FF}\x{31F0}-\x{31FF}'                          // kana
            .'\x{1100}-\x{11FF}\x{AC00}-\x{D7AF}'                          // hangul
            .'\x{0400}-\x{04FF}\x{0500}-\x{052F}'                          // cirílico
            .'\x{0370}-\x{03FF}'                                           // griego
            .'\x{0590}-\x{05FF}\x{0600}-\x{06FF}'                          // hebreo, árabe
            .'\x{0E00}-\x{0E7F}\x{0900}-\x{097F}';                         // tailandés, devanagari

        $foreignCount = preg_match_all("/[{$foreign}]/u", $text);

        if ($foreignCount === 0) {
            // Puntuación de ancho completo, que llega sola a veces.
            return preg_replace('/[\x{3000}-\x{303F}\x{FF01}-\x{FF60}\x{FFE0}-\x{FFEE}]+/u', '', $text) ?? $text;
        }

        $latinCount = preg_match_all('/\p{Latin}/u', $text);

        // Mayoría latina con infiltrados: se limpia. Si no, es un mensaje en
        // ese idioma y se deja intacto.
        if ($latinCount <= $foreignCount * 2) {
            return $text;
        }

        return preg_replace(
            "/[{$foreign}\x{3000}-\x{303F}\x{FF01}-\x{FF60}\x{FFE0}-\x{FFEE}]+/u",
            '',
            $text,
        ) ?? $text;
    }

    /**
     * El JSON como lo debe leer el modelo: con acentos y sin barras
     * escapadas. Las herramientas se llaman en proceso, así que no pasan
     * por el middleware de la ruta y hay que desescapar aquí.
     */
    public static function readable(\Illuminate\Http\JsonResponse $response): string
    {
        $content = $response->getContent();
        $decoded = json_decode((string) $content, true);

        if (! is_array($decoded)) {
            return (string) $content;
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ?: (string) $content;
    }

    protected function systemPrompt(?Conversation $conversation = null): string
    {
        $policiesJson = self::readable($this->tools->policies());
        $policies = json_decode($policiesJson, true);
        $guestBlock = $this->guestBlock($conversation);
        $summaryBlock = $this->summaryBlock($conversation);
        $instructionsBlock = $this->instructionsBlock();
        $guidelinesBlock = $this->guidelinesBlock();

        return <<<PROMPT
Eres el asistente virtual del hotel "{$policies['hotel']['name']}". Atiendes huéspedes por chat en español (responde en el idioma del huésped si escribe en otro).

DATOS DEL HOTEL (única fuente de verdad — si algo no está aquí ni en tus herramientas, di que no tienes esa información y ofrece comunicarlo con recepción):
```json
{$policiesJson}
```
{$guestBlock}{$summaryBlock}{$instructionsBlock}{$guidelinesBlock}
REGLAS ESTRICTAS:
- Si la duda del huésped coincide con una pregunta de "faqs", responde con esa respuesta tal cual (puedes adaptarla al tono de la conversación, sin cambiar los datos).
- Si el huésped comparte su teléfono, usa identificar_huesped para reconocerlo; si ya nos visitó, salúdalo por su nombre como cliente frecuente (sin recitar sus datos).
- Usa las herramientas para tarifas, disponibilidad y reservas; NUNCA inventes precios, fechas, políticas ni cantidades de habitaciones.
- INVENTARIO: cada tipo tiene un número FIJO de habitaciones, el campo "units" de room_types. Ese es el tope absoluto: si units es 1, JAMÁS ofrezcas dos ("2 Cabañas Reales" cuando solo existe una es el peor error que puedes cometer). Para ofrecer varias, usa consultar_disponibilidad_general y no pases de "units_available" por tipo.
- NO AFIRMES DISPONIBILIDAD SIN VERIFICARLA: nunca digas que una habitación está libre —ni la ofrezcas como alternativa— sin haberla consultado con consultar_disponibilidad o consultar_disponibilidad_general para ESAS fechas exactas. Si un tipo salió ocupado, consulta el resto con consultar_disponibilidad_general ANTES de nombrar alternativas; si no queda nada libre, dilo tal cual y ofrece las fechas de alternative_dates (ya vienen verificadas, con su etiqueta en español) para no perder al huésped.
- GRUPOS: si el grupo no cabe en una sola habitación, llama consultar_disponibilidad_general con las fechas y "personas", y ofrece TAL CUAL lo que devuelva suggested_combination (qué tipos, cuántas de cada uno y el total). Si combination_covers_guests viene en false, dilo con claridad y ofrece otras fechas o usa transferir_a_humano; nunca completes el grupo con habitaciones que no aparecen libres. No le pidas al huésped que él arme la combinación: propónsela tú.
- No inventes política comercial: nunca afirmes descuentos, mínimos de noches, ni que "el precio es fijo todo el año" si no está en los datos del hotel. Si una tarifa trae seasonal en true, el precio cambia por fechas y solo consultar_disponibilidad te da el correcto.
- FECHAS: al repetir la llegada y la salida usa exactamente las que devolvió la herramienta (starts_at/ends_at); no cambies día, mes ni año al redactarlas, y confirma el año solo si el huésped lo dio.
- Cada tarifa pertenece a UN tipo de habitación (room_type en consultar_tarifas). Si el huésped pidió un tipo, cotiza y aparta SOLO con tarifas de ese tipo — jamás uses la tarifa de otro tipo.
- El precio de una tarifa es POR UNIDAD (por noche o por bloque); el TOTAL del rango lo calcula consultar_disponibilidad. Nunca presentes el total del rango como si fuera el precio por unidad ("$1,750 por 3 horas" está MAL si es el total de varias unidades). Para estancias con fechas usa tarifas por noche; las tarifas por bloque (ratos/horas) solo si el huésped pide horas.
- Antes de crear un apartado repite al huésped: tipo de habitación, nombre de la tarifa, TOTAL exacto, fecha de llegada y nombre completo — y espera su confirmación.
- Al entregar el código de un apartado creado, menciona una sola vez que el día de la llegada se pide una identificación oficial en recepción para el registro.
- PAGOS: si el apartado requiere prepago (requires_prepayment), PRIMERO ofrece al huésped las formas de pago disponibles según payment_options del apartado (pasarelas por su nombre, transferencia, efectivo al llegar) y pregunta cuál prefiere — solo menciona las que existan. Con su elección llama solicitar_pago (metodo y proveedor) y comparte lo que devuelva tal cual: link de pago (paga ahí y el sistema confirma solo), cuentas para transferencia (pide el comprobante por este chat; el hotel lo verifica), o efectivo (dile hasta cuándo queda apartada su habitación y que paga al llegar). Si solo hay UNA opción, no preguntes: úsala directo. NUNCA digas que un pago fue recibido o verificado: eso solo lo confirma el sistema (consultar_reserva) o el personal. Si el huésped insiste en que ya pagó y el sistema no lo refleja, usa transferir_a_humano.
- Si NO tienes la herramienta solicitar_pago, este hotel no tiene cobros configurados: no prometas NINGUNA forma de pago (ni efectivo al llegar, ni transferencia, ni link) — di que recepción se comunica para cerrar el pago.
- NUNCA pidas ni aceptes números de tarjeta por el chat; si el huésped los envía, dile que por seguridad los borre y no los uses.
- Cita montos exactamente como los devuelven las herramientas (usa *_label).
- CAPACIDAD Y PERSONAS EXTRA: responde SOLO con "occupancy" de room_types: la tarifa incluye included_guests personas, el máximo es max_guests, y cada persona adicional cuesta extra_guest_fee_label. Si extra_guest_fee existe, NUNCA digas que no hay cobro por persona extra. Si el grupo supera max_guests, sugiere una habitación con más capacidad o transfiere a recepción.
- FIANZA: si get_policies o el resultado de crear_apartado traen "guarantee", al confirmar un apartado avisa UNA vez que al llegar se cobra ese depósito en garantía (usa su "label" tal cual) y que se devuelve al registrar la salida. NO lo sumes al total de la estancia: es un depósito aparte que regresa. Si el huésped aparta varias habitaciones y hay "tiers_label", menciónalo; nunca inventes descuentos de fianza que no estén ahí.
- FOTOS: si piden fotos de una habitación y su tipo tiene photos_url, comparte ese link tal cual diciendo que ahí están las fotos. Sin photos_url, describe la habitación y ofrece que el personal envíe fotos por este chat.
- ENLACES: comparte una liga SOLO cuando venga al caso (piden fotos, preguntan por una habitación en concreto, por cómo llegar o por qué hacer). Una sola liga, una sola vez en la conversación: nunca la pegues de firma en cada mensaje ni recites la lista completa. Usa únicamente las URLs que vienen en estos datos (website, maps_url, links, photos_url, url de un recorrido) — JAMÁS inventes ni completes una dirección web.
- RECORRIDOS: si preguntan por actividades, tours, qué hacer o qué hay en la zona, ofrece lo que traiga "experiences" con su duración y precio (y su liga si la tiene); para apartarlos comparte experiences_booking_url. Si no hay bloque "experiences", el hotel NO tiene recorridos: no los inventes ni prometas que alguien los organiza.
- VARIAS HABITACIONES: si tienes crear_apartado_grupo, úsala — aparta todas bajo un folio GRP- y es todo o nada, así nadie se queda sin cuarto a medio grupo. Su cobro es UNO consolidado: llama solicitar_pago con el folio GRP-, nunca uno por habitación. Si NO tienes esa herramienta, haz UNA llamada de crear_apartado por cada habitación y reporta el resultado real de CADA una (código o el error exacto). En cualquier caso, nunca resumas dos apartados en uno ni des por hecho uno que no confirmaste con la herramienta.
- Si una herramienta devuelve un error, comunica al huésped el mensaje EXACTO que devolvió — nunca inventes la causa ni digas "no hay disponibilidad" si la herramienta dijo otra cosa.
- ADJUNTOS: tú no puedes ver imágenes ni archivos. Cuando un mensaje diga "[adjuntó una imagen o documento]", el archivo SÍ llegó y el personal puede verlo — NUNCA digas que no se recibió ni pidas que lo reenvíe. Si es un comprobante de pago, agradece y di que el personal lo verificará; recuerda que tú no confirmas pagos.
- Si el huésped pide hablar con una persona, se queja, o pide algo fuera de tu alcance, usa la herramienta transferir_a_humano.
- Hoy es {$this->today()}. Fechas en formato YYYY-MM-DD HH:MM.
- FORMATO: tus mensajes se muestran como TEXTO PLANO (WhatsApp, Telegram, webchat) — JAMÁS uses tablas, negritas con asteriscos, títulos con #, ni ningún markdown: el huésped vería los símbolos literales. Para listar opciones usa un renglón corto por opción con guion, ej.: "- Habitación Sencilla: $1,300".
- IDIOMA: escribe TODO el mensaje en el idioma del huésped (español por defecto); JAMÁS mezcles palabras o caracteres de otro idioma o alfabeto (chino, inglés...) a media frase.
- Nunca menciones duraciones en horas, horarios de entrada/salida ni vigencias que las herramientas o estos datos no indiquen explícitamente.
- Sé breve, cálido y profesional; máximo 2-3 oraciones por respuesta salvo que listes opciones. No uses emojis.
- No saludes de nuevo si la conversación ya empezó: continúa el hilo donde va.
PROMPT;
    }

    /**
     * Aprendizajes del hotel (agent_guidelines): correcciones capturadas de
     * conversaciones reales, inyectadas como reglas numeradas. Es el canal
     * para que el bot "aprenda" de sus errores con control humano.
     */
    protected function guidelinesBlock(): string
    {
        $guidelines = \App\Models\AgentGuideline::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('instruction');

        if ($guidelines->isEmpty()) {
            return '';
        }

        $list = $guidelines->map(fn (string $g, int $i) => ($i + 1).'. '.$g)->implode("\n");

        return <<<BLOCK

APRENDIZAJES DEL HOTEL (correcciones de conversaciones reales — cúmplelas SIEMPRE, tienen prioridad sobre tu criterio):
{$list}

BLOCK;
    }

    /**
     * Vista del prompt efectivo (sin conversación): lo que el bot realmente
     * recibe — para el "ojito" del admin de plataforma.
     */
    public function promptPreview(): string
    {
        return $this->systemPrompt(null);
    }

    /**
     * Instrucciones en dos niveles, ambas subordinadas a las REGLAS
     * ESTRICTAS: primero las de PLATAFORMA (super-admin, por hotel — cómo
     * cotizar, apartar, métodos de pago) y luego las del propio hotel
     * (settings.agent_instructions, editadas en /ajustes).
     */
    protected function instructionsBlock(): string
    {
        $blocks = '';

        $platform = trim((string) (\App\Models\Central\TenantAgentSetting::for((string) tenant('id'))->platform_instructions ?? ''));
        if ($platform !== '') {
            $blocks .= <<<BLOCK

INSTRUCCIONES DE LA PLATAFORMA (prioritarias sobre las del hotel; síguelas siempre que no contradigan las REGLAS ESTRICTAS):
{$platform}

BLOCK;
        }

        $hotel = trim((string) (\App\Models\Property::query()->first()?->settings['agent_instructions'] ?? ''));
        if ($hotel !== '') {
            $blocks .= <<<BLOCK

INSTRUCCIONES DEL EQUIPO DEL HOTEL (síguelas siempre que no contradigan las REGLAS ESTRICTAS ni las de la plataforma):
{$hotel}

BLOCK;
        }

        return $blocks === '' ? "\n" : $blocks;
    }

    /**
     * Bloque de memoria: si la conversación ya está ligada a un huésped del
     * CRM, el bot lo "recuerda" (nombre, visitas, preferencias) sin exponer
     * datos sensibles. Un huésped vetado se transfiere a humano de inmediato.
     */
    protected function guestBlock(?Conversation $conversation): string
    {
        $guest = $conversation?->guest;

        if (! $guest) {
            return "\n";
        }

        if ($guest->is_blacklisted) {
            return <<<'BLOCK'

HUÉSPED IDENTIFICADO CON RESTRICCIÓN INTERNA: no ofrezcas apartados ni tarifas; usa transferir_a_humano de inmediato con motivo "revisión de recepción" (sin mencionar la restricción al huésped).

BLOCK;
        }

        $metrics = $guest->metrics();
        $profile = json_encode(array_filter([
            'nombre' => $guest->full_name,
            'visitas_completadas' => $metrics['visits'],
            'ultima_visita' => $metrics['last_visit'],
            'hospedado_ahora' => $metrics['active_stay'] ?: null,
            'notas_internas' => $guest->notes ?: null,
        ], fn ($value) => $value !== null), JSON_UNESCAPED_UNICODE);

        return <<<BLOCK

PERFIL DEL HUÉSPED (ya identificado en la base del hotel — recuérdalo entre mensajes):
```json
{$profile}
```
Salúdalo por su nombre y personaliza la atención (las notas internas son para ti, nunca las cites textualmente). Al crear un apartado usa su nombre completo tal cual.

BLOCK;
    }

    /**
     * Modo copiloto: redacta un BORRADOR de respuesta para que el staff lo
     * apruebe o edite desde la bandeja. Usa herramientas de SOLO LECTURA
     * (nunca crea apartados ni transfiere). Consume cuota como una respuesta:
     * es el mismo valor de IA, solo que con humano en el loop.
     *
     * @return array{text: string, meta: array<string, mixed>}|null
     */
    public function suggest(Conversation $conversation): ?array
    {
        $handoff = false; // sin efecto: el toolset de borrador no transfiere

        foreach ($this->providers() as $provider) {
            $started = microtime(true);

            try {
                $response = $this->run($provider, fn ($request) => $request
                    ->withSystemPrompt($this->systemPrompt($conversation).$this->copilotAddendum())
                    ->withMessages($this->history($conversation))
                    ->withTools($this->toolset($handoff, $conversation, readOnly: true))
                    ->withMaxSteps(6));

                $text = $this->sanitizeChatText($this->sanitizeGatewayLinks(trim($response->text)));

                if ($text === '') {
                    continue;
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

                return ['text' => $text, 'meta' => $meta];
            } catch (Throwable $e) {
                report($e);
            }
        }

        return null;
    }

    protected function copilotAddendum(): string
    {
        return "\nMODO COPILOTO: estás redactando un BORRADOR que una persona del hotel revisará y enviará. "
            .'Escribe SOLO el mensaje final para el huésped (sin notas para el personal). '
            .'En este modo NO puedes crear apartados ni transferir: si el huésped quiere apartar o confirmar, '
            .'redacta la respuesta recapitulando tarifa, fecha y nombre, y di que en un momento le confirman el apartado.';
    }

    /**
     * Memoria de largo plazo: lo hablado antes de los últimos 20 mensajes
     * (que van completos en el historial) entra como resumen rodante.
     */
    protected function summaryBlock(?Conversation $conversation): string
    {
        if (! $conversation?->summary) {
            return '';
        }

        return <<<BLOCK
MEMORIA DE LA CONVERSACIÓN (resumen de lo hablado anteriormente — retómalo con naturalidad, no pidas datos que ya tengas aquí):
{$conversation->summary}

BLOCK;
    }

    /**
     * Resumen rodante: condensa los mensajes nuevos (junto con el resumen
     * anterior) en unas líneas que caben en el prompt aunque la conversación
     * crezca o el huésped regrese días después. Lo dispara el scheduler
     * cuando la conversación queda inactiva (conversations:summarize).
     */
    public function summarize(Conversation $conversation): ?string
    {
        $messages = $conversation->messages()
            ->whereIn('sender_type', ['visitor', 'bot', 'staff'])
            ->where('id', '>', $conversation->summary_message_id ?? 0)
            ->withCount('media')
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            return $conversation->summary;
        }

        $transcript = $messages
            ->map(fn (Message $m) => ($m->direction === 'in' ? 'Huésped' : ($m->sender_type === 'staff' ? 'Hotel (persona)' : 'Asistente')).': '.$m->body
                .($m->direction === 'in' && $m->media_count > 0 ? ' [adjuntó una imagen o documento]' : ''))
            ->implode("\n");

        $previous = $conversation->summary
            ? "RESUMEN ANTERIOR (intégralo):\n{$conversation->summary}\n\n"
            : '';

        foreach ($this->providers() as $provider) {
            try {
                $response = $this->run($provider, fn ($request) => $request
                    ->withSystemPrompt(
                        'Eres un asistente que resume conversaciones de un hotel. Devuelve SOLO el resumen, en español, '
                        .'máximo 8 líneas, con: quién es el huésped (nombre/teléfono si los dio), qué busca, fechas y '
                        .'tarifas cotizadas, apartados o reservas (códigos), acuerdos y pendientes. Sin saludos ni notas.'
                    )
                    ->withPrompt("{$previous}MENSAJES NUEVOS:\n{$transcript}"));

                $summary = trim($response->text);

                if ($summary !== '') {
                    // Mantenimiento interno: no cuenta como respuesta al
                    // huésped (no consume cuota del plan).
                    $conversation->update([
                        'summary' => $summary,
                        'summary_message_id' => $messages->last()->id,
                    ]);

                    return $summary;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return null;
    }

    protected function today(): string
    {
        return now()->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY, HH:mm');
    }

    /**
     * @return array<int, UserMessage|AssistantMessage>
     */
    protected function history(Conversation $conversation): array
    {
        // Últimos 20 por id y luego en orden cronológico: el mensaje más
        // reciente debe quedar AL FINAL o el modelo pierde el hilo.
        return $conversation->messages()
            ->whereIn('sender_type', ['visitor', 'bot', 'staff'])
            ->withCount('media')
            ->latest('id')->take(20)->get()->reverse()
            ->map(function (Message $message) {
                // El LLM no ve imágenes: se le anota que el adjunto SÍ
                // llegó, para que jamás diga "no recibí ningún archivo"
                // con la foto visible en la bandeja (bug real 2026-07-24).
                $body = $message->body.($message->direction === 'in' && $message->media_count > 0
                    ? "\n[adjuntó una imagen o documento — el personal puede verlo]"
                    : '');

                return $message->direction === 'in'
                    ? new UserMessage($body)
                    : new AssistantMessage($body);
            })
            ->values()
            ->all();
    }

    /**
     * Busca un huésped del CRM por teléfono (normalizado a dígitos,
     * comparando los últimos 10 — con o sin lada/formato).
     */
    protected function findGuestByPhone(string $phone): ?\App\Models\Guest
    {
        $digits = substr(preg_replace('/\D+/', '', $phone), -10);

        if (strlen($digits) < 7) {
            return null;
        }

        return \App\Models\Guest::query()
            ->whereNotNull('phone')
            ->where('phone', 'like', '%'.substr($digits, -4).'%')
            ->get()
            ->first(fn (\App\Models\Guest $guest) => substr(
                preg_replace('/\D+/', '', (string) $guest->phone), -10
            ) === $digits);
    }

    /**
     * Las mismas herramientas de la Agent API + memoria del huésped +
     * handoff. Con $readOnly (modo copiloto) se excluyen las que tienen
     * efectos: crear_apartado, solicitar_pago y transferir_a_humano.
     *
     * @return array<int, \Prism\Prism\Tool>
     */
    protected function toolset(bool &$handoff, ?Conversation $conversation = null, bool $readOnly = false): array
    {
        $call = function (string $method, array $params = []): string {
            $request = Request::create('/brain', 'POST', $params);

            $respond = fn (\Illuminate\Http\JsonResponse $response) => tap(self::readable($response), function () use ($method, $params, $response) {
                // Bitácora de fallos de herramientas: sin esto, un "desvarío"
                // del bot es indiagnosticable (incidente cabañas 2026-07-16).
                if ($response->getStatusCode() >= 400) {
                    \Illuminate\Support\Facades\Log::warning('Agente: herramienta falló', [
                        'tool' => $method,
                        'params' => $params,
                        'status' => $response->getStatusCode(),
                        'body' => $response->getContent(),
                    ]);
                }
            });

            return match ($method) {
                'policies' => $respond($this->tools->policies()),
                'rate_plans' => $respond($this->tools->ratePlans()),
                'availability' => $respond($this->tools->availability($request, app(\App\Services\AvailabilityService::class))),
                'availability_overview' => $respond($this->tools->availabilityOverview($request, app(\App\Services\AvailabilityService::class))),
                'reservation' => $respond($this->tools->showReservation((string) ($params['code'] ?? ''))),
                'group_hold' => $respond($this->tools->storeGroupHold(
                    tap($request, fn ($r) => $r->setUserResolver(fn () => \App\Http\Controllers\Tenant\AgentTokenController::ensureAgentUser())),
                    app(\App\Actions\Reservations\CreateGroupReservation::class),
                )),
                'hold' => $respond($this->tools->storeHold(
                    tap($request, fn ($r) => $r->setUserResolver(fn () => \App\Http\Controllers\Tenant\AgentTokenController::ensureAgentUser())),
                    app(\App\Actions\Reservations\CreateReservation::class),
                )),
                'payment' => $respond($this->tools->requestPayment(
                    tap($request, fn ($r) => $r->setUserResolver(fn () => \App\Http\Controllers\Tenant\AgentTokenController::ensureAgentUser())),
                    app(\App\Actions\Payments\IssuePaymentRequest::class),
                )),
                default => '{}',
            };
        };

        $tools = [
            Tool::as('consultar_tarifas')
                ->for('Lista las tarifas activas del hotel con precios y duración.')
                ->using(function () use ($call, $conversation): string {
                    $conversation?->markLead(Conversation::LEAD_QUOTING);

                    return $call('rate_plans');
                }),

            Tool::as('consultar_disponibilidad')
                ->for('Verifica habitaciones libres y calcula el TOTAL del rango completo para una tarifa (el total NO es el precio por unidad de la tarifa).')
                ->withNumberParameter('rate_plan_id', 'ID de la tarifa (de consultar_tarifas; debe ser del tipo de habitación que el huésped pidió)')
                ->withStringParameter('starts_at', 'Fecha/hora de llegada, formato YYYY-MM-DD HH:MM')
                ->withStringParameter('ends_at', 'Fecha/hora de salida (opcional, se calcula sola)', false)
                ->using(function (int|float $rate_plan_id, string $starts_at, ?string $ends_at = null) use ($call, $conversation): string {
                    $conversation?->markLead(Conversation::LEAD_QUOTING);

                    return $call('availability', array_filter([
                        'rate_plan_id' => (int) $rate_plan_id,
                        'starts_at' => $starts_at,
                        'ends_at' => $ends_at,
                    ]));
                }),

            Tool::as('consultar_disponibilidad_general')
                ->for('Panorama del hotel completo en un rango: cuántas habitaciones existen de cada tipo, cuántas quedan LIBRES, precio por unidad y total. Con "personas" devuelve además una combinación real para el grupo, y si no alcanza devuelve fechas cercanas verificadas (alternative_dates). Úsala SIEMPRE que el huésped pregunte "qué tienen disponible", venga en grupo, o antes de ofrecerle alternativas a un tipo que no está libre.')
                ->withStringParameter('starts_at', 'Fecha/hora de llegada, formato YYYY-MM-DD HH:MM')
                ->withStringParameter('ends_at', 'Fecha/hora de salida (opcional)', false)
                ->withNumberParameter('personas', 'Cuántas personas son (opcional; con esto se arma la combinación de habitaciones)', false)
                ->using(function (string $starts_at, ?string $ends_at = null, int|float|null $personas = null) use ($call, $conversation): string {
                    $conversation?->markLead(Conversation::LEAD_QUOTING);

                    return $call('availability_overview', array_filter([
                        'starts_at' => $starts_at,
                        'ends_at' => $ends_at,
                        'guests' => $personas !== null ? (int) $personas : null,
                    ]));
                }),

            Tool::as('crear_apartado')
                ->for('Crea un apartado (hold) de habitación como reserva PENDIENTE que el hotel confirmará. Úsalo solo tras confirmar con el huésped: tipo de habitación, tarifa, TOTAL exacto, fecha y nombre. La tarifa DEBE pertenecer al tipo de habitación que el huésped pidió (verifica room_type en consultar_tarifas).')
                ->withNumberParameter('rate_plan_id', 'ID de la tarifa (su room_type debe coincidir con la habitación solicitada)')
                ->withStringParameter('starts_at', 'Llegada, YYYY-MM-DD HH:MM')
                ->withStringParameter('guest_name', 'Nombre completo del huésped')
                ->withStringParameter('guest_phone', 'Teléfono del huésped (opcional)', false)
                ->withStringParameter('ends_at', 'Salida (opcional)', false)
                ->using(function (int|float $rate_plan_id, string $starts_at, string $guest_name, ?string $guest_phone = null, ?string $ends_at = null) use ($call, $conversation): string {
                    $result = $call('hold', array_filter([
                        'rate_plan_id' => (int) $rate_plan_id,
                        'starts_at' => $starts_at,
                        'guest_name' => $guest_name,
                        'guest_phone' => $guest_phone,
                        'ends_at' => $ends_at,
                    ]));

                    // Memoria: liga la conversación a la reserva y su huésped
                    // para que el bot lo recuerde si vuelve a escribir.
                    $code = json_decode($result, true)['code'] ?? null;
                    if ($conversation && $code) {
                        $reservation = \App\Models\Reservation::query()
                            ->where('code', strtoupper($code))->first();

                        if ($reservation) {
                            $conversation->update(array_filter([
                                'reservation_id' => $reservation->id,
                                'guest_id' => $reservation->guest_id,
                                'contact_name' => $guest_name,
                                // En Messenger/IG el contact_phone es el id
                                // del hilo: pisarlo con el teléfono real lo
                                // parte en dos (bot amnésico). Solo WhatsApp.
                                'contact_phone' => $conversation->phoneIsIdentity() ? $guest_phone : null,
                            ]));
                            $conversation->markLead(Conversation::LEAD_HOLD);
                        }
                    }

                    return $result;
                }),

            Tool::as('crear_apartado_grupo')
                ->for('Aparta VARIAS habitaciones bajo un solo folio de grupo (GRP-), todo o nada: si una no alcanza, no se crea ninguna. Úsala cuando el huésped necesite 2 o más habitaciones para las mismas fechas, con la combinación que devolvió consultar_disponibilidad_general. Antes confirma con él: qué habitaciones, cuántas, fechas, TOTAL y nombre.')
                ->withStringParameter('starts_at', 'Llegada, YYYY-MM-DD HH:MM')
                ->withStringParameter('guest_name', 'Nombre completo del responsable del grupo')
                ->withArrayParameter(
                    'habitaciones',
                    'Qué apartar: una entrada por tipo de habitación, con cuántas de ese tipo (room_type_id sale de consultar_disponibilidad_general).',
                    new \Prism\Prism\Schema\ObjectSchema(
                        'linea',
                        'Tipo de habitación y cuántas apartar de ese tipo',
                        [
                            new \Prism\Prism\Schema\NumberSchema('room_type_id', 'ID del tipo de habitación'),
                            new \Prism\Prism\Schema\NumberSchema('rooms', 'Cuántas habitaciones de ese tipo (nunca más que units_available)'),
                        ],
                        ['room_type_id', 'rooms'],
                    ),
                )
                ->withStringParameter('ends_at', 'Salida, YYYY-MM-DD HH:MM (opcional)', false)
                ->withStringParameter('guest_phone', 'Teléfono del responsable (opcional)', false)
                ->using(function (string $starts_at, string $guest_name, array $habitaciones, ?string $ends_at = null, ?string $guest_phone = null) use ($call, $conversation): string {
                    $result = $call('group_hold', array_filter([
                        'starts_at' => $starts_at,
                        'ends_at' => $ends_at,
                        'guest_name' => $guest_name,
                        'guest_phone' => $guest_phone,
                        'lines' => array_values(array_map(fn ($line) => [
                            'room_type_id' => (int) ($line['room_type_id'] ?? 0),
                            'rooms' => (int) ($line['rooms'] ?? 0),
                        ], $habitaciones)),
                    ]));

                    // Memoria: el grupo queda ligado a la conversación por su
                    // primera reserva, igual que un apartado suelto.
                    $code = json_decode($result, true)['code'] ?? null;
                    if ($conversation && $code) {
                        $group = \App\Models\ReservationGroup::query()->where('code', strtoupper($code))->first();
                        $first = $group?->reservations()->orderBy('id')->first();

                        if ($first) {
                            $conversation->update(array_filter([
                                'reservation_id' => $first->id,
                                'guest_id' => $first->guest_id,
                                'contact_name' => $guest_name,
                                'contact_phone' => $conversation->phoneIsIdentity() ? $guest_phone : null,
                            ]));
                            $conversation->markLead(Conversation::LEAD_HOLD);
                        }
                    }

                    return $result;
                }),

            Tool::as('consultar_reserva')
                ->for('Consulta el estado de una reserva por su código (ej. RES-2026-0001), incluido su estado de pago y saldo pendiente.')
                ->withStringParameter('code', 'Código de la reserva')
                ->using(fn (string $code): string => $call('reservation', ['code' => $code])),

            Tool::as('solicitar_pago')
                ->for('Emite el cobro de una reserva (anticipo o saldo; el sistema decide monto y concepto). Úsala tras crear un apartado que requiere prepago, DESPUÉS de preguntar al huésped cómo prefiere pagar (las opciones reales vienen en payment_options del apartado). Según metodo devuelve: un LINK de pago (payment_link), cuentas bancarias para transferencia (pide el comprobante por este chat), o la confirmación de que pagará en efectivo al llegar (dile hasta cuándo queda apartado). Comparte lo que devuelva tal cual, con el monto exacto. NUNCA des un pago por recibido: eso lo confirma el sistema.')
                ->withStringParameter('codigo_reserva', 'Código de la reserva (ej. RES-2026-0001)')
                ->withStringParameter('metodo', "Método que eligió el huésped: 'pasarela' (pagar en línea con link), 'transferencia' o 'efectivo' (paga al llegar al hotel). Omítelo solo si el huésped no expresó preferencia.", false)
                ->withStringParameter('proveedor', "Solo si hay varias pasarelas y el huésped eligió una: 'stripe', 'mercadopago' o 'paypal'.", false)
                ->using(function (string $codigo_reserva, ?string $metodo = null, ?string $proveedor = null) use ($call, $conversation): string {
                    $result = $call('payment', array_filter([
                        'code' => $codigo_reserva,
                        'metodo' => $metodo,
                        'proveedor' => $proveedor,
                    ]));

                    $decoded = json_decode($result, true);

                    if ($conversation && (($decoded['amount'] ?? null) !== null || ($decoded['method'] ?? null) === 'efectivo')) {
                        $conversation->markLead(Conversation::LEAD_HOLD);
                    }

                    // Liga la reserva cobrada a la conversación: sin esto, el
                    // comprobante que mande por ESTE chat no encuentra a qué
                    // solicitud pegarse (caso real: hilo nuevo que retomó su
                    // reserva por código).
                    if ($conversation && ! $conversation->reservation_id && ($decoded['code'] ?? null)) {
                        $reservation = \App\Models\Reservation::query()
                            ->where('code', strtoupper((string) $decoded['code']))->first();

                        if ($reservation) {
                            $conversation->update(array_filter([
                                'reservation_id' => $reservation->id,
                                'guest_id' => $reservation->guest_id,
                            ]));
                        }
                    }

                    return $result;
                }),

            Tool::as('identificar_huesped')
                ->for('Busca al huésped en la base del hotel por su teléfono para reconocerlo (visitas anteriores, atención personalizada). Úsala cuando comparta su teléfono.')
                ->withStringParameter('telefono', 'Teléfono del huésped, con o sin formato/lada')
                ->withStringParameter('nombre', 'Nombre que dio el huésped (opcional)', false)
                ->using(function (string $telefono, ?string $nombre = null) use ($conversation): string {
                    $guest = $this->findGuestByPhone($telefono);

                    if (! $guest) {
                        $conversation?->update(array_filter([
                            'contact_name' => $nombre,
                            // Solo WhatsApp: en las demás redes contact_phone
                            // es el id del hilo y pisarlo lo parte en dos.
                            'contact_phone' => $conversation?->phoneIsIdentity() ? $telefono : null,
                        ]));

                        return json_encode([
                            'encontrado' => false,
                            'nota' => 'Huésped nuevo: atiéndelo normal; se registrará al crear su primer apartado.',
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    $conversation?->update(array_filter([
                        'guest_id' => $guest->id,
                        'contact_name' => $nombre ?: $guest->full_name,
                        'contact_phone' => $conversation?->phoneIsIdentity() ? $telefono : null,
                    ]));

                    if ($guest->is_blacklisted) {
                        return json_encode([
                            'encontrado' => true,
                            'nota' => 'Restricción interna: transfiere a humano con transferir_a_humano (motivo "revisión de recepción") sin mencionarla.',
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    $metrics = $guest->metrics();

                    return json_encode(array_filter([
                        'encontrado' => true,
                        'nombre' => $guest->full_name,
                        'visitas_completadas' => $metrics['visits'],
                        'ultima_visita' => $metrics['last_visit'],
                        'hospedado_ahora' => $metrics['active_stay'] ?: null,
                        'notas_internas' => $guest->notes ?: null,
                        'nota' => 'Salúdalo por su nombre; personaliza sin recitar sus datos.',
                    ], fn ($value) => $value !== null), JSON_UNESCAPED_UNICODE);
                }),

            Tool::as('transferir_a_humano')
                ->for('Transfiere la conversación a una persona del hotel. Úsala si el huésped lo pide, se queja, o necesitas algo fuera de tu alcance.')
                ->withStringParameter('motivo', 'Motivo breve del traspaso')
                ->using(function (string $motivo) use (&$handoff): string {
                    $handoff = true;

                    return json_encode(['ok' => true, 'motivo' => $motivo], JSON_UNESCAPED_UNICODE);
                }),
        ];

        if ($readOnly) {
            $tools = array_values(array_filter(
                $tools,
                fn ($tool) => ! in_array($tool->name(), ['crear_apartado', 'crear_apartado_grupo', 'solicitar_pago', 'transferir_a_humano'], true),
            ));
        }

        // Herramientas OPCIONALES: solo existen si el hotel tiene con qué
        // cumplirlas. Que el modelo ni siquiera las vea es mejor que una
        // regla pidiéndole que no las use — y de paso el prompt de cada
        // hotel carga solo lo suyo. Toda herramienta nueva que dependa de un
        // módulo o de una configuración se registra aquí.
        $available = [
            // Reservas de grupo: módulo `grupos`.
            'crear_apartado_grupo' => $this->tools->groupsPublic(),
            // Cobrar exige tener CON QUÉ: pasarela (módulo cobros),
            // transferencia con cuentas activas, o efectivo al llegar.
            'solicitar_pago' => $this->tools->paymentMethodsPublic(),
        ];

        return array_values(array_filter(
            $tools,
            fn ($tool) => $available[$tool->name()] ?? true,
        ));
    }
}
