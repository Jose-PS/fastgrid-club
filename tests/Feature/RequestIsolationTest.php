<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

function scriptNonceOf(TestResponse $response): string
{
    preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $matches);

    return $matches[1];
}

test('consecutive requests from different users do not share state', function () {
    $spanish = User::factory()->create(['locale' => 'es']);
    $english = User::factory()->create(['locale' => 'en']);

    $first = $this->actingAs($spanish)->get('/');
    $second = $this->actingAs($english)->get('/en');

    $first->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.id', $spanish->id)
        ->where('locale', 'es'));
    $second->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.id', $english->id)
        ->where('locale', 'en'));

    expect(scriptNonceOf($first))->not->toBe(scriptNonceOf($second));
    expect($first->getContent())->toContain('<html lang="es"');
    expect($second->getContent())->toContain('<html lang="en"');
});
