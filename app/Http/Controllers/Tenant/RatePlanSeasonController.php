<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\RatePlan;
use App\Models\RatePlanSeason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Temporadas y promos de una tarifa (spec-motor-reservas-web E0.5): rangos
 * de fechas con un precio que sustituye el de la tarifa mientras estén
 * vigentes. Anidado bajo su rate plan — no tienen sentido sueltas.
 */
class RatePlanSeasonController extends Controller
{
    public function index(RatePlan $ratePlan): JsonResponse
    {
        return response()->json(
            $ratePlan->seasons()->orderByDesc('starts_on')->get()->map(fn (RatePlanSeason $s) => $this->serialize($s))
        );
    }

    public function store(Request $request, RatePlan $ratePlan): JsonResponse
    {
        $data = $this->validated($request);
        // Los defaults de columna (kind/priority/active) viven en la DB pero
        // Eloquent no los refleja de vuelta en el modelo recién creado si no
        // vinieron en $data.
        $data['kind'] ??= RatePlanSeason::KIND_SEASON;
        $data['priority'] ??= 0;
        $data['active'] ??= true;
        $season = $ratePlan->seasons()->create($data);

        return response()->json($this->serialize($season), 201);
    }

    public function update(Request $request, RatePlan $ratePlan, RatePlanSeason $season): JsonResponse
    {
        abort_unless($season->rate_plan_id === $ratePlan->id, 404);

        $season->update($this->validated($request, $season));

        return response()->json($this->serialize($season->refresh()));
    }

    public function destroy(RatePlan $ratePlan, RatePlanSeason $season): JsonResponse
    {
        abort_unless($season->rate_plan_id === $ratePlan->id, 404);

        $season->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?RatePlanSeason $season = null): array
    {
        return $request->validate([
            'name' => [$season ? 'sometimes' : 'required', 'string', 'max:255'],
            'kind' => ['sometimes', Rule::in([RatePlanSeason::KIND_SEASON, RatePlanSeason::KIND_PROMO])],
            'starts_on' => [$season ? 'sometimes' : 'required', 'date'],
            'ends_on' => [$season ? 'sometimes' : 'required', 'date', 'after_or_equal:starts_on'],
            'price' => [$season ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(RatePlanSeason $season): array
    {
        return [
            'id' => $season->id,
            'rate_plan_id' => $season->rate_plan_id,
            'name' => $season->name,
            'kind' => $season->kind,
            'starts_on' => $season->starts_on->toDateString(),
            'ends_on' => $season->ends_on->toDateString(),
            'price' => $season->price,
            'priority' => $season->priority,
            'active' => $season->active,
        ];
    }
}
