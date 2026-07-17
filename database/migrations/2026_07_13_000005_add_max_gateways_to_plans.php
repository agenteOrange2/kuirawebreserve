<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gating de pasarelas de pago por plan (spec-pagos §12): las transferencias
 * con verificación van en todos los planes (cero infra externa); las
 * pasarelas conectadas son gancho del Pro. null = sin límite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('max_gateways')->nullable()->after('max_channels');
        });

        DB::table('plans')->where('key', 'basic')->update(['max_gateways' => 0]);
        DB::table('plans')->where('key', 'pro')->update(['max_gateways' => 3]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('max_gateways');
        });
    }
};
