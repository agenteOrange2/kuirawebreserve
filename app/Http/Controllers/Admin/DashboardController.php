<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\PlanProspect;
use App\Models\Central\PlatformAiProvider;
use App\Models\Central\PlatformAlert;
use App\Models\Central\TenantAgentSetting;
use App\Models\Central\TenantAiUsage;
use App\Models\Tenant;
use App\Services\Agent\PlatformAgentGate;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard del panel de plataforma: cómo va el negocio — cuántos hoteles
 * pagan y cuánto, cómo se reparte por plan, cuánto cuesta la IA (la base
 * de costos) y qué pide atención hoy.
 */
class DashboardController extends Controller
{
    /** Días de la gráfica de actividad del bot. */
    public const ACTIVITY_DAYS = 30;

    /** Meses de la gráfica de crecimiento. */
    public const GROWTH_MONTHS = 6;

    public function __invoke(): Response
    {
        $tenants = Tenant::query()->with('domains')->get();
        $names = $tenants->mapWithKeys(fn (Tenant $t) => [$t->id => $t->name ?: $t->id]);
        $plans = config('plans');
        $monthStart = now()->startOfMonth();

        // Lo que paga cada hotel este mes (plan + servicios adicionales).
        // Una vez por hotel: monthlyPrice() consulta sus servicios.
        $price = $tenants->mapWithKeys(fn (Tenant $t) => [$t->id => $t->monthlyPrice()]);
        $active = $tenants->reject(fn (Tenant $t) => $t->isSuspended());

        // Rollup central de IA del mes, agrupado por tenant.
        $usage = TenantAiUsage::query()
            ->where('date', '>=', $monthStart->toDateString())
            ->selectRaw('tenant_id')
            ->selectRaw('SUM(replies) as replies')
            ->selectRaw('SUM(prompt_tokens) as prompt_tokens')
            ->selectRaw('SUM(completion_tokens) as completion_tokens')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        // El mismo tramo del mes pasado (del 1 al día de hoy): comparar el
        // mes en curso contra el mes pasado entero siempre sale "a la baja".
        $prevStart = $monthStart->copy()->subMonthNoOverflow();
        $prevEnd = $prevStart->copy()->addDays(now()->day - 1)->min($monthStart->copy()->subDay());
        $repliesPrev = (int) TenantAiUsage::query()
            ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
            ->sum('replies');

        $settings = TenantAgentSetting::query()->with('provider')->get()->keyBy('tenant_id');

        // Hoteles con IA en el plan (o con consumo este mes), ordenados por
        // consumo: los que más cuestan primero.
        $aiTenants = $tenants
            ->map(function (Tenant $tenant) use ($plans, $usage, $settings) {
                $planAi = $plans[$tenant->plan]['ai'] ?? ['enabled' => false];
                $setting = $settings->get($tenant->id);
                $used = (int) ($usage->get($tenant->id)?->replies ?? 0);
                // Misma cuota que aplica el bot: plan + servicios con IA.
                $limit = $setting?->monthly_reply_limit ?? PlatformAgentGate::defaultLimit($tenant);

                if (! ($planAi['enabled'] ?? false) && ! $limit && $used === 0) {
                    return null;
                }

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name ?: $tenant->id,
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

        return Inertia::render('admin/Dashboard', [
            'stats' => [
                'tenants' => $tenants->count(),
                'active' => $active->count(),
                'suspended' => $tenants->count() - $active->count(),
                'new_month' => $tenants->filter(fn (Tenant $t) => $t->created_at?->gte($monthStart))->count(),
                'mrr' => (int) $active->sum(fn (Tenant $t) => $price[$t->id]),
                'ai_replies_month' => (int) $usage->sum('replies'),
                'ai_replies_prev' => $repliesPrev,
                'ai_tokens_month' => (int) ($usage->sum('prompt_tokens') + $usage->sum('completion_tokens')),
                'ai_keys_active' => PlatformAiProvider::query()->active()->count(),
                'ai_keys_total' => PlatformAiProvider::query()->count(),
                'prospects_new' => PlanProspect::query()->where('status', 'new')->count(),
            ],
            'monthLabel' => now()->translatedFormat('F Y'),
            'activity' => $this->activity($names->all()),
            'plans' => $this->planBreakdown($tenants, $price->all(), $plans),
            'growth' => $this->growth(),
            'aiTenants' => $aiTenants,
            'alerts' => PlatformAlert::query()->open()
                ->whereIn('severity', ['danger', 'warning'])
                ->orderByRaw("CASE severity WHEN 'danger' THEN 0 ELSE 1 END")
                ->orderByDesc('first_seen_at')
                ->take(4)
                ->get()
                ->map(fn (PlatformAlert $a) => [
                    'id' => $a->id,
                    'severity' => $a->severity,
                    'title' => $a->title,
                    'tenant' => $a->tenant_id ? ($names[$a->tenant_id] ?? $a->tenant_id) : null,
                    'url' => $a->url,
                    'ago' => $a->first_seen_at?->diffForHumans(),
                ]),
            'alertCounts' => [
                'danger' => PlatformAlert::query()->open()->where('severity', 'danger')->count(),
                'warning' => PlatformAlert::query()->open()->where('severity', 'warning')->count(),
            ],
            'recentTenants' => $tenants->sortByDesc('created_at')->take(5)->values()->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name ?: $tenant->id,
                'plan_label' => $plans[$tenant->plan]['label'] ?? $tenant->plan,
                'suspended' => $tenant->isSuspended(),
                'domain' => $tenant->domains->first()?->domain,
                'price' => (int) $price[$tenant->id],
                'created_ago' => $tenant->created_at?->diffForHumans(),
                'created_at' => $tenant->created_at?->format('d/m/Y'),
            ]),
        ]);
    }

    /**
     * Respuestas del bot por día, con el desglose por hotel de cada día
     * (lo abre el modal al tocar una barra). Días sin uso van en cero.
     *
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
    protected function activity(array $names): array
    {
        $rows = TenantAiUsage::query()
            ->where('date', '>=', now()->subDays(self::ACTIVITY_DAYS - 1)->toDateString())
            ->get(['tenant_id', 'date', 'replies'])
            ->groupBy(fn ($row) => Carbon::parse($row->date)->toDateString());

        return collect(range(self::ACTIVITY_DAYS - 1, 0))->map(function (int $daysAgo) use ($rows, $names) {
            $date = now()->subDays($daysAgo);
            $day = $rows->get($date->toDateString(), collect());

            return [
                'date' => $date->toDateString(),
                'label' => $date->format('d/m'),
                'long' => $date->locale('es')->isoFormat('dddd D [de] MMMM'),
                'replies' => (int) $day->sum('replies'),
                'by_tenant' => $day->groupBy('tenant_id')
                    ->map(fn ($r, $id) => ['name' => $names[$id] ?? $id, 'replies' => (int) $r->sum('replies')])
                    ->sortByDesc('replies')->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * Hoteles e ingreso por plan. Solo planes con hoteles: la dona no
     * enseña rebanadas vacías de planes privados que nadie usa.
     *
     * @param  array<string, int>  $price
     * @param  array<string, array<string, mixed>>  $plans
     * @return list<array<string, mixed>>
     */
    protected function planBreakdown($tenants, array $price, array $plans): array
    {
        return $tenants->groupBy('plan')
            ->map(fn ($group, $key) => [
                'key' => $key,
                'label' => $plans[$key]['label'] ?? $key,
                'public' => (bool) ($plans[$key]['public'] ?? true),
                'count' => $group->count(),
                // El ingreso cuenta solo hoteles activos (un suspendido no paga).
                'mrr' => (int) $group->reject(fn (Tenant $t) => $t->isSuspended())->sum(fn (Tenant $t) => $price[$t->id]),
                'tenants' => $group->sortBy('name')->values()->map(fn (Tenant $t) => [
                    'id' => $t->id,
                    'name' => $t->name ?: $t->id,
                    'suspended' => $t->isSuspended(),
                    'price' => (int) $price[$t->id],
                ])->all(),
            ])
            ->sortByDesc('mrr')
            ->values()
            ->all();
    }

    /**
     * Altas de hoteles y registros de prospectos por mes.
     *
     * @return list<array{month: string, label: string, tenants: int, prospects: int}>
     */
    protected function growth(): array
    {
        $from = now()->startOfMonth()->subMonths(self::GROWTH_MONTHS - 1);
        $count = fn (string $model) => $model::query()
            ->where('created_at', '>=', $from)
            ->get(['created_at'])
            ->countBy(fn ($row) => $row->created_at->format('Y-m'));
        $tenants = $count(Tenant::class);
        $prospects = $count(PlanProspect::class);

        return collect(range(0, self::GROWTH_MONTHS - 1))->map(function (int $i) use ($from, $tenants, $prospects) {
            $month = $from->copy()->addMonths($i);

            return [
                'month' => $month->format('Y-m'),
                'label' => ucfirst($month->locale('es')->isoFormat('MMM YY')),
                'tenants' => (int) ($tenants[$month->format('Y-m')] ?? 0),
                'prospects' => (int) ($prospects[$month->format('Y-m')] ?? 0),
            ];
        })->all();
    }
}
