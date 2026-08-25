<?php

namespace App\Models\Central;

use Illuminate\Support\Str;

/**
 * Cuenta de TikTok conectada a un hotel (DB central) vía la Business
 * Messaging API. TikTok manda los eventos a /webhooks/tiktok/{webhook_token};
 * el token enruta al tenant dueño — mismo esquema que Evolution/Telegram.
 */
class TiktokChannelLink extends CentralModel
{
    protected $table = 'tiktok_channel_links';

    protected $fillable = [
        'tenant_id',
        'name',
        'business_id',
        'access_token',
        'webhook_token',
        'active',
        'last_event_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'active' => 'boolean',
            'last_event_at' => 'datetime',
        ];
    }

    public static function generateToken(): string
    {
        return Str::random(48);
    }

    /** URL que se pega como webhook en el panel de la app de TikTok. */
    public function webhookUrl(): string
    {
        return rtrim(config('app.url'), '/').'/webhooks/tiktok/'.$this->webhook_token;
    }

    public function maskedToken(): string
    {
        $token = (string) $this->access_token;

        return strlen($token) > 8 ? '••••'.substr($token, -6) : '••••';
    }
}
