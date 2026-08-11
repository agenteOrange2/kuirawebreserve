<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identificación del huésped a pie en el registro exprés de caseta
 * (spec-modo-motel): tipo + número del documento capturados en la llegada.
 * El número va cifrado (cast 'encrypted' en Stay), por eso es text — el
 * ciphertext crece. Vive en la estancia (dato operativo: quién está en el
 * cuarto ahora), no en Guest: un Guest sin nombre ni teléfono solo
 * ensuciaría el CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->string('id_document_type', 20)->nullable()->after('vehicle_desc');
            $table->text('id_document_number')->nullable()->after('id_document_type');
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->dropColumn(['id_document_type', 'id_document_number']);
        });
    }
};
