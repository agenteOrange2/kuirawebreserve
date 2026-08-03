<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo "Cupones" (cupones): códigos de descuento que el huésped aplica
 * en el wizard público. El descuento se congela en la reserva
 * (coupon_code + discount_amount) al crear el hold — el catálogo puede
 * cambiar después sin alterar lo ya vendido — y used_count se incrementa
 * recién cuando la reserva se CONFIRMA (no en el hold, que puede expirar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            // percent (value = %) | amount (value = $ fijo)
            $table->string('kind', 20)->default('percent');
            $table->decimal('value', 10, 2);
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->string('coupon_code', 40)->nullable()->after('deposit_amount');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('coupon_code');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['coupon_code', 'discount_amount']);
        });

        Schema::dropIfExists('coupons');
    }
};
