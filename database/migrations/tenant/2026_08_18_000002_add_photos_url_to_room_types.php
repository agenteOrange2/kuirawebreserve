<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga pública de fotos del tipo de habitación (la página del sitio web del
 * hotel). El asistente IA la comparte cuando el huésped pide fotos, en vez
 * de decir "no tengo acceso a fotos".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->string('photos_url', 500)->nullable()->after('amenities');
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('photos_url');
        });
    }
};
