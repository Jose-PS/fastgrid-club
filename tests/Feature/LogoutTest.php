<?php

declare(strict_types=1);

use App\Models\User;

test('logging out invalidates the session and redirects home', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'es')
        ->withSession(['pending' => 'value'])
        ->post(route('logout'))
        ->assertRedirect(route('home'))
        ->assertSessionMissing('pending');

    $this->assertGuest();
});

test('a guest can post to logout without errors', function () {
    $this->withHeader('Accept-Language', 'es')->post(route('logout'))->assertRedirect(route('home'));
});

test('logout does not accept get requests', function () {
    $this->get('/logout')->assertMethodNotAllowed();
});
