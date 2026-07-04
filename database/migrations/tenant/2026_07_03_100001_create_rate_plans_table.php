<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // night = por noche (hotel) · block = por bloque/rato (motel);
            // una tarifa por hora es un block con duration_minutes = 60.
            $table->string('type')->default('night');
            $table->unsignedInteger('duration_minutes')->nullable();

            $table->decimal('price', 10, 2);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['property_id', 'room_type_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_plans');
    }
};
