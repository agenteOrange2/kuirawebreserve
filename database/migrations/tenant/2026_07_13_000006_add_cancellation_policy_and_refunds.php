<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4 (spec-pagos §6.6 + spec-pendientes §2.6): política de cancelación con
 * dinero por tarifa, y el libro de reembolsos. Un reembolso NUNCA edita el
 * pago original (payments sigue append-only): es una fila propia ligada a él.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            // "Cancelación sin costo hasta N unidades antes de la llegada".
            // Sin unidad/valor = sin política (decisión 100% humana, como hoy).
            $table->string('cancel_free_unit')->nullable()->after('payment_due_value'); // hour|day|week|month
            $table->unsignedInteger('cancel_free_value')->nullable()->after('cancel_free_unit');
            // % de lo pagado que se RETIENE al cancelar fuera de la ventana
            // (null = se retiene todo, el caso hotelero típico).
            $table->decimal('cancel_penalty_percent', 5, 2)->nullable()->after('cancel_free_value');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete(); // reporteo directo
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('completed'); // completed | failed
            $table->string('gateway')->nullable(); // stripe | mercadopago | paypal (null = manual)
            $table->string('gateway_ref')->nullable(); // id del refund en el proveedor
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('refunded_at');
            $table->timestamps();

            $table->index(['reservation_id', 'refunded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');

        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn(['cancel_free_unit', 'cancel_free_value', 'cancel_penalty_percent']);
        });
    }
};
