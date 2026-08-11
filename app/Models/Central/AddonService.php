<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Builder;

/**
 * Servicio adicional de la plataforma (DB central): add-on que un hotel
 * contrata por encima de su plan base y se cobra aparte (documento
 * "Resumen de Planes, Servicios Adicionales e Inversión"). Cada servicio
 * enciende módulos del catálogo config/modules.php — la verdad efectiva
 * la resuelve Tenant::hasModule(), que suma plan + servicios contratados.
 */
class AddonService extends CentralModel
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'name',
        'summary',
        'objective',
        'recommendation',
        'price_monthly',
        'activation_fee',
        'modules',
        'ai_monthly_replies',
        'requires',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'active' => 'boolean',
        ];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('key');
    }

    /**
     * Keys de servicios contratados por hotel: ['tenant_id' => ['key', ...]].
     *
     * @return array<string, list<string>>
     */
    public static function contractedByTenant(): array
    {
        return TenantAddonService::query()
            ->get(['tenant_id', 'addon_service_key'])
            ->groupBy('tenant_id')
            ->map(fn ($rows) => $rows->pluck('addon_service_key')->all())
            ->all();
    }
}
