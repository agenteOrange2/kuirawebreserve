<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los checkouts de Stripe traen un fragmento (#fid...) que empuja la URL
 * a ~800 caracteres: VARCHAR(255) truncaba y el cobro moría con 1406
 * justo después de crear la sesión en el proveedor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->text('checkout_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->string('checkout_url')->nullable()->change();
        });
    }
};
