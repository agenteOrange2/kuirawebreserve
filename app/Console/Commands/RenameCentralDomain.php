<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Migración de dominio de la plataforma: renombra los dominios de TODOS los
 * tenants en la DB central (demo.viejo.la → demo.nuevo.com). Los pasos
 * manuales que acompañan: APP_URL en .env, central_domains en
 * config/tenancy.php y el server_name de nginx.
 */
class RenameCentralDomain extends Command
{
    protected $signature = 'platform:rename-domain {from : Sufijo actual (kuirawebreserve.la)} {to : Sufijo nuevo (kuirawebreserve.com)} {--dry : Solo mostrar qué cambiaría}';

    protected $description = 'Renombra el sufijo de dominio de todos los tenants (DB central)';

    public function handle(): int
    {
        $from = ltrim($this->argument('from'), '.');
        $to = ltrim($this->argument('to'), '.');

        $domains = Domain::query()
            ->where('domain', 'like', '%.'.$from)
            ->orWhere('domain', $from)
            ->get();

        if ($domains->isEmpty()) {
            $this->warn("No hay dominios con sufijo {$from}.");

            return self::SUCCESS;
        }

        foreach ($domains as $domain) {
            $new = preg_replace('/'.preg_quote($from, '/').'$/', $to, $domain->domain);
            $this->line(($this->option('dry') ? '[dry] ' : '')."{$domain->domain} → {$new}");

            if (! $this->option('dry')) {
                $domain->update(['domain' => $new]);
            }
        }

        if (! $this->option('dry')) {
            $this->info('Listo. Recuerda: APP_URL en .env, config/tenancy.php central_domains, nginx server_name y config:clear.');
        }

        return self::SUCCESS;
    }
}
