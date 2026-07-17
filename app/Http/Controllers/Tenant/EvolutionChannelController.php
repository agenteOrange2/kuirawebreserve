<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\EvolutionChannelLink;
use App\Models\Central\MetaChannelLink;
use App\Models\Channel;
use App\Models\Property;
use App\Services\Evolution\EvolutionApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conexión self-service de instancias WhatsApp vía Evolution API desde el
 * panel del hotel. El límite de canales conectados lo pone el plan
 * (max_channels: Meta + Evolution; el webchat no cuenta).
 */
class EvolutionChannelController extends Controller
{
    public function __construct(protected EvolutionApi $api) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'base_url' => ['required', 'url', 'max:255'],
            'instance' => ['required', 'string', 'max:100'],
            'api_key' => ['required', 'string', 'max:255'],
        ]);

        $max = tenant()->planLimit('max_channels');
        if ($max !== null && $this->connectedChannels() >= $max) {
            return response()->json([
                'message' => "Límite del plan alcanzado: máximo {$max} canal(es) de mensajería. Actualiza el plan para conectar más.",
            ], 422);
        }

        $data['base_url'] = $this->normalizeBaseUrl($data['base_url']);

        $taken = EvolutionChannelLink::query()
            ->where('base_url', $data['base_url'])
            ->where('instance', $data['instance'])
            ->exists();

        if ($taken) {
            return response()->json([
                'message' => 'Esa instancia de Evolution ya está conectada a un hotel.',
            ], 422);
        }

        $link = EvolutionChannelLink::create([
            ...$data,
            'tenant_id' => tenant('id'),
            'webhook_token' => EvolutionChannelLink::generateToken(),
            'active' => true,
        ]);

        // El canal aparece en la bandeja desde ya (con su modo propio),
        // sin esperar el primer mensaje entrante.
        Channel::firstOrCreate(
            [
                'property_id' => Property::firstOrFail()->id,
                'type' => Channel::TYPE_WHATSAPP_EVOLUTION,
                'external_id' => (string) $link->id,
            ],
            ['name' => $link->name ?: "WhatsApp {$link->instance}", 'mode' => 'auto', 'active' => true],
        );

        $webhookConfigured = $this->api->configureWebhook($link);
        $connection = $this->api->connectionState($link);

        return response()->json([
            ...$this->serialize($link),
            'webhook_configured' => $webhookConfigured,
            'connection' => $connection,
        ], 201);
    }

    public function update(Request $request, int $linkId): JsonResponse
    {
        $link = $this->ownLink($linkId);

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'base_url' => ['sometimes', 'url', 'max:255'],
            'instance' => ['sometimes', 'string', 'max:100'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Campo de key vacío = conservar la actual.
        if (array_key_exists('api_key', $data) && ! $data['api_key']) {
            unset($data['api_key']);
        }

        if (isset($data['base_url'])) {
            $data['base_url'] = $this->normalizeBaseUrl($data['base_url']);
        }

        // Corregir servidor/instancia no debe chocar consigo misma, pero sí
        // con instancias conectadas por otros hoteles.
        $taken = EvolutionChannelLink::query()
            ->where('base_url', $data['base_url'] ?? $link->base_url)
            ->where('instance', $data['instance'] ?? $link->instance)
            ->whereKeyNot($link->id)
            ->exists();

        if ($taken) {
            return response()->json([
                'message' => 'Esa instancia de Evolution ya está conectada a un hotel.',
            ], 422);
        }

        $link->update($data);

        // Si cambió el servidor o la instancia, reintenta dejar el webhook
        // apuntando a la plataforma de una vez.
        if (isset($data['base_url']) || isset($data['instance'])) {
            $this->api->configureWebhook($link->refresh());
        }

        Channel::query()
            ->where('type', Channel::TYPE_WHATSAPP_EVOLUTION)
            ->where('external_id', (string) $link->id)
            ->update(array_filter([
                'name' => $data['name'] ?? null,
                'active' => $data['active'] ?? null,
            ], fn ($value) => $value !== null));

        return response()->json($this->serialize($link));
    }

    public function destroy(int $linkId): JsonResponse
    {
        $link = $this->ownLink($linkId);

        // El Channel del tenant se desactiva pero NO se borra: las
        // conversaciones y su historial se conservan.
        Channel::query()
            ->where('type', Channel::TYPE_WHATSAPP_EVOLUTION)
            ->where('external_id', (string) $link->id)
            ->update(['active' => false]);

        $link->delete();

        return response()->json(status: 204);
    }

    /** Prueba la conexión y reintenta configurar el webhook. */
    public function test(int $linkId): JsonResponse
    {
        $link = $this->ownLink($linkId);

        return response()->json([
            'connection' => $this->api->connectionState($link),
            'webhook_configured' => $this->api->configureWebhook($link),
            'webhook_url' => $link->webhookUrl(),
        ]);
    }

    /**
     * La gente pega la URL del panel (…/manager/): la API vive en la raíz.
     */
    protected function normalizeBaseUrl(string $url): string
    {
        $url = rtrim($url, '/');

        return rtrim(preg_replace('#/manager$#i', '', $url), '/');
    }

    /** Canales de mensajería conectados que cuentan para el plan. */
    protected function connectedChannels(): int
    {
        return EvolutionChannelLink::query()->where('tenant_id', tenant('id'))->count()
            + MetaChannelLink::query()->where('tenant_id', tenant('id'))->count();
    }

    protected function ownLink(int $linkId): EvolutionChannelLink
    {
        return EvolutionChannelLink::query()
            ->where('tenant_id', tenant('id'))
            ->findOrFail($linkId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(EvolutionChannelLink $link): array
    {
        return [
            'id' => $link->id,
            'name' => $link->name,
            'base_url' => $link->base_url,
            'instance' => $link->instance,
            'masked_key' => $link->maskedKey(),
            'webhook_url' => $link->webhookUrl(),
            'active' => $link->active,
            'created_at' => $link->created_at?->format('d/m/Y'),
        ];
    }
}
