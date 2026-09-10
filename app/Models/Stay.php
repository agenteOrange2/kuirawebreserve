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
        'auto_closed_at',
        'settlement_closed_at',
        'settlement_note',
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
            // La cerró el reloj y no una persona: la bandeja de cuentas por
            // cerrar lo dice, porque cambia a quién hay que preguntarle.
            'auto_closed_at' => 'datetime',
            // Cuenta resuelta sin cobrarla (cortesía, incobrable, error de
            // captura), con el porqué en settlement_note.
            'settlement_closed_at' => 'datetime',
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

        // Daños y cargos capturados después del check-in. Ya están dentro
        // del hospedaje (suben el monto de la estancia o el total de su
        // reserva); se listan aparte porque el mostrador necesita ver QUÉ
        // sumó y poder quitarlo antes de cobrar.
        $damages = collect($this->extra_charges ?? [])
            ->filter(fn ($line) => in_array($line['kind'] ?? '', ['damage', 'late'], true))
            ->map(fn ($line, $i) => [
                'id' => (string) ($line['id'] ?? $i),
                'concept' => (string) ($line['concept'] ?? ''),
                'amount' => round((float) ($line['amount'] ?? 0), 2),
            ])
            ->values();

        return [
            'lodging_total' => $lodgingTotal,
            'lodging_paid' => $lodgingPaid,
            'lodging_pending' => $lodgingPending,
            'orders' => $unsettledOrders,
            'consumption_pending' => $consumptionPending,
            'damages' => $damages,
            'damages_total' => round((float) $damages->sum('amount'), 2),
            'grand_pending' => round($lodgingPending + $consumptionPending, 2),
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Cuentas por cerrar: estancias ya cerradas a las que les quedó dinero
     * sin registrar y que nadie ha resuelto.
     *
     * Todo se calcula en SQL a propósito. folio() responde por estancia y
     * pinta bien un modal, pero una lista paginada haría dos consultas por
     * renglón — el panel no puede tener listas que consulten por fila.
     *
     * El saldo son dos cosas: el hospedaje que falta (de la reserva si la
     * hay, o del monto de la estancia en un walk-in) y los consumos cargados
     * a la habitación que nadie liquidó. La fianza no cuenta: es un pasivo
     * que se devuelve, y por eso vive con stay_id y sin reservation_id.
     */
    public function scopePendingSettlement(Builder $query): Builder
    {
        $payments = fn (string $where) => "(select coalesce(sum(p.amount), 0) from payments p where {$where})";

        $conSaldo = static::query()
            ->where('stays.status', self::STATUS_COMPLETED)
            ->whereNull('stays.settlement_closed_at')
            ->leftJoin('reservations', 'reservations.id', '=', 'stays.reservation_id')
            ->select('stays.*')
            ->selectRaw('coalesce(reservations.total_amount, stays.amount) as lodging_total_calc')
            ->selectRaw('case when stays.reservation_id is null then '
                .$payments("p.stay_id = stays.id and p.kind = 'lodging'")
                .' else '
                .$payments('p.reservation_id = stays.reservation_id')
                .' end as lodging_paid_calc')
            ->selectRaw('(select coalesce(sum(o.total), 0) from orders o'
                ." where o.stay_id = stays.id and o.status = 'completed'"
                ." and o.payment_method = 'room' and o.settled_at is null) as consumption_pending_calc")
            ->toBase();

        // Subconsulta y no HAVING: sqlite (los tests) rechaza un HAVING sin
        // GROUP BY, y filtrar por columnas calculadas es justo lo que hay que
        // hacer aquí. El alias se llama igual que la tabla para que las
        // relaciones y los `orderBy('stays.x')` sigan resolviendo.
        return $query
            ->fromSub($conSaldo, 'stays')
            ->whereRaw('round(coalesce(lodging_total_calc, 0) - coalesce(lodging_paid_calc, 0), 2)'
                .' + coalesce(consumption_pending_calc, 0) > 0.009');
    }

    /**
     * Saldo de la fila que trajo scopePendingSettlement, sin volver a
     * consultar. Fuera de ese scope cae a folio(), que sí consulta.
     */
    public function pendingSettlementAmount(): float
    {
        if (! array_key_exists('lodging_total_calc', $this->attributes)) {
            return $this->folio()['grand_pending'];
        }

        $lodging = max(0, round(
            (float) $this->attributes['lodging_total_calc'] - (float) $this->attributes['lodging_paid_calc'],
            2,
        ));

        return round($lodging + (float) $this->attributes['consumption_pending_calc'], 2);
    }

    /**
     * Solape con estancias activas (para disponibilidad).
     */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $start, \DateTimeInterface $end): Builder
    {
        return $query->where('check_in_at', '<', $end)->where('planned_end_at', '>', $start);
    }
}
