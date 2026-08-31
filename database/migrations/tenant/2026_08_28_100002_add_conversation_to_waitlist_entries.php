<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El hilo que abrió el asistente al avisar (módulo lista-espera): cuando
 * el aviso sale por el bot, la conversación de la bandeja ES la prueba de
 * que salió, y el renglón la enlaza en vez de pedirle a recepción que la
 * busque a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->after('notify_error')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversation_id');
        });
    }
};
