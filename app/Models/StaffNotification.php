<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aviso para el staff en la campana del panel. A diferencia del tono de la
 * bandeja (que solo suena si esa página está abierta), esto se ve desde
 * cualquier pantalla y sobrevive a recargar.
 */
class StaffNotification extends Model
{
    public const TYPE_MESSAGE = 'message';

    public const TYPE_RESERVATION = 'reservation';

    public const TYPE_PAYMENT = 'payment';

    /** Evaluación baja de un huésped: atender antes de la reseña pública. */
    public const TYPE_SURVEY = 'survey';

    /** Solicitud del menú digital: un huésped pidió productos desde /menu. */
    public const TYPE_MENU = 'menu';

    protected $fillable = [
        'type',
        'title',
        'body',
        'url',
        'subject_type',
        'subject_id',
        'user_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Avisos que le tocan a este usuario: los suyos y los que son para todo
     * el staff (user_id nulo).
     */
    public function scopeFor(Builder $query, ?User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('user_id')
            ->when($user, fn (Builder $inner) => $inner->orWhere('user_id', $user->id)));
    }
}
