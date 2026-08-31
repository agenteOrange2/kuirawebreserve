<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\Stay;
use App\Models\Vehicle;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Único escritor del registro de vehículos (docs/spec-modo-motel.md).
 *
 * Existe para que no nazca una tercera representación del vehículo: ya conviven
 * la placa tecleada en la estancia y el vehículo estructurado del CRM, y con
 * una tabla nueva la tentación es que cada flujo escriba a su manera. Todo lo
 * que crea o enriquece una ficha pasa por aquí.
 *
 * Regla de enriquecimiento, igual que la copia de identificación al Guest en
 * CreateWalkInStay: solo se llenan campos vacíos, nunca se pisa lo capturado.
 */
class VehicleRegistry
{
    /**
     * Resuelve (creando si hace falta) la ficha de una placa.
     *
     * @param  array<string, mixed>  $data  vehicle_plate, vehicle_brand, vehicle_model, vehicle_color, vehicle_year
     */
    public function resolve(array $data, ?Guest $guest = null): ?Vehicle
    {
        // La ficha CRM del huésped se enriquece ANTES de validar la placa:
        // una placa corta no crea ficha de vehículo, pero la marca, el
        // modelo y el color capturados no se tiran — antes se perdían y el
        // editor del huésped abría con esos campos vacíos.
        $this->enrichGuestVehicle($guest, $data);

        $plate = (string) ($data['vehicle_plate'] ?? '');
        $normalized = Vehicle::normalizePlate($plate);

        if ($normalized === null) {
            return null;
        }

        // La placa mostrada va en mayúsculas (así se escriben) pero conserva
        // los guiones o espacios como se tecleó: identidad y presentación son
        // cosas distintas.
        $display = mb_strtoupper(trim($plate));
        $vehicle = $this->firstOrCreateByPlate($normalized, $display);

        // Enriquecer sin pisar: la primera captura manda y las siguientes
        // solo rellenan huecos.
        $fill = array_filter([
            'brand' => $vehicle->brand ? null : ($data['vehicle_brand'] ?? null),
            'model' => $vehicle->model ? null : ($data['vehicle_model'] ?? null),
            'color' => $vehicle->color ? null : ($data['vehicle_color'] ?? null),
            'year' => $vehicle->year ? null : ($data['vehicle_year'] ?? null),
            'guest_id' => $vehicle->guest_id ? null : $guest?->id,
        ], fn ($value) => $value !== null && $value !== '');

        // La placa mostrada sí se actualiza al último formato tecleado: es
        // presentación, no identidad.
        if ($display !== '' && $vehicle->plate !== $display) {
            $fill['plate'] = $display;
        }

        if ($fill !== []) {
            $vehicle->fill($fill)->save();
        }

        return $vehicle;
    }

    /**
     * El vehículo del huésped en su ficha CRM (meta.vehicle, la que edita
     * "Editar huésped"): misma regla de solo llenar huecos. Es la segunda
     * representación que ya existía — aquí solo se alimenta de paso, para
     * que lo tecleado en el mostrador aparezca en sus campos (marca, modelo,
     * color) y no perdido en una línea de texto.
     *
     * @param  array<string, mixed>  $data
     */
    protected function enrichGuestVehicle(?Guest $guest, array $data): void
    {
        if (! $guest) {
            return;
        }

        $incoming = array_filter([
            'plate' => filled($data['vehicle_plate'] ?? null)
                ? mb_strtoupper(trim((string) $data['vehicle_plate']))
                : null,
            'brand' => $data['vehicle_brand'] ?? null,
            'model' => $data['vehicle_model'] ?? null,
            'color' => $data['vehicle_color'] ?? null,
            'year' => $data['vehicle_year'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $vehicle = $guest->vehicle();
        foreach ($incoming as $key => $value) {
            if (empty($vehicle[$key])) {
                $vehicle[$key] = $value;
            }
        }

        if ($vehicle !== $guest->vehicle()) {
            $guest->fill(['meta' => array_merge($guest->meta ?? [], ['vehicle' => $vehicle])])->save();
        }
    }

    /**
     * Dos recepcionistas registrando la misma placa a la vez chocarían contra
     * el índice único: si pasa, la fila ya existe y basta con leerla.
     */
    protected function firstOrCreateByPlate(string $normalized, string $plate): Vehicle
    {
        $existing = Vehicle::where('plate_normalized', $normalized)->first();

        if ($existing) {
            return $existing;
        }

        try {
            return Vehicle::create([
                'plate' => $plate !== '' ? $plate : $normalized,
                'plate_normalized' => $normalized,
            ]);
        } catch (QueryException $e) {
            return Vehicle::where('plate_normalized', $normalized)->firstOrFail();
        }
    }

    /**
     * Llena el registro con lo que ya estaba capturado antes de que existiera:
     * las placas sueltas de las estancias y los vehículos del CRM. Lo invoca
     * la migración, pero vive aquí para poder probarlo sin migrar.
     *
     * @return int fichas creadas
     */
    public function backfill(): int
    {
        $before = Vehicle::count();

        // 1) Placas de estancias, de la más reciente a la más vieja para que
        //    la ficha se quede con el último formato tecleado.
        Stay::query()
            ->whereNotNull('vehicle_plate')
            ->where('vehicle_plate', '<>', '')
            ->orderByDesc('check_in_at')
            ->chunkById(500, function ($stays) {
                foreach ($stays as $stay) {
                    $vehicle = $this->resolve(
                        ['vehicle_plate' => $stay->vehicle_plate],
                        $stay->guest,
                    );

                    if (! $vehicle) {
                        continue;
                    }

                    // La descripción libre de la visita se conserva como nota
                    // de la ficha si no había ninguna; NO se intenta partir en
                    // marca/modelo ("Versa Gris" adivinado sería basura).
                    if (! $vehicle->notes && $stay->vehicle_desc) {
                        $vehicle->update(['notes' => "Del registro anterior: {$stay->vehicle_desc}"]);
                    }

                    DB::table('stays')->where('id', $stay->id)->update(['vehicle_id' => $vehicle->id]);
                }
            });

        // 2) Vehículos del CRM: esos sí vienen estructurados.
        Guest::query()->whereNotNull('meta')->chunkById(500, function ($guests) {
            foreach ($guests as $guest) {
                $vehicle = $guest->vehicle();

                if (empty($vehicle['plate'] ?? null)) {
                    continue;
                }

                $this->resolve([
                    'vehicle_plate' => $vehicle['plate'],
                    'vehicle_brand' => $vehicle['brand'] ?? null,
                    'vehicle_model' => $vehicle['model'] ?? null,
                    'vehicle_color' => $vehicle['color'] ?? null,
                    'vehicle_year' => $vehicle['year'] ?? null,
                ], $guest);
            }
        });

        return Vehicle::count() - $before;
    }
}
