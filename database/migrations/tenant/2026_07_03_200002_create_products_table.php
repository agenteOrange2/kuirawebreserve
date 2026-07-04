<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->nullable();
            $table->string('name');

            // simple: descuenta su propio stock (coca) ·
            // composite: descuenta ingredientes vía receta/BOM (hamburguesa).
            $table->string('type')->default('simple');

            $table->string('unit')->default('pieza');
            $table->decimal('price', 10, 2);
            $table->decimal('cost', 10, 2)->default(0);
            $table->boolean('track_stock')->default(true);
            $table->decimal('stock_qty', 10, 3)->default(0);
            $table->decimal('reorder_point', 10, 3)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'name']);
            $table->index(['property_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
