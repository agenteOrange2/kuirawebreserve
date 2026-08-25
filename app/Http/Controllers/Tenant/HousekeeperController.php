<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Housekeeper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alta y baja de camaristas. No son usuarios del sistema: no consumen el
 * límite de usuarios del plan ni tienen credenciales.
 */
class HousekeeperController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        return response()->json(self::present(Housekeeper::create($data)), 201);
    }

    public function update(Request $request, Housekeeper $housekeeper): JsonResponse
    {
        $housekeeper->update($this->validated($request));

        return response()->json(self::present($housekeeper->fresh()));
    }

    /**
     * Dar de baja NO borra: su historial de limpiezas sigue contando en los
     * reportes de periodos pasados. Solo deja de aparecer para asignar.
     */
    public function destroy(Housekeeper $housekeeper): JsonResponse
    {
        if ($housekeeper->cleanings()->exists()) {
            $housekeeper->update(['active' => false]);

            return response()->json([
                'ok' => true,
                'archived' => true,
                'message' => "{$housekeeper->name} tiene limpiezas registradas: se dio de baja sin borrar su historial.",
            ]);
        }

        $housekeeper->delete();

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
            'active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Housekeeper $housekeeper): array
    {
        return [
            'id' => $housekeeper->id,
            'name' => $housekeeper->name,
            'phone' => $housekeeper->phone,
            'active' => $housekeeper->active,
            'notes' => $housekeeper->notes,
        ];
    }
}
