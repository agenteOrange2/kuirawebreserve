<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los huéspedes con historial de reservas/estancias ya no se bloquean al
 * eliminar: se archivan (soft delete) para conservar el rastro y poder
 * restaurarlos desde el directorio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
