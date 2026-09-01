<?php

use App\Models\User;

test('authenticated user can sign out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('sidebar shows the current user and a sign out button', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee($user->name);
    $response->assertSee('Sign out');
    $response->assertSee(route('logout'));
});
