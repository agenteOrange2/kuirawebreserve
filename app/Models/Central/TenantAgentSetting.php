<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuración del agente IA por tenant (DB central): kill switch,
 * proveedor asignado, cuota y permiso de BYOK. La administra el
 * super-admin desde /admin.
 */
class TenantAgentSetting extends CentralModel
{
    protected $table = 'tenant_agent_settings';

    protected $fillable = [
        'tenant_id',
        'enabled',
        'platform_ai_provider_id',
        'monthly_reply_limit',
        'byok_allowed',
        'api_allowed',
        'platform_instructions',
        'context_editable',
        'guidelines_editable',
        'channels_allowed',
    ];

    /** Canales que la plataforma puede habilitar por hotel. */
    public const CHANNELS = [
        'evolution' => 'WhatsApp (Evolution API)',
        'meta' => 'WhatsApp (Cloud API de Meta)',
        'telegram' => 'Telegram',
        'tiktok' => 'TikTok',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'byok_allowed' => 'boolean',
            'api_allowed' => 'boolean',
            'context_editable' => 'boolean',
            'guidelines_editable' => 'boolean',
            'channels_allowed' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PlatformAiProvider::class, 'platform_ai_provider_id');
    }

    /**
     * Canales habilitados para este hotel. NULL en la columna significa
     * "todos": así ningún hotel pierde lo que ya tenía al desplegar.
     *
     * @return array<int, string>
     */
    public function allowedChannels(): array
    {
        $allowed = $this->channels_allowed;

        if (! is_array($allowed)) {
            return array_keys(self::CHANNELS);
        }

        return array_values(array_intersect(array_keys(self::CHANNELS), $allowed));
    }

    public function allowsChannel(string $key): bool
    {
        return in_array($key, $this->allowedChannels(), true);
    }

    public static function for(string $tenantId): self
    {
        $row = self::firstOrCreate(['tenant_id' => $tenantId]);

        // Recién creado: hidratar los defaults de la BD (enabled, byok…).
        return $row->wasRecentlyCreated ? $row->refresh() : $row;
    }
}
