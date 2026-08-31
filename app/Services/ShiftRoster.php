<?php

namespace App\Services;

use App\Models\ShiftAssignment;
use Illuminate\Support\Collection;

/**
 * Quién está programado hoy, por área. El rol (shift_assignments) cubre al
 * personal del panel, a las camaristas y a los técnicos; esta clase lo lee
 * para que limpieza e incidencias puedan responder la pregunta que la
 * operación hace todo el día: "¿quién está en turno ahorita?".
 *
 * No confundir con Shift, que es la asistencia con caja de recepción.
 */
class ShiftRoster
{
    /**
     * Programados hoy de un área, con su turno y si ese turno cubre la
     * hora actual.
     *
     * @param  'user'|'housekeeper'|'technician'  $kind
     * @return Collection<int, array{id: int, name: string, shift: ?string, time: ?string, color: string, now: bool}>
     */
    public function today(string $kind): Collection
    {
        $class = ShiftAssignment::classFor($kind);

        if ($class === null) {
            return collect();
        }

        return ShiftAssignment::query()
            ->with(['assignable', 'shiftType:id,name,starts_at,ends_at,color'])
            ->where('assignable_type', $class)
            ->whereDate('date', today())
            ->get()
            // Una persona puede tener dos turnos el mismo día; para la
            // operación manda el que está corriendo.
            ->sortByDesc(fn (ShiftAssignment $a) => $a->shiftType?->covers() ? 1 : 0)
            ->unique('assignable_id')
            ->map(fn (ShiftAssignment $a) => [
                'id' => (int) $a->assignable_id,
                'name' => $a->assigneeName() ?? 'Sin nombre',
                'shift' => $a->shiftType?->name,
                'time' => $a->shiftType?->timeLabel(),
                'color' => $a->shiftType?->color ?? 'primary',
                'now' => (bool) $a->shiftType?->covers(),
            ])
            ->sortByDesc('now')
            ->values();
    }

    /**
     * Ids de quienes están en turno EN ESTE MOMENTO (no solo programados
     * hoy), para preseleccionar a quien de verdad está trabajando.
     *
     * @param  'user'|'housekeeper'|'technician'  $kind
     * @return array<int, int>
     */
    public function onDutyNow(string $kind): array
    {
        return $this->today($kind)
            ->filter(fn (array $person) => $person['now'])
            ->pluck('id')
            ->all();
    }
}
