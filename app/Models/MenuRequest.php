<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud del menú digital: pedido que el huésped arma en /menu (módulo
 * menu-digital). No es una venta — el staff la atiende y cobra en el POS
 * como siempre; los items quedan congelados con el precio del momento.
 */
class MenuRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    /** La cocina tomó el pedido y lo está preparando. */
    public const STATUS_PREPARING = 'preparing';

    public const STATUS_ATTENDED = 'attended';

    public const STATUS_CANCELLED = 'cancelled';

    /** Cargo a la habitación: se paga al final, en el check-out (hotel). */
    public const PAYMENT_ROOM_CHARGE = 'room_charge';

    /** Se paga al recibir el pedido (único camino en modo motel). */
    public const PAYMENT_ON_DELIVERY = 'on_delivery';

    /** Métodos al recibir; el cobro real se registra en el POS. */
    public const DELIVERY_METHODS = [
        'cash' => 'Efectivo al recibir',
        'card' => 'Tarjeta al recibir',
    ];

    protected $fillable = [
        'property_id',
        'room_id',
        'room_label',
        'guest_name',
        'notes',
        'items',
        'total',
        'payment_mode',
        'payment_method',
        'order_id',
        'status',
        'preparing_by',
        'preparing_at',
        'attended_by',
        'attended_at',
    ];

    /** Etiqueta humana de la preferencia de pago (panel y campana). */
    public function paymentLabel(): string
    {
        if ($this->payment_mode === self::PAYMENT_ROOM_CHARGE) {
            return 'Cargo a la habitación';
        }

        return self::DELIVERY_METHODS[$this->payment_method] ?? 'Paga al recibir';
    }

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total' => 'decimal:2',
            'preparing_at' => 'datetime',
            'attended_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** La venta POS generada al despachar (folio o venta del turno). */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function preparingBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preparing_by');
    }

    public function attendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attended_by');
    }
}
