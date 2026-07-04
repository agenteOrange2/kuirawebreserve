<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

test('dashboard redirects to the admin panel', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect('/admin');
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('admin.dashboard'));
    $response->assertRedirect(route('login'));
});

test('platform admins can visit the admin dashboard', function () {
    Role::findOrCreate('platform-admin');
    $user = User::factory()->create();
    $user->assignRole('platform-admin');
    $this->actingAs($user);

    $response = $this->get(route('admin.dashboard'));
    $response->assertOk();
});

test('users without the platform-admin role get forbidden', function () {
    Role::findOrCreate('platform-admin');
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('admin.dashboard'));
    $response->assertForbidden();
});
