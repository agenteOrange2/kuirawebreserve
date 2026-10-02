<?php

namespace App\Models\Central;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un renglón de la bitácora del panel de plataforma. Lo escriben
 * RecordAdminActivity (toda acción que cambia algo bajo /admin) y los
 * eventos de acceso; se lee en la ficha del usuario (/admin/usuarios/{id}).
 */
class AdminActivity extends CentralModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'tenant_id',
        'properties',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
