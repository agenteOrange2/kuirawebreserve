<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Recuperar contraseña en español, y sin delatar qué correos tienen
        // cuenta (ver App\Http\Responses\Auth).
        $this->app->singleton(\Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse::class, \App\Http\Responses\Auth\SuccessfulPasswordResetLinkRequestResponse::class);
        $this->app->singleton(\Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse::class, \App\Http\Responses\Auth\FailedPasswordResetLinkRequestResponse::class);
        $this->app->singleton(\Laravel\Fortify\Contracts\PasswordResetResponse::class, \App\Http\Responses\Auth\PasswordResetResponse::class);
        $this->app->singleton(\Laravel\Fortify\Contracts\FailedPasswordResetResponse::class, \App\Http\Responses\Auth\FailedPasswordResetResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => Features::enabled(Features::registration()),
            'status' => $request->session()->get('status'),
            // El correo con el que viene de recuperar su contraseña.
            'email' => (string) $request->session()->getOldInput('email', ''),
            // En el dominio de un hotel, su logo, nombre y colores.
            'tenantBrand' => \App\Support\TenantLoginBrand::resolve(),
        ]));

        Fortify::resetPasswordView(function (Request $request) {
            $email = (string) $request->email;
            $token = (string) $request->route('token');

            return Inertia::render('auth/ResetPassword', [
                'email' => $email,
                'token' => $token,
                // Se revisa al ABRIR el enlace: antes el aviso de "venció"
                // llegaba hasta después de escribir dos veces la contraseña.
                'tokenValid' => $this->resetTokenIsValid($email, $token),
                'requirements' => (new class
                {
                    use \App\Concerns\PasswordValidationRules;
                })->passwordRequirements(),
                'tenantBrand' => \App\Support\TenantLoginBrand::resolve(),
            ]);
        });

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
            'email' => (string) ($request->session()->getOldInput('email') ?? $request->query('email', '')),
            'expireMinutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
            'tenantBrand' => \App\Support\TenantLoginBrand::resolve(),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register'));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /** El enlace existe, es de ese correo y no ha vencido. */
    private function resetTokenIsValid(string $email, string $token): bool
    {
        if ($email === '' || $token === '') {
            return false;
        }

        try {
            $user = \App\Models\User::query()->where('email', $email)->first();

            return $user !== null && \Illuminate\Support\Facades\Password::broker(config('fortify.passwords'))->tokenExists($user, $token);
        } catch (\Throwable) {
            // Ante la duda se deja intentar: el envío vuelve a validar.
            return true;
        }
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
