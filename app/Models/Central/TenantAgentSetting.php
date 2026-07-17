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
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'byok_allowed' => 'boolean',
            'api_allowed' => 'boolean',
            'context_editable' => 'boolean',
            'guidelines_editable' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PlatformAiProvider::class, 'platform_ai_provider_id');
    }

    public static function for(string $tenantId): self
    {
        $row = self::firstOrCreate(['tenant_id' => $tenantId]);

        // Recién creado: hidratar los defaults de la BD (enabled, byok…).
        return $row->wasRecentlyCreated ? $row->refresh() : $row;
    }
}
