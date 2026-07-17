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
        'cancel_free_unit',
        'cancel_free_value',
        'cancel_penalty_percent',
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
            'cancel_free_unit' => RateDurationUnit::class,
            'cancel_free_value' => 'integer',
            'cancel_penalty_percent' => 'decimal:2',
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
     * Temporadas y promos (spec-motor-reservas-web E0.5): rangos de fechas
     * que SUSTITUYEN el precio de la tarifa mientras estén vigentes.
     */
    public function seasons(): HasMany
    {
        return $this->hasMany(RatePlanSeason::class);
    }

    /**
     * Temporada/promo activa que cubre esa fecha, o null si ninguna aplica
     * (rige el precio base de la tarifa). Si hay solape, gana la de mayor
     * `priority`; empate lo resuelve la más reciente (id mayor).
     */
    public function activeSeasonFor(CarbonInterface $date): ?RatePlanSeason
    {
        $candidates = $this->relationLoaded('seasons')
            ? $this->seasons->filter(fn (RatePlanSeason $s) => $s->active && $s->coversDate($date))
            : $this->seasons()
                ->where('active', true)
                ->whereDate('starts_on', '<=', $date->toDateString())
                ->whereDate('ends_on', '>=', $date->toDateString())
                ->get();

        return $candidates->isEmpty() ? null : $candidates
            ->sort(fn (RatePlanSeason $a, RatePlanSeason $b) => $b->priority <=> $a->priority ?: $b->id <=> $a->id)
            ->first();
    }

    /** Precio por unidad para esa fecha: el de la temporada activa, o el base. */
    protected function unitPriceForDate(CarbonInterface $date): float
    {
        return (float) ($this->activeSeasonFor($date)?->price ?? $this->price);
    }

    /**
     * Precio total para un rango: noches calendario (night, cada una con SU
     * propio precio de temporada si aplica) o periodos completos redondeados
     * hacia arriba (block, precio de temporada resuelto por el día de
     * inicio). Si se pasa la habitación, su price_modifier ajusta el precio
     * por unidad (spec-profundidad §2.1: +$100 vista al mar, −$50 interior).
     */
    public function priceFor(CarbonInterface $start, CarbonInterface $end, ?Room $room = null): float
    {
        $modifier = (float) ($room?->price_modifier ?? 0);

        if ($this->type === RatePlanType::Night) {
            $nights = $this->unitsFor($start, $end);
            // Reasignar en vez de mutar: now()/las fechas de Eloquent en esta
            // app son CarbonImmutable — un ->addDay() sin capturar el
            // resultado es un no-op silencioso y el cursor se queda pegado.
            $cursor = $start->copy()->startOfDay();
            $total = 0.0;
            for ($i = 0; $i < $nights; $i++) {
                $total += $this->unitPriceForDate($cursor) + $modifier;
                $cursor = $cursor->addDay();
            }

            return max(0, round($total, 2));
        }

        $units = $this->unitsFor($start, $end);
        $unitPrice = $this->unitPriceForDate($start) + $modifier;

        return max(0, round($units * $unitPrice, 2));
    }

    /**
     * Desglose de `priceFor()` en líneas explicables (spec-wizard-precios-y-
     * pasos §3): tarifa base o de temporada (agrupando noches consecutivas
     * de la misma temporada en una sola línea), ajuste del cuarto, y lo que
     * traiga `$extraChargeLines` (persona extra / cargos opcionales de
     * `Room::extraChargeLines()`). Puramente para mostrar — un solo lugar
     * para que el wizard, la Agent API y el panel expliquen el precio igual,
     * en vez de que cada canal invente su propio texto.
     *
     * @param  array<int, array{concept: string, amount: float, kind?: string}>  $extraChargeLines
     * @return array<int, array{concept: string, amount: float}>
     */
    public function priceBreakdown(CarbonInterface $start, CarbonInterface $end, ?Room $room, array $extraChargeLines = []): array
    {
        $units = $this->unitsFor($start, $end);
        $lines = [];

        if ($this->type === RatePlanType::Night) {
            $cursor = $start->copy()->startOfDay();
            $runs = [];
            for ($i = 0; $i < $units; $i++) {
                $season = $this->activeSeasonFor($cursor);
                $lastKey = array_key_last($runs);
                if ($lastKey !== null && $runs[$lastKey]['season']?->id === $season?->id) {
                    $runs[$lastKey]['nights']++;
                } else {
                    $runs[] = ['season' => $season, 'nights' => 1];
                }
                $cursor = $cursor->addDay();
            }

            foreach ($runs as $run) {
                $nights = $run['nights'];
                $unitPrice = (float) ($run['season']?->price ?? $this->price);
                $label = $run['season']?->name ?? 'Tarifa';
                $lines[] = [
                    'concept' => $label.' ('.$nights.' '.($nights === 1 ? 'noche' : 'noches').')',
                    'amount' => round($nights * $unitPrice, 2),
                ];
            }
        } else {
            $season = $this->activeSeasonFor($start);
            $unitPrice = (float) ($season?->price ?? $this->price);
            $label = $season?->name ?? 'Tarifa';
            $lines[] = [
                'concept' => $label.' ('.$this->durationLabel().($units > 1 ? " × {$units}" : '').')',
                'amount' => round($units * $unitPrice, 2),
            ];
        }

        $modifier = (float) ($room?->price_modifier ?? 0);
        if ($modifier !== 0.0) {
            $lines[] = [
                'concept' => $modifier > 0 ? 'Ajuste de esta habitación' : 'Descuento de esta habitación',
                'amount' => round($units * $modifier, 2),
            ];
        }

        foreach ($extraChargeLines as $line) {
            $lines[] = ['concept' => $line['concept'], 'amount' => round((float) $line['amount'], 2)];
        }

        return $lines;
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

    /**
     * Política de cancelación con dinero (spec-pagos F4 / pendientes §2.6):
     * sin costo hasta N unidades antes de la llegada; después se retiene
     * cancel_penalty_percent de lo pagado (null = se retiene todo).
     */
    public function hasCancellationPolicy(): bool
    {
        return $this->cancel_free_unit !== null && $this->cancel_free_value !== null;
    }

    /** Último momento para cancelar sin costo: llegada − ventana. */
    public function cancelFreeDeadlineFor(CarbonInterface $start): ?CarbonInterface
    {
        if (! $this->hasCancellationPolicy()) {
            return null;
        }

        return $this->cancel_free_unit->subtractFrom($start, $this->cancel_free_value);
    }

    public function cancellationPolicyLabel(): ?string
    {
        if (! $this->hasCancellationPolicy()) {
            return null;
        }

        $penalty = $this->cancel_penalty_percent !== null ? (float) $this->cancel_penalty_percent : 100.0;
        $after = $penalty >= 100
            ? 'después no hay reembolso'
            : 'después se retiene el '.rtrim(rtrim(number_format($penalty, 2), '0'), '.').'% de lo pagado';

        return 'Cancelación sin costo hasta '.$this->cancel_free_unit->label($this->cancel_free_value)
            .' antes de la llegada; '.$after.'.';
    }

    protected function durationUnit(): RateDurationUnit
    {
        // Tarifas viejas sin unidad: duration_minutes expresado en minutos.
        return $this->duration_unit ?? RateDurationUnit::Minute;
    }
}
