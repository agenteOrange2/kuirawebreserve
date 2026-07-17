<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Experience;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catálogo de experiencias (tours/recorridos con horario y cupo propios).
 * Las reservas ya hechas conservan su total congelado: cambiar el precio
 * aquí solo afecta reservas futuras.
 */
class ExperienceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $experience = Experience::create([
            ...$data,
            'property_id' => Property::firstOrFail()->id,
        ]);

        return response()->json(self::serialize($experience), 201);
    }

    public function update(Request $request, Experience $experience, \App\Actions\Experiences\GenerateExperienceSessions $generator): JsonResponse
    {
        $data = $request->validate($this->rules(partial: true));

        if (isset($data['min_people'], $data['max_people']) && $data['max_people'] !== null && $data['max_people'] < $data['min_people']) {
            return response()->json(['message' => 'El máximo de personas no puede ser menor que el mínimo.'], 422);
        }

        $experience->update($data);

        // Días de operación o disponibilidad cambiados: la programación
        // semanal se materializa/poda en caliente.
        if ($experience->wasChanged(['operating_days', 'active'])) {
            $generator->handle($experience);
        }

        return response()->json(self::serialize($experience->fresh()));
    }

    public function destroy(Experience $experience): JsonResponse
    {
        // Sesiones y reservas caen en cascada: borrar una experiencia con
        // reservas vivas es decisión explícita del hotel (la UI lo advierte).
        $experience->delete();

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    protected function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$presence, 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'includes' => ['sometimes', 'array', 'max:15'],
            'includes.*' => ['string', 'max:120'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:10080'],
            'pricing_mode' => [$presence, Rule::in(array_keys(Experience::PRICING_MODES))],
            'price' => [$presence, 'numeric', 'min:0.01', 'max:999999'],
            'min_people' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'max_people' => ['nullable', 'integer', 'min:1', 'max:500'],
            // Días ISO (1=lunes ... 7=domingo) en que opera el tour; null o
            // vacío = sin programación semanal (solo sesiones manuales).
            'operating_days' => ['sometimes', 'nullable', 'array', 'max:7'],
            'operating_days.*' => ['integer', 'between:1,7', 'distinct'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }

    /** @return array<string, mixed> */
    public static function serialize(Experience $experience): array
    {
        return [
            'id' => $experience->id,
            'name' => $experience->name,
            'description' => $experience->description,
            'includes' => $experience->includes ?? [],
            'duration_minutes' => $experience->duration_minutes,
            'duration_label' => $experience->durationLabel(),
            'pricing_mode' => $experience->pricing_mode,
            'price' => (float) $experience->price,
            'price_label' => $experience->priceLabel(),
            'min_people' => $experience->min_people,
            'max_people' => $experience->max_people,
            'operating_days' => $experience->operating_days ?? [],
            'active' => $experience->active,
            'sort_order' => $experience->sort_order,
            'photos' => $experience->photosPayload(),
        ];
    }
}
