<?php

namespace App\Notifications;

use App\Mail\TenantBranding;
use App\Services\OutgoingMailer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Enlace para crear una contraseña nueva. Sustituye al de Laravel, que
 * llegaba en inglés ("Reset Password Notification"), firmado por la
 * plataforma y por el SMTP del .env aunque el hotel tuviera el suyo.
 * El enlace apunta al dominio desde el que se pidió (el del hotel o el
 * central), que es donde vive ese usuario.
 */
class ResetPasswordNotification extends Notification
{
    public function __construct(public string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function resetUrl(object $notifiable): string
    {
        // Siempre en el dominio del hotel (o el central fuera de un hotel),
        // aunque el envío salga de una cola o de la consola.
        return TenantBranding::tenantUrl(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = TenantBranding::resolve();
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        $message = (new MailMessage)
            ->subject("Crea tu contraseña nueva · {$brand->name}")
            ->markdown('emails.auth.reset-password', [
                'name' => trim((string) ($notifiable->name ?? '')),
                'url' => $this->resetUrl($notifiable),
                'minutes' => $minutes,
                'email' => $notifiable->getEmailForPasswordReset(),
                'brandName' => $brand->name,
            ]);

        if ($mailer = app(OutgoingMailer::class)->name()) {
            $message->mailer($mailer);
        }

        return $message;
    }
}
