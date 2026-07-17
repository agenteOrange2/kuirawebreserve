<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\RateDurationUnit;
use App\Enums\RatePlanType;
use App\Http\Controllers\Controller;
use App\Models\RatePlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RatePlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            RatePlan::query()
                ->with('roomType:id,name')
                ->withCount('seasons')
                ->when($request->integer('room_type_id'), fn ($q, $id) => $q->where('room_type_id', $id))
                ->when($request->boolean('active_only'), fn ($q) => $q->where('active', true))
                ->get()
                ->map(fn (RatePlan $plan) => $this->serialize($plan))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        return response()->json(
            $this->serialize(RatePlan::create($data)->load('roomType:id,name')->loadCount('seasons')),
            201,
        );
    }

    public function update(Request $request, RatePlan $ratePlan): JsonResponse
    {
        $ratePlan->update($this->validated($request, $ratePlan));

        return response()->json($this->serialize($ratePlan->refresh()->load('roomType:id,name')->loadCount('seasons')));
    }

    public function destroy(RatePlan $ratePlan): JsonResponse
    {
        if ($ratePlan->reservations()->exists()) {
            // Con historial no se borra: se desactiva.
            $ratePlan->update(['active' => false]);

            return response()->json($this->serialize($ratePlan->load('roomType:id,name')));
        }

        $ratePlan->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?RatePlan $ratePlan = null): array
    {
        $data = $request->validate([
            'property_id' => [$ratePlan ? 'sometimes' : 'required', 'exists:properties,id'],
            'room_type_id' => [
                $ratePlan ? 'sometimes' : 'required',
                Rule::exists('room_types', 'id')->where(
                    'property_id',
                    $request->integer('property_id') ?: $ratePlan?->property_id,
                ),
            ],
            'name' => [$ratePlan ? 'sometimes' : 'required', 'string', 'max:255'],
            'type' => [$ratePlan ? 'sometimes' : 'required', Rule::enum(RatePlanType::class)],
            'duration_unit' => ['required_if:type,block', 'nullable', Rule::enum(RateDurationUnit::class)],
            'duration_value' => ['required_if:type,block', 'nullable', 'integer', 'min:1', 'max:1440'],
            'price' => [$ratePlan ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'min_advance_unit' => [
                'nullable',
                'required_with:min_advance_value',
                Rule::in(array_map(fn (RateDurationUnit $unit) => $unit->value, RateDurationUnit::advanceUnits())),
            ],
            'min_advance_value' => ['nullable', 'required_with:min_advance_unit', 'integer', 'min:1', 'max:365'],
            'deposit_percent' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'payment_due_unit' => [
                'nullable',
                'required_with:payment_due_value',
                Rule::in(array_map(fn (RateDurationUnit $unit) => $unit->value, RateDurationUnit::advanceUnits())),
            ],
            'payment_due_value' => ['nullable', 'required_with:payment_due_unit', 'integer', 'min:1', 'max:365'],
            // Política de cancelación con dinero (spec-pagos F4): sin costo
            // hasta X antes de llegar; después se retiene el % de lo pagado.
            'cancel_free_unit' => [
                'nullable',
                'required_with:cancel_free_value',
                Rule::in(array_map(fn (RateDurationUnit $unit) => $unit->value, RateDurationUnit::advanceUnits())),
            ],
            'cancel_free_value' => ['nullable', 'required_with:cancel_free_unit', 'integer', 'min:1', 'max:365'],
            'cancel_penalty_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'duration_unit.required_if' => 'Las tarifas por periodo necesitan una unidad de duración.',
            'duration_value.required_if' => 'Las tarifas por periodo necesitan la duración.',
        ]);

        $type = RatePlanType::tryFrom($data['type'] ?? '') ?? $ratePlan?->type;

        if ($type === RatePlanType::Night) {
            $data['duration_unit'] = null;
            $data['duration_value'] = null;
            $data['duration_minutes'] = null;
        } elseif (array_key_exists('duration_unit', $data)) {
            // Derivado en minutos para unidades exactas (compat con fase 2);
            // los meses son calendario y no tienen minutos fijos.
            $unit = RateDurationUnit::from($data['duration_unit']);
            $minutes = $unit->minutes();
            $data['duration_minutes'] = $minutes !== null
                ? $minutes * (int) $data['duration_value']
                : null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(RatePlan $plan): array
    {
        return [
            'id' => $plan->id,
            'room_type_id' => $plan->room_type_id,
            'room_type' => $plan->roomType?->name,
            'name' => $plan->name,
            'type' => $plan->type->value,
            'duration_unit' => $plan->duration_unit?->value,
            'duration_value' => $plan->duration_value,
            'duration_minutes' => $plan->duration_minutes,
            'duration_label' => $plan->durationLabel(),
            'price' => $plan->price,
            'min_advance_unit' => $plan->min_advance_unit?->value,
            'min_advance_value' => $plan->min_advance_value,
            'min_advance_label' => $plan->minAdvanceLabel(),
            'deposit_percent' => $plan->deposit_percent,
            'payment_due_unit' => $plan->payment_due_unit?->value,
            'payment_due_value' => $plan->payment_due_value,
            'payment_due_label' => $plan->paymentDueLabel(),
            'cancel_free_unit' => $plan->cancel_free_unit?->value,
            'cancel_free_value' => $plan->cancel_free_value,
            'cancel_penalty_percent' => $plan->cancel_penalty_percent,
            'cancellation_policy_label' => $plan->cancellationPolicyLabel(),
            'active' => $plan->active,
            'seasons_count' => $plan->seasons_count ?? $plan->seasons()->count(),
        ];
    }
}
