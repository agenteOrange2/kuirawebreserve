<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Turno al que pertenece la venta. Hasta ahora el corte se
            // calculaba por usuario + rango de fechas y el enlace con el
            // turno se INFERÍA por hora de cierre: si alguien cerraba tarde
            // o se compartía usuario, el corte se desalineaba.
            $table->foreignId('shift_id')->nullable()->after('stay_id')
                ->constrained()->nullOnDelete();

            // subtotal = suma de las líneas. total = subtotal - descuento +
            // propina, que es lo que de verdad se cobra (y lo que suman el
            // corte de caja y el folio de la habitación).
            $table->decimal('subtotal', 10, 2)->default(0)->after('total_cost');
            $table->decimal('discount', 10, 2)->default(0)->after('subtotal');
            $table->string('discount_reason')->nullable()->after('discount');
            $table->decimal('tip', 10, 2)->default(0)->after('discount_reason');

            // Autorización de tarjeta o folio de transferencia: en reservas
            // ya se pedía referencia, en el POS no.
            $table->string('payment_reference')->nullable()->after('payment_method');

            // Cancelación de venta (status void, que ya existía sin usarse).
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
        });

        // Las ventas ya registradas no tenían descuento ni propina: su
        // subtotal es su total.
        DB::table('orders')->update(['subtotal' => DB::raw('total')]);

        Schema::table('payments', function (Blueprint $table) {
            // Mismo motivo que en orders: el cobro sabe de qué turno es.
            $table->foreignId('shift_id')->nullable()->after('received_by')
                ->constrained()->nullOnDelete();
        });

        Schema::table('cash_cuts', function (Blueprint $table) {
            // Corte de un turno concreto: deja de depender de acertarle al
            // rango de fechas.
            $table->foreignId('shift_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_cuts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn([
                'subtotal', 'discount', 'discount_reason', 'tip',
                'payment_reference', 'voided_at', 'void_reason',
            ]);
        });
    }
};
