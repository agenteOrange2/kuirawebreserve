<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * CRM de huéspedes (spec-profundidad §1). El documento de identidad va
 * encriptado y sus fotos en disco privado; se sirven solo con permiso
 * guests.view-documents.
 *
 * Soft deletes = "archivado": el huésped con historial desaparece del
 * directorio y del autocompletado, pero sus reservas/estancias lo siguen
 * mostrando (relaciones withTrashed) y puede restaurarse.
 */
class Guest extends Model implements HasMedia
{
    use InteractsWithMedia;
    use SoftDeletes;

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

    /**
     * Los últimos 10 dígitos: el mismo número llega escrito de varias
     * formas según por dónde entre (`5216562025344` del JID de WhatsApp,
     * `+526562025344` del wizard, `656 202 5344` del mostrador).
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $phone);

        if (strlen($digits) < 4) {
            return null;
        }

        // Corto (una extensión, un fijo viejo): se compara completo, que es
        // como se comparaba antes. Nada de suponer que dos números cortos
        // parecidos son la misma persona.
        if (strlen($digits) < 10) {
            return $digits;
        }

        $digits = substr($digits, -10);

        // Números de relleno: en cabañas dos huéspedes distintos tenían
        // guardado 1234567890, así que como llave habría fundido sus
        // fichas y las de todos los que vinieran después.
        return self::isPlaceholderPhone($digits) ? null : $digits;
    }

    /** 1234567890, 0000000000, 1111111111 y compañía: relleno, no un teléfono. */
    public static function isPlaceholderPhone(string $digits): bool
    {
        if (preg_match('/^(\d)\1{9}$/', $digits) === 1) {
            return true;
        }

        return in_array($digits, ['1234567890', '0123456789', '9876543210'], true);
    }

    /**
     * La ficha que ya existe para este teléfono o correo, si la hay.
     *
     * Buscar por el texto exacto del teléfono partía en dos a la misma
     * persona: cabañas 2026-09-15 tenía 7 huéspedes duplicados, y en
     * varios el historial quedó repartido entre las dos fichas (Aline
     * Alonzo: 1 reserva en una y 4 en la otra). El correo se compara sin
     * distinguir mayúsculas.
     */
    public static function findByContact(?string $phone, ?string $email = null): ?self
    {
        $digits = self::normalizePhone($phone);

        if ($digits !== null) {
            $match = self::query()
                ->whereNotNull('phone')
                ->where('phone', 'like', '%'.substr($digits, -4).'%')
                ->get()
                ->first(fn (self $guest) => self::normalizePhone($guest->phone) === $digits);

            if ($match !== null) {
                return $match;
            }
        }

        $email = trim((string) $email);

        if ($email !== '') {
            return self::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
        }

        return null;
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

    /** Fichas del registro de vehículos (las escribe VehicleRegistry). */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * Visitas para listados y buscadores, sin una consulta por fila: mismo
     * criterio que metrics() — estancias completadas + reservas completadas
     * sin estancia. El buscador de recepción contaba solo estancias, y como
     * el historial migrado del sitio anterior no trae estancias, un huésped
     * que ya había venido cinco veces salía "0 visitas" justo al volver a
     * reservar (reclamo de Real de la Sierra, 2026-09-10). Se lee con
     * $guest->visits.
     */
    public function scopeWithVisits(Builder $query): Builder
    {
        return $query->withCount([
            'stays as stay_visits' => fn ($q) => $q->where('status', Stay::STATUS_COMPLETED),
            'reservations as reservation_visits' => fn ($q) => $q
                ->where('status', ReservationStatus::Completed)
                ->whereDoesntHave('stay'),
        ]);
    }

    /** Visitas cargadas con withVisits(); null si la consulta no las trajo. */
    protected function visits(): Attribute
    {
        return Attribute::get(fn () => array_key_exists('stay_visits', $this->attributes)
            ? (int) $this->attributes['stay_visits'] + (int) ($this->attributes['reservation_visits'] ?? 0)
            : null);
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
     * Una visita es una estancia completada O una reserva completada sin
     * estancia registrada. Ese segundo caso no es raro: así llegó todo el
     * historial migrado del sitio anterior (nadie capturó horas de entrada
     * y salida) y así queda cualquier reserva que el hotel cierra sin
     * registrar la llegada. Contando solo estancias, un huésped con ocho
     * noches encima se veía como nuevo — y los cupones de frecuente y el
     * asistente leen justo este número.
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

        // whereDoesntHave('stay'): si la reserva sí tiene estancia, su
        // dinero ya viaja arriba — sumarlo otra vez inflaría el gasto.
        $pastReservations = $this->reservations()
            ->where('status', \App\Enums\ReservationStatus::Completed)
            ->whereDoesntHave('stay')
            ->get(['id', 'starts_at', 'total_amount']);

        $lastVisit = collect([
            $stays->max('check_in_at'),
            $pastReservations->max('starts_at'),
        ])->filter()->max();

        return [
            'visits' => $completed->count() + $pastReservations->count(),
            'active_stay' => $stays->firstWhere('status', Stay::STATUS_ACTIVE) !== null,
            'total_spent' => round(
                $lodging + (float) $consumos + $pastReservations->sum(fn ($r) => (float) $r->total_amount),
                2,
            ),
            'last_visit' => $lastVisit?->format('d/m/Y'),
            'cancellations' => $this->reservations()->where('status', 'cancelled')->count(),
            'no_shows' => $this->reservations()->where('status', 'no_show')->count(),
        ];
    }
}
