<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_cuts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            // Encargado al que corresponde el corte.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Periodo contabilizado.
            $table->dateTime('opened_at');
            $table->dateTime('closed_at');

            // Ventas POS.
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('orders_total', 12, 2)->default(0);
            $table->decimal('orders_cost', 12, 2)->default(0);

            // Cobros de reservas (abonos).
            $table->unsignedInteger('payments_count')->default(0);
            $table->decimal('payments_total', 12, 2)->default(0);

            // Desglose por método (POS + cobros).
            $table->decimal('cash_total', 12, 2)->default(0);
            $table->decimal('card_total', 12, 2)->default(0);
            $table->decimal('transfer_total', 12, 2)->default(0);

            // Gran total contabilizado.
            $table->decimal('grand_total', 12, 2)->default(0);

            // Arqueo de efectivo.
            $table->decimal('expected_cash', 12, 2)->default(0);
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->default(0);

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'user_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_cuts');
    }
};
