<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            // Con qué se arma la ficha: quién es esta persona dentro del
            // panel, no solo su nombre y correo.
            'profile' => [
                'phone' => $user->phone,
                'roles' => $user->getRoleNames()->values()->all(),
                'member_since' => $user->created_at?->locale('es')->isoFormat('MMMM [de] YYYY'),
                'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'workspace' => tenant() ? (\App\Models\Property::first()?->name ?? tenant('id')) : 'Plataforma',
            ],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        // Las mismas pantallas sirven a los dos paneles: se vuelve al perfil
        // del panel donde está la persona, no siempre al del admin.
        return to_route(tenant() ? 'tenant.profile.edit' : 'admin.settings.profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
