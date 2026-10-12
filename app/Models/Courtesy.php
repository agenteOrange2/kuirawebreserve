<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cortesía (módulo cortesias): hospedaje gratis o con descuento con motivo.
 *
 * El propietario la da directo (nace `approved`); el resto del personal la
 * solicita (nace `pending`) y solo un propietario la decide. Mientras está
 * pendiente la cuenta sigue con su precio normal: el descuento entra al
 * aprobarse. Una sola viva (pendiente o aprobada) por reserva o estancia.
 *
 * Solo toca el HOSPEDAJE (tarifa y persona extra): consumos, extras,
 * cargos opcionales, daños y fianza se cobran normal. Único escritor:
 * App\Services\Courtesies\CourtesyService.
 */
class Courtesy extends Model
{
    public const KIND_FULL = 'full';

    public const KIND_PERCENT = 'percent';

    public const KIND_AMOUNT = 'amount';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_REVOKED = 'revoked';

    public const KINDS = [self::KIND_FULL, self::KIND_PERCENT, self::KIND_AMOUNT];

    public const LIVE = [self::STATUS_PENDING, self::STATUS_APPROVED];

    protected $fillable = [
        'reservation_id',
        'stay_id',
        'guest_id',
        'guest_name',
        'kind',
        'value',
        'lodging_base',
        'amount',
        'category',
        'reason',
        'status',
        'requested_by',
        'decided_by',
        'decided_at',
        'decision_note',
        'over_cap',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'lodging_base' => 'decimal:2',
            'amount' => 'decimal:2',
            'decided_at' => 'datetime',
            'over_cap' => 'boolean',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class)->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::KIND_FULL => 'Gratis',
            self::KIND_PERCENT => rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').'% de descuento',
            default => '$'.number_format((float) $this->value, 2).' de descuento',
        };
    }

    public function categoryLabel(): string
    {
        return config('courtesies.categories.'.$this->category, 'Otro');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Esperando al propietario',
            self::STATUS_APPROVED => 'Aprobada',
            self::STATUS_REJECTED => 'Rechazada',
            self::STATUS_CANCELED => 'Cancelada',
            self::STATUS_REVOKED => 'Retirada',
            default => $this->status,
        };
    }

    /** Folio legible de lo que cubre: la reserva o la estancia de mostrador. */
    public function targetLabel(): string
    {
        if ($this->reservation_id && $this->relationLoaded('reservation') && $this->reservation) {
            return $this->reservation->displayCode();
        }

        return $this->reservation_id ? 'Reserva '.$this->reservation_id : 'Estancia '.$this->stay_id;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'kind' => $this->kind,
            'kind_label' => $this->kindLabel(),
            'value' => (float) $this->value,
            'lodging_base' => (float) $this->lodging_base,
            'amount' => (float) $this->amount,
            'category' => $this->category,
            'category_label' => $this->categoryLabel(),
            'reason' => $this->reason,
            'requested_by' => $this->requested_by,
            'requested_by_name' => $this->requester?->name,
            'decided_by_name' => $this->decider?->name,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'over_cap' => (bool) $this->over_cap,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
