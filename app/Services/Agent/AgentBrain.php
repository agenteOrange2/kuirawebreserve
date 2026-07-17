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
            'body' => $text,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }

    protected function systemPrompt(?Conversation $conversation = null): string
    {
        $policies = json_decode($this->tools->policies()->getContent(), true);
        $guestBlock = $this->guestBlock($conversation);
        $summaryBlock = $this->summaryBlock($conversation);
        $instructionsBlock = $this->instructionsBlock();
        $guidelinesBlock = $this->guidelinesBlock();

        return <<<PROMPT
Eres el asistente virtual del hotel "{$policies['hotel']['name']}". Atiendes huéspedes por chat en español (responde en el idioma del huésped si escribe en otro).

DATOS DEL HOTEL (única fuente de verdad — si algo no está aquí ni en tus herramientas, di que no tienes esa información y ofrece comunicarlo con recepción):
```json
{$this->tools->policies()->getContent()}
```
{$guestBlock}{$summaryBlock}{$instructionsBlock}{$guidelinesBlock}
REGLAS ESTRICTAS:
- Si la duda del huésped coincide con una pregunta de "faqs", responde con esa respuesta tal cual (puedes adaptarla al tono de la conversación, sin cambiar los datos).
- Si el huésped comparte su teléfono, usa identificar_huesped para reconocerlo; si ya nos visitó, salúdalo por su nombre como cliente frecuente (sin recitar sus datos).
- Usa las herramientas para tarifas, disponibilidad y reservas; NUNCA inventes precios, fechas ni políticas.
- Cada tarifa pertenece a UN tipo de habitación (room_type en consultar_tarifas). Si el huésped pidió un tipo, cotiza y aparta SOLO con tarifas de ese tipo — jamás uses la tarifa de otro tipo.
- El precio de una tarifa es POR UNIDAD (por noche o por bloque); el TOTAL del rango lo calcula consultar_disponibilidad. Nunca presentes el total del rango como si fuera el precio por unidad ("$1,750 por 3 horas" está MAL si es el total de varias unidades). Para estancias con fechas usa tarifas por noche; las tarifas por bloque (ratos/horas) solo si el huésped pide horas.
- Antes de crear un apartado repite al huésped: tipo de habitación, nombre de la tarifa, TOTAL exacto, fecha de llegada y nombre completo — y espera su confirmación.
- PAGOS: si el apartado requiere prepago (requires_prepayment), usa solicitar_pago y comparte lo que devuelva tal cual: link de pago (el huésped paga ahí y el sistema confirma solo) o cuentas para transferencia (pide el comprobante por este chat; el hotel lo verifica). NUNCA digas que un pago fue recibido o verificado: eso solo lo confirma el sistema (consultar_reserva) o el personal. Si el huésped insiste en que ya pagó y el sistema no lo refleja, usa transferir_a_humano.
- NUNCA pidas ni aceptes números de tarjeta por el chat; si el huésped los envía, dile que por seguridad los borre y no los uses.
- Cita montos exactamente como los devuelven las herramientas (usa *_label).
- Si el huésped pide VARIAS habitaciones, haz UNA llamada de crear_apartado por cada una y reporta el resultado real de CADA llamada (código de reserva o el error exacto). Nunca resumas dos apartados en uno ni des por hecho uno que no confirmaste con la herramienta.
- Si una herramienta devuelve un error, comunica al huésped el mensaje EXACTO que devolvió — nunca inventes la causa ni digas "no hay disponibilidad" si la herramienta dijo otra cosa.
- Si el huésped pide hablar con una persona, se queja, o pide algo fuera de tu alcance, usa la herramienta transferir_a_humano.
- Hoy es {$this->today()}. Fechas en formato YYYY-MM-DD HH:MM.
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
            return <<<BLOCK

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

                $text = trim($response->text);

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
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            return $conversation->summary;
        }

        $transcript = $messages
            ->map(fn (Message $m) => ($m->direction === 'in' ? 'Huésped' : ($m->sender_type === 'staff' ? 'Hotel (persona)' : 'Asistente')).': '.$m->body)
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
            ->latest('id')->take(20)->get()->reverse()
            ->map(fn (Message $message) => $message->direction === 'in'
                ? new UserMessage($message->body)
                : new AssistantMessage($message->body))
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

            $respond = fn (\Illuminate\Http\JsonResponse $response) => tap($response->getContent(), function () use ($method, $params, $response) {
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
                'reservation' => $respond($this->tools->showReservation((string) ($params['code'] ?? ''))),
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
                                'contact_phone' => $guest_phone,
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
                ->for('Emite el cobro de una reserva (anticipo o saldo; el sistema decide monto y concepto). Úsala tras crear un apartado que requiere prepago, o si el huésped quiere pagar. Devuelve un LINK de pago (payment_link) o cuentas bancarias para transferencia: comparte lo que devuelva tal cual, con el monto exacto. Si es transferencia, pide el comprobante por el chat. NUNCA des un pago por recibido: eso lo confirma el sistema.')
                ->withStringParameter('codigo_reserva', 'Código de la reserva (ej. RES-2026-0001)')
                ->using(function (string $codigo_reserva) use ($call, $conversation): string {
                    $result = $call('payment', ['code' => $codigo_reserva]);

                    if ($conversation && (json_decode($result, true)['amount'] ?? null) !== null) {
                        $conversation->markLead(Conversation::LEAD_HOLD);
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
                            'contact_phone' => $telefono,
                        ]));

                        return json_encode([
                            'encontrado' => false,
                            'nota' => 'Huésped nuevo: atiéndelo normal; se registrará al crear su primer apartado.',
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    $conversation?->update([
                        'guest_id' => $guest->id,
                        'contact_name' => $nombre ?: $guest->full_name,
                        'contact_phone' => $telefono,
                    ]);

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

                    return json_encode(['ok' => true, 'motivo' => $motivo]);
                }),
        ];

        if ($readOnly) {
            $tools = array_values(array_filter(
                $tools,
                fn ($tool) => ! in_array($tool->name(), ['crear_apartado', 'solicitar_pago', 'transferir_a_humano'], true),
            ));
        }

        return $tools;
    }
}
