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

        foreach ($overdue as $stay) {
            try {
                $transition->checkOut($stay, null, ['auto' => true]);
                $closed++;
            } catch (Throwable $e) {
                // P. ej. habitación movida a mantenimiento con huésped dentro:
                // se deja para resolución manual, no debe frenar a las demás.
                $this->warn("Estancia {$stay->id} (hab. {$stay->room?->number}): {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Estancias vencidas cerradas: {$closed} de {$overdue->count()}.");

        return self::SUCCESS;
    }
}
