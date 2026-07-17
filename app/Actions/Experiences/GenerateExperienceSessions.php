<?php

namespace App\Actions\Experiences;

use App\Models\Experience;
use App\Models\ExperienceBooking;
use App\Models\ExperienceSession;
use App\Models\ExperienceVehicle;
use Illuminate\Support\Facades\DB;

/**
 * Materializa la programación semanal (operating_days + horarios con sus
 * vehículos) en sesiones reales para el horizonte de venta. Las sesiones
 * siguen siendo la única verdad del cupo (lock en CreateExperienceBooking);
 * esto solo las fabrica por adelantado, así que todo lo ya construido
 * (wizard, cobros, cupo duro) funciona igual sin distinguir origen.
 *
 * Reglas:
 * - Solo toca sesiones GENERADAS (experience_slot_id != null) y futuras;
 *   las manuales ("por si se ocupa") nunca se tocan.
 * - Cupo = override del horario o suma de sus vehículos activos; si la
 *   flota cambia, las sesiones futuras se actualizan sin bajar jamás de lo
 *   ya vendido (eso inventaría overbooking).
 * - Una sesión generada que el hotel canceló NO se revive: cancelar esa
 *   fecha fue decisión explícita.
 * - Poda sesiones futuras que ya no corresponden a la programación (día
 *   quitado, horario movido) solo si nadie ha reservado; con gente
 *   adentro se quedan y avisar/cancelar es decisión humana.
 */
class GenerateExperienceSessions
{
    /**
     * Días hacia adelante que se mantienen en venta: un año, igual que las
     * habitaciones — el huésped planea sus vacaciones con meses de
     * anticipación y el tour debe estar abierto cuando busque.
     */
    public const HORIZON_DAYS = 365;

    /** @return array{created: int, updated: int, pruned: int} */
    public function handle(?Experience $only = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'pruned' => 0];

        $experiences = Experience::query()
            ->when($only !== null, fn ($q) => $q->whereKey($only->id))
            ->where('active', true)
            ->whereNotNull('operating_days')
            ->with(['slots' => fn ($q) => $q->where('active', true)])
            ->get();

        if ($experiences->isEmpty()) {
            return $stats;
        }

        $fleet = ExperienceVehicle::query()->where('active', true)->get()->keyBy('id');

        foreach ($experiences as $experience) {
            DB::transaction(function () use ($experience, $fleet, &$stats) {
                $days = array_map('intval', $experience->operating_days ?? []);

                // Lo que la programación dice que debe existir en el horizonte.
                $wanted = [];
                foreach ($experience->slots as $slot) {
                    $capacity = $slot->effectiveCapacity($fleet);

                    if ($capacity < 1) {
                        continue; // sin vehículos vivos ni override: nada que vender
                    }

                    for ($i = 0; $i <= self::HORIZON_DAYS; $i++) {
                        $date = today()->addDays($i);

                        if (! in_array($date->isoWeekday(), $days, true)) {
                            continue;
                        }

                        $startsAt = $date->setTimeFromTimeString($slot->start_time);

                        if ($startsAt->isPast()) {
                            continue;
                        }

                        $wanted[$slot->id.'|'.$startsAt->format('Y-m-d H:i')] = [
                            'slot_id' => $slot->id,
                            'starts_at' => $startsAt,
                            'capacity' => $capacity,
                        ];
                    }
                }

                $existing = $experience->sessions()
                    ->whereNotNull('experience_slot_id')
                    ->where('starts_at', '>', now())
                    ->withSum(['bookings as people_booked' => fn ($q) => $q->whereIn('status', [
                        ExperienceBooking::STATUS_PENDING, ExperienceBooking::STATUS_CONFIRMED,
                    ])], 'people')
                    ->get()
                    ->keyBy(fn (ExperienceSession $s) => $s->experience_slot_id.'|'.$s->starts_at->format('Y-m-d H:i'));

                foreach ($wanted as $key => $target) {
                    $session = $existing->get($key);

                    if ($session) {
                        $booked = (int) ($session->people_booked ?? 0);
                        $capacity = max($target['capacity'], $booked);

                        if ($session->status === ExperienceSession::STATUS_SCHEDULED && (int) $session->capacity !== $capacity) {
                            $session->update(['capacity' => $capacity]);
                            $stats['updated']++;
                        }

                        continue;
                    }

                    $experience->sessions()->create([
                        'experience_slot_id' => $target['slot_id'],
                        'starts_at' => $target['starts_at'],
                        'capacity' => $target['capacity'],
                        'status' => ExperienceSession::STATUS_SCHEDULED,
                    ]);
                    $stats['created']++;
                }

                foreach ($existing as $key => $session) {
                    if (isset($wanted[$key]) || (int) ($session->people_booked ?? 0) > 0) {
                        continue;
                    }

                    $session->delete();
                    $stats['pruned']++;
                }
            });
        }

        return $stats;
    }
}
