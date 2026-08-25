<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * El formato de levantamiento en PDF, personalizado por cliente.
 *
 * El contenido vive en docs/levantamiento-cliente.md (una sola fuente: se
 * edita ahí y se regenera). Este comando solo lo maqueta y le pone la
 * portada con el nombre del hotel, para no mandarle a cada prospecto un
 * documento genérico.
 */
class BuildIntakeDoc extends Command
{
    protected $signature = 'levantamiento:pdf
        {--hotel= : Nombre del hotel para la portada}
        {--tenant= : Id de un tenant existente; toma su nombre}
        {--out= : Ruta del PDF (por defecto storage/app/levantamiento-<hotel>.pdf)}';

    protected $description = 'Genera el formato de levantamiento en PDF, personalizado para un cliente';

    public function handle(): int
    {
        $fuente = base_path('docs/levantamiento-cliente.md');

        if (! is_file($fuente)) {
            $this->error("No encontré {$fuente}.");

            return self::FAILURE;
        }

        $hotel = $this->option('hotel');

        if ($id = $this->option('tenant')) {
            $tenant = Tenant::find($id);

            if (! $tenant) {
                $this->error("No existe el hotel '{$id}'.");

                return self::FAILURE;
            }

            // El nombre comercial de verdad vive en la Property del hotel;
            // el del tenant a veces es el corto con el que se dio de alta.
            $hotel ??= $tenant->run(fn () => \App\Models\Property::query()->value('name'))
                ?: ($tenant->name ?? $tenant->id);
        }

        $hotel = trim((string) ($hotel ?: 'Tu hotel'));

        // El markdown se convierte a HTML y se maqueta; el encabezado y la
        // introducción ya los pinta la portada, así que se recortan.
        $markdown = file_get_contents($fuente);
        $markdown = Str::after($markdown, '## 1. Datos del negocio');
        $markdown = '## 1. Datos del negocio'.$markdown;

        $pdf = Pdf::loadView('pdf.levantamiento', [
            'title' => "Levantamiento · {$hotel}",
            'marca' => config('app.name', 'KuiraReserve'),
            'hotel' => $hotel,
            // locale('es') explícito: translatedFormat sigue el locale de la
            // app, que aquí es inglés, y salía "25 de August de 2026".
            'fecha' => now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
            'contenido' => Str::markdown($markdown, ['html_input' => 'allow']),
        ])->setPaper('letter');

        $destino = $this->option('out')
            ?: storage_path('app/levantamiento-'.Str::slug($hotel).'.pdf');

        @mkdir(dirname($destino), 0775, true);
        file_put_contents($destino, $pdf->output());

        $this->info("Listo: {$destino}");
        $this->line('  '.number_format(filesize($destino) / 1024, 0).' KB · para '.$hotel);

        return self::SUCCESS;
    }
}
