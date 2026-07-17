<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El plan gana descripción comercial y el límite de canales de mensajería
 * (números WhatsApp Meta/Evolution, páginas). max_channels vivía solo en
 * config/plans.php de archivo — la tabla lo pisaba al hidratar, así que el
 * límite no se hacía cumplir: aquí entra a la fuente de verdad real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('description', 160)->nullable()->after('label');
            $table->unsignedInteger('max_channels')->nullable()->after('max_users'); // null = sin límite
        });

        // Mismos valores que config/plans.php de archivo.
        DB::table('plans')->where('key', 'basic')->update(['max_channels' => 1]);
        DB::table('plans')->where('key', 'pro')->update(['max_channels' => 3]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['description', 'max_channels']);
        });
    }
};
