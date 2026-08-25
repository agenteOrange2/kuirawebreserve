<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\TelegramChannelLink;
use App\Models\Channel;
use App\Models\Property;
use App\Services\Channels\ChannelPlanCounter;
use App\Services\Telegram\TelegramApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conexión self-service de bots de Telegram desde el panel del hotel: se
 * pega el token de BotFather, se valida con getMe y el webhook queda
 * registrado solo. El límite de canales lo pone el plan (max_channels).
 */
class TelegramChannelController extends Controller
{
    public function __construct(protected TelegramApi $api) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'bot_token' => ['required', 'string', 'max:255'],
        ]);

        $max = tenant()->planLimit('max_channels');
        if ($max !== null && ChannelPlanCounter::connected((string) tenant('id')) >= $max) {
            return response()->json([
                'message' => "Límite del plan alcanzado: máximo {$max} canal(es) de mensajería. Actualiza el plan para conectar más.",
            ], 422);
        }

        $botId = TelegramChannelLink::botIdFromToken($data['bot_token']);

        if (TelegramChannelLink::query()->where('bot_id', $botId)->exists()) {
            return response()->json([
                'message' => 'Ese bot de Telegram ya está conectado a un hotel.',
            ], 422);
        }

        $link = new TelegramChannelLink([
            'tenant_id' => tenant('id'),
            'name' => $data['name'] ?? null,
            'bot_id' => $botId,
            'bot_token' => $data['bot_token'],
            'webhook_token' => TelegramChannelLink::generateToken(),
            'active' => true,
        ]);

        // Token inválido = no crear nada (getMe es la prueba de vida).
        $me = $this->api->getMe($link);

        if (! $me['ok']) {
            return response()->json([
                'message' => 'Telegram rechazó el token del bot. Revisa que sea el token completo de BotFather.',
            ], 422);
        }

        $link->bot_username = $me['username'];
        $link->save();

        // El canal aparece en la bandeja desde ya (con su modo propio),
        // sin esperar el primer mensaje entrante.
        Channel::firstOrCreate(
            [
                'property_id' => Property::firstOrFail()->id,
                'type' => Channel::TYPE_TELEGRAM,
                'external_id' => (string) $link->id,
            ],
            ['name' => $link->name ?: ($link->bot_username ? "Telegram @{$link->bot_username}" : 'Telegram'), 'mode' => 'auto', 'active' => true],
        );

        $webhookConfigured = $this->api->configureWebhook($link);

        return response()->json([
            ...$this->serialize($link),
            'webhook_configured' => $webhookConfigured,
        ], 201);
    }

    public function update(Request $request, int $linkId): JsonResponse
    {
        $link = $this->ownLink($linkId);

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bot_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Campo de token vacío = conservar el actual.
        if (array_key_exists('bot_token', $data) && ! $data['bot_token']) {
            unset($data['bot_token']);
        }

        if (isset($data['bot_token'])) {
            $botId = TelegramChannelLink::botIdFromToken($data['bot_token']);

            $taken = TelegramChannelLink::query()
                ->where('bot_id', $botId)
                ->whereKeyNot($link->id)
                ->exists();

            if ($taken) {
                return response()->json([
                    'message' => 'Ese bot de Telegram ya está conectado a un hotel.',
                ], 422);
            }

            $data['bot_id'] = $botId;
        }

        $link->update($data);

        // Token nuevo = bot posiblemente distinto: revalidar identidad y
        // volver a apuntar su webhook a la plataforma.
        if (isset($data['bot_token'])) {
            $me = $this->api->getMe($link->refresh());
            $link->update(['bot_username' => $me['username']]);
            $this->api->configureWebhook($link);
        }

        Channel::query()
            ->where('type', Channel::TYPE_TELEGRAM)
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
            ->where('type', Channel::TYPE_TELEGRAM)
            ->where('external_id', (string) $link->id)
            ->update(['active' => false]);

        $link->delete();

        return response()->json(status: 204);
    }

    /** Prueba el token y reintenta registrar el webhook. */
    public function test(int $linkId): JsonResponse
    {
        $link = $this->ownLink($linkId);

        $me = $this->api->getMe($link);

        if ($me['ok'] && $me['username'] !== $link->bot_username) {
            $link->update(['bot_username' => $me['username']]);
        }

        return response()->json([
            'connection' => $me,
            'webhook_configured' => $this->api->configureWebhook($link),
            'webhook_url' => $link->webhookUrl(),
        ]);
    }

    protected function ownLink(int $linkId): TelegramChannelLink
    {
        return TelegramChannelLink::query()
            ->where('tenant_id', tenant('id'))
            ->findOrFail($linkId);
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(TelegramChannelLink $link): array
    {
        return [
            'id' => $link->id,
            'name' => $link->name,
            'bot_username' => $link->bot_username,
            'masked_token' => $link->maskedToken(),
            'webhook_url' => $link->webhookUrl(),
            'active' => $link->active,
            'last_event_at' => $link->last_event_at?->diffForHumans(short: true),
            'created_at' => $link->created_at?->format('d/m/Y'),
        ];
    }
}
