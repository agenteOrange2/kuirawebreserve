<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\PlatformAiProvider;
use App\Models\Central\TenantAgentSetting;
use App\Models\Central\TenantAiUsage;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard del panel de plataforma: visión global del negocio — hoteles,
 * distribución por plan y consumo de IA (la base de costos) por tenant.
 */
class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $tenants = Tenant::query()->with('domains')->get();
        $plans = config('plans');
        $monthStart = now()->startOfMonth()->toDateString();

        // Rollup central de IA del mes, agrupado por tenant.
        $usage = TenantAiUsage::query()
            ->where('date', '>=', $monthStart)
            ->selectRaw('tenant_id')
            ->selectRaw('SUM(replies) as replies')
            ->selectRaw('SUM(prompt_tokens) as prompt_tokens')
            ->selectRaw('SUM(completion_tokens) as completion_tokens')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $settings = TenantAgentSetting::query()->with('provider')->get()->keyBy('tenant_id');

        // Hoteles con IA en el plan (o con consumo histórico este mes),
        // ordenados por consumo: los que más cuestan primero.
        $aiTenants = $tenants
            ->map(function (Tenant $tenant) use ($plans, $usage, $settings) {
                $planAi = $plans[$tenant->plan]['ai'] ?? ['enabled' => false, 'monthly_replies' => 0];
                $setting = $settings->get($tenant->id);
                $used = (int) ($usage->get($tenant->id)?->replies ?? 0);

                if (! $planAi['enabled'] && $used === 0) {
                    return null;
                }

                $limit = $setting?->monthly_reply_limit ?? $planAi['monthly_replies'] ?? null;

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'plan' => $tenant->plan,
                    'plan_label' => $plans[$tenant->plan]['label'] ?? $tenant->plan,
                    'suspended' => $tenant->isSuspended(),
                    'enabled' => $setting?->enabled ?? true,
                    'provider_label' => $setting?->provider?->label(),
                    'used' => $used,
                    'limit' => $limit ? (int) $limit : null,
                    'prompt_tokens' => (int) ($usage->get($tenant->id)?->prompt_tokens ?? 0),
                    'completion_tokens' => (int) ($usage->get($tenant->id)?->completion_tokens ?? 0),
                ];
            })
            ->filter()
            ->sortByDesc('used')
            ->values();

        // Actividad diaria del bot (14 días) para la gráfica, con días en cero.
        $daily = TenantAiUsage::query()
            ->where('date', '>=', now()->subDays(13)->toDateString())
            ->selectRaw('date, SUM(replies) as replies')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => $row->date->toDateString());

        $activity = collect(range(13, 0))->map(function (int $daysAgo) use ($daily) {
            $date = now()->subDays($daysAgo);

            return [
                'date' => $date->format('d/m'),
                'replies' => (int) ($daily->get($date->toDateString())?->replies ?? 0),
            ];
        })->values();

        // Distribución por plan (todos los planes del catálogo, aunque estén en 0).
        $byPlan = $tenants->countBy('plan');
        $planDistribution = collect($plans)->map(fn (array $plan, string $key) => [
            'value' => $key,
            'label' => $plan['label'],
            'count' => (int) ($byPlan[$key] ?? 0),
            'ai' => (bool) ($plan['ai']['enabled'] ?? false),
        ])->values();

        return Inertia::render('admin/Dashboard', [
            'stats' => [
                'tenants' => $tenants->count(),
                'active' => $tenants->reject(fn (Tenant $t) => $t->isSuspended())->count(),
                'suspended' => $tenants->filter(fn (Tenant $t) => $t->isSuspended())->count(),
                'users' => User::count(),
                'ai_replies_month' => (int) $usage->sum('replies'),
                'ai_tokens_month' => (int) ($usage->sum('prompt_tokens') + $usage->sum('completion_tokens')),
                'ai_keys_active' => PlatformAiProvider::query()->active()->count(),
                'ai_keys_total' => PlatformAiProvider::query()->count(),
            ],
            'monthLabel' => now()->translatedFormat('F Y'),
            'activity' => $activity,
            'planDistribution' => $planDistribution,
            'aiTenants' => $aiTenants,
            'recentTenants' => $tenants->sortByDesc('created_at')->take(6)->values()->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'plan_label' => $plans[$tenant->plan]['label'] ?? $tenant->plan,
                'suspended' => $tenant->isSuspended(),
                'domain' => $tenant->domains->first()?->domain,
                'created_at' => $tenant->created_at?->format('d/m/Y'),
            ]),
        ]);
    }
}
