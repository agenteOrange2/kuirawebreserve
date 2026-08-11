<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes del menú digital (módulo menu-digital): el huésped arma su
 * pedido en /menu y el staff lo atiende desde el panel. NO es una venta:
 * el cobro real se hace en el POS como siempre (cargo a estancia o pago
 * directo) — por eso los items van congelados en JSON con el precio del
 * momento, sin tocar inventario hasta que el staff lo procese.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            // Habitación resuelta por número si coincide; el texto libre
            // del huésped se conserva tal cual en room_label.
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('room_label')->nullable();
            $table->string('guest_name');
            $table->text('notes')->nullable();
            // [{product_id, name, qty, price}] congelado al solicitar.
            $table->json('items');
            $table->decimal('total', 10, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->foreignId('attended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_requests');
    }
};
