<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\RatePlan;
use App\Models\RatePlanSeason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Temporadas y promos de una tarifa (spec-motor-reservas-web E0.5): rangos
 * de fechas con un precio que sustituye el de la tarifa mientras estén
 * vigentes, acotables por día de la semana (weekdays). Sin fechas +
 * weekdays = regla recurrente todo el año ("todos los viernes y sábados").
 * Anidado bajo su rate plan — no tienen sentido sueltas.
 */
class RatePlanSeasonController extends Controller
{
    public function index(RatePlan $ratePlan): JsonResponse
    {
        return response()->json(
            $ratePlan->seasons()
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->get()
                ->map(fn (RatePlanSeason $s) => $this->serialize($s))
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
     * Fechas y días de la semana: las fechas van en pareja (o ninguna) y
     * una temporada sin fechas necesita weekdays — sin lo uno ni lo otro
     * sería un "siempre" que pisaría el precio base todo el año sin que se
     * note por qué.
     *
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?RatePlanSeason $season = null): array
    {
        $data = $request->validate([
            'name' => [$season ? 'sometimes' : 'required', 'string', 'max:255'],
            'kind' => ['sometimes', Rule::in([RatePlanSeason::KIND_SEASON, RatePlanSeason::KIND_PROMO])],
            'starts_on' => ['sometimes', 'nullable', 'date', 'required_with:ends_on'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on', 'required_with:starts_on'],
            'weekdays' => ['sometimes', 'nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:0,6', 'distinct'],
            'price' => [$season ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'starts_on.required_with' => 'Captura ambas fechas o deja las dos vacías.',
            'ends_on.required_with' => 'Captura ambas fechas o deja las dos vacías.',
            'weekdays.*.between' => 'Los días van de 0 (domingo) a 6 (sábado).',
        ]);

        // Sin días marcados = toda la semana (null), igual que siempre.
        if (array_key_exists('weekdays', $data)) {
            $weekdays = collect($data['weekdays'] ?? [])
                ->map(fn ($day) => (int) $day)
                ->unique()
                ->sort()
                ->values()
                ->all();
            $data['weekdays'] = $weekdays === [] ? null : $weekdays;
        }

        // Estado RESULTANTE (payload + lo ya guardado en update): las fechas
        // van en pareja, y debe quedar rango o días de la semana; ambos
        // vacíos no es una regla.
        $startsOn = array_key_exists('starts_on', $data) ? $data['starts_on'] : $season?->starts_on;
        $endsOn = array_key_exists('ends_on', $data) ? $data['ends_on'] : $season?->ends_on;
        $weekdays = array_key_exists('weekdays', $data) ? $data['weekdays'] : $season?->weekdays;

        if (($startsOn === null) !== ($endsOn === null)) {
            throw ValidationException::withMessages([
                'ends_on' => 'Captura ambas fechas o deja las dos vacías.',
            ]);
        }

        if ($startsOn === null && empty($weekdays)) {
            throw ValidationException::withMessages([
                'starts_on' => 'Captura un rango de fechas, marca días de la semana, o ambos.',
            ]);
        }

        return $data;
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
            'starts_on' => $season->starts_on?->toDateString(),
            'ends_on' => $season->ends_on?->toDateString(),
            'weekdays' => $season->weekdays,
            'price' => $season->price,
            'priority' => $season->priority,
            'active' => $season->active,
        ];
    }
}
