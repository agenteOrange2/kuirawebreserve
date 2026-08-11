<?php

namespace App\Services;

use App\Models\Central\PlatformSetting;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * Correo saliente de la PLATAFORMA (dominio central): prospectos, avisos
 * comerciales. Espejo de TenantMailer pero leyendo PlatformSetting
 * (/admin/settings/correo) en vez del Property del hotel. Sin SMTP
 * configurado devuelve null y quien envía cae al mailer default.
 */
class PlatformMailer
{
    public const MAILER = 'platform_smtp';

    public function configured(): bool
    {
        return PlatformSetting::get('mail_host') !== null
            && PlatformSetting::get('mail_from_address') !== null;
    }

    public function mailer(): ?Mailer
    {
        if (! $this->configured()) {
            return null;
        }

        $port = (int) (PlatformSetting::get('mail_port') ?? 587);

        config([
            'mail.mailers.'.self::MAILER => [
                'transport' => 'smtp',
                'host' => PlatformSetting::get('mail_host'),
                'port' => $port,
                'username' => PlatformSetting::get('mail_username'),
                'password' => $this->password(),
                // 465 = TLS implícito; en 587/25 Symfony negocia STARTTLS solo.
                'scheme' => $port === 465 ? 'smtps' : null,
                'timeout' => 10,
            ],
            'mail.from.address' => PlatformSetting::get('mail_from_address'),
            'mail.from.name' => PlatformSetting::get('mail_from_name')
                ?? PlatformSetting::get('app_name')
                ?? config('app.name'),
        ]);

        Mail::purge(self::MAILER);

        return Mail::mailer(self::MAILER);
    }

    protected function password(): ?string
    {
        $stored = PlatformSetting::get('mail_password');

        if (! $stored) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return $stored;
        }
    }
}
