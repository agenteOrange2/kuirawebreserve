<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Solicitudes de cobro (spec-pagos §4.1): el puente entre "apartado
        // creado" y "dinero confirmado". Una fila por intento de cobro; solo
        // una activa por concepto. F0 = transferencias; las pasarelas (F1)
        // reutilizan esta misma tabla vía provider/gateway_ref/checkout_url.
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // identificador público (links, webhooks, bot)
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('concept'); // deposit | balance | full | custom
            $table->decimal('amount', 10, 2); // calculado server-side al emitir
            $table->char('currency', 3)->default('MXN');
            $table->string('method'); // gateway | transfer
            $table->string('provider')->nullable(); // stripe | mercadopago | paypal (F1)
            $table->string('mode')->default('live'); // test | live
            $table->string('status')->default('pending'); // pending | paid | expired | canceled | rejected
            $table->string('checkout_url')->nullable(); // checkout hospedado (F1)
            $table->string('gateway_ref')->nullable(); // id externo del proveedor (F1)
            $table->dateTime('expires_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete(); // null = bot
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete(); // el pago que la cerró
            $table->json('meta')->nullable(); // comprobante, motivo de rechazo, anomalías
            $table->timestamps();

            $table->index(['reservation_id', 'status']);
            $table->unique(['provider', 'gateway_ref']);
        });

        // El libro contable gana trazabilidad de origen (spec-pagos §4.2);
        // sigue siendo append-only y las columnas son nullables: los pagos de
        // mostrador no cambian.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('payment_request_id')->nullable()->after('stay_id')
                ->constrained('payment_requests')->nullOnDelete();
            $table->string('gateway')->nullable()->after('method'); // stripe | mercadopago | paypal (F1)
            $table->string('gateway_ref')->nullable()->after('gateway');
            $table->decimal('fee_amount', 10, 2)->nullable()->after('amount'); // comisión reportada (conciliación)
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_request_id');
            $table->dropColumn(['gateway', 'gateway_ref', 'fee_amount']);
        });

        Schema::dropIfExists('payment_requests');
    }
};
