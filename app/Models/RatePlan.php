<?php

namespace App\Models;

use App\Enums\RateDurationUnit;
use App\Enums\RatePlanType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RatePlan extends Model
{
    /** @use HasFactory<\Database\Factories\RatePlanFactory> */
    use HasFactory;

    protected $fillable = [
        'property_id',
        'room_type_id',
        'name',
        'type',
        'duration_minutes',
        'duration_unit',
        'duration_value',
        'price',
        'deposit_percent',
        'min_advance_unit',
        'min_advance_value',
        'payment_due_unit',
        'payment_due_value',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'type' => RatePlanType::class,
            'duration_minutes' => 'integer',
            'duration_unit' => RateDurationUnit::class,
            'duration_value' => 'integer',
            'min_advance_unit' => RateDurationUnit::class,
            'min_advance_value' => 'integer',
            'payment_due_unit' => RateDurationUnit::class,
            'payment_due_value' => 'integer',
            'price' => 'decimal:2',
            'deposit_percent' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Precio total para un rango: noches calendario (night) o periodos
     * completos redondeados hacia arriba (block con su unidad). Si se pasa
     * la habitación, su price_modifier ajusta el precio por unidad
     * (spec-profundidad §2.1: +$100 vista al mar, −$50 interior).
     */
    public function priceFor(CarbonInterface $start, CarbonInterface $end, ?Room $room = null): float
    {
        $units = $this->unitsFor($start, $end);
        $unitPrice = (float) $this->price + (float) ($room?->price_modifier ?? 0);

        return max(0, round($units * $unitPrice, 2));
    }

    public function unitsFor(CarbonInterface $start, CarbonInterface $end): int
    {
        if ($this->type === RatePlanType::Night) {
            return max(1, $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()));
        }

        $unit = $this->durationUnit();
        $value = max(1, $this->duration_value ?? $this->duration_minutes ?? 60);

        // Los meses son calendario (5 feb → 5 mar = 1 mes), no minutos fijos:
        // se avanza periodo a periodo hasta cubrir el rango.
        if ($unit->minutes() === null) {
            $units = 0;
            $cursor = $start->copy();

            while ($cursor < $end) {
                $cursor = $unit->addTo($cursor, $value);
                $units++;
            }

            return max(1, $units);
        }

        $periodMinutes = $unit->minutes() * $value;

        return max(1, (int) ceil($start->diffInMinutes($end) / $periodMinutes));
    }

    /**
     * Fin sugerido: inicio + un periodo (pre-llena la salida en panel/bots).
     */
    public function suggestedEnd(CarbonInterface $start): CarbonInterface
    {
        if ($this->type === RatePlanType::Night) {
            return $start->copy()->addDay()->setTime(12, 0);
        }

        return $this->durationUnit()->addTo(
            $start,
            max(1, $this->duration_value ?? $this->duration_minutes ?? 60),
        );
    }

    /**
     * Duración legible: "por noche" · "3 horas" · "1 semana" · "2 meses".
     */
    public function durationLabel(): string
    {
        if ($this->type === RatePlanType::Night) {
            return 'por noche';
        }

        return $this->durationUnit()->label(
            max(1, $this->duration_value ?? $this->duration_minutes ?? 60),
        );
    }

    /**
     * Antelación mínima (spec §2.6.2). Null = sin restricción.
     */
    public function earliestStartAt(): ?CarbonInterface
    {
        if (! $this->min_advance_unit || ! $this->min_advance_value) {
            return null;
        }

        return $this->min_advance_unit->addTo(now(), $this->min_advance_value);
    }

    public function violatesMinAdvance(CarbonInterface $start): bool
    {
        $earliest = $this->earliestStartAt();

        return $earliest !== null && $start < $earliest;
    }

    public function minAdvanceLabel(): ?string
    {
        if (! $this->min_advance_unit || ! $this->min_advance_value) {
            return null;
        }

        return $this->min_advance_unit->label($this->min_advance_value);
    }

    /**
     * Cobro anticipado (spec §2.6.3). Null = la tarifa no lo exige.
     */
    public function requiresPrepayment(): bool
    {
        return $this->deposit_percent !== null && (float) $this->deposit_percent > 0;
    }

    public function depositAmountFor(float $total): ?float
    {
        if (! $this->requiresPrepayment()) {
            return null;
        }

        return round($total * (float) $this->deposit_percent / 100, 2);
    }

    /**
     * Fecha límite para liquidar el total: llegada − ventana configurada.
     */
    public function paymentDueAt(CarbonInterface $start): ?CarbonInterface
    {
        if (! $this->payment_due_unit || ! $this->payment_due_value) {
            return null;
        }

        return $this->payment_due_unit->subtractFrom($start, $this->payment_due_value);
    }

    public function paymentDueLabel(): ?string
    {
        if (! $this->payment_due_unit || ! $this->payment_due_value) {
            return null;
        }

        return $this->payment_due_unit->label($this->payment_due_value).' antes de la llegada';
    }

    protected function durationUnit(): RateDurationUnit
    {
        // Tarifas viejas sin unidad: duration_minutes expresado en minutos.
        return $this->duration_unit ?? RateDurationUnit::Minute;
    }
}
