<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora del panel de plataforma (BD central): qué hizo cada
 * administrador — cambios en hoteles, planes, canales, "Entrar como" y sus
 * accesos. El activity_log de spatie vive en cada tenant y no ve /admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Nombre de ruta (admin.tenants.update) o evento de acceso (auth.login).
            $table->string('action', 80);
            // Sobre qué cayó: hotel, usuario, plan... con el nombre de ese
            // momento, para que la bitácora se lea aunque luego se borre.
            $table->string('subject_type', 40)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('subject_label')->nullable();
            // Hotel al que pertenece (aunque el sujeto sea un canal suyo).
            $table->string('tenant_id')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activities');
    }
};
