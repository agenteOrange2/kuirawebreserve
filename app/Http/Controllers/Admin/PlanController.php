<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\Plan;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de planes de la plataforma: límites, precio informativo e IA.
 * Los cambios aplican de inmediato a todos los hoteles del plan (los
 * límites se hacen cumplir vía config('plans'), hidratado desde esta tabla).
 */
class PlanController extends Controller
{
    public function index(): Response
    {
        $byPlan = Tenant::query()->get()->countBy('plan');

        return Inertia::render('admin/plans/Index', [
            'plans' => Plan::query()->ordered()->get()->map(fn (Plan $plan) => [
                'key' => $plan->key,
                'label' => $plan->label,
                'price_monthly' => (int) $plan->price_monthly,
                'max_properties' => $plan->max_properties,
                'max_rooms' => $plan->max_rooms,
                'max_users' => $plan->max_users,
                'ai_enabled' => $plan->ai_enabled,
                'ai_monthly_replies' => $plan->ai_monthly_replies,
                'active' => $plan->active,
                'tenants' => (int) ($byPlan[$plan->key] ?? 0),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, key: true);
        $data['sort_order'] = (int) (Plan::max('sort_order') + 1);

        Plan::create($data);

        return redirect()->route('admin.plans');
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $plan->update($this->validated($request, key: false));

        return redirect()->route('admin.plans');
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        if (Tenant::query()->where('plan', $plan->key)->exists()) {
            return back()->withErrors([
                'plan' => 'Hay hoteles en este plan; muévelos a otro plan o desactívalo en su lugar.',
            ]);
        }

        $plan->delete();

        return redirect()->route('admin.plans');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $key): array
    {
        return $request->validate(array_filter([
            'key' => $key ? [
                'required', 'string', 'max:40',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
                Rule::unique(Plan::class, 'key'),
            ] : null,
            'label' => ['required', 'string', 'max:60'],
            'price_monthly' => ['required', 'integer', 'min:0'],
            'max_properties' => ['nullable', 'integer', 'min:1'],
            'max_rooms' => ['nullable', 'integer', 'min:1'],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'ai_enabled' => ['boolean'],
            'ai_monthly_replies' => ['nullable', 'integer', 'min:1'],
            'active' => ['boolean'],
        ]), [
            'key.regex' => 'Solo minúsculas, números y guiones.',
            'key.unique' => 'Ya existe un plan con esa clave.',
        ]);
    }
}
