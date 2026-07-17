<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Gestión de hoteles (tenants) desde el panel de plataforma. Crear un tenant
 * dispara el pipeline de TenancyServiceProvider (DB + migraciones + roles) y
 * después siembra el usuario owner y la primera propiedad, para que el hotel
 * nazca usable.
 */
class TenantController extends Controller
{
    public function index(): Response
    {
        $monthStart = now()->startOfMonth();
        $tenants = Tenant::query()->with('domains')->latest()->get();

        // Rollup central de IA del mes (una sola query para todos).
        $aiUsage = \App\Models\Central\TenantAiUsage::query()
            ->where('date', '>=', $monthStart->toDateString())
            ->selectRaw('tenant_id, SUM(replies) as replies')
            ->groupBy('tenant_id')
            ->pluck('replies', 'tenant_id');

        $rows = $tenants->map(function (Tenant $tenant) use ($aiUsage, $monthStart) {
            // Métricas de operación de la BD del tenant, con caché corto
            // para no abrir N conexiones en cada carga del listado.
            $ops = \Illuminate\Support\Facades\Cache::remember(
                "admin:tenant-ops:{$tenant->id}",
                600,
                fn () => $tenant->run(fn () => [
                    'users' => User::count(),
                    'rooms' => \App\Models\Room::count(),
                    'reservations_month' => \App\Models\Reservation::where('created_at', '>=', $monthStart)->count(),
                ]),
            );

            $plan = config("plans.{$tenant->plan}", []);

            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'plan' => $tenant->plan,
                'plan_label' => $plan['label'] ?? $tenant->plan,
                'price_monthly' => (int) ($plan['price_monthly'] ?? 0),
                'ai_in_plan' => (bool) ($plan['ai']['enabled'] ?? false),
                'suspended' => $tenant->isSuspended(),
                'domain' => $tenant->domains->first()?->domain,
                'created_at' => $tenant->created_at?->format('d/m/Y'),
                'users' => $ops['users'],
                'rooms' => $ops['rooms'],
                'reservations_month' => $ops['reservations_month'],
                'ai_replies' => (int) ($aiUsage[$tenant->id] ?? 0),
            ];
        });

        $active = $tenants->reject(fn (Tenant $t) => $t->isSuspended());

        return Inertia::render('admin/tenants/Index', [
            'tenants' => $rows,
            'stats' => [
                'total' => $tenants->count(),
                'active' => $active->count(),
                'suspended' => $tenants->count() - $active->count(),
                'new_month' => $tenants->filter(fn (Tenant $t) => $t->created_at?->gte($monthStart))->count(),
                'mrr' => (int) $active->sum(fn (Tenant $t) => config("plans.{$t->plan}.price_monthly", 0)),
                'ai_replies_month' => (int) $aiUsage->sum(),
            ],
            'monthLabel' => now()->translatedFormat('F Y'),
            'domainSuffix' => $this->domainSuffix(),
            'plans' => collect(config('plans'))->map(fn (array $plan, string $key) => [
                'value' => $key,
                'label' => $plan['label'],
                'max_properties' => $plan['max_properties'],
                'max_rooms' => $plan['max_rooms'],
                'max_users' => $plan['max_users'],
                'active' => (bool) ($plan['active'] ?? true),
            ])->values(),
        ]);
    }

    /**
     * Ficha del cliente: datos del contrato + métricas reales de su
     * operación (leídas de la BD del tenant) + consumo de IA.
     */
    public function show(Tenant $tenant): Response
    {
        $plan = config("plans.{$tenant->plan}", []);
        $monthStart = now()->startOfMonth();

        $ops = $tenant->run(fn () => [
            'owner' => ($owner = User::role('owner')->first()) ? $owner->only(['name', 'email']) : null,
            'users' => User::count(),
            // El bot es identidad técnica (rol agent), no personal: se excluye.
            'users_list' => User::with('roles:id,name')
                ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'agent'))
                ->orderBy('name')->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->roles->first()?->name,
                ])->values(),
            'assignable_roles' => \App\Http\Controllers\Tenant\UserController::assignableRoles(),
            'properties' => \App\Models\Property::count(),
            'rooms' => \App\Models\Room::count(),
            'guests' => \App\Models\Guest::count(),
            'active_stays' => \App\Models\Stay::where('status', \App\Models\Stay::STATUS_ACTIVE)->count(),
            'reservations_month' => \App\Models\Reservation::where('created_at', '>=', $monthStart)->count(),
            'revenue_month' => (float) \Illuminate\Support\Facades\DB::table('payments')
                ->where('paid_at', '>=', $monthStart)->sum('amount'),
            'conversations' => \App\Models\Conversation::count(),
            'conversations_pending' => \App\Models\Conversation::where('status', \App\Models\Conversation::STATUS_PENDING)->count(),
            'recent_reservations' => \App\Models\Reservation::query()->latest()->take(6)->get()
                ->map(fn (\App\Models\Reservation $r) => [
                    'code' => $r->displayCode(),
                    'guest' => $r->guest_name,
                    'status' => $r->status->value,
                    'status_label' => $r->status->label(),
                    'starts_at' => $r->starts_at->format('d/m/Y H:i'),
                    'total' => (float) $r->total_amount,
                ])->values(),
        ]);

        $paymentGate = app(\App\Services\Payments\PaymentMethodGate::class);
        $paymentMethods = collect(\App\Services\Payments\PaymentMethodGate::METHODS)
            ->map(fn ($label, $method) => [
                'method' => $method,
                'label' => $label,
                'platform_enabled' => $paymentGate->platformEnabled($method),
                // El toggle del hotel se muestra tal cual; el efectivo es AND.
                'tenant_enabled' => \App\Models\Central\PaymentMethodSetting::query()
                    ->where('tenant_id', $tenant->id)->where('method', $method)->value('enabled') ?? true,
            ])->values();

        // Módulos: estado efectivo por módulo con su origen (plan u override)
        // + solicitudes de activación pendientes hechas desde "Tu plan".
        $moduleOverrides = \App\Models\Central\TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->pluck('enabled', 'module');
        $moduleRequests = \App\Models\Central\ModuleActivationRequest::query()
            ->where('tenant_id', $tenant->id)
            ->pluck('created_at', 'module');
        $planModules = $plan['modules'] ?? [];

        $modules = collect(config('modules', []))
            ->map(function (array $def, string $key) use ($moduleOverrides, $moduleRequests, $planModules) {
                $override = $moduleOverrides->has($key) ? (bool) $moduleOverrides[$key] : null;
                $inPlan = in_array($key, $planModules, true);

                return [
                    'key' => $key,
                    'label' => $def['label'],
                    'description' => $def['description'],
                    'available' => $def['available'],
                    'in_plan' => $inPlan,
                    'override' => $override, // null = hereda del plan
                    'enabled' => $override ?? $inPlan,
                    'requested_at' => $moduleRequests->has($key)
                        ? \Illuminate\Support\Carbon::parse($moduleRequests[$key])->format('d/m/Y')
                        : null,
                ];
            })->values();

        $agentSetting = \App\Models\Central\TenantAgentSetting::for($tenant->id);
        $aiUsed = \App\Models\Central\TenantAiUsage::repliesThisMonth($tenant->id);
        $aiTokens = (int) \App\Models\Central\TenantAiUsage::query()
            ->where('tenant_id', $tenant->id)
            ->where('date', '>=', $monthStart->toDateString())
            ->selectRaw('COALESCE(SUM(prompt_tokens) + SUM(completion_tokens), 0) as t')
            ->value('t');

        return Inertia::render('admin/tenants/Show', [
            'paymentMethods' => $paymentMethods,
            'modules' => $modules,
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'plan' => $tenant->plan,
                'plan_label' => $plan['label'] ?? $tenant->plan,
                'suspended' => $tenant->isSuspended(),
                'domain' => $tenant->domains->first()?->domain,
                'created_at' => $tenant->created_at?->format('d/m/Y'),
            ],
            'plan' => [
                'max_properties' => $plan['max_properties'] ?? null,
                'max_rooms' => $plan['max_rooms'] ?? null,
                'max_users' => $plan['max_users'] ?? null,
                'price_monthly' => $plan['price_monthly'] ?? 0,
                'ai_enabled' => (bool) ($plan['ai']['enabled'] ?? false),
            ],
            'ops' => $ops,
            'ai' => [
                'enabled' => $agentSetting->enabled,
                'limit' => $agentSetting->monthly_reply_limit ?? ($plan['ai']['monthly_replies'] ?? null),
                'used' => $aiUsed,
                'tokens' => $aiTokens,
                'byok_allowed' => $agentSetting->byok_allowed,
            ],
            'plans' => collect(config('plans'))->map(fn (array $p, string $key) => [
                'value' => $key,
                'label' => $p['label'],
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subdomain' => [
                'required',
                'string',
                'max:40',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
                Rule::notIn(['www', 'admin', 'api', 'mail', 'app']),
                Rule::unique('tenants', 'id'),
            ],
            'plan' => ['required', Rule::in(array_keys(config('plans')))],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_password' => ['required', 'string', 'min:8'],
        ], [
            'subdomain.regex' => 'Solo minúsculas, números y guiones (sin empezar/terminar con guion).',
            'subdomain.unique' => 'Ese subdominio ya está ocupado.',
        ]);

        $domain = $data['subdomain'].'.'.$this->domainSuffix();

        if (Domain::where('domain', $domain)->exists()) {
            return back()->withErrors(['subdomain' => 'Ese dominio ya está registrado.']);
        }

        // Sincrónico: crea la DB, migra y siembra roles (tarda unos segundos).
        $tenant = Tenant::create([
            'id' => $data['subdomain'],
            'name' => $data['name'],
            'plan' => $data['plan'],
        ]);

        $tenant->domains()->create(['domain' => $domain]);

        // El hotel nace usable: dueño con rol owner + su primera propiedad.
        $tenant->run(function () use ($data) {
            $owner = User::create([
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => Hash::make($data['owner_password']),
            ]);
            $owner->assignRole('owner');

            Property::create(['name' => $data['name']]);
        });

        return redirect()->route('admin.tenants.index');
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'plan' => ['required', Rule::in(array_keys(config('plans')))],
        ]);

        $tenant->update($data);

        return redirect()->route('admin.tenants.index');
    }

    /**
     * Override de un módulo para este hotel: heredar del plan (borra la
     * fila), forzar encendido o forzar apagado. Forzar en cualquier sentido
     * atiende (borra) la solicitud de activación pendiente.
     */
    public function updateModule(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'module' => ['required', Rule::in(array_keys(config('modules', [])))],
            'mode' => ['required', Rule::in(['inherit', 'on', 'off'])],
        ]);

        if ($data['mode'] === 'inherit') {
            \App\Models\Central\TenantModule::query()
                ->where('tenant_id', $tenant->id)
                ->where('module', $data['module'])
                ->delete();
        } else {
            \App\Models\Central\TenantModule::updateOrCreate(
                ['tenant_id' => $tenant->id, 'module' => $data['module']],
                ['enabled' => $data['mode'] === 'on'],
            );

            \App\Models\Central\ModuleActivationRequest::query()
                ->where('tenant_id', $tenant->id)
                ->where('module', $data['module'])
                ->delete();
        }

        return back();
    }

    /**
     * Descarta una solicitud de activación sin cambiar el módulo.
     */
    public function dismissModuleRequest(Tenant $tenant, string $module): RedirectResponse
    {
        \App\Models\Central\ModuleActivationRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('module', $module)
            ->delete();

        return back();
    }

    /**
     * Suspende o reactiva el hotel (bloquea su panel sin borrar datos).
     */
    public function toggleSuspend(Tenant $tenant): RedirectResponse
    {
        $tenant->update([
            'suspended_at' => $tenant->isSuspended() ? null : now(),
        ]);

        return redirect()->route('admin.tenants.index');
    }

    /**
     * "Entrar como" (soporte): token de impersonación de un solo uso (60 s)
     * que inicia sesión como el owner del hotel en su subdominio.
     */
    public function impersonate(Tenant $tenant): \Illuminate\Http\JsonResponse
    {
        if ($tenant->isSuspended()) {
            return response()->json(['message' => 'El hotel está suspendido; reactívalo para entrar.'], 422);
        }

        $ownerId = $tenant->run(fn () => User::role('owner')->first()?->id);

        if (! $ownerId) {
            return response()->json(['message' => 'El hotel no tiene usuario propietario.'], 422);
        }

        $token = tenancy()->impersonate($tenant, (string) $ownerId, '/dashboard', 'web');
        $domain = $tenant->domains()->first()?->domain;
        $scheme = parse_url(config('app.url'), PHP_URL_SCHEME) ?: 'http';

        return response()->json(['url' => "{$scheme}://{$domain}/impersonate/{$token->token}"]);
    }

    /**
     * Elimina el tenant Y SU BASE DE DATOS (pipeline DeleteDatabase).
     */
    public function destroy(Tenant $tenant): RedirectResponse
    {
        $tenant->delete();

        return redirect()->route('admin.tenants.index');
    }

    private function domainSuffix(): string
    {
        return parse_url(config('app.url'), PHP_URL_HOST);
    }
}
