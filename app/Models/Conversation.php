<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Conversation extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_PENDING = 'pending'; // espera a un humano

    public const STATUS_RESOLVED = 'resolved';

    // Embudo de venta de la conversación (lead).
    public const LEAD_NEW = 'new';

    public const LEAD_QUOTING = 'quoting'; // preguntó tarifas/disponibilidad

    public const LEAD_HOLD = 'hold'; // tiene un apartado pendiente

    public const LEAD_WON = 'won'; // su reserva se confirmó

    public const LEAD_LOST = 'lost'; // el apartado venció / se enfrió

    protected $fillable = [
        'uuid',
        'channel_id',
        'guest_id',
        'reservation_id',
        'contact_name',
        'contact_phone',
        'status',
        'lead_status',
        'summary',
        'summary_message_id',
        'followups',
        'bot_enabled',
        'assigned_to',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'bot_enabled' => 'boolean',
            'followups' => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * Avanza el embudo respetando el sentido de la venta: ganado es final,
     * un apartado no baja a cotizando, y un lead perdido puede reengancharse.
     */
    public function markLead(string $status): void
    {
        $allowed = match ($this->lead_status) {
            self::LEAD_QUOTING => [self::LEAD_HOLD, self::LEAD_WON, self::LEAD_LOST],
            self::LEAD_HOLD => [self::LEAD_WON, self::LEAD_LOST],
            self::LEAD_LOST => [self::LEAD_QUOTING, self::LEAD_HOLD, self::LEAD_WON],
            self::LEAD_WON => [],
            default => [self::LEAD_QUOTING, self::LEAD_HOLD, self::LEAD_WON, self::LEAD_LOST],
        };

        if (in_array($status, $allowed, true)) {
            $this->update(['lead_status' => $status]);
        }
    }

    /** ¿Ya se envió este follow-up? (cada uno se manda una sola vez). */
    public function followupSent(string $key): bool
    {
        return array_key_exists($key, $this->followups ?? []);
    }

    public function markFollowup(string $key): void
    {
        $this->update(['followups' => ($this->followups ?? []) + [$key => now()->toDateTimeString()]]);
    }

    protected static function booted(): void
    {
        static::creating(function (self $conversation) {
            $conversation->uuid ??= (string) Str::uuid();
        });
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
