<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnóstico de canales: waba_id permite verificar/reparar la suscripción
 * de la app a la cuenta de WhatsApp por API (la causa #1 de "no llegan
 * mensajes"), y last_event_at deja rastro del último webhook recibido para
 * ver de un vistazo si el canal está vivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_channel_links', function (Blueprint $table) {
            $table->string('waba_id')->nullable()->after('external_id');
            $table->timestamp('last_event_at')->nullable()->after('active');
        });

        Schema::table('evolution_channel_links', function (Blueprint $table) {
            $table->timestamp('last_event_at')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('meta_channel_links', function (Blueprint $table) {
            $table->dropColumn(['waba_id', 'last_event_at']);
        });

        Schema::table('evolution_channel_links', function (Blueprint $table) {
            $table->dropColumn('last_event_at');
        });
    }
};
