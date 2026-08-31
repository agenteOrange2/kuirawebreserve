<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El rol semanal deja de ser solo de usuarios del panel.
 *
 * Camaristas y técnicos trabajan por turno igual que recepción, pero no
 * tienen cuenta (viven en `housekeepers` y `technicians` justamente para
 * no consumir el límite de usuarios del plan), así que no se les podía
 * programar: el rol solo aceptaba `user_id`.
 *
 * OJO con la distinción: `shift_assignments` es el ROL (a quién le toca
 * qué turno y qué día) y ahora es polimórfico; `shifts` sigue siendo la
 * asistencia CON CAJA (fondo inicial y corte), que solo aplica a quien
 * maneja dinero y por eso se queda amarrada a `users`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Re-entrante: una corrida anterior pudo dejar las columnas puestas
        // y morir al soltar el índice viejo.
        if (! Schema::hasColumn('shift_assignments', 'assignable_type')) {
            Schema::table('shift_assignments', function (Blueprint $table) {
                $table->string('assignable_type')->nullable()->after('property_id');
                $table->unsignedBigInteger('assignable_id')->nullable()->after('assignable_type');

                $table->index(['assignable_type', 'assignable_id']);
            });
        }

        if (! Schema::hasColumn('shift_assignments', 'user_id')) {
            return;
        }

        // El rol que ya existía es todo de usuarios del panel.
        DB::table('shift_assignments')->whereNotNull('user_id')->update([
            'assignable_type' => \App\Models\User::class,
            'assignable_id' => DB::raw('user_id'),
        ]);

        // En tres pasos y en este orden: el único que empieza con user_id
        // es el índice que sostiene a la llave foránea, así que soltarlo
        // antes que la foránea revienta con errno 1553.
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'shift_type_id', 'date']);
        });

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });

        Schema::table('shift_assignments', function (Blueprint $table) {
            // Misma garantía de antes: nadie queda dos veces en el mismo
            // turno del mismo día.
            $table->unique(['assignable_type', 'assignable_id', 'shift_type_id', 'date'], 'shift_assignments_assignable_unique');
        });
    }

    public function down(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropUnique('shift_assignments_assignable_unique');
            $table->foreignId('user_id')->nullable()->after('property_id')->constrained()->cascadeOnDelete();
        });

        DB::table('shift_assignments')
            ->where('assignable_type', \App\Models\User::class)
            ->update(['user_id' => DB::raw('assignable_id')]);

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropIndex(['assignable_type', 'assignable_id']);
            $table->dropColumn(['assignable_type', 'assignable_id']);
            $table->unique(['user_id', 'shift_type_id', 'date']);
        });
    }
};
