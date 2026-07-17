<?php

namespace App\Models\Central;

/**
 * Solicitud de activación de un módulo hecha por el hotel desde "Tu plan"
 * (/ajustes). La fila ES la solicitud pendiente: aparece en la ficha del
 * hotel del admin y se borra al atenderla (forzar el módulo o descartarla).
 */
class ModuleActivationRequest extends CentralModel
{
    protected $table = 'module_activation_requests';

    protected $fillable = [
        'tenant_id',
        'module',
    ];
}
