<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\AddonService;
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
        $contracted = TenantAddonService::query()
            ->get(['tenant_id', 'addon_service_key'])
            ->groupBy('addon_service_key');
        $tenants = Tenant::query()->orderBy('name')->get();

        return Inertia::render('admin/services/Index', [
            'services' => AddonService::query()->ordered()->get()
                ->map(fn (AddonService $service) => [
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
                ]),
            'tenants' => $tenants->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'plan' => $tenant->plan,
                'plan_label' => config("plans.{$tenant->plan}.label", $tenant->plan),
            ])->values(),
            'stats' => [
                'mrr_addons' => (int) TenantAddonService::query()->get()
                    ->sum(fn (TenantAddonService $row) => (int) AddonService::find($row->addon_service_key)?->price_monthly),
                'contracts' => TenantAddonService::query()->count(),
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
