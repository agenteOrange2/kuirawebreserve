<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // El endpoint identifica al navegador+dispositivo: es la llave
            // real. Un mismo usuario puede tener varios (celular, la compu
            // de recepción) y todos deben recibir.
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();

            // Llaves de cifrado que entrega el navegador al suscribirse.
            $table->string('public_key');
            $table->string('auth_token');

            // Para que el usuario reconozca sus dispositivos al desconectarlos.
            $table->string('device')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
