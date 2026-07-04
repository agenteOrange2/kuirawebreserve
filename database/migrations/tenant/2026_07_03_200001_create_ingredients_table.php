<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('unit')->default('pieza'); // pieza, kg, lt…
            $table->decimal('stock_qty', 10, 3)->default(0);
            $table->decimal('reorder_point', 10, 3)->nullable();
            // Último costo de compra; el historial vive en stock_movements.
            $table->decimal('cost', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['property_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
