<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            // Cargo a habitación: la venta puede ir a una estancia activa.
            $table->foreignId('stay_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('completed'); // completed|void
            $table->decimal('total', 10, 2);
            $table->decimal('total_cost', 10, 2)->default(0); // COGS snapshot
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'created_at']);
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('qty', 10, 3);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
