<?php

namespace App\Models;

use App\Events\ConversationActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Message extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const UPDATED_AT = null;

    protected $fillable = [
        'conversation_id',
        'direction',
        'sender_type',
        'sender_id',
        'body',
        'meta',
        'read_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $message): void {
            // La bandeja lista la vista previa de 100 conversaciones a la
            // vez: se guarda denormalizada en la conversación en lugar de
            // consultar el último mensaje fila por fila. Este es el único
            // punto por el que nacen los mensajes de todos los canales,
            // así que aquí no se puede quedar desincronizada.
            Conversation::query()
                ->whereKey($message->conversation_id)
                ->update(['last_message_preview' => Str::limit((string) $message->body, 250)]);

            // La bandeja se entera en el momento (Reverb) en lugar de estar
            // preguntando cada pocos segundos.
            ConversationActivity::dispatch($message);

            // Si el huésped vuelve a escribir a una conversación archivada,
            // esta regresa sola a la bandeja activa y se reabre (todos los
            // canales — webchat, Meta, Evolution — crean el mensaje por aquí).
            if ($message->direction !== 'in') {
                return;
            }

            $conversation = $message->conversation;

            if ($conversation !== null && $conversation->archived_at !== null) {
                $conversation->update([
                    'archived_at' => null,
                    'status' => Conversation::STATUS_OPEN,
                ]);
            }
        });
    }

    /**
     * Adjuntos entrantes de WhatsApp (imagen/PDF del huésped): privados,
     * se sirven por ruta autenticada del inbox — nunca públicos.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')->useDisk('local');
    }

    /** @return array<int, array{id: int, url: string, name: string, is_image: bool}> */
    public function attachmentsPayload(): array
    {
        return $this->getMedia('attachments')->map(fn (Media $media) => [
            'id' => $media->id,
            'url' => route('tenant.inbox.attachment', [$this->conversation_id, $media->id]),
            'name' => $media->file_name,
            'is_image' => str_starts_with((string) $media->mime_type, 'image/'),
        ])->values()->all();
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
