<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    use PasswordValidationRules;

    /**
     * Show the user's password settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Password', [
            // La pantalla enseña los requisitos REALES; sin esto el usuario
            // los descubría a base de errores del servidor.
            'requirements' => $this->passwordRequirements(),
        ]);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        // Aviso de seguridad: si no fue la persona, se entera por correo.
        try {
            $request->user()->notify(new \App\Notifications\PasswordChangedNotification('profile', $request->ip(), $request->userAgent()));
        } catch (\Throwable $e) {
            report($e);
        }

        return back();
    }
}
