<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cupón de descuento (módulo cupones): código que el huésped aplica en el
 * wizard público. percent = % sobre el subtotal; amount = monto fijo. El
 * descuento aplicado se congela en la reserva (coupon_code +
 * discount_amount); used_count se incrementa al CONFIRMARSE la reserva
 * (TransitionReservation), nunca en el hold.
 */
class Coupon extends Model
{
    public const KIND_PERCENT = 'percent';

    public const KIND_AMOUNT = 'amount';

    protected $fillable = [
        'code',
        'kind',
        'value',
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
            'starts_at' => 'date',
            'ends_at' => 'date',
            'active' => 'boolean',
        ];
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
