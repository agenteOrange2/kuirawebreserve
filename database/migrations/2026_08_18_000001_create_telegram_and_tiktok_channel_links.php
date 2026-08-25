<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram (Bot API) y TikTok (Business Messaging) como canales de la
 * bandeja: cada fila vincula un bot/cuenta a un hotel. Igual que Evolution,
 * el webhook central enruta por webhook_token → tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_channel_links', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('name')->nullable();
            // Parte numérica del token del bot (antes de los dos puntos):
            // permite el unique sin desencriptar el token completo.
            $table->string('bot_id', 32)->unique();
            $table->text('bot_token'); // cifrado (cast encrypted)
            $table->string('bot_username')->nullable();
            $table->string('webhook_token', 64)->unique();
            $table->boolean('active')->default(true);
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tiktok_channel_links', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('name')->nullable();
            // Id de la cuenta business/creator de TikTok (open_id o
            // business_id según la app aprobada).
            $table->string('business_id')->unique();
            $table->text('access_token'); // cifrado (cast encrypted)
            $table->string('webhook_token', 64)->unique();
            $table->boolean('active')->default(true);
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_channel_links');
        Schema::dropIfExists('tiktok_channel_links');
    }
};
