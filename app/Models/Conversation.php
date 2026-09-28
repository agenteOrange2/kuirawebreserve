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
    /**
     * Desde cuándo esta conversación espera a una persona del hotel.
     *
     * No es "el último mensaje": si el huésped vuelve a escribir mientras
     * espera, el reloj no se reinicia — sería premiar al hotel por dejarlo
     * esperando. Se mide desde el primer mensaje que quedó sin contestar
     * después de la última respuesta del personal.
     *
     * Esperas reales de cabañas del 13 al 15 de septiembre: 16 min, 41 min,
     * 1 h 12, 2 h 47, 5 h, 9.6 h y 22 h.
     *
     * @param  array<int, int>  $ids
     * @return array<int, \Illuminate\Support\Carbon>
     */
    public static function waitingSinceFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $staff = Message::query()
            ->selectRaw('conversation_id, max(id) as staff_id')
            ->whereIn('conversation_id', $ids)
            ->where('sender_type', 'staff')
            ->groupBy('conversation_id')
            ->pluck('staff_id', 'conversation_id');

        $rows = collect();

        // Hilos donde ya contestó alguien: se mide desde el primer mensaje
        // posterior a esa respuesta.
        if ($staff->isNotEmpty()) {
            $rows = Message::query()
                ->selectRaw('conversation_id, min(created_at) as since')
                ->whereIn('conversation_id', $staff->keys()->all())
                ->where(function ($query) use ($staff) {
                    foreach ($staff as $conversationId => $staffId) {
                        $query->orWhere(fn ($q) => $q->where('conversation_id', $conversationId)->where('id', '>', $staffId));
                    }
                })
                ->groupBy('conversation_id')
                ->pluck('since', 'conversation_id');
        }

        // Hilos donde nadie del hotel ha escrito nunca: desde el principio.
        $sinStaff = array_values(array_diff($ids, $staff->keys()->all()));

        if ($sinStaff !== []) {
            $primeros = Message::query()
                ->selectRaw('conversation_id, min(created_at) as since')
                ->whereIn('conversation_id', $sinStaff)
                ->groupBy('conversation_id')
                ->pluck('since', 'conversation_id');

            foreach ($primeros as $conversationId => $since) {
                $rows[$conversationId] = $since;
            }
        }

        return collect($rows)
            ->map(fn ($since) => $since instanceof \Illuminate\Support\Carbon ? $since : \Illuminate\Support\Carbon::parse($since))
            ->all();
    }

    /** Lo mismo para una sola conversación. */
    public function waitingSince(): ?\Illuminate\Support\Carbon
    {
        return self::waitingSinceFor([$this->id])[$this->id] ?? null;
    }

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
     * ¿Sigue abierta la ventana de 24 h de WhatsApp? La Cloud API solo deja
     * mandar texto libre si el huésped escribió en las últimas 24 h; fuera
     * de ella acepta el envío y DESPUÉS lo rechaza por webhook (#131047).
     * Cabañas, RES-2026-1750 (2026-09-25): el recordatorio de saldo salió
     * diez días después del último mensaje de la huésped y nunca llegó.
     */
    public function whatsappWindowOpen(): bool
    {
        return $this->messages()
            ->where('direction', 'in')
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();
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
