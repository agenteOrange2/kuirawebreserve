<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tarifas que solo se venden en recepción (la de 1 hora de Hotel
     * México): el asistente y el wizard no las ven. Default true para que
     * las tarifas existentes sigan igual.
     */
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->boolean('online')->default(true)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn('online');
        });
    }
};
