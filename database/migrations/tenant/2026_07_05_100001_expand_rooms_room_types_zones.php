<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Iteración B (spec-modulos-profundidad §2 y §3): habitaciones, tipos y
 * zonas dejan de ser esqueleto — campos comerciales y operativos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('name')->nullable()->after('number');
            $table->text('description')->nullable()->after('name');
            // [{"type":"king","qty":1},{"type":"individual","qty":2}]
            $table->json('beds')->nullable()->after('description');
            $table->unsignedSmallInteger('max_occupancy')->nullable()->after('beds');
            $table->decimal('size_m2', 6, 2)->nullable()->after('max_occupancy');
            $table->string('view', 100)->nullable()->after('size_m2');
            // Extra/override sobre las amenities del tipo.
            $table->json('amenities')->nullable()->after('view');
            $table->boolean('smoking')->default(false)->after('amenities');
            $table->boolean('accessible')->default(false)->after('smoking');
            // Ajuste por unidad sobre la tarifa del tipo (+100 vista al mar).
            $table->decimal('price_modifier', 10, 2)->nullable()->after('accessible');
            $table->text('maintenance_notes')->nullable()->after('notes');
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->unsignedSmallInteger('max_adults')->nullable()->after('capacity');
            $table->unsignedSmallInteger('max_children')->nullable()->after('max_adults');
            $table->time('check_in_time')->nullable()->after('base_price');
            $table->time('check_out_time')->nullable()->after('check_in_time');
            $table->unsignedInteger('sort_order')->default(0)->after('amenities');
            $table->boolean('active')->default(true)->after('sort_order');
        });

        Schema::table('zones', function (Blueprint $table) {
            $table->string('kind', 20)->default('area')->after('name'); // piso | edificio | area
            $table->string('color', 20)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn([
                'name', 'description', 'beds', 'max_occupancy', 'size_m2',
                'view', 'amenities', 'smoking', 'accessible', 'price_modifier',
                'maintenance_notes',
            ]);
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn([
                'description', 'max_adults', 'max_children',
                'check_in_time', 'check_out_time', 'sort_order', 'active',
            ]);
        });

        Schema::table('zones', function (Blueprint $table) {
            $table->dropColumn(['kind', 'color']);
        });
    }
};
