<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PlatformTestMail;
use App\Models\Central\PlatformSetting;
use App\Services\PlatformMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * SMTP de la plataforma (/admin/settings/correo): remitente de los correos
 * comerciales del dominio central (documentos a prospectos). La contraseña
 * se guarda cifrada y nunca vuelve al navegador. Incluye el interruptor del
 * envío automático al registrarse y un correo de prueba.
 */
class EmailSettingsController extends Controller
{
    public function edit(PlatformMailer $mailer): Response
    {
        return Inertia::render('settings/Email', [
            'settings' => [
                'mail_host' => PlatformSetting::get('mail_host', ''),
                'mail_port' => PlatformSetting::get('mail_port', '587'),
                'mail_username' => PlatformSetting::get('mail_username', ''),
                'mail_from_address' => PlatformSetting::get('mail_from_address', ''),
                'mail_from_name' => PlatformSetting::get('mail_from_name', ''),
                'has_password' => PlatformSetting::get('mail_password') !== null,
            ],
            'autoEmailEnabled' => PlatformSetting::get('prospects_auto_email', '1') === '1',
            'configured' => $mailer->configured(),
            'prospectsUrl' => route('admin.prospects'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mail_host' => ['nullable', 'string', 'max:190'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['nullable', 'string', 'max:190'],
            'mail_password' => ['nullable', 'string', 'max:190'],
            'mail_from_address' => ['nullable', 'email:rfc', 'max:190'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
            'prospects_auto_email' => ['required', 'boolean'],
        ]);

        foreach (['mail_host', 'mail_username', 'mail_from_address', 'mail_from_name'] as $key) {
            PlatformSetting::set($key, trim((string) ($data[$key] ?? '')) ?: null);
        }

        PlatformSetting::set('mail_port', $data['mail_port'] ? (string) $data['mail_port'] : null);

        // Vacío = conservar la contraseña actual (nunca se reenvía al form).
        if (($data['mail_password'] ?? '') !== '') {
            PlatformSetting::set('mail_password', Crypt::encryptString($data['mail_password']));
        }

        PlatformSetting::set('prospects_auto_email', $data['prospects_auto_email'] ? '1' : '0');

        return back()->with('success', 'Configuración de correo guardada.');
    }

    public function test(Request $request, PlatformMailer $mailer): RedirectResponse
    {
        $smtp = $mailer->mailer();

        if ($smtp === null) {
            return back()->with('error', 'Configura el servidor SMTP y el remitente antes de probar.');
        }

        $to = $request->user()->email;

        try {
            $smtp->to($to)->send(new PlatformTestMail);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo enviar: revisa servidor, puerto y credenciales.');
        }

        return back()->with('success', 'Correo de prueba enviado a '.$to.'.');
    }
}
