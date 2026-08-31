<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seguimiento de las respuestas del cuestionario (módulo encuestas):
 * hasta aquí /encuestas era solo un reporte — una queja de dos estrellas
 * se leía y ahí se quedaba, sin forma de decir quién la atendió ni qué se
 * hizo. Ahora cada respuesta se puede cerrar con nota, y si la queja era
 * una falla real, queda ligada a la incidencia que se levantó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stay_surveys', function (Blueprint $table) {
            $table->dateTime('handled_at')->nullable()->after('submitted_at');
            $table->foreignId('handled_by')->nullable()->after('handled_at')->constrained('users')->nullOnDelete();
            $table->text('handled_notes')->nullable()->after('handled_by');
            // La incidencia que salió de esta queja (si se levantó una).
            $table->foreignId('incident_id')->nullable()->after('handled_notes')->constrained()->nullOnDelete();

            $table->index('handled_at');
        });
    }

    public function down(): void
    {
        Schema::table('stay_surveys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handled_by');
            $table->dropConstrainedForeignId('incident_id');
            $table->dropIndex(['handled_at']);
            $table->dropColumn(['handled_at', 'handled_notes']);
        });
    }
};
