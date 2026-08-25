<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto cuesta mantener el hotel y quién lo repara.
 *
 * La spec original (estructura/spec-modulos-profundidad.md §2.2) pedía el
 * campo `cost` desde el principio para acumular el gasto por habitación, y
 * nunca se construyó: hoy no hay forma de saber qué cuarto sale caro.
 *
 * `technicians` sigue el molde de `housekeepers`: personal que no entra al
 * sistema. Aquí cubre las dos formas de reparar que tiene el hotel — gente
 * de casa y proveedores externos (plomero, electricista).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technicians', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            // Plomería, electricidad, clima... texto libre: cada hotel llama
            // distinto a lo mismo y un catálogo cerrado estorbaría.
            $table->string('specialty', 60)->nullable();
            // Externo = proveedor que se contrata; interno = personal de casa.
            $table->boolean('external')->default(false);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('active');
        });

        Schema::table('incidents', function (Blueprint $table) {
            // Lo que costó repararla (no lo que se le cobró al huésped).
            $table->decimal('cost', 10, 2)->nullable()->after('resolution_notes');
            // Quién la arregló: distinto de assigned_to, que es el usuario
            // del sistema que le da seguimiento.
            $table->foreignId('technician_id')->nullable()->after('assigned_to')
                ->constrained()->nullOnDelete();
            // La estancia que causó el daño: sin esto, un cargo por sábana
            // rota queda como ticket suelto y no se sabe a quién se le cobró.
            $table->foreignId('stay_id')->nullable()->after('room_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stay_id');
            $table->dropConstrainedForeignId('technician_id');
            $table->dropColumn('cost');
        });

        Schema::dropIfExists('technicians');
    }
};
