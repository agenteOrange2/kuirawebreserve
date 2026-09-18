<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lo que salió de la caja en el periodo, congelado en el corte: un
     * gasto borrado después no puede cambiar un arqueo ya firmado.
     */
    public function up(): void
    {
        Schema::table('cash_cuts', function (Blueprint $table) {
            $table->unsignedInteger('expenses_count')->default(0)->after('opening_cash');
            $table->decimal('expenses_total', 12, 2)->default(0)->after('expenses_count');
        });
    }

    public function down(): void
    {
        Schema::table('cash_cuts', function (Blueprint $table) {
            $table->dropColumn(['expenses_count', 'expenses_total']);
        });
    }
};
