<?php

namespace App\Services\Channels;

use App\Models\Property;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\WaitlistEntry;

/**
 * Aviso de la lista de espera (módulo lista-espera): cuando una
 * cancelación o no-show libera fechas, se busca a quienes esperaban un
 * hueco solapado (del mismo tipo de habitación, o de cualquiera si no
 * eligieron tipo) y se les avisa por WhatsApp/correo con el link al wizard
 * para reservar. Cada entrada se sella como notified ANTES de mandar
 * (claim atómico) — un aviso por entrada, nunca spam. Lo dispara
 * TransitionReservation::cancel fuera de su transacción: avisar es
 * cortesía, no puede revertir una cancelación.
 */
class WaitlistNotifier
{
    public function __construct(protected DirectGuestMessenger $direct) {}

    public function roomFreed(Reservation $reservation): void
    {
        // Sin contexto de tenant no hay forma de resolver el módulo (solo
        // pasa en tests/CLI suelto — en producción cancel() siempre corre
        // bajo tenancy); con tenant, el módulo manda.
        $tenant = tenant();
        if ($tenant instanceof Tenant && ! $tenant->hasModule('lista-espera')) {
            return;
        }

        $entries = WaitlistEntry::query()
            ->waiting()
            ->where(fn ($q) => $q
                ->whereNull('room_type_id')
                ->orWhere('room_type_id', $reservation->room_type_id))
            ->overlappingDates($reservation->starts_at, $reservation->ends_at)
            ->get();

        if ($entries->isEmpty()) {
            return;
        }

        $hotel = Property::query()->first()?->name;
        $link = $this->wizardUrl();

        foreach ($entries as $entry) {
            // Claim atómico: si otra corrida ya la selló, esta no manda.
            $claimed = WaitlistEntry::query()
                ->whereKey($entry->id)
                ->where('status', WaitlistEntry::STATUS_WAITING)
                ->update([
                    'status' => WaitlistEntry::STATUS_NOTIFIED,
                    'notified_at' => now(),
                ]);

            if ($claimed === 0) {
                continue;
            }

            $name = trim((string) $entry->guest_name);
            $body = ($name !== '' ? "Hola {$name}, buenas noticias" : 'Buenas noticias')
                .($hotel ? " de {$hotel}" : '')
                .': se liberó espacio para tus fechas ('
                .$entry->starts_at->locale('es')->isoFormat('D [de] MMMM')
                .' al '
                .$entry->ends_at->locale('es')->isoFormat('D [de] MMMM')
                .').';
            $body .= $link
                ? " Reserva aquí: {$link} — la disponibilidad vuela, te recomendamos apartar pronto."
                : ' Contáctanos para apartar tu lugar — la disponibilidad vuela.';

            $this->direct->sendToContact(
                $entry->guest_phone,
                $entry->guest_email,
                'Se liberó espacio para tus fechas',
                $body,
            );
        }
    }

    /**
     * URL pública del wizard (/reservar), SIEMPRE en el dominio del hotel —
     * mismo criterio que PaymentGuestNotifier::bookingLookupUrl (esto corre
     * también desde schedulers, donde route() hereda un host equivocado).
     * null si el hotel no tiene motor-web: la página respondería 403.
     */
    protected function wizardUrl(): ?string
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant || ! $tenant->hasModule('motor-web')) {
            return null;
        }

        $relative = route('tenant.booking.wizard', [], false);
        $domain = $tenant->domains()->value('domain');

        if (! $domain) {
            return url($relative);
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://{$domain}{$relative}";
    }
}
