<?php

namespace App\Models\Central;

/**
 * Override de un módulo por hotel (DB central). Sin fila = el hotel hereda
 * los módulos de su plan; con fila = el admin lo forzó encendido o apagado.
 * La verdad efectiva la resuelve Tenant::hasModule().
 */
class TenantModule extends CentralModel
{
    protected $table = 'tenant_modules';

    protected $fillable = [
        'tenant_id',
        'module',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
