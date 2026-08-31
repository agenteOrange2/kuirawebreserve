<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\TenantMetaApp;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * App de Meta PROPIA por hotel: el super-admin captura aquí el app_id y las
 * claves de la app que ese hotel creó en developers.facebook.com. Con fila,
 * el hotel firma/canjea con SU app; sin fila, usa la de la plataforma. El
 * webhook central es el mismo para todas (misma URL y verify token).
 */
class TenantMetaAppController extends Controller
{
    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['required', 'string', 'max:50'],
            // Vacíos al editar = conservar los guardados.
            'app_secret' => ['nullable', 'string', 'max:100'],
            'ig_app_secret' => ['nullable', 'string', 'max:100'],
            'login_config_id' => ['nullable', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        $app = TenantMetaApp::forTenant($tenant->id);

        if (! $app && blank($data['app_secret'] ?? null)) {
            return response()->json([
                'message' => 'La clave secreta de la aplicación es obligatoria al conectarla por primera vez.',
            ], 422);
        }

        $payload = array_filter($data, fn ($value) => filled($value));
        $payload['app_id'] = $data['app_id'];
        $payload['name'] = $data['name'] ?? null;
        $payload['login_config_id'] = $data['login_config_id'] ?? null;

        $app = TenantMetaApp::updateOrCreate(['tenant_id' => $tenant->id], $payload);

        return response()->json(self::serialize($app));
    }

    /** El hotel vuelve a la app de la plataforma. */
    public function destroy(Tenant $tenant): JsonResponse
    {
        TenantMetaApp::forTenant($tenant->id)?->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serialize(TenantMetaApp $app): array
    {
        return [
            'app_id' => $app->app_id,
            'name' => $app->name,
            'masked_app_secret' => $app->maskedSecret('app_secret'),
            'masked_ig_app_secret' => $app->maskedSecret('ig_app_secret'),
            'login_config_id' => $app->login_config_id,
            'updated_at' => $app->updated_at?->format('d/m/Y H:i'),
        ];
    }
}
