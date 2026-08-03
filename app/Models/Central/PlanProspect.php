<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contacto comercial generado desde la landing pública.
 */
class PlanProspect extends CentralModel
{
    /** @use HasFactory<\Database\Factories\Central\PlanProspectFactory> */
    use HasFactory;

    /** @var list<string> */
    public const STATUSES = ['new', 'contacted', 'qualified', 'won', 'lost'];

    protected $fillable = [
        'name',
        'hotel_name',
        'email',
        'phone',
        'rooms',
        'plan_key',
        'plan_label',
        'message',
        'status',
        'notes',
        'source',
        'ip_hash',
        'contacted_at',
    ];

    protected function casts(): array
    {
        return [
            'rooms' => 'integer',
            'contacted_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_key', 'key');
    }
}
