<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Payments\PaymentGuestNotifier;
use Illuminate\Console\Command;
use Throwable;

/**
 * Recordatorio de llegada: reservas confirmadas que llegan en las
 * próximas 24 horas reciben UN aviso (conversación si existe; si no,
 * WhatsApp/correo directo). Se omiten las reservas recién hechas — quien
 * reservó hace un par de horas no necesita que se lo recuerden. Correr
 * por tenant: tenants:run.
 */
class SendArrivalReminders extends Command
{
    protected $signature = 'reservations:arrival-reminders';

    protected $description = 'Recuerda su llegada a los huéspedes con reserva confirmada para las próximas 24 horas';

    public function handle(PaymentGuestNotifier $notifier): int
    {
        $settings = Property::query()->first()?->settings ?? [];

        if (! (bool) ($settings['arrival_reminder_enabled'] ?? true)) {
            $this->info('Recordatorio de llegada desactivado para este hotel; sin acciones.');

            return self::SUCCESS;
        }

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

        return self::SUCCESS;
    }
}
