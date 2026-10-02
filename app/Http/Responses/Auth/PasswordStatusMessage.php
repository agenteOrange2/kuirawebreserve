<?php

namespace App\Http\Responses\Auth;

use Illuminate\Support\Facades\Password;

/**
 * Los estados del broker de contraseñas, en español. La app corre con
 * locale `en` y sin archivos de idioma: Fortify devolvía "We have emailed
 * your password reset link." tal cual.
 */
class PasswordStatusMessage
{
    /** Lo que se le dice a quien pide el enlace, exista o no su cuenta. */
    public const LINK_SENT = 'Si ese correo tiene una cuenta, ya te enviamos un enlace para crear una contraseña nueva. Revisa tu bandeja y la carpeta de spam.';

    public static function for(string $status): string
    {
        return match ($status) {
            Password::RESET_LINK_SENT, Password::INVALID_USER => self::LINK_SENT,
            Password::RESET_THROTTLED => 'Ya te enviamos un enlace hace un momento. Espera un minuto antes de pedir otro.',
            Password::INVALID_TOKEN => 'Este enlace ya no sirve: venció o ya se usó. Pide uno nuevo.',
            Password::PASSWORD_RESET => 'Listo, tu contraseña cambió. Entra con la nueva.',
            default => 'No pudimos completar la solicitud. Intenta de nuevo.',
        };
    }
}
