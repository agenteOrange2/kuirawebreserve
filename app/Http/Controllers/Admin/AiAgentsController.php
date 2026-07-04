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
            'tenants' => Tenant::query()->with('domains')->get()->map(function (Tenant $tenant) use ($usage, $settings) {
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
                ];
            }),
            'metaChannels' => \App\Models\Central\MetaChannelLink::query()->latest()->get()
                ->map(fn ($link) => app(MetaChannelController::class)->serialize($link))->values(),
            'metaConfig' => [
                'mode' => config('meta.mode'),
                'webhook_url' => rtrim(config('app.url'), '/').'/webhooks/meta',
                'verify_token' => config('meta.verify_token'),
                'app_configured' => filled(config('meta.app_id')),
            ],
            'tenantOptions' => Tenant::query()->orderBy('id')->get()->map(fn (Tenant $t) => [
                'value' => $t->id,
                'label' => $t->name ?? $t->id,
            ])->values(),
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
        ]);

        TenantAgentSetting::for($tenant->id)->update($data);

        return response()->json(['ok' => true]);
    }
}
