<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Builder;

/**
 * Plan de la plataforma (DB central). La forma canónica de consumirlos en
 * el resto del código sigue siendo config('plans') — AppServiceProvider
 * la hidrata desde esta tabla en cada request.
 */
class Plan extends CentralModel
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'label',
        'description',
        'price_monthly',
        'max_properties',
        'max_rooms',
        'max_users',
        'max_channels',
        'max_gateways',
        'modules',
        'ai_enabled',
        'ai_monthly_replies',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'ai_enabled' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('key');
    }

    /** La forma que espera config('plans.{key}') en todo el código. */
    public function toConfigArray(): array
    {
        // La verdad de los módulos es la columna `modules`; ai_enabled queda
        // como columna espejo un ciclo por compatibilidad (se sincroniza al
        // guardar en PlanController). Si la migración aún no corrió
        // (modules null), la columna vieja manda.
        $modules = $this->modules ?? ($this->ai_enabled ? ['pos', 'agente-ia'] : ['pos']);

        return [
            'label' => $this->label,
            'price_monthly' => (int) $this->price_monthly,
            'max_properties' => $this->max_properties !== null ? (int) $this->max_properties : null,
            'max_rooms' => $this->max_rooms !== null ? (int) $this->max_rooms : null,
            'max_users' => $this->max_users !== null ? (int) $this->max_users : null,
            'max_channels' => $this->max_channels !== null ? (int) $this->max_channels : null,
            'max_gateways' => $this->max_gateways !== null ? (int) $this->max_gateways : null,
            'modules' => array_values($modules),
            'ai' => [
                'enabled' => in_array('agente-ia', $modules, true),
                'monthly_replies' => $this->ai_monthly_replies !== null ? (int) $this->ai_monthly_replies : null,
            ],
            'active' => $this->active,
        ];
    }
}
