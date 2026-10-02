<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\AddonService;
use App\Models\Central\AdminActivity;
use App\Models\Central\TenantAddonService;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Servicios adicionales de la plataforma: catálogo (precios, módulos que
 * encienden) y contratación por hotel. Se cobran POR ENCIMA del plan base;
 * los módulos que aportan aplican al instante vía Tenant::hasModule().
 */
class AddonServiceController extends Controller
{
    public function index(): Response
    {
        $services = AddonService::query()->ordered()->get();
        $contracted = TenantAddonService::query()
            ->get(['tenant_id', 'addon_service_key'])
            ->toBase()
            ->groupBy('addon_service_key');
        $tenants = Tenant::query()->orderBy('name')->get();

        // Último cambio de cada servicio según la bitácora del admin: una
        // sola consulta (el renglón más nuevo por servicio).
        $lastChange = AdminActivity::query()
            ->with('user:id,name')
            ->whereIn('id', AdminActivity::query()
                ->selectRaw('MAX(id)')
                ->where('subject_type', 'service')
                ->groupBy('subject_id'))
            ->get()
            ->keyBy('subject_id');

        // Solo cuentan las contrataciones de servicios activos: un servicio
        // fuera del catálogo ni enciende módulos ni se cobra
        // (Tenant::addonServices() filtra igual).
        $activeKeys = $services->where('active', true)->pluck('key');
        $billable = $contracted->only($activeKeys->all())->flatten(1);

        return Inertia::render('admin/services/Index', [
            'services' => $services->map(fn (AddonService $service) => [
                'key' => $service->key,
                'name' => $service->name,
                'summary' => $service->summary,
                'objective' => $service->objective,
                'recommendation' => $service->recommendation,
                'price_monthly' => (int) $service->price_monthly,
                'activation_fee' => (int) $service->activation_fee,
                // Lo que el servicio le enciende al hotel, en lenguaje de
                // catálogo (solo lectura: el mapeo es cableado interno).
                'includes' => collect($service->modules ?? [])
                    ->map(fn (string $key) => [
                        'label' => config("modules.{$key}.label", $key),
                        'available' => (bool) config("modules.{$key}.available", true),
                    ])->values(),
                'ai_monthly_replies' => $service->ai_monthly_replies,
                'requires' => $service->requires,
                'active' => $service->active,
                'tenants' => $contracted->get($service->key)?->pluck('tenant_id')->values() ?? [],
                'last_change' => ($row = $lastChange->get($service->key)) ? [
                    'ago' => $row->created_at?->diffForHumans(),
                    'at' => $row->created_at?->format('d/m/Y H:i'),
                    'by' => $row->user?->name,
                    'by_id' => $row->user_id,
                ] : null,
            ]),
            'tenants' => $tenants->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'plan' => $tenant->plan,
                'plan_label' => config("plans.{$tenant->plan}.label", $tenant->plan),
                'suspended' => $tenant->isSuspended(),
            ])->values(),
            'stats' => [
                'mrr_addons' => (int) $billable
                    ->sum(fn (TenantAddonService $row) => (int) $services->firstWhere('key', $row->addon_service_key)?->price_monthly),
                'contracts' => $billable->count(),
                'tenants_with_addons' => $billable->pluck('tenant_id')->unique()->count(),
            ],
        ]);
    }

    public function update(Request $request, AddonService $addonService): RedirectResponse
    {
        // El mapeo servicio→módulos, la cuota IA y los prerrequisitos son
        // cableado interno (semilla de la migración): no se editan desde la
        // UI para no confundir servicios con módulos.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'objective' => ['nullable', 'string', 'max:1000'],
            'recommendation' => ['nullable', 'string', 'max:1000'],
            'price_monthly' => ['required', 'integer', 'min:0'],
            'activation_fee' => ['required', 'integer', 'min:0'],
            'active' => ['boolean'],
        ]);

        $addonService->update($data);

        return redirect()->route('admin.services');
    }

    /**
     * Contratar o retirar un servicio para un hotel (el equivalente de los
     * overrides de módulos, pero a nivel servicio: con precio).
     */
    public function updateTenant(Request $request, Tenant $tenant, AddonService $addonService): RedirectResponse
    {
        $data = $request->validate([
            'contracted' => ['required', 'boolean'],
        ]);

        if ($data['contracted']) {
            if (! $addonService->active) {
                return back()->withErrors([
                    'service' => 'Este servicio está fuera del catálogo: actívalo antes de contratarlo a un hotel.',
                ]);
            }

            if ($addonService->requires && ! TenantAddonService::query()
                ->where('tenant_id', $tenant->id)
                ->where('addon_service_key', $addonService->requires)
                ->exists()) {
                $requiredName = AddonService::find($addonService->requires)?->name ?? $addonService->requires;

                return back()->withErrors([
                    'service' => "Este servicio amplía otro: contrata primero \"{$requiredName}\".",
                ]);
            }

            TenantAddonService::firstOrCreate([
                'tenant_id' => $tenant->id,
                'addon_service_key' => $addonService->key,
            ]);
        } else {
            // Al retirar un servicio caen también los que lo requerían.
            TenantAddonService::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('addon_service_key', [
                    $addonService->key,
                    ...AddonService::query()->where('requires', $addonService->key)->pluck('key'),
                ])
                ->delete();
        }

        return back();
    }
}
