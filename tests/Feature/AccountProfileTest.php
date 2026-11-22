<?php

declare(strict_types=1);

use App\Models\User;

test('the profile is updated with a trimmed name, the locale and the rankings preference', function () {
    $user = User::factory()->create(['name' => 'Antes', 'locale' => 'es', 'show_in_rankings' => true]);

    $response = $this->actingAs($user)->patch(route('account.profile.update'), [
        'name' => '  Max Piloto  ',
        'locale' => 'en',
    ]);

    $response->assertRedirect(route('account.show'))->assertSessionHasNoErrors()->assertCookie('locale', 'en');

    $user->refresh();
    expect($user->name)->toBe('Max Piloto')
        ->and($user->locale)->toBe('en')
        ->and($user->show_in_rankings)->toBeFalse();
});

test('the rankings preference can be turned on', function () {
    $user = User::factory()->create(['show_in_rankings' => false]);

    $this->actingAs($user)->patch(route('account.profile.update'), [
        'name' => 'Max Piloto',
        'locale' => 'es',
        'show_in_rankings' => '1',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->show_in_rankings)->toBeTrue();
});

test('the name must have between 3 and 32 characters after trimming', function (string $name) {
    $user = User::factory()->create(['name' => 'Antes']);

    $this->actingAs($user)
        ->patchJson(route('account.profile.update'), ['name' => $name, 'locale' => 'es'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect($user->fresh()->name)->toBe('Antes');
})->with(['', '  ab  ', str_repeat('a', 33)]);

test('names of 3 and 32 characters are accepted', function (string $name) {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('account.profile.update'), ['name' => $name, 'locale' => 'es'])->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe($name);
})->with(['abc', str_repeat('a', 32)]);

test('an unsupported locale is rejected', function () {
    $user = User::factory()->create(['locale' => 'es']);

    $this->actingAs($user)
        ->patchJson(route('account.profile.update'), ['name' => 'Max Piloto', 'locale' => 'fr'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('locale');

    expect($user->fresh()->locale)->toBe('es');
});

test('the new locale applies to the next page', function () {
    $user = User::factory()->create(['locale' => 'es']);

    $this->actingAs($user)->patch(route('account.profile.update'), ['name' => 'Max Piloto', 'locale' => 'en']);

    expect($this->get(route('account.show'))->inertiaProps()['locale'])->toBe('en');
});

test('guests cannot update a profile', function () {
    $this->patchJson(route('account.profile.update'), ['name' => 'Max Piloto', 'locale' => 'es'])->assertUnauthorized();
});
