<?php

namespace App\Models\Central;

use Illuminate\Support\Str;

/**
 * Instancia de WhatsApp conectada vía Evolution API (self-hosted) — la
 * alternativa a la Cloud API de Meta. El webhook de cada instancia apunta
 * a /webhooks/evolution/{webhook_token}; el token enruta al tenant dueño.
 */
class EvolutionChannelLink extends CentralModel
{
    protected $table = 'evolution_channel_links';

    protected $fillable = [
        'tenant_id',
        'name',
        'base_url',
        'instance',
        'api_key',
        'webhook_token',
        'active',
        'last_event_at',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'active' => 'boolean',
            'last_event_at' => 'datetime',
        ];
    }

    public static function generateToken(): string
    {
        return Str::random(48);
    }

    /** URL que se configura como webhook de la instancia en Evolution. */
    public function webhookUrl(): string
    {
        return rtrim(config('app.url'), '/').'/webhooks/evolution/'.$this->webhook_token;
    }

    public function maskedKey(): string
    {
        $key = (string) $this->api_key;

        return strlen($key) <= 4 ? '••••' : '••••'.substr($key, -4);
    }
}
