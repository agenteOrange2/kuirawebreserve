<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Experiences\GenerateExperienceSessions;
use App\Http\Controllers\Controller;
use App\Models\ExperienceSlot;
use App\Models\ExperienceVehicle;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Flota para experiencias (razer, camioneta, cuatrimoto...) con su
 * capacidad. Es catálogo de la propiedad: los horarios de cualquier
 * experiencia asignan estos vehículos y el cupo de las sesiones futuras
 * se regenera al momento; lo ya vendido nunca baja de cupo.
 */
class ExperienceVehicleController extends Controller
{
    public function store(Request $request, GenerateExperienceSessions $generator): JsonResponse
    {
        $data = $request->validate($this->rules());

        $vehicle = ExperienceVehicle::create([
            ...$data,
            'property_id' => Property::firstOrFail()->id,
        ]);

        $generator->handle();

        return response()->json(self::serialize($vehicle), 201);
    }

    public function update(Request $request, ExperienceVehicle $vehicle, GenerateExperienceSessions $generator): JsonResponse
    {
        $data = $request->validate($this->rules(partial: true));

        $vehicle->update($data);

        // La capacidad o el estado del vehículo cambian el cupo de todos
        // los horarios que lo usan: regenerar en caliente.
        $generator->handle();

        return response()->json(self::serialize($vehicle->fresh()));
    }

    public function destroy(ExperienceVehicle $vehicle, GenerateExperienceSessions $generator): JsonResponse
    {
        // Sacarlo de los horarios que lo tenían asignado — un id muerto en
        // vehicle_ids no truena (effectiveCapacity lo ignora), pero dejaría
        // basura en la UI.
        ExperienceSlot::query()
            ->whereJsonContains('vehicle_ids', $vehicle->id)
            ->get()
            ->each(function (ExperienceSlot $slot) use ($vehicle) {
                $slot->update([
                    'vehicle_ids' => array_values(array_diff($slot->vehicle_ids ?? [], [$vehicle->id])),
                ]);
            });

        $vehicle->delete();

        $generator->handle();

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    protected function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$presence, 'string', 'max:100'],
            'capacity' => [$presence, 'integer', 'min:1', 'max:100'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }

    /** @return array<string, mixed> */
    public static function serialize(ExperienceVehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'name' => $vehicle->name,
            'capacity' => $vehicle->capacity,
            'active' => $vehicle->active,
            'sort_order' => $vehicle->sort_order,
        ];
    }
}
