<?php

namespace App\Http\Responses\Auth;

use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as Contract;

class SuccessfulPasswordResetLinkRequestResponse implements Contract
{
    public function __construct(protected string $status) {}

    public function toResponse($request)
    {
        $message = PasswordStatusMessage::for($this->status);

        return $request->wantsJson()
            ? response()->json(['message' => $message])
            : back()->with('status', $message)->withInput($request->only('email'));
    }
}
