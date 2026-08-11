<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decisión comercial 2026-08: el Menú Digital deja de venderse aparte y
 * pasa a ser parte del servicio "Inventario y Control de Costos por
 * Producto" (mismo mundo: los productos del POS son los del menú). El
 * servicio combinado enciende pos + menu-digital; las contrataciones del
 * servicio viejo se convierten en contrataciones del combinado.
 */
return new class extends Migration
{
    public function up(): void
    {
        $menu = DB::table('addon_services')->where('key', 'menu-digital')->first();

        DB::table('addon_services')->where('key', 'inventario-costos')->update([
            'name' => 'Inventario, Control de Costos y Menú Digital',
            'summary' => 'Permite controlar insumos, existencias, recetas, entradas, salidas, mermas y costos de los productos vendidos por el hotel o motel, e incluye el menú digital: catálogo para ofrecerlos por liga, QR o sección web con solicitudes del huésped.',
            'objective' => 'Controlar insumos, productos, entradas, salidas, existencias, recetas y costos relacionados con los productos que vende el hotel o motel, y ofrecerlos al huésped en un menú digital con solicitud de productos.',
            'modules' => json_encode(['pos', 'menu-digital']),
            'updated_at' => now(),
        ]);

        if ($menu !== null) {
            // Quien tenía contratado el Menú Digital pasa al combinado.
            foreach (DB::table('tenant_addon_services')->where('addon_service_key', 'menu-digital')->get() as $contract) {
                DB::table('tenant_addon_services')->updateOrInsert(
                    ['tenant_id' => $contract->tenant_id, 'addon_service_key' => 'inventario-costos'],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }

            // El cascade de la FK limpia sus contrataciones.
            DB::table('addon_services')->where('key', 'menu-digital')->delete();
        }
    }

    public function down(): void
    {
        // La separación de vuelta es decisión comercial (precios incluidos):
        // no se revierte sola. Solo se restaura el nombre original.
        DB::table('addon_services')->where('key', 'inventario-costos')->update([
            'name' => 'Inventario y Control de Costos por Producto',
            'modules' => json_encode(['pos']),
            'updated_at' => now(),
        ]);
    }
};
