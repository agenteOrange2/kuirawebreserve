<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo "Lista de espera" (lista-espera): cuando el wizard público no
 * encuentra disponibilidad, el interesado deja su contacto y, si una
 * cancelación o no-show libera fechas que se solapan con las suyas, se le
 * avisa solo (TransitionReservation::cancel → WaitlistNotifier).
 *
 * room_type_id null = le sirve cualquier tipo (el wizard captura desde la
 * búsqueda general, sin tipo elegido).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->nullable()->constrained()->nullOnDelete();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->string('guest_name');
            $table->string('guest_phone', 30)->nullable();
            $table->string('guest_email')->nullable();
            // waiting | notified | converted | expired
            $table->string('status', 20)->default('waiting');
            $table->dateTime('notified_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
