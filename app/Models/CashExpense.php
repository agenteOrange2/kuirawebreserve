<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Dinero que SALE de la caja durante un turno: insumos, gasolina, un
 * retiro a bóveda. El arqueo solo contaba entradas (fondo, ventas,
 * fianzas), así que cada salida legítima se leía como faltante del
 * encargado y el corte nunca cuadraba.
 *
 * Pesa en el corte del ÁMBITO donde salió: recepción y punto de venta son
 * dos cajones distintos.
 */
class CashExpense extends Model implements HasMedia
{
    use InteractsWithMedia;

    /** Categorías: lo que de verdad se paga desde el cajón de un hotel. */
    public const CATEGORIES = [
        'insumos' => 'Insumos y despensa',
        'mantenimiento' => 'Mantenimiento y reparaciones',
        'transporte' => 'Gasolina y transporte',
        'servicios' => 'Servicios y pagos',
        'personal' => 'Personal (adelantos, propinas)',
        'retiro' => 'Retiro a bóveda o depósito',
        'otro' => 'Otro',
    ];

    protected $fillable = [
        'property_id',
        'user_id',
        'shift_id',
        'scope',
        'category',
        'concept',
        'amount',
        'occurred_at',
        'cash_cut_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        // Foto del ticket: opcional, pero es lo que sostiene el gasto
        // cuando el dueño revisa el corte una semana después.
        $this->addMediaCollection('receipt')->useDisk('public')->singleFile();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function cashCut(): BelongsTo
    {
        return $this->belongsTo(CashCut::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? self::CATEGORIES['otro'];
    }

    /** Ya quedó dentro de un corte cerrado: no se toca. */
    public function isLocked(): bool
    {
        return $this->cash_cut_id !== null;
    }

    public function receiptUrl(): ?string
    {
        return $this->getFirstMediaUrl('receipt') ?: null;
    }
}
