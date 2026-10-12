<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Módulo cortesías (2026-10-09, pedido de Hotel México): hospedaje gratis o
 * con descuento a un cliente importante o conocido. El propietario la da
 * directo; el resto del personal la solicita y solo un propietario la
 * aprueba.
 *
 * `courtesy_amount` vive junto a `discount_amount` (el del cupón) y no
 * dentro de él: UpdateReservation rearma el total desde cero y borraba un
 * descuento sin cupón. Con su propia columna el total siempre se puede
 * rearmar como bruto − cupón − cortesía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courtesies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->nullable()->constrained()->cascadeOnDelete();
            // Solo walk-in sin reserva: con reserva, la cortesía va en ella.
            $table->foreignId('stay_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->string('kind', 10); // full | percent | amount
            $table->decimal('value', 10, 2)->default(0);
            $table->decimal('lodging_base', 10, 2)->default(0);
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('category', 30);
            $table->string('reason', 255);
            $table->string('status', 12)->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->index();
            $table->string('decision_note', 255)->nullable();
            $table->boolean('over_cap')->default(false);
            $table->timestamps();
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->decimal('courtesy_amount', 10, 2)->default(0)->after('discount_amount');
        });

        Schema::table('stays', function (Blueprint $table) {
            $table->decimal('courtesy_amount', 10, 2)->default(0)->after('amount');
        });

        // El seeder de roles solo corre al crear un hotel: el permiso nuevo
        // se reparte aquí a los roles de fábrica que ya existen.
        if (Schema::hasTable('permissions')) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $permission = Permission::findOrCreate('courtesies.request');

            foreach (['owner', 'manager', 'front-desk'] as $name) {
                $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
                $role?->givePermissionTo($permission);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('courtesies');

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('courtesy_amount');
        });

        Schema::table('stays', function (Blueprint $table) {
            $table->dropColumn('courtesy_amount');
        });

        DB::table('permissions')->where('name', 'courtesies.request')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
