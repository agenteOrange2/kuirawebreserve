<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo llegaron: en carro o a pie. La caseta ya lo elige al abrir el acceso
 * —lo tiene enfrente— pero esa decisión no se guardaba en ningún lado: solo
 * decidía qué campos mandaba el formulario. Por eso, al completar el registro
 * cuando regresaba el papel, se volvía a preguntar desde cero.
 *
 * Nullable a propósito: las estancias anteriores a este cambio no lo traen y
 * el diálogo de completar sigue preguntando en ese caso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->string('arrival_mode', 10)->nullable()->after('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->dropColumn('arrival_mode');
        });
    }
};
