<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tipos de turno del hotel (matutino, vespertino, nocturno…): cada
        // hotel define los suyos con su horario y color.
        Schema::create('shift_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->time('starts_at');
            $table->time('ends_at'); // puede cruzar medianoche (23:00 → 07:00)
            $table->string('color')->default('primary'); // token del theme
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'name']);
        });

        // Rol semanal: a qué usuario le toca qué tipo de turno cada día.
        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shift_type_id')->constrained('shift_types')->cascadeOnDelete();
            $table->date('date');
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Una persona no repite el mismo tipo de turno el mismo día.
            $table->unique(['user_id', 'shift_type_id', 'date']);
            $table->index(['property_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('shift_types');
    }
};
