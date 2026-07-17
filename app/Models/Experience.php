<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Experiencia (módulo `experiencias`, spec-reservas-avanzado §3): tour o
 * recorrido reservable POR SÍ SOLO, con sesiones (fecha/hora) y cupo
 * propios. Precio por persona o por grupo (pricing_mode).
 */
class Experience extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;

    public const PRICING_MODES = [
        'per_person' => 'Por persona',
        'flat' => 'Por grupo (precio fijo)',
    ];

    protected $fillable = [
        'property_id',
        'name',
        'description',
        'includes',
        'duration_minutes',
        'pricing_mode',
        'price',
        'min_people',
        'max_people',
        'operating_days',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'includes' => 'array',
            'duration_minutes' => 'integer',
            'price' => 'decimal:2',
            'min_people' => 'integer',
            'max_people' => 'integer',
            'operating_days' => 'array',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Fotos públicas (misma mecánica que RoomType). */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos')->useDisk('public');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(720)
            ->nonQueued()
            ->performOnCollections('photos');
    }

    /** @return array<int, array<string, mixed>> */
    public function photosPayload(): array
    {
        return $this->getMedia('photos')->map(fn (Media $media) => [
            'id' => $media->id,
            'url' => route('tenant.experience-photo', ['mediaId' => $media->id]),
            'thumb_url' => route('tenant.experience-photo', ['mediaId' => $media->id, 'v' => 'thumb']),
        ])->values()->all();
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ExperienceSession::class);
    }

    /** Horarios recurrentes de la programación semanal (con operating_days). */
    public function slots(): HasMany
    {
        return $this->hasMany(ExperienceSlot::class);
    }

    /** ¿Tiene programación semanal completa (días + al menos un horario vivo)? */
    public function hasSchedule(): bool
    {
        return ! empty($this->operating_days) && $this->slots()->where('active', true)->exists();
    }

    /** Total para N personas según la modalidad de precio. */
    public function totalFor(int $people): float
    {
        return $this->pricing_mode === 'flat'
            ? (float) $this->price
            : round((float) $this->price * $people, 2);
    }

    public function durationLabel(): ?string
    {
        if (! $this->duration_minutes) {
            return null;
        }

        $hours = intdiv($this->duration_minutes, 60);
        $minutes = $this->duration_minutes % 60;

        return trim(($hours ? "{$hours} h" : '').($minutes ? " {$minutes} min" : ''));
    }

    public function priceLabel(): string
    {
        $amount = '$'.number_format((float) $this->price, 2);

        return $this->pricing_mode === 'flat' ? "{$amount} por grupo" : "{$amount} por persona";
    }
}
