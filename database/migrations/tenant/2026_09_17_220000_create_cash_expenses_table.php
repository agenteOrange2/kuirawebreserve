<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Salidas de efectivo de la caja: gasolina, insumos, un retiro a
     * bóveda. Hasta ahora el arqueo solo sabía de dinero ENTRANDO (fondo,
     * ventas, fianzas), así que todo lo que salía del cajón aparecía como
     * faltante del encargado.
     */
    public function up(): void
    {
        Schema::create('cash_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            // De quién es la caja: el gasto pesa en SU corte, lo capture quien lo capture.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // El turno en que salió el dinero. Igual que los pagos: con turno
            // el corte no tiene que adivinar el periodo por el reloj.
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            // Recepción o punto de venta: son dos cajones distintos.
            $table->string('scope', 16)->default('rooms');
            $table->string('category', 32)->default('otro');
            $table->string('concept', 160);
            $table->decimal('amount', 12, 2);
            $table->timestamp('occurred_at');
            // Se liga al cerrar el corte: después de eso el gasto ya no se
            // borra ni se edita, queda como respaldo de ese arqueo.
            $table->foreignId('cash_cut_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'scope', 'occurred_at']);
            $table->index('shift_id');
            $table->index('cash_cut_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_expenses');
    }
};
