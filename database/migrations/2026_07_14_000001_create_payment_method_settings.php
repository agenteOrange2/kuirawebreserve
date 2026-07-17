<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Habilitación de métodos de pago en dos niveles (spec-pagos §12, patrón
 * Agentes IA): fila con tenant_id NULL = interruptor GLOBAL de plataforma;
 * fila con tenant_id = override por hotel. Sin fila = habilitado. Un método
 * apagado a nivel plataforma desaparece para todos los hoteles aunque su
 * override diga lo contrario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_method_settings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index();
            $table->string('method', 30); // transfer | stripe | mercadopago
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_method_settings');
    }
};
