<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\TelegramChannelLink;
use App\Models\Tenant;
use App\Services\Telegram\TelegramApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bots de Telegram conectados por hotel, gestionados desde el admin de
 * plataforma (alta con el token de BotFather; el webhook se registra solo
 * por la Bot API). Espejo del alta self-service del hotel.
 */
class TelegramChannelController extends Controller
{
    public function __construct(protected TelegramApi $api) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', Rule::exists('tenants', 'id')],
            'name' => ['nullable', 'string', 'max:100'],
            'bot_token' => ['required', 'string', 'max:255'],
        ]);

        $botId = TelegramChannelLink::botIdFromToken($data['bot_token']);

        if (TelegramChannelLink::query()->where('bot_id', $botId)->exists()) {
            return response()->json(['message' => 'Ese bot de Telegram ya está vinculado a un hotel.'], 422);
        }

        $link = new TelegramChannelLink([
            'tenant_id' => $data['tenant_id'],
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

        // El canal aparece en la bandeja del hotel desde ya.
        $this->syncTenantChannel($link);

        return response()->json([
            ...$this->serialize($link),
            'webhook_configured' => $this->api->configureWebhook($link),
        ], 201);
    }

    public function update(Request $request, TelegramChannelLink $telegramChannelLink): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bot_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Campo de token vacío = conservar el actual.
        if (array_key_exists('bot_token', $data) && blank($data['bot_token'])) {
            unset($data['bot_token']);
        }

        if (isset($data['bot_token'])) {
            $botId = TelegramChannelLink::botIdFromToken($data['bot_token']);

            $taken = TelegramChannelLink::query()
                ->where('bot_id', $botId)
                ->whereKeyNot($telegramChannelLink->id)
                ->exists();

            if ($taken) {
                return response()->json(['message' => 'Ese bot de Telegram ya está vinculado a un hotel.'], 422);
            }

            $data['bot_id'] = $botId;
        }

        $telegramChannelLink->update($data);

        // Token nuevo = bot posiblemente distinto: revalidar identidad y
        // volver a apuntar su webhook a la plataforma.
        if (isset($data['bot_token'])) {
            $me = $this->api->getMe($telegramChannelLink->refresh());
            $telegramChannelLink->update(['bot_username' => $me['username']]);
            $this->api->configureWebhook($telegramChannelLink);
        }

        $this->syncTenantChannel($telegramChannelLink, $data);

        return response()->json($this->serialize($telegramChannelLink->refresh()));
    }

    public function destroy(TelegramChannelLink $telegramChannelLink): JsonResponse
    {
        // El Channel del tenant se desactiva pero NO se borra: las
        // conversaciones y su historial se conservan.
        Tenant::find($telegramChannelLink->tenant_id)?->run(function () use ($telegramChannelLink) {
            \App\Models\Channel::query()
                ->where('type', \App\Models\Channel::TYPE_TELEGRAM)
                ->where('external_id', (string) $telegramChannelLink->id)
                ->update(['active' => false]);
        });

        $telegramChannelLink->delete();

        return response()->json(['ok' => true]);
    }

    /** Prueba el token y reintenta registrar el webhook. */
    public function test(TelegramChannelLink $telegramChannelLink): JsonResponse
    {
        $me = $this->api->getMe($telegramChannelLink);

        if ($me['ok'] && $me['username'] !== $telegramChannelLink->bot_username) {
            $telegramChannelLink->update(['bot_username' => $me['username']]);
        }

        return response()->json([
            'connection' => $me,
            'webhook_configured' => $this->api->configureWebhook($telegramChannelLink),
            'webhook_url' => $telegramChannelLink->webhookUrl(),
        ]);
    }

    /**
     * Crea/actualiza el Channel dentro del tenant dueño para que el canal
     * viva en la bandeja sin esperar el primer mensaje.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function syncTenantChannel(TelegramChannelLink $link, array $changes = []): void
    {
        Tenant::find($link->tenant_id)?->run(function () use ($link, $changes) {
            \App\Models\Channel::firstOrCreate(
                [
                    'property_id' => \App\Models\Property::firstOrFail()->id,
                    'type' => \App\Models\Channel::TYPE_TELEGRAM,
                    'external_id' => (string) $link->id,
                ],
                ['name' => $link->name ?: ($link->bot_username ? "Telegram @{$link->bot_username}" : 'Telegram'), 'mode' => 'auto', 'active' => true],
            );

            \App\Models\Channel::query()
                ->where('type', \App\Models\Channel::TYPE_TELEGRAM)
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
    public function serialize(TelegramChannelLink $link): array
    {
        return [
            'id' => $link->id,
            'tenant_id' => $link->tenant_id,
            'name' => $link->name,
            'bot_username' => $link->bot_username,
            'masked_token' => $link->maskedToken(),
            'webhook_url' => $link->webhookUrl(),
            'active' => $link->active,
            'last_event_at' => $link->last_event_at?->diffForHumans(short: true),
        ];
    }
}
