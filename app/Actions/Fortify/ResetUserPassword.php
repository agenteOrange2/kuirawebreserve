<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ], $this->passwordMessages())->validate();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();

        // Quien tenga una sesión abierta con la contraseña vieja queda fuera:
        // si la recuperaron porque alguien más entraba, así se le cierra.
        try {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        } catch (\Throwable) {
            // Sesiones fuera de la base (otro driver): nada que cerrar aquí.
        }

        // Aviso de seguridad al correo de la cuenta; nunca rompe el cambio.
        try {
            $user->notify(new PasswordChangedNotification('reset', request()->ip(), request()->userAgent()));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
