<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Property::withCount('rooms')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        // Decisión (docs/spec-pendientes-y-agentes.md §3.1): el panel opera
        // UNA propiedad por tenant hoy; multipropiedad = selector + scoping
        // en fase futura. Evita estados a medias con Property::firstOrFail().
        if (Property::query()->exists()) {
            return response()->json([
                'message' => 'Por ahora el panel maneja una propiedad por hotel; la multipropiedad llegará en una fase futura.',
            ], 422);
        }

        $max = tenant()->planLimit('max_properties');
        if ($max !== null && Property::count() >= $max) {
            return response()->json([
                'message' => "Límite del plan alcanzado: máximo {$max} propiedad(es). Actualiza el plan para agregar más.",
            ], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['sometimes', 'timezone'],
            'address' => ['nullable', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ]);

        return response()->json(Property::create($data), 201);
    }

    public function show(Property $property): JsonResponse
    {
        return response()->json(
            $property->load(['zones', 'roomTypes'])->loadCount('rooms')
        );
    }

    public function update(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'timezone'],
            'address' => ['nullable', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
            // Ajustes del hotel (los consume el panel y el get_policies() de agentes).
            'settings.check_in_time' => ['nullable', 'date_format:H:i'],
            'settings.check_out_time' => ['nullable', 'date_format:H:i'],
            'settings.currency' => ['nullable', 'string', 'size:3'],
            'settings.phone' => ['nullable', 'string', 'max:30'],
            'settings.email' => ['nullable', 'email', 'max:255'],
            'settings.policies' => ['nullable', 'string', 'max:5000'],
        ]);

        // Merge para no pisar llaves de settings que esta pantalla no maneja.
        if (isset($data['settings'])) {
            $data['settings'] = array_merge($property->settings ?? [], $data['settings']);
        }

        $property->update($data);

        return response()->json($property);
    }

    public function destroy(Property $property): JsonResponse
    {
        $property->delete();

        return response()->json(status: 204);
    }
}
