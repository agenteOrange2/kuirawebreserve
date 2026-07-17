<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo `grupos`: varias habitaciones reservadas de un jalón bajo un
 * folio de grupo (GRP-). El grupo es un cascarón que agrupa reservas
 * normales — cada una conserva su ciclo de vida (confirmar, check-in,
 * cobrar, cancelar) y el grupo suma la vista de conjunto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30)->nullable()->unique();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('reservation_group_id')
                ->nullable()
                ->after('rate_plan_id')
                ->constrained('reservation_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_group_id');
        });
        Schema::dropIfExists('reservation_groups');
    }
};
