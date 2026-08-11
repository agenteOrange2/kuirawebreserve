<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contratación de un servicio adicional por un hotel (DB central). La fila
 * existe = el servicio está contratado y su precio se suma al plan.
 */
class TenantAddonService extends CentralModel
{
    protected $fillable = [
        'tenant_id',
        'addon_service_key',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(AddonService::class, 'addon_service_key');
    }
}
