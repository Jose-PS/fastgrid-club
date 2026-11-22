<?php

declare(strict_types=1);

use App\Models\User;

test('a guest gets the locale cookie', function () {
    $this->from('/login')
        ->post(route('locale.update'), ['locale' => 'en'])
        ->assertRedirect('/login')
        ->assertCookie('locale', 'en');
});

test('the cookie lasts one year', function () {
    $response = $this->post(route('locale.update'), ['locale' => 'en']);

    $cookie = $response->getCookie('locale', false);

    expect($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(364)->getTimestamp());
});

test('a signed in user gets the cookie and the stored locale', function () {
    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)
        ->post(route('locale.update'), ['locale' => 'en'])
        ->assertCookie('locale', 'en');

    expect($user->fresh()->locale)->toBe('en');
});

test('an invalid locale is rejected without changes', function (mixed $value) {
    $user = User::factory()->create(['locale' => 'es']);

    $response = $this->actingAs($user)
        ->from('/')
        ->post(route('locale.update'), ['locale' => $value]);

    $response->assertSessionHasErrors('locale')->assertCookieMissing('locale');
    expect($user->fresh()->locale)->toBe('es');
})->with(['fr', '', null, 'EN']);

test('the validation message is translated to the resolved language', function () {
    $spanish = $this->withHeader('Accept-Language', 'es')
        ->postJson(route('locale.update'), ['locale' => 'fr']);

    $english = $this->withHeader('Accept-Language', 'en')
        ->postJson(route('locale.update'), ['locale' => 'fr']);

    $spanish->assertUnprocessable();
    $english->assertUnprocessable();
    expect($spanish->json('errors.locale.0'))->toContain('seleccionado no es válido');
    expect($english->json('errors.locale.0'))->toContain('selected locale is invalid');
});

test('the switcher on a public page redirects to the equivalent url of the other language', function (string $from, string $locale, string $expected) {
    $this->from($from)
        ->post(route('locale.update'), ['locale' => $locale])
        ->assertRedirect($expected)
        ->assertCookie('locale', $locale);
})->with([
    ['/calendar', 'en', '/en/calendar'],
    ['/en/calendar', 'es', '/calendar'],
    ['/en', 'es', '/'],
    ['/proposals/new', 'en', '/en/proposals/new'],
    ['/en/proposals?x=1', 'es', '/proposals?x=1'],
]);
