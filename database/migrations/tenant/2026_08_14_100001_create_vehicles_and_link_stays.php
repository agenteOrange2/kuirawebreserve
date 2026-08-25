<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de vehículos del motel (docs/spec-modo-motel.md): en caseta el
 * cliente es el carro, así que la placa necesita ficha propia e historial.
 *
 * `stays.vehicle_plate` NO se toca: se queda como sello de lo que se tecleó
 * esa noche (mismo patrón que `stays.guest_name` junto a `guest_id`), y la
 * ficha editable vive en `vehicles`.
 *
 * En hoteles la tabla simplemente queda vacía: la sección no existe para
 * ellos y apagar nunca borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            // Como se tecleó la última vez (para mostrar) y la llave real de
            // identidad: mayúsculas sin guiones ni espacios.
            $table->string('plate', 20);
            $table->string('plate_normalized', 20)->unique();
            $table->string('brand', 40)->nullable();
            $table->string('model', 40)->nullable();
            $table->string('color', 30)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->text('notes')->nullable();
            // Cuando alguna visita sí dejó datos, la ficha del CRM queda ligada.
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            // Vetar una placa es de lo que más se pide en caseta; nace con la
            // tabla para no obligar a otra migración por tenant.
            $table->boolean('is_blacklisted')->default(false);
            $table->string('blacklist_reason')->nullable();
            $table->timestamps();
        });

        Schema::table('stays', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable()->after('vehicle_desc')
                ->constrained()->nullOnDelete();
        });

        // El llenado con lo ya capturado NO se hace aquí: una migración que
        // llama al modelo se rompe en cuanto el modelo cambia (pasó con los
        // soft deletes de la migración siguiente, que este backfill todavía
        // no conocía). Para un tenant con historial previo se corre a mano:
        //   php artisan tenants:run vehicles:backfill
        // Un tenant nuevo no tiene nada que llenar.
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_id');
        });

        Schema::dropIfExists('vehicles');
    }
};
