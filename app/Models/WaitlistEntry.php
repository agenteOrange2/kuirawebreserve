<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada de la lista de espera (módulo lista-espera): alguien quiso
 * reservar sin disponibilidad y dejó su contacto. Cuando una cancelación
 * libera fechas solapadas, WaitlistNotifier le avisa y la sella como
 * notified — un aviso automático por entrada, nunca spam; el staff sí
 * puede reintentar a mano desde /lista-espera.
 *
 * "Avisado" solo se sella cuando el mensaje SALIÓ por algún canal
 * (notified_channel dice cuál). Si no salió, la entrada vuelve a espera
 * con notify_failed_at y el motivo, para que nadie dé por atendido a un
 * prospecto que nunca recibió nada.
 */
class WaitlistEntry extends Model
{
    public const STATUS_WAITING = 'waiting';

    public const STATUS_NOTIFIED = 'notified';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'room_type_id',
        'starts_at',
        'ends_at',
        'guest_name',
        'guest_phone',
        'guest_email',
        'status',
        'notified_at',
        'notified_channel',
        'notify_attempts',
        'notify_failed_at',
        'notify_error',
        'conversation_id',
        'reservation_id',
        'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'notified_at' => 'datetime',
            'notify_failed_at' => 'datetime',
            'converted_at' => 'datetime',
            'notify_attempts' => 'integer',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** El hilo que abrió el asistente al avisar (null si salió directo). */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** La reserva que salió de esta espera (null mientras no se ligue). */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_WAITING);
    }

    /**
     * Entradas cuyo rango de fechas se solapa con el rango dado (fechas
     * calendario: [starts_at, ends_at) contra [start, end)).
     */
    public function scopeOverlappingDates(Builder $query, \DateTimeInterface $start, \DateTimeInterface $end): Builder
    {
        return $query
            ->whereDate('starts_at', '<', $end->format('Y-m-d'))
            ->whereDate('ends_at', '>', $start->format('Y-m-d'));
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_WAITING => 'En espera',
            self::STATUS_NOTIFIED => 'Avisado',
            self::STATUS_CONVERTED => 'Convertida',
            self::STATUS_EXPIRED => 'Expirada',
            default => $this->status,
        };
    }

    /** Por dónde salió el último aviso, en palabras. */
    public function channelLabel(): ?string
    {
        $labels = [
            'agente' => 'el asistente',
            'whatsapp' => 'WhatsApp',
            'email' => 'correo',
        ];

        $parts = array_filter(explode('+', (string) $this->notified_channel));

        if ($parts === []) {
            return null;
        }

        $names = array_map(fn (string $part) => $labels[$part] ?? $part, $parts);

        return implode(' y ', $names);
    }

    /**
     * Teléfono normalizado para wa.me; lada del hotel (52 por defecto) en
     * números de 10 dígitos, igual que DirectGuestMessenger.
     */
    public function whatsappNumber(?string $countryCode = null): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->guest_phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            $code = preg_replace('/\D+/', '', $countryCode ?: '52');

            return $code.$digits;
        }

        return $digits;
    }
}
