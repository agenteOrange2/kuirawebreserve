<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Base de módulos por plan (spec-plan-maestro E1):
 *
 * - `plans.modules` (JSON): qué módulos incluye cada plan. Backfill según lo
 *   que cada plan ya otorgaba: POS lo veían todos; cobros si tenía pasarelas
 *   (max_gateways > 0); agente-ia si ai_enabled.
 * - `tenant_modules`: override por hotel (patrón payment_method_settings).
 *   Sin fila = hereda del plan; con fila = el admin forzó on/off.
 * - `module_activation_requests`: solicitudes del tenant desde "Tu plan"
 *   (/ajustes). La fila ES la solicitud pendiente; se borra al atenderla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('modules')->nullable()->after('max_gateways');
        });

        foreach (DB::table('plans')->get() as $plan) {
            $modules = ['pos'];
            if ((int) ($plan->max_gateways ?? 0) !== 0) {
                $modules[] = 'cobros';
            }
            if ($plan->ai_enabled) {
                $modules[] = 'agente-ia';
            }

            DB::table('plans')->where('key', $plan->key)->update([
                'modules' => json_encode($modules),
            ]);
        }

        Schema::create('tenant_modules', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('module', 40);
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['tenant_id', 'module']);
        });

        Schema::create('module_activation_requests', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('module', 40);
            $table->timestamps();

            $table->unique(['tenant_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_activation_requests');
        Schema::dropIfExists('tenant_modules');
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('modules');
        });
    }
};
