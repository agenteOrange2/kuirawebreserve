<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Vehículo del que llega al motel (docs/spec-modo-motel.md): en caseta no se
 * registra al huésped, se registra el carro — placa, marca y modelo, y ya.
 * Es el equivalente motelero de Guest: la placa es la identidad y su historial
 * son las estancias que trae colgadas.
 *
 * OJO, no confundir con App\Models\ExperienceVehicle, que es la FLOTA del
 * hotel (razers, camionetas) para los tours del módulo experiencias.
 *
 * Convivencia de datos, para que nadie invente una cuarta representación:
 * - `stays.vehicle_plate` es la placa tal como se tecleó esa noche (sello
 *   histórico inmutable, mismo patrón que `stays.guest_name`).
 * - `vehicles` es la FICHA: marca, modelo, color, notas y veto. Manda aquí.
 * - `stays.vehicle_id` es el vínculo, y solo lo escribe VehicleRegistry.
 * - `guests.meta['vehicle']` sigue siendo el vehículo del CRM de hotel.
 */
class Vehicle extends Model implements HasMedia
{
    // Archivado en vez de borrado cuando la placa ya tiene historial: misma
    // política que Guest, para no dejar estancias huérfanas.
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'plate',
        'plate_normalized',
        'brand',
        'model',
        'color',
        'year',
        'notes',
        'guest_id',
        'is_blacklisted',
        'blacklist_reason',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'is_blacklisted' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        // Fotos del vehículo; disco privado, igual que las del documento.
        $this->addMediaCollection('photos')->useDisk('local');
    }

    /**
     * ÚNICA definición de "la misma placa" en todo el proyecto: mayúsculas y
     * solo alfanuméricos, para que "abc-123-d", "ABC 123 D" y "ABC123D" sean
     * el mismo carro. Devuelve null cuando lo tecleado no puede ser una placa
     * ("N/A", "-", "SIN", "ABC"): con menos de 4 caracteres útiles preferimos
     * no crear ficha a crear basura que después nadie limpia.
     */
    public static function normalizePlate(?string $plate): ?string
    {
        $normalized = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim((string) $plate)));

        return strlen((string) $normalized) >= 4 ? $normalized : null;
    }

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /** Marca, modelo y color en una línea; null cuando no se capturó nada. */
    public function label(): ?string
    {
        $parts = array_filter([$this->brand, $this->model, $this->color]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * Busca por placa (normalizando el término, para que dé igual cómo la
     * teclee quien busca) o por marca, modelo y color.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $plate = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($term));

        return $query->where(function (Builder $q) use ($term, $plate) {
            if ($plate !== '') {
                $q->where('plate_normalized', 'like', "%{$plate}%");
            }

            $q->orWhere('brand', 'like', "%{$term}%")
                ->orWhere('model', 'like', "%{$term}%")
                ->orWhere('color', 'like', "%{$term}%");
        });
    }

    /**
     * Métricas de la ficha, calcadas de Guest::metrics(): la verdad se lee de
     * las estancias, sin contadores denormalizados que se puedan desfasar.
     *
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        $stays = $this->stays()->get(['id', 'status', 'amount', 'check_in_at']);
        $completed = $stays->where('status', Stay::STATUS_COMPLETED);

        $consumos = Order::whereIn('stay_id', $stays->pluck('id'))
            ->where('status', Order::STATUS_COMPLETED)
            ->sum('total');

        $lodging = $stays->whereIn('status', [Stay::STATUS_COMPLETED, Stay::STATUS_ACTIVE])
            ->sum(fn (Stay $stay) => (float) $stay->amount);

        return [
            'visits' => $completed->count(),
            'is_inside' => $stays->firstWhere('status', Stay::STATUS_ACTIVE) !== null,
            'total_spent' => round($lodging + (float) $consumos, 2),
            'first_visit' => $stays->min('check_in_at')?->format('d/m/Y'),
            'last_visit' => $stays->max('check_in_at')?->format('d/m/Y'),
        ];
    }
}
