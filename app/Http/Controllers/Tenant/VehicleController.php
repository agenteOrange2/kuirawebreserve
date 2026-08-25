<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Stay;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API del registro de vehículos (docs/spec-modo-motel.md). `search` es lo que
 * hace que el registro sirva de algo en la caseta: al teclear la placa, el
 * cajero ve si ese carro ya vino y con qué datos, y si está vetado.
 */
class VehicleController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        if (mb_strlen($term) < 3) {
            return response()->json([]);
        }

        $vehicles = Vehicle::query()
            ->search($term)
            ->withCount('stays as visits')
            ->withMax('stays as last_seen_at', 'check_in_at')
            ->orderByDesc('last_seen_at')
            ->take(8)
            ->get()
            ->map(fn (Vehicle $vehicle) => [
                'id' => $vehicle->id,
                'plate' => $vehicle->plate,
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'color' => $vehicle->color,
                'label' => $vehicle->label(),
                'visits' => $vehicle->visits,
                'last_seen_at' => $vehicle->last_seen_at
                    ? \Illuminate\Support\Carbon::parse($vehicle->last_seen_at)->format('d/m/Y')
                    : null,
                'is_blacklisted' => $vehicle->is_blacklisted,
                'blacklist_reason' => $vehicle->blacklist_reason,
            ]);

        return response()->json($vehicles);
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'brand' => ['nullable', 'string', 'max:40'],
            'model' => ['nullable', 'string', 'max:40'],
            'color' => ['nullable', 'string', 'max:30'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:'.(date('Y') + 1)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_blacklisted' => ['sometimes', 'boolean'],
            'blacklist_reason' => ['nullable', 'required_if:is_blacklisted,true', 'string', 'max:255'],
        ], [
            'blacklist_reason.required_if' => 'Escribe por qué se veta esta placa.',
        ]);

        // La placa NO se edita: es la identidad de la ficha. Corregir una mal
        // tecleada implicaría fusionar dos fichas, y eso es otra herramienta.
        $vehicle->update($data);

        return response()->json($vehicle->fresh());
    }

    /**
     * Archivar o eliminar, misma política que el CRM de huéspedes: con
     * historial se archiva (sus estancias quedarían huérfanas) y se puede
     * restaurar; sin historial se borra de verdad.
     */
    public function destroy(Vehicle $vehicle): JsonResponse
    {
        if (! $vehicle->trashed() && $vehicle->stays()->exists()) {
            $vehicle->delete();

            return response()->json([
                'archived' => true,
                'message' => 'Vehículo archivado: tiene historial de entradas, así que se ocultó del registro. Puedes restaurarlo desde el filtro Archivados.',
            ]);
        }

        $vehicle->clearMediaCollection('photos');
        $vehicle->forceDelete();

        return response()->json(status: 204);
    }

    public function restore(Vehicle $vehicle): JsonResponse
    {
        $vehicle->restore();

        return response()->json($vehicle->refresh());
    }

    /**
     * Estancia asociada a una placa: lo usa el plano para avisar "este carro
     * ya estuvo aquí" sin abrir la sección.
     */
    public function lookup(Request $request): JsonResponse
    {
        $normalized = Vehicle::normalizePlate($request->string('plate')->toString());

        if ($normalized === null) {
            return response()->json(null);
        }

        $vehicle = Vehicle::where('plate_normalized', $normalized)
            ->withCount('stays as visits')
            ->first();

        if (! $vehicle) {
            return response()->json(null);
        }

        return response()->json([
            'id' => $vehicle->id,
            'plate' => $vehicle->plate,
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'color' => $vehicle->color,
            'label' => $vehicle->label(),
            'visits' => $vehicle->visits,
            'is_blacklisted' => $vehicle->is_blacklisted,
            'blacklist_reason' => $vehicle->blacklist_reason,
            'is_inside' => $vehicle->stays()->where('status', Stay::STATUS_ACTIVE)->exists(),
        ]);
    }
}
