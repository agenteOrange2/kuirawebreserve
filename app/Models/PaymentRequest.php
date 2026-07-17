<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Solicitud de cobro (spec-pagos §4.1): el puente entre "apartado creado" y
 * "dinero confirmado". El bot o el staff la emiten; la cierra un humano que
 * verifica la transferencia (F0) o el webhook firmado de la pasarela (F1).
 * El LLM nunca la marca pagada.
 */
class PaymentRequest extends Model
{
    public const CONCEPT_DEPOSIT = 'deposit';

    public const CONCEPT_BALANCE = 'balance';

    public const CONCEPT_FULL = 'full';

    public const CONCEPT_CUSTOM = 'custom';

    public const METHOD_TRANSFER = 'transfer';

    public const METHOD_GATEWAY = 'gateway';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'uuid',
        'reservation_id',
        'experience_booking_id',
        'reservation_group_id',
        'concept',
        'amount',
        'currency',
        'method',
        'provider',
        'mode',
        'status',
        'checkout_url',
        'gateway_ref',
        'expires_at',
        'requested_by',
        'payment_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            $request->uuid ??= (string) Str::uuid();
        });
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function experienceBooking(): BelongsTo
    {
        return $this->belongsTo(ExperienceBooking::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ReservationGroup::class, 'reservation_group_id');
    }

    public function isForExperience(): bool
    {
        return $this->experience_booking_id !== null;
    }

    public function isForGroup(): bool
    {
        return $this->reservation_group_id !== null;
    }

    /** Folio de lo que se cobra: reserva, experiencia o grupo. */
    public function subjectCode(): string
    {
        return $this->reservation?->displayCode()
            ?? $this->experienceBooking?->displayCode()
            ?? $this->group?->displayCode()
            ?? $this->uuid;
    }

    /** Etiqueta humana del sujeto — la ven Stripe/PayPal/MP y el huésped. */
    public function subjectLabel(): string
    {
        return match (true) {
            $this->isForExperience() => 'Experiencia '.$this->subjectCode(),
            $this->isForGroup() => 'Reserva de grupo '.$this->subjectCode(),
            default => 'Reserva '.$this->subjectCode(),
        };
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Pendiente y sin vencer: la única que puede cobrarse. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isPayable(): bool
    {
        return $this->status === self::STATUS_PENDING
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function conceptLabel(): string
    {
        return match ($this->concept) {
            self::CONCEPT_DEPOSIT => 'Anticipo',
            self::CONCEPT_BALANCE => 'Saldo',
            self::CONCEPT_FULL => 'Pago total',
            self::CONCEPT_CUSTOM => 'Cobro',
            default => $this->concept,
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Por verificar',
            self::STATUS_PAID => 'Pagada',
            self::STATUS_EXPIRED => 'Vencida',
            self::STATUS_CANCELED => 'Cancelada',
            self::STATUS_REJECTED => 'Rechazada',
            default => $this->status,
        };
    }

    public function amountLabel(): string
    {
        return '$'.number_format((float) $this->amount, 2).' '.$this->currency;
    }
}
