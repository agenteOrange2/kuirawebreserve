<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\ModuleActivationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Solicitar activación" desde la tarjeta Tu plan (/ajustes): registra el
 * interés del hotel en un módulo que su plan no incluye. La solicitud
 * aparece en la ficha del hotel del admin, que decide (forzar el módulo,
 * proponer upgrade o descartar). v1 sin correo: solo registro visible.
 */
class ModuleRequestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module' => ['required', Rule::in(array_keys(config('modules', [])))],
        ]);

        if (tenant()->hasModule($data['module'])) {
            return response()->json(['message' => 'Ese módulo ya está activo para tu hotel.'], 422);
        }

        ModuleActivationRequest::firstOrCreate([
            'tenant_id' => tenant('id'),
            'module' => $data['module'],
        ]);

        return response()->json(['ok' => true]);
    }
}
