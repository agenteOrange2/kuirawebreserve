<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los planes anteriores al escalonado comercial (basic, pro, premium y
 * cualquier otro creado a mano) dejan de ofrecerse a hoteles nuevos:
 * `active = false` los quita del landing y del alta, SIN tocar a los
 * hoteles que ya viven en ellos (planLimits resuelve por key, no por
 * active). La oferta pública queda en Esencial / Profesional / Empresarial.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')
            ->whereNotIn('key', ['esencial', 'profesional', 'empresarial'])
            ->update(['active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // No se revierte en bloque: reactivar un plan es decisión del admin
        // en /admin/plans.
    }
};
