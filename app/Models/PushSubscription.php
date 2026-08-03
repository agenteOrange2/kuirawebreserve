<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un navegador suscrito a las notificaciones push del panel. Un usuario
 * puede tener varias (su celular y la computadora de recepción) y todas
 * reciben: no sabemos cuál está mirando.
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'public_key',
        'auth_token',
        'device',
        'last_used_at',
    ];

    protected $hidden = ['auth_token', 'public_key'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /** El endpoint es largo y variable: se indexa por su hash. */
    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
