<?php

namespace Database\Seeders;

use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Datos de ejemplo para desarrollo. Correr manualmente dentro de un tenant:
 * php artisan tenants:seed --class="Database\Seeders\TenantDemoSeeder" --tenants=demo
 */
class TenantDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(TenantRolesSeeder::class);

        $owner = User::firstOrCreate(
            ['email' => 'owner@demo.test'],
            ['name' => 'Dueño Demo', 'password' => Hash::make('password')],
        );
        $owner->assignRole('owner');

        $property = Property::firstOrCreate(
            ['name' => 'Hotel Demo Centro'],
            ['timezone' => 'America/Mexico_City', 'address' => 'Av. Principal 123'],
        );

        $planta = $property->zones()->firstOrCreate(['name' => 'Planta baja'], ['sort_order' => 0]);
        $planta->fill(['kind' => 'piso', 'color' => '#0ea5e9'])->save();
        $piso1 = $property->zones()->firstOrCreate(['name' => 'Piso 1'], ['sort_order' => 1]);
        $piso1->fill(['kind' => 'piso', 'color' => '#8b5cf6'])->save();

        $sencilla = $property->roomTypes()->firstOrCreate(
            ['name' => 'Sencilla'],
            ['capacity' => 2, 'base_price' => 650, 'amenities' => ['tv', 'wifi', 'ac']],
        );
        $sencilla->fill([
            'description' => 'Habitación acogedora con cama matrimonial, ideal para viajeros de paso.',
            'max_adults' => 2,
            'max_children' => 1,
            'check_in_time' => '15:00',
            'check_out_time' => '12:00',
            'sort_order' => 0,
        ])->save();
        $suite = $property->roomTypes()->firstOrCreate(
            ['name' => 'Suite'],
            ['capacity' => 4, 'base_price' => 1400, 'amenities' => ['tv', 'wifi', 'ac', 'minibar', 'jacuzzi']],
        );
        $suite->fill([
            'description' => 'Suite amplia con sala, jacuzzi y minibar. Perfecta para estancias largas o familias.',
            'max_adults' => 3,
            'max_children' => 2,
            'check_in_time' => '15:00',
            'check_out_time' => '12:00',
            'sort_order' => 1,
        ])->save();

        // Tarifas: por noche (hotel) y por bloque/rato (motel).
        $property->hasMany(\App\Models\RatePlan::class)->firstOrCreate(
            ['room_type_id' => $sencilla->id, 'name' => 'Noche sencilla'],
            ['type' => 'night', 'price' => 650],
        );
        $property->hasMany(\App\Models\RatePlan::class)->firstOrCreate(
            ['room_type_id' => $suite->id, 'name' => 'Noche suite'],
            ['type' => 'night', 'price' => 1400],
        );
        $property->hasMany(\App\Models\RatePlan::class)->firstOrCreate(
            ['room_type_id' => $sencilla->id, 'name' => 'Rato 3 horas'],
            ['type' => 'block', 'duration_minutes' => 180, 'price' => 250],
        );

        if (Room::count() === 0) {
            foreach (range(101, 106) as $i => $number) {
                Room::create([
                    'property_id' => $property->id,
                    'zone_id' => $planta->id,
                    'room_type_id' => $sencilla->id,
                    'number' => (string) $number,
                    'pos_x' => 40 + ($i % 3) * 160,
                    'pos_y' => 40 + intdiv($i, 3) * 120,
                ]);
            }

            foreach (range(201, 204) as $i => $number) {
                Room::create([
                    'property_id' => $property->id,
                    'zone_id' => $piso1->id,
                    'room_type_id' => $suite->id,
                    'number' => (string) $number,
                    'pos_x' => 40 + ($i % 2) * 200,
                    'pos_y' => 320 + intdiv($i, 2) * 140,
                ]);
            }
        }

        // Ficha de ejemplo (spec-profundidad §2.1) — solo rellena habitaciones
        // que siguen sin camas capturadas, sin pisar ediciones del panel.
        Room::query()->whereNull('beds')->where('number', 'like', '1%')->get()
            ->each(fn (Room $room) => $room->update([
                'beds' => [['type' => 'matrimonial', 'qty' => 1]],
                'size_m2' => 18,
                'view' => 'interior',
            ]));
        Room::query()->whereNull('beds')->where('number', 'like', '2%')->get()
            ->each(fn (Room $room) => $room->update([
                'beds' => [['type' => 'king', 'qty' => 1], ['type' => 'sofa_cama', 'qty' => 1]],
                'size_m2' => 34,
                'view' => 'jardín',
                'accessible' => false,
            ]));
        Room::query()->where('number', '201')->whereNull('name')->update([
            'name' => 'Suite Jardín',
            'price_modifier' => 100,
            'view' => 'jardín',
        ]);
    }
}
