<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial dedicado del semáforo: quién, cuándo, de → a (spec §6).
 */
class RoomStatusLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'room_id',
        'from_status',
        'to_status',
        'changed_by',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
