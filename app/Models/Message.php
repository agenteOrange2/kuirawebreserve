<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        // Si el huésped vuelve a escribir a una conversación archivada,
        // esta regresa sola a la bandeja activa y se reabre (todos los
        // canales — webchat, Meta, Evolution — crean el mensaje por aquí).
        static::created(function (self $message): void {
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
