<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de planes en la DB CENTRAL: el super-admin los administra desde
 * /admin/planes. AppServiceProvider hidrata config('plans') desde aquí, así
 * todo el código que lee config sigue funcionando sin cambios. Se siembra
 * desde el config de archivo para que nada se rompa al migrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->string('key', 40)->primary();
            $table->string('label');
            $table->unsignedInteger('price_monthly')->default(0); // informativo (MXN); el cobro es fase posterior
            $table->unsignedInteger('max_properties')->nullable(); // null = sin límite
            $table->unsignedInteger('max_rooms')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->boolean('ai_enabled')->default(false);
            $table->unsignedInteger('ai_monthly_replies')->nullable(); // null = sin límite
            $table->boolean('active')->default(true); // inactivo = no se ofrece a hoteles nuevos
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $i = 0;
        foreach (config('plans') as $key => $plan) {
            DB::table('plans')->insert([
                'key' => $key,
                'label' => $plan['label'],
                'price_monthly' => $plan['price_monthly'] ?? 0,
                'max_properties' => $plan['max_properties'] ?? null,
                'max_rooms' => $plan['max_rooms'] ?? null,
                'max_users' => $plan['max_users'] ?? null,
                'ai_enabled' => (bool) ($plan['ai']['enabled'] ?? false),
                'ai_monthly_replies' => $plan['ai']['monthly_replies'] ?? null,
                'active' => true,
                'sort_order' => $i++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
