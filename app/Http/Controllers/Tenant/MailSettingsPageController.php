<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Área aislada de correo saliente: el SMTP propio del hotel para que las
 * confirmaciones y avisos al huésped salgan a nombre del hotel. Separada de
 * Ajustes general a propósito, igual que el wizard y los métodos de pago.
 */
class MailSettingsPageController extends Controller
{
    public function __invoke(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return Inertia::render('tenant/settings/Mails', [
            'property' => [
                'id' => $property->id,
                'name' => $property->name,
            ],
            'settings' => [
                'email' => $settings['email'] ?? '',
                // La contraseña nunca viaja al cliente: solo si existe.
                'smtp_host' => $settings['smtp_host'] ?? '',
                'smtp_port' => (int) ($settings['smtp_port'] ?? 587),
                'smtp_username' => $settings['smtp_username'] ?? '',
                'smtp_from_address' => $settings['smtp_from_address'] ?? '',
                'smtp_from_name' => $settings['smtp_from_name'] ?? '',
                'has_smtp_password' => ! empty($settings['smtp_password']),
            ],
        ]);
    }
}
