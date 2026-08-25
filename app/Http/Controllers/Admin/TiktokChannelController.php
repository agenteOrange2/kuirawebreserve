<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\TiktokChannelLink;
use App\Models\Tenant;
use App\Services\Tiktok\TiktokApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cuentas de TikTok (Business Messaging) conectadas por hotel, gestionadas
 * desde el admin de plataforma. El webhook se pega a mano en el panel de la
 * app de TikTok con la URL por vínculo que expone esta vista.
 */
class TiktokChannelController extends Controller
{
    public function __construct(protected TiktokApi $api) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', Rule::exists('tenants', 'id')],
            'name' => ['nullable', 'string', 'max:100'],
            'business_id' => ['required', 'string', 'max:100'],
            'access_token' => ['required', 'string', 'max:500'],
        ]);

        if (TiktokChannelLink::query()->where('business_id', $data['business_id'])->exists()) {
            return response()->json(['message' => 'Esa cuenta de TikTok ya está vinculada a un hotel.'], 422);
        }

        $link = TiktokChannelLink::create($data + [
            'webhook_token' => TiktokChannelLink::generateToken(),
            'active' => true,
        ]);

        // El canal aparece en la bandeja del hotel desde ya.
        $this->syncTenantChannel($link);

        return response()->json([
            ...$this->serialize($link),
            'connection' => $this->api->accountInfo($link),
        ], 201);
    }

    public function update(Request $request, TiktokChannelLink $tiktokChannelLink): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'business_id' => ['sometimes', 'string', 'max:100'],
            'access_token' => ['sometimes', 'nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Campo de token vacío = conservar el actual.
        if (array_key_exists('access_token', $data) && blank($data['access_token'])) {
            unset($data['access_token']);
        }

        if (isset($data['business_id'])) {
            $taken = TiktokChannelLink::query()
                ->where('business_id', $data['business_id'])
                ->whereKeyNot($tiktokChannelLink->id)
                ->exists();

            if ($taken) {
                return response()->json(['message' => 'Esa cuenta de TikTok ya está vinculada a un hotel.'], 422);
            }
        }

        $tiktokChannelLink->update($data);

        $this->syncTenantChannel($tiktokChannelLink, $data);

        return response()->json($this->serialize($tiktokChannelLink->refresh()));
    }

    public function destroy(TiktokChannelLink $tiktokChannelLink): JsonResponse
    {
        // El Channel del tenant se desactiva pero NO se borra: las
        // conversaciones y su historial se conservan.
        Tenant::find($tiktokChannelLink->tenant_id)?->run(function () use ($tiktokChannelLink) {
            \App\Models\Channel::query()
                ->where('type', \App\Models\Channel::TYPE_TIKTOK)
                ->where('external_id', (string) $tiktokChannelLink->id)
                ->update(['active' => false]);
        });

        $tiktokChannelLink->delete();

        return response()->json(['ok' => true]);
    }

    /** Prueba el token contra la Business API. */
    public function test(TiktokChannelLink $tiktokChannelLink): JsonResponse
    {
        return response()->json([
            'connection' => $this->api->accountInfo($tiktokChannelLink),
            'webhook_url' => $tiktokChannelLink->webhookUrl(),
        ]);
    }

    /**
     * Crea/actualiza el Channel dentro del tenant dueño para que el canal
     * viva en la bandeja sin esperar el primer mensaje.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function syncTenantChannel(TiktokChannelLink $link, array $changes = []): void
    {
        Tenant::find($link->tenant_id)?->run(function () use ($link, $changes) {
            \App\Models\Channel::firstOrCreate(
                [
                    'property_id' => \App\Models\Property::firstOrFail()->id,
                    'type' => \App\Models\Channel::TYPE_TIKTOK,
                    'external_id' => (string) $link->id,
                ],
                ['name' => $link->name ?: 'TikTok', 'mode' => 'auto', 'active' => true],
            );

            \App\Models\Channel::query()
                ->where('type', \App\Models\Channel::TYPE_TIKTOK)
                ->where('external_id', (string) $link->id)
                ->update(array_filter([
                    'name' => $changes['name'] ?? null,
                    'active' => $changes['active'] ?? null,
                ], fn ($value) => $value !== null));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(TiktokChannelLink $link): array
    {
        return [
            'id' => $link->id,
            'tenant_id' => $link->tenant_id,
            'name' => $link->name,
            'business_id' => $link->business_id,
            'masked_token' => $link->maskedToken(),
            'webhook_url' => $link->webhookUrl(),
            'active' => $link->active,
            'last_event_at' => $link->last_event_at?->diffForHumans(short: true),
        ];
    }
}
