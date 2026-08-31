<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasMedia
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, InteractsWithMedia, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** Foto de perfil: una sola, misma mecánica que el logo del hotel. */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->useDisk('public')->singleFile();
    }

    /**
     * URL de la foto, o null si no subió ninguna (el panel cae a iniciales).
     *
     * NO va en $appends a propósito: `getFirstMedia` consulta si el media no
     * está cargado, y en un listado de usuarios eso es una consulta por
     * renglón. Quien la necesite la pide explícito, con `media` eager-loaded.
     */
    public function avatarUrl(): ?string
    {
        $media = $this->getFirstMedia('avatar');

        // ?v= : al resubir cambia el id y revienta el caché del navegador.
        return $media ? '/avatar/'.$this->id.'?v='.$media->id : null;
    }
}
