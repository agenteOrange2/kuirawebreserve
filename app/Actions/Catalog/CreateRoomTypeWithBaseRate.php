<?php

namespace App\Actions\Catalog;

use App\Models\RoomType;
use Illuminate\Support\Facades\DB;

/**
 * Precio único (spec-plan-maestro E2/E3): crea un tipo de habitación junto
 * con su tarifa "Tarifa base" en una transacción — el precio se captura UNA
 * vez. La usan el alta de tipos del catálogo y el alta rápida de
 * habitaciones únicas (motel).
 */
class CreateRoomTypeWithBaseRate
{
    public function __construct(protected AddBaseRatePlan $addRate) {}

    /**
     * @param  array<string, mixed>  $typeData  atributos del RoomType
     * @param  array{price: mixed, rate_type: string, duration_unit?: ?string, duration_value?: mixed}  $rate
     */
    public function execute(array $typeData, array $rate): RoomType
    {
        return DB::transaction(function () use ($typeData, $rate) {
            $roomType = RoomType::create($typeData);
            $this->addRate->execute($roomType, $rate);

            return $roomType;
        });
    }
}
