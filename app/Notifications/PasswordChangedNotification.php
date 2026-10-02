<?php

namespace App\Notifications;

use App\Mail\TenantBranding;
use App\Services\OutgoingMailer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de seguridad: "tu contraseña cambió". Sale tras recuperarla con el
 * enlace y tras cambiarla desde el perfil, con cuándo, desde dónde y qué
 * hacer si no fue la persona. Si alguien más la cambió, así se entera.
 */
class PasswordChangedNotification extends Notification
{
    public function __construct(
        /** 'reset' = por el enlace del correo; 'profile' = desde su perfil. */
        public string $via = 'reset',
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = TenantBranding::resolve();

        $message = (new MailMessage)
            ->subject("Tu contraseña cambió · {$brand->name}")
            ->markdown('emails.auth.password-changed', [
                'name' => trim((string) ($notifiable->name ?? '')),
                'email' => $notifiable->email ?? '',
                'when' => now()->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY, HH:mm'),
                'how' => $this->via === 'profile' ? 'Desde tu perfil, con tu contraseña anterior.' : 'Con el enlace de recuperación que enviamos a tu correo.',
                'ip' => $this->ip,
                'device' => self::device($this->userAgent),
                'loginUrl' => TenantBranding::tenantUrl(route('login', [], false)),
                'forgotUrl' => TenantBranding::tenantUrl(route('password.request', [], false)),
                'brandName' => $brand->name,
            ]);

        if ($mailer = app(OutgoingMailer::class)->name()) {
            $message->mailer($mailer);
        }

        return $message;
    }

    /** "Chrome en Windows" a partir del user agent; null si no se reconoce. */
    public static function device(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        $os = match (true) {
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iPhone/iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser && $os => "{$browser} en {$os}",
            (bool) $browser => $browser,
            (bool) $os => $os,
            default => null,
        };
    }
}
