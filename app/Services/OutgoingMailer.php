<?php

namespace App\Services;

/**
 * Por dónde sale un correo de CUENTA (recuperar contraseña, contraseña
 * cambiada): el SMTP del hotel si estamos en su dominio y lo configuró; si
 * no, el de la plataforma (/admin/settings/correo); si tampoco, el mailer
 * default del .env. Devuelve el NOMBRE del mailer porque las notificaciones
 * lo piden así (MailMessage::mailer()); null = el default.
 *
 * Antes el correo de recuperación ignoraba los dos SMTP configurables y
 * salía siempre por el .env, en inglés y firmado por la plataforma.
 */
class OutgoingMailer
{
    public function name(): ?string
    {
        try {
            if (tenancy()->initialized && app(TenantMailer::class)->mailer() !== null) {
                return TenantMailer::MAILER;
            }
        } catch (\Throwable) {
            // Sin tabla de properties o SMTP roto en la config: sigue abajo.
        }

        try {
            if (app(PlatformMailer::class)->mailer() !== null) {
                return PlatformMailer::MAILER;
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
