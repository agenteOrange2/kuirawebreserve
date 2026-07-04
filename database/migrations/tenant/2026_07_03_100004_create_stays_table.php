<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rate_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();

            $table->string('guest_name')->nullable();
            $table->unsignedSmallInteger('num_people')->default(1);

            $table->dateTime('check_in_at');
            $table->dateTime('planned_end_at');
            $table->dateTime('check_out_at')->nullable();

            // active|completed
            $table->string('status')->default('active');
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('channel')->default('walk_in');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['room_id', 'status', 'check_in_at', 'planned_end_at'], 'stays_overlap_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stays');
    }
};
