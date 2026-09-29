<?php

namespace App\Console\Commands;

use App\Actions\Reservations\TransitionReservation;
use App\Models\Stay;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cierre automático de estancias vencidas: cuando planned_end_at + gracia ya
 * pasó, se hace check-out y la habitación cae a "sucia" — housekeeping la ve
 * en el plano (Reverb la pinta en vivo) y sigue el flujo sucia → limpieza →
 * disponible. Correr por tenant: tenants:run.
 *
 * OJO con el dinero: la salida MANUAL exige cobrar el saldo o forzarla a
 * propósito; esta se salta las dos cosas porque no hay nadie a quien
 * preguntarle. Por eso la estancia queda marcada (auto_closed_at) y, si le
 * quedó saldo, aparece en /reservas/cuentas para cobrarla o cerrarla con
 * motivo. Antes se cerraba en silencio y el dinero desaparecía del panel:
 * no había forma de agregar un cargo ni de registrar un cobro después.
 */
class AutoCheckoutOverdueStays extends Command
{
    protected $signature = 'stays:auto-checkout {--grace= : Minutos de gracia tras la salida prevista}';

    protected $description = 'Hace check-out de estancias cuyo tiempo venció y manda la habitación a sucia';

    public function handle(TransitionReservation $transition): int
    {
        if (! config('reservations.auto_checkout.enabled')) {
            $this->info('Auto-checkout deshabilitado (reservations.auto_checkout.enabled).');

            return self::SUCCESS;
        }

        $grace = (int) ($this->option('grace') ?? config('reservations.auto_checkout.grace_minutes', 15));

        $overdue = Stay::query()
            ->active()
            ->where('planned_end_at', '<=', now()->subMinutes($grace))
            ->with('room')
            ->get();

        $closed = 0;
        $withBalance = 0;

        foreach ($overdue as $stay) {
            try {
                $transition->checkOut($stay, null, ['auto' => true]);
                // Sello de "la cerró el reloj": la bandeja de cuentas lo
                // muestra, porque cambia a quién hay que preguntarle qué pasó.
                $stay->forceFill(['auto_closed_at' => now()])->saveQuietly();
                $closed++;

                // Que el hotel se entere: la habitación ya está en sucia.
                app(\App\Services\StaffAlerts::class)->stayAutoClosed($stay);

                if (($pending = $stay->fresh()->folio()['grand_pending']) > 0) {
                    $withBalance++;
                    $this->warn("Estancia {$stay->id} (hab. {$stay->room?->number}) cerró con saldo de {$pending}: queda en cuentas por cerrar.");
                }
            } catch (Throwable $e) {
                // P. ej. habitación movida a mantenimiento con huésped dentro:
                // se deja para resolución manual, no debe frenar a las demás.
                $this->warn("Estancia {$stay->id} (hab. {$stay->room?->number}): {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Estancias vencidas cerradas: {$closed} de {$overdue->count()}"
            .($withBalance > 0 ? " ({$withBalance} con saldo, en cuentas por cerrar)." : '.'));

        return self::SUCCESS;
    }
}
