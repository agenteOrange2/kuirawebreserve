<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * Los mismos requisitos, en palabras, para poder mostrarlos en la
     * pantalla en vez de que el usuario los descubra a base de errores.
     * Espejo de la política de AppServiceProvider::boot (Password::defaults).
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function passwordRequirements(): array
    {
        if (! app()->isProduction()) {
            return [['key' => 'length8', 'label' => 'Al menos 8 caracteres']];
        }

        return [
            ['key' => 'length12', 'label' => 'Al menos 12 caracteres'],
            ['key' => 'mixedCase', 'label' => 'Mayúsculas y minúsculas'],
            ['key' => 'numbers', 'label' => 'Al menos un número'],
            ['key' => 'symbols', 'label' => 'Al menos un símbolo'],
            ['key' => 'uncompromised', 'label' => 'Que no aparezca en filtraciones conocidas'],
        ];
    }

    /**
     * Los errores de la contraseña en español. La app corre con locale `en`
     * y sin archivos de idioma, así que sin esto el usuario leía "The
     * password field must contain at least one symbol." El Password rule
     * hereda los mensajes del validador padre, por eso basta con las llaves
     * `password.<regla>`.
     *
     * @return array<string, string>
     */
    public function passwordMessages(): array
    {
        return [
            'password.required' => 'Escribe la contraseña nueva.',
            'password.min' => 'La contraseña debe tener al menos :min caracteres.',
            'password.confirmed' => 'Las dos contraseñas no coinciden.',
            'password.mixed' => 'La contraseña necesita mayúsculas y minúsculas.',
            'password.letters' => 'La contraseña necesita al menos una letra.',
            'password.numbers' => 'La contraseña necesita al menos un número.',
            'password.symbols' => 'La contraseña necesita al menos un símbolo.',
            'password.uncompromised' => 'Esta contraseña aparece en filtraciones conocidas de internet. Elige otra.',
            'current_password.required' => 'Escribe tu contraseña actual.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
        ];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
