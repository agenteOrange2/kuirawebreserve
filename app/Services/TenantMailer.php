<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * Correo saliente con el SMTP propio del hotel (settings del Property,
 * /ajustes): cada tenant manda desde SU remitente, no desde uno global de
 * la plataforma. Sin SMTP configurado devuelve null y quien avisa cae al
 * mailer default (en este server: log) — el aviso nunca truena por esto.
 */
class TenantMailer
{
    public const MAILER = 'tenant_smtp';

    public function mailer(): ?Mailer
    {
        $settings = Property::query()->first()?->settings ?? [];

        if (empty($settings['smtp_host']) || empty($settings['smtp_from_address'])) {
            return null;
        }

        $port = (int) ($settings['smtp_port'] ?? 587);

        config([
            'mail.mailers.'.self::MAILER => [
                'transport' => 'smtp',
                'host' => $settings['smtp_host'],
                'port' => $port,
                'username' => $settings['smtp_username'] ?? null,
                'password' => $this->password($settings),
                // 465 = TLS implícito; en 587/25 Symfony negocia STARTTLS solo.
                'scheme' => $port === 465 ? 'smtps' : null,
                'timeout' => 10,
            ],
            'mail.from.address' => $settings['smtp_from_address'],
            'mail.from.name' => $settings['smtp_from_name'] ?? (Property::query()->first()?->name ?? config('app.name')),
        ]);

        // El manager cachea mailers por nombre: en procesos largos (scheduler
        // multi-tenant) hay que purgar para que tome la config del tenant actual.
        Mail::purge(self::MAILER);

        return Mail::mailer(self::MAILER);
    }

    /** @param array<string, mixed> $settings */
    protected function password(array $settings): ?string
    {
        $stored = $settings['smtp_password'] ?? null;

        if (! $stored) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            // Valor viejo sin cifrar (o llave rotada): se usa tal cual.
            return $stored;
        }
    }
}
