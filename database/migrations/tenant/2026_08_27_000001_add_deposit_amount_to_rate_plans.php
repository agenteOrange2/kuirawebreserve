<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Anticipo en monto fijo: alternativa al deposit_percent para
        // hoteles que cobran "$1,500 para apartar" sin importar la estancia.
        // Son excluyentes: la tarifa usa porcentaje O monto, nunca ambos.
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->decimal('deposit_amount', 10, 2)->nullable()->after('deposit_percent');
        });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn('deposit_amount');
        });
    }
};
