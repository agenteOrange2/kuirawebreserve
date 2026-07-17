<?php

namespace App\Actions\Rooms;

use App\Models\Room;
use Illuminate\Support\Facades\DB;

/**
 * Alta masiva por rango (spec-plan-maestro E3): "del 101 al 110, tipo X,
 * zona Y" crea N habitaciones de golpe. Los números que ya existen se
 * OMITEN (no es error: capturar 101-120 con 105 ya dada de alta es el caso
 * normal). El límite del plan lo valida el controlador antes de llamar.
 */
class CreateRoomRange
{
    /**
     * @return array{created: list<string>, skipped: list<string>}
     */
    public function execute(int $propertyId, int $roomTypeId, ?int $zoneId, int $from, int $to): array
    {
        $numbers = array_map('strval', range($from, $to));

        $existing = Room::query()
            ->where('property_id', $propertyId)
            ->whereIn('number', $numbers)
            ->pluck('number')
            ->all();

        $toCreate = array_values(array_diff($numbers, $existing));

        DB::transaction(function () use ($toCreate, $propertyId, $roomTypeId, $zoneId) {
            // Posición inicial escalonada para que no se encimen en el plano
            // (mismo patrón que el alta individual).
            $offset = Room::query()->where('property_id', $propertyId)->count();

            foreach ($toCreate as $i => $number) {
                $n = $offset + $i;

                Room::create([
                    'property_id' => $propertyId,
                    'room_type_id' => $roomTypeId,
                    'zone_id' => $zoneId,
                    'number' => $number,
                    'pos_x' => 40 + ($n % 5) * 160,
                    'pos_y' => 40 + intdiv($n, 5) * 120,
                ]);
            }
        });

        return ['created' => $toCreate, 'skipped' => array_values($existing)];
    }
}
