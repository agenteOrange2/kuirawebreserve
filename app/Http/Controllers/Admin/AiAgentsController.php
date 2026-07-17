<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\Central\PlatformAiProvider;
use App\Models\Central\TenantAgentSetting;
use App\Models\Central\TenantAiUsage;
use App\Models\Tenant;
use App\Services\Agent\AgentBrain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Admin de plataforma · Agentes IA: keys maestras, asignación por tenant,
 * cuotas, kill switch, BYOK y consumo (costo-beneficio del negocio).
 */
class AiAgentsController extends Controller
{
    public function index(): Response
    {
        $monthStart = now()->startOfMonth()->toDateString();

        $usage = TenantAiUsage::query()
            ->where('date', '>=', $monthStart)
            ->selectRaw('tenant_id, SUM(replies) as replies, SUM(prompt_tokens) + SUM(completion_tokens) as tokens')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $settings = TenantAgentSetting::query()->get()->keyBy('tenant_id');

        // Canales conectados por hotel (Meta + Evolution) para los iconos de
        // la tabla; la gestión completa vive en admin.ai.channels.
        $metaByTenant = \App\Models\Central\MetaChannelLink::query()->get()->groupBy('tenant_id');
        $evoByTenant = \App\Models\Central\EvolutionChannelLink::query()->get()->groupBy('tenant_id');

        return Inertia::render('admin/AiAgents', [
            'providers' => PlatformAiProvider::query()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (PlatformAiProvider $p) => [
                    'id' => $p->id,
                    'provider' => $p->provider,
                    'label' => $p->label(),
                    'model' => $p->model,
                    'masked_key' => $p->maskedKey(),
                    'active' => $p->active,
                ]),
            'catalog' => collect(AiProvider::CATALOG)->map(fn (array $meta, string $key) => [
                'key' => $key,
                'label' => $meta['label'],
                'placeholder_model' => $meta['placeholder_model'],
                'key_hint' => $meta['key_hint'],
                'models' => $meta['models'],
            ])->values(),
            'tenants' => Tenant::query()->with('domains')->get()->map(function (Tenant $tenant) use ($usage, $settings, $metaByTenant, $evoByTenant) {
                $setting = $settings->get($tenant->id);
                $planAi = config("plans.{$tenant->plan}.ai", ['enabled' => false, 'monthly_replies' => 0]);

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name ?? $tenant->id,
                    'domain' => $tenant->domains->first()?->domain,
                    'plan' => $tenant->plan,
                    'plan_label' => $tenant->planLimits()['label'] ?? $tenant->plan,
                    'plan_ai_enabled' => (bool) $planAi['enabled'],
                    'plan_ai_limit' => $planAi['monthly_replies'] ?? null,
                    'enabled' => $setting?->enabled ?? true,
                    'provider_id' => $setting?->platform_ai_provider_id,
                    'monthly_reply_limit' => $setting?->monthly_reply_limit,
                    'byok_allowed' => $setting?->byok_allowed ?? false,
                    'api_allowed' => $setting?->api_allowed ?? false,
                    'used_replies' => (int) ($usage->get($tenant->id)?->replies ?? 0),
                    'used_tokens' => (int) ($usage->get($tenant->id)?->tokens ?? 0),
                    'suspended' => $tenant->isSuspended(),
                    'channels' => collect()
                        ->concat(($metaByTenant->get($tenant->id) ?? collect())->map(fn ($l) => [
                            'type' => $l->type,
                            'label' => $l->typeLabel().($l->name ? " · {$l->name}" : ''),
                            'active' => (bool) $l->active,
                            'last_event_at' => $l->last_event_at?->diffForHumans(short: true),
                        ]))
                        ->concat(($evoByTenant->get($tenant->id) ?? collect())->map(fn ($l) => [
                            'type' => 'whatsapp_evo',
                            'label' => 'WhatsApp (Evolution)'.($l->name ? " · {$l->name}" : ''),
                            'active' => (bool) $l->active,
                            'last_event_at' => $l->last_event_at?->diffForHumans(short: true),
                        ]))
                        ->values(),
                ];
            }),
        ]);
    }

    /**
     * Canales de UN hotel: sus conexiones Meta + Evolution aisladas, con la
     * configuración del webhook de Meta. Espejo de la vista de contexto.
     */
    public function channels(Tenant $tenant): Response
    {
        return Inertia::render('admin/AgentChannels', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name ?? $tenant->id,
                'domain' => $tenant->domains()->first()?->domain,
            ],
            'meta' => \App\Models\Central\MetaChannelLink::query()
                ->where('tenant_id', $tenant->id)->latest()->get()
                ->map(fn ($link) => app(MetaChannelController::class)->serialize($link))->values(),
            'evolution' => \App\Models\Central\EvolutionChannelLink::query()
                ->where('tenant_id', $tenant->id)->orderBy('id')->get()
                ->map(fn ($link) => [
                    'id' => $link->id,
                    'name' => $link->name,
                    'base_url' => $link->base_url,
                    'instance' => $link->instance,
                    'active' => (bool) $link->active,
                    'last_event_at' => $link->last_event_at?->diffForHumans(short: true),
                ])->values(),
            'metaConfig' => [
                'mode' => config('meta.mode'),
                'webhook_url' => rtrim(config('app.url'), '/').'/webhooks/meta',
                'verify_token' => config('meta.verify_token'),
                'app_configured' => filled(config('meta.app_id')),
            ],
        ]);
    }

    public function storeProvider(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(AiProvider::CATALOG))],
            'model' => ['required', 'string', 'max:100'],
            'api_key' => ['required', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $provider = PlatformAiProvider::create($data + [
            'sort_order' => (int) PlatformAiProvider::max('sort_order') + 1,
        ]);

        return response()->json(['id' => $provider->id], 201);
    }

    public function updateProvider(Request $request, PlatformAiProvider $platformAiProvider): JsonResponse
    {
        $data = $request->validate([
            'model' => ['sometimes', 'required', 'string', 'max:100'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
        }

        $platformAiProvider->update($data);

        return response()->json(['ok' => true]);
    }

    /**
     * El "ojito": prompt efectivo del bot para un hotel (identidad, datos,
     * FAQs, instrucciones de plataforma y del hotel, reglas) tal como lo
     * recibe el modelo — se arma dentro del contexto del tenant.
     */
    /** Plantilla base de instrucciones de plataforma (punto de partida). */
    public const INSTRUCTIONS_TEMPLATE = <<<'TXT'
IDENTIDAD Y TONO
- Presentate como el asistente del hotel. Trato calido, profesional y breve; maximo 2-3 oraciones salvo que listes opciones.

COMO COTIZAR (cero errores de calculo)
- Todo precio sale de tus herramientas, nunca de memoria.
- Cada tarifa pertenece a UN tipo de habitacion: cotiza solo tarifas del tipo que el huesped pidio.
- El precio de la tarifa es POR UNIDAD (noche o bloque). El TOTAL de la estancia lo calcula consultar_disponibilidad: entrega siempre ese total desglosado, p. ej. "2 noches x $650 = $1,300".
- Estancia con fechas = tarifa por noche. Tarifas por horas/bloque SOLO si piden un rato de horas.
- Al comparar, presenta maximo 3 opciones: tipo, tarifa y total de cada una.

COMO APARTAR (reservar)
- Antes de crear el apartado repite y espera confirmacion de: tipo de habitacion, tarifa, TOTAL exacto, llegada/salida y nombre completo.
- Tras crearlo entrega el folio y aclara que es un apartado pendiente que el hotel confirmara.
- No prometas habitacion especifica, piso ni vista: eso lo asigna recepcion.

PAGOS
- El pago se realiza o registra en recepcion (efectivo, tarjeta o transferencia segun el hotel).
- NUNCA pidas numeros de tarjeta ni datos bancarios por chat.
- Si preguntan por anticipos, informa solo lo que la tarifa indique; sin dato, ofrece comunicar con recepcion.

CUANDO PASAR CON UNA PERSONA
- Quejas, cambios a reservas ya pagadas, grupos grandes, facturas o cualquier dato que no tengas: usa transferir_a_humano.
TXT;

    public function promptPreview(Tenant $tenant): JsonResponse
    {
        $prompt = $tenant->run(fn () => app(\App\Services\Agent\AgentBrain::class)->promptPreview());

        return response()->json([
            'prompt' => $prompt,
            'platform_instructions' => TenantAgentSetting::for($tenant->id)->platform_instructions,
        ]);
    }

    /** Vista dedicada "Contexto del bot" por hotel (el ojito). */
    public function context(Tenant $tenant): \Inertia\Response
    {
        $prompt = $tenant->run(fn () => app(\App\Services\Agent\AgentBrain::class)->promptPreview());
        // Sin esto, la página del ADMIN se renderiza con tenancy inicializada
        // y el layout pinta el menú del tenant (fuga de contexto).
        tenancy()->end();

        $settings = TenantAgentSetting::for($tenant->id);

        return \Inertia\Inertia::render('admin/AgentContext', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'platformInstructions' => $settings->platform_instructions,
            'contextEditable' => (bool) $settings->context_editable,
            'guidelinesEditable' => (bool) $settings->guidelines_editable,
            'template' => self::INSTRUCTIONS_TEMPLATE,
            'prompt' => $prompt,
        ]);
    }

    public function destroyProvider(PlatformAiProvider $platformAiProvider): JsonResponse
    {
        $platformAiProvider->delete();

        return response()->json(status: 204);
    }

    /** Prueba real de la key maestra (latencia + respuesta). */
    public function testProvider(PlatformAiProvider $platformAiProvider, AgentBrain $brain): JsonResponse
    {
        $started = microtime(true);

        try {
            $response = $brain->run($platformAiProvider->asRuntimeProvider(), fn ($request) => $request
                ->withPrompt('Responde únicamente con la palabra: ok')
                ->withClientOptions(['timeout' => 20]));

            return response()->json([
                'ok' => true,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'reply' => str($response->text)->limit(80)->toString(),
                'tokens' => ($response->usage->promptTokens ?? 0) + ($response->usage->completionTokens ?? 0),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => str($e->getMessage())->limit(220)->toString(),
            ], 422);
        }
    }

    /** Configuración del agente de un tenant (toggle, asignación, cuota, BYOK). */
    public function updateTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'platform_ai_provider_id' => ['sometimes', 'nullable', Rule::exists('platform_ai_providers', 'id')],
            'monthly_reply_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'byok_allowed' => ['sometimes', 'boolean'],
            'api_allowed' => ['sometimes', 'boolean'],
            'platform_instructions' => ['sometimes', 'nullable', 'string', 'max:6000'],
            'context_editable' => ['sometimes', 'boolean'],
            'guidelines_editable' => ['sometimes', 'boolean'],
        ]);

        TenantAgentSetting::for($tenant->id)->update($data);

        return response()->json(['ok' => true]);
    }
}
