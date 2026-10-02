<?php

namespace App\Http\Responses\Auth;

use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse as Contract;

/**
 * Enlace vencido, ya usado o de otro correo. El error va en `token` (no en
 * `email`, que en la pantalla es de solo lectura) para que la página
 * ofrezca pedir un enlace nuevo en vez de un mensaje sin salida.
 */
class FailedPasswordResetResponse implements Contract
{
    public function __construct(protected string $status) {}

    public function toResponse($request)
    {
        $message = PasswordStatusMessage::for($this->status);

        if ($request->wantsJson()) {
            throw ValidationException::withMessages(['token' => [$message]]);
        }

        return back()->withInput($request->only('email'))->withErrors(['token' => $message]);
    }
}
