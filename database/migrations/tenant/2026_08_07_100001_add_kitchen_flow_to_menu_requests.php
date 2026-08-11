<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flujo de cocina del menú digital: estado intermedio "preparing" (quién
 * tomó el pedido y cuándo) y liga a la venta POS que se genera al
 * despachar (order_id) — con ella el cargo llega al folio o al corte y la
 * solicitud no puede venderse dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_requests', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('payment_method')
                ->constrained('orders')->nullOnDelete();
            $table->foreignId('preparing_by')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('preparing_at')->nullable()->after('preparing_by');
        });
    }

    public function down(): void
    {
        Schema::table('menu_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->dropConstrainedForeignId('preparing_by');
            $table->dropColumn('preparing_at');
        });
    }
};
