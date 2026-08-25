<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\TiktokChannelLink;
use App\Models\Channel;
use App\Models\Property;
use App\Services\Channels\ChannelPlanCounter;
use App\Services\Tiktok\TiktokApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conexión self-service de cuentas TikTok (Business Messaging) desde el
 * panel del hotel: id de la cuenta business + access token de la app
 * aprobada. El webhook NO se registra por API — TikTok lo pide en el panel
 * de la app, así que se muestra la URL para pegarla ahí.
 */
class TiktokChannelController extends Controller
{
    public function __construct(protected TiktokApi $api) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'business_id' => ['required', 'string', 'max:100'],
            'access_token' => ['required', 'string', 'max:500'],
        ]);

        $max = tenant()->planLimit('max_channels');
        if ($max !== null && ChannelPlanCounter::connected((string) tenant('id')) >= $max) {
            return response()->json([
                'message' => "Límite del plan alcanzado: máximo {$max} canal(es) de mensajería. Actualiza el plan para conectar más.",
            ], 422);
        }

        if (TiktokChannelLink::query()->where('business_id', $data['business_id'])->exists()) {
            return response()->json([
                'message' => 'Esa cuenta de TikTok ya está conectada a un hotel.',
            ], 422);
        }

        $link = TiktokChannelLink::create([
            ...$data,
            'tenant_id' => tenant('id'),
            'webhook_token' => TiktokChannelLink::generateToken(),
            'active' => true,
        ]);

        // El canal aparece en la bandeja desde ya (con su modo propio),
        // sin esperar el primer mensaje entrante.
        Channel::firstOrCreate(
            [
                'property_id' => Property::firstOrFail()->id,
                'type' => Channel::TYPE_TIKTOK,
                'external_id' => (string) $link->id,
            ],
            ['name' => $link->name ?: 'TikTok', 'mode' => 'auto', 'active' => true],
        );

        return response()->json([
            ...$this->serialize($link),
            'connection' => $this->api->accountInfo($link),
        ], 201);
    }

    public function update(Request $request, int $linkId): JsonResponse
    {
        $link = $this->ownLink($linkId);

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'business_id' => ['sometimes', 'string', 'max:100'],
            'access_token' => ['sometimes', 'nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Campo de token vacío = conservar el actual.
        if (array_key_exists('access_token', $data) && ! $data['access_token']) {
            unset($data['access_token']);
        }

        if (isset($data['business_id'])) {
            $taken = TiktokChannelLink::query()
                ->where('business_id', $data['business_id'])
                ->whereKeyNot($link->id)
                ->exists();

            if ($taken) {
                return response()->json([
                    'message' => 'Esa cuenta de TikTok ya está conectada a un hotel.',
                ], 422);
            }
        }

        $link->update($data);

        Channel::query()
            ->where('type', Channel::TYPE_TIKTOK)
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
            ->where('type', Channel::TYPE_TIKTOK)
            ->where('external_id', (string) $link->id)
            ->update(['active' => false]);

        $link->delete();

        return response()->json(status: 204);
    }

    /** Prueba el token contra la Business API. */
    public function test(int $linkId): JsonResponse
    {
        $link = $this->ownLink($linkId);

        return response()->json([
            'connection' => $this->api->accountInfo($link),
            'webhook_url' => $link->webhookUrl(),
        ]);
    }

    protected function ownLink(int $linkId): TiktokChannelLink
    {
        return TiktokChannelLink::query()
            ->where('tenant_id', tenant('id'))
            ->findOrFail($linkId);
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(TiktokChannelLink $link): array
    {
        return [
            'id' => $link->id,
            'name' => $link->name,
            'business_id' => $link->business_id,
            'masked_token' => $link->maskedToken(),
            'webhook_url' => $link->webhookUrl(),
            'active' => $link->active,
            'last_event_at' => $link->last_event_at?->diffForHumans(short: true),
            'created_at' => $link->created_at?->format('d/m/Y'),
        ];
    }
}
