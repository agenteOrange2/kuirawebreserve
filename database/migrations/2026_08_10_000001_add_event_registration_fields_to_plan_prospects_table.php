<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('plan_prospects', function (Blueprint $table) {
            // Los registros de evento no eligen plan.
            $table->string('plan_label', 60)->nullable()->change();
        });

        Schema::table('plan_prospects', function (Blueprint $table) {
            $table->boolean('has_whatsapp')->default(false)->after('phone');
            $table->json('services')->nullable()->after('message');
            $table->timestamp('docs_email_sent_at')->nullable()->after('contacted_at');
            $table->timestamp('docs_whatsapp_sent_at')->nullable()->after('docs_email_sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plan_prospects', function (Blueprint $table) {
            $table->dropColumn(['has_whatsapp', 'services', 'docs_email_sent_at', 'docs_whatsapp_sent_at']);
        });

        // plan_label se queda nullable: revertir a NOT NULL fallaría con filas de evento.
    }
};
