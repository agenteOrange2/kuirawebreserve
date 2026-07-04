<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('code')->nullable()->after('guest_id');
        });

        DB::table('reservations')
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->get()
            ->each(function (object $reservation): void {
                $year = $reservation->created_at
                    ? date('Y', strtotime((string) $reservation->created_at))
                    : date('Y');

                DB::table('reservations')
                    ->where('id', $reservation->id)
                    ->update([
                        'code' => sprintf('RES-%s-%04d', $year, $reservation->id),
                    ]);
            });

        Schema::table('reservations', function (Blueprint $table) {
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
