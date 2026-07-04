<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->date('birth_date')->nullable();
            $table->string('nationality')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip', 10)->nullable();
            $table->string('id_document_type')->nullable(); // ine|pasaporte|licencia|otro
            $table->text('id_document_number')->nullable(); // cast encrypted
            $table->text('notes')->nullable();
            $table->boolean('is_blacklisted')->default(false);
            $table->string('blacklist_reason')->nullable();
            $table->boolean('marketing_consent')->default(false);
        });

        // Datos existentes: name → first_name.
        DB::table('guests')->whereNotNull('name')->update([
            'first_name' => DB::raw('name'),
        ]);

        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
        });

        DB::table('guests')->update(['name' => DB::raw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')))")]);

        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn([
                'first_name', 'last_name', 'birth_date', 'nationality', 'address',
                'city', 'state', 'zip', 'id_document_type', 'id_document_number',
                'notes', 'is_blacklisted', 'blacklist_reason', 'marketing_consent',
            ]);
        });
    }
};
