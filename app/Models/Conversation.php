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
        'last_message_preview',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'bot_enabled' => 'boolean',
            'followups' => 'array',
            'last_message_at' => 'datetime',
            'archived_at' => 'datetime',
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

    /**
     * spec-reservas-avanzado §1.3: el huésped que reservó por el wizard
     * público no tiene conversación previa; cuando escribe por WhatsApp
     * (p. ej. con el botón "Enviar comprobante") se liga aquí su reserva
     * pendiente más reciente comparando los últimos 10 dígitos del
     * teléfono — el huésped teclea "614 123 4567" en el wizard pero llega
     * como 5216141234567 desde el webhook. Así el staff ve el código y el
     * estado de pago directo en la bandeja sin preguntar.
     */
    /**
     * ¿El identificador del contacto de este canal ES su teléfono? Solo en
     * WhatsApp. En Messenger/IG/Telegram/TikTok/webchat, contact_phone
     * guarda el id externo del hilo (PSID/IGSID/chat id): sobrescribirlo
     * con el teléfono real PARTE la conversación en dos — el webhook ya no
     * la encuentra y abre otra vacía, y el bot pierde todo el hilo (caso
     * real cabañas 2026-08-28, RES-2026-0048).
     */
    public function phoneIsIdentity(): bool
    {
        return in_array($this->channel?->type, ['whatsapp', 'whatsapp_evo'], true);
    }

    public function linkReservationByPhone(): void
    {
        if ($this->reservation_id !== null) {
            return;
        }

        $digits = preg_replace('/\D+/', '', (string) $this->contact_phone);

        if (strlen($digits) < 10) {
            return;
        }

        $tail = substr($digits, -10);

        // Solo pendientes recientes (la ventana en la que se espera un
        // comprobante); acotado para no barrer la tabla completa.
        $reservation = Reservation::query()
            ->where('status', \App\Enums\ReservationStatus::Pending)
            ->where('created_at', '>=', now()->subDays(7))
            ->with('guest:id,phone')
            ->latest('id')
            ->limit(50)
            ->get()
            ->first(function (Reservation $candidate) use ($tail): bool {
                $phone = preg_replace('/\D+/', '', (string) $candidate->guest?->phone);

                return strlen($phone) >= 10 && substr($phone, -10) === $tail;
            });

        if (! $reservation) {
            return;
        }

        $this->update(array_filter([
            'reservation_id' => $reservation->id,
            'guest_id' => $reservation->guest_id,
        ]));
        $this->markLead(self::LEAD_HOLD);
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
        // withTrashed: un huésped archivado sigue visible en su historial.
        return $this->belongsTo(Guest::class)->withTrashed();
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

    /**
     * Comentarios de redes sociales que abrieron (o retomaron) esta
     * conversación: la atribución del embudo post → comentario → DM → reserva.
     */
    public function socialComments(): HasMany
    {
        return $this->hasMany(SocialComment::class);
    }
}
