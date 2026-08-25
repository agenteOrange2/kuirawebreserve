<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo "IA en redes sociales" (docs/spec-pendientes-y-agentes.md §4.6):
 * las publicaciones de la página y sus comentarios viven aquí para que el
 * asistente los conteste y el embudo quede medible (post → comentario → DM
 * → reserva).
 *
 * `social_posts` no es un espejo completo de la red: guarda lo mínimo para
 * agrupar comentarios y saber a qué cuenta responder (`account_external_id`
 * resuelve el MetaChannelLink central en el momento del envío).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->string('network', 20); // fb | ig | tiktok
            $table->string('external_id'); // id del post (FB) o del media (IG)
            // Página de Facebook o cuenta de Instagram dueña de la
            // publicación: con esto se ubica el canal para responder.
            $table->string('account_external_id')->nullable();
            $table->text('message')->nullable();
            $table->string('permalink')->nullable();
            $table->string('media_url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('comments_count')->default(0);
            $table->json('stats')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['network', 'external_id']);
            $table->index(['network', 'published_at']);
        });

        Schema::create('social_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            // Llave de idempotencia: Meta reintenta los webhooks y sin esto
            // el mismo comentario se contestaría dos veces en público.
            $table->string('external_id')->unique();
            $table->string('parent_external_id')->nullable();
            $table->string('author_external_id')->nullable(); // PSID / IGSID
            $table->string('author_name')->nullable();
            $table->text('body')->nullable();

            // compra | pregunta | queja | elogio | spam (null = sin clasificar)
            $table->string('classification', 20)->nullable();
            $table->json('classification_meta')->nullable(); // proveedor, modelo, tokens, motivo
            // nuevo | respondido | pendiente_staff | oculto | ignorado
            $table->string('status', 20)->default('nuevo');

            $table->text('public_reply_text')->nullable();
            $table->string('public_reply_external_id')->nullable();
            $table->timestamp('public_replied_at')->nullable();

            $table->timestamp('private_reply_sent_at')->nullable();
            $table->string('private_reply_error')->nullable();

            // Comment-to-conversation: el DM abre (o retoma) la conversación
            // de la bandeja y ahí sigue el bot de siempre.
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('commented_at')->nullable();

            // Moderación auditada: ocultar nunca borra y siempre es reversible.
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hidden_reason')->nullable();

            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            // El autor borró su comentario en la red: se conserva la fila.
            $table->timestamp('deleted_from_network_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('classification');
            $table->index(['social_post_id', 'commented_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_comments');
        Schema::dropIfExists('social_posts');
    }
};
