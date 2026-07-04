<?php

namespace App\Providers;

use App\Session\TenantAwareDatabaseSessionHandler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTenantAwareSessions();
        $this->hydratePlansFromDatabase();
    }

    /**
     * El catálogo de planes vive en la DB central (tabla plans, editable en
     * /admin/planes) pero TODO el código lo consume vía config('plans').
     * Aquí se hidrata en cada request; si la tabla aún no existe (primer
     * migrate, build) se queda el config de archivo como fallback.
     */
    protected function hydratePlansFromDatabase(): void
    {
        try {
            $plans = \App\Models\Central\Plan::query()->ordered()->get();

            if ($plans->isNotEmpty()) {
                config()->set('plans', $plans->mapWithKeys(
                    fn (\App\Models\Central\Plan $plan) => [$plan->key => $plan->toConfigArray()],
                )->all());
            }
        } catch (\Throwable) {
            // Sin DB o sin tabla todavía: config/plans.php sigue mandando.
        }
    }

    /**
     * Ver el docblock de TenantAwareDatabaseSessionHandler: sin esto, el login
     * de Fortify en dominios de tenant guardaría la sesión en la DB central.
     */
    protected function configureTenantAwareSessions(): void
    {
        Session::extend('database', function ($app) {
            return new TenantAwareDatabaseSessionHandler(
                $app['db']->connection(config('session.connection')),
                config('session.table', 'sessions'),
                config('session.lifetime'),
                $app,
            );
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
