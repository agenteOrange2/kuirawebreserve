<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomType extends Model
{
    /** @use HasFactory<\Database\Factories\RoomTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'property_id',
        'name',
        'description',
        'capacity',
        'max_adults',
        'max_children',
        'base_price',
        'check_in_time',
        'check_out_time',
        'amenities',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'max_adults' => 'integer',
            'max_children' => 'integer',
            'base_price' => 'decimal:2',
            'amenities' => 'array',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function ratePlans(): HasMany
    {
        return $this->hasMany(RatePlan::class);
    }
}
