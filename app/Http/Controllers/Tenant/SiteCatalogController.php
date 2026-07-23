<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\SiteIntegration;
use App\Models\Property;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API pública de sitio, v1 lectura (spec-integracion-sitios §3): catálogo
 * con precio VIVO para los sitios conectados (plugin WP, Laravel a
 * medida). El precio se edita una vez en el sistema y las páginas lo
 * leen de aquí — jamás lo guardan. Requiere token de integración y el
 * módulo motor-web; el plugin consulta desde servidor con caché de
 * minutos (transient), sin exponer el token al navegador.
 */
class SiteCatalogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! tenant()?->hasModule('motor-web')) {
            abort(403, 'El módulo Motor de reservas web no está incluido en el plan de este hotel.');
        }

        $token = $request->bearerToken() ?? $request->header('X-Kuira-Site-Key');
        $integration = SiteIntegration::findByToken($token, (string) tenant('id'));

        abort_unless($integration !== null, 401, 'Token de integración inválido o revocado.');

        $integration->touchUsage();

        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return response()->json([
            'property' => [
                'name' => $property->name,
                'currency' => $settings['currency'] ?? 'MXN',
                'check_in_time' => $settings['check_in_time'] ?? null,
                'check_out_time' => $settings['check_out_time'] ?? null,
            ],
            'generated_at' => now()->toIso8601String(),
            'room_types' => RoomType::query()
                ->where('active', true)
                ->with(['media', 'ratePlans' => fn ($q) => $q->where('active', true)->orderBy('price')])
                ->withMin(['ratePlans as price_from' => fn ($q) => $q->where('active', true)], 'price')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (RoomType $type) => [
                    'id' => $type->id,
                    'name' => $type->name,
                    'description' => $type->description,
                    'capacity' => $type->capacity,
                    'max_adults' => $type->max_adults,
                    'max_children' => $type->max_children,
                    'amenities' => $type->amenities ?? [],
                    // URLs absolutas de la ruta pública (sin login): el
                    // plugin WP las pone tal cual en las tarjetas.
                    'photos' => $type->photosPayload(),
                    'price_from' => $type->priceFrom(),
                    'reservable' => $type->hasActiveRate(),
                    'rate_plans' => $type->ratePlans->map(fn ($plan) => [
                        'name' => $plan->name,
                        'duration_label' => $plan->durationLabel(),
                        'price' => (float) $plan->price,
                        'deposit_percent' => $plan->deposit_percent !== null ? (float) $plan->deposit_percent : null,
                    ])->values(),
                ]),
        ]);
    }
}
