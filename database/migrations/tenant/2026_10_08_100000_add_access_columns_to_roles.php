<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles editables por hotel (2026-10-08): el propietario y la plataforma
 * deciden qué áreas del panel ve cada rol y pueden crear roles propios.
 * `hidden_areas` guarda lo que el rol NO ve (null = todo lo que su permiso
 * abre), así ningún rol existente pierde nada al migrar. `label` y
 * `description` pisan el texto de fábrica de StaffAccess::SYSTEM_ROLES.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('label', 60)->nullable()->after('name');
            $table->string('description', 200)->nullable()->after('label');
            $table->json('hidden_areas')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['label', 'description', 'hidden_areas']);
        });
    }
};
