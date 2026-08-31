<?php

namespace App\Models\Central;

/**
 * App de Meta propia de un hotel (DB central). El hotel que tiene fila aquí
 * firma sus webhooks y canjea sus tokens con SU app; el que no, usa la app
 * de la plataforma (config/meta.php). Un solo webhook central atiende a
 * todas las apps: la firma se valida contra todas las claves registradas.
 */
class TenantMetaApp extends CentralModel
{
    protected $table = 'tenant_meta_apps';

    protected $fillable = [
        'tenant_id',
        'app_id',
        'app_secret',
        'ig_app_secret',
        'login_config_id',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'ig_app_secret' => 'encrypted',
        ];
    }

    public static function forTenant(?string $tenantId): ?self
    {
        return $tenantId
            ? self::query()->where('tenant_id', $tenantId)->first()
            : null;
    }

    public function maskedSecret(string $field): string
    {
        $value = (string) $this->{$field};

        return strlen($value) > 8 ? '••••'.substr($value, -4) : ($value === '' ? '' : '••••');
    }
}
