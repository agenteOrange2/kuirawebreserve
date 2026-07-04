<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Todo movimiento queda auditado → habilita COGS, valuación y
        // márgenes (spec §8). qty firmada: entrada +, salida −.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->morphs('stockable'); // Ingredient | Product
            $table->string('type'); // purchase|sale|waste|adjustment
            $table->decimal('qty', 10, 3);
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->nullableMorphs('ref'); // Order, etc.
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
