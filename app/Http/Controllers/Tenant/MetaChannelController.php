<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\EvolutionChannelLink;
use App\Models\Central\MetaChannelLink;
use App\Models\Channel;
use App\Models\Property;
use App\Services\Meta\MetaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conexión self-service de WhatsApp por la Cloud API oficial de Meta desde
 * el panel del hotel — alternativa a Evolution (que "tumba números" con
 * frecuencia). A diferencia de Evolution, el webhook de Meta es GLOBAL de
 * plataforma (/webhooks/meta con un verify_token compartido): el hotel pega
 * esa URL y el token en su app de Meta; aquí solo aporta su phone_number_id
 * y su access_token. El límite de canales lo pone el plan (Meta + Evolution).
 */
class MetaChannelController extends Controller
{
    public function __construct(protected MetaApi $api) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            // phone_number_id del número de WhatsApp (Meta lo da en el panel).
            'external_id' => ['required', 'string', 'max:100'],
            'waba_id' => ['nullable', 'string', 'max:100'],
            'access_token' => ['required', 'string'],
        ]);

        $max = tenant()->planLimit('max_channels');
        if ($max !== null && $this->connectedChannels() >= $max) {
            return response()->json([
                'message' => "Límite del plan alcanzado: máximo {$max} canal(es) de mensajería. Actualiza el plan para conectar más.",
            ], 422);
        }

        // Unicidad GLOBAL: un phone_number_id no puede vivir en dos hoteles.
        $taken = MetaChannelLink::query()
            ->where('type', 'whatsapp')
            ->where('external_id', $data['external_id'])
            ->exists();

        if ($taken) {
            return response()->json([
                'message' => 'Ese número de WhatsApp (phone_number_id) ya está conectado a un hotel.',
            ], 422);
        }

        $link = MetaChannelLink::create([
            ...$data,
            'type' => 'whatsapp',
            'tenant_id' => tenant('id'),
            'active' => true,
        ]);

        // El canal aparece en la bandeja desde ya — el webhook Meta crea el
        // Channel type 'whatsapp' SIN external_id, así que aquí igual (un
        // único canal WhatsApp-Meta por propiedad).
        Channel::firstOrCreate(
            [
                'property_id' => Property::firstOrFail()->id,
                'type' => 'whatsapp',
            ],
            ['name' => $link->name ?: 'WhatsApp', 'mode' => 'auto', 'active' => true],
        );

        return response()->json([
            ...$this->serialize($link),
            'diagnose' => $this->api->diagnose($link),
        ], 201);
    }

    public function update(Request $request, int $linkId): JsonResponse
    {
        $link = $this->ownLink($linkId);

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'external_id' => ['sometimes', 'string', 'max:100'],
            'waba_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'access_token' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Token vacío = conservar el actual.
        if (array_key_exists('access_token', $data) && ! $data['access_token']) {
            unset($data['access_token']);
        }

        $taken = MetaChannelLink::query()
            ->where('type', 'whatsapp')
            ->where('external_id', $data['external_id'] ?? $link->external_id)
            ->whereKeyNot($link->id)
            ->exists();

        if ($taken) {
            return response()->json([
                'message' => 'Ese número de WhatsApp (phone_number_id) ya está conectado a un hotel.',
            ], 422);
        }

        $link->update($data);

        Channel::query()
            ->where('property_id', Property::firstOrFail()->id)
            ->where('type', 'whatsapp')
            ->update(array_filter([
                'name' => $data['name'] ?? null,
                'active' => $data['active'] ?? null,
            ], fn ($value) => $value !== null));

        return response()->json($this->serialize($link->refresh()));
    }

    public function destroy(int $linkId): JsonResponse
    {
        $link = $this->ownLink($linkId);

        // El Channel se desactiva pero NO se borra: conserva su historial.
        Channel::query()
            ->where('property_id', Property::firstOrFail()->id)
            ->where('type', 'whatsapp')
            ->update(['active' => false]);

        $link->delete();

        return response()->json(status: 204);
    }

    /**
     * Probar conexión: valida el token vivo contra la Graph API, el número,
     * la calidad, y que el callback registrado en Meta sea el nuestro.
     */
    public function test(int $linkId): JsonResponse
    {
        $link = $this->ownLink($linkId);

        return response()->json(['diagnose' => $this->api->diagnose($link)]);
    }

    /** Reintenta suscribir la app de la plataforma a la WABA del hotel. */
    public function resubscribe(int $linkId): JsonResponse
    {
        $link = $this->ownLink($linkId);

        if (! $link->waba_id) {
            return response()->json([
                'message' => 'Falta el WhatsApp Business Account ID (WABA) para reparar la suscripción.',
            ], 422);
        }

        return response()->json(['resubscribed' => $this->api->resubscribe($link)]);
    }

    /** Canales de mensajería conectados que cuentan para el plan. */
    protected function connectedChannels(): int
    {
        return EvolutionChannelLink::query()->where('tenant_id', tenant('id'))->count()
            + MetaChannelLink::query()->where('tenant_id', tenant('id'))->count();
    }

    protected function ownLink(int $linkId): MetaChannelLink
    {
        return MetaChannelLink::query()
            ->where('tenant_id', tenant('id'))
            ->where('type', 'whatsapp')
            ->findOrFail($linkId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(MetaChannelLink $link): array
    {
        return [
            'id' => $link->id,
            'name' => $link->name,
            'external_id' => $link->external_id,
            'waba_id' => $link->waba_id,
            'masked_token' => $link->maskedToken(),
            'active' => $link->active,
            'last_event_at' => $link->last_event_at?->diffForHumans(),
            'created_at' => $link->created_at?->format('d/m/Y'),
        ];
    }
}
