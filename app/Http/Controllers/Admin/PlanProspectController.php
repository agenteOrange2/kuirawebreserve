<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterPlanProspectsRequest;
use App\Http\Requests\UpdatePlanProspectRequest;
use App\Models\Central\Plan;
use App\Models\Central\PlanProspect;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PlanProspectController extends Controller
{
    public function index(FilterPlanProspectsRequest $request): Response
    {
        $filters = $request->validated();

        $prospects = PlanProspect::query()
            ->with('plan:key,label')
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('hotel_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when(
                isset($filters['status']) && $filters['status'] !== 'all',
                fn ($query) => $query->where('status', $filters['status']),
            )
            ->when($filters['plan'] ?? null, fn ($query, string $plan) => $query->where('plan_key', $plan))
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (PlanProspect $prospect) => [
                'id' => $prospect->id,
                'name' => $prospect->name,
                'hotel_name' => $prospect->hotel_name,
                'email' => $prospect->email,
                'phone' => $prospect->phone,
                'rooms' => $prospect->rooms,
                'plan_key' => $prospect->plan_key,
                'plan_label' => $prospect->plan?->label ?? $prospect->plan_label,
                'message' => $prospect->message,
                'status' => $prospect->status,
                'notes' => $prospect->notes,
                'source' => $prospect->source,
                'contacted_at' => $prospect->contacted_at?->format('d/m/Y H:i'),
                'created_at' => $prospect->created_at?->format('d/m/Y H:i'),
            ]);

        $statusCounts = PlanProspect::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('admin/prospects/Index', [
            'prospects' => $prospects,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? 'all',
                'plan' => $filters['plan'] ?? '',
            ],
            'stats' => [
                'total' => PlanProspect::query()->count(),
                'new' => (int) ($statusCounts['new'] ?? 0),
                'qualified' => (int) ($statusCounts['qualified'] ?? 0),
                'won' => (int) ($statusCounts['won'] ?? 0),
            ],
            'plans' => Plan::query()->ordered()->get(['key', 'label']),
        ]);
    }

    public function update(UpdatePlanProspectRequest $request, PlanProspect $planProspect): RedirectResponse
    {
        $data = $request->validated();

        if ($data['status'] !== 'new' && $planProspect->contacted_at === null) {
            $data['contacted_at'] = now();
        }

        $planProspect->update($data);

        return back()->with('success', 'Prospecto actualizado.');
    }
}
