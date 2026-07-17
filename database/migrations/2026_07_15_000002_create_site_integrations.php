<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens de integración de sitios (spec-integracion-sitios §2, patrón
 * *_links): un token por sitio conectado (WordPress, Laravel a medida).
 * El token JAMÁS se guarda en claro: solo su sha256 y un prefijo para
 * identificarlo en la UI. La API pública de catálogo lo exige y el área
 * completa vive detrás del módulo motor-web.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('label');
            $table->string('token_hash', 64)->unique();
            $table->string('token_prefix', 12);
            $table->json('domains')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_integrations');
    }
};
