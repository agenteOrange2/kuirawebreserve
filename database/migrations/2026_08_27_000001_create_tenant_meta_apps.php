<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App de Meta PROPIA por hotel (DB CENTRAL). Separa a los tenants en apps
 * distintas: cada uno con su app_id/clave, su clave de Instagram Login y su
 * configuración de registro incrustado. Sin fila, el hotel usa la app de la
 * plataforma (config/meta.php) — el respaldo mantiene vivos los canales
 * existentes mientras cada hotel migra a su propia app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_meta_apps', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique();
            $table->string('app_id', 50);
            $table->text('app_secret'); // cifrado (cast encrypted)
            $table->text('ig_app_secret')->nullable(); // cifrado; app anidada de Instagram Login
            $table->string('login_config_id', 50)->nullable(); // Embedded Signup
            $table->string('name')->nullable(); // etiqueta: "App Real de la Sierra"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_meta_apps');
    }
};
