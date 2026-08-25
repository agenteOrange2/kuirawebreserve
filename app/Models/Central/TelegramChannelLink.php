<?php

namespace App\Models\Central;

use Illuminate\Support\Str;

/**
 * Bot de Telegram conectado a un hotel (DB central). El webhook del bot
 * apunta a /webhooks/telegram/{webhook_token}; el token enruta al tenant
 * dueño de la conversación — mismo esquema que Evolution.
 */
class TelegramChannelLink extends CentralModel
{
    protected $table = 'telegram_channel_links';

    protected $fillable = [
        'tenant_id',
        'name',
        'bot_id',
        'bot_token',
        'bot_username',
        'webhook_token',
        'active',
        'last_event_at',
    ];

    protected function casts(): array
    {
        return [
            'bot_token' => 'encrypted',
            'active' => 'boolean',
            'last_event_at' => 'datetime',
        ];
    }

    public static function generateToken(): string
    {
        return Str::random(48);
    }

    /** Parte numérica del token de BotFather ("123456:ABC…" → "123456"). */
    public static function botIdFromToken(string $token): string
    {
        return strstr($token, ':', true) ?: $token;
    }

    /** URL que se registra con setWebhook en la Bot API. */
    public function webhookUrl(): string
    {
        return rtrim(config('app.url'), '/').'/webhooks/telegram/'.$this->webhook_token;
    }

    public function maskedToken(): string
    {
        $token = (string) $this->bot_token;

        return strlen($token) > 8 ? '••••'.substr($token, -6) : '••••';
    }
}
