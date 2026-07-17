<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\Message;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Agent\AgentBrain;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel del Asistente IA: estado, tokens de acceso, herramientas
 * disponibles (con playground de prueba) y guía de conexión.
 */
class AgentPageController extends Controller
{
    public function __invoke(Request $request, AgentBrain $brain): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];
        $agent = User::role('agent')->first();

        // Uso por proveedor (mensajes del bot con meta) para costo-beneficio.
        $usage = Message::query()
            ->where('sender_type', 'bot')
            ->whereNotNull('meta')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.provider')) as provider_key")
            ->selectRaw('COUNT(*) as replies')
            ->selectRaw("ROUND(AVG(JSON_EXTRACT(meta, '$.ms'))) as avg_ms")
            ->selectRaw("COALESCE(SUM(JSON_EXTRACT(meta, '$.prompt_tokens')), 0) + COALESCE(SUM(JSON_EXTRACT(meta, '$.completion_tokens')), 0) as tokens")
            ->groupBy('provider_key')
            ->get()
            ->keyBy('provider_key');

        $tokens = $agent
            ? $agent->tokens()->latest()->get()->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at?->diffForHumans(),
                'created_at' => $token->created_at->format('d/m/Y'),
            ])
            : collect();

        $agentReservations = $agent
            ? Reservation::query()->where('created_by', $agent->id)
            : null;

        return Inertia::render('tenant/agent/Index', [
            'property' => $property->only(['id', 'name']),
            // Aprendizajes del bot: área aislada /asistente/aprendizajes,
            // visible solo si el super-admin la habilitó para este hotel.
            'guidelinesEditable' => (bool) \App\Models\Central\TenantAgentSetting::for((string) tenant('id'))->guidelines_editable,
            'tokens' => $tokens,
            'aiProviders' => AiProvider::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (AiProvider $p) => [
                'id' => $p->id,
                'provider' => $p->provider,
                'label' => $p->label(),
                'model' => $p->model,
                'masked_key' => $p->maskedKey(),
                'active' => $p->active,
                'replies' => (int) ($usage->get($p->provider)?->replies ?? 0),
                'avg_ms' => $usage->get($p->provider)?->avg_ms !== null ? (int) $usage->get($p->provider)->avg_ms : null,
                'tokens' => (int) ($usage->get($p->provider)?->tokens ?? 0),
            ]),
            'aiCatalog' => collect(AiProvider::CATALOG)->map(fn (array $meta, string $key) => [
                'key' => $key,
                'label' => $meta['label'],
                'placeholder_model' => $meta['placeholder_model'],
                'key_hint' => $meta['key_hint'],
                'models' => $meta['models'],
            ])->values(),
            'llmReady' => $brain->isConfigured(),
            'aiPlan' => (function () use ($brain) {
                $gate = $brain->gateStatus();

                return [
                    'plan_label' => $gate['plan_label'],
                    'included' => $gate['plan_enabled'],
                    'enabled' => $gate['enabled'],
                    'byok_allowed' => $gate['byok_allowed'],
                    'api_allowed' => $gate['api_allowed'],
                    'limit' => $gate['limit'],
                    'used' => $gate['used'],
                    'blocked_reason' => $gate['blocked_reason'],
                ];
            })(),
            'stats' => [
                'active' => $tokens->isNotEmpty(),
                'policies_set' => filled($settings['policies'] ?? null),
                'holds_total' => $agentReservations?->clone()->count() ?? 0,
                'holds_confirmed' => $agentReservations?->clone()
                    ->whereIn('status', ['confirmed', 'checked_in', 'completed'])->count() ?? 0,
                'last_activity' => $agent?->tokens()->max('last_used_at')
                    ? \Illuminate\Support\Carbon::parse($agent->tokens()->max('last_used_at'))->diffForHumans()
                    : null,
            ],
            // El hotel ve/edita su contexto del bot solo si la plataforma
            // habilitó la palanca para este tenant.
            'contextEditable' => (bool) \App\Models\Central\TenantAgentSetting::for((string) tenant('id'))->context_editable,
            'baseUrl' => $request->getSchemeAndHttpHost().'/api/agent',
            'ratePlansCount' => \App\Models\RatePlan::where('active', true)->count(),
            // WhatsApp vía Evolution API: instancias conectadas por el hotel.
            'evolutionChannels' => \App\Models\Central\EvolutionChannelLink::query()
                ->where('tenant_id', tenant('id'))
                ->orderBy('id')
                ->get()
                ->map(fn ($link) => [
                    'id' => $link->id,
                    'name' => $link->name,
                    'base_url' => $link->base_url,
                    'instance' => $link->instance,
                    'masked_key' => $link->maskedKey(),
                    'webhook_url' => $link->webhookUrl(),
                    'active' => $link->active,
                    'created_at' => $link->created_at?->format('d/m/Y'),
                ]),
            'channelLimit' => [
                'max' => tenant()->planLimit('max_channels'),
                'used' => \App\Models\Central\EvolutionChannelLink::query()->where('tenant_id', tenant('id'))->count()
                    + \App\Models\Central\MetaChannelLink::query()->where('tenant_id', tenant('id'))->count(),
            ],
        ]);
    }
}
