<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Folio de estancia: los pagos pueden colgar de una estancia (walk-in
        // sin reserva, o liquidación de consumos) además de una reserva.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('reservation_id')->nullable()->change();
            $table->foreignId('stay_id')->nullable()->after('reservation_id')
                ->constrained()->cascadeOnDelete();
            // lodging = hospedaje · consumption = consumos POS cargados a la
            // habitación. Null en abonos normales de reserva.
            $table->string('kind')->nullable()->after('method');

            $table->index(['stay_id', 'paid_at']);
        });

        // Consumos cargados a habitación: quedan liquidados en el check-out.
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('settled_at')->nullable()->after('payment_method');
            $table->foreignId('settled_by')->nullable()->after('settled_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settled_by');
            $table->dropColumn('settled_at');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['stay_id', 'paid_at']);
            $table->dropConstrainedForeignId('stay_id');
            $table->dropColumn('kind');
            $table->foreignId('reservation_id')->nullable(false)->change();
        });
    }
};
