<?php

namespace App\Models\Central;

use Illuminate\Support\Str;

/**
 * Token de un sitio conectado (DB central). El token en claro solo existe
 * en el momento de crearlo (se muestra una vez); aquí vive su sha256.
 */
class SiteIntegration extends CentralModel
{
    protected $table = 'site_integrations';

    protected $fillable = [
        'tenant_id',
        'label',
        'token_hash',
        'token_prefix',
        'domains',
        'active',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Crea la integración y devuelve el token EN CLARO (única vez que
     * existe fuera del hash).
     *
     * @param  list<string>  $domains
     * @return array{integration: self, token: string}
     */
    public static function generate(string $tenantId, string $label, array $domains = []): array
    {
        $token = 'ksk_'.Str::random(44);

        $integration = static::create([
            'tenant_id' => $tenantId,
            'label' => $label,
            'token_hash' => hash('sha256', $token),
            'token_prefix' => substr($token, 0, 12),
            'domains' => $domains ?: null,
            'active' => true,
        ]);

        return ['integration' => $integration, 'token' => $token];
    }

    /**
     * Resuelve un token en claro a su integración ACTIVA del tenant dado.
     */
    public static function findByToken(?string $token, string $tenantId): ?self
    {
        if (! $token) {
            return null;
        }

        return static::query()
            ->where('tenant_id', $tenantId)
            ->where('token_hash', hash('sha256', $token))
            ->where('active', true)
            ->first();
    }

    public function touchUsage(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
