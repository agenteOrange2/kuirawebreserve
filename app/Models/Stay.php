<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Ocupación real de una habitación (check-in hecho). Puede venir de una
 * reserva o ser walk-in directo.
 */
class Stay extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'room_id',
        'reservation_id',
        'rate_plan_id',
        'guest_id',
        'guest_name',
        'num_people',
        // Placa TAL COMO se tecleó esa noche: sello histórico, igual que
        // guest_name junto a guest_id. La ficha editable vive en `vehicles`
        // y el vínculo es vehicle_id, que solo escribe VehicleRegistry.
        'vehicle_plate',
        'vehicle_desc',
        'vehicle_id',
        'id_document_type',
        'id_document_number',
        'arrival_completed_at',
        'arrival_mode',
        'check_in_at',
        'planned_end_at',
        'check_out_at',
        'thanks_sent_at',
        'status',
        'amount',
        'extra_charges',
        'channel',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'check_in_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'check_out_at' => 'datetime',
            'thanks_sent_at' => 'datetime',
            // Caseta en dos momentos: null = falta terminar de capturar la
            // llegada (placa o identificación) y marcar el cobro.
            'arrival_completed_at' => 'datetime',
            'amount' => 'decimal:2',
            'extra_charges' => 'array',
            // Identificación del huésped a pie (registro exprés de caseta):
            // cifrada en reposo, igual que Guest.id_document_number.
            'id_document_number' => 'encrypted',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('stay')
            ->logOnly(['status', 'room_id', 'check_in_at', 'check_out_at', 'amount'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Foto del documento del huésped a pie (registro exprés de caseta):
     * privada como los documentos del CRM — solo se sirve con el permiso
     * guests.view-documents vía la ruta tenant.stays.document.show.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('id_document')->useDisk('local');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function guest(): BelongsTo
    {
        // withTrashed: un huésped archivado sigue visible en su historial.
        return $this->belongsTo(Guest::class)->withTrashed();
    }

    public function vehicle(): BelongsTo
    {
        // withTrashed: una ficha archivada sigue visible en el historial.
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Cuenta final de la estancia (folio): hospedaje pendiente + consumos
     * POS cargados a la habitación aún no liquidados.
     *
     * @return array<string, mixed>
     */
    /**
     * ¿Falta terminar de capturar esta llegada? Es el estado intermedio de la
     * caseta de motel: el acceso ya se abrió, pero los datos del carro y el
     * cobro llegan cuando el encargado regresa con el papel.
     */
    public function arrivalPending(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->arrival_completed_at === null;
    }

    public function folio(): array
    {
        // Hospedaje: con reserva manda su control de pagos; walk-in sin
        // reserva usa el monto de la estancia menos lo ya liquidado en folio.
        if ($this->reservation) {
            $lodgingTotal = (float) $this->reservation->total_amount;
            $lodgingPaid = $this->reservation->paidTotal();
        } else {
            $lodgingTotal = (float) $this->amount;
            $lodgingPaid = round((float) $this->payments()->where('kind', Payment::KIND_LODGING)->sum('amount'), 2);
        }
        $lodgingPending = max(0, round($lodgingTotal - $lodgingPaid, 2));

        $unsettledOrders = $this->orders()
            ->with('lines.product:id,name')
            ->where('status', Order::STATUS_COMPLETED)
            ->where('payment_method', 'room')
            ->whereNull('settled_at')
            ->get();

        $consumptionPending = round((float) $unsettledOrders->sum('total'), 2);

        return [
            'lodging_total' => $lodgingTotal,
            'lodging_paid' => $lodgingPaid,
            'lodging_pending' => $lodgingPending,
            'orders' => $unsettledOrders,
            'consumption_pending' => $consumptionPending,
            'grand_pending' => round($lodgingPending + $consumptionPending, 2),
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Solape con estancias activas (para disponibilidad).
     */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $start, \DateTimeInterface $end): Builder
    {
        return $query->where('check_in_at', '<', $end)->where('planned_end_at', '>', $start);
    }
}
