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

    /**
     * Verbos con los que el modelo anuncia un traspaso que no ejecutó.
     *
     * "Comunicar" faltaba y es el verbo más natural en español: cabañas
     * 2026-09-23 17:51 (Chago 02, conv. 1448) pidió "Hablar con asesor", el
     * bot contestó "lo comunico con un asesor para que le atienda
     * personalmente" y la conversación se quedó con el bot encendido — nadie
     * del hotel se enteró de que lo estaban esperando.
     */
    protected const HANDOFF_VERB = '/(transfer[íi]\b|transferid[oa]\b|transferir\b|transfiero\b|transfiriendo\b|pas[oé] con\b|pasar[ée] con\b|paso tu|escalo\b'
        .'|comunic(?:o|amos|ar[ée]|aremos|arte|arle|o de inmediato)\b|enlaz(?:o|amos|ar[ée]|aremos)\b|canaliz(?:o|amos|ar[ée]|aremos)\b|deriv(?:o|amos|ar[ée]|aremos)\b)/iu';

    /**
     * A quién dice pasarlo: sin un humano al otro lado no es un traspaso.
     * "Asesor" y "ejecutivo" se agregaron con el caso de Chago (conv. 1448).
     */
    protected const HANDOFF_TARGET = '/(persona|personal|recepci[óo]n|recepcionista|equipo|alguien|compañer[oa]|encargad[oa]|asesor|ejecutiv[oa]|anfitri[óo]n|agente)/iu';

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
     * @param  bool  $canRetryLater  Si el proveedor no contesta, ¿se puede
     *                               reintentar en unos segundos (RetryAgentReply)
     *                               en vez de transferir? False dentro del
     *                               propio reintento: ahí ya no hay red abajo.
     */
    public function reply(Conversation $conversation, ?Message $inbound = null, bool $canRetryLater = true): ?Message
    {
        $handoff = false;
        $handoffReason = '';
        $text = '';
        $meta = [];
        $used = [];
        $answeredBy = null;

        // Dos vueltas a la cadena de proveedores. Si ninguno contesta, el
        // huésped acaba con una persona sin haber preguntado nada raro: de los
        // 52 traspasos de cabañas entre el 8 y el 22 de septiembre, 8 fueron
        // esto (picos de "openai is overloaded" y tiempos agotados), y en la
        // bandeja no se distinguían de un traspaso decidido por el bot.
        foreach ([1, 2] as $vuelta) {
            if ($vuelta === 2) {
                if ($answeredBy !== null || $handoff) {
                    break;
                }

                usleep(2_000_000); // el pico del proveedor dura segundos
            }

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

                    break 2; // el primero que responde gana
                } catch (Throwable $e) {
                    report($e);

                    if ($handoff) {
                        break 2; // el traspaso ya se decidió; no probar otro proveedor
                    }
                }
            }
        }

        // Ni en la segunda vuelta. El pico del proveedor dura segundos, así
        // que ANTES de molestar al hotel se programa un tercer intento: a un
        // "Hola" no se le contesta con una persona porque nuestro proveedor
        // se saturó. Caso real cabañas 2026-09-22 (tarde): 4 de los 5
        // traspasos fueron esto — "Hola", "¿dónde se encuentra ubicado?",
        // "¿a qué hora es la entrada?" y una pregunta de precios.
        if ($answeredBy === null && ! $handoff) {
            $ultimo = (int) $conversation->messages()->where('direction', 'in')->max('id');

            // Solo si el hotel TIENE proveedores y todos fallaron: sin
            // ninguno configurado no hay pico que esperar, hay un hotel sin
            // asistente, y ahí el traspaso es lo correcto.
            if ($canRetryLater && $ultimo > 0 && $this->providers()->isNotEmpty()) {
                \Illuminate\Support\Facades\Log::warning('Agente: ningún proveedor respondió, se reintenta en unos segundos', [
                    'conversation_id' => $conversation->id,
                ]);

                \App\Jobs\RetryAgentReply::dispatch((string) tenant('id'), $conversation->id, $ultimo)
                    ->delay(now()->addSeconds(25));

                return null; // el bot sigue encendido; nadie se entera del pico
            }

            // Sin red abajo: se transfiere, pero se dice por qué. Sin esta
            // marca, "el bot transfirió" y "el bot no pudo contestar" se ven
            // igual en la bandeja y se persigue el problema equivocado.
            \Illuminate\Support\Facades\Log::warning('Agente: ningún proveedor respondió, se transfiere', [
                'conversation_id' => $conversation->id,
            ]);

            $meta['provider_failure'] = true;
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

        // ¿Contesta sobre la fecha que el huésped acaba de pedir? Si no,
        // se regenera una vez; si vuelve a fallar, $text queda vacío y el
        // traspaso de abajo se hace cargo.
        if (! $handoff && $text !== '' && $answeredBy !== null) {
            $text = $this->reanswerOffTargetDate($conversation, $text, $answeredBy, $used, $meta, $handoffReason);
        }

        $hours = app(SupportHours::class);

        // Traspasar por una pregunta que el bot SÍ puede contestar es perder
        // al cliente por nada. Caso real cabañas 2026-09-17 (conv. 992): tras
        // dos fechas llenas, el huésped escribió "para el 26 de septiembre?"
        // y el bot lo transfirió; un minuto después, preguntado otra vez,
        // contestó la disponibilidad correcta él solo.
        if ($handoff && $answeredBy !== null && $this->handoffIsPremature($conversation, $handoffReason)) {
            $retry = $this->answerInsteadOfHandoff($conversation, $answeredBy, $meta);

            if ($retry !== null) {
                $text = $retry;
                $handoff = false;
            }
        }

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
        $text = $this->enforceLanguage($text, $answeredBy, $conversation);

        $body = $this->sanitizeWeekdays($this->sanitizeChatText($this->sanitizeClockClaims($this->sanitizeBankBlocks($this->sanitizeBankNumbers($this->sanitizeGatewayLinks(
            $this->enforceLiveReservationClaims(
                $this->enforceCashClaims(
                    $this->enforcePaymentClaims(
                        $this->enforceHoldDeadlineClaims(
                            $this->enforceHandoffClaims(
                                $this->enforceRescheduleClaims(
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
                ),
                $conversation,
            ),
            $conversation,
        ))))));

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
    /**
     * ¿El traspaso es por algo que el bot podía contestar solo?
     *
     * Transferir está bien cuando el huésped lo pide, se queja, reclama un
     * pago o pregunta algo que no está en las herramientas (un evento, una
     * factura). NO está bien cuando solo preguntó por una fecha, un precio o
     * la disponibilidad: para eso tiene herramientas, y el hotel acaba
     * atendiendo a mano lo que el bot ya sabía.
     */
    protected function handoffIsPremature(Conversation $conversation, string $reason): bool
    {
        $last = (string) $conversation->messages()
            ->where('direction', 'in')
            ->latest('id')
            ->value('body');

        if (trim($last) === '') {
            return false;
        }

        // Motivos que SIEMPRE se respetan, aunque el huésped haya nombrado una
        // fecha: la restricción interna de recepción y el pago reclamado.
        if (preg_match('/revisi[óo]n de recepci[óo]n|restricci[óo]n|pag[óo]|pago|comprobante|transferí|dep[óo]sito/iu', $reason) === 1) {
            return false;
        }

        // Lo que el huésped pide y no se contesta con herramientas: hablar con
        // alguien, quejas, eventos... y también lo que el hotel agenda o
        // arregla a mano (una visita a las cabañas, decoración, un trato
        // comercial). Eso último se agregó el 2026-09-22 junto con el freno de
        // abajo: al frenar más traspasos había que dejar claro cuáles siguen.
        $humano = '/hablar con|con una persona|un humano|alguien m[áa]s|asesor|ejecutiv[oa]|recepcionista|encargad|gerente|due[ñn]o|queja|reclamo|molest|inconform|factura|evento|boda|xv|graduaci[óo]n|cotizaci[óo]n especial|ya pagu|ya transfer|mand[ée] el comprobante|cita|ir a ver|visitar|conocer las|decoraci|globos|p[ée]talos|publicidad|intercambio/iu';

        if (preg_match($humano, $last) === 1) {
            return false;
        }

        // Y lo que sí: fechas, precios, disponibilidad. Las fechas en modo
        // SUELTO, porque quien está cotizando contesta "sábado 26" o "el 26",
        // no "26 de septiembre" (con el modo estricto, "sábado 26" no era
        // ninguna fecha y el traspaso seguía de largo).
        $consultable = '/disponib|hay (lugar|cabaña|espacio)|tienes?\b|queda[n]?\b|precio|costo|cu[áa]nto|tarifa|libre|informaci[óo]n|informes/iu';

        if ($this->datesMentioned($last, loose: true) !== [] || preg_match($consultable, $last) === 1) {
            return true;
        }

        return $this->answersQuotingQuestion($conversation, $last);
    }

    /**
     * ¿El huésped solo está CONTESTANDO lo que el bot le acaba de preguntar?
     *
     * El freno de arriba juzga el mensaje suelto, y en una cotización en curso
     * el huésped contesta con una palabra: ahí no hay ni fecha escrita ni la
     * palabra "precio", así que el traspaso pasaba de largo. Casos reales de
     * cabañas del 2026-09-22: el bot preguntó "¿la llegada sería viernes o
     * sábado?" y ella contestó "Sábado" (conv. 1231); tras cotizar precios e
     * inclusiones, "Tengo fechas en mente" (conv. 1244); preguntado por el
     * grupo, "Para 2 adultos y tres menores" (conv. 1258). Los tres se
     * transfirieron y el personal acabó cotizando a mano, hasta una hora
     * después, lo que el bot ya sabía.
     *
     * Se exige que el propio bot haya PREGUNTADO algo de cotización en sus
     * últimos mensajes y que la respuesta traiga un dato de cotización (un
     * día, un número, un sí). Así "una cena romántica" o "¿podría ir a
     * verlas?" siguen yendo con una persona.
     */
    protected function answersQuotingQuestion(Conversation $conversation, string $last): bool
    {
        $pregunto = $conversation->messages()
            ->where('direction', 'out')
            ->where('sender_type', 'bot')
            ->latest('id')
            ->limit(3)
            ->pluck('body')
            ->implode("\n");

        if (! str_contains($pregunto, '?')) {
            return false;
        }

        $deCotizacion = '/fecha|llegada|salida|noche|d[ií]a|persona|adulto|ni[nñ]o|hu[ée]sped|caba[nñ]a|habitaci[óo]n|cu[áa]nto|cu[áa]l/iu';

        if (preg_match($deCotizacion, $pregunto) !== 1) {
            return false;
        }

        $dato = '/\d|^\s*(s[ií]|no|ok|claro|as[íi] es|correcto|exacto)\b|lunes|martes|mi[ée]rcoles|jueves|viernes|s[áa]bado|domingo|fecha|noche|persona|adulto|ni[nñ]o|caba[nñ]a|habitaci[óo]n|fin de semana|puente|ma[nñ]ana|hoy/iu';

        return preg_match($dato, trim($last)) === 1;
    }

    /**
     * Segundo intento SIN poder transferir (el juego de solo lectura no
     * incluye transferir_a_humano): contesta la pregunta con las
     * herramientas. Si tampoco sale texto, el traspaso sigue su curso.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function answerInsteadOfHandoff(Conversation $conversation, AiProvider $provider, array &$meta): ?string
    {
        \Illuminate\Support\Facades\Log::warning('Agente: iba a transferir algo que podía contestar, se reintenta', [
            'conversation_id' => $conversation->id,
        ]);

        try {
            $handoff = false;
            $used = [];
            $reason = '';

            $aviso = 'CORRECCIÓN: ibas a transferir al huésped con una persona del hotel, pero lo que preguntó lo puedes contestar TÚ con tus herramientas (disponibilidad, precios, políticas). Consúltalo y contéstale con los datos reales. No transfieras, no prometas que alguien más lo atenderá y no le pidas que espere.';

            $response = $this->run($provider, fn ($request) => $request
                ->withSystemPrompt($this->systemPrompt($conversation)."\n\n".$aviso)
                ->withMessages($this->history($conversation))
                ->withTools($this->toolset($handoff, $conversation, true, $used, $reason))
                ->withMaxSteps(6));

            $text = trim($response->text);

            if ($text === '') {
                return null;
            }

            $meta['retry'] = 'handoff_evitado';
            $meta['completion_tokens'] = ($meta['completion_tokens'] ?? 0) + ($response->usage->completionTokens ?? 0);

            return $text;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

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
    /**
     * El bot NO puede mover una reserva: entre sus herramientas no hay
     * ninguna que cambie fechas (consultar, apartar, cobrar, reactivar un
     * apartado vencido y transferir; nada más). Prometerlo es dejar a un
     * huésped creyendo que su reserva cambió de día.
     *
     * Caso real cabañas 2026-09-17 (conv. 917, RES-2026-1758): al huésped
     * que pagó por el viernes creyendo que era sábado le contestó "Tiene
     * razón... Entonces las fechas quedan: entrada sábado 19 de septiembre",
     * con el sábado LLENO y sin poder cambiar nada.
     *
     * Lo que promete se borra y la conversación pasa a una persona.
     */
    protected function enforceRescheduleClaims(string $text, ?Conversation $conversation): string
    {
        if ($conversation === null || trim($text) === '') {
            return $text;
        }

        $lines = preg_split('/\R/u', $text) ?: [];
        $offending = [];

        foreach ($lines as $line) {
            foreach ($this->sentencesOf($line) as $sentence) {
                if ($this->claimsReschedule($sentence)) {
                    $offending[] = $sentence;
                }
            }
        }

        if ($offending === []) {
            return $text;
        }

        \Illuminate\Support\Facades\Log::warning('Agente: prometió mover una reserva, algo que no puede hacer', [
            'conversation_id' => $conversation->id,
            'texto' => $text,
        ]);

        $this->markHandoff($conversation, 'El huésped pide cambiar la fecha de su reserva.');

        $kept = collect($lines)
            ->map(fn (string $line) => collect($this->sentencesOf($line))
                ->reject(fn (string $sentence) => in_array($sentence, $offending, true))
                ->map(fn (string $sentence) => trim($sentence))
                ->filter()
                ->implode(' '))
            ->filter(fn (string $line) => trim($line) !== '')
            ->implode("\n");

        return trim($kept."\n\nEl cambio de fecha lo hace una persona del hotel. ".$this->handoffLine());
    }

    /** ¿Esta frase promete mover o corregir la fecha de una reserva? */
    protected function claimsReschedule(string $sentence): bool
    {
        $plain = $this->plain($sentence);

        // "no puedo cambiarla", "no se puede mover": eso es decir la verdad.
        if (preg_match('/\bno\s+(puedo|podemos|se\s+puede|es\s+posible)\b/u', $plain) === 1) {
            return false;
        }

        // Reactivar un apartado vencido SÍ lo puede hacer (reactivar_apartado).
        if (preg_match('/\breactiv/u', $plain) === 1) {
            return false;
        }

        // "muev" aparte de "mov": en español el verbo cambia de raíz (muevo,
        // mueve) y "la muevo para esas fechas" se colaba.
        return preg_match('/\b(cambi|mov|muev|reagend|modific|actualiz|recorr|corrij|correcci)\w*\b[^.!?]{0,80}\b(reserva\w*|apartado|fecha\w*|estancia|d[ií]as?)\b/u', $plain) === 1
            || preg_match('/\b(las|sus|tus)\s+fechas\s+(quedan|quedar[ií]an|cambian|ser[ií]an)\b/u', $plain) === 1;
    }

    protected function enforceHandoffClaims(string $text, ?Conversation $conversation): string
    {
        if ($conversation === null || trim($text) === '' || ! $this->claimsHandoff($text)) {
            return $text;
        }

        \Illuminate\Support\Facades\Log::warning('Agente: anunció un traspaso sin llamar la herramienta', [
            'conversation_id' => $conversation->id,
            'texto' => $text,
        ]);

        // La promesa inventada se cae completa: el huésped no puede quedarse
        // con "recibirá una llamada" al lado de la frase verdadera.
        $kept = collect(preg_split('/\R+/u', $text) ?: [])
            ->reject(fn (string $line) => $this->claimsHandoff($line))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->implode("\n");

        // Anunciar el traspaso no lo justifica. Este camino no pasaba por
        // ningún freno: entre el 8 y el 22 de septiembre, cabañas mandó así
        // 36 chats con una persona, muchos con la respuesta ya contestada
        // encima. Casos reales del 2026-09-22: la lista de tarifas completa
        // con el "te comunicamos" pegado al final (conv. 1258), y un "el
        // cambio de fecha lo hace una persona del hotel" —que es un dato,
        // no una promesa— que mandó a recepción a quien preguntaba por el
        // fin de semana (conv. 1158). Si lo que preguntó el huésped el bot
        // lo sabe contestar, se cae la promesa y se queda la respuesta.
        if (trim($kept) !== '' && $this->handoffIsPremature($conversation, 'El asistente anunció el traspaso en su respuesta.')) {
            \Illuminate\Support\Facades\Log::warning('Agente: anunció un traspaso que no hacía falta, se queda con la respuesta', [
                'conversation_id' => $conversation->id,
            ]);

            return trim($kept);
        }

        $this->markHandoff($conversation, 'El asistente anunció el traspaso en su respuesta.');

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
        // Se trabaja por RENGLONES, no por frases sueltas: el divisor corta
        // también en los dos puntos, así que "- Cabaña Real: $4,500" eran dos
        // pedazos. Se borraba el del nombre y quedaba el precio huérfano
        // ("hasta 6 personas, $4,500"), y el renglón siguiente apagaba el modo
        // lista, así que solo se atrapaba la PRIMERA cabaña de la lista y las
        // demás se ofrecían igual (cabañas, conv. 17-sep-2026).
        $lines = preg_split('/\R/u', $text) ?: [];

        // NOCHES, no fechas sueltas: "del viernes 18 al sábado 19" es una
        // sola noche y el 19 es el día en que se van. Juzgar por la salida
        // fue lo que borró tres respuestas correctas seguidas (conv. 1011).
        $nights = [];
        $spans = [];
        $wrong = [];
        $freeLabels = [];
        // Renglones que se caen enteros, y frases sueltas dentro de un
        // renglón de prosa.
        $dropLine = [];
        $dropSentence = [];
        // "Para el 27 tenemos estas cabañas disponibles:" y abajo la lista.
        // El nombre de la habitación va en su propio renglón, sin fecha ni
        // la palabra "disponible": la promesa la hereda del encabezado.
        $listing = false;
        $header = null;
        $itemsKept = [];

        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }

            $bullet = preg_match('/^\s*[-•*\d]/u', $line) === 1;
            $lineNights = $this->nightsClaimed($line);
            $nights = $lineNights !== [] ? $lineNights : $nights;
            $claims = $this->claimsAvailability($line);
            $named = $this->roomTypesMentioned($line, $types);

            // Encabezado de lista ("Para el 27 tenemos estas disponibles:").
            if ($claims && $named->isEmpty()) {
                $listing = $nights !== [];
                $header = $listing ? $i : null;

                if ($header !== null) {
                    $itemsKept[$header] = 0;
                }

                continue;
            }

            if (! $bullet && ! $claims) {
                $listing = false;
                $header = null;
            }

            $isItem = $listing && $bullet;

            if ($nights === [] || $named->isEmpty() || ! ($claims || $isItem)) {
                continue;
            }

            $busy = $named->filter(fn (\App\Models\RoomType $type) => ! in_array($type->id, $mine, true)
                && ! $this->typeIsFreeEveryNight($type, $nights, $availability));

            if ($busy->isEmpty()) {
                if ($isItem && $header !== null) {
                    $itemsKept[$header]++;
                }

                continue;
            }

            if ($bullet) {
                // Renglón de lista: se cae completo, con precio y todo.
                $dropLine[$i] = true;
            } else {
                // Prosa: solo las frases que ofrecen lo ocupado; lo demás del
                // párrafo (una pregunta, un dato) se respeta.
                foreach ($this->sentencesOf($line) as $sentence) {
                    if ($this->roomTypesMentioned($sentence, $types)->isEmpty()) {
                        continue;
                    }

                    $dropSentence[$i][] = $sentence;
                }

                if (($dropSentence[$i] ?? []) === []) {
                    $dropLine[$i] = true;
                }
            }

            $key = $this->nightsKey($nights);
            $spans[$key] ??= $nights;
            $wrong[$key] = array_values(array_unique([...($wrong[$key] ?? []), ...$busy->pluck('name')->all()]));
            $freeLabels[$key] ??= $types
                ->filter(fn (\App\Models\RoomType $type) => $this->typeIsFreeEveryNight($type, $nights, $availability))
                ->pluck('name')
                ->values()
                ->all();
        }

        if ($dropLine === [] && $dropSentence === []) {
            return $text;
        }

        // Un encabezado de lista al que se le cayeron TODOS los renglones
        // deja un "Tenemos:" colgando de la nada.
        foreach ($itemsKept as $headerLine => $kept) {
            if ($kept === 0) {
                $dropLine[$headerLine] = true;
            }
        }

        // Un "Total: $38,000" que sumaba los renglones borrados ya no cuadra
        // con lo que queda a la vista. Se cae el total del mismo bloque (los
        // renglones seguidos sin blanco en medio) donde se borró algo.
        $block = [];

        foreach ([...$lines, ''] as $i => $line) {
            if (trim($line) !== '') {
                $block[] = $i;

                continue;
            }

            $touched = collect($block)->contains(fn (int $j) => isset($dropLine[$j]) || isset($dropSentence[$j]));

            if ($touched) {
                foreach ($block as $j) {
                    if (preg_match('/\btotal\b/iu', $lines[$j]) === 1 && preg_match('/\$\s?\d/u', $lines[$j]) === 1) {
                        $dropLine[$j] = true;
                    }
                }
            }

            $block = [];
        }

        \Illuminate\Support\Facades\Log::warning('Agente: ofreció habitaciones que no están libres', [
            'conversation_id' => $conversation?->id,
            // Contra qué noches se juzgó: sin esto no se puede saber si el
            // guardián borró bien o se equivocó de fecha.
            'noches' => array_map(fn (array $span) => $this->nightsKey($span), $spans),
            'texto' => $text,
        ]);

        $truth = [];

        foreach ($wrong as $key => $names) {
            $label = $this->nightsLabel($spans[$key] ?? []);
            $free = $freeLabels[$key] ?? [];

            // Esto lo LEE EL HUÉSPED: nunca una instrucción para el modelo.
            // "dile la verdad y ofrécele otra fecha" se le mandó tal cual a un
            // huésped el 17-sep-2026.
            $truth[] = $free === []
                ? 'Para '.$label.' no queda ninguna habitación libre. Con gusto reviso otra fecha.'
                : 'Para '.$label.', '.implode(' y ', $names)
                    .(count($names) === 1 ? ' ya no está disponible' : ' ya no están disponibles').'. '
                    .'Lo que sí queda libre ese día: '.implode(', ', $free).'.';
        }

        // Se rearma respetando los renglones: juntarlo todo con espacios era
        // lo que dejaba el mensaje amontonado en un solo párrafo.
        $kept = [];

        foreach ($lines as $i => $line) {
            if (isset($dropLine[$i])) {
                continue;
            }

            if (isset($dropSentence[$i])) {
                $line = collect($this->sentencesOf($line))
                    ->reject(fn (string $sentence) => in_array($sentence, $dropSentence[$i], true))
                    ->map(fn (string $sentence) => trim($sentence))
                    ->filter()
                    ->implode(' ');

                if (trim($line) === '') {
                    continue;
                }
            }

            // Sin renglones en blanco pegados donde se cayó algo.
            if (trim($line) === '' && ($kept === [] || trim((string) end($kept)) === '')) {
                continue;
            }

            $kept[] = $line;
        }

        return trim(implode(' ', $truth)."\n\n".trim(implode("\n", $kept)));
    }

    /**
     * Frases de un renglón. Corta en dos puntos además del punto final
     * porque un encabezado ("Tenemos:") también cierra una idea.
     *
     * @return array<int, string>
     */
    protected function sentencesOf(string $line): array
    {
        return preg_split('/(?<=[.!?:])\s+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
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

            // Familia sin número: "las Sencillas" son la 1, 2, 3 y 4. Con
            // número ("Sencilla 2", "Sencillas 2 y 3") nombra SOLO esas: antes
            // "Cabaña Sencilla 2" contaba como las cuatro, y con la 1 y la 4
            // ocupadas el guardián borró la 2 y la 3 que sí estaban libres y
            // dejó un total de $38,000 que ya no sumaba (cabañas, 30-sep-2026).
            if (! preg_match('/^(.+?)\s*(\d+)$/u', $key, $parts)) {
                return false;
            }

            [, $family, $number] = $parts;

            preg_match_all(
                '/\b'.preg_quote($family, '/').'s?\b(\s*(?:#|no\.?|num\.?)?\s*\d+(?:\s*(?:,|y|e|o)\s*\d+)*)?/u',
                $haystack,
                $mentions,
                PREG_SET_ORDER,
            );

            foreach ($mentions as $mention) {
                $listed = $mention[1] ?? '';

                if ($listed === '') {
                    return true;
                }

                preg_match_all('/\d+/', $listed, $numbers);

                if (in_array($number, $numbers[0], true)) {
                    return true;
                }
            }

            return false;
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
     * Noches que promete un renglón.
     *
     * La última fecha de un rango es la SALIDA, no una noche: "del viernes 18
     * al sábado 19" es UNA noche, la del 18. Caso real cabañas 2026-09-17
     * (conv. 1011): una familia de 4 preguntó TRES veces por el viernes 18 al
     * sábado 19; el modelo contestó bien las tres veces y el guardián le borró
     * la lista de las cuatro cabañas libres y le contestó "para el sábado 19
     * no queda ninguna habitación libre" — cierto, pero el 19 era el día en
     * que se iban.
     *
     * Se lee en modo SUELTO a propósito: en "viernes 18 al sábado 19 de
     * septiembre" el mes solo acompaña a la SEGUNDA fecha, así que el modo
     * normal veía únicamente el 19 y ni siquiera se enteraba del 18.
     *
     * @return array<int, \Carbon\CarbonImmutable>
     */
    protected function nightsClaimed(string $line): array
    {
        $dates = array_values($this->datesMentioned($line, loose: true));

        if (count($dates) < 2) {
            return $dates;
        }

        usort($dates, fn (\Carbon\CarbonImmutable $a, \Carbon\CarbonImmutable $b) => $a <=> $b);

        // Sin conector de rango son fechas sueltas ("el 20 y el 27 libres"):
        // cada una es su propia noche y todas deben estar libres.
        if (! $this->mentionsDateRange($line)) {
            return $dates;
        }

        $first = $dates[0];
        $last = $dates[count($dates) - 1];
        $nights = [];

        // Tope de dos semanas: son consultas al motor de disponibilidad por
        // tipo y por noche, y una estancia de mes no se cotiza por chat.
        for ($night = $first; $night < $last && count($nights) < 14; $night = $night->addDay()) {
            $nights[] = $night;
        }

        return $nights !== [] ? $nights : [$first];
    }

    /**
     * ¿El renglón habla de un rango de fechas ("del 18 al 19")? La "a" suelta
     * no cuenta: "alberca de 9:00 AM a 10:30 PM" es un horario.
     */
    protected function mentionsDateRange(string $line): bool
    {
        $plain = $this->plain((string) preg_replace('/\s+/u', ' ', $line));

        return preg_match('/\d\s*(?:\p{L}+\s+){0,2}?(?:al|hasta)\s/u', $plain) === 1;
    }

    /**
     * Ofrecer una habitación para una estancia es prometerla TODAS sus
     * noches: libre la primera y ocupada la segunda no se puede vender.
     *
     * @param  array<int, \Carbon\CarbonImmutable>  $nights
     */
    protected function typeIsFreeEveryNight(\App\Models\RoomType $type, array $nights, \App\Services\AvailabilityService $availability): bool
    {
        foreach ($nights as $night) {
            if (! $this->typeIsFree($type, $night, $availability)) {
                return false;
            }
        }

        return $nights !== [];
    }

    /** @param  array<int, \Carbon\CarbonImmutable>  $nights */
    protected function nightsKey(array $nights): string
    {
        return implode(',', array_map(
            fn (\Carbon\CarbonImmutable $night) => $night->toDateString(),
            $nights,
        ));
    }

    /**
     * Cómo se nombra la estancia en el mensaje que LEE EL HUÉSPED: una noche
     * por su día, y varias de la llegada a la SALIDA (última noche + 1), que
     * es como la lee cualquiera. Fechas sueltas se enumeran.
     *
     * @param  array<int, \Carbon\CarbonImmutable>  $nights
     */
    protected function nightsLabel(array $nights): string
    {
        if ($nights === []) {
            return 'esa fecha';
        }

        $fmt = fn (\Carbon\CarbonImmutable $night) => $night->locale('es')->isoFormat('dddd D [de] MMMM');
        $first = $nights[0];
        $last = $nights[count($nights) - 1];

        if (count($nights) === 1) {
            return 'el '.$fmt($first);
        }

        $contiguas = count($nights) === (int) $first->diffInDays($last) + 1;

        return $contiguas
            ? 'del '.$fmt($first).' al '.$fmt($last->addDay())
            : 'el '.implode(' y el ', array_map($fmt, $nights));
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

        // Solo lo que el huésped PUDO recibir (CLABE y tarjeta): si el número
        // de cuenta interno entrara a la lista de válidos, un mensaje que lo
        // trajera pasaría el filtro y se le filtraría al huésped.
        $validNumbers = $active
            ->flatMap(fn (array $account) => \App\Support\BankAccountNumber::normalize($account)['guestDigits'])
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
        // ¿El modelo escribió renglones "Campo: valor" de banco? Se
        // reemplazan SIEMPRE por el bloque fijo, aunque parezcan correctos:
        // el bot no redacta datos bancarios, los pega desde la configuración.
        $structured = false;
        // Donde iba el primer dato bancario: ahí se pone el bloque rehecho, no
        // al final del mensaje (quedaba después de "Después de hacer la
        // transferencia…").
        $insertAt = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            // La línea de la alternativa es parte del bloque: si se deja
            // pasar, al rehacer el bloque sale DOS veces (RES-2026-1767).
            // Se compara por lo que DICE, no por su redacción exacta: el
            // filtro pedía "si TU app" y el modelo la escribió de usted
            // ("si SU app"), así que se coló y salió duplicada otra vez
            // (RES-2026-1769, 17-sep-2026).
            if (preg_match('/permite\s+transferir\s+a\s+tarjeta/iu', $line)) {
                $insertAt ??= count($kept);
                $structured = true;

                continue;
            }

            if (preg_match('/^\s*[-*•]?\s*(clabe(?:\s+interbancaria)?|n[uú]mero de cuenta|no\.?\s*de\s*cuenta|cuenta|tarjeta(?:\s+de\s+d[ée]bito)?|banco|titular|beneficiario)\s*:(.*)$/iu', $line, $m)) {
                $insertAt ??= count($kept);
                $structured = true;
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

        if ($suspicious === [] && ! $structured) {
            return $text;
        }

        if ($suspicious !== []) {
            rescue(fn () => \Illuminate\Support\Facades\Log::warning('Agente: datos bancarios inventados o incompletos, se rehízo el bloque', [
                'renglones' => $suspicious,
            ]), null, false);
        }

        $insertAt ??= count($kept);
        $replacement = [];

        if ($active->isNotEmpty()) {
            $transferOpen ??= app(\App\Services\ReservationPolicy::class)->transferOpenNow();

            if ($transferOpen) {
                // Etiqueta según lo que ES el número: una tarjeta de 16
                // dígitos anunciada como "Cuenta" hacía fallar la
                // transferencia en la app del huésped.
                $replacement = explode("\n", $active
                    ->map(fn (array $account) => implode("\n", \App\Support\BankAccountNumber::blockLines($account)))
                    ->implode("\n\n"));
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

        // Cinturón contra duplicados: si el modelo copió bien un renglón del
        // bloque y aquí no se reconoció, no puede quedarse además del rehecho.
        $delBloque = collect($replacement)->map(fn (string $line) => trim($line))->filter()->all();

        if ($delBloque !== []) {
            $antes = count($kept);
            $kept = array_values(array_filter(
                $kept,
                fn (string $line, int $i) => $i >= $insertAt || ! in_array(trim($line), $delBloque, true),
                ARRAY_FILTER_USE_BOTH,
            ));
            $insertAt -= $antes - count($kept);

            $kept = array_values(array_filter(
                $kept,
                fn (string $line, int $i) => $i < $insertAt || ! in_array(trim($line), $delBloque, true),
                ARRAY_FILTER_USE_BOTH,
            ));
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
            // guestDigits, no todos: la cuenta interna debe poder borrarse de
            // un mensaje al huésped, no quedar en la lista blanca.
            ->flatMap(fn (array $account) => \App\Support\BankAccountNumber::normalize($account)['guestDigits'])
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
     * Marcas con mayúscula a media palabra que SÍ son correctas. Sin esta
     * lista, "WhatsApp" se vería igual de roto que "díasWould".
     */
    protected const BRAND_WORDS = [
        'WhatsApp', 'PayPal', 'MercadoPago', 'OpenPay', 'TikTok', 'YouTube', 'iPhone',
        'KuiraWeb', 'Kuirawebreserve', 'AirBnB', 'Airbnb', 'McAllen', 'PayU', 'BanCoppel',
    ];

    /**
     * UNA palabra en inglés a media frase en español. Solo palabras que en un
     * hotel mexicano NO se dicen en inglés: "check-in", "room service", "spa"
     * o "Booking" son español de hotel y por eso no están aquí.
     */
    protected const ENGLISH_STRAY = '/\b(attention|please|sorry|thanks|thank you|available|availability|welcome|information|regards|kindly|however|shortly|assistance|greetings|apologies|unfortunately|currently|immediately|confirmation|payment|reservation|nights)\b/iu';

    /**
     * Palabras que no existen ni en español ni en inglés: portugués,
     * italiano y francés que se le escapan al modelo.
     */
    protected const FOREIGN_WORDS = [
        'então', 'entao', 'você', 'voce', 'obrigad', 'não', 'quarto disponível', 'desculpe',
        'sarebbe', 'vorrei', 'grazie', 'prego', 'disponibilità',
        'accueillir', 'bonjour', 'merci', 'aujourd', 'voudrais', 'pouvez', 'nous sommes',
    ];

    /**
     * Basura del modelo que el huésped no debería ver nunca. 14 mensajes en
     * 2.5 días de cabañas: "¿Qué díasWould you like…" (conv. 648), "Hello!
     * I\'d be happy" a quien escribía en español (660), "[Asistente
     * Virtual]" (614) y "[nombre del asistente]" (745) sin llenar, y fugas
     * de portugués, italiano y francés.
     *
     * Tres señales, todas deterministas: un marcador entre corchetes sin
     * llenar, dos palabras pegadas con mayúscula en medio, o vocabulario de
     * un idioma que este hotel no habla. Lo cuarto —contestar en inglés a
     * quien escribe en español— se mide con palabras funcionales, que es lo
     * que de verdad distingue un idioma del otro.
     */
    /**
     * ¿La única falla es una palabra suelta en inglés? Entonces se poda esa
     * oración en vez de rehacer el mensaje: se comprueba quitándola y
     * volviendo a juzgar lo que queda.
     */
    protected function strayEnglishOnly(string $text, bool $guestInSpanish): bool
    {
        if (! $guestInSpanish || preg_match(self::ENGLISH_STRAY, $this->plain($text)) !== 1) {
            return false;
        }

        $podado = $this->withoutStrayEnglish($text);

        // Lo que queda tiene que ser un mensaje, no un saludo suelto: si la
        // frase mala era casi todo, mejor que lo rehaga el modelo.
        return mb_strlen($podado) >= 30 && ! $this->garbledReply($podado, $guestInSpanish);
    }

    /** Quita las oraciones que traen la palabra en inglés. */
    protected function withoutStrayEnglish(string $text): string
    {
        return collect(preg_split('/\R/u', $text) ?: [])
            ->map(fn (string $line) => collect(preg_split('/(?<=[.!?])\s+/u', trim($line)) ?: [])
                ->reject(fn (string $sentence) => preg_match(self::ENGLISH_STRAY, $this->plain($sentence)) === 1)
                ->implode(' '))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->implode("\n");
    }

    public function garbledReply(string $text, bool $guestInSpanish = true): bool
    {
        // Las ligas no se juzgan: el id de un Google Doc ("…DrbrIsuiAZwD…")
        // parece dos palabras pegadas y "edit?usp=sharing" parece inglés.
        // Caso real Hotel México 2026-10-01 (conv. 2 y 3): la respuesta con
        // el contrato se marcó como basura, la reescritura traía la misma
        // liga y el huésped recibió "repítame su mensaje" con el apartado
        // ya creado.
        $clean = trim((string) preg_replace('~(?:https?://|www\.)\S+~iu', ' ', $text));

        if ($clean === '') {
            return false;
        }

        // 1. Marcador sin llenar: "[Asistente Virtual]", "[nombre del hotel]".
        if (preg_match('/\[[^\]\n]{2,60}\]/u', $clean)) {
            return true;
        }

        $sinMarcas = str_ireplace(self::BRAND_WORDS, '', $clean);

        // 2. Dos palabras pegadas: "díasWould", "gustaWe".
        if (preg_match('/\p{Ll}{3}\p{Lu}\p{Ll}{2}/u', $sinMarcas)) {
            return true;
        }

        $plain = $this->plain($clean);

        // 3. Vocabulario de otro idioma.
        foreach (self::FOREIGN_WORDS as $word) {
            if (str_contains($plain, $this->plain($word))) {
                return true;
            }
        }

        if (! $guestInSpanish) {
            return false;
        }

        // 4. UNA palabra suelta en inglés a media frase en español. El conteo
        // de abajo pide tres o más, así que esto se colaba entero: cabañas
        // 2026-09-23 (Chago 02, conv. 1448) recibió "En breve le attention."
        // Solo palabras que en un hotel mexicano NO se dicen en inglés —
        // "check-in", "room service", "spa" o "Booking" son español de hotel
        // y por eso no están en la lista.
        // 5. Inglés a quien escribe en español. Se cuentan palabras
        // funcionales (las que no se pueden evitar al hablar), no
        // sustantivos: "check-in" o "spa" son español de hotel.
        $ingles = preg_match_all('/\b(the|you|your|would|like|please|we|our|is|are|for|with|have|how|what|when|thank|hello|help|about|there|and|can)\b/iu', $plain);
        $espanol = preg_match_all('/\b(que|para|con|los|las|una|por|del|est[aá]|son|tiene|gusto|puede|le|su|te|hola|gracias|noche|fecha|cabaña|habitaci[oó]n|disponib\w*)\b/iu', $plain);

        // La palabra suelta solo cuenta DENTRO de un mensaje en español: una
        // respuesta entera en inglés no es una fuga, y sin esta condición se
        // marcaba "Sure, the cabin is available on Friday" como basura.
        if ($espanol >= 1 && preg_match(self::ENGLISH_STRAY, $plain) === 1) {
            return true;
        }

        return $ingles >= 3 && $espanol <= 1;
    }

    /** ¿El huésped viene escribiendo en español? */
    protected function guestWritesSpanish(?Conversation $conversation): bool
    {
        if ($conversation === null) {
            return true;
        }

        $said = (string) $conversation->messages()
            ->where('direction', 'in')
            ->latest('id')
            ->limit(3)
            ->pluck('body')
            ->implode(' ');

        if (trim($said) === '') {
            return true;
        }

        $ingles = preg_match_all('/\b(the|you|your|would|like|please|we|our|is|are|hello|hi|thanks|available|room|cabin|night)\b/iu', $said);
        $espanol = preg_match_all('/\b(que|qué|para|con|los|las|una|por|del|hola|gracias|cu[aá]nto|precio|fecha|cabaña|habitaci[oó]n|disponib\w*|buenas|buenos)\b/iu', $said);

        return $espanol >= $ingles;
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
    protected function enforceLanguage(string $text, ?AiProvider $provider, ?Conversation $conversation = null): string
    {
        $otroAlfabeto = $this->needsTranslation($text);
        $enEspanol = $this->guestWritesSpanish($conversation);
        $basura = ! $otroAlfabeto && $this->garbledReply($text, $enEspanol);

        if (! $otroAlfabeto && ! $basura) {
            return $text;
        }

        // Si lo ÚNICO malo es una palabra suelta en inglés, se poda esa
        // oración y listo: rehacer el mensaje entero cuesta una llamada más
        // y, si el proveedor está saturado, termina cambiando una respuesta
        // buena por "tuve un problema, repítame su mensaje" — peor el remedio.
        if (! $otroAlfabeto && $this->strayEnglishOnly($text, $enEspanol)) {
            \Illuminate\Support\Facades\Log::warning('Agente: palabra suelta en inglés, se poda la oración', [
                'conversation_id' => $conversation?->id,
                'text' => mb_substr($text, 0, 300),
            ]);

            return $this->withoutStrayEnglish($text);
        }

        \Illuminate\Support\Facades\Log::warning('Agente: respuesta mal redactada, se rehace', [
            'motivo' => $otroAlfabeto ? 'otro alfabeto' : 'idioma o marcador sin llenar',
            'conversation_id' => $conversation?->id,
            'text' => mb_substr($text, 0, 300),
        ]);

        $instruccion = $otroAlfabeto
            ? 'Traduce al español el siguiente mensaje que un asistente de hotel le escribe a su huésped. Responde SOLO con la traducción, en texto plano, sin comillas ni comentarios.'
            : 'Reescribe este mensaje de un asistente de hotel a su huésped. Déjalo ENTERO en '.($enEspanol ? 'español' : 'inglés')
                .', en texto plano, sin marcadores entre corchetes, sin palabras de otros idiomas y sin comillas ni comentarios. No agregues ni quites información.';

        // El mismo proveedor primero y, si su reescritura vuelve a salir
        // mal, el siguiente de la cadena: la basura es del modelo, así que
        // insistirle al mismo tiene poco caso.
        $candidatos = array_values(array_filter(
            [$provider, ...$this->providers()],
            fn (?AiProvider $p) => $p !== null,
        ));

        foreach (array_slice($candidatos, 0, 2) as $candidato) {
            try {
                $rehecho = trim($this->run($candidato, fn ($request) => $request
                    ->withSystemPrompt($instruccion)
                    ->withPrompt($text))->text);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($rehecho !== '' && ! $this->needsTranslation($rehecho) && ! $this->garbledReply($rehecho, $enEspanol)) {
                return $rehecho;
            }
        }

        return $enEspanol
            ? 'Disculpe, tuve un problema al redactar mi respuesta. ¿Me puede repetir su mensaje, por favor?'
            : 'Sorry, I had trouble writing my reply. Could you send your message again, please?';
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
        $paymentBlock = $this->paymentMethodsBlock();
        $datesBlock = $this->requestedDatesBlock($conversation);
        $reservationsBlock = $this->reservationsBlock($conversation);
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
{$instructionsBlock}{$guidelinesBlock}{$paymentBlock}
REGLAS ESTRICTAS:
- Si la duda del huésped coincide con una pregunta de "faqs", responde con esa respuesta tal cual (puedes adaptarla al tono de la conversación, sin cambiar los datos).
- Si el huésped comparte su teléfono, usa identificar_huesped para reconocerlo; si ya nos visitó, salúdalo por su nombre como cliente frecuente (sin recitar sus datos).
- Usa las herramientas para tarifas, disponibilidad y reservas; NUNCA inventes precios, fechas, políticas ni cantidades de habitaciones.
- INVENTARIO: cada tipo tiene un número FIJO de habitaciones, el campo "units" de room_types. Ese es el tope absoluto: si units es 1, JAMÁS ofrezcas dos ("2 Cabañas Reales" cuando solo existe una es el peor error que puedes cometer). Para ofrecer varias, usa consultar_disponibilidad_general y no pases de "units_available" por tipo.
- NO AFIRMES DISPONIBILIDAD SIN VERIFICARLA: nunca digas que una habitación está libre —ni la ofrezcas como alternativa— sin haberla consultado con consultar_disponibilidad o consultar_disponibilidad_general para ESAS fechas exactas. Si un tipo salió ocupado, consulta el resto con consultar_disponibilidad_general ANTES de nombrar alternativas; si no queda nada libre, dilo tal cual y ofrece las fechas de alternative_dates (ya vienen verificadas, con su etiqueta en español) para no perder al huésped.
- SIN LUGAR NO ES ADIÓS: cuando no haya disponibilidad y el huésped no acepte las fechas alternativas, ofrécele que lo apuntes en la lista de espera para avisarle si se libera, y si acepta llama apuntar_lista_espera con sus fechas originales. Solo si tienes esa herramienta; nunca prometas que se va a liberar ni le guardes lugar.
- GRUPOS: si el grupo no cabe en una sola habitación, llama consultar_disponibilidad_general con las fechas y "personas", y ofrece TAL CUAL lo que devuelva suggested_combination (qué tipos, cuántas de cada uno y el total). Si combination_covers_guests viene en false, dilo con claridad y ofrece otras fechas o usa transferir_a_humano; nunca completes el grupo con habitaciones que no aparecen libres. No le pidas al huésped que él arme la combinación: propónsela tú.
- No inventes política comercial: nunca afirmes descuentos, mínimos de noches, ni que "el precio es fijo todo el año" si no está en los datos del hotel. Si una tarifa trae seasonal en true, el precio cambia por fechas y solo consultar_disponibilidad te da el correcto.
- FECHAS: al repetir la llegada y la salida usa exactamente las que devolvió la herramienta (starts_at/ends_at); no cambies día, mes ni año al redactarlas.
- CALENDARIO — NUNCA DE MEMORIA: jamás calcules tú qué día de la semana cae una fecha, ni una fecha límite a partir de una regla ("una semana antes", "el lunes o martes previos"). Los días y fechas que digas salen ya escritos de una herramienta o del bloque RESERVAS DE QUIEN TE ESCRIBE (arrival_label, departure_label, balance_due_label). Si ese dato no está, NO lo adivines: di que el personal se lo confirma y usa transferir_a_humano. Una fecha inventada en un chat de dinero es peor que decir "lo confirmo con el personal".
- SU RESERVA MANDA SOBRE LA REGLA GENERAL: cuando alguien que YA tiene reserva pregunta por su saldo, su fecha límite, su horario o el estado de su pago, contesta con los datos de SU reserva (bloque RESERVAS DE QUIEN TE ESCRIBE o consultar_reserva), no con la política ni la FAQ general — esas son para quien apenas cotiza. Ante la duda, llama consultar_reserva antes de contestar.
- SI EL HUÉSPED TE CORRIGE UN DATO: no le des la razón para quedar bien ni te inventes una explicación. Verifica con consultar_reserva: si el sistema coincide con él, díselo; si no coincide, dile con amabilidad lo que marca el sistema y que el personal se lo aclara, y usa transferir_a_humano. Nunca confirmes un dato que no viste en una herramienta.
- VERIFICACIÓN DE TRANSFERENCIAS: un comprobante lo revisa el personal a mano. Nunca digas que "se confirma automáticamente" ni prometas un tiempo ("unos minutos") que ninguna herramienta te dio: di que el personal lo está verificando y que el sistema le avisa por este chat en cuanto quede registrado.
- CIERRE: si el huésped se despide ("sería todo", "gracias"), despídete en una o dos líneas cordiales. No mandes listas de recordatorios ni resúmenes que no pidió; si pide un resumen, ármalo SOLO con datos de su reserva en el sistema (payment_summary, arrival_label, balance_due_label), sin cambiar ninguno. Escribe con ortografía correcta y sin palabras en mayúsculas.
- AÑO — REGLA ABSOLUTA: hoy es {$this->today()}. JAMÁS cotices, ofrezcas, consultes ni menciones fechas que ya pasaron ni años anteriores al actual. Si el huésped da día y mes sin año, es la PRÓXIMA vez que llega esa fecha: este año si todavía no pasa, el siguiente si ya pasó — mándala así a las herramientas sin preguntarle el año. Si solo da el NÚMERO del día, sin mes ("el 24", "24 y 25"), NO supongas el mes: pregúntale de qué mes habla antes de consultar disponibilidad o cotizar. NUNCA le pongas a escoger entre dos años ("para 2025 / para 2026" es el peor error de fechas posible: el huésped no puede viajar al pasado). Si una herramienta devuelve "date_notice", la fecha que mandaste estaba en el pasado y se corrigió: obedécela y usa solo la fecha que trae.
- Cada tarifa pertenece a UN tipo de habitación (room_type en consultar_tarifas). Si el huésped pidió un tipo, cotiza y aparta SOLO con tarifas de ese tipo — jamás uses la tarifa de otro tipo.
- El precio de una tarifa es POR UNIDAD (por noche o por bloque); el TOTAL del rango lo calcula consultar_disponibilidad. Nunca presentes el total del rango como si fuera el precio por unidad ("$1,750 por 3 horas" está MAL si es el total de varias unidades). Para estancias con fechas usa tarifas por noche; las tarifas por bloque (ratos/horas) solo si el huésped pide horas.
- AL COTIZAR, NUNCA DES EL PRECIO PELADO: consultar_disponibilidad devuelve "quote_notice" (y el panorama "payment_notice") con los renglones que el hotel exige decir — cuántas personas incluye la tarifa y qué cuesta la persona extra, el anticipo para apartar, hasta cuándo debe quedar liquidada la estancia y el teléfono para dudas o aclaraciones. El anticipo de esos renglones es el que de verdad va a cobrar el sistema: si otra instrucción te dicta una cifra distinta, manda ESTA. Cópialos TAL CUAL debajo del total, TODOS, en cada cotización. No los resumas, no los omitas "por brevedad" y no cambies fechas ni montos: si el plazo de liquidación viene ahí, ese es, y va aunque el huésped no pregunte.
- Antes de crear un apartado repite al huésped: tipo de habitación, nombre de la tarifa, TOTAL exacto, fecha de llegada y nombre completo — y espera su confirmación.
- Al entregar el código de un apartado creado, menciona una sola vez que el día de la llegada se pide una identificación oficial en recepción para el registro.
- PAGOS: si el apartado requiere prepago (requires_prepayment), PRIMERO ofrece al huésped las formas de pago del bloque FORMAS DE PAGO (y payment_options del apartado, que manda si difieren) y pregunta cuál prefiere — SOLO esas, nunca una que no esté ahí. Con su elección llama solicitar_pago (metodo y proveedor) y comparte lo que devuelva tal cual: link de pago (paga ahí y el sistema confirma solo), cuentas para transferencia (pide el comprobante por este chat; el hotel lo verifica), o efectivo (dile hasta cuándo queda apartada su habitación y que paga al llegar). Si solo hay UNA opción, no preguntes: úsala directo. NUNCA digas que un pago fue recibido o verificado: eso solo lo confirma el sistema (consultar_reserva) o el personal. Si el huésped insiste en que ya pagó y el sistema no lo refleja, usa transferir_a_humano.
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
- CANCELAR O REAGENDAR: tú no cancelas ni mueves fechas. Si el huésped lo pide, dile la regla que aplica (si su reserva entra en short_notice de las políticas, la de short_notice.cancellation; si no, cancellation_policy) y usa transferir_a_humano en ese mismo turno para que el personal lo resuelva. Nunca le prometas un reembolso.
- Si el huésped pide hablar con una persona, se queja, o pide algo fuera de tu alcance, usa la herramienta transferir_a_humano. TRANSFERIR ES UNA ACCIÓN, NO UN ANUNCIO: llámala en ESE mismo turno y nunca prometas una llamada — el hotel contesta por este chat.
- PERSONAL DEL HOTEL: un turno que empieza con "[PERSONAL DEL HOTEL]" lo escribió una persona del hotel y ya se lo dijo al huésped. Es palabra dada: no la contradigas, no vuelvas a cotizar la fecha ni el precio que esa línea ya cerró, y no repitas la pregunta que ahí ya se respondió. Si una herramienta te dice lo contrario que el personal, NO corrijas al personal: usa transferir_a_humano.
- Hoy es {$this->today()}. Fechas en formato YYYY-MM-DD HH:MM.
- FORMATO: tus mensajes se muestran como TEXTO PLANO (WhatsApp, Telegram, webchat) — JAMÁS uses tablas, negritas con asteriscos, títulos con #, ni ningún markdown: el huésped vería los símbolos literales. Para listar opciones usa un renglón corto por opción con guion, ej.: "- Habitación Sencilla: $1,300".
- IDIOMA — REGLA ABSOLUTA: SOLO español o inglés. Contesta en inglés únicamente si el huésped te escribe en inglés; en cualquier otro caso —aunque escriba en ruso, portugués, francés, chino o cualquier otro idioma— contesta en español. JAMÁS escribas en otro idioma ni uses otro alfabeto (cirílico, chino, árabe...), ni una sola palabra, y nunca mezcles idiomas a media frase.
- Nunca menciones duraciones en horas, horarios de entrada/salida ni vigencias que las herramientas o estos datos no indiquen explícitamente.
- Sé breve, cálido y profesional; máximo 2-3 oraciones por respuesta salvo que listes opciones. No uses emojis.
- No saludes de nuevo si la conversación ya empezó: continúa el hilo donde va.
{$guestBlock}{$hoursBlock}{$summaryBlock}{$couponBlock}{$nowBlock}{$reservationsBlock}{$datesBlock}
PROMPT;
    }

    /**
     * Las formas de pago REALES del hotel, dictadas desde el primer mensaje.
     *
     * El prompt enumeraba "pasarelas, transferencia, efectivo al llegar" como
     * ejemplo genérico, y el modelo las repetía tal cual ANTES de que ninguna
     * herramienta le dijera cuáles existen. En cabañas el efectivo está
     * APAGADO (`cash_payment_enabled` = false) y aun así se ofreció a 45
     * huéspedes entre el 10 y el 22 de septiembre: "pagas al llegar" hace
     * creer que la cabaña queda apartada sin pagar, y no queda.
     *
     * Público para poder verlo en el "ojito" del prompt sin armarlo entero.
     */
    public function paymentMethodsBlock(): string
    {
        try {
            $options = $this->tools->paymentOptions();
        } catch (Throwable) {
            return ''; // sin datos, mejor callar que dictar algo falso
        }

        $metodos = collect($options['pasarelas'] ?? [])
            ->map(fn (array $gateway) => 'link de pago ('.$gateway['label'].')')
            ->when($options['transferencia'] ?? false, fn ($lista) => $lista->push('transferencia bancaria'))
            ->when($options['efectivo'] ?? false, fn ($lista) => $lista->push('efectivo al llegar'))
            ->values();

        if ($metodos->isEmpty()) {
            return '';
        }

        $block = "\nFORMAS DE PAGO DEL HOTEL (las ÚNICAS que puedes ofrecer): ".$metodos->implode(', ').".\n";

        if (! ($options['efectivo'] ?? false)) {
            $block .= "Este hotel NO acepta pagar en efectivo al llegar: el efectivo NO aparta la habitación. JAMÁS ofrezcas pagar al llegar, a la llegada, en el check-in, en recepción ni en las instalaciones como forma de apartar. Si el huésped lo pide, dile que el apartado se hace con las formas de arriba.\n";
        }

        return $block;
    }

    /**
     * Candado de salida del mismo asunto: lo que el prompt prohíbe, aquí se
     * comprueba. Si el hotel no acepta efectivo al llegar y el texto lo
     * ofrece, se quita esa opción de la lista; si la oración era SOLO eso, se
     * cae y se dicen las formas reales. Caso real cabañas 2026-09-22 (Uziel,
     * conv. 1357): "cómo prefieres pagar el anticipo: transferencia, Mercado
     * Pago o efectivo al llegar".
     */
    protected function enforceCashClaims(string $text, ?Conversation $conversation): string
    {
        if ($conversation === null || trim($text) === '') {
            return $text;
        }

        try {
            $options = $this->tools->paymentOptions();
        } catch (Throwable) {
            return $text;
        }

        if ($options['efectivo'] ?? false) {
            return $text; // el hotel sí lo acepta: no hay nada que corregir
        }

        // "efectivo" a secas no basta: el hotel tiene escrito que se puede
        // pagar EN LAS INSTALACIONES con cita, y eso no es pagar al llegar.
        // Lo que se persigue es la promesa de pagar en el momento de llegar.
        $ofreceEfectivo = '/(efectivo|en efectivo)[^.!?\n]{0,40}(al llegar|a (?:tu|su) llegada|cuando llegue|cuando llegues|al momento de llegar|en el check|al registrarse)'
            .'|(al llegar|a (?:tu|su) llegada|cuando llegue|cuando llegues|en recepci[óo]n|en el hotel)[^.!?\n]{0,40}(en efectivo|efectivo)'
            .'|pagar? (?:el anticipo )?(?:en )?efectivo/iu';

        if (preg_match($ofreceEfectivo, $text) !== 1) {
            return $text;
        }

        $reales = collect($options['pasarelas'] ?? [])
            ->map(fn (array $gateway) => 'link de pago ('.$gateway['label'].')')
            ->when($options['transferencia'] ?? false, fn ($lista) => $lista->push('transferencia bancaria'))
            ->values();

        $otroMetodo = '/transferenc|link de pago|mercado ?pago|stripe|paypal|sitio web|en l[íi]nea|tarjeta/iu';
        $caida = false;

        $kept = collect(preg_split('/\R/u', $text) ?: [])
            ->map(function (string $line) use ($ofreceEfectivo, $otroMetodo, &$caida) {
                return collect(preg_split('/(?<=[.!?])\s+/u', trim($line)) ?: [])
                    ->map(function (string $sentence) use ($ofreceEfectivo, $otroMetodo, &$caida) {
                        if (preg_match($ofreceEfectivo, $sentence) !== 1) {
                            return $sentence;
                        }

                        // Decir que NO se acepta es justo lo que queremos que
                        // diga: "no se aparta pagando en efectivo" explica la
                        // regla, no la ofrece. Sin esta salvedad el candado se
                        // comía la explicación y el huésped se quedaba sin
                        // saber por qué.
                        if (preg_match('/\bno\b|jam[áa]s|nunca|tampoco|sin pagar/iu', $sentence) === 1) {
                            return $sentence;
                        }

                        // Se intenta salvar la oración quitando SOLO la
                        // opción del efectivo de la enumeración.
                        $limpia = (string) preg_replace(
                            ['/[,;]?\s*(?:o\s+|u\s+|y\s+)?(?:pagar\s+)?(?:en\s+)?efectivo(?:\s+(?:al llegar|a (?:tu|su) llegada|cuando llegues?|al momento de llegar|en recepci[óo]n|en el hotel))?/iu'],
                            '',
                            $sentence,
                        );
                        $limpia = trim((string) preg_replace('/\s{2,}/u', ' ', $limpia));
                        $limpia = (string) preg_replace('/\s+([,.:;!?])/u', '$1', $limpia);
                        $limpia = (string) preg_replace('/[,:]\s*([.!?])/u', '$1', $limpia);

                        if (preg_match($otroMetodo, $limpia) === 1) {
                            return $limpia;
                        }

                        $caida = true;

                        return '';
                    })
                    ->filter(fn (string $sentence) => trim($sentence) !== '')
                    ->implode(' ');
            })
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->implode("\n");

        \Illuminate\Support\Facades\Log::warning('Agente: ofreció efectivo al llegar y el hotel no lo acepta', [
            'conversation_id' => $conversation->id,
            'texto' => mb_substr($text, 0, 300),
        ]);

        if ($caida && $reales->isNotEmpty()) {
            $kept = trim($kept."\n".'Para apartar se paga por '.$reales->implode(' o ').'.');
        }

        return trim($kept);
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

        // Caso real Hotel México 2026-09-30: a las 4 PM el bot desanimó una
        // reserva para ese mismo día diciendo que "el personal que genera los
        // links atiende hasta las 5 PM". La liga la genera el sistema.
        $scope = '- Este horario es SOLO para que conteste una persona. NO limita cotizar, apartar, reservar para hoy ni pagar: la liga de pago la genera el sistema al momento, a cualquier hora. Nunca digas que el personal genera las ligas ni que el horario impide reservar.';

        if ($hours->isOpen()) {
            return "\nHORARIO DE ATENCIÓN: el personal del hotel atiende {$hours->label()}; ahora mismo SÍ hay quien conteste.\n{$scope}\n";
        }

        $next = $hours->nextOpeningLabel();

        return <<<BLOCK

HORARIO DE ATENCIÓN: el personal atiende {$hours->label()} y AHORA MISMO ESTÁ FUERA DE HORARIO.
{$scope}
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
    protected function datesMentioned(string $text, bool $loose = false): array
    {
        $months = $this->monthNames();
        $plain = $this->plain((string) preg_replace('/\s+/u', ' ', $text));
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

        // Modo suelto: el huésped que ELIGE de una lista no repite el mes.
        // "El domingo 27" es una fecha exacta —el próximo 27 que caiga en
        // domingo— y sin esto el sistema no veía ninguna fecha en su
        // mensaje. Caso real cabañas 2026-09-16 (conv. 937): eligió "el
        // domingo 27" de las alternativas que el propio bot le ofreció y el
        // bot le contestó del sábado 26, la fecha vieja, y volvió a
        // ofrecerle la lista con el domingo 27 dentro.
        if ($loose) {
            $weekdays = 'domingo|lunes|martes|miercoles|jueves|viernes|sabado';

            // "(el) domingo 27", nunca "domingo 27 de septiembre" (ese ya lo
            // resolvió el paso de arriba, con su mes).
            if (preg_match_all('/\b('.$weekdays.')\s+(?:el\s+)?(\d{1,2})\b(?!\s*(?:de\s*)?(?:'.$names.'))/u', $plain, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $date = $this->nextDateWith((int) $match[2], $match[1]);

                    if ($date !== null) {
                        $found[$date->toDateString()] = $date;
                    }
                }
            }

            // "el sábado", SIN número. Sin esto el servidor no veía ninguna
            // fecha en el mensaje, así que ni el prompt le decía cuál era ni
            // el guardián podía comparar nada: el modelo la elegía por su
            // cuenta. Caso real cabañas 2026-09-16 (conv. 917, RES-2026-1758):
            // el huésped pidió "para el sabado" y el bot le vendió el VIERNES
            // 18 llamándolo "sábado 18 de septiembre"; pagó $1,500 y el error
            // se descubrió el día antes de llegar, con el sábado lleno.
            // Solo si nombra UN día: "entro el sábado y salgo el domingo" es
            // un itinerario que se está repitiendo, no una fecha que se pide,
            // y resolverlo al sábado de ESTA semana pisaría la fecha que la
            // conversación ya tenía.
            if (preg_match_all('/\b(?:el|este|esta|proximo|proxima)\s+('.$weekdays.')\b(?!\s*\d)/u', $plain, $matches, PREG_SET_ORDER)) {
                $named = array_unique(array_column($matches, 1));

                if (count($named) === 1) {
                    $date = $this->nextWeekday(reset($named));
                    $found[$date->toDateString()] = $date;
                }
            }

            // "para el 27", "el día 27". Se descartan los números que son
            // otra cosa ("el 27 personas" no existe, pero "el 2 noches" sí).
            if (preg_match_all('/\b(?:el|del|dia)\s+(\d{1,2})\b(?!\s*(?:de\s*)?(?:'.$names.')|\s*(?:personas?|pax|adultos?|ni[nñ]os?|noches?|dias?|anos?|grados?|pesos?|%))/u', $plain, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $date = $this->nextDateWith((int) $match[1]);

                    if ($date !== null) {
                        $found[$date->toDateString()] = $date;
                    }
                }
            }

            // "24 y 25", "del 24 al 26", "24-25": dos días sin mes. Es la
            // próxima vez que llega el primero, y el segundo cae en ese mismo
            // mes. Caso real cabañas 2026-09-29 (Messenger, conv. 1631):
            // "¿tiene disponible 24 y 25?" no resolvía a nada, el modelo puso
            // septiembre —ya pasado— y el huésped recibió septiembre de 2027.
            // Solo rangos cortos y crecientes: "somos 4 y 2 niños" no es fecha.
            if (preg_match_all('/\b(\d{1,2})\s*(?:y|al|a|-)\s*(?:el\s+)?(\d{1,2})\b(?!\s*(?:de\s*)?(?:'.$names.')|\s*(?:personas?|pax|adultos?|ni[nñ]os?|menores|noches?|dias?|anos?|horas?|grados?|pesos?|%|:))/u', $plain, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    [$first, $second] = [(int) $match[1], (int) $match[2]];

                    if ($second <= $first || $second - $first > 14) {
                        continue;
                    }

                    $date = $this->nextDateWith($first);

                    if ($date !== null && checkdate($date->month, $second, $date->year)) {
                        $found[$date->toDateString()] = $date;
                        $found[$date->day($second)->toDateString()] = $date->day($second);
                    }
                }
            }
        }

        return array_slice($found, 0, 5, true);
    }

    /**
     * @return array<string, int>
     */
    protected function monthNames(): array
    {
        return [
            'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
            'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
            'noviembre' => 11, 'diciembre' => 12,
        ];
    }

    /** Minúsculas y sin acentos: así se comparan nombres de días y meses. */
    protected function plain(string $text): string
    {
        return strtr(mb_strtolower($text), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);
    }

    /**
     * ¿La fecha la dio como día de la semana, sin número ni mes? Entonces la
     * dedujo el servidor y hay que confirmársela antes de cobrarle.
     */
    protected function saidOnlyWeekday(string $text): bool
    {
        if (trim($text) === '' || $this->datesMentioned($text) !== []) {
            return false;
        }

        return preg_match(
            '/\b(?:el|este|esta|proximo|proxima)\s+(?:domingo|lunes|martes|miercoles|jueves|viernes|sabado)\b(?!\s*\d)/u',
            $this->plain($text),
        ) === 1;
    }

    /**
     * El apartado NO puede caer en una fecha distinta a la que el huésped
     * acaba de pedir. Devuelve el reclamo para el modelo, o null si cuadra.
     *
     * Solo mira su ÚLTIMO mensaje: si ahí no nombró fecha, no hay contra qué
     * comparar y no se estorba (el huésped que acepta una alternativa con un
     * "ok, ese" no puede quedarse sin apartado).
     */
    protected function holdDateMismatch(?Conversation $conversation, string $startsAt): ?string
    {
        if ($conversation === null) {
            return null;
        }

        $requested = $this->requestedDates($conversation);

        if ($requested === []) {
            return null;
        }

        try {
            $day = \Carbon\CarbonImmutable::parse($startsAt)->toDateString();
        } catch (\Throwable) {
            return null;
        }

        if (array_key_exists($day, $requested)) {
            return null;
        }

        $pedida = reset($requested);

        \Illuminate\Support\Facades\Log::warning('Agente: iba a apartar otra fecha', [
            'conversation_id' => $conversation->id,
            'pedida' => $pedida->toDateString(),
            'intento' => $day,
        ]);

        return json_encode([
            'ok' => false,
            'error' => 'La fecha del apartado no es la que pidió el huésped.',
            'fecha_pedida' => $pedida->toDateString(),
            'fecha_pedida_texto' => $pedida->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY'),
            'que_hacer' => 'Consulta la disponibilidad de la fecha pedida y apártala con ESA fecha. Si el huésped quiere otra, confírmasela con día y número antes de apartar.',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * El próximo día de la semana con ese nombre. Hoy cuenta: quien dice "el
     * sábado" un sábado habla de hoy, no del de la semana que entra.
     */
    protected function nextWeekday(string $weekday): \Carbon\CarbonImmutable
    {
        $index = (int) array_search($weekday, ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'], true);
        $today = \Carbon\CarbonImmutable::now()->startOfDay();

        return $today->addDays(($index - $today->dayOfWeek + 7) % 7);
    }

    /**
     * El próximo día N (opcionalmente, el próximo N que caiga en ese día de
     * la semana). Nadie reserva para ayer: siempre hacia adelante.
     */
    protected function nextDateWith(int $day, ?string $weekday = null): ?\Carbon\CarbonImmutable
    {
        if ($day < 1 || $day > 31) {
            return null;
        }

        $indexes = ['domingo' => 0, 'lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6];
        $target = $weekday !== null ? ($indexes[$this->plain($weekday)] ?? null) : null;

        if ($weekday !== null && $target === null) {
            return null;
        }

        $today = \Carbon\CarbonImmutable::now()->startOfDay();

        // Dos años de margen: el mismo número en el mismo día de la semana
        // se repite cada 5 o 6 meses en el peor caso.
        for ($i = 0; $i < 24; $i++) {
            $cursor = $today->addMonths($i);

            if (! checkdate($cursor->month, $day, $cursor->year)) {
                continue;
            }

            $date = \Carbon\CarbonImmutable::create($cursor->year, $cursor->month, $day)->startOfDay();

            if ($date->lt($today)) {
                continue;
            }

            if ($target === null || (int) $date->dayOfWeek === $target) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Las reservas vivas de quien está escribiendo, sin que tenga que
     * teclear su código.
     *
     * En WhatsApp el número del chat ES su identidad (lo verificó el canal),
     * así que basta para reconocerlo. Casos reales cabañas del 13 al 15 de
     * septiembre: el huésped daba su teléfono y el bot contestaba "ya te
     * encontré, pero necesito tu código" — y minutos después el propio
     * sistema le mandaba "tu apartado RES-2026-1748 vence...", o sea que el
     * dato estaba ahí.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Reservation>
     */
    protected function guestReservations(?Conversation $conversation): \Illuminate\Support\Collection
    {
        if ($conversation === null) {
            return collect();
        }

        $guestIds = array_filter([$conversation->guest_id]);

        if ($conversation->phoneIsIdentity()) {
            $byPhone = \App\Models\Guest::findByContact($conversation->contact_phone);

            if ($byPhone !== null) {
                $guestIds[] = $byPhone->id;
            }
        }

        $linked = array_filter([$conversation->reservation_id]);

        if ($guestIds === [] && $linked === []) {
            return collect();
        }

        return \App\Models\Reservation::query()
            ->with(['roomType:id,name', 'group'])
            ->where(function ($query) use ($guestIds, $linked) {
                if ($linked !== []) {
                    $query->orWhereIn('id', $linked);
                }

                if ($guestIds !== []) {
                    // Vivas: lo que todavía puede pasar. Una estancia
                    // terminada hace meses solo sería ruido en el prompt.
                    $query->orWhere(fn ($active) => $active
                        ->whereIn('guest_id', $guestIds)
                        ->whereIn('status', [
                            \App\Enums\ReservationStatus::Pending,
                            \App\Enums\ReservationStatus::Confirmed,
                            \App\Enums\ReservationStatus::CheckedIn,
                        ])
                        ->where('ends_at', '>=', now()->subDay()));
                }
            })
            ->orderBy('starts_at')
            ->limit(3)
            ->get();
    }

    /**
     * Las reservas de quien escribe, en el prompt. Sin esto el bot pedía un
     * código que el sistema ya tenía.
     */
    protected function reservationsBlock(?Conversation $conversation): string
    {
        $reservations = $this->guestReservations($conversation);

        if ($reservations->isEmpty()) {
            return '';
        }

        $lines = $reservations
            ->map(function (\App\Models\Reservation $reservation): string {
                // Días de la semana ya escritos y el dinero en una frase: con
                // "llegada 2026-10-17" y "pago deposit_paid" el bot hacía el
                // calendario de cabeza y le dijo a un huésped con el anticipo
                // confirmado que seguía "pendiente de confirmar" (RES-2026-1792).
                $when = fn ($date) => $date?->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY').' a las '.$date?->format('g:i A');

                return '- '.$reservation->displayCode()
                    .($reservation->group?->code ? ' (parte del grupo '.$reservation->group->code.')' : '')
                    .': '.($reservation->roomType?->name ?? 'habitación')
                    .'. Llegada: '.$when($reservation->starts_at)
                    .'. Salida: '.$when($reservation->ends_at)
                    .'. Estado: '.$reservation->status->label()
                    .'. Pago: '.app(\App\Services\ReservationPolicy::class)->paymentSummary($reservation);
            })
            ->implode("\n");

        return "\nRESERVAS DE QUIEN TE ESCRIBE (el sistema las encontró por su número de este chat; son SUYAS):\n{$lines}\nSi pregunta por \"su reserva\" o \"su apartado\", es una de estas: NO le pidas el código, ya lo tienes. Estas fechas, días de la semana y montos son los del sistema: cítalos tal cual, no los recalcules. Para cualquier otro detalle llama consultar_reserva con ese código.\n";
    }

    /**
     * La fecha que el huésped pidió EN SU ÚLTIMO MENSAJE. Solo el último:
     * cuando cambia de fecha, la anterior deja de importar, y ese cambio es
     * justo donde el bot se pierde.
     *
     * @return array<string, \Carbon\CarbonImmutable>
     */
    protected function requestedDates(Conversation $conversation): array
    {
        $last = $conversation->messages()
            ->where('direction', 'in')
            ->latest('id')
            ->first();

        $said = trim((string) $last?->body);

        // Los adjuntos llegan como "[adjuntó un comprobante: ...]": ahí no
        // hay fecha pedida, hay un archivo.
        if ($said === '' || str_starts_with($said, '[')) {
            return [];
        }

        return $this->datesMentioned($said, loose: true);
    }

    /**
     * Los días que el huésped dio en su ÚLTIMO mensaje sin decir el mes
     * ("24 y 25", "el 24"). Pedido del dueño (2026-09-29, tras cotizarle a
     * un huésped septiembre de 2027 por un "24 y 25"): con solo el número,
     * el bot pregunta el mes antes de consultar o cotizar.
     *
     * No cuenta si el bot le acababa de ofrecer ese día con su mes ("el
     * domingo 27" elegido de una lista con "domingo 27 de septiembre"): ahí
     * el mes ya está dicho.
     *
     * @return array<int, int>
     */
    protected function dayWithoutMonth(?Conversation $conversation): array
    {
        if ($conversation === null) {
            return [];
        }

        $last = $conversation->messages()->where('direction', 'in')->latest('id')->first();
        $said = trim((string) $last?->body);

        if ($said === '' || str_starts_with($said, '[')) {
            return [];
        }

        $plain = $this->plain($said);

        if (preg_match('/\b(?:'.implode('|', array_keys($this->monthNames())).')\b/u', $plain)
            || $this->datesMentioned($said) !== []) {
            return [];
        }

        $days = collect($this->datesMentioned($said, loose: true))
            ->map(fn (\Carbon\CarbonImmutable $date) => $date->day)
            ->filter(fn (int $day) => preg_match('/\b'.$day.'\b/', $plain) === 1)
            ->unique()
            ->values();

        if ($days->isEmpty()) {
            return [];
        }

        $offered = $conversation->messages()
            ->where('direction', 'out')
            ->where('id', '<', $last->id)
            ->latest('id')
            ->value('body');

        $offeredDays = collect($this->datesMentioned((string) $offered))
            ->map(fn (\Carbon\CarbonImmutable $date) => $date->day);

        if ($days->every(fn (int $day) => $offeredDays->contains($day))) {
            return [];
        }

        return $days->sort()->values()->all();
    }

    /**
     * La fecha pedida, ya resuelta por el servidor, al final del prompt.
     * Prevención antes que corrección: si el modelo la tiene escrita con
     * todas sus letras, no tiene que deducirla del hilo.
     */
    protected function requestedDatesBlock(?Conversation $conversation): string
    {
        if ($conversation === null) {
            return '';
        }

        // Solo el número del día: el mes lo dice el huésped, no el bot.
        $days = $this->dayWithoutMonth($conversation);

        if ($days !== []) {
            $numeros = implode(' y ', $days);

            return "\nFECHA SIN MES: el huésped escribió solo el número del día ({$numeros}), sin el mes. NO supongas el mes, NO consultes disponibilidad, NO cotices y NO apartes: primero pregúntale de qué mes habla (por ejemplo: \"¿El {$numeros} de qué mes?\") y espera su respuesta.\n";
        }

        $dates = $this->requestedDates($conversation);

        if ($dates === []) {
            return '';
        }

        $list = implode('; ', array_map(
            fn (\Carbon\CarbonImmutable $date) => $date->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY').' ('.$date->toDateString().')',
            $dates,
        ));

        $bloque = "\nFECHA QUE PIDIÓ EL HUÉSPED EN SU ÚLTIMO MENSAJE: {$list}. Contesta sobre ESA fecha y consulta la disponibilidad con ESA fecha. Si antes se habló de otra, ya no aplica: el huésped acaba de elegir esta. Y si esta fecha la sacaste de una lista de alternativas que tú le ofreciste, NO se la vuelvas a ofrecer como alternativa: es la que eligió.\n";

        // Dijo "el sábado" y ya: la fecha la dedujo el servidor, no él. Antes
        // de cotizar o apartar tiene que oírla completa y decir que sí — un
        // huésped pagó $1,500 por un viernes creyendo que era sábado
        // (cabañas, RES-2026-1758, 16-sep-2026).
        if ($this->saidOnlyWeekday((string) $conversation->messages()->where('direction', 'in')->latest('id')->value('body'))) {
            $bloque .= "OJO: el huésped solo dijo el DÍA DE LA SEMANA, no la fecha. Antes de cotizar o apartar, dile la fecha completa tal como está arriba y pídele que la confirme. No apartes hasta que él confirme esa fecha.\n";
        }

        return $bloque;
    }

    /**
     * ¿El veredicto de la respuesta es sobre la fecha que pidió el huésped?
     *
     * Solo se revisan las oraciones que dictan disponibilidad ("no hay",
     * "sí está disponible"): el resto puede nombrar otras fechas con toda
     * razón —la salida del día siguiente, alternativas verificadas— y
     * marcarlas sería romper respuestas correctas.
     *
     * @param  array<string, \Carbon\CarbonImmutable>  $requested
     */
    protected function answersRequestedDates(string $text, array $requested): bool
    {
        if ($requested === [] || trim($text) === '') {
            return true;
        }

        // Cómo dicta el bot de verdad, sacado del corpus: "lamento
        // informarle que...", "tampoco hay disponibilidad", "confirmo la
        // disponibilidad". Sin "lamento"/"tampoco" se escapaba justo la
        // frase que perdió al huésped de la conversación 937.
        $verdict = '/(lamento|lamentablemente|tampoco\s+(?:hay|queda|est[áa])|no\s+hay\s+(?:disponibilidad|lugar|cupo)|no\s+queda|no\s+est[áa]\s+disponible|no\s+tenemos\s+disponib|sin\s+disponibilidad|s[íi]\s+hay\s+disponibilidad|s[íi]\s+est[áa]\s+disponible|confirmo\s+(?:la\s+)?disponibilidad|est[áa]\s+disponible|tenemos\s+disponible)/iu';

        foreach (preg_split('/(?<=[.!?\n])/u', $text) ?: [] as $sentence) {
            if (trim($sentence) === '' || ! preg_match($verdict, $sentence)) {
                continue;
            }

            $dates = $this->datesMentioned($sentence, loose: true);

            if ($dates !== [] && array_intersect_key($dates, $requested) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * El huésped eligió una fecha y la respuesta dictamina sobre otra.
     *
     * Caso real cabañas 2026-09-16 (conv. 937): tras ofrecerle "Domingo 27
     * de septiembre" como alternativa, el huésped escribió "El domingo 27" y
     * el bot contestó "el sábado 26 tampoco hay disponibilidad" y le repitió
     * la lista con el domingo 27 dentro. El huésped ya no volvió a escribir.
     *
     * Aquí no se puede corregir el texto —el servidor no sabe qué contestar
     * por él—, así que se vuelve a generar UNA vez con la fecha dictada. Si
     * a la segunda sigue hablando de otra fecha, contesta una persona: decir
     * la fecha equivocada es peor que tardarse.
     *
     * @param  array<int, string>  $used
     * @param  array<string, mixed>  $meta
     */
    protected function reanswerOffTargetDate(
        Conversation $conversation,
        string $text,
        AiProvider $provider,
        array $used,
        array &$meta,
        string &$handoffReason,
    ): string {
        $requested = $this->requestedDates($conversation);

        if ($requested === [] || $this->answersRequestedDates($text, $requested)) {
            return $text;
        }

        // Si esta corrida ya apartó o cobró, no se repite: volvería a
        // hacerlo. La respuesta sale como está y queda en la bitácora.
        if (array_intersect($used, ['hold', 'group_hold', 'payment', 'reopen_hold']) !== []) {
            \Illuminate\Support\Facades\Log::warning('Agente: respuesta sobre otra fecha, no se reintenta (ya escribió)', [
                'conversation_id' => $conversation->id,
                'pedida' => array_keys($requested),
            ]);

            return $text;
        }

        $list = implode(' y ', array_map(
            fn (\Carbon\CarbonImmutable $date) => $date->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY'),
            $requested,
        ));

        \Illuminate\Support\Facades\Log::warning('Agente: respuesta sobre otra fecha, se regenera', [
            'conversation_id' => $conversation->id,
            'pedida' => array_keys($requested),
            'descartada' => mb_substr($text, 0, 160),
        ]);

        try {
            $handoff = false;
            $usedAgain = [];
            $reason = '';

            $aviso = "CORRECCIÓN: tu respuesta anterior dictaminó sobre una fecha que el huésped NO pidió. Él pidió {$list}. Vuelve a contestar SOLO sobre esa fecha, consultándola con las herramientas si hace falta. No dictamines sobre ninguna otra fecha ni se la ofrezcas como alternativa.";

            $response = $this->run($provider, fn ($request) => $request
                ->withSystemPrompt($this->systemPrompt($conversation)."\n\n".$aviso)
                ->withMessages($this->history($conversation))
                // Solo lectura: el segundo intento consulta, nunca aparta.
                ->withTools($this->toolset($handoff, $conversation, true, $usedAgain, $reason))
                ->withMaxSteps(6));

            $second = trim($response->text);
        } catch (Throwable $e) {
            report($e);

            return $text;
        }

        $meta['date_retry'] = true;

        if ($second !== '' && $this->answersRequestedDates($second, $requested)) {
            return $second;
        }

        $meta['date_handoff'] = true;
        $handoffReason = 'El asistente contestó dos veces sobre una fecha distinta a la que pidió el huésped ('.$list.').';

        return '';
    }

    /**
     * Días de la semana que no cuadran con la fecha. Casos reales cabañas:
     * "sábado 18 de septiembre" (era viernes) dos veces —en una la venta se
     * perdió entre "disponible / no disponible / hubo un error"—, "mañana
     * martes 22" dicho un lunes 14 y "lunes 21 de octubre". Contar días no
     * es trabajo del modelo: el nombre lo pone el servidor.
     *
     * Si el día de la semana cuadra con el año pasado, este o el siguiente,
     * se deja tal cual: hablar de una estancia pasada es legítimo.
     */
    public function sanitizeWeekdays(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $names = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $plainNames = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];
        $months = $this->monthNames();
        $monthNames = implode('|', array_keys($months));

        $fixed = preg_replace_callback(
            '/\b(domingos?|lunes|martes|mi[eé]rcoles|jueves|viernes|s[áa]bados?)(\s+(?:el\s+)?)(\d{1,2})(\s*(?:de\s*)?)('.$monthNames.')((?:\s+(?:de|del)\s+(\d{4}))?)/iu',
            function (array $m) use ($names, $plainNames, $months) {
                // "domingos"/"sábados" son los únicos con plural; los
                // demás ya terminan en s (lunes, martes, miércoles...).
                $said = $this->plain($m[1]);
                $said = in_array($said, ['domingos', 'sabados'], true) ? rtrim($said, 's') : $said;
                $index = array_search($said, $plainNames, true);
                $month = $months[$this->plain($m[5])] ?? null;
                $day = (int) $m[3];

                if ($index === false || $month === null || $day < 1 || $day > 31) {
                    return $m[0];
                }

                $today = \Carbon\CarbonImmutable::now()->startOfDay();
                $written = isset($m[7]) && $m[7] !== '' ? (int) $m[7] : null;

                // Con año escrito no hay nada que adivinar.
                if ($written !== null) {
                    if (! checkdate($month, $day, $written)) {
                        return $m[0];
                    }

                    $date = \Carbon\CarbonImmutable::create($written, $month, $day)->startOfDay();

                    if ((int) $date->dayOfWeek === $index) {
                        return $m[0];
                    }
                } else {
                    if (! checkdate($month, $day, $today->year)) {
                        return $m[0];
                    }

                    $date = \Carbon\CarbonImmutable::create($today->year, $month, $day)->startOfDay();

                    // Fecha ya pasada: puede ser una estancia anterior, así
                    // que si el día cuadra con este año o con el pasado se
                    // respeta. Hacia adelante no hay excusa: la fecha es la
                    // próxima vez que llega ese día.
                    if ($date->lt($today)) {
                        foreach ([$today->year, $today->year - 1] as $year) {
                            if (checkdate($month, $day, $year) && (int) \Carbon\CarbonImmutable::create($year, $month, $day)->dayOfWeek === $index) {
                                return $m[0];
                            }
                        }

                        if (checkdate($month, $day, $today->year + 1)) {
                            $date = \Carbon\CarbonImmutable::create($today->year + 1, $month, $day)->startOfDay();
                        }
                    } elseif ((int) $date->dayOfWeek === $index) {
                        return $m[0];
                    }
                }

                $correct = $names[(int) $date->dayOfWeek];

                // Se respeta la mayúscula con la que venía escrito.
                if (mb_substr($m[1], 0, 1) === mb_strtoupper(mb_substr($m[1], 0, 1))) {
                    $correct = mb_strtoupper(mb_substr($correct, 0, 1)).mb_substr($correct, 1);
                }

                return $correct.$m[2].$m[3].$m[4].$m[5].($m[6] ?? '');
            },
            $text,
        );

        return $fixed ?? $text;
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

        $malas = [];

        foreach ($this->datesMentioned($text) as $date) {
            $reason = $coupon->rejectionReason($conversation->guest, $date, 1, null);

            if ($reason !== null) {
                $malas[] = ['fecha' => $date, 'motivo' => $reason];
            }
        }

        if ($malas === []) {
            return $text;
        }

        \Illuminate\Support\Facades\Log::warning('Agente: prometió un cupón que no aplica', [
            'coupon' => $coupon->code,
            'fecha' => $malas[0]['fecha']->toDateString(),
            'motivo' => $malas[0]['motivo'],
        ]);

        // La verdad va PRIMERO y la promesa se borra. Antes se agregaba una
        // "aclaración" al final: el huésped leía "sí tienes 30% de descuento"
        // y debajo "no aplica", en el mismo mensaje. En cabañas pasó 43 veces
        // en dos días con PACHEPACHE (2026-09-15/16).
        $verdad = collect($malas)
            ->map(fn (array $mala) => "El cupón {$coupon->code} no aplica para el "
                .$mala['fecha']->format('d/m/Y').'. '.$mala['motivo'])
            ->unique()
            ->implode(' ');

        // Por renglones: juntar todo con espacios dejaba la lista de precios
        // amontonada en un párrafo (mismo defecto que el guardián de
        // disponibilidad, 17-sep-2026).
        $kept = collect(preg_split('/\R/u', $text) ?: [])
            ->map(fn (string $line) => collect(preg_split('/(?<=[.!?])\s+/u', trim($line)) ?: [])
                ->reject(fn (string $frase) => $this->promisesDiscount($frase))
                ->map(fn (string $frase) => trim($frase))
                ->filter()
                ->implode(' '))
            ->filter(fn (string $line) => trim($line) !== '')
            ->implode("\n");

        return trim($verdad.' Puedes apartar sin el descuento, o elegir una fecha en la que sí aplique.'
            .($kept !== '' ? "\n\n".$kept : ''));
    }

    /**
     * ¿Esta frase promete el descuento? (no la que ya dice que no aplica)
     */
    protected function promisesDiscount(string $frase): bool
    {
        if (preg_match('/\bno\s+(aplica|es v[áa]lido|cuenta)|no puedes usar/iu', $frase) === 1) {
            return false;
        }

        return preg_match('/(\d+\s?%|descuento|promoci[óo]n|rebaja|precio con cup[óo]n)/iu', $frase) === 1;
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

        $hotel = trim((string) app(\App\Services\ReservationPolicy::class)->fillTerms(
            \App\Models\Property::query()->first()?->settings['agent_instructions'] ?? '',
        ));
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
        $call = function (string $method, array $params = []) use (&$used, $conversation): string {
            // Qué herramientas tocó esta respuesta: con eso se sabe si el
            // huésped venía cotizando (para avisarle al hotel fuera de
            // horario) sin tener que adivinarlo leyendo el texto.
            $used[] = $method;

            // Dio el día sin mes: nada de consultar ni apartar hasta que lo
            // diga. El prompt ya se lo pide; esto es por si no obedece.
            if (in_array($method, ['availability', 'availability_overview', 'hold', 'group_hold'], true)
                && $this->dayWithoutMonth($conversation) !== []) {
                return json_encode([
                    'ok' => false,
                    'error' => 'El huésped dio el día sin el mes.',
                    'que_hacer' => 'No consultes ni apartes todavía: pregúntale de qué mes habla y espera su respuesta.',
                ], JSON_UNESCAPED_UNICODE);
            }

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
                'waitlist' => $respond($this->tools->joinWaitlist($request)),
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
                    // Nunca en una fecha distinta a la que pidió el huésped.
                    if (($reclamo = $this->holdDateMismatch($conversation, $starts_at)) !== null) {
                        return $reclamo;
                    }

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
                    // Nunca en una fecha distinta a la que pidió el huésped.
                    if (($reclamo = $this->holdDateMismatch($conversation, $starts_at)) !== null) {
                        return $reclamo;
                    }

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

            // Más de 40 conversaciones de cabañas (13 al 15 de septiembre)
            // chocaron con "no hay disponibilidad" y ahí murieron. El módulo
            // de lista de espera ya existía; lo que faltaba era que el bot
            // pudiera apuntarlos.
            Tool::as('apuntar_lista_espera')
                ->for('Apunta al huésped en la lista de espera para unas fechas SIN lugar, para avisarle si se libera. Úsala cuando consultar_disponibilidad o consultar_disponibilidad_general no den lugar y el huésped no acepte las fechas alternativas. No promete habitación: es un aviso si se desocupa. Dile exactamente lo que devuelva "message".')
                ->withStringParameter('nombre', 'Nombre del huésped')
                ->withStringParameter('fecha_llegada', 'Fecha de llegada que quería, YYYY-MM-DD')
                ->withStringParameter('fecha_salida', 'Fecha de salida que quería, YYYY-MM-DD')
                ->withNumberParameter('room_type_id', 'ID del tipo de habitación que quería (opcional: déjalo vacío si le sirve cualquiera)', false)
                ->withStringParameter('correo', 'Correo del huésped (opcional si el chat es de WhatsApp, porque ahí ya tenemos su número)', false)
                ->withStringParameter('telefono', 'Teléfono del huésped (opcional: en WhatsApp se usa el del chat)', false)
                ->using(fn (string $nombre, string $fecha_llegada, string $fecha_salida, ?float $room_type_id = null, ?string $correo = null, ?string $telefono = null): string => $call('waitlist', array_filter([
                    'guest_name' => $nombre,
                    'starts_at' => $fecha_llegada,
                    'ends_at' => $fecha_salida,
                    'room_type_id' => $room_type_id !== null ? (int) $room_type_id : null,
                    'guest_email' => $correo,
                    'guest_phone' => $telefono,
                    'conversation_id' => $conversation?->id,
                ]))),

            Tool::as('consultar_reserva')
                ->for('Consulta el estado de una reserva por su código (ej. RES-2026-0001) O de un grupo completo por su folio (ej. GRP-2026-0149), incluido su estado de pago y saldo pendiente. Los folios GRP- son los que tú mismo repartes al apartar varias habitaciones, así que son los que el huésped te va a teclear de vuelta.')
                ->withStringParameter('code', 'Código de la reserva (RES-) o folio del grupo (GRP-). Opcional: si el huésped ya está identificado por su número, déjalo vacío y se consulta la suya.', false)
                ->using(function (?string $code = null) use ($call, $conversation): string {
                    $code = trim((string) $code);

                    // Sin código, la del huésped que escribe: el número del
                    // chat ya lo identifica y pedirle su folio para algo que
                    // el sistema tiene a la mano es hacerlo trabajar.
                    if ($code === '') {
                        $mine = $this->guestReservations($conversation)->first();

                        if ($mine === null) {
                            return json_encode([
                                'error' => 'No encontré ninguna reserva ligada a este chat. Pídele su código (RES- o GRP-) con amabilidad.',
                            ], JSON_UNESCAPED_UNICODE);
                        }

                        $code = $mine->group?->code ?: $mine->displayCode();
                    }

                    return $call('reservation', ['code' => $code]);
                }),

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
                fn ($tool) => ! in_array($tool->name(), ['crear_apartado', 'crear_apartado_grupo', 'reactivar_apartado', 'solicitar_pago', 'transferir_a_humano', 'apuntar_lista_espera'], true),
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
            // Lista de espera: módulo `lista-espera`.
            'apuntar_lista_espera' => $this->tools->waitlistPublic(),
        ];

        return array_values(array_filter(
            $tools,
            fn ($tool) => $available[$tool->name()] ?? true,
        ));
    }
}
