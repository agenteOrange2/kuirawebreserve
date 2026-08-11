<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Curación del menú digital (módulo menu-digital): "activo" dice si se
 * vende en el POS; esto decide si además se ofrece al huésped en /menu.
 * Mismo patrón que available_in_wizard. Default false: el hotel elige a
 * propósito qué sale en su carta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('available_in_menu')->default(false)->after('available_in_wizard');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('available_in_menu');
        });
    }
};
