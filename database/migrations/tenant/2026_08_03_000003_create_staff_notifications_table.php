<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_notifications', function (Blueprint $table) {
            $table->id();

            // Qué pasó: message | reservation | payment | ... Sirve para el
            // icono y el color, y para deduplicar.
            $table->string('type', 40);
            $table->string('title');
            $table->string('body')->nullable();
            // A dónde lleva al picarle (ruta del panel).
            $table->string('url')->nullable();

            // De qué objeto habla, para no repetir el mismo aviso.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // Sin destinatario = para todo el staff. Se deja el campo listo
            // para cuando haya avisos dirigidos (ej. "tu turno cierra").
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // La campana pide "las últimas sin leer": este es su índice.
            $table->index(['read_at', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_notifications');
    }
};
