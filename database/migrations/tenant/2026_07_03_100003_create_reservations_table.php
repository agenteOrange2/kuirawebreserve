<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rate_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();

            $table->string('guest_name')->nullable();
            $table->unsignedSmallInteger('num_people')->default(1);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // pending|confirmed|checked_in|completed|cancelled|no_show
            $table->string('status')->default('pending');
            // pending con hold vencido deja de bloquear disponibilidad.
            $table->dateTime('hold_expires_at')->nullable();

            $table->string('source_channel')->default('front_desk');
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('deposit_amount', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['room_id', 'status', 'starts_at', 'ends_at'], 'reservations_overlap_idx');
            $table->index(['property_id', 'status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
