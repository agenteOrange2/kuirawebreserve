<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Precio único (spec-plan-maestro E2 / spec-modulos-y-precio-unico Parte I):
 * la tarifa es la única fuente de precio. Los tipos que ya tienen tarifa no
 * cambian (caso motellacupula: sus tarifas ya espejean base_price); a los
 * tipos SIN ninguna tarifa se les genera "Tarifa base" por noche desde su
 * base_price. base_price > 0 requerido: un tipo con precio 0 y sin tarifa
 * queda "Sin tarifa — no reservable" (guarda visible) en vez de venderse
 * gratis. La columna base_price deja de escribirse/mostrarse y se eliminará
 * físicamente un ciclo después, verificada en producción.
 */
return new class extends Migration
{
    public function up(): void
    {
        $types = DB::table('room_types')
            ->whereNotIn('id', DB::table('rate_plans')->select('room_type_id'))
            ->where('base_price', '>', 0)
            ->get();

        foreach ($types as $type) {
            DB::table('rate_plans')->insert([
                'property_id' => $type->property_id,
                'room_type_id' => $type->id,
                'name' => 'Tarifa base',
                'type' => 'night',
                'duration_unit' => null,
                'duration_value' => null,
                'duration_minutes' => null,
                'price' => $type->base_price,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Solo borra lo que esta migración pudo haber creado.
        DB::table('rate_plans')
            ->where('name', 'Tarifa base')
            ->where('type', 'night')
            ->delete();
    }
};
