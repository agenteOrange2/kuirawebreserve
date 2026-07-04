<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    /** @use HasFactory<\Database\Factories\ZoneFactory> */
    use HasFactory;

    /** Naturaleza de la zona (spec-profundidad §3): piso, edificio o área. */
    public const KINDS = [
        'piso' => 'Piso',
        'edificio' => 'Edificio',
        'area' => 'Área',
    ];

    protected $fillable = [
        'property_id',
        'name',
        'kind',
        'color',
        'sort_order',
    ];

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
