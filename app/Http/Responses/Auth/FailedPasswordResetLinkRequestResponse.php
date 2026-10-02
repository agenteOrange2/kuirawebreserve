<?php

namespace App\Http\Responses\Auth;

use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as Contract;

/**
 * Un correo sin cuenta recibe la MISMA respuesta que uno con cuenta: decir
 * "no encontramos ese correo" le confirma a cualquiera quién trabaja en el
 * hotel. Solo la espera entre envíos se reporta como error.
 */
class FailedPasswordResetLinkRequestResponse implements Contract
{
    public function __construct(protected string $status) {}

    public function toResponse($request)
    {
        if ($this->status === Password::INVALID_USER) {
            return (new SuccessfulPasswordResetLinkRequestResponse($this->status))->toResponse($request);
        }

        $message = PasswordStatusMessage::for($this->status);

        if ($request->wantsJson()) {
            throw ValidationException::withMessages(['email' => [$message]]);
        }

        return back()->withInput($request->only('email'))->withErrors(['email' => $message]);
    }
}
