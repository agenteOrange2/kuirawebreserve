<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Reservation extends Model
{
    /** @use HasFactory<\Database\Factories\ReservationFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'property_id',
        'room_type_id',
        'room_id',
        'rate_plan_id',
        'reservation_group_id',
        'guest_id',
        'code',
        'guest_name',
        'num_people',
        'adults',
        'children',
        'vehicle_plate',
        'vehicle_desc',
        'eta',
        'starts_at',
        'ends_at',
        'status',
        'hold_expires_at',
        'source_channel',
        'total_amount',
        'extra_charges',
        'products',
        'extras',
        'experiences',
        'deposit_amount',
        'coupon_code',
        'discount_amount',
        'payment_status',
        'payment_due_at',
        'notes',
        'guest_notes',
        'cancellation_reason',
        'settlement_closed_at',
        'settlement_note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'adults' => 'integer',
            'children' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'hold_expires_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'extra_charges' => 'array',
            'products' => 'array',
            'extras' => 'array',
            'experiences' => 'array',
            'deposit_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'payment_status' => \App\Enums\PaymentStatus::class,
            'payment_due_at' => 'datetime',
            // Alguien resolvió la cuenta sin cobrarla, con el porqué en
            // settlement_note.
            'settlement_closed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('reservation')
            // Bitácora completa de ediciones: no solo estado/fechas/cuarto —
            // cambiar huésped, personas, tarifa, notas o cupón también es
            // "quién modificó una reserva". Los JSON (extras, products) se
            // quedan fuera: generan diffs ilegibles y sus altas ya dejan
            // rastro propio.
            ->logOnly([
                'status', 'room_id', 'starts_at', 'ends_at', 'total_amount', 'cancellation_reason',
                'guest_id', 'guest_name', 'num_people', 'adults', 'children', 'notes',
                'rate_plan_id', 'deposit_amount', 'coupon_code', 'discount_amount', 'eta',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ReservationGroup::class, 'reservation_group_id');
    }

    /**
     * Cuántas habitaciones trae la misma partida: las vivas del grupo
     * (folio GRP-) o 1 si la reserva va sola. Lo usa la fianza escalonada
     * — hay hoteles que la bajan por habitación cuando el mismo grupo
     * aparta varias. Las canceladas y los no-show no cuentan: el grupo que
     * llega es el que paga.
     *
     * OJO: solo mira el grupo, no "reservas del mismo huésped en las mismas
     * fechas". Tres reservas sueltas hechas por separado son tres partidas
     * hasta que recepción las agrupe — adivinarlo cobraría de menos sin que
     * nadie lo pidiera. Para ese caso el modal de llegada deja ajustar el
     * monto a mano, con motivo.
     */
    public function partyRoomCount(): int
    {
        if ($this->reservation_group_id === null) {
            return 1;
        }

        return max(1, static::query()
            ->where('reservation_group_id', $this->reservation_group_id)
            ->whereNotIn('status', [ReservationStatus::Cancelled, ReservationStatus::NoShow])
            ->count());
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

    public function stay(): HasOne
    {
        return $this->hasOne(Stay::class);
    }

    /** Tours comprados como extra de esta reserva (líneas en `experiences`). */
    public function experienceBookings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExperienceBooking::class);
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function refunds(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function refundedTotal(): float
    {
        return round((float) $this->refunds()->where('status', Refund::STATUS_COMPLETED)->sum('amount'), 2);
    }

    /**
     * Reembolso sugerido por la política de cancelación efectiva — la de la
     * tarifa si define una, o la default del hotel (spec-pagos F4): lo
     * pagado no reembolsado, menos la penalidad si se cancela fuera de la
     * ventana. null = sin política (decisión humana, como siempre). Es
     * SUGERENCIA: el staff decide.
     */
    public function suggestedRefund(?\DateTimeInterface $at = null): ?float
    {
        $policy = app(\App\Services\ReservationPolicy::class)->cancellationPolicyFor($this->ratePlan);

        if ($policy === null) {
            return null;
        }

        $refundable = max(0, round($this->paidTotal() - $this->refundedTotal(), 2));

        if ($refundable <= 0) {
            return null;
        }

        $deadline = $policy['unit']->subtractFrom($this->starts_at, $policy['value']);
        $moment = $at ? \Carbon\Carbon::instance(\Carbon\Carbon::parse($at)) : now();

        if ($moment->lte($deadline)) {
            return $refundable; // dentro de la ventana: se devuelve todo
        }

        return max(0, round($refundable * (1 - $policy['penalty'] / 100), 2));
    }

    public function paidTotal(): float
    {
        // Con la suma precargada (withSum('payments', 'amount')) no se hace
        // un SELECT por fila: las listas del panel pintan decenas de
        // reservas y cada una preguntaba por su dinero dos veces.
        if (array_key_exists('payments_sum_amount', $this->attributes)) {
            return round((float) $this->attributes['payments_sum_amount'], 2);
        }

        return round((float) $this->payments()->sum('amount'), 2);
    }

    public function pendingBalance(): float
    {
        return max(0, round((float) $this->total_amount - $this->paidTotal(), 2));
    }

    /**
     * Estado de pago derivado de la suma de abonos (spec §2.6.3): no se
     * marca a mano. Llamar tras registrar pagos o recalcular el total.
     */
    public function syncPaymentStatus(): void
    {
        $paid = $this->paidTotal();

        $this->payment_status = match (true) {
            $paid >= (float) $this->total_amount && (float) $this->total_amount > 0 => \App\Enums\PaymentStatus::Paid,
            (float) $this->deposit_amount > 0 && $paid >= (float) $this->deposit_amount => \App\Enums\PaymentStatus::DepositPaid,
            $paid > 0 => \App\Enums\PaymentStatus::Partial,
            default => \App\Enums\PaymentStatus::Unpaid,
        };

        $this->save();
    }

    public function isPaymentOverdue(): bool
    {
        return $this->payment_due_at !== null
            && $this->payment_due_at->isPast()
            && $this->payment_status !== \App\Enums\PaymentStatus::Paid
            && in_array($this->status, [ReservationStatus::Pending, ReservationStatus::Confirmed], true);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function formatCode(int $id, ?\DateTimeInterface $date = null): string
    {
        $year = (int) ($date?->format('Y') ?? now()->format('Y'));

        return sprintf('RES-%d-%04d', $year, $id);
    }

    public function displayCode(): string
    {
        return $this->code ?: self::formatCode($this->id, $this->created_at);
    }

    /**
     * Por dónde entró, legible para el hotel. Las del asistente dicen además
     * el canal real de su conversación (WhatsApp, Messenger, Instagram...).
     */
    public function channelLabel(): string
    {
        $label = match ($this->source_channel) {
            'front_desk', 'counter' => 'Mostrador',
            'walk_in' => 'Llegó sin reserva',
            'phone' => 'Teléfono',
            'web' => 'Sitio web',
            'whatsapp' => 'WhatsApp',
            'agent' => 'Asistente IA',
            null, '' => 'Sin canal',
            default => (string) $this->source_channel,
        };

        if ($this->source_channel === 'agent') {
            $type = Conversation::query()
                ->where('reservation_id', $this->id)
                ->latest('id')
                ->first()
                ?->channel
                ?->type;

            if ($type) {
                $label .= ' · '.(Channel::TYPE_LABELS[$type] ?? $type);
            }
        }

        return $label;
    }

    /** Motivo con el que el barrido de apartados (ExpireReservationHolds) cancela. */
    public const EXPIRED_HOLD_REASON = 'Apartado vencido sin pago';

    /**
     * Apartado que se canceló SOLO porque se le acabó el plazo. El barrido
     * deja puesto hold_expires_at y una cancelación a mano lo borra
     * (TransitionReservation::cancel), así que esto distingue también las
     * reservas que vencieron antes de que existiera el motivo.
     */
    /**
     * ¿Esta reserva está VIVA ahora mismo? Confirmada, en casa, o un
     * apartado cuyo plazo no ha llegado. Es la pregunta que hay que hacerse
     * antes de decirle a un huésped que ya no tiene nada.
     */
    public function isLiveHold(): bool
    {
        return in_array($this->status, [ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)
            || ($this->status === ReservationStatus::Pending
                && $this->hold_expires_at !== null
                && $this->hold_expires_at->isFuture());
    }

    public function isExpiredHold(): bool
    {
        return $this->status === ReservationStatus::Cancelled
            && $this->hold_expires_at !== null
            && $this->hold_expires_at->isPast();
    }

    /**
     * Cuentas por cerrar SIN estancia: la reserva terminó (casi siempre por
     * el cierre de día, que completa sin preguntarle a nadie) y le quedó
     * dinero sin registrar.
     *
     * Las reservas que sí tienen estancia no entran: esas las cubre
     * Stay::pendingSettlement(), donde el saldo además incluye los consumos
     * del folio. Contarlas aquí las mostraría dos veces con cifras
     * distintas.
     *
     * La fianza no es pago del hospedaje: es un pasivo que se devuelve.
     */
    public function scopePendingSettlement(Builder $query): Builder
    {
        return $query
            ->where('reservations.status', ReservationStatus::Completed)
            ->whereNull('reservations.settlement_closed_at')
            ->whereNotExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('stays')
                ->whereColumn('stays.reservation_id', 'reservations.id'))
            ->whereRaw('reservations.total_amount > (select coalesce(sum(p.amount), 0) from payments p'
                ." where p.reservation_id = reservations.id and (p.kind is null or p.kind <> 'guarantee'))");
    }

    /**
     * Reservas que bloquean disponibilidad: confirmadas / en casa, y
     * pendientes cuyo hold sigue vigente (spec §7).
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
                ->orWhere(function (Builder $q) {
                    $q->where('status', ReservationStatus::Pending)
                        ->where('hold_expires_at', '>', now());
                });
        });
    }

    /**
     * Solape de rangos: (start_a < end_b) AND (end_a > start_b).
     */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $start, \DateTimeInterface $end): Builder
    {
        return $query->where('starts_at', '<', $end)->where('ends_at', '>', $start);
    }

    /**
     * Reservas que JUSTIFICAN el semáforo "reservada" en este instante.
     *
     * Único punto de verdad: lo usan rooms:reserve-arrivals para ENCENDERLO,
     * rooms:advance-housekeeping para APAGARLO y el panel para decidir si el
     * cuarto se puede soltar a mano. Cuando esos tres divergían, el semáforo
     * parpadeaba cada cinco minutos o se congelaba para siempre — que es lo
     * que pasó en septiembre de 2026: quien lo apagaba preguntaba "¿tiene
     * alguna reserva futura?" (cualquiera, aunque fuera de otro mes) en vez
     * de "¿hay una reserva que la aparte HOY?", así que un hotel con agenda
     * cargada dejaba sus cuartos apartados indefinidamente.
     *
     * Solo confirmadas. Una pendiente con hold aparta FECHAS en el motor de
     * disponibilidad, no el cuarto físico: el mostrador no tiene por qué ver
     * "apartada" por un carrito que expira en veinte minutos. Y una
     * pendiente SIN hold (hold_expires_at nulo) no aparta ni fechas —
     * scopeBlocking() la ignora—, así que menos aún el semáforo.
     *
     * La ventana abre a las 00:00 del día de entrada y no a la hora exacta:
     * es la misma asimetría con la que se enciende (starts_at <= endOfDay),
     * y por eso encender y apagar no pelean entre corridas.
     */
    public function scopeHoldsRoomAt(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $at = $at ? \Illuminate\Support\Carbon::instance($at) : now();

        // Columnas calificadas: dentro del ofMany de Room::holdingReservation
        // la consulta se une consigo misma y un `room_id` pelado es ambiguo.
        return $query
            ->where($query->qualifyColumn('status'), ReservationStatus::Confirmed)
            ->whereNotNull($query->qualifyColumn('room_id'))
            ->where($query->qualifyColumn('starts_at'), '<=', $at->copy()->endOfDay())
            ->where($query->qualifyColumn('ends_at'), '>', $at);
    }

    /**
     * Llegadas dadas por perdidas: pasados N minutos de la hora de entrada
     * sin que nadie registrara la llegada (ajuste arrival_no_show_* de
     * /ajustes/limpieza). Su salida todavía no llega — las vencidas son del
     * cierre de día, no de aquí.
     */
    public function scopeArrivalWindowClosed(Builder $query, int $minutes, ?\DateTimeInterface $at = null): Builder
    {
        $at = $at ? \Illuminate\Support\Carbon::instance($at) : now();

        return $query
            ->where('status', ReservationStatus::Confirmed)
            ->whereNotNull('room_id')
            ->where('starts_at', '<=', $at->copy()->subMinutes(max(1, $minutes)))
            ->where('ends_at', '>', $at);
    }
}
