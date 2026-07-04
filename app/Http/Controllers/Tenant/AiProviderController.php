<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Services\Agent\AgentBrain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Proveedores LLM del hotel: alta/edición/baja y prueba de conexión real
 * (latencia + respuesta) para comparar costo-beneficio entre proveedores.
 */
class AiProviderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(AiProvider::CATALOG))],
            'model' => ['required', 'string', 'max:100'],
            'api_key' => ['required', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $provider = AiProvider::create($data + [
            'sort_order' => (int) AiProvider::max('sort_order') + 1,
        ]);

        return response()->json(['id' => $provider->id], 201);
    }

    public function update(Request $request, AiProvider $aiProvider): JsonResponse
    {
        $data = $request->validate([
            'model' => ['sometimes', 'required', 'string', 'max:100'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']); // vacío = conservar la key actual
        }

        $aiProvider->update($data);

        return response()->json(['ok' => true]);
    }

    public function destroy(AiProvider $aiProvider): JsonResponse
    {
        $aiProvider->delete();

        return response()->json(status: 204);
    }

    /**
     * Prueba real de conexión: mini-prompt al proveedor y mide latencia.
     */
    public function test(AiProvider $aiProvider, AgentBrain $brain): JsonResponse
    {
        $started = microtime(true);

        try {
            $response = $brain->run($aiProvider, fn ($request) => $request
                ->withPrompt('Responde únicamente con la palabra: ok')
                ->withClientOptions(['timeout' => 20]));

            return response()->json([
                'ok' => true,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'reply' => str($response->text)->limit(80)->toString(),
                'tokens' => ($response->usage->promptTokens ?? 0) + ($response->usage->completionTokens ?? 0),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => str($e->getMessage())->limit(220)->toString(),
            ], 422);
        }
    }
}
