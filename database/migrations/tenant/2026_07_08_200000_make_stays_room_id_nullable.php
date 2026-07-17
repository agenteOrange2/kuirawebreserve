<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Borrar una habitación ya no muere en la FK de stays: el historial de la
// estancia conserva huésped y montos (cortes, reportes) pero suelta la
// referencia a la habitación (SET NULL), igual que ya hacían las reservas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
        });

        Schema::table('stays', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->change();
            $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
        });

        Schema::table('stays', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable(false)->change();
            $table->foreign('room_id')->references('id')->on('rooms')->restrictOnDelete();
        });
    }
};
