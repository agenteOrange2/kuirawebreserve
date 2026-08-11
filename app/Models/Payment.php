<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Abono registrado a una reserva o a una estancia (folio): el libro de
 * dinero CONFIRMADO, append-only. Los intentos de cobro (pendientes,
 * vencidos, rechazados) viven en payment_requests, no aquí.
 *
 * `kind`: null = abono normal de reserva · 'lodging' = hospedaje liquidado
 * en el folio (walk-in) · 'consumption' = consumos POS del folio.
 */
class Payment extends Model
{
    use LogsActivity;
    public const UPDATED_AT = null;

    /** Métodos de mostrador (los que el staff captura a mano). */
    public const METHODS = ['cash', 'card', 'transfer'];

    /**
     * Pago cobrado por pasarela (spec-pagos §4.2): nunca se captura a mano
     * (lo crea el webhook) y se excluye del corte de caja (received_by null).
     */
    public const METHOD_ONLINE = 'online';

    public const KIND_LODGING = 'lodging';

    public const KIND_CONSUMPTION = 'consumption';

    /**
     * Fianza (depósito en garantía) cobrada al registrar la llegada: NO es
     * ingreso, es un pasivo que se devuelve al registrar la salida (Refund
     * manual) salvo retención explícita por daños. Stay::folio() y
     * CashCutService la excluyen de los totales de hospedaje/venta.
     */
    public const KIND_GUARANTEE = 'guarantee';

    protected $fillable = [
        'reservation_id',
        'experience_booking_id',
        'stay_id',
        'payment_request_id',
        'amount',
        'fee_amount',
        'method',
        'gateway',
        'gateway_ref',
        'kind',
        'reference',
        'notes',
        'received_by',
        'shift_id',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Bitácora: "quién registró un pago" es requisito del documento base.
     * El libro es append-only, así que en la práctica solo se loguean
     * creaciones; el causer lo resuelve spatie (usuario autenticado, null
     * para webhooks de pasarela = Sistema).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('payment')
            ->logOnly(['amount', 'method', 'kind', 'reservation_id', 'stay_id', 'received_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(function (self $payment): void {
            // El turno se resuelve solo: los cobros nacen en media docena de
            // acciones distintas (walk-in, folio, abono, pasarela...) y
            // ponerlo en cada una se olvidaría en la siguiente que se sume.
            // Los cobros de pasarela no traen encargado y quedan sin turno,
            // que es justo lo correcto: no pasaron por ninguna caja.
            if ($payment->shift_id !== null || $payment->received_by === null) {
                return;
            }

            $payment->shift_id = Shift::query()
                ->open()
                ->where('user_id', $payment->received_by)
                ->latest('started_at')
                ->value('id');
        });
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** La solicitud que originó este pago (transferencia o pasarela). */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function refunds(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function refundedTotal(): float
    {
        return round((float) $this->refunds()->where('status', Refund::STATUS_COMPLETED)->sum('amount'), 2);
    }

    /** Lo que aún puede devolverse de este pago. */
    public function refundableAmount(): float
    {
        return max(0, round((float) $this->amount - $this->refundedTotal(), 2));
    }

    public static function methodLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            default => $method,
        };
    }
}
