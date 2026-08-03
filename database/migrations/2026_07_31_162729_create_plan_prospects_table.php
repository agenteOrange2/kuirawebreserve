<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plan_prospects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hotel_name');
            $table->string('email');
            $table->string('phone', 40);
            $table->unsignedInteger('rooms')->nullable();
            $table->string('plan_key', 40)->nullable();
            $table->string('plan_label', 60);
            $table->text('message')->nullable();
            $table->string('status', 24)->default('new')->index();
            $table->text('notes')->nullable();
            $table->string('source', 80)->default('landing');
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamps();

            $table->foreign('plan_key')
                ->references('key')
                ->on('plans')
                ->nullOnDelete();
            $table->index(['plan_key', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_prospects');
    }
};
