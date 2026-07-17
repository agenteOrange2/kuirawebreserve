<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulos "Extras de reserva" y "Experiencias" (spec-reservas-avanzado §3,
 * spec-motor-reservas-web §12): dos conceptos que NO se mezclan —
 *
 * - Extras: add-ons SIN calendario ni cupo que se pegan a una reserva de
 *   habitación (decoración, desayuno, late checkout). Catálogo `extras` +
 *   líneas congeladas en `reservations.extras` (mismo patrón JSON que
 *   `products`): suman al total ANTES de emitir cobros, así el anticipo
 *   y el saldo los incluyen solos.
 * - Experiencias: reservables POR SÍ SOLAS con horario y cupo propios
 *   (tours, recorridos). Es otro motor: sesiones con capacidad dura +
 *   reservas propias con folio EXP-.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->decimal('price', 10, 2);
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('reservations', function (Blueprint $table) {
            // Líneas congeladas {extra_id, name, qty, unit_price, total} —
            // igual que products: el catálogo puede cambiar después sin
            // alterar lo ya vendido.
            $table->json('extras')->nullable()->after('products');
        });

        Schema::create('experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('includes')->nullable(); // lista de strings: guía, equipo, refrigerio...
            $table->unsignedInteger('duration_minutes')->nullable();
            // per_person: price por cabeza; flat: price por grupo/vehículo
            // (decisión §3.5.1: ambos desde el inicio para no migrar luego).
            $table->string('pricing_mode', 20)->default('per_person');
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('min_people')->default(1);
            $table->unsignedInteger('max_people')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('experience_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experience_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            // Cupo TOTAL de la sesión (suma de personas de sus reservas
            // vivas). Duro, con lock — misma filosofía anti-doble-venta que
            // AvailabilityService (decisión §3.5.3).
            $table->unsignedInteger('capacity');
            $table->string('status', 20)->default('scheduled'); // scheduled|cancelled|completed
            $table->timestamps();

            $table->index(['experience_id', 'starts_at']);
        });

        Schema::create('experience_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experience_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            // Opcional: liga a una estancia si el huésped ya tiene una
            // (decisión §3.5.2: la experiencia se vende también sola).
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->unsignedInteger('people');
            $table->decimal('total', 10, 2); // congelado al reservar
            $table->string('status', 20)->default('pending'); // pending|confirmed|cancelled|completed
            $table->string('code', 30)->nullable()->unique();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_bookings');
        Schema::dropIfExists('experience_sessions');
        Schema::dropIfExists('experiences');
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('extras');
        });
        Schema::dropIfExists('extras');
    }
};
