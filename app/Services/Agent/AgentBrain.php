<?php

namespace App\Services\Agent;

use App\Http\Controllers\Agent\AgentToolsController;
use App\Models\AiProvider;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Channels\StaffAlerter;
use App\Services\SupportHours;
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
    /** Marca en conversations.followups de que hubo una cotización con disponibilidad real. */
    public const REAL_QUOTE = 'real_quote';

    /** Vida del candado por conversación: el LLM tarda 4 s de mediana, 23 s en el peor caso. */
    protected const REPLY_LOCK_TTL = 60;

    /** Lo que espera una corrida a que la anterior termine antes de darse por vencida. */
    protected const REPLY_LOCK_WAIT = 25;

    /**
     * "Mañana" como día, no como la mañana: "de 9:00 de la mañana a 5:00 de
     * la tarde" es un horario, no una promesa.
     */
    protected const LATER_DAY = '/(?<!la\s)ma[ñn]ana/iu';

    /** De qué habla la promesa: pagar, transferir o recibir los datos para hacerlo. */
    protected const PAYMENT_WORD = '/(transfer|pag[oaué]|dep[óo]sit|datos|cuenta|link|liga)/iu';

    /** Verbos con los que el modelo anuncia un traspaso que no ejecutó. */
    protected const HANDOFF_VERB = '/(transfer[íi]\b|transferid[oa]\b|transferir\b|transfiero\b|transfiriendo\b|pas[oé] con\b|pasar[ée] con\b|paso tu|escalo\b)/iu';

    /** A quién dice pasarlo: sin un humano al otro lado no es un traspaso. */
    protected const HANDOFF_TARGET = '/(persona|personal|recepci[óo]n|equipo|alguien|compañer[oa]|encargad[oa])/iu';

    /** Lo que este producto no puede cumplir: aquí nadie devuelve llamadas. */
    /**
     * Frases con las que el bot le quita al huésped una reserva que SÍ
     * existe. Ninguna de las dos puede salir si su apartado está vivo.
     */
    protected const DENIES_RESERVATION = '/(no (aparece|est[áa]|figura) registrad|no aparece en (el|nuestro) sistema|no existe (esa|su|la) reserv|no encontramos (esa|su|ninguna) reserv)/iu';

    protected const CLAIMS_EXPIRED = '/(venci[óo]|ya venci|expir[óo]|se liber(?:ó|aron)|ya no (?:la|las) (?:tenemos|tiene)|perdi[óo] (?:su|el) apartado)/iu';

    protected const HANDOFF_PROMISE = '/(recibir[áa]s? una llamada|(?:te|le) (?:van a |va a )?llamar[áa]n?|(?:te|le) (?:van a |va a )?contactar[áa]n?|(?:te|le) contactar[áa]|(?:te|le) responder[áa]n? (?:en breve|pronto|en un momento))/iu';

    /**
     * El mismo verbo en sentido dinero: "puedes transferir a la cuenta y el
     * personal lo verifica" NO es un traspaso. Sin esta salvedad, cada
     * mensaje con datos bancarios mandaba la conversación a la bandeja.
     */
    protected const HANDOFF_MONEY = '/(cuenta|banco|bbva|clabe|dep[óo]sito|comprobante|pago|transferencia|monto|\$)/iu';

    /**
     * El traspaso anunciado como encargo: "ya pedí al personal que le envíe
     * las fotos", "voy a pasar tu mensaje al personal". Caso real cabañas
     * 2026-09-13/14 (conv. 392, 618, 624, 714): lo dijo con el bot encendido
     * y nadie del hotel se enteró.
     */
    protected const HANDOFF_ASK = '/(ped[íi]\b|pedir[ée]\b|(?:le|les) pido\b|avis[ée] a\b|aviso a\b|pasar[ée]? (?:tu|su) (?:mensaje|solicitud|consulta|caso|pregunta|petici[óo]n))/iu';

    /**
     * "Lo transfiero para que le envíen las fotos": el pronombre de persona
     * lo vuelve traspaso aunque no nombre a nadie (conv. 764, dos veces). El
     * dinero se transfiere "a la cuenta", nunca "lo transfiero".
     */
    protected const HANDOFF_PRONOUN = '/\b(?:lo|la|le|te|los|las)\s+(?:transfiero|transferir[ée]|voy a transferir)\b/iu';

    /** El bot dando por hecho un pago: "Anticipo de $1,500 pagado", "recibimos tu pago". */
    protected const CLAIMS_PAID = '/((?:anticipo|pago|saldo|dep[óo]sito|transferencia)[^.\n]{0,25}\b(?:pagad[oa]|recibid[oa]|confirmad[oa]|acreditad[oa]|registrad[oa]|verificad[oa])\b|recibimos (?:tu|su) (?:pago|dep[óo]sito|transferencia)|ya (?:qued[óo]|est[áa]) pagad)/iu';

    /** "Una vez que el pago sea verificado…" habla del futuro, no afirma nada. */
    protected const PAYMENT_CONDITIONAL = '/\b(una vez|cuando|en cuanto|hasta que|despu[ée]s de que|al quedar|si ya)\b/iu';

    /** Amenazar con cancelar a quien ya puso dinero o mandó su comprobante. */
    protected const THREATENS_CANCEL = '/(se cancela\b|ser[áa] cancelad|se cancelar[áa]|queda cancelad|pierde (?:su|tu) (?:reserva|apartado))/iu';

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
     * Punto de entrada de los canales: serializa la respuesta por conversación.
     *
     * WhatsApp entrega cada mensaje en su propio webhook, así que tres
     * mensajes seguidos son tres procesos a la vez: cada uno armaba su
     * historial sin ver la respuesta del otro y contestaba por separado.
     * Caso real cabañas 2026-09-11: 96 pares de respuestas a menos de 20
     * segundos en 62 conversaciones y, en cuatro de ellas, las dos
     * respuestas cotizaban totales distintos para la misma noche.
     *
     * El candado pone las corridas en fila; ya adentro, la que no trae el
     * último mensaje del huésped se retira, porque la que sí lo trae va a
     * contestar la ráfaga completa: su historial ya la incluye entera.
     */
    public function replyTo(Conversation $conversation, Message $inbound): ?Message
    {
        $lock = \Illuminate\Support\Facades\Cache::lock(
            'agent:reply:'.tenant('id').':'.$conversation->id,
            self::REPLY_LOCK_TTL,
        );

        try {
            // Esperar, no tirar: la ráfaga se contesta junta.
            $lock->block(self::REPLY_LOCK_WAIT);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            // Nadie debería tardar tanto (p99 real: 15 s). Si el candado se
            // quedó colgado, dejar al huésped sin respuesta es peor que
            // arriesgar un duplicado.
            report(new \RuntimeException("Agente: candado de respuesta agotado en la conversación {$conversation->id}"));

            return $this->reply($conversation);
        }

        try {
            if (! $this->isLatestInbound($conversation, $inbound)) {
                return null;
            }

            return $this->reply($conversation->refresh(), $inbound);
        } finally {
            $lock->release();
        }
    }

    /**
     * ¿Este entrante sigue siendo el último del huésped? Si ya llegó otro,
     * esta corrida se calla: la del mensaje nuevo los contesta todos.
     */
    protected function isLatestInbound(Conversation $conversation, Message $inbound): bool
    {
        return (int) $conversation->messages()->where('direction', 'in')->max('id') <= $inbound->id;
    }

    /**
     * ¿Llegó, después de este, otro mensaje ESCRITO del huésped? Solo esos
     * despiertan otra corrida del bot; una foto sola no.
     */
    protected function newerTextInbound(Conversation $conversation, Message $inbound): bool
    {
        return $conversation->messages()
            ->where('direction', 'in')
            ->where('id', '>', $inbound->id)
            ->whereDoesntHave('media')
            ->whereNotIn('body', ['[Imagen]', '[Documento]'])
            ->exists();
    }

    /**
     * Genera y guarda la respuesta del bot probando la cadena de proveedores;
     * si todos fallan (o pide humano), hace handoff a la bandeja.
     *
     * @param  Message|null  $inbound  El mensaje que se contesta: si mientras
     *                                 se generaba llegó otro, la respuesta se tira.
     */
    public function reply(Conversation $conversation, ?Message $inbound = null): ?Message
    {
        $handoff = false;
        $handoffReason = '';
        $text = '';
        $meta = [];
        $used = [];
        $answeredBy = null;

        foreach ($this->providers() as $provider) {
            $started = microtime(true);

            try {
                $response = $this->run($provider, fn ($request) => $request
                    ->withSystemPrompt($this->systemPrompt($conversation))
                    ->withMessages($this->history($conversation))
                    ->withTools($this->toolset($handoff, $conversation, false, $used, $handoffReason))
                    ->withMaxSteps(6));

                $text = trim($response->text);
                $answeredBy = $provider;
                $meta = [
                    'provider' => $provider->provider,
                    'model' => $provider->model,
                    'platform' => (bool) ($provider->platform ?? false),
                    'ms' => (int) round((microtime(true) - $started) * 1000),
                    'prompt_tokens' => $response->usage->promptTokens ?? null,
                    'completion_tokens' => $response->usage->completionTokens ?? null,
                    // Tokens que el proveedor cobró como caché (repetidos del
                    // prefijo). Sin registrarlos no hay forma de saber si el
                    // caché está funcionando: MiniMax-M2 devolvía 0 siempre y
                    // nadie se enteró — se pagaron 12,650 tokens fijos en cada
                    // respuesta durante meses.
                    'cached_tokens' => $response->usage->cacheReadInputTokens ?? null,
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

        // Llegó otro mensaje de texto mientras se generaba: esta respuesta no
        // lo vio. Se tira sin guardar ni enviar, y la corrida del mensaje
        // nuevo contesta todo junto. Caso real cabañas 2026-09-14 (Kevin,
        // 17:39 y 17:52): dos respuestas seguidas, una ofrecía el 10 de
        // octubre y la otra el 27 de septiembre, y una decía "para 2
        // personas" cuando eran 9. El candado solo las ponía en fila.
        // Una foto no cuenta: esa no despierta al bot y dejaría al huésped
        // sin respuesta.
        if ($inbound !== null && ! $handoff && $this->newerTextInbound($conversation, $inbound)) {
            \Illuminate\Support\Facades\Log::info('Agente: respuesta descartada, llegó otro mensaje mientras se generaba', [
                'conversation_id' => $conversation->id,
                'inbound_id' => $inbound->id,
            ]);

            return null;
        }

        $hours = app(SupportHours::class);

        if ($handoff || $text === '') {
            $this->markHandoff($conversation, $handoffReason);

            return $conversation->messages()->create([
                'direction' => 'out',
                'sender_type' => 'system',
                'body' => $this->handoffLine(),
                'meta' => $meta ?: null,
                'created_at' => now(),
            ]);
        }

        // Solo español o inglés: una respuesta en otro alfabeto se vuelve a
        // redactar ANTES de sanearla (el saneador solo recorta letras sueltas).
        $text = $this->enforceLanguage($text, $answeredBy);

        $body = $this->sanitizeChatText($this->sanitizeClockClaims($this->sanitizeBankBlocks($this->sanitizeBankNumbers($this->sanitizeGatewayLinks(
            $this->enforceLiveReservationClaims(
                $this->enforcePaymentClaims(
                    $this->enforceHoldDeadlineClaims(
                        $this->enforceHandoffClaims(
                            $this->enforceCouponClaims(
                                $this->enforceAvailabilityClaims($text, $conversation),
                                $conversation,
                            ),
                            $conversation,
                        ),
                        $conversation,
                    ),
                    $conversation,
                ),
                $conversation,
            ),
            $conversation,
        )))));

        // Teléfonos, correos y ligas: solo los del hotel, los del huésped o
        // los del propio sistema. Va al final, sobre el texto ya saneado.
        $body = $this->sanitizeContactData($body, $conversation);

        if ($hours->isClosed()) {
            // El aviso de "ya no estamos" va pegado a la respuesta y UNA vez
            // al día: repetirlo en cada mensaje del hilo es peor que callarlo.
            $noticeKey = 'after_hours_notice:'.now()->toDateString();

            if (! $conversation->followupSent($noticeKey)) {
                $conversation->markFollowup($noticeKey);
                $body .= "\n\n".$hours->afterHoursNotice();
            }

            // Y el hotel se entera SOLO si hubo intención de compra: que
            // vibre el teléfono del dueño por un "hola" es la mejor forma
            // de que apague los avisos.
            if (array_intersect($used, ['rate_plans', 'availability', 'availability_overview', 'hold', 'group_hold', 'payment', 'reopen_hold'])) {
                $this->alertStaff($conversation, StaffAlerter::KIND_AFTER_HOURS);
            }
        }

        $conversation->update(['last_message_at' => now()]);
        $this->markQuotedLead($conversation, $body);

        return $conversation->messages()->create([
            'direction' => 'out',
            'sender_type' => 'bot',
            'body' => $body,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }

    /**
     * Traspaso de verdad: la conversación cae a la bandeja en pendiente y el
     * hotel se entera. De noche nadie tiene el panel abierto, así que el
     * aviso sale por WhatsApp y no al otro día.
     */
    protected function markHandoff(Conversation $conversation, string $reason = ''): void
    {
        $conversation->update(['bot_enabled' => false, 'status' => Conversation::STATUS_PENDING]);

        $this->alertStaff($conversation, StaffAlerter::KIND_HANDOFF, $reason);
    }

    /**
     * Prometer "en un momento te atienden" a las 11 de la noche es mentirle
     * al huésped: fuera de horario se le dice cuándo. Una sola fuente para
     * esta frase — la usan el traspaso por herramienta y el que se descubre
     * en el texto del modelo.
     */
    protected function handoffLine(): string
    {
        $hours = app(SupportHours::class);

        return $hours->isOpen()
            ? 'Te comunicamos con una persona del hotel; en un momento te atienden.'
            : 'Le paso tu mensaje al equipo del hotel. Te contactan '.$hours->nextOpeningLabel().'.';
    }

    /**
     * TRASPASAR ES UNA ACCIÓN, NO UN ANUNCIO. El prompt lo pide desde el
     * 7-sep y el modelo siguió anunciándolo de todos modos: caso real
     * cabañas 2026-09-11, 27 mensajes prometieron un traspaso ("ya transferí
     * tu solicitud", "recibirá una llamada pronto") contra 4 traspasos de
     * verdad. Sin la herramienta nadie en el hotel se enteraba — ni la
     * bandeja en pendiente, ni la campana, ni el aviso al dueño — y el
     * huésped esperaba una llamada que este producto no hace.
     *
     * Si el texto lo anuncia y la herramienta no corrió, aquí se cumple la
     * promesa: traspaso real, y la frase honesta en lugar de la inventada.
     * Llegar hasta aquí ya implica que transferir_a_humano NO se llamó (ese
     * camino sale antes, con su mensaje de sistema).
     */
    protected function enforceHandoffClaims(string $text, ?Conversation $conversation): string
    {
        if ($conversation === null || trim($text) === '' || ! $this->claimsHandoff($text)) {
            return $text;
        }

        \Illuminate\Support\Facades\Log::warning('Agente: anunció un traspaso sin llamar la herramienta', [
            'conversation_id' => $conversation->id,
            'texto' => $text,
        ]);

        $this->markHandoff($conversation, 'El asistente anunció el traspaso en su respuesta.');

        // La promesa inventada se cae completa: el huésped no puede quedarse
        // con "recibirá una llamada" al lado de la frase verdadera.
        $kept = collect(preg_split('/\R+/u', $text) ?: [])
            ->reject(fn (string $line) => $this->claimsHandoff($line))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->implode("\n");

        return trim($kept."\n\n".$this->handoffLine());
    }

    /**
     * Marca el embudo cuando el bot SUELTA UN PRECIO, lo haya sacado de una
     * herramienta o no.
     *
     * markLead(QUOTING) solo vivía dentro de los manejadores de herramienta,
     * pero este hotel pegó su lista de tarifas en las instrucciones de texto
     * libre, así que el bot contesta "¿precios?" de memoria y sin tocar
     * ninguna herramienta. Medido en el corpus del VPS (2026-09-12): de 382
     * conversaciones donde el bot dio un precio, 252 seguían en `new`. Y el
     * único seguimiento que recupera gente (`quote_nudge`, 24% de respuesta
     * sobre 115 envíos reales) solo persigue a las que están en `quoting`:
     * esas 252 personas cotizadas jamás recibieron nada.
     *
     * markLead ya respeta el sentido de la venta, así que llamarlo de más
     * no puede retroceder un apartado ni una venta ganada.
     */
    protected function markQuotedLead(Conversation $conversation, string $body): void
    {
        if (preg_match('/\$\s?\d/u', $body) !== 1) {
            return;
        }

        $conversation->markLead(Conversation::LEAD_QUOTING);
    }

    /**
     * Marca que esta conversación llegó a una COTIZACIÓN REAL: la
     * herramienta de disponibilidad confirmó al menos una habitación libre
     * para fechas concretas. Es lo único que distingue a un huésped que
     * está comprando de uno que pidió la lista de precios y se fue.
     *
     * Contar mensajes no sirve para eso: aquí la gente escribe en
     * fragmentos ("Buenas tardes" / "Para 2 personas" / "Mañana" ya son
     * tres mensajes sin una sola intención). Caso real cabañas
     * 2026-09-12: con el filtro por conteo, el bot todavía reenganchó a
     * la conv. 550 —a quien acababa de decirle dos veces que no había
     * nada— y a la 569, que nunca dio una fecha.
     */
    protected function markRealQuote(?Conversation $conversation, string $json): void
    {
        if ($conversation === null || $conversation->followupSent(self::REAL_QUOTE)) {
            return;
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            return;
        }

        // consultar_disponibilidad responde con un available suelto; el
        // panorama trae un available por tipo de habitación.
        $available = ($data['available'] ?? false) === true
            || collect($data['room_types'] ?? [])->contains(fn ($type) => ($type['available'] ?? false) === true);

        if ($available) {
            $conversation->markFollowup(self::REAL_QUOTE);
        }
    }

    /** ¿Este texto anuncia un traspaso o una devolución de llamada? */
    protected function claimsHandoff(string $text): bool
    {
        if (preg_match(self::HANDOFF_PROMISE, $text) === 1) {
            return true;
        }

        // Encargarle algo al personal ES traspaso aunque hable de la cuenta:
        // "ya pedí al personal que te envíe los datos de la cuenta" dejó a la
        // huésped esperando 1 h 12 min (conv. 618). Pedir que verifiquen un
        // comprobante no: eso el sistema ya se lo avisa al hotel.
        if (preg_match(self::HANDOFF_ASK, $text) === 1
            && preg_match(self::HANDOFF_TARGET, $text) === 1
            && preg_match('/(verifi[cq]|revis)/iu', $text) !== 1) {
            return true;
        }

        // El verbo en sentido dinero no cuenta ("transfiere a esta cuenta y
        // el personal lo verifica" aparece en casi todo mensaje de pago).
        if (preg_match(self::HANDOFF_MONEY, $text) === 1) {
            return false;
        }

        if (preg_match(self::HANDOFF_PRONOUN, $text) === 1) {
            return true;
        }

        return preg_match(self::HANDOFF_VERB, $text) === 1
            && preg_match(self::HANDOFF_TARGET, $text) === 1;
    }

    /**
     * El bot no puede ofrecer una habitación que no está libre.
     *
     * Caso real cabañas 2026-09-14 (Kevin, conv. 744): el sábado 26 las ocho
     * cabañas estaban confirmadas y aun así le dijo "¿podría ser otra cabaña
     * para el 26 de septiembre? Tenemos la Luxury, Prisma o las Sencillas
     * disponibles para ese día". Reusó de memoria la lista del domingo 27 sin
     * volver a consultar, y el huésped pasó una hora eligiendo entre cabañas
     * que no existían.
     *
     * Cada frase que ofrece una habitación con nombre para una fecha se
     * verifica contra el MISMO motor de disponibilidad que usan el mostrador
     * y el wizard. Lo que no esté libre se borra y se antepone la verdad de
     * esa fecha. Las habitaciones que ya son de este huésped cuentan como
     * suyas, no como "no hay".
     */
    protected function enforceAvailabilityClaims(string $text, ?Conversation $conversation): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $types = \App\Models\RoomType::query()->where('active', true)->with('rooms')->get();

        if ($types->isEmpty()) {
            return $text;
        }

        $availability = app(\App\Services\AvailabilityService::class);
        $mine = $this->ownHeldTypeIds($conversation);
        $sentences = preg_split('/(?<=[.!?:\n])\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $date = null;
        $offending = [];
        $wrong = [];
        $freeLabels = [];
        // "Para el 27 tenemos estas cabañas disponibles:" y abajo la lista.
        // El nombre de la habitación va en su propio renglón, sin fecha ni
        // la palabra "disponible": la promesa la hereda del encabezado.
        $listing = false;

        foreach ($sentences as $sentence) {
            $dates = $this->datesMentioned($sentence);
            $date = $dates !== [] ? reset($dates) : $date;
            $bullet = preg_match('/^\s*[-•*\d]/u', $sentence) === 1;
            $claims = $this->claimsAvailability($sentence);
            $named = $this->roomTypesMentioned($sentence, $types);

            if ($claims && $named->isEmpty()) {
                $listing = $date !== null;

                continue;
            }

            if (! $bullet && ! $claims) {
                $listing = false;
            }

            if ($date === null || $named->isEmpty() || ! ($claims || ($listing && $bullet))) {
                continue;
            }

            $busy = $named->filter(fn (\App\Models\RoomType $type) => ! in_array($type->id, $mine, true)
                && ! $this->typeIsFree($type, $date, $availability));

            if ($busy->isEmpty()) {
                continue;
            }

            $offending[] = $sentence;
            $key = $date->toDateString();
            $wrong[$key] = array_values(array_unique([...($wrong[$key] ?? []), ...$busy->pluck('name')->all()]));
            $freeLabels[$key] ??= $types
                ->filter(fn (\App\Models\RoomType $type) => $this->typeIsFree($type, $date, $availability))
                ->pluck('name')
                ->values()
                ->all();
        }

        if ($offending === []) {
            return $text;
        }

        \Illuminate\Support\Facades\Log::warning('Agente: ofreció habitaciones que no están libres', [
            'conversation_id' => $conversation?->id,
            'texto' => $text,
        ]);

        $truth = [];

        foreach ($wrong as $day => $names) {
            $label = \Carbon\CarbonImmutable::parse($day)->locale('es')->isoFormat('dddd D [de] MMMM');
            $free = $freeLabels[$day] ?? [];

            $truth[] = 'Para el '.$label.', '.implode(' y ', $names).(count($names) === 1 ? ' ya no está disponible' : ' ya no están disponibles').'. '
                .($free === []
                    ? 'Ese día no queda ninguna habitación libre: dile la verdad y ofrécele otra fecha.'
                    : 'Lo que sí queda libre ese día: '.implode(', ', $free).'.');
        }

        $kept = collect($sentences)
            ->reject(fn (string $sentence) => in_array($sentence, $offending, true))
            ->map(fn (string $sentence) => trim($sentence))
            ->filter()
            ->implode(' ');

        return trim(implode(' ', $truth)."\n\n".$kept);
    }

    /** ¿Esta frase ofrece algo como libre? (y no lo contrario) */
    protected function claimsAvailability(string $sentence): bool
    {
        if (preg_match('/(no (hay|tenemos|queda|está|estan|están)|ya no|sin disponibilidad|ocupad|no est[áa] disponible|no disponible|lamento)/iu', $sentence) === 1) {
            return false;
        }

        return preg_match('/(disponible|disponibles|libre|libres|s[íi] hay|tenemos|queda|quedan|te la aparto|puedo apartar)/iu', $sentence) === 1;
    }

    /**
     * Tipos de habitación nombrados en la frase. "las Sencillas" nombra a
     * todas las de esa familia. El nombre del hotel se quita primero: en
     * "Cabañas Real de la Sierra" está el nombre de la Cabaña Real.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\RoomType>  $types
     * @return \Illuminate\Support\Collection<int, \App\Models\RoomType>
     */
    protected function roomTypesMentioned(string $sentence, $types)
    {
        $normalize = function (string $value): string {
            $value = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));

            return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
        };

        $hotel = $normalize((string) (\App\Models\Property::query()->first()?->name ?? ''));
        $haystack = $normalize($sentence);

        if ($hotel !== '') {
            $haystack = str_replace($hotel, ' ', $haystack);
        }

        return $types->filter(function (\App\Models\RoomType $type) use ($haystack, $normalize) {
            $key = trim((string) preg_replace('/^cabanas?\s+|^habitaci[o]n(?:es)?\s+/u', '', $normalize($type->name)));

            if ($key === '') {
                return false;
            }

            if (preg_match('/\b'.preg_quote($key, '/').'\b/u', $haystack) === 1) {
                return true;
            }

            // Familia en plural: "las Sencillas" son la 1, 2, 3 y 4.
            $family = trim((string) preg_replace('/\s*\d+$/', '', $key));

            return $family !== $key && $family !== ''
                && preg_match('/\b'.preg_quote($family, '/').'s?\b/u', $haystack) === 1;
        })->values();
    }

    /** ¿Queda al menos una habitación de este tipo esa noche? */
    protected function typeIsFree(\App\Models\RoomType $type, \Carbon\CarbonImmutable $date, \App\Services\AvailabilityService $availability): bool
    {
        [[$inHour, $inMinute], [$outHour, $outMinute]] = $type->effectiveScheduleTimes();

        $start = $date->setTime($inHour, $inMinute);
        $end = $date->addDay()->setTime($outHour, $outMinute);

        try {
            return $availability->availableRooms($type->id, $start, $end)->isNotEmpty();
        } catch (Throwable $e) {
            report($e);

            // Ante la duda, no se borra lo que dijo el modelo.
            return true;
        }
    }

    /**
     * Tipos que ESTE huésped ya tiene apartados: aparecen ocupados porque son
     * suyos, y decirle "ya no está disponible" sería negarle su reserva.
     *
     * @return array<int, int>
     */
    protected function ownHeldTypeIds(?Conversation $conversation): array
    {
        $reservation = $conversation?->reservation;

        if ($reservation === null) {
            return [];
        }

        return \App\Models\Reservation::query()
            ->when(
                $reservation->reservation_group_id,
                fn ($query, $group) => $query->where('reservation_group_id', $group),
                fn ($query) => $query->whereKey($reservation->id),
            )
            ->get()
            ->filter(fn (\App\Models\Reservation $r) => $r->isLiveHold()
                || in_array($r->status, [\App\Enums\ReservationStatus::Confirmed, \App\Enums\ReservationStatus::CheckedIn], true))
            ->pluck('room_type_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * El bot no puede prometer que el huésped pague OTRO DÍA si su apartado
     * vence hoy. Caso real cabañas 2026-09-13 (RES-2026-1727): vencía a las
     * 8:50 PM y el bot le dijo "puedes hacer la transferencia mañana" y "los
     * datos te los paso mañana"; a las 8:50 el sistema le avisó que venció.
     * El hotel decidió no sostener apartados de noche, así que la corrección
     * es decir la verdad: se quitan las promesas y va la hora real.
     */
    protected function enforceHoldDeadlineClaims(string $text, ?Conversation $conversation): string
    {
        $reservation = $conversation?->reservation;

        if ($reservation === null || trim($text) === '') {
            return $text;
        }

        $notice = app(\App\Services\ReservationPolicy::class)->holdDeadlineNotice($reservation);

        // Sin apartado vivo no hay plazo que contradecir; y si el apartado
        // aguanta hasta mañana, "mañana" puede ser verdad.
        if ($notice === null || $reservation->hold_expires_at->gte(now()->addDay()->startOfDay())) {
            return $text;
        }

        $lines = preg_split('/\R/u', $text) ?: [];

        if (! collect($lines)->contains(fn (string $line) => $this->promisesLaterPayment($line))) {
            return $text;
        }

        \Illuminate\Support\Facades\Log::warning('Agente: prometió pagar otro día con el apartado venciendo hoy', [
            'conversation_id' => $conversation->id,
            'reservation' => $reservation->displayCode(),
            'texto' => $text,
        ]);

        $kept = collect($lines)
            ->reject(fn (string $line) => $this->promisesLaterPayment($line))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->implode("\n");

        return trim($kept."\n\n".$notice);
    }

    /**
     * El bot no puede desaparecer una reserva que existe.
     *
     * Caso real cabañas 2026-09-14 (Abril Alejandra, GRP-2026-0149): apartó
     * 4 cabañas, transfirió $6,750 y mandó el comprobante; seis minutos
     * después el bot le dijo "el código GRP-2026-0149 no aparece
     * registrado" y que ya no había disponibilidad — las cuatro cabañas
     * eran suyas y seguían apartadas. El hotel le devolvió el dinero.
     *
     * Las herramientas ya devuelven el grupo y avisan de quién son las
     * habitaciones; esto es el cinturón por si el modelo igual se
     * equivoca: con un apartado VIVO en la conversación se quitan las
     * líneas que lo niegan o lo dan por vencido, se dice la verdad y se
     * avisa al personal.
     */
    protected function enforceLiveReservationClaims(string $text, ?Conversation $conversation): string
    {
        $reservation = $conversation?->reservation;

        if ($reservation === null || trim($text) === '') {
            return $text;
        }

        $vivas = $reservation->reservation_group_id
            ? \App\Models\Reservation::query()
                ->where('reservation_group_id', $reservation->reservation_group_id)
                ->get()
                ->filter(fn ($r) => $r->isLiveHold())
            : collect([$reservation])->filter(fn ($r) => $r->isLiveHold());

        if ($vivas->isEmpty()) {
            return $text;
        }

        $lines = preg_split('/\R/u', $text) ?: [];
        $ofensivas = collect($lines)->filter(
            fn (string $line) => preg_match(self::DENIES_RESERVATION, $line) === 1
                || preg_match(self::CLAIMS_EXPIRED, $line) === 1,
        );

        if ($ofensivas->isEmpty()) {
            return $text;
        }

        $folio = $reservation->group?->displayCode() ?? $reservation->displayCode();

        \Illuminate\Support\Facades\Log::warning('Agente: negó una reserva viva', [
            'conversation_id' => $conversation->id,
            'reservation' => $folio,
            'texto' => $text,
        ]);

        $this->alertStaff($conversation, 'reserva_negada', "El asistente estuvo a punto de decirle al huésped que {$folio} no existe o venció. Revisa la conversación.");

        $verdad = sprintf(
            'Tu %s %s sigue registrado con %d habitación(es) para el %s. El personal del hotel está revisando tu pago y en un momento te confirma.',
            $reservation->reservation_group_id ? 'grupo' : 'apartado',
            $folio,
            $vivas->count(),
            $vivas->sortBy('starts_at')->first()->starts_at->locale('es')->isoFormat('dddd D [de] MMMM'),
        );

        $kept = collect($lines)
            ->reject(fn (string $line) => preg_match(self::DENIES_RESERVATION, $line) === 1
                || preg_match(self::CLAIMS_EXPIRED, $line) === 1)
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->implode("\n");

        return trim($verdad."\n\n".$kept);
    }

    /**
     * El bot no da por pagado lo que el sistema no registró, ni amenaza con
     * cancelar a quien ya puso dinero o mandó su comprobante.
     *
     * Caso real cabañas 2026-09-14 (Damaris, RES-2026-1728): mandó la foto de
     * su transferencia y el bot le escribió "Anticipo de $1,500 pagado"; un
     * minuto después, "si no se paga a tiempo, la reserva se cancela". El
     * cobro seguía por verificar y ninguna de las dos cosas era verdad: el
     * pago lo confirma el sistema o el personal, y una reserva con dinero
     * encima no se cancela sola.
     */
    protected function enforcePaymentClaims(string $text, ?Conversation $conversation): string
    {
        if ($conversation === null || trim($text) === '') {
            return $text;
        }

        $reservation = $conversation->reservation;
        $reservations = match (true) {
            $reservation === null => collect(),
            $reservation->reservation_group_id !== null => \App\Models\Reservation::query()
                ->where('reservation_group_id', $reservation->reservation_group_id)
                ->get(),
            default => collect([$reservation]),
        };

        $paid = $reservations->contains(fn ($r) => $r->payment_status !== \App\Enums\PaymentStatus::Unpaid);
        $receipt = $reservations->isNotEmpty() && \App\Models\PaymentRequest::query()
            ->where(fn ($query) => $query
                ->whereIn('reservation_id', $reservations->pluck('id'))
                ->when($reservation->reservation_group_id, fn ($query, $group) => $query->orWhere('reservation_group_id', $group)))
            ->whereHas('media', fn ($query) => $query->where('collection_name', 'receipt'))
            ->exists();

        $claimsPaid = fn (string $sentence): bool => ! $paid
            && preg_match(self::CLAIMS_PAID, $sentence) === 1
            && preg_match(self::PAYMENT_CONDITIONAL, $sentence) !== 1;
        $threatens = fn (string $sentence): bool => ($paid || $receipt)
            && preg_match(self::THREATENS_CANCEL, $sentence) === 1;

        $removedPaid = false;
        $removed = false;

        // Por oración y no por renglón: el modelo suele mandar el párrafo
        // entero en una línea y tirarlo completo dejaría al huésped sin nada.
        $kept = collect(preg_split('/\R/u', $text) ?: [])
            ->map(function (string $line) use ($claimsPaid, $threatens, &$removedPaid, &$removed) {
                return collect(preg_split('/(?<=[.!?])\s+/u', trim($line)) ?: [])
                    ->reject(function (string $sentence) use ($claimsPaid, $threatens, &$removedPaid, &$removed) {
                        $paidClaim = $claimsPaid($sentence);
                        $offends = $paidClaim || $threatens($sentence);

                        $removedPaid = $removedPaid || $paidClaim;
                        $removed = $removed || $offends;

                        return $offends;
                    })
                    ->implode(' ');
            })
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->implode("\n");

        if (! $removed) {
            return $text;
        }

        \Illuminate\Support\Facades\Log::warning('Agente: afirmó un pago o amenazó con cancelar sin respaldo', [
            'conversation_id' => $conversation->id,
            'reservation' => $reservation?->displayCode(),
            'texto' => $text,
        ]);

        $note = match (true) {
            $receipt && ! $paid => 'El personal del hotel está verificando tu comprobante; en cuanto quede registrado te confirmamos por aquí.',
            $removedPaid => 'En cuanto el pago quede registrado en el sistema, te llega la confirmación por aquí.',
            default => null,
        };

        return trim($kept.($note ? "\n\n".$note : ''));
    }

    /** ¿Esta línea promete pagar (o pasar los datos para pagar) otro día? */
    protected function promisesLaterPayment(string $line): bool
    {
        return preg_match(self::LATER_DAY, $line) === 1
            && preg_match(self::PAYMENT_WORD, $line) === 1;
    }

    /** Avisar nunca rompe la conversación: es cortesía, no transacción. */
    protected function alertStaff(Conversation $conversation, string $kind, string $reason = ''): void
    {
        try {
            app(StaffAlerter::class)->alert($conversation, $kind, $reason);
        } catch (Throwable $e) {
            report($e);
        }
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
    public function sanitizeGatewayLinks(string $text, ?\App\Models\Conversation $conversation = null): string
    {
        // Cualquier subdominio de una pasarela, no una lista corta de hosts:
        // el bot inventó `https://pay.mercadopago.com.mx/XXXXXXXX` (caso real
        // cabañas 2026-09-13, RES-2026-1725) y el patrón viejo solo miraba
        // `www.mercadopago.com`, así que el link falso llegó al huésped.
        $pattern = '~https?://(?:[\w-]+\.)*(?:mercadopago|stripe|paypal|conekta|openpay|clip)\.[\w.]+/\S*~i';

        return preg_replace_callback($pattern, function (array $match) use ($conversation) {
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

            if ($request !== null) {
                return $request->publicReturnUrl().$trail;
            }

            // Link que no corresponde a ningún cobro: si la reserva de esta
            // conversación tiene uno vivo, ese es el bueno; si no, el link se
            // BORRA. Un link inventado es peor que no mandar ninguno, y jamás
            // se pone el cobro de otro huésped.
            $own = $conversation?->reservation?->paymentRequests()->active()->latest('id')->first();

            return ($own?->publicReturnUrl() ?? '').$trail;
        }, $text) ?? $text;
    }

    /**
     * Red de seguridad DETERMINISTA para datos de contacto.
     *
     * REGLA: el bot no puede escribir un teléfono, correo o liga que no le
     * hayamos dado. Solo pasan:
     * - los datos del hotel que recibe en su prompt (sitio, mapas, fotos de
     *   cada habitación, recorridos, aviso legal, teléfono, correo) y los
     *   teléfonos y WhatsApps de la configuración;
     * - lo que el propio huésped escribió o tiene en su ficha (repetirle su
     *   teléfono o su correo es legítimo);
     * - las ligas del propio sistema (el dominio del hotel en la plataforma:
     *   /pago, /reservar…).
     * Lo demás es inventado: una liga se quita, y un teléfono o correo se
     * cambia por el del hotel.
     *
     * Existe por el caso de la cuenta bancaria inventada (cabañas, conv. 635,
     * 2026-09-13): un huésped mandó $1,700 a un número que el modelo sacó de
     * la nada. Con un teléfono o una liga pasa lo mismo: el huésped confía en
     * lo que dice el chat del hotel.
     *
     * @param  array<string, mixed>|null  $allowed  Lista permitida (para tests):
     *                                              urls, hosts, emails, phones, bank_numbers, main_phone, main_email.
     */
    public function sanitizeContactData(string $text, ?Conversation $conversation = null, ?array $allowed = null): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $allowed ??= $this->allowedContactData($conversation);

        $normalizeUrl = fn (string $url) => strtolower(rtrim(preg_replace('~[?#].*$~', '', $url) ?? $url, '/'));
        $urls = array_map($normalizeUrl, $allowed['urls'] ?? []);
        $hosts = array_map('strtolower', $allowed['hosts'] ?? []);
        $emails = array_map(fn ($e) => strtolower(trim((string) $e)), $allowed['emails'] ?? []);
        $phones = array_values(array_filter(
            array_map(fn ($p) => substr(preg_replace('/\D/', '', (string) $p), -10), $allowed['phones'] ?? []),
            fn (string $p) => strlen($p) >= 7,
        ));
        $bank = array_values(array_filter(array_map(fn ($b) => preg_replace('/\D/', '', (string) $b), $allowed['bank_numbers'] ?? [])));
        $mainPhone = $allowed['main_phone'] ?? null;
        $mainEmail = $allowed['main_email'] ?? null;
        $isAllowedPhone = fn (string $digits) => collect($phones)->contains(fn (string $p) => str_ends_with($digits, $p));

        $removed = [];

        // 1) Ligas.
        $text = preg_replace_callback('~https?://[^\s<>"\')\]]+~u', function (array $m) use ($normalizeUrl, $urls, $hosts, $isAllowedPhone, &$removed) {
            $url = rtrim($m[0], '.,;:!?');
            $trail = substr($m[0], strlen($url));
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            if (($host !== '' && in_array($host, $hosts, true)) || in_array($normalizeUrl($url), $urls, true)) {
                return $m[0];
            }

            // wa.me/<teléfono del hotel o del huésped>
            if (in_array($host, ['wa.me', 'api.whatsapp.com'], true) && $isAllowedPhone(preg_replace('/\D/', '', $url))) {
                return $m[0];
            }

            $removed[] = $url;

            return $trail;
        }, $text) ?? $text;

        // 2) Lo que tiene dígitos y NO es un teléfono se protege antes de
        // buscar teléfonos: ligas, folios, fechas con hora, horas y montos.
        $protected = [];
        $text = preg_replace_callback(
            '~https?://\S+|\b(?:RES|GRP|EXP)-\d{4}-\d+\b|\b\d{4}-\d{2}-\d{2}(?:[ T]\d{1,2}:\d{2})?\b|\b\d{1,2}:\d{2}\b|\$\s?\d[\d,]*(?:\.\d+)?~u',
            function (array $m) use (&$protected) {
                $key = "\u{E000}".count($protected)."\u{E001}";
                $protected[$key] = $m[0];

                return $key;
            },
            $text,
        ) ?? $text;

        // 3) Correos.
        $text = preg_replace_callback('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', function (array $m) use ($emails, $mainEmail, &$removed) {
            if (in_array(strtolower($m[0]), $emails, true)) {
                return $m[0];
            }

            $removed[] = $m[0];

            return (string) ($mainEmail ?? '');
        }, $text) ?? $text;

        // 4) Teléfonos: de 10 a 13 dígitos (con lada), sin partir números
        // más largos. Una cuenta bancaria válida no es un teléfono.
        $text = preg_replace_callback('/(?<![\w\/\-])\+?\d(?:[ \-.]?\(?\d\)?){9,12}(?![\w\/])/u', function (array $m) use ($bank, $isAllowedPhone, $mainPhone, &$removed) {
            $digits = preg_replace('/\D/', '', $m[0]);

            if (strlen($digits) < 10 || strlen($digits) > 13
                || collect($bank)->contains(fn (string $b) => str_contains($b, $digits))
                || $isAllowedPhone($digits)) {
                return $m[0];
            }

            $removed[] = $m[0];

            return (string) ($mainPhone ?? '');
        }, $text) ?? $text;

        $text = strtr($text, $protected);

        if ($removed === []) {
            return $text;
        }

        rescue(fn () => \Illuminate\Support\Facades\Log::warning('Agente: dato de contacto inventado, se quitó antes de enviarse', [
            'conversation_id' => $conversation?->id,
            'datos' => $removed,
        ]), null, false);

        return preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
    }

    /**
     * La lista permitida de sanitizeContactData, armada con la MISMA
     * información que recibe el bot (su prompt), la configuración del hotel,
     * lo que escribió el huésped y el dominio del hotel en la plataforma.
     *
     * @return array<string, mixed>
     */
    protected function allowedContactData(?Conversation $conversation): array
    {
        $settings = \App\Models\Property::query()->first()?->settings ?? [];
        $source = str_replace('\/', '/', $this->systemPrompt($conversation));

        $guestText = $conversation
            ? $conversation->messages()->where('direction', 'in')->latest('id')->limit(80)->pluck('body')->implode("\n")
            : '';
        $all = $source."\n".$guestText;

        preg_match_all('~https?://[^\s"<>)\]]+~u', $all, $urls);
        preg_match_all('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', $all, $emails);
        preg_match_all('/\+?\d(?:[ \-.]?\d){9,12}/u', preg_replace('~https?://\S+~u', '', $all) ?? '', $numbers);

        $guest = $conversation?->guest_id ? \App\Models\Guest::query()->find($conversation->guest_id) : null;

        $phones = $numbers[0];
        foreach (['phones', 'transfer_whatsapps'] as $key) {
            foreach (($settings[$key] ?? []) as $phone) {
                if (is_array($phone)) {
                    $phones[] = ($phone['code'] ?? '').($phone['number'] ?? '');
                }
            }
        }
        array_push($phones, $settings['phone'] ?? '', $conversation?->contact_phone ?? '', $guest?->phone ?? '');

        return [
            'urls' => array_map(fn (string $u) => rtrim($u, '.,;:'), $urls[0]),
            'hosts' => tenant()?->domains()->pluck('domain')->all() ?? [],
            'emails' => array_values(array_filter(array_merge($emails[0], [$settings['email'] ?? ''], (array) ($settings['emails'] ?? []), [$guest?->email ?? '']))),
            'phones' => array_values(array_filter(array_map('strval', $phones))),
            'bank_numbers' => $this->bankNumbers(),
            'main_phone' => $settings['phone'] ?? null,
            'main_email' => $settings['email'] ?? null,
        ];
    }

    /**
     * Red de seguridad DETERMINISTA para los datos de transferencia.
     *
     * REGLA: el bot jamás pone datos bancarios propios. Todo renglón de
     * banco, cuenta, CLABE, tarjeta, titular o beneficiario se valida contra
     * las cuentas ACTIVAS del hotel; si uno solo no cuadra, el bloque entero
     * se rehace desde la configuración (nunca se mezcla un dato real con uno
     * inventado).
     *
     * Casos reales cabañas:
     * - 2026-09-13 18:01 (conv. 635, msg 6289): a las 6 PM las transferencias
     *   ya estaban cerradas, la herramienta no dio cuenta y el modelo se
     *   inventó "Cuenta: 0119870255" y "Beneficiario: Cabañas Real de la
     *   Sierra". Esta misma función lo DEJABA pasar: solo desconfiaba de
     *   renglones sin dígitos, y el número inventado tenía 10 dígitos —el
     *   largo de una cuenta BBVA real—, fuera del rango que revisa
     *   sanitizeBankNumbers (14 a 20).
     * - 2026-09-13: "Clabe: (pide al personal…)" y "Banco: Por confirmar".
     *
     * Fuera del horario de transferencias no se da NINGUNA cuenta, ni real ni
     * inventada: se dice el horario (regla del hotel).
     *
     * @param  array<int, array<string, mixed>>|null  $accounts  Cuentas (para tests).
     * @param  bool|null  $transferOpen  ¿Se reciben transferencias ahora? (para tests).
     * @param  array<int, string>|null  $phones  Teléfonos del hotel, solo dígitos (para tests).
     * @param  string|null  $hoursLabel  "de 9:00 AM a 5:00 PM" (para tests).
     */
    public function sanitizeBankBlocks(
        string $text,
        ?array $accounts = null,
        ?bool $transferOpen = null,
        ?array $phones = null,
        ?string $hoursLabel = null,
    ): string {
        $settings = ($accounts === null || $phones === null)
            ? (\App\Models\Property::query()->first()?->settings ?? [])
            : [];
        $accounts ??= $settings['bank_accounts'] ?? [];
        $phones ??= $this->hotelPhoneDigits($settings);

        $active = collect($accounts)
            ->filter(fn ($account) => is_array($account) && ! empty($account['active']))
            ->values();

        $validNumbers = $active
            ->flatMap(fn (array $account) => collect(['clabe', 'account', 'card', 'number', 'cuenta'])
                ->map(fn (string $key) => preg_replace('/\D/', '', (string) ($account[$key] ?? ''))))
            ->filter(fn (string $digits) => strlen($digits) >= 8)
            ->unique()
            ->values()
            ->all();
        $validHolders = $active->map(fn (array $a) => $this->normalizeBankText((string) ($a['holder'] ?? '')))->filter()->values()->all();
        $validBanks = $active->map(fn (array $a) => $this->normalizeBankText((string) ($a['bank'] ?? '')))->filter()->values()->all();

        $isHotelPhone = fn (string $digits) => collect($phones)->contains(
            fn (string $phone) => $phone !== '' && ($digits === $phone || str_ends_with($digits, substr($phone, -10)) || str_ends_with($phone, $digits)),
        );

        $kept = [];
        $suspicious = [];
        // Donde iba el primer dato bancario: ahí se pone el bloque rehecho, no
        // al final del mensaje (quedaba después de "Después de hacer la
        // transferencia…").
        $insertAt = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s*[-*•]?\s*(clabe|n[uú]mero de cuenta|no\.?\s*de\s*cuenta|cuenta|tarjeta|banco|titular|beneficiario)\s*:(.*)$/iu', $line, $m)) {
                $insertAt ??= count($kept);
                $field = mb_strtolower($m[1]);
                $value = trim($m[2]);

                $ok = match (true) {
                    str_contains($field, 'banco') => $this->bankTextMatches($value, $validBanks),
                    str_contains($field, 'titular'), str_contains($field, 'beneficiario') => $this->bankTextMatches($value, $validHolders),
                    default => in_array(preg_replace('/\D/', '', $value), $validNumbers, true),
                };

                if (! $ok) {
                    $suspicious[] = trim($line);
                }

                // Los renglones bancarios nunca se conservan tal cual si hay
                // que rehacer el bloque; si todo cuadra, se devuelve el texto
                // original más abajo.
                continue;
            }

            // En prosa: "deposita a la cuenta 0119870255". Antes de buscar
            // números se quitan folios, fechas y ligas, que también traen
            // dígitos y no son datos bancarios.
            if (preg_match('/(cuenta|clabe|tarjeta|dep[oó]sit|transfer)/iu', $line)) {
                $scan = preg_replace(['~https?://\S+~u', '/\b(?:RES|GRP|EXP)-\d{4}-\d+\b/iu', '/\b\d{4}-\d{2}-\d{2}\b/'], '', $line) ?? $line;

                if (preg_match_all('/\d(?:[ \-]?\d){7,19}/', $scan, $numbers)) {
                    foreach ($numbers[0] as $raw) {
                        $digits = preg_replace('/\D/', '', $raw);

                        if (! in_array($digits, $validNumbers, true) && ! $isHotelPhone($digits)) {
                            $suspicious[] = trim($line);
                            $insertAt ??= count($kept);

                            continue 2;
                        }
                    }
                }
            }

            $kept[] = $line;
        }

        if ($suspicious === []) {
            return $text;
        }

        rescue(fn () => \Illuminate\Support\Facades\Log::warning('Agente: datos bancarios inventados o incompletos, se rehízo el bloque', [
            'renglones' => $suspicious,
        ]), null, false);

        $insertAt ??= count($kept);
        $replacement = [];

        if ($active->isNotEmpty()) {
            $transferOpen ??= app(\App\Services\ReservationPolicy::class)->transferOpenNow();

            if ($transferOpen) {
                $replacement = explode("\n", $active->map(fn (array $account) => implode("\n", array_filter([
                    ! empty($account['bank']) ? '- Banco: '.$account['bank'] : null,
                    ! empty($account['holder']) ? '- Titular: '.$account['holder'] : null,
                    '- Cuenta: '.($account['clabe'] ?? $account['account'] ?? $account['cuenta'] ?? ''),
                ])))->implode("\n\n"));
            } else {
                $hoursLabel ??= app(\App\Services\ReservationPolicy::class)->transferHoursLabel();

                // Fuera de horario no hay datos que anunciar: el "los datos
                // son:" que quedaba colgando, sin nada debajo, se quita.
                for ($i = $insertAt - 1; $i >= 0; $i--) {
                    if (trim($kept[$i]) === '') {
                        continue;
                    }
                    if (str_ends_with(rtrim($kept[$i]), ':')) {
                        array_splice($kept, $i, 1);
                        $insertAt = $i;
                    }
                    break;
                }

                $replacement = [$hoursLabel
                    ? "Las transferencias se reciben {$hoursLabel}, así que en este momento no puedo darte los datos de la cuenta. Puedes hacer la transferencia dentro de ese horario o pedirme otra forma de pago."
                    : 'En este momento no puedo darte los datos de la cuenta. Pídeme otra forma de pago.'];
            }
        }

        array_splice($kept, $insertAt, 0, $replacement);

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept)) ?? '');
    }

    /** Minúsculas, sin acentos ni signos: "Sofía" y "sofia" son la misma titular. */
    protected function normalizeBankText(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', $value) ?? '') ?? '');
    }

    /** "BBVA" cuadra con "BBVA Bancomer"; "Por confirmar" no cuadra con nada. */
    protected function bankTextMatches(string $value, array $valid): bool
    {
        $value = $this->normalizeBankText($value);

        if ($value === '' || $valid === []) {
            return false;
        }

        foreach ($valid as $candidate) {
            if (str_contains($candidate, $value) || str_contains($value, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Teléfonos del hotel en dígitos (con y sin lada): en un renglón de
     * "manda el comprobante de tu transferencia al 656…" el número es un
     * teléfono, no una cuenta inventada.
     *
     * @return array<int, string>
     */
    protected function hotelPhoneDigits(array $settings): array
    {
        $out = [];
        $push = function ($value) use (&$out): void {
            $digits = preg_replace('/\D/', '', (string) $value);
            if (strlen($digits) >= 7) {
                $out[] = $digits;
                $out[] = substr($digits, -10);
            }
        };

        $push($settings['phone'] ?? '');

        foreach (['phones', 'transfer_whatsapps'] as $key) {
            foreach (($settings[$key] ?? []) as $phone) {
                if (is_array($phone)) {
                    $push(($phone['code'] ?? '').($phone['number'] ?? ''));
                    $push($phone['number'] ?? '');
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * La hora la dice el reloj del servidor, no el modelo. Caso real cabañas
     * 2026-09-13: "Son las 16:11, tienes hasta las 16:41" cuando eran las
     * 16:51 — el huésped calcula su plazo con una hora falsa. El prompt ya
     * trae la hora correcta; esto la corrige cuando el modelo la ignora.
     */
    public function sanitizeClockClaims(string $text, ?string $now = null): string
    {
        $now ??= now()->format('H:i');

        return preg_replace('/\bson las \d{1,2}:\d{2}\b/iu', 'son las '.$now, $text) ?? $text;
    }

    /**
     * Red de seguridad DETERMINISTA para datos bancarios. Caso real cabañas
     * 2026-09-10 (conv. 69): la herramienta entregó la cuenta exacta
     * (4152314577952941) y MiniMax la copió mal —4152313477952941— y
     * después repitió su propio número equivocado del historial. El huésped
     * no pudo depositar ("no me aparece esa cuenta") y el negocio quedó
     * comprometido.
     *
     * Todo número de 14 a 20 dígitos que el bot escriba se compara contra
     * las cuentas guardadas del hotel: si coincide exacto, pasa; si se
     * parece a una (dígitos cambiados o transpuestos), se reemplaza por la
     * real; si no se parece a ninguna, se quita. Los teléfonos (hasta 13
     * dígitos con lada) no se tocan.
     *
     * @param  array<int, string>|null  $valid  Números válidos (para tests).
     */
    public function sanitizeBankNumbers(string $text, ?array $valid = null): string
    {
        $valid ??= $this->bankNumbers();

        return preg_replace_callback('/\d(?:[ \-]?\d){13,19}/', function (array $match) use ($valid) {
            $digits = preg_replace('/\D/', '', $match[0]);

            if (in_array($digits, $valid, true)) {
                return $match[0];
            }

            // El más parecido de los números guardados.
            $closest = null;
            $distance = PHP_INT_MAX;
            foreach ($valid as $candidate) {
                $d = levenshtein($digits, $candidate);
                if ($d < $distance) {
                    [$closest, $distance] = [$candidate, $d];
                }
            }

            // Avisar en la bitácora nunca puede tumbar la respuesta.
            rescue(fn () => \Illuminate\Support\Facades\Log::warning('Agente: número bancario corregido antes de enviarse', [
                'escrito' => $digits,
                'reemplazo' => $closest !== null && $distance <= 6 ? $closest : null,
            ]), null, false);

            return $closest !== null && $distance <= 6
                ? $closest
                : '(pide al personal los datos de la cuenta)';
        }, $text) ?? $text;
    }

    /**
     * Números de las cuentas ACTIVAS del hotel (Métodos de pago), solo dígitos.
     *
     * @return array<int, string>
     */
    protected function bankNumbers(): array
    {
        $accounts = \App\Models\Property::query()->first()?->settings['bank_accounts'] ?? [];

        return collect($accounts)
            ->filter(fn ($account) => is_array($account) && ! empty($account['active']))
            ->flatMap(fn (array $account) => collect(['clabe', 'account', 'card', 'number', 'cuenta'])
                ->map(fn (string $key) => preg_replace('/\D/', '', (string) ($account[$key] ?? ''))))
            ->filter(fn (string $digits) => strlen($digits) >= 10)
            ->unique()
            ->values()
            ->all();
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
     * Alfabetos que el bot NUNCA escribe: el producto habla solo español o
     * inglés. Han, kana, hangul, cirílico, griego, hebreo, árabe, tailandés
     * y devanagari.
     */
    private const FOREIGN_SCRIPTS = '\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{F900}-\x{FAFF}'
        .'\x{3040}-\x{30FF}\x{31F0}-\x{31FF}'
        .'\x{1100}-\x{11FF}\x{AC00}-\x{D7AF}'
        .'\x{0400}-\x{04FF}\x{0500}-\x{052F}'
        .'\x{0370}-\x{03FF}'
        .'\x{0590}-\x{05FF}\x{0600}-\x{06FF}'
        .'\x{0E00}-\x{0E7F}\x{0900}-\x{097F}';

    /**
     * ¿La respuesta está escrita, en su mayoría, en otro alfabeto? Entonces
     * recortar letras la dejaría vacía: hay que volver a redactarla. Unas
     * cuantas letras infiltradas en un texto latino no cuentan — esas las
     * quita stripForeignScriptLeaks().
     */
    public function needsTranslation(string $text): bool
    {
        $foreign = preg_match_all('/['.self::FOREIGN_SCRIPTS.']/u', $text);

        if (! $foreign) {
            return false;
        }

        return preg_match_all('/\p{Latin}/u', $text) <= $foreign * 2;
    }

    /**
     * Candado de idioma DETERMINISTA. Caso real cabañas 2026-09-10 (conv.
     * 69): a un huésped que escribía en español, MiniMax le contestó "He
     * передал ваш запрос..." — entero en ruso. El prompt ya lo prohibía; con
     * modelos débiles la única garantía es revisar la salida (misma doctrina
     * que sanitizeChatText). Si viene en otro alfabeto, se le pide al mismo
     * proveedor la traducción al español; si tampoco sale bien, va una frase
     * segura en vez del mensaje en otro idioma.
     */
    protected function enforceLanguage(string $text, ?AiProvider $provider): string
    {
        if (! $this->needsTranslation($text)) {
            return $text;
        }

        \Illuminate\Support\Facades\Log::warning('Agente: respuesta en otro idioma, se traduce al español', [
            'text' => mb_substr($text, 0, 300),
        ]);

        if ($provider !== null) {
            try {
                $translated = trim($this->run($provider, fn ($request) => $request
                    ->withSystemPrompt('Traduce al español el siguiente mensaje que un asistente de hotel le escribe a su huésped. Responde SOLO con la traducción, en texto plano, sin comillas ni comentarios.')
                    ->withPrompt($text))->text);

                if ($translated !== '' && ! $this->needsTranslation($translated)) {
                    return $translated;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return 'Disculpe, tuve un problema al redactar mi respuesta. ¿Me puede repetir su mensaje, por favor?';
    }

    /**
     * Quita cualquier letra de otro alfabeto. Antes respetaba un mensaje
     * que venía ENTERO en ruso o chino ("el bot contesta en el idioma del
     * huésped"); desde 2026-09-11 el hotel solo admite español e inglés, y
     * lo que venga mayormente en otro alfabeto ya lo tradujo
     * enforceLanguage() antes de llegar aquí. Lo que quede son fugas
     * sueltas ("habitaciones классик", "Si告诉我"): se recortan siempre.
     */
    protected function stripForeignScriptLeaks(string $text): string
    {
        return preg_replace(
            '/['.self::FOREIGN_SCRIPTS.'\x{3000}-\x{303F}\x{FF01}-\x{FF60}\x{FFE0}-\x{FFEE}]+/u',
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
        $hoursBlock = $this->supportHoursBlock();
        $summaryBlock = $this->summaryBlock($conversation);
        $instructionsBlock = $this->instructionsBlock();
        $guidelinesBlock = $this->guidelinesBlock();
        $couponBlock = $this->couponBlock($conversation);
        $nowBlock = "\nAHORA MISMO son las ".now()->locale('es')->isoFormat('HH:mm')
            .' del '.$this->today().".\n";

        // ORDEN A PROPÓSITO: primero todo lo que NO cambia (datos del hotel,
        // instrucciones, reglas) y hasta el final lo que cambia en cada
        // conversación y cada minuto. El caché de los proveedores es por
        // PREFIJO: un dato variable a media mitad invalida todo lo que sigue.
        // Con la hora y el cupón en medio, el caché bajaba de 10,299 a 7,163
        // tokens por respuesta (medido contra MiniMax el 2026-09-14).
        return <<<PROMPT
Eres el asistente virtual del hotel "{$policies['hotel']['name']}". Atiendes huéspedes por chat en español; si el huésped escribe en inglés, contestas en inglés. Ningún otro idioma.

DATOS DEL HOTEL (única fuente de verdad — si algo no está aquí ni en tus herramientas, di que no tienes esa información y ofrece comunicarlo con recepción):
```json
{$policiesJson}
```
{$instructionsBlock}{$guidelinesBlock}
REGLAS ESTRICTAS:
- Si la duda del huésped coincide con una pregunta de "faqs", responde con esa respuesta tal cual (puedes adaptarla al tono de la conversación, sin cambiar los datos).
- Si el huésped comparte su teléfono, usa identificar_huesped para reconocerlo; si ya nos visitó, salúdalo por su nombre como cliente frecuente (sin recitar sus datos).
- Usa las herramientas para tarifas, disponibilidad y reservas; NUNCA inventes precios, fechas, políticas ni cantidades de habitaciones.
- INVENTARIO: cada tipo tiene un número FIJO de habitaciones, el campo "units" de room_types. Ese es el tope absoluto: si units es 1, JAMÁS ofrezcas dos ("2 Cabañas Reales" cuando solo existe una es el peor error que puedes cometer). Para ofrecer varias, usa consultar_disponibilidad_general y no pases de "units_available" por tipo.
- NO AFIRMES DISPONIBILIDAD SIN VERIFICARLA: nunca digas que una habitación está libre —ni la ofrezcas como alternativa— sin haberla consultado con consultar_disponibilidad o consultar_disponibilidad_general para ESAS fechas exactas. Si un tipo salió ocupado, consulta el resto con consultar_disponibilidad_general ANTES de nombrar alternativas; si no queda nada libre, dilo tal cual y ofrece las fechas de alternative_dates (ya vienen verificadas, con su etiqueta en español) para no perder al huésped.
- GRUPOS: si el grupo no cabe en una sola habitación, llama consultar_disponibilidad_general con las fechas y "personas", y ofrece TAL CUAL lo que devuelva suggested_combination (qué tipos, cuántas de cada uno y el total). Si combination_covers_guests viene en false, dilo con claridad y ofrece otras fechas o usa transferir_a_humano; nunca completes el grupo con habitaciones que no aparecen libres. No le pidas al huésped que él arme la combinación: propónsela tú.
- No inventes política comercial: nunca afirmes descuentos, mínimos de noches, ni que "el precio es fijo todo el año" si no está en los datos del hotel. Si una tarifa trae seasonal en true, el precio cambia por fechas y solo consultar_disponibilidad te da el correcto.
- FECHAS: al repetir la llegada y la salida usa exactamente las que devolvió la herramienta (starts_at/ends_at); no cambies día, mes ni año al redactarlas.
- AÑO — REGLA ABSOLUTA: hoy es {$this->today()}. JAMÁS cotices, ofrezcas, consultes ni menciones fechas que ya pasaron ni años anteriores al actual. Si el huésped da día y mes sin año, es la PRÓXIMA vez que llega esa fecha: este año si todavía no pasa, el siguiente si ya pasó — mándala así a las herramientas sin preguntarle el año. NUNCA le pongas a escoger entre dos años ("para 2025 / para 2026" es el peor error de fechas posible: el huésped no puede viajar al pasado). Si una herramienta devuelve "date_notice", la fecha que mandaste estaba en el pasado y se corrigió: obedécela y usa solo la fecha que trae.
- Cada tarifa pertenece a UN tipo de habitación (room_type en consultar_tarifas). Si el huésped pidió un tipo, cotiza y aparta SOLO con tarifas de ese tipo — jamás uses la tarifa de otro tipo.
- El precio de una tarifa es POR UNIDAD (por noche o por bloque); el TOTAL del rango lo calcula consultar_disponibilidad. Nunca presentes el total del rango como si fuera el precio por unidad ("$1,750 por 3 horas" está MAL si es el total de varias unidades). Para estancias con fechas usa tarifas por noche; las tarifas por bloque (ratos/horas) solo si el huésped pide horas.
- AL COTIZAR, NUNCA DES EL PRECIO PELADO: consultar_disponibilidad devuelve "quote_notice" (y el panorama "payment_notice") con los renglones que el hotel exige decir — cuántas personas incluye la tarifa y qué cuesta la persona extra, el anticipo para apartar, hasta cuándo debe quedar liquidada la estancia y el teléfono para dudas o aclaraciones. El anticipo de esos renglones es el que de verdad va a cobrar el sistema: si otra instrucción te dicta una cifra distinta, manda ESTA. Cópialos TAL CUAL debajo del total, TODOS, en cada cotización. No los resumas, no los omitas "por brevedad" y no cambies fechas ni montos: si el plazo de liquidación viene ahí, ese es, y va aunque el huésped no pregunte.
- Antes de crear un apartado repite al huésped: tipo de habitación, nombre de la tarifa, TOTAL exacto, fecha de llegada y nombre completo — y espera su confirmación.
- Al entregar el código de un apartado creado, menciona una sola vez que el día de la llegada se pide una identificación oficial en recepción para el registro.
- PAGOS: si el apartado requiere prepago (requires_prepayment), PRIMERO ofrece al huésped las formas de pago disponibles según payment_options del apartado (pasarelas por su nombre, transferencia, efectivo al llegar) y pregunta cuál prefiere — solo menciona las que existan. Con su elección llama solicitar_pago (metodo y proveedor) y comparte lo que devuelva tal cual: link de pago (paga ahí y el sistema confirma solo), cuentas para transferencia (pide el comprobante por este chat; el hotel lo verifica), o efectivo (dile hasta cuándo queda apartada su habitación y que paga al llegar). Si solo hay UNA opción, no preguntes: úsala directo. NUNCA digas que un pago fue recibido o verificado: eso solo lo confirma el sistema (consultar_reserva) o el personal. Si el huésped insiste en que ya pagó y el sistema no lo refleja, usa transferir_a_humano.
- UN APARTADO POR HUÉSPED: si ya creaste un apartado en esta conversación y el huésped solo cambia la forma de pago (link, transferencia, efectivo), llama solicitar_pago con ESE MISMO código. Nunca vuelvas a llamar crear_apartado ni consultar_disponibilidad para cambiar el pago, y nunca le digas que su cabaña ya no está disponible por eso: está apartada para él. Si el huésped cambia de cabaña, crea el apartado nuevo y dale su código nuevo (el anterior se libera solo).
- DISPONIBILIDAD REAL: solo di que una cabaña está disponible, y solo das su total, si consultar_disponibilidad devolvió available=true para ESA cabaña y ESAS fechas. La primera línea de quote_notice trae el nombre de la cabaña con su precio: cópiala tal cual. Nunca pongas el precio de una cabaña a otra ni cotices una cabaña distinta a la que consultaste. Si payment_options trae transferencia_nota, obedécela.
- CUPONES: aplica un descuento SOLO si el huésped te da un código de cupón. Valídalo con validar_cupon (con la tarifa y las fechas) y, si es válido, cotiza con la línea de quote_notice que devuelve y pásalo en crear_apartado con el parámetro cupon. Si no es válido, dile el motivo exacto que devuelva. Nunca inventes códigos, nunca ofrezcas descuentos por tu cuenta y nunca reveles qué cupones existen. Si no tienes la herramienta validar_cupon, este hotel no maneja cupones: dilo así.
- ANTES DE APARTAR, EN ESTE ORDEN: (1) su nombre completo y su CORREO electrónico —los dos, en un solo mensaje—; (2) si el hotel tiene aviso legal (legal_notice_url en las políticas), mándaselo con la frase "Es importante que lea y confirme el contrato; confírmeme de leído, por favor" y espera su confirmación; (3) pregúntale cómo va a pagar el anticipo con las opciones REALES (payment_options). Hasta que elija, NO llames crear_apartado: la cabaña no se aparta antes. Cuando elija, llama crear_apartado con metodo_pago y enseguida solicitar_pago con ese mismo método.
- Si NO tienes la herramienta solicitar_pago, este hotel no tiene cobros configurados: no prometas NINGUNA forma de pago (ni efectivo al llegar, ni transferencia, ni link) — di que recepción se comunica para cerrar el pago.
- NUNCA pidas ni aceptes números de tarjeta por el chat; si el huésped los envía, dile que por seguridad los borre y no los uses.
- Cita montos exactamente como los devuelven las herramientas (usa *_label).
- CAPACIDAD Y PERSONAS EXTRA: responde SOLO con "occupancy" de room_types (o el "occupancy_notice" ya redactado): la tarifa incluye included_guests personas, el máximo es max_guests, y cada persona adicional cuesta extra_guest_fee_label. Dilo SIEMPRE que ofrezcas o cotices una habitación, aunque no te lo pregunten — enterarse del cargo por persona extra al llegar es un reclamo en el mostrador. Si extra_guest_fee existe, NUNCA digas que no hay cobro por persona extra. Si el grupo supera max_guests, sugiere una habitación con más capacidad o transfiere a recepción.
- FIANZA: si get_policies o el resultado de crear_apartado traen "guarantee", al confirmar un apartado avisa UNA vez que al llegar se cobra ese depósito en garantía y que se devuelve al registrar la salida. Si el resultado del apartado trae "guarantee_for_this_booking", usa SU "label" tal cual: ya dice cuántas habitaciones son, cuánto cada una y el total — no hagas tú la cuenta ni cites el monto base. Si no viene, usa el "label" de "guarantee". NO lo sumes al total de la estancia: es un depósito aparte que regresa. Si el huésped aparta varias habitaciones y hay "tiers_label", menciónalo; nunca inventes descuentos de fianza que no estén ahí.
- FOTOS: si piden fotos de una habitación y su tipo tiene photos_url, comparte ese link tal cual diciendo que ahí están las fotos. Sin photos_url, describe la habitación y ofrece que el personal envíe fotos por este chat.
- ENLACES: comparte una liga SOLO cuando venga al caso (piden fotos, preguntan por una habitación en concreto, por cómo llegar o por qué hacer). Una sola liga, una sola vez en la conversación: nunca la pegues de firma en cada mensaje ni recites la lista completa. Usa únicamente las URLs que vienen en estos datos (website, maps_url, links, photos_url, url de un recorrido) — JAMÁS inventes ni completes una dirección web. Excepción: si las INSTRUCCIONES DEL EQUIPO DEL HOTEL ordenan mandar una liga en un momento concreto (por ejemplo, el aviso legal al pedir los datos para reservar), mándala SIEMPRE en ese momento, aunque ya hayas compartido otra liga en la conversación.
- RECORRIDOS: si preguntan por actividades, tours, qué hacer o qué hay en la zona, ofrece lo que traiga "experiences" con su duración y precio (y su liga si la tiene); para apartarlos comparte experiences_booking_url. Si no hay bloque "experiences", el hotel NO tiene recorridos: no los inventes ni prometas que alguien los organiza.
- VARIAS HABITACIONES: si tienes crear_apartado_grupo, úsala — aparta todas bajo un folio GRP- y es todo o nada, así nadie se queda sin cuarto a medio grupo. Su cobro es UNO consolidado: llama solicitar_pago con el folio GRP-, nunca uno por habitación. Si NO tienes esa herramienta, haz UNA llamada de crear_apartado por cada habitación y reporta el resultado real de CADA una (código o el error exacto). En cualquier caso, nunca resumas dos apartados en uno ni des por hecho uno que no confirmaste con la herramienta.
- Si una herramienta devuelve un error, comunica al huésped el mensaje EXACTO que devolvió — nunca inventes la causa ni digas "no hay disponibilidad" si la herramienta dijo otra cosa.
- SI EL HUÉSPED YA PAGÓ, NO LE QUITES SU RESERVA CON PALABRAS. Nunca le digas "su reserva no existe", "no aparece registrada", "venció" o "ya no hay disponibilidad" a alguien que dice haber pagado o haber mandado comprobante — aunque una herramienta te devuelva 404 o cero lugares. Un 404 significa que TÚ no encontraste el código (pudo teclearlo mal, o ser un folio de grupo), no que su dinero no exista. En esa situación solo haces dos cosas: consultar_reserva con el código tal cual lo escribió (acepta RES- y GRP-) y, si sigue sin cuadrar, transferir_a_humano. Decirle a quien acaba de transferir que no tiene nada es el peor error posible: cuesta el dinero y el cliente (cabañas 2026-09-14, GRP-2026-0149).
- ADJUNTOS: tú no ves imágenes, pero el sistema las lee y te dice qué son. "[adjuntó un comprobante: …]": agradece, puedes repetir el monto que trae y di que el personal lo verificará; tú no confirmas pagos. "[adjuntó una imagen que NO es un comprobante…]": NO la trates como pago ni digas que recibiste su comprobante; contesta sobre lo que se ve y, si tiene un apartado esperando pago, pídele con amabilidad la captura de su transferencia. "[adjuntó una imagen o documento…]" sin lectura: el archivo SÍ llegó y el personal puede verlo — NUNCA digas que no se recibió ni pidas que lo reenvíe; si es un comprobante, agradece y di que el personal lo verificará. Si tiene un apartado vigente, el sistema lo sostiene mientras el hotel verifica el depósito: dile que su apartado queda guardado mientras confirman el pago y NUNCA le digas que venció. Si su apartado YA había vencido, al recibir el comprobante el sistema lo reabre solo con el MISMO código si la habitación sigue libre: consulta la reserva con consultar_reserva y dile cómo quedó. Si quiere retomar un apartado vencido sin haber mandado comprobante, usa reactivar_apartado con su código y dale ese código.
- SERVICIOS E INSTALACIONES: "amenities" de cada tipo de habitación y "services" del hotel son datos reales del catálogo. Si preguntan por alberca, asador, fogata, estacionamiento, terraza o cualquier cosa que aparezca ahí, la respuesta es SÍ y la das TÚ, en ese mismo turno, diciendo que sí se cuenta con ello. Prohibido transferir, dudar o decir "déjame confirmarlo" sobre algo que ya está en tus datos: es hacerle perder el tiempo al huésped y al hotel (caso real cabañas 2026-09-07: transfirió una pregunta de alberca que el catálogo contestaba).
- Si una pregunta trae varias cosas y solo una está fuera de tus datos, responde las que sí sabes y transfiere ÚNICAMENTE la que falta, diciendo cuál es.
- Si el huésped pide hablar con una persona, se queja, o pide algo fuera de tu alcance, usa la herramienta transferir_a_humano. TRANSFERIR ES UNA ACCIÓN, NO UN ANUNCIO: llámala en ESE mismo turno y nunca prometas una llamada — el hotel contesta por este chat.
- PERSONAL DEL HOTEL: un turno que empieza con "[PERSONAL DEL HOTEL]" lo escribió una persona del hotel y ya se lo dijo al huésped. Es palabra dada: no la contradigas, no vuelvas a cotizar la fecha ni el precio que esa línea ya cerró, y no repitas la pregunta que ahí ya se respondió. Si una herramienta te dice lo contrario que el personal, NO corrijas al personal: usa transferir_a_humano.
- Hoy es {$this->today()}. Fechas en formato YYYY-MM-DD HH:MM.
- FORMATO: tus mensajes se muestran como TEXTO PLANO (WhatsApp, Telegram, webchat) — JAMÁS uses tablas, negritas con asteriscos, títulos con #, ni ningún markdown: el huésped vería los símbolos literales. Para listar opciones usa un renglón corto por opción con guion, ej.: "- Habitación Sencilla: $1,300".
- IDIOMA — REGLA ABSOLUTA: SOLO español o inglés. Contesta en inglés únicamente si el huésped te escribe en inglés; en cualquier otro caso —aunque escriba en ruso, portugués, francés, chino o cualquier otro idioma— contesta en español. JAMÁS escribas en otro idioma ni uses otro alfabeto (cirílico, chino, árabe...), ni una sola palabra, y nunca mezcles idiomas a media frase.
- Nunca menciones duraciones en horas, horarios de entrada/salida ni vigencias que las herramientas o estos datos no indiquen explícitamente.
- Sé breve, cálido y profesional; máximo 2-3 oraciones por respuesta salvo que listes opciones. No uses emojis.
- No saludes de nuevo si la conversación ya empezó: continúa el hilo donde va.
{$guestBlock}{$hoursBlock}{$summaryBlock}{$couponBlock}{$nowBlock}
PROMPT;
    }

    /**
     * Horario de atención del hotel (opt-in en Datos generales). Fuera de
     * horario el bot sigue trabajando —cotizar de madrugada es justo su
     * gracia— pero deja de prometer que "en un momento te atienden".
     *
     * Público para poder verlo sin armar el prompt completo (que necesita
     * tenant central) y para mostrarlo en el "ojito" del prompt.
     */
    public function supportHoursBlock(): string
    {
        $hours = app(SupportHours::class);

        if (! $hours->enabled()) {
            return '';
        }

        if ($hours->isOpen()) {
            return "\nHORARIO DE ATENCIÓN: el personal del hotel atiende {$hours->label()}; ahora mismo SÍ hay quien conteste.\n";
        }

        $next = $hours->nextOpeningLabel();

        return <<<BLOCK

HORARIO DE ATENCIÓN: el personal atiende {$hours->label()} y AHORA MISMO ESTÁ FUERA DE HORARIO.
- Sigue atendiendo normal: cotiza, revisa disponibilidad y aparta como siempre.
- NUNCA digas que alguien lo atiende "en un momento" ni que "ahorita te contactan": el equipo retoma {$next}.
- Si tienes que transferir, hazlo igual (queda registrado y lo ven al abrir), y dile que le responden {$next}.

BLOCK;
    }

    /**
     * Aprendizajes del hotel (agent_guidelines): correcciones capturadas de
     * conversaciones reales, inyectadas como reglas numeradas. Es el canal
     * para que el bot "aprenda" de sus errores con control humano.
     */
    /**
     * Cupón que el huésped mencionó SIN decir la palabra "cupón".
     *
     * Caso real cabañas 2026-09-11: el hotel publicó el código PACHEPACHE en
     * un video; el huésped escribió "vengo del video del pache pache... con
     * el 30%" y el bot creyó que hablaba de la política de cancelación (que
     * también es 30%). El modelo no relaciona un nombre suelto con un
     * código, así que el servidor lo detecta y se lo dice.
     */
    protected function couponBlock(?Conversation $conversation): string
    {
        if ($conversation === null || ! $this->tools->couponsPublic()) {
            return '';
        }

        // TODA la conversación, no los últimos mensajes: el huésped suele
        // nombrar la colaboración al saludar y apartar mucho después.
        $said = $this->guestSaid($conversation);
        $coupon = \App\Models\Coupon::mentionedIn($said);

        if ($coupon === null) {
            return '';
        }

        // Las condiciones van en el aviso: si el cupón es de lunes a jueves,
        // el bot tiene que decirlo ANTES de cotizar, no después (caso real
        // cabañas 2026-09-11: cotizó un domingo con el 30% de PACHEPACHE).
        $conditions = array_values(array_filter([
            $coupon->weekdaysLabel() ? 'solo para estancias en '.$coupon->weekdaysLabel() : null,
            $coupon->min_nights ? 'mínimo '.$coupon->min_nights.' noches' : null,
            $coupon->min_visits ? 'solo para clientes frecuentes' : null,
            $coupon->birthday ? 'solo en fechas cercanas al cumpleaños del huésped' : null,
            $coupon->roomType?->name ? 'solo en '.$coupon->roomType->name : null,
            $coupon->ends_at ? 'solo para estancias hasta el '.$coupon->ends_at->format('d/m/Y') : null,
        ]));

        $lines = sprintf(
            '- %s: %s de descuento%s',
            $coupon->code,
            $coupon->kindLabel(),
            $conditions === [] ? '.' : ' ('.implode('; ', $conditions).').',
        );

        // Veredicto YA CALCULADO para las fechas que nombró el huésped: el
        // modelo promete el descuento antes de validar nada (caso real
        // cabañas 2026-09-11: ofreció el 30% para el 20/10 con un cupón que
        // vence el 15/10), así que se le da masticado.
        foreach ($this->couponVerdicts($coupon, $said, $conversation) as $verdict) {
            $lines .= "\n".$verdict;
        }

        return "\n\nCUPÓN QUE MENCIONÓ EL HUÉSPED (lo detectó el sistema en sus mensajes, aunque no haya dicho \"cupón\" ni \"código\"):\n"
            .$lines
            ."\nEs un código de descuento REAL de este hotel: reconócelo de inmediato, dile cuánto descuenta y no lo confundas con otros porcentajes (la penalización por cancelar NO es un descuento). Si tiene condiciones, dilas ANTES de cotizar y no prometas el descuento en fechas que no cumplen. Al cotizar, valídalo con validar_cupon (con la tarifa y las fechas) y pásalo en crear_apartado con el parámetro cupon. Si no aplica, dile el motivo exacto que devuelva la herramienta.";
    }

    /**
     * Fechas concretas que aparecen en un texto ("20 de octubre", "20/10").
     * Sin año, la próxima ocurrencia — misma regla que las herramientas.
     *
     * @return array<string, \Carbon\CarbonImmutable>
     */
    protected function datesMentioned(string $text): array
    {
        $months = [
            'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
            'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
            'noviembre' => 11, 'diciembre' => 12,
        ];
        $plain = mb_strtolower((string) preg_replace('/\s+/u', ' ', $text));
        $plain = strtr($plain, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        $found = [];

        $add = function (int $day, int $month, ?int $year) use (&$found) {
            if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
                return;
            }

            try {
                $date = \Carbon\CarbonImmutable::create($year ?? now()->year, $month, $day)->startOfDay();
            } catch (\Throwable) {
                return;
            }

            // Sin año, la próxima ocurrencia (nadie reserva para ayer).
            if ($year === null && $date->lt(now()->startOfDay())) {
                $date = $date->addYear();
            }

            $found[$date->toDateString()] = $date;
        };

        $names = implode('|', array_keys($months));

        if (preg_match_all('/\b(\d{1,2})\s*(?:de\s*)?('.$names.')(?:\s*(?:de|del)?\s*(\d{4}))?/u', $plain, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $add((int) $match[1], $months[$match[2]], isset($match[3]) && $match[3] !== '' ? (int) $match[3] : null);
            }
        }

        if (preg_match_all('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/u', $plain, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $year = isset($match[3]) && $match[3] !== '' ? (int) $match[3] : null;
                $add((int) $match[1], (int) $match[2], $year !== null && $year < 100 ? 2000 + $year : $year);
            }
        }

        return array_slice($found, 0, 5, true);
    }

    /**
     * Para cada fecha que nombró el huésped, si el cupón aplica o no. Una
     * noche por fecha: es lo que pregunta el huésped al cotizar.
     *
     * @return array<int, string>
     */
    protected function couponVerdicts(\App\Models\Coupon $coupon, string $said, ?Conversation $conversation = null): array
    {
        $guest = $conversation?->guest;

        return collect($this->datesMentioned($said))
            ->map(function (\Carbon\CarbonImmutable $date) use ($coupon, $guest) {
                $reason = $coupon->rejectionReason($guest, $date, 1, null);

                return $reason === null
                    ? 'Para el '.$date->format('d/m/Y').' el cupón SÍ aplica (si la estancia cumple las demás condiciones).'
                    : 'Para el '.$date->format('d/m/Y').' el cupón NO aplica: '.$reason.' NO se lo ofrezcas para esa fecha; dile el motivo y que puede apartar sin descuento o elegir una fecha en la que sí aplique.';
            })
            ->values()
            ->all();
    }

    /**
     * Red determinista para lo que el bot PROMETE. Caso real cabañas
     * 2026-09-11: con el cupón vencido el 15/10 siguió diciendo "el 20 de
     * octubre aplica" tres mensajes seguidos. Si el mensaje habla del
     * descuento y nombra una fecha en la que el cupón no aplica, se le
     * agrega la corrección con el motivo exacto antes de enviarlo.
     */
    protected function enforceCouponClaims(string $text, ?Conversation $conversation): string
    {
        if ($conversation === null || trim($text) === '' || ! $this->tools->couponsPublic()) {
            return $text;
        }

        $coupon = \App\Models\Coupon::mentionedIn($this->guestSaid($conversation));

        if ($coupon === null) {
            return $text;
        }

        $claimsDiscount = str_contains(\App\Models\Coupon::keyOf($text), \App\Models\Coupon::keyOf($coupon->code))
            || preg_match('/descuento|%/u', $text) === 1;

        if (! $claimsDiscount) {
            return $text;
        }

        foreach ($this->datesMentioned($text) as $date) {
            $reason = $coupon->rejectionReason($conversation->guest, $date, 1, null);

            if ($reason === null) {
                continue;
            }

            \Illuminate\Support\Facades\Log::warning('Agente: prometió un cupón que no aplica', [
                'coupon' => $coupon->code,
                'fecha' => $date->toDateString(),
                'motivo' => $reason,
            ]);

            return rtrim($text)."\n\nUna aclaración importante: el cupón {$coupon->code} no aplica para el "
                .$date->format('d/m/Y').'. '.$reason
                .' Puedes apartar sin el descuento, o elegir una fecha en la que sí aplique.';
        }

        return $text;
    }

    /** Todo lo que el huésped ha escrito en la conversación, en un texto. */
    protected function guestSaid(Conversation $conversation): string
    {
        return $conversation->messages()
            ->where('direction', 'in')
            ->latest('id')
            ->limit(200)
            ->pluck('body')
            ->implode(' ');
    }

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

                // Los mismos guardianes que la respuesta automática: el
                // borrador lo manda una persona, y si ofrece una cabaña
                // ocupada el huésped la recibe igual (falta el de traspaso,
                // que ejecutaría un traspaso de verdad desde un borrador).
                $text = $this->enforcePaymentClaims(
                    $this->enforceHoldDeadlineClaims(
                        $this->enforceCouponClaims(
                            $this->enforceAvailabilityClaims(trim($response->text), $conversation),
                            $conversation,
                        ),
                        $conversation,
                    ),
                    $conversation,
                );

                $text = $this->sanitizeChatText($this->sanitizeClockClaims($this->sanitizeBankBlocks($this->sanitizeBankNumbers($this->sanitizeGatewayLinks($text, $conversation)))));
                $text = $this->sanitizeContactData($text, $conversation);

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
                    'cached_tokens' => $response->usage->cacheReadInputTokens ?? null,
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
                .($m->direction === 'in' && $m->media_count > 0 ? ' '.$this->attachmentNote($m) : ''))
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

    /**
     * La fecha de hoy, SIN hora. Va dentro de las reglas fijas, y el caché
     * del proveedor funciona por prefijo: con la hora pegada aquí, el prompt
     * cambiaba cada minuto y se perdían 3,136 tokens de caché por respuesta
     * (medido contra MiniMax el 2026-09-14). La hora exacta vive al final,
     * en el bloque AHORA MISMO.
     */
    protected function today(): string
    {
        return now()->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');
    }

    /**
     * @return array<int, UserMessage|AssistantMessage>
     */
    protected function history(Conversation $conversation): array
    {
        // Últimos 20 por id y luego en orden cronológico: el mensaje más
        // reciente debe quedar AL FINAL o el modelo pierde el hilo.
        $recent = $conversation->messages()
            ->whereIn('sender_type', ['visitor', 'bot', 'staff'])
            ->withCount('media')
            ->latest('id')->take(20)->get();

        // Lo que escribió el personal es lo que más vale del hilo y lo
        // primero que se cae de esa ventana (en el corpus del 11-sep eran 26
        // mensajes de 3,680). Traerlos cuesta nada y evita que el bot
        // contradiga un compromiso que el hotel ya dio.
        $staff = $conversation->messages()
            ->where('sender_type', 'staff')
            ->whereNotIn('id', $recent->modelKeys())
            ->withCount('media')
            ->latest('id')->take(10)->get();

        return $recent->concat($staff)->sortBy('id')
            ->map(function (Message $message) {
                // El LLM no ve imágenes: se le anota que el adjunto SÍ
                // llegó, para que jamás diga "no recibí ningún archivo"
                // con la foto visible en la bandeja (bug real 2026-07-24).
                $body = $message->body.($message->direction === 'in' && $message->media_count > 0
                    ? "\n".$this->attachmentNote($message)
                    : '');

                // Nota de voz: ese texto lo escribió una máquina oyendo, no
                // la persona. Un "domingo 6" que en realidad era "16" se
                // paga con una reserva mal hecha, así que se confirma.
                if ($message->direction === 'in' && ($message->meta['voice_note'] ?? false)) {
                    $body .= "\n[llegó como nota de voz transcrita: confirma en una línea las fechas, personas o cantidades que entendiste antes de cotizar o apartar]";
                }

                if ($message->direction === 'in') {
                    return new UserMessage($body);
                }

                // Lo que dijo una persona del hotel NO es texto del bot: es
                // un compromiso ya dado al huésped. Sin marcarlo, el modelo
                // lo leía como suyo y lo sobreescribía sin fricción — caso
                // real cabañas 2026-09-11: el personal escribió "para este
                // sábado tenemos todo ocupado, solo disponible el domingo" y
                // dos mensajes después el bot cotizó el sábado como libre.
                return new AssistantMessage($message->sender_type === 'staff'
                    ? "[PERSONAL DEL HOTEL — ya se lo dijo al huésped]\n".$body
                    : $body);
            })
            ->values()
            ->all();
    }

    /**
     * Qué se sabe del adjunto, dicho para el modelo. Con la lectura del
     * comprobante (InboundMediaService) deja de ser "adjuntó una imagen" a
     * secas: el bot sabe si llegó un comprobante y por cuánto, o si la foto
     * no tiene nada que ver con un pago.
     */
    protected function attachmentNote(Message $message): string
    {
        $reading = $message->meta['media_reading'] ?? null;
        $verdict = is_array($reading) ? ($reading['check']['verdict'] ?? null) : null;
        $description = is_array($reading) && ! empty($reading['description']) ? ': '.$reading['description'] : '';

        return match (true) {
            ! is_array($reading) => '[adjuntó una imagen o documento — el personal puede verlo]',
            $verdict === \App\Services\Payments\ReceiptCheck::NOT_RECEIPT => "[adjuntó una imagen que NO es un comprobante de pago{$description}. No la trates como pago. Si esperabas su comprobante, díselo con amabilidad y pídele la captura de la transferencia]",
            $verdict === \App\Services\Payments\ReceiptCheck::DUPLICATE => '[adjuntó un comprobante que el sistema pasó a revisión del personal. No confirmes ni niegues el pago: di que el personal lo revisa]',
            default => '[adjuntó un comprobante: '.($reading['check']['summary'] ?? 'transferencia').'. El personal lo verifica; tú NO confirmas pagos]',
        };
    }

    /**
     * Busca un huésped del CRM por teléfono (normalizado a dígitos,
     * comparando los últimos 10 — con o sin lada/formato).
     */
    protected function findGuestByPhone(string $phone): ?\App\Models\Guest
    {
        // La misma regla que usan el mostrador, el wizard y las
        // experiencias para no duplicar fichas.
        return \App\Models\Guest::findByContact($phone);
    }

    /**
     * Las mismas herramientas de la Agent API + memoria del huésped +
     * handoff. Con $readOnly (modo copiloto) se excluyen las que tienen
     * efectos: crear_apartado, solicitar_pago y transferir_a_humano.
     *
     * @return array<int, \Prism\Prism\Tool>
     */
    protected function toolset(bool &$handoff, ?Conversation $conversation = null, bool $readOnly = false, array &$used = [], string &$handoffReason = ''): array
    {
        $call = function (string $method, array $params = []) use (&$used): string {
            // Qué herramientas tocó esta respuesta: con eso se sabe si el
            // huésped venía cotizando (para avisarle al hotel fuera de
            // horario) sin tener que adivinarlo leyendo el texto.
            $used[] = $method;

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
                'coupon' => $respond($this->tools->checkCoupon($request)),
                'reopen_hold' => $respond($this->tools->reopenHold(
                    tap($request, fn ($r) => $r->setUserResolver(fn () => \App\Http\Controllers\Tenant\AgentTokenController::ensureAgentUser())),
                    app(\App\Actions\Reservations\TransitionReservation::class),
                )),
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

                    $result = $call('availability', array_filter([
                        'rate_plan_id' => (int) $rate_plan_id,
                        'starts_at' => $starts_at,
                        'ends_at' => $ends_at,
                    ]));

                    $this->markRealQuote($conversation, $result);

                    return $result;
                }),

            Tool::as('consultar_disponibilidad_general')
                ->for('Panorama del hotel completo en un rango: cuántas habitaciones existen de cada tipo, cuántas quedan LIBRES, precio por unidad y total. Con "personas" devuelve además una combinación real para el grupo, y si no alcanza devuelve fechas cercanas verificadas (alternative_dates). Úsala SIEMPRE que el huésped pregunte "qué tienen disponible", venga en grupo, o antes de ofrecerle alternativas a un tipo que no está libre.')
                ->withStringParameter('starts_at', 'Fecha/hora de llegada, formato YYYY-MM-DD HH:MM')
                ->withStringParameter('ends_at', 'Fecha/hora de salida (opcional)', false)
                ->withNumberParameter('personas', 'Cuántas personas son (opcional; con esto se arma la combinación de habitaciones)', false)
                ->using(function (string $starts_at, ?string $ends_at = null, int|float|null $personas = null) use ($call, $conversation): string {
                    $conversation?->markLead(Conversation::LEAD_QUOTING);

                    $result = $call('availability_overview', array_filter([
                        'starts_at' => $starts_at,
                        'ends_at' => $ends_at,
                        'guests' => $personas !== null ? (int) $personas : null,
                        // Para que el servidor pueda avisar cuáles de esas
                        // habitaciones YA son de este huésped: sin esto el
                        // bot lee su propio apartado como "ocupado" y le
                        // dice que ya no hay lugar a quien acaba de pagar.
                        'conversation_id' => $conversation?->id,
                    ]));

                    $this->markRealQuote($conversation, $result);

                    return $result;
                }),

            Tool::as('crear_apartado')
                ->for('Crea un apartado (hold) de habitación como reserva PENDIENTE que el hotel confirmará. Úsalo solo tras confirmar con el huésped: tipo de habitación, tarifa, TOTAL exacto, fecha y nombre. La tarifa DEBE pertenecer al tipo de habitación que el huésped pidió (verifica room_type en consultar_tarifas).')
                ->withNumberParameter('rate_plan_id', 'ID de la tarifa (su room_type debe coincidir con la habitación solicitada)')
                ->withStringParameter('starts_at', 'Llegada, YYYY-MM-DD HH:MM')
                ->withStringParameter('guest_name', 'Nombre completo del huésped')
                ->withStringParameter('guest_phone', 'Teléfono del huésped (opcional)', false)
                ->withStringParameter('guest_email', 'Correo electrónico del huésped (opcional; si el hotel lo pide, pídelo junto con el nombre y mándalo aquí)', false)
                ->withStringParameter('ends_at', 'Salida (opcional)', false)
                ->withStringParameter('cupon', 'Código de cupón que dio el huésped; SOLO si validar_cupon dijo que es válido (opcional)', false)
                ->withStringParameter('metodo_pago', "Cómo eligió pagar el anticipo: 'pasarela', 'transferencia' o 'efectivo'. Algunos hoteles no apartan hasta que el huésped lo elige.", false)
                ->withNumberParameter('personas', 'Cuántas personas se van a quedar. Mándalo SIEMPRE que el huésped lo haya dicho: con más de las incluidas se cobra persona extra y el total cambia.', false)
                ->using(function (int|float $rate_plan_id, string $starts_at, string $guest_name, ?string $guest_phone = null, ?string $guest_email = null, ?string $ends_at = null, ?string $cupon = null, ?string $metodo_pago = null, int|float|null $personas = null) use ($call, $conversation): string {
                    $result = $call('hold', array_filter([
                        'rate_plan_id' => (int) $rate_plan_id,
                        'starts_at' => $starts_at,
                        'guest_name' => $guest_name,
                        // Sin esto la reserva nacía con 1 persona y el total
                        // salía sin persona extra (cabañas 2026-09-14).
                        'adults' => $personas !== null && $personas >= 1 ? (int) $personas : null,
                        'guest_phone' => $guest_phone,
                        'guest_email' => $guest_email,
                        'ends_at' => $ends_at,
                        // Para no duplicar: si esta conversación ya tiene
                        // ese apartado, la herramienta devuelve el mismo.
                        'conversation_id' => $conversation?->id,
                        'coupon_code' => $cupon,
                        'metodo_pago' => $metodo_pago,
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
                                // contact_phone NUNCA se toca: es la llave con
                                // la que el webhook encuentra la conversación.
                                // En WhatsApp es el número con lada (5216…) y
                                // el que teclea el huésped (656…) no coincide:
                                // su siguiente mensaje abría una conversación
                                // nueva y el bot lo olvidaba (cabañas
                                // 2026-09-13/14, 5 huéspedes partidos en dos).
                                // El teléfono tecleado vive en la reserva.
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
                ->withNumberParameter('personas', 'Cuántas personas van en total. Mándalo SIEMPRE que el huésped lo haya dicho: el sistema las reparte entre las habitaciones y cobra la persona extra; sin esto el total sale sin personas extra.', false)
                ->using(function (string $starts_at, string $guest_name, array $habitaciones, ?string $ends_at = null, ?string $guest_phone = null, int|float|null $personas = null) use ($call, $conversation): string {
                    $result = $call('group_hold', array_filter([
                        'starts_at' => $starts_at,
                        'ends_at' => $ends_at,
                        'guest_name' => $guest_name,
                        'guest_phone' => $guest_phone,
                        'guests' => $personas !== null && $personas >= 1 ? (int) $personas : null,
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
                                // contact_phone no se toca: ver crear_apartado.
                            ]));
                            $conversation->markLead(Conversation::LEAD_HOLD);
                        }
                    }

                    return $result;
                }),

            // Cupones (módulo cupones, pedido del hotel de cabañas 2026-09-11):
            // mismas condiciones que el wizard y el apartado; el descuento lo
            // calcula el servidor, nunca el modelo.
            Tool::as('validar_cupon')
                ->for('Valida un código de cupón que el huésped te dio y, con la tarifa y las fechas, calcula el total con descuento. Úsala ANTES de cotizar con descuento. Si es válido, cotiza con su quote_notice y pásalo en crear_apartado (parámetro cupon). Si no, dile el motivo exacto que devuelva.')
                ->withStringParameter('codigo', 'Código del cupón tal como lo escribió el huésped')
                ->withNumberParameter('rate_plan_id', 'ID de la tarifa de la cabaña que quiere (opcional pero necesario para calcular el total)', false)
                ->withStringParameter('starts_at', 'Llegada, YYYY-MM-DD HH:MM (opcional)', false)
                ->withStringParameter('ends_at', 'Salida (opcional)', false)
                ->using(fn (string $codigo, int|float|null $rate_plan_id = null, ?string $starts_at = null, ?string $ends_at = null): string => $call('coupon', array_filter([
                    'code' => $codigo,
                    'rate_plan_id' => $rate_plan_id !== null ? (int) $rate_plan_id : null,
                    'starts_at' => $starts_at,
                    'ends_at' => $ends_at,
                    'conversation_id' => $conversation?->id,
                ]))),

            Tool::as('consultar_reserva')
                ->for('Consulta el estado de una reserva por su código (ej. RES-2026-0001) O de un grupo completo por su folio (ej. GRP-2026-0149), incluido su estado de pago y saldo pendiente. Los folios GRP- son los que tú mismo repartes al apartar varias habitaciones, así que son los que el huésped te va a teclear de vuelta.')
                ->withStringParameter('code', 'Código de la reserva (RES-) o folio del grupo (GRP-)')
                ->using(fn (string $code): string => $call('reservation', ['code' => $code])),

            // Regla del hotel (cabañas 2026-09-11): "si el usuario se tardó en
            // depositar y se venció, volver a reservar la habitación y darle su
            // código". Con el MISMO código: el huésped ya lo tiene anotado y a
            // veces ya depositó con él de concepto.
            Tool::as('reactivar_apartado')
                ->for('Reactiva, con el MISMO código, un apartado que venció sin pago (el huésped se tardó en depositar o depositó tarde). Acepta también un folio de grupo GRP-, y entonces reactiva TODAS sus habitaciones juntas. Revisa que la habitación siga libre y lo vuelve a apartar. Úsala cuando el huésped quiera retomar su apartado vencido. Comparte el código y lo que diga el resultado (message); si ya había mandado comprobante, di que el personal lo verificará. Solo sirve para apartados vencidos: una reserva que canceló el hotel la reabre el personal.')
                ->withStringParameter('codigo_reserva', 'Código del apartado vencido (ej. RES-2026-0001) o folio del grupo (ej. GRP-2026-0149)')
                ->using(function (string $codigo_reserva) use ($call, $conversation): string {
                    $result = $call('reopen_hold', array_filter([
                        'code' => $codigo_reserva,
                        'conversation_id' => $conversation?->id,
                    ]));

                    $code = json_decode($result, true)['status'] ?? null ? strtoupper($codigo_reserva) : null;
                    if ($conversation && $code) {
                        $reservation = \App\Models\Reservation::query()->where('code', $code)->first();

                        if ($reservation) {
                            $conversation->update(['reservation_id' => $reservation->id]);
                            $conversation->markLead(Conversation::LEAD_HOLD);
                        }
                    }

                    return $result;
                }),

            Tool::as('solicitar_pago')
                ->for('Emite el cobro de una reserva (anticipo o saldo; el sistema decide monto y concepto). Úsala tras crear un apartado que requiere prepago, DESPUÉS de preguntar al huésped cómo prefiere pagar (las opciones reales vienen en payment_options del apartado). Según metodo devuelve: un LINK de pago (payment_link), cuentas bancarias para transferencia (pide el comprobante por este chat), o la confirmación de que pagará en efectivo al llegar (dile hasta cuándo queda apartado). Comparte lo que devuelva tal cual, con el monto exacto. NUNCA des un pago por recibido: eso lo confirma el sistema.')
                ->withStringParameter('codigo_reserva', 'Código de la reserva (ej. RES-2026-0001)')
                ->withStringParameter('metodo', "Método que eligió el huésped: 'pasarela' (pagar en línea con link), 'transferencia' o 'efectivo' (paga al llegar al hotel). Omítelo solo si el huésped no expresó preferencia.", false)
                ->withStringParameter('proveedor', "Solo si hay varias pasarelas y el huésped eligió una: 'stripe', 'mercadopago' o 'paypal'.", false)
                ->using(function (string $codigo_reserva, ?string $metodo = null, ?string $proveedor = null) use ($call, $conversation): string {
                    // El modelo a veces inventa el código (caso real cabañas
                    // 2026-09-11: "RES-2026-0037"). Si no existe y la
                    // conversación tiene su apartado, se cobra ese.
                    $code = strtoupper(trim($codigo_reserva));
                    $known = \App\Models\Reservation::query()->where('code', $code)->exists()
                        || \App\Models\ReservationGroup::query()->where('code', $code)->exists();

                    if (! $known && $conversation?->reservation) {
                        $code = $conversation->reservation->displayCode();
                    }

                    $result = $call('payment', array_filter([
                        'code' => $code,
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
                            // contact_phone no se toca: es la llave del
                            // webhook y pisarla parte la conversación en dos.
                        ]));

                        return json_encode([
                            'encontrado' => false,
                            'nota' => 'Huésped nuevo: atiéndelo normal; se registrará al crear su primer apartado.',
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    $conversation?->update(array_filter([
                        'guest_id' => $guest->id,
                        'contact_name' => $nombre ?: $guest->full_name,
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
                ->using(function (string $motivo) use (&$handoff, &$handoffReason): string {
                    $handoff = true;
                    // El motivo viaja al aviso que recibe el hotel: "pidió
                    // hablar con alguien" y "reclama un pago" no se atienden
                    // con la misma prisa.
                    $handoffReason = trim($motivo);

                    return json_encode(['ok' => true, 'motivo' => $motivo], JSON_UNESCAPED_UNICODE);
                }),
        ];

        if ($readOnly) {
            $tools = array_values(array_filter(
                $tools,
                fn ($tool) => ! in_array($tool->name(), ['crear_apartado', 'crear_apartado_grupo', 'reactivar_apartado', 'solicitar_pago', 'transferir_a_humano'], true),
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
            // Cupones: módulo `cupones` y al menos un cupón activo.
            'validar_cupon' => $this->tools->couponsPublic(),
        ];

        return array_values(array_filter(
            $tools,
            fn ($tool) => $available[$tool->name()] ?? true,
        ));
    }
}
