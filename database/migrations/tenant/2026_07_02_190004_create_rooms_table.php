<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->string('number');
            $table->string('status')->default('available');

            // Posición y tamaño en el canvas del plano (drag-and-drop, fase 1).
            $table->integer('pos_x')->default(0);
            $table->integer('pos_y')->default(0);
            $table->unsignedInteger('width')->default(120);
            $table->unsignedInteger('height')->default(80);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
