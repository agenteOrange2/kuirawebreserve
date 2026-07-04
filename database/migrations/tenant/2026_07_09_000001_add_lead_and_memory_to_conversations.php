<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Embudo de venta y memoria por conversación: estado de lead (nuevo →
 * cotizando → apartado → ganado/perdido), resumen rodante que el bot usa
 * cuando el huésped regresa, y control de follow-ups enviados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('lead_status', 20)->default('new')->index()->after('status');
            $table->text('summary')->nullable()->after('lead_status');
            $table->unsignedBigInteger('summary_message_id')->nullable()->after('summary');
            $table->json('followups')->nullable()->after('summary_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['lead_status', 'summary', 'summary_message_id', 'followups']);
        });
    }
};
