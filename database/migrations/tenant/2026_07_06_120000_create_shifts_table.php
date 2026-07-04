<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            // Encargado del turno.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable(); // null = turno abierto

            // Fondo de caja con el que arranca el turno.
            $table->decimal('opening_cash', 12, 2)->default(0);

            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'user_id', 'started_at']);
            $table->index(['property_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
