<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comentario en una publicación de la página. El asistente lo clasifica y,
 * según lo que quiera el hotel, contesta breve en público y abre el mensaje
 * privado — de ahí en adelante es una conversación normal de la bandeja.
 *
 * Ocultar NUNCA borra: `hidden_at`/`hidden_by`/`hidden_reason` dejan el
 * rastro y la acción es reversible desde el panel.
 */
class SocialComment extends Model
{
    /** Pide precio, disponibilidad o ubicación: es un cliente potencial. */
    public const CLASS_PURCHASE = 'compra';

    public const CLASS_QUESTION = 'pregunta';

    public const CLASS_COMPLAINT = 'queja';

    public const CLASS_PRAISE = 'elogio';

    public const CLASS_SPAM = 'spam';

    public const CLASSIFICATIONS = [
        self::CLASS_PURCHASE,
        self::CLASS_QUESTION,
        self::CLASS_COMPLAINT,
        self::CLASS_PRAISE,
        self::CLASS_SPAM,
    ];

    public const CLASSIFICATION_LABELS = [
        self::CLASS_PURCHASE => 'Intención de compra',
        self::CLASS_QUESTION => 'Pregunta',
        self::CLASS_COMPLAINT => 'Queja',
        self::CLASS_PRAISE => 'Elogio',
        self::CLASS_SPAM => 'Spam',
    ];

    /** Recién llegado: nadie lo ha atendido todavía. */
    public const STATUS_NEW = 'nuevo';

    public const STATUS_ANSWERED = 'respondido';

    /** Necesita a una persona (queja, o el asistente no pudo decidir). */
    public const STATUS_PENDING_STAFF = 'pendiente_staff';

    public const STATUS_HIDDEN = 'oculto';

    /** Eco de la propia página o algo que no amerita respuesta. */
    public const STATUS_IGNORED = 'ignorado';

    public const STATUS_LABELS = [
        self::STATUS_NEW => 'Nuevo',
        self::STATUS_ANSWERED => 'Respondido',
        self::STATUS_PENDING_STAFF => 'Pendiente de staff',
        self::STATUS_HIDDEN => 'Oculto',
        self::STATUS_IGNORED => 'Ignorado',
    ];

    protected $fillable = [
        'social_post_id',
        'external_id',
        'parent_external_id',
        'author_external_id',
        'author_name',
        'body',
        'classification',
        'classification_meta',
        'status',
        'public_reply_text',
        'public_reply_external_id',
        'public_replied_at',
        'private_reply_sent_at',
        'private_reply_error',
        'conversation_id',
        'commented_at',
        'hidden_at',
        'hidden_by',
        'hidden_reason',
        'handled_by',
        'handled_at',
        'deleted_from_network_at',
    ];

    protected function casts(): array
    {
        return [
            'classification_meta' => 'array',
            'public_replied_at' => 'datetime',
            'private_reply_sent_at' => 'datetime',
            'commented_at' => 'datetime',
            'hidden_at' => 'datetime',
            'handled_at' => 'datetime',
            'deleted_from_network_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function classificationLabel(): ?string
    {
        return self::CLASSIFICATION_LABELS[$this->classification] ?? null;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    /**
     * Meta solo permite UN mensaje privado por comentario y dentro de los 7
     * días siguientes; pasado eso hay que esperar a que la persona escriba.
     */
    public function canPrivateReply(): bool
    {
        return $this->private_reply_sent_at === null
            && $this->commented_at !== null
            && $this->commented_at->gt(now()->subDays(7));
    }

    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_NEW, self::STATUS_PENDING_STAFF]);
    }
}
