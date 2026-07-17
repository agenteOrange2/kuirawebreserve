<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\States\Room\RoomState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\ModelStates\HasStates;

class Room extends Model
{
    /** @use HasFactory<\Database\Factories\RoomFactory> */
    use HasFactory, HasStates, LogsActivity;

    /**
     * Catálogo de tipos de cama (spec-profundidad §2.1) — el frontend y los
     * bots (fase 4) muestran estos labels.
     */
    public const BED_TYPES = [
        'king' => 'King size',
        'queen' => 'Queen size',
        'matrimonial' => 'Matrimonial',
        'individual' => 'Individual',
        'litera' => 'Litera',
        'sofa_cama' => 'Sofá cama',
    ];

    protected $fillable = [
        'property_id',
        'zone_id',
        'room_type_id',
        'number',
        'name',
        'description',
        'beds',
        'max_occupancy',
        'included_occupancy',
        'size_m2',
        'view',
        'amenities',
        'smoking',
        'accessible',
        'price_modifier',
        'extra_guest_fee',
        'optional_charges',
        'status',
        'pos_x',
        'pos_y',
        'width',
        'height',
        'notes',
        'maintenance_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => RoomState::class,
            'beds' => 'array',
            'max_occupancy' => 'integer',
            'included_occupancy' => 'integer',
            'size_m2' => 'decimal:2',
            'amenities' => 'array',
            'smoking' => 'boolean',
            'accessible' => 'boolean',
            'price_modifier' => 'decimal:2',
            'extra_guest_fee' => 'decimal:2',
            'optional_charges' => 'array',
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('room')
            ->logOnly(['number', 'name', 'status', 'zone_id', 'room_type_id', 'notes', 'maintenance_notes', 'price_modifier'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Ocupación máxima efectiva: override propio o capacidad del tipo.
     */
    public function effectiveMaxOccupancy(): ?int
    {
        return $this->max_occupancy ?? $this->roomType?->capacity;
    }

    /**
     * Amenities del tipo + extras propias, sin duplicados.
     *
     * @return array<int, string>
     */
    public function effectiveAmenities(): array
    {
        return array_values(array_unique(array_merge(
            $this->roomType?->amenities ?? [],
            $this->amenities ?? [],
        )));
    }

    /**
     * Desglose de cargos extra de la habitación para una ocupación dada:
     * línea automática por personas que exceden las incluidas en la tarifa
     * ($X por persona extra, por unidad de tarifa) + los cargos opcionales
     * elegidos por concepto (mascota, decoración… — una sola vez). El total
     * de estas líneas se suma al hospedaje al crear walk-in o reserva.
     *
     * @param  array<int, string>  $selectedConcepts
     * @return array<int, array{concept: string, amount: float, kind: string}>
     */
    public function extraChargeLines(int $people, int $units, array $selectedConcepts = []): array
    {
        $lines = [];

        $included = $this->included_occupancy;
        $fee = (float) ($this->extra_guest_fee ?? 0);

        if ($included !== null && $included > 0 && $fee > 0 && $people > $included) {
            $extra = $people - $included;
            $lines[] = [
                'concept' => 'Personas extra ('.$extra.')',
                'amount' => round($extra * $fee * max(1, $units), 2),
                'kind' => 'extra_guests',
            ];
        }

        foreach ($this->optional_charges ?? [] as $charge) {
            $concept = trim((string) ($charge['concept'] ?? ''));

            if ($concept === '' || ! in_array($concept, $selectedConcepts, true)) {
                continue;
            }

            $lines[] = [
                'concept' => $concept,
                'amount' => round((float) ($charge['amount'] ?? 0), 2),
                'kind' => 'optional',
            ];
        }

        return $lines;
    }

    /**
     * Camas legibles: "1 King size · 2 Individual".
     */
    public function bedsLabel(): ?string
    {
        if (empty($this->beds)) {
            return null;
        }

        return collect($this->beds)
            ->map(fn (array $bed) => trim(($bed['qty'] ?? 1).' '.(self::BED_TYPES[$bed['type'] ?? ''] ?? ($bed['type'] ?? ''))))
            ->implode(' · ');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(RoomStatusLog::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }

    public function activeStay(): HasOne
    {
        return $this->hasOne(Stay::class)
            ->where('status', Stay::STATUS_ACTIVE)
            ->latestOfMany('check_in_at');
    }

    public function upcomingReservation(): HasOne
    {
        return $this->hasOne(Reservation::class)
            ->whereIn('status', [
                ReservationStatus::Pending->value,
                ReservationStatus::Confirmed->value,
            ])
            ->where('ends_at', '>=', now())
            ->oldestOfMany('starts_at');
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Siguiente número libre a partir de uno dado: numéricos incrementan
     * (204 → 205, saltando ocupados); no numéricos agregan sufijo (A → A-2).
     */
    public static function nextAvailableNumber(string $after, int $propertyId): string
    {
        if (ctype_digit($after)) {
            $candidate = (int) $after + 1;

            while (static::query()->where('property_id', $propertyId)->where('number', (string) $candidate)->exists()) {
                $candidate++;
            }

            return (string) $candidate;
        }

        $suffix = 2;

        while (static::query()->where('property_id', $propertyId)->where('number', "{$after}-{$suffix}")->exists()) {
            $suffix++;
        }

        return "{$after}-{$suffix}";
    }

    /**
     * Duplica la habitación (misma zona/tipo/ficha) con el siguiente número
     * libre; la copia nace disponible y ligeramente desplazada en el plano.
     */
    public function duplicateAsNew(): self
    {
        $copy = $this->replicate(['status', 'notes', 'maintenance_notes']);
        $copy->number = static::nextAvailableNumber($this->number, $this->property_id);
        $copy->pos_x = (int) $this->pos_x + 30;
        $copy->pos_y = (int) $this->pos_y + 30;
        $copy->save();

        return $copy;
    }

    public function toFloorPlanPayload(): array
    {
        /** @var Stay|null $activeStay */
        $activeStay = $this->getRelationValue('activeStay');
        /** @var Reservation|null $upcomingReservation */
        $upcomingReservation = $this->getRelationValue('upcomingReservation');
        $todayHistory = $this->relationLoaded('statusLogs')
            ? $this->getRelation('statusLogs')
            : collect();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => $this->name,
            'description' => $this->description,
            'zone' => $this->zone?->name,
            'zone_id' => $this->zone_id,
            'zone_color' => $this->zone?->color,
            'room_type' => $this->roomType?->name,
            'capacity' => $this->effectiveMaxOccupancy(),
            'beds_label' => $this->bedsLabel(),
            'size_m2' => $this->size_m2 !== null ? (float) $this->size_m2 : null,
            'view' => $this->view,
            'smoking' => $this->smoking,
            'accessible' => $this->accessible,
            'amenities' => $this->effectiveAmenities(),
            'price_from' => $this->roomType?->priceFrom(),
            'price_modifier' => $this->price_modifier !== null ? (float) $this->price_modifier : null,
            'included_occupancy' => $this->included_occupancy,
            'extra_guest_fee' => $this->extra_guest_fee !== null ? (float) $this->extra_guest_fee : null,
            'optional_charges' => collect($this->optional_charges ?? [])
                ->map(fn (array $charge) => [
                    'concept' => (string) ($charge['concept'] ?? ''),
                    'amount' => round((float) ($charge['amount'] ?? 0), 2),
                ])
                ->values()
                ->all(),
            'check_in_time' => $this->roomType?->check_in_time ? substr($this->roomType->check_in_time, 0, 5) : null,
            'check_out_time' => $this->roomType?->check_out_time ? substr($this->roomType->check_out_time, 0, 5) : null,
            'status' => $this->status->getMorphClass(),
            'color' => $this->status->color(),
            'label' => $this->status->label(),
            'transitions' => $this->status->transitionableStates(),
            'pos_x' => $this->pos_x,
            'pos_y' => $this->pos_y,
            'width' => $this->width,
            'height' => $this->height,
            'notes' => $this->notes,
            'maintenance_notes' => $this->maintenance_notes,
            'rate_plans' => $this->roomType?->ratePlans
                ? $this->roomType->ratePlans
                    ->sortBy('price')
                    ->values()
                    ->map(fn (RatePlan $plan) => [
                        'id' => $plan->id,
                        'name' => $plan->name,
                        'type' => $plan->type->value,
                        // Precio ya ajustado con el modificador de la habitación
                        // (spec §2.1: el motor de precios lo suma por unidad).
                        'price' => max(0, round((float) $plan->price + (float) ($this->price_modifier ?? 0), 2)),
                        'duration_minutes' => $plan->duration_minutes,
                        'duration_label' => $plan->durationLabel(),
                    ])
                    ->all()
                : [],
            'active_stay' => $activeStay ? [
                'id' => $activeStay->id,
                'guest_name' => $activeStay->guest?->full_name ?? $activeStay->guest_name ?? 'Anónimo',
                'rate_plan' => $activeStay->ratePlan?->name,
                'channel' => $activeStay->channel,
                'amount' => (float) $activeStay->amount,
                'consumos_total' => round((float) ($activeStay->consumos_total ?? 0), 2),
                'total_due' => round((float) $activeStay->amount + (float) ($activeStay->consumos_total ?? 0), 2),
                'check_in_at' => $activeStay->check_in_at?->format('d/m/Y H:i'),
                'check_in_at_iso' => $activeStay->check_in_at?->toIso8601String(),
                'planned_end_at' => $activeStay->planned_end_at?->format('d/m/Y H:i'),
                'planned_end_at_iso' => $activeStay->planned_end_at?->toIso8601String(),
                'is_overdue' => $activeStay->planned_end_at?->isPast() ?? false,
                'reservation_id' => $activeStay->reservation_id,
                'num_people' => $activeStay->num_people,
                'vehicle_plate' => $activeStay->vehicle_plate,
                'vehicle_desc' => $activeStay->vehicle_desc,
            ] : null,
            'upcoming_reservation' => $upcomingReservation ? [
                'id' => $upcomingReservation->id,
                'code' => $upcomingReservation->displayCode(),
                'guest_name' => $upcomingReservation->guest?->full_name ?? $upcomingReservation->guest_name ?? 'Anónimo',
                'rate_plan' => $upcomingReservation->ratePlan?->name,
                'status' => $upcomingReservation->status->value,
                'status_label' => $upcomingReservation->status->label(),
                'total_amount' => (float) $upcomingReservation->total_amount,
                'starts_at' => $upcomingReservation->starts_at->format('d/m/Y H:i'),
                'starts_at_iso' => $upcomingReservation->starts_at->toIso8601String(),
                'starts_today' => $upcomingReservation->starts_at->isToday(),
                'ends_at' => $upcomingReservation->ends_at->format('d/m/Y H:i'),
                'ends_at_iso' => $upcomingReservation->ends_at->toIso8601String(),
                'eta' => $upcomingReservation->eta ? substr($upcomingReservation->eta, 0, 5) : null,
                'vehicle_plate' => $upcomingReservation->vehicle_plate,
                'adults' => $upcomingReservation->adults,
                'children' => $upcomingReservation->children,
            ] : null,
            'today_history' => $todayHistory
                ->sortByDesc('created_at')
                ->values()
                ->map(fn (RoomStatusLog $log) => [
                    'id' => $log->id,
                    'from_status' => $log->from_status,
                    'from_label' => $this->statusLabel($log->from_status),
                    'to_status' => $log->to_status,
                    'to_label' => $this->statusLabel($log->to_status),
                    'changed_by' => $log->changedBy?->name,
                    'auto' => (bool) ($log->context['auto'] ?? false),
                    'created_at' => $log->created_at?->format('H:i'),
                ])
                ->all(),
        ];
    }

    protected function statusLabel(?string $status): ?string
    {
        if (! $status) {
            return null;
        }

        return RoomStatus::tryFrom($status)?->label() ?? $status;
    }
}
