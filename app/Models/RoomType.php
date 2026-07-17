<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class RoomType extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\RoomTypeFactory> */
    use HasFactory;
    use InteractsWithMedia;

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

    /**
     * Fotos del tipo (galería del wizard y del catálogo). A diferencia de
     * los documentos de huéspedes (disco local, privados), estas son
     * públicas: se sirven por la ruta tenant.room-type-photo sin login.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos')->useDisk('public');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Miniatura para tarjetas; sin cola (nonQueued) para que la foto
        // aparezca al instante tras subirla.
        $this->addMediaConversion('thumb')
            ->width(720)
            ->nonQueued()
            ->performOnCollections('photos');
    }

    /**
     * Horarios efectivos del tipo para reservas por noche: los propios del
     * tipo (Catálogo → Horarios) mandan; sin ellos, los del hotel
     * (Ajustes); sin nada, 15:00/12:00. Devuelve [hora, minuto].
     *
     * @return array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}
     */
    public function effectiveScheduleTimes(): array
    {
        $settings = $this->property?->settings ?? [];

        $parse = fn (?string $time, string $fallback) => array_map(
            'intval',
            array_pad(explode(':', $time ?: $fallback), 2, '0'),
        );

        return [
            $parse($this->check_in_time ? substr($this->check_in_time, 0, 5) : null, $settings['check_in_time'] ?? '15:00'),
            $parse($this->check_out_time ? substr($this->check_out_time, 0, 5) : null, $settings['check_out_time'] ?? '12:00'),
        ];
    }

    /**
     * Payload de fotos para el panel y el wizard: la primera es la portada.
     *
     * @return array<int, array<string, mixed>>
     */
    public function photosPayload(): array
    {
        return $this->getMedia('photos')->map(fn (Media $media) => [
            'id' => $media->id,
            'url' => route('tenant.room-type-photo', ['mediaId' => $media->id]),
            'thumb_url' => route('tenant.room-type-photo', ['mediaId' => $media->id, 'v' => 'thumb']),
        ])->values()->all();
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

    /**
     * "Precio desde": la tarifa activa más barata. Es el precio único que se
     * muestra en catálogo/plano/web (spec-plan-maestro E2): base_price quedó
     * deprecado — el precio que cobra el sistema SIEMPRE es el de la tarifa.
     * Si la query lo trajo con withMin (alias price_from), se usa ese valor.
     */
    public function priceFrom(): ?float
    {
        $value = match (true) {
            array_key_exists('price_from', $this->attributes) => $this->attributes['price_from'],
            $this->relationLoaded('ratePlans') => $this->getRelation('ratePlans')->where('active', true)->min('price'),
            default => $this->ratePlans()->where('active', true)->min('price'),
        };

        return $value !== null ? (float) $value : null;
    }

    /** Sin tarifa activa el tipo NO es reservable (guarda visible en UI). */
    public function hasActiveRate(): bool
    {
        return $this->priceFrom() !== null;
    }

    /**
     * Duplica el tipo CON sus tarifas (alta rápida de catálogos parecidos).
     */
    public function duplicateWithRatePlans(): self
    {
        $copy = $this->replicate(['rooms_count', 'price_from']);
        $copy->name = "{$this->name} (copia)";
        $copy->sort_order = (int) static::query()
            ->where('property_id', $this->property_id)
            ->max('sort_order') + 1;
        $copy->save();

        $this->ratePlans()->get()->each(function (RatePlan $plan) use ($copy) {
            $clone = $plan->replicate();
            $clone->room_type_id = $copy->id;
            $clone->save();
        });

        return $copy;
    }
}
