<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pasarelas de pago conectadas por hotel (spec-pagos §4.4): cuentas PROPIAS
 * del tenant (sus llaves API). Tabla central porque los webhooks del
 * proveedor llegan al dominio central y hay que resolver el tenant por
 * webhook_token sin levantar N tenants (patrón evolution_channel_links).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_links', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('provider'); // stripe | mercadopago
            $table->string('mode')->default('test'); // test | live
            $table->string('public_key')->nullable(); // publishable key / public key
            $table->text('secret_key'); // cifrada (cast encrypted)
            $table->text('webhook_secret')->nullable(); // signing secret (Stripe), cifrada
            $table->string('webhook_token', 64)->unique();
            $table->boolean('active')->default(true);
            $table->dateTime('last_event_at')->nullable(); // latido del webhook
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_links');
    }
};
