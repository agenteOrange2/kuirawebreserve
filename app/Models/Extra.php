<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Add-on de reserva (módulo `extras`): decoración, desayuno, late
 * checkout... Sin calendario ni cupo — vive pegado a la reserva y suma a
 * su total. NO confundir con los productos del POS (paso Extras del
 * wizard con módulo `pos`) ni con Experiencias (cupo/horario propios).
 */
class Extra extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id',
        'name',
        'description',
        'price',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
