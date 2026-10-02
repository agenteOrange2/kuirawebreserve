<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePlanProspectRequest;
use App\Models\Central\AddonService;
use App\Models\Central\Plan;
use App\Models\Central\PlanProspect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class LandingController extends Controller
{
    public function __invoke(): Response
    {
        $moduleCatalog = config('modules', []);
        $groups = config('module_groups', []);

        return Inertia::render('Welcome', [
            'canRegister' => Features::enabled(Features::registration()),
            // Solo los planes anunciados: los planes a la medida
            // (public = false) existen en /admin pero no se publican.
            'plans' => Plan::query()
                ->ordered()
                ->public()
                ->get()
                ->map(fn (Plan $plan) => [
                    'key' => $plan->key,
                    'label' => $plan->label,
                    'description' => $plan->description,
                    'price_monthly' => (int) $plan->price_monthly,
                    'activation_fee' => (int) $plan->activation_fee,
                    'max_rooms' => $plan->max_rooms,
                    'max_users' => $plan->max_users,
                    'max_channels' => $plan->max_channels,
                    'modules' => collect($plan->modules ?? [])
                        ->map(fn (string $module) => [
                            'key' => $module,
                            'label' => $moduleCatalog[$module]['label'] ?? Str::headline($module),
                        ])
                        ->values(),
                    'ai_monthly_replies' => $plan->ai_monthly_replies,
                ])
                ->values(),
            'modules' => $modules = collect($moduleCatalog)
                ->filter(fn (array $module) => $module['available'] ?? true)
                ->map(fn (array $module, string $key) => [
                    'key' => $key,
                    'label' => $module['label'],
                    'description' => $module['description'],
                    'group' => isset($groups[$module['group'] ?? '']) ? $module['group'] : 'otros',
                ])
                ->values(),
            // Las familias de config/module_groups.php, en su orden y sin
            // las vacías: 25 tarjetas seguidas no se leen.
            'moduleGroups' => collect($groups)
                ->map(fn (array $group, string $key) => [
                    'key' => $key,
                    'label' => $group['label'],
                    'description' => $group['description'],
                    'icon' => $group['icon'] ?? 'Blocks',
                    'count' => $modules->where('group', $key)->count(),
                ])
                ->filter(fn (array $group) => $group['count'] > 0)
                ->values(),
            // Servicios que se contratan aparte del plan (los activos), sin precio.
            'addons' => AddonService::query()
                ->where('active', true)
                ->ordered()
                ->get()
                ->map(fn (AddonService $service) => [
                    'key' => $service->key,
                    'name' => $service->name,
                    'summary' => $service->summary,
                    // Sin precio: los servicios se cotizan en la demo.
                    'requires' => $service->requires,
                ])
                ->values(),
        ]);
    }

    public function store(StorePlanProspectRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $plan = Plan::query()->public()->findOrFail($data['plan_key']);

        PlanProspect::query()->create([
            'name' => $data['name'],
            'hotel_name' => $data['hotel_name'],
            'email' => Str::lower($data['email']),
            'phone' => $data['phone'],
            'rooms' => $data['rooms'] ?? null,
            'plan_key' => $plan->key,
            'plan_label' => $plan->label,
            'message' => $data['message'] ?? null,
            'source' => $data['source'] ?? 'landing',
            'ip_hash' => hash('sha256', (string) $request->ip()),
        ]);

        return back()->with('success', 'Solicitud recibida. Te contactaremos muy pronto.');
    }
}
