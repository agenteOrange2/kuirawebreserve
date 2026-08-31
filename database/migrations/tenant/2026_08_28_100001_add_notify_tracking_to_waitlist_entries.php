<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora del aviso de la lista de espera (módulo lista-espera): hasta
 * aquí `notified_at` se sellaba ANTES de mandar y el resultado del envío
 * se tiraba, así que "Avisado" podía significar "no salió por ningún
 * canal". Ahora se guarda por dónde salió, cuántos intentos van y por qué
 * falló el último; y la conversión deja de ser palabra de honor: se liga a
 * la reserva que salió de la espera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            // whatsapp | email | whatsapp+email (null = nunca salió).
            $table->string('notified_channel', 30)->nullable()->after('notified_at');
            $table->unsignedSmallInteger('notify_attempts')->default(0)->after('notified_channel');
            $table->dateTime('notify_failed_at')->nullable()->after('notify_attempts');
            $table->string('notify_error', 160)->nullable()->after('notify_failed_at');
            // La reserva que salió de esta espera (conversión con prueba).
            $table->foreignId('reservation_id')->nullable()->after('notify_error')->constrained()->nullOnDelete();
            $table->dateTime('converted_at')->nullable()->after('reservation_id');
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_id');
            $table->dropColumn([
                'notified_channel',
                'notify_attempts',
                'notify_failed_at',
                'notify_error',
                'converted_at',
            ]);
        });
    }
};
