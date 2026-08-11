<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Encuesta de experiencia de una estancia: nace junto al agradecimiento
 * post-check-out con un token público; el huésped responde una sola vez
 * en /encuesta/{token}. La calificación general y el comentario son
 * fijos; los ASPECTOS (1 a 5) los personaliza cada hotel en
 * /ajustes/encuestas y las respuestas van en el JSON `answers`.
 */
class StaySurvey extends Model
{
    /**
     * Aspectos default: el cuestionario completo del documento base (la
     * "experiencia general" es la calificación fija, no va aquí). Un hotel
     * sin personalizar pregunta estos 8; `cleanliness` y `service` además
     * leen las columnas legacy de respuestas viejas (ver LEGACY_COLUMNS).
     */
    public const DEFAULT_ASPECTS = [
        ['key' => 'service', 'label' => 'Atención del personal'],
        ['key' => 'cleanliness', 'label' => 'Limpieza'],
        ['key' => 'room_condition', 'label' => 'Estado de la habitación'],
        ['key' => 'comfort', 'label' => 'Comodidad'],
        ['key' => 'privacy', 'label' => 'Privacidad'],
        ['key' => 'security', 'label' => 'Seguridad'],
        ['key' => 'value', 'label' => 'Relación precio-servicio'],
        ['key' => 'food', 'label' => 'Alimentos y bebidas'],
    ];

    /** Respuestas guardadas en columnas antes de existir `answers`. */
    public const LEGACY_COLUMNS = [
        'cleanliness' => 'rating_cleanliness',
        'service' => 'rating_service',
        'facilities' => 'rating_facilities',
    ];

    protected $fillable = [
        'stay_id',
        'guest_id',
        'token',
        'rating',
        'answers',
        'rating_cleanliness',
        'rating_service',
        'rating_facilities',
        'comment',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'answers' => 'array',
            'rating_cleanliness' => 'integer',
            'rating_service' => 'integer',
            'rating_facilities' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * Aspectos vigentes del hotel: settings.survey_aspects o los default.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public static function aspects(): array
    {
        $configured = Property::query()->first()?->settings['survey_aspects'] ?? null;

        if (! is_array($configured)) {
            return self::DEFAULT_ASPECTS;
        }

        return collect($configured)
            ->filter(fn ($aspect) => is_array($aspect) && ! blank($aspect['key'] ?? null) && ! blank($aspect['label'] ?? null))
            ->map(fn (array $aspect) => ['key' => (string) $aspect['key'], 'label' => (string) $aspect['label']])
            ->values()
            ->all();
    }

    /** Respuesta de un aspecto: del JSON o de la columna legacy. */
    public function answerFor(string $key): ?int
    {
        $value = $this->answers[$key] ?? null;

        if ($value === null && isset(self::LEGACY_COLUMNS[$key])) {
            $value = $this->getAttribute(self::LEGACY_COLUMNS[$key]);
        }

        return $value !== null ? (int) $value : null;
    }

    /** La encuesta de la estancia, creándola con su token si no existe. */
    public static function forStay(Stay $stay): self
    {
        return self::query()->firstOrCreate(
            ['stay_id' => $stay->id],
            ['guest_id' => $stay->guest_id, 'token' => Str::random(40)],
        );
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class)->withTrashed();
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->whereNotNull('submitted_at');
    }
}
