<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caseta de motel en dos momentos: la caseta abre el acceso con la tarifa y el
 * método previsto, y los datos del carro (placa, marca, modelo, color) más el
 * cobro llegan después, cuando el encargado regresa con el papel.
 *
 * Este sello dice cuándo se terminó de capturar esa llegada. Se prefirió a
 * deducirlo de "sin placa y sin identificación" por un caso real que la
 * deducción no cubre: el cliente que NO quiso dar datos dejaría el aviso de
 * "falta capturar" encendido para siempre.
 *
 * Las estancias que ya existen nacen selladas: se capturaron completas con el
 * flujo anterior, y encenderles el aviso hacia atrás sería ruido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->timestamp('arrival_completed_at')->nullable()->after('id_document_number');
        });

        // Histórico: todo lo anterior a este cambio ya venía completo.
        \Illuminate\Support\Facades\DB::table('stays')
            ->whereNull('arrival_completed_at')
            ->update(['arrival_completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->dropColumn('arrival_completed_at');
        });
    }
};
