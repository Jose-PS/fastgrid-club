<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

function ipSeenBehind(string $proxy, ?string $trusted): string
{
    test()->get('/up');

    TrustProxies::at($trusted === null ? [] : [$trusted]);

    Route::get('/_ip', fn (Request $request) => $request->ip());

    return test()->withServerVariables(['REMOTE_ADDR' => $proxy])
        ->get('/_ip', ['X-Forwarded-For' => '203.0.113.9'])
        ->getContent();
}

afterEach(function () {
    TrustProxies::flushState();
});

test('the real ip is taken from a trusted proxy', function () {
    expect(ipSeenBehind('10.0.0.5', '10.0.0.0/8'))->toBe('203.0.113.9');
});

test('forwarded headers are ignored from an untrusted proxy', function () {
    expect(ipSeenBehind('10.0.0.5', null))->toBe('10.0.0.5');
});
