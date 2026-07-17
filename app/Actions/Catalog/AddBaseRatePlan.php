<?php

namespace App\Actions\Catalog;

use App\Enums\RateDurationUnit;
use App\Enums\RatePlanType;
use App\Models\RatePlan;
use App\Models\RoomType;

/**
 * Crea "Tarifa base" para un tipo existente (precio único, E2). Extraída
 * de CreateRoomTypeWithBaseRate para reutilizarla también cuando el tipo
 * ya existe (agente importador: solo agrega tarifa si el tipo aún no
 * tiene ninguna activa — nunca toca un precio que el hotel ya vende).
 */
class AddBaseRatePlan
{
    /**
     * @param  array{price: mixed, rate_type: string, duration_unit?: ?string, duration_value?: mixed}  $rate
     */
    public function execute(RoomType $roomType, array $rate): RatePlan
    {
        $type = RatePlanType::from($rate['rate_type']);
        $unit = $type === RatePlanType::Block ? RateDurationUnit::from($rate['duration_unit']) : null;
        $value = $type === RatePlanType::Block ? (int) $rate['duration_value'] : null;

        return RatePlan::create([
            'property_id' => $roomType->property_id,
            'room_type_id' => $roomType->id,
            'name' => 'Tarifa base',
            'type' => $type,
            'duration_unit' => $unit,
            'duration_value' => $value,
            'duration_minutes' => $unit?->minutes() !== null ? $unit->minutes() * $value : null,
            'price' => $rate['price'],
            'active' => true,
        ]);
    }
}
