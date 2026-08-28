<?php

use App\Models\User;

test('login with invalid credentials fails gracefully with a validation error', function () {
    $user = User::factory()->create(['password' => bcrypt('correct-password')]);

    $response = $this->post(route('login.post'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors('email');
    $response->assertRedirect();
    $this->assertGuest();
});

test('login is graceful when a stored password is not hashed with the supported algorithm', function () {
    $user = User::factory()->create(['password' => 'not-a-bcrypt-hash']);

    $response = $this->post(route('login.post'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email', 'We could not process your login right now. Please try again later.');
    $response->assertRedirect();
    $this->assertGuest();
});
