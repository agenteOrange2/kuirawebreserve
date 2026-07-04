<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * CRM de huéspedes (spec-profundidad §1). El documento de identidad va
 * encriptado y sus fotos en disco privado; se sirven solo con permiso
 * guests.view-documents.
 */
class Guest extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const DOCUMENT_TYPES = ['ine', 'pasaporte', 'licencia', 'otro'];

    protected $fillable = [
        'first_name',
        'last_name',
        'phone',
        'email',
        'birth_date',
        'nationality',
        'address',
        'city',
        'state',
        'zip',
        'id_document_type',
        'id_document_number',
        'notes',
        'is_blacklisted',
        'blacklist_reason',
        'marketing_consent',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'id_document_number' => 'encrypted',
            'is_blacklisted' => 'boolean',
            'marketing_consent' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function registerMediaCollections(): void
    {
        // Fotos del documento (frente/reverso) — disco privado del tenant.
        $this->addMediaCollection('documents')->useDisk('local');
        // Fotos del vehículo con el que ingresó (placa, color, etc.).
        $this->addMediaCollection('vehicle')->useDisk('local');
    }

    /**
     * Datos del vehículo del huésped (guardados en meta).
     *
     * @return array<string, mixed>
     */
    public function vehicle(): array
    {
        return $this->meta['vehicle'] ?? [];
    }

    public function getFullNameAttribute(): ?string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $name !== '' ? $name : null;
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }

    /**
     * Métricas para el perfil: visitas, gasto total (hospedaje + consumos),
     * cancelaciones y no-shows.
     *
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        $stays = $this->stays()->get(['id', 'status', 'amount', 'check_in_at']);
        $completed = $stays->where('status', Stay::STATUS_COMPLETED);

        $consumos = Order::whereIn('stay_id', $stays->pluck('id'))
            ->where('status', Order::STATUS_COMPLETED)
            ->sum('total');

        $lodging = $completed->sum(fn (Stay $stay) => (float) $stay->amount)
            + $stays->where('status', Stay::STATUS_ACTIVE)->sum(fn (Stay $stay) => (float) $stay->amount);

        return [
            'visits' => $completed->count(),
            'active_stay' => $stays->firstWhere('status', Stay::STATUS_ACTIVE) !== null,
            'total_spent' => round($lodging + (float) $consumos, 2),
            'last_visit' => $stays->max('check_in_at')?->format('d/m/Y'),
            'cancellations' => $this->reservations()->where('status', 'cancelled')->count(),
            'no_shows' => $this->reservations()->where('status', 'no_show')->count(),
        ];
    }
}
