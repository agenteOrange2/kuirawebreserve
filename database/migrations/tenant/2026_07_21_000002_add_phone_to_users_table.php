<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teléfono del usuario del panel (staff): opcional, para el directorio
 * interno y el buscador de /usuarios (por nombre, correo o teléfono).
 */
return new class extends Migration
{
    public function up(): void
    {
        // En tests las migraciones centrales y tenant corren sobre la misma
        // BD sqlite: la central 2026_07_28_000001 pudo agregarla ya.
        if (Schema::hasColumn('users', 'phone')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
