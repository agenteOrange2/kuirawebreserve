<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sello de idempotencia del agradecimiento post-estancia: cada estancia
 * recibe UN solo mensaje de gracias al completarse (check-out manual o
 * automático), aunque el flujo se repita.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('stays', 'thanks_sent_at')) {
            return;
        }

        Schema::table('stays', function (Blueprint $table) {
            $table->timestamp('thanks_sent_at')->nullable()->after('check_out_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('stays', 'thanks_sent_at')) {
            return;
        }

        Schema::table('stays', function (Blueprint $table) {
            $table->dropColumn('thanks_sent_at');
        });
    }
};
