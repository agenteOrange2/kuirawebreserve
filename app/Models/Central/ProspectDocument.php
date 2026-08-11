<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Documento comercial que se envía a prospectos según el servicio de interés.
 */
class ProspectDocument extends CentralModel
{
    /** @use HasFactory<\Database\Factories\Central\ProspectDocumentFactory> */
    use HasFactory;

    public const GENERAL_SERVICE = 'general';

    protected $fillable = [
        'uuid',
        'title',
        'service',
        'path',
        'original_name',
        'mime',
        'size',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProspectDocument $document) {
            if ($document->uuid === null || $document->uuid === '') {
                $document->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    /**
     * Documentos para un conjunto de servicios de interés (incluye los generales).
     *
     * @param  list<string>  $services
     */
    public function scopeForServices(Builder $query, array $services): Builder
    {
        return $query->whereIn('service', [...$services, self::GENERAL_SERVICE]);
    }

    public function publicUrl(): string
    {
        return route('prospects.documents.file', $this);
    }
}
