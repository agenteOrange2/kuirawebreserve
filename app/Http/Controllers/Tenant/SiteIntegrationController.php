<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\SiteIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tokens de sitios conectados (spec-integracion-sitios §2). El token en
 * claro solo viaja en la respuesta del store: después únicamente se ve el
 * prefijo. Todo el grupo va detrás de can:properties.manage + module:motor-web.
 */
class SiteIntegrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'domains' => ['sometimes', 'array', 'max:5'],
            'domains.*' => ['string', 'max:120'],
        ]);

        $result = SiteIntegration::generate(
            (string) tenant('id'),
            $data['label'],
            array_values(array_filter(array_map('trim', $data['domains'] ?? []))),
        );

        return response()->json([
            'integration' => $this->serialize($result['integration']),
            // Única vez que el token existe en claro: se muestra y se copia.
            'token' => $result['token'],
        ], 201);
    }

    public function update(Request $request, int $integrationId): JsonResponse
    {
        $integration = $this->find($integrationId);

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
            'domains' => ['sometimes', 'array', 'max:5'],
            'domains.*' => ['string', 'max:120'],
        ]);

        if (array_key_exists('domains', $data)) {
            $data['domains'] = array_values(array_filter(array_map('trim', $data['domains']))) ?: null;
        }

        $integration->update($data);

        return response()->json($this->serialize($integration->refresh()));
    }

    public function destroy(int $integrationId): JsonResponse
    {
        $this->find($integrationId)->delete();

        return response()->json(status: 204);
    }

    protected function find(int $integrationId): SiteIntegration
    {
        return SiteIntegration::query()
            ->where('tenant_id', tenant('id'))
            ->findOrFail($integrationId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serialize(SiteIntegration $integration): array
    {
        return [
            'id' => $integration->id,
            'label' => $integration->label,
            'token_prefix' => $integration->token_prefix,
            'domains' => $integration->domains ?? [],
            'active' => $integration->active,
            'last_used_at' => $integration->last_used_at?->format('d/m/Y H:i'),
            'created_at' => $integration->created_at?->format('d/m/Y'),
        ];
    }
}
