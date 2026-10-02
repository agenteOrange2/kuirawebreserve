<?php

namespace App\Http\Responses\Auth;

use Laravel\Fortify\Contracts\PasswordResetResponse as Contract;

class PasswordResetResponse implements Contract
{
    public function __construct(protected string $status) {}

    public function toResponse($request)
    {
        $message = PasswordStatusMessage::for($this->status);

        return $request->wantsJson()
            ? response()->json(['message' => $message])
            : redirect()->route('login')->with('status', $message)->withInput($request->only('email'));
    }
}
