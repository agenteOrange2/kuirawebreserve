<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de limpieza: registro del trabajo real de las camaristas.
 *
 * Hasta ahora el único rastro era `room_status_logs`, que dice cuándo cambió
 * el semáforo pero no quién limpió ni qué se hizo — y cuando el reloj mueve
 * el estado solo, ni siquiera hay persona. Estas dos tablas cierran ese hueco.
 *
 * `housekeepers` existe a propósito aparte de `users`: las camaristas NO
 * entran al sistema, así que no deben consumir el límite de usuarios del plan
 * ni cargar con credenciales que nadie va a usar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeepers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            // Solo si además tiene cuenta (p. ej. una supervisora): permite
            // ligar su registro con lo que hace dentro del panel.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('active');
        });

        Schema::create('room_cleanings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            // Si se da de baja a una camarista, su historial no se borra:
            // el registro queda sin nombre pero los tiempos siguen contando.
            $table->foreignId('housekeeper_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stay_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kind', 20)->default('salida'); // salida | retoque | profunda

            $table->timestamp('started_at');
            // Null = limpieza en curso (el cronómetro sigue corriendo).
            $table->timestamp('ended_at')->nullable();
            // Se sella al cerrar para no recalcular en cada reporte.
            $table->unsignedInteger('minutes')->nullable();

            $table->json('checklist')->nullable(); // llaves marcadas del checklist del hotel
            $table->json('linens')->nullable();    // {sabanas: n, toallas: n, ...}
            $table->text('notes')->nullable();

            // Desperfecto levantado desde el mismo registro.
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            // Quién capturó (gerente o recepción), distinto de quién limpió.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 10)->default('plano'); // plano | manual

            $table->timestamps();

            $table->index(['room_id', 'started_at']);
            $table->index(['housekeeper_id', 'started_at']);
            $table->index('ended_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_cleanings');
        Schema::dropIfExists('housekeepers');
    }
};
