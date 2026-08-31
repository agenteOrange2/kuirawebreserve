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
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
