<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos del panel de plataforma (BD central): lo que pasa en los hoteles y
 * necesita a un administrador — cuota de IA por agotarse, registros nuevos,
 * canales mudos, hoteles sin dueño... Los arma PlatformAlertScanner; cada
 * condición es un renglón con llave estable, así el estado (leído,
 * pospuesto, descartado) sobrevive entre revisiones y el aviso se resuelve
 * solo cuando la condición desaparece.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_alerts', function (Blueprint $table) {
            $table->id();
            // Llave estable de la condición: ai_quota:hotelmexico:2026-10.
            $table->string('key', 160)->unique();
            $table->string('type', 40);
            // danger | warning | info
            $table->string('severity', 10);
            $table->string('tenant_id')->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamps();

            $table->index(['resolved_at', 'dismissed_at', 'severity']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_alerts');
    }
};
