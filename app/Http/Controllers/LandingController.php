<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePlanProspectRequest;
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

        return Inertia::render('Welcome', [
            'canRegister' => Features::enabled(Features::registration()),
            'plans' => Plan::query()
                ->ordered()
                ->where('active', true)
                ->get()
                ->map(fn (Plan $plan) => [
                    'key' => $plan->key,
                    'label' => $plan->label,
                    'description' => $plan->description,
                    'price_monthly' => (int) $plan->price_monthly,
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
            'modules' => collect($moduleCatalog)
                ->filter(fn (array $module) => $module['available'] ?? true)
                ->map(fn (array $module, string $key) => [
                    'key' => $key,
                    'label' => $module['label'],
                    'description' => $module['description'],
                ])
                ->values(),
        ]);
    }

    public function store(StorePlanProspectRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $plan = Plan::query()->findOrFail($data['plan_key']);

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
