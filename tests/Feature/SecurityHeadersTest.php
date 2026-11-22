<?php

declare(strict_types=1);

function cspOf($response, string $header = 'Content-Security-Policy'): string
{
    return (string) $response->headers->get($header);
}

test('public responses carry the security headers', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');

    expect($response->headers->get('Permissions-Policy'))
        ->toContain('publickey-credentials-get=(self)')
        ->toContain('publickey-credentials-create=(self)')
        ->toContain('camera=()');
    expect($response->headers->has('Content-Security-Policy'))->toBeTrue();
});

test('error responses carry the security headers', function () {
    $response = $this->get('/ruta-que-no-existe');

    $response->assertNotFound()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    expect($response->headers->has('Content-Security-Policy'))->toBeTrue();
});

test('the nonce changes between requests and appears in the html', function () {
    $first = $this->get('/');
    $second = $this->get('/');

    preg_match("/'nonce-([^']+)'/", cspOf($first), $firstMatch);
    preg_match("/'nonce-([^']+)'/", cspOf($second), $secondMatch);

    expect($firstMatch[1])->not->toBe($secondMatch[1]);
    expect($first->getContent())->toContain('nonce="'.$firstMatch[1].'"');
});

test('the policy forbids framing and unsafe script sources', function () {
    $csp = cspOf($this->get('/'));
    $scriptSrc = collect(explode(';', $csp))
        ->map(fn (string $directive): string => trim($directive))
        ->first(fn (string $directive): bool => str_starts_with($directive, 'script-src'));

    expect($csp)->toContain("frame-ancestors 'none'")
        ->toContain("style-src 'self';")
        ->toContain("form-action 'self'");
    expect($scriptSrc)->not->toContain('unsafe-inline')->not->toContain('unsafe-eval');
});

test('the policy is report only in staging and enforced elsewhere', function () {
    $this->get('/')->assertHeaderMissing('Content-Security-Policy-Report-Only');

    $this->app['env'] = 'staging';
    $staging = $this->get('/');

    expect($staging->headers->has('Content-Security-Policy-Report-Only'))->toBeTrue();
    expect($staging->headers->has('Content-Security-Policy'))->toBeFalse();
});
