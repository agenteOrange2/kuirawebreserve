<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Vista previa del último mensaje, denormalizada: la lista de la
            // bandeja la pide para las 100 conversaciones y consultarla por
            // fila costaba una query por conversación en cada refresco.
            // La mantiene al día el hook created de Message.
            $table->string('last_message_preview')->nullable()->after('last_message_at');
        });

        // Relleno inicial: el cuerpo del último mensaje de cada conversación.
        // substr() se comporta igual en MySQL (producción) y SQLite (tests).
        DB::table('conversations')->update([
            'last_message_preview' => DB::raw(
                '(SELECT substr(m.body, 1, 255) FROM messages m'
                .' WHERE m.conversation_id = conversations.id'
                .' ORDER BY m.id DESC LIMIT 1)'
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('last_message_preview');
        });
    }
};
