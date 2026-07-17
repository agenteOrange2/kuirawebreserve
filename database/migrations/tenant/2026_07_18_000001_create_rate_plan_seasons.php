<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Temporadas y promos por tarifa (spec-motor-reservas-web E0.5,
 * spec-pendientes-y-agentes §2.5): un rango de fechas con un precio que
 * SUSTITUYE al de la tarifa mientras esté vigente. `priority` resuelve
 * solapes (gana la más alta); `kind` es solo etiqueta para la UI
 * (temporada vs promoción), el mecanismo es el mismo para ambas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_plan_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind')->default('season'); // season | promo
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['rate_plan_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_plan_seasons');
    }
};
