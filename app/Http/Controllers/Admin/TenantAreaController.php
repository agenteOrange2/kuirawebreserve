<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\AddonService;
use App\Models\Central\EvolutionChannelLink;
use App\Models\Central\MetaChannelLink;
use App\Models\Central\ModuleActivationRequest;
use App\Models\Central\PaymentGatewayLink;
use App\Models\Central\PaymentMethodSetting;
use App\Models\Central\TelegramChannelLink;
use App\Models\Central\TenantAgentSetting;
use App\Models\Central\TenantAiUsage;
use App\Models\Central\TenantModule;
use App\Models\Central\TiktokChannelLink;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\PaymentMethodGate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las áreas de UN hotel, cada una en su propia URL bajo
 * /admin/tenants/{tenant}/…: plan, módulos, equipo, asistente, canales y
 * cobros. El resumen (la portada) sigue en TenantController::show.
 *
 * Antes todo esto vivía apilado en una sola página de 1,500 líneas que
 * cargaba los 25 módulos aunque entraras nada más a ver si el hotel está
 * suspendido; y los canales y el contexto del bot colgaban de
 * /admin/agentes-ia, que es el catálogo de la plataforma, no el hotel.
 */
class TenantAreaController extends Controller
{
    /**
     * Identidad del hotel + catálogo de planes: lo que necesita la
     * cabecera compartida de todas las sub-vistas (incluido el resumen).
     *
     * @return array<string, mixed>
     */
    public static function shell(Tenant $tenant): array
    {
        $plan = config("plans.{$tenant->plan}", []);

        return [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name ?? $tenant->id,
                'plan' => $tenant->plan,
                'plan_label' => $plan['label'] ?? $tenant->plan,
                'suspended' => $tenant->isSuspended(),
                'domain' => $tenant->domains->first()?->domain,
                'created_at' => $tenant->created_at?->format('d/m/Y'),
            ],
            'plans' => collect(config('plans'))->map(fn (array $p, string $key) => [
                'value' => $key,
                'label' => $p['label'],
            ])->values(),
        ];
    }

    /**
     * Plan y facturación: qué contrató, contra qué tope y cuánto paga —
     * plan y servicios adicionales en la misma pantalla, porque el número
     * que importa (el total del mes) es la suma de los dos.
     */
    public function plan(Tenant $tenant): Response
    {
        $plan = config("plans.{$tenant->plan}", []);

        $usage = $tenant->run(fn () => [
            'properties' => Property::count(),
            'rooms' => \App\Models\Room::count(),
            'users' => User::whereDoesntHave('roles', fn ($q) => $q->where('name', 'agent'))->count(),
        ]);

        $contractedKeys = $tenant->addonServices()->pluck('key');

        return Inertia::render('admin/tenants/Plan', self::shell($tenant) + [
            'limits' => [
                'max_properties' => $plan['max_properties'] ?? null,
                'max_rooms' => $plan['max_rooms'] ?? null,
                'max_users' => $plan['max_users'] ?? null,
                'ai_enabled' => (bool) ($plan['ai']['enabled'] ?? false),
                'ai_monthly_replies' => $plan['ai']['monthly_replies'] ?? null,
            ],
            'usage' => $usage,
            'billing' => [
                'plan_monthly' => (int) ($plan['price_monthly'] ?? 0),
                'addons_monthly' => (int) $tenant->addonServices()->sum('price_monthly'),
                'total_monthly' => $tenant->monthlyPrice(),
            ],
            'addonServices' => AddonService::query()->ordered()->get()
                ->map(fn (AddonService $service) => [
                    'key' => $service->key,
                    'name' => $service->name,
                    'summary' => $service->summary,
                    'price_monthly' => (int) $service->price_monthly,
                    'activation_fee' => (int) $service->activation_fee,
                    'modules' => $service->modules ?? [],
                    'requires' => $service->requires,
                    'active' => $service->active,
                    'contracted' => $contractedKeys->contains($service->key),
                ])->values(),
        ]);
    }

    /**
     * Módulos: estado efectivo con su origen (plan, servicio adicional u
     * override de este hotel) + las solicitudes hechas desde "Tu plan".
     */
    public function modules(Tenant $tenant): Response
    {
        $plan = config("plans.{$tenant->plan}", []);
        $overrides = TenantModule::query()->where('tenant_id', $tenant->id)->pluck('enabled', 'module');
        $requests = ModuleActivationRequest::query()->where('tenant_id', $tenant->id)->pluck('created_at', 'module');
        $planModules = $plan['modules'] ?? [];
        $addonModules = $tenant->addonModules();

        $modules = collect(config('modules', []))
            ->map(function (array $def, string $key) use ($overrides, $requests, $planModules, $addonModules) {
                $override = $overrides->has($key) ? (bool) $overrides[$key] : null;
                $inPlan = in_array($key, $planModules, true);
                $inAddon = in_array($key, $addonModules, true);

                return [
                    'key' => $key,
                    'label' => $def['label'],
                    'description' => $def['description'],
                    'available' => $def['available'],
                    'in_plan' => $inPlan,
                    // Lo aporta un servicio adicional contratado.
                    'in_addon' => $inAddon,
                    'override' => $override, // null = hereda del plan/servicio
                    'enabled' => $override ?? ($inPlan || $inAddon),
                    'requested_at' => $requests->has($key)
                        ? \Illuminate\Support\Carbon::parse($requests[$key])->format('d/m/Y')
                        : null,
                ];
            })->values();

        return Inertia::render('admin/tenants/Modules', self::shell($tenant) + [
            'modules' => $modules,
        ]);
    }

    /** Equipo del hotel: quién entra al panel y con qué rol. */
    public function team(Tenant $tenant): Response
    {
        $team = $tenant->run(fn () => [
            'owner' => ($owner = User::role('owner')->first()) ? $owner->only(['name', 'email']) : null,
            'users' => User::with('roles:id,name')
                ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'agent'))
                ->orderBy('name')->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->roles->first()?->name,
                ])->values(),
            'assignable_roles' => \App\Http\Controllers\Tenant\UserController::assignableRoles(),
        ]);

        $plan = config("plans.{$tenant->plan}", []);

        return Inertia::render('admin/tenants/Team', self::shell($tenant) + [
            'owner' => $team['owner'],
            'users' => $team['users'],
            'assignableRoles' => $team['assignable_roles'],
            'maxUsers' => $plan['max_users'] ?? null,
        ]);
    }

    /**
     * Asistente IA del hotel: interruptor y cuota junto al contexto que
     * recibe el modelo. Antes eran dos páginas separadas y la cuota vivía
     * lejos del prompt que la consume.
     */
    public function assistant(Tenant $tenant): Response
    {
        $plan = config("plans.{$tenant->plan}", []);
        $setting = TenantAgentSetting::for($tenant->id);
        $monthStart = now()->startOfMonth();

        $tokens = (int) TenantAiUsage::query()
            ->where('tenant_id', $tenant->id)
            ->where('date', '>=', $monthStart->toDateString())
            ->selectRaw('COALESCE(SUM(prompt_tokens) + SUM(completion_tokens), 0) as t')
            ->value('t');

        $prompt = $tenant->run(fn () => app(\App\Services\Agent\AgentBrain::class)->promptPreview());
        // Sin esto la página del ADMIN se renderiza con tenancy iniciada y
        // el layout pinta el menú del hotel (fuga de contexto).
        tenancy()->end();

        return Inertia::render('admin/tenants/Assistant', self::shell($tenant) + [
            'ai' => [
                'enabled' => $setting->enabled,
                // Mismo cálculo que PlatformAgentGate: ajuste del hotel ??
                // plan + cuota que aporten los servicios adicionales con IA.
                'limit' => $setting->monthly_reply_limit
                    ?? (($plan['ai']['monthly_replies'] ?? null) === null
                        ? null
                        : (int) $plan['ai']['monthly_replies'] + (int) $tenant->addonServices()->sum('ai_monthly_replies')),
                'used' => TenantAiUsage::repliesThisMonth($tenant->id),
                'tokens' => $tokens,
                'byok_allowed' => $setting->byok_allowed,
                'api_allowed' => $setting->api_allowed,
                // Lo que el hotel tiene fijado a mano (null = sigue al plan).
                'monthly_reply_limit' => $setting->monthly_reply_limit,
                'provider_id' => $setting->platform_ai_provider_id,
                'ai_in_plan' => (bool) ($plan['ai']['enabled'] ?? false),
                'plan_replies' => $plan['ai']['monthly_replies'] ?? null,
            ],
            'providers' => \App\Models\Central\PlatformAiProvider::query()
                ->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'label' => $p->label(),
                    'model' => $p->model,
                    'active' => $p->active,
                ])->values(),
            'platformInstructions' => $setting->platform_instructions,
            'contextEditable' => (bool) $setting->context_editable,
            'guidelinesEditable' => (bool) $setting->guidelines_editable,
            'template' => AiAgentsController::INSTRUCTIONS_TEMPLATE,
            'prompt' => $prompt,
        ]);
    }

    /** Canales conectados de este hotel (Meta, Evolution, Telegram, TikTok). */
    public function channels(Tenant $tenant): Response
    {
        return Inertia::render('admin/tenants/Channels', self::shell($tenant) + [
            'meta' => MetaChannelLink::query()
                ->where('tenant_id', $tenant->id)->latest()->get()
                ->map(fn ($link) => app(MetaChannelController::class)->serialize($link))->values(),
            'evolution' => EvolutionChannelLink::query()
                ->where('tenant_id', $tenant->id)->orderBy('id')->get()
                ->map(fn ($link) => [
                    'id' => $link->id,
                    'name' => $link->name,
                    'base_url' => $link->base_url,
                    'instance' => $link->instance,
                    'active' => (bool) $link->active,
                    'last_event_at' => $link->last_event_at?->diffForHumans(short: true),
                ])->values(),
            'telegram' => TelegramChannelLink::query()
                ->where('tenant_id', $tenant->id)->orderBy('id')->get()
                ->map(fn ($link) => app(TelegramChannelController::class)->serialize($link))->values(),
            'tiktok' => TiktokChannelLink::query()
                ->where('tenant_id', $tenant->id)->orderBy('id')->get()
                ->map(fn ($link) => app(TiktokChannelController::class)->serialize($link))->values(),
            'metaConfig' => [
                'mode' => config('meta.mode'),
                'webhook_url' => rtrim(config('app.url'), '/').'/webhooks/meta',
                'verify_token' => config('meta.verify_token'),
                'app_configured' => filled(config('meta.app_id')),
            ],
        ]);
    }

    /**
     * Cobros del hotel: qué métodos tiene permitidos (su override sobre
     * los interruptores de plataforma) y qué pasarelas conectó, con su
     * último latido.
     */
    public function payments(Tenant $tenant): Response
    {
        $gate = app(PaymentMethodGate::class);
        $tenantSettings = PaymentMethodSetting::query()
            ->where('tenant_id', $tenant->id)
            ->pluck('enabled', 'method');

        return Inertia::render('admin/tenants/Payments', self::shell($tenant) + [
            'methods' => collect(PaymentMethodGate::METHODS)
                ->map(fn ($label, $method) => [
                    'method' => $method,
                    'label' => $label,
                    'platform_enabled' => $gate->platformEnabled($method),
                    // El toggle del hotel se muestra tal cual; el efectivo es AND.
                    'tenant_enabled' => $tenantSettings->has($method)
                        ? (bool) $tenantSettings[$method]
                        : true,
                ])->values(),
            'gateways' => PaymentGatewayLink::query()
                ->where('tenant_id', $tenant->id)->orderBy('id')->get()
                ->map(fn (PaymentGatewayLink $link) => [
                    'id' => $link->id,
                    'provider' => $link->provider,
                    'provider_label' => $link->providerLabel(),
                    'mode' => $link->mode,
                    'active' => $link->active,
                    'last_event_at' => $link->last_event_at?->diffForHumans(short: true),
                ])->values(),
        ]);
    }
}
