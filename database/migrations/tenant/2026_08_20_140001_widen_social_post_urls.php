<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las URLs de Facebook no caben en varchar(255): un `full_picture` del CDN
 * ronda los 700 caracteres (token de firma, gid, tpa, oe...) y el permalink
 * con `substory_index` también se estira. La primera sincronización real de
 * motellacupula murió con "Data too long for column 'media_url'".
 *
 * Se guardan como texto porque su largo lo decide Meta, no nosotros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->text('permalink')->nullable()->change();
            $table->text('media_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('permalink')->nullable()->change();
            $table->string('media_url')->nullable()->change();
        });
    }
};
