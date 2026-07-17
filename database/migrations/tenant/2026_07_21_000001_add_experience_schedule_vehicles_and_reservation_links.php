<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programación semanal de experiencias + vehículos + experiencias como
 * extra cobrable de una reserva (individual o grupal):
 *
 * - `experience_vehicles`: flota del hotel (razer, camioneta...) con su
 *   capacidad. Es catálogo de la propiedad: el mismo vehículo puede servir
 *   a varias experiencias en horarios distintos.
 * - `experiences.operating_days`: qué días de la semana opera el tour
 *   (ISO 1=lunes ... 7=domingo). Null = sin programación (solo sesiones
 *   manuales, como hasta ahora).
 * - `experience_slots`: horarios recurrentes del tour (10:00, 16:00...)
 *   con los vehículos que cuentan en ese horario. El cupo de la sesión
 *   generada = override manual o la suma de capacidad de sus vehículos.
 * - `experience_sessions.experience_slot_id`: marca las sesiones
 *   materializadas por la programación; las manuales quedan con null y la
 *   regeneración nunca las toca. nullOnDelete: borrar el horario no borra
 *   sesiones ya vendidas — quedan huérfanas y el hotel decide.
 * - `reservations.experiences`: líneas congeladas de tours comprados como
 *   extra de la reserva {experience_booking_id, name, starts_at, people,
 *   unit_price, total} — mismo patrón que products/extras: suman al total
 *   ANTES de emitir cobros, así el anticipo y el saldo los incluyen solos.
 * - `experience_bookings.reservation_group_id`: el tour comprado como
 *   extra de un grupo (GRP-) cuelga del grupo, no de una habitación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experience_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('capacity');
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('experiences', function (Blueprint $table) {
            $table->json('operating_days')->nullable()->after('max_people');
        });

        Schema::create('experience_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experience_id')->constrained()->cascadeOnDelete();
            $table->string('start_time', 5); // 'HH:MM' hora local del hotel
            // Ids de experience_vehicles asignados a este horario. JSON y no
            // pivote: el cupo se CONGELA en la sesión generada, aquí solo se
            // consulta al generar.
            $table->json('vehicle_ids')->nullable();
            // Override manual del cupo; null = suma de los vehículos.
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('experience_sessions', function (Blueprint $table) {
            $table->foreignId('experience_slot_id')
                ->nullable()
                ->after('experience_id')
                ->constrained('experience_slots')
                ->nullOnDelete();
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->json('experiences')->nullable()->after('extras');
        });

        Schema::table('experience_bookings', function (Blueprint $table) {
            $table->foreignId('reservation_group_id')
                ->nullable()
                ->after('reservation_id')
                ->constrained('reservation_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('experience_bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_group_id');
        });
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('experiences');
        });
        Schema::table('experience_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('experience_slot_id');
        });
        Schema::dropIfExists('experience_slots');
        Schema::table('experiences', function (Blueprint $table) {
            $table->dropColumn('operating_days');
        });
        Schema::dropIfExists('experience_vehicles');
    }
};
