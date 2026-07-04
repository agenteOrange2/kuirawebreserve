<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Iteración C (spec-modulos-profundidad §4): vehículo (clave en moteles),
 * ETA, adultos/niños en vez de num_people plano, notas del huésped separadas
 * de las internas y motivo de cancelación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedSmallInteger('adults')->default(1)->after('num_people');
            $table->unsignedSmallInteger('children')->default(0)->after('adults');
            $table->string('vehicle_plate', 20)->nullable()->after('children');
            $table->string('vehicle_desc', 100)->nullable()->after('vehicle_plate');
            $table->time('eta')->nullable()->after('vehicle_desc');
            $table->text('guest_notes')->nullable()->after('notes');
            $table->string('cancellation_reason')->nullable()->after('guest_notes');
        });

        // Las reservas existentes traían todo en num_people: se asume adultos.
        DB::table('reservations')->update(['adults' => DB::raw('num_people')]);

        Schema::table('stays', function (Blueprint $table) {
            $table->string('vehicle_plate', 20)->nullable()->after('num_people');
            $table->string('vehicle_desc', 100)->nullable()->after('vehicle_plate');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn([
                'adults', 'children', 'vehicle_plate', 'vehicle_desc',
                'eta', 'guest_notes', 'cancellation_reason',
            ]);
        });

        Schema::table('stays', function (Blueprint $table) {
            $table->dropColumn(['vehicle_plate', 'vehicle_desc']);
        });
    }
};
