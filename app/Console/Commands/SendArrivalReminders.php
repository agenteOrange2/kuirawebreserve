<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Payments\PaymentGuestNotifier;
use App\Services\ReservationPolicy;
use Illuminate\Console\Command;
use Throwable;

/**
 * Recordatorios de llegada, dos barridos con sello propio cada uno:
 *
 * 1. Reservas confirmadas que llegan en las próximas 24 horas reciben UN
 *    aviso (conversación si existe; si no, WhatsApp/correo directo). Se
 *    omiten las recién hechas — quien reservó hace un par de horas no
 *    necesita que se lo recuerden.
 * 2. Aviso el día de la llegada: cuando faltan N horas para la entrada
 *    (N configurable en /ajustes/metodos-pago, default 2), un segundo
 *    mensaje corto — su habitación lo espera hoy, código y hora.
 *
 * Correr por tenant: tenants:run.
 */
class SendArrivalReminders extends Command
{
    protected $signature = 'reservations:arrival-reminders';

    protected $description = 'Recuerda su llegada a los huéspedes con reserva confirmada (24 horas antes y el día de la llegada)';

    public function handle(PaymentGuestNotifier $notifier, ReservationPolicy $policy): int
    {
        $settings = Property::query()->first()?->settings ?? [];

        if ((bool) ($settings['arrival_reminder_enabled'] ?? true)) {
            $reservations = Reservation::query()
                ->with(['roomType', 'guest'])
                ->where('status', ReservationStatus::Confirmed)
                ->whereNull('arrival_reminder_sent_at')
                ->whereBetween('starts_at', [now(), now()->addDay()])
                // Reservas recién hechas: la confirmación ya les dijo todo.
                ->where('created_at', '<=', now()->subHours(6))
                ->get();

            $sent = 0;

            foreach ($reservations as $reservation) {
                // La marca va ANTES del envío: un aviso perdido es mejor que un
                // huésped bombardeado si el transporte truena a medias.
                $reservation->forceFill(['arrival_reminder_sent_at' => now()])->saveQuietly();

                try {
                    $notifier->arrivalReminder($reservation);
                    $sent++;
                } catch (Throwable $e) {
                    report($e);
                }
            }

            $this->info("Recordatorios de llegada enviados: {$sent}");
        } else {
            $this->info('Recordatorio de llegada desactivado para este hotel; sin acciones.');
        }

        // Segundo barrido: aviso el día de la llegada, cuando la entrada
        // está a N horas o menos. Sin filtro de recién hechas — aunque la
        // confirmación acabe de salir, este es el "ya casi llegas" del día.
        if ($policy->arrivalSoonEnabled()) {
            $soon = Reservation::query()
                ->with(['roomType', 'guest'])
                ->where('status', ReservationStatus::Confirmed)
                ->whereNull('arrival_soon_reminder_sent_at')
                ->whereBetween('starts_at', [now(), now()->addHours($policy->arrivalSoonHours())])
                ->get();

            $sentSoon = 0;

            foreach ($soon as $reservation) {
                // Mismo patrón de idempotencia: la marca va ANTES del envío.
                $reservation->forceFill(['arrival_soon_reminder_sent_at' => now()])->saveQuietly();

                try {
                    $notifier->arrivalSoonReminder($reservation);
                    $sentSoon++;
                } catch (Throwable $e) {
                    report($e);
                }
            }

            $this->info("Avisos del día de la llegada enviados: {$sentSoon}");
        }

        return self::SUCCESS;
    }
}
