<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Cupón de descuento (módulo cupones): código que el huésped aplica en el
 * wizard público. percent = % sobre el subtotal; amount = monto fijo. El
 * descuento aplicado se congela en la reserva (coupon_code +
 * discount_amount); used_count se incrementa al CONFIRMARSE la reserva
 * (TransitionReservation), nunca en el hold.
 */
class Coupon extends Model
{
    use LogsActivity;
    public const KIND_PERCENT = 'percent';

    public const KIND_AMOUNT = 'amount';

    /** Ventana del cupón de cumpleaños: ± días alrededor de la fecha. */
    public const BIRTHDAY_WINDOW_DAYS = 7;

    protected $fillable = [
        'code',
        'kind',
        'value',
        'min_nights',
        'min_visits',
        'room_type_id',
        'birthday',
        'starts_at',
        'ends_at',
        'max_uses',
        'used_count',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_nights' => 'integer',
            'min_visits' => 'integer',
            'birthday' => 'boolean',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'active' => 'boolean',
        ];
    }

    public function roomType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Bitácora: quién creó, editó o apagó cada cupón. El canje NO pasa por
     * aquí (used_count se incrementa con query builder, sin eventos): lo
     * registra TransitionReservation sobre la reserva que lo usó.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('coupon')
            ->logOnly(['code', 'kind', 'value', 'active', 'starts_at', 'ends_at', 'max_uses'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** ¿Se puede aplicar hoy? Activo, dentro de vigencia y con usos libres. */
    public function isRedeemable(): bool
    {
        if (! $this->active) {
            return false;
        }

        $today = now()->toDateString();

        if ($this->starts_at !== null && $today < $this->starts_at->toDateString()) {
            return false;
        }

        if ($this->ends_at !== null && $today > $this->ends_at->toDateString()) {
            return false;
        }

        return $this->max_uses === null || $this->used_count < $this->max_uses;
    }

    /**
     * Condiciones del cupón contra la reserva concreta (documento base:
     * estancia larga, tipo de habitación, cliente frecuente, cumpleaños).
     * Devuelve NULL si todo cumple, o el motivo en texto para el huésped.
     * La vigencia/usos base se valida aparte con isRedeemable().
     */
    public function rejectionReason(?Guest $guest, ?\Carbon\CarbonInterface $start, ?int $nights, ?int $roomTypeId): ?string
    {
        if ($this->min_nights !== null && ($nights === null || $nights < $this->min_nights)) {
            return "Este cupón aplica en estancias de al menos {$this->min_nights} noches.";
        }

        if ($this->room_type_id !== null && $roomTypeId !== $this->room_type_id) {
            $name = $this->roomType?->name;

            return $name !== null
                ? "Este cupón aplica solo para habitaciones {$name}."
                : 'Este cupón aplica solo para otro tipo de habitación.';
        }

        if ($this->min_visits !== null) {
            if ($guest === null || ($guest->metrics()['visits'] ?? 0) < $this->min_visits) {
                return 'Este cupón es para clientes frecuentes; aún no alcanzas las visitas necesarias.';
            }
        }

        if ($this->birthday) {
            if ($guest?->birth_date === null || $start === null) {
                return 'Este cupón de cumpleaños requiere tu fecha de nacimiento registrada.';
            }

            // Cumpleaños más cercano al check-in (maneja el cruce de año).
            $birthday = $guest->birth_date->copy()->year($start->year);
            $distance = min(
                abs($start->copy()->startOfDay()->diffInDays($birthday->startOfDay(), false)),
                abs($start->copy()->startOfDay()->diffInDays($birthday->copy()->addYear()->startOfDay(), false)),
                abs($start->copy()->startOfDay()->diffInDays($birthday->copy()->subYear()->startOfDay(), false)),
            );

            if ($distance > self::BIRTHDAY_WINDOW_DAYS) {
                return 'Este cupón aplica solo en fechas cercanas a tu cumpleaños.';
            }
        }

        return null;
    }

    /**
     * Descuento en pesos para un subtotal dado: % del subtotal o el monto
     * fijo, nunca más que el propio subtotal (el total jamás baja de 0).
     */
    public function discountFor(float $subtotal): float
    {
        $subtotal = max(0, $subtotal);

        $discount = $this->kind === self::KIND_PERCENT
            ? $subtotal * ((float) $this->value / 100)
            : (float) $this->value;

        return round(min($discount, $subtotal), 2);
    }

    public function kindLabel(): string
    {
        return $this->kind === self::KIND_PERCENT
            ? rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').'%'
            : '$'.number_format((float) $this->value, 2);
    }
}
