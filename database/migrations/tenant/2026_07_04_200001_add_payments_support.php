<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cobro anticipado por tarifa (spec-profundidad §2.6.3): % de
        // anticipo y ventana para liquidar el total antes de la llegada.
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->decimal('deposit_percent', 5, 2)->nullable()->after('price');
            $table->string('payment_due_unit')->nullable()->after('min_advance_value'); // hour|day|week
            $table->unsignedInteger('payment_due_value')->nullable()->after('payment_due_unit');
        });

        Schema::table('reservations', function (Blueprint $table) {
            // unpaid|deposit_paid|paid — derivado de la suma de pagos.
            $table->string('payment_status')->default('unpaid')->after('deposit_amount');
            $table->dateTime('payment_due_at')->nullable()->after('payment_status');
        });

        // Registro de abonos (spec §7.5). La pasarela real es fase 7.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method'); // cash|card|transfer
            $table->string('reference')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at');
            $table->timestamp('created_at');

            $table->index(['reservation_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'payment_due_at']);
        });

        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn(['deposit_percent', 'payment_due_unit', 'payment_due_value']);
        });
    }
};
