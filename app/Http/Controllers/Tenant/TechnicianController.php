<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Technician;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alta y baja de quien repara: personal de casa y proveedores externos.
 * No son usuarios del sistema, así que no consumen el límite del plan.
 */
class TechnicianController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        return response()->json(self::present(Technician::create($this->validated($request))), 201);
    }

    public function update(Request $request, Technician $technician): JsonResponse
    {
        $technician->update($this->validated($request));

        return response()->json(self::present($technician->fresh()));
    }

    /**
     * Con historial se archiva en vez de borrarse: los reportes de periodos
     * pasados tienen que seguir cuadrando.
     */
    public function destroy(Technician $technician): JsonResponse
    {
        if ($technician->incidents()->exists()) {
            $technician->update(['active' => false]);

            return response()->json([
                'ok' => true,
                'archived' => true,
                'message' => "{$technician->name} tiene reparaciones registradas: se dio de baja sin borrar su historial.",
            ]);
        }

        $technician->delete();

        return response()->json(['ok' => true, 'archived' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:30'],
            'specialty' => ['nullable', 'string', 'max:60'],
            'external' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Technician $technician): array
    {
        return [
            'id' => $technician->id,
            'name' => $technician->name,
            'phone' => $technician->phone,
            'specialty' => $technician->specialty,
            'external' => $technician->external,
            'kind_label' => $technician->kindLabel(),
            'active' => $technician->active,
            'notes' => $technician->notes,
        ];
    }
}
