<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('public');
});

test('the 61st request in a minute is rate limited', function () {
    foreach (range(1, 60) as $attempt) {
        $this->get('/')->assertOk();
    }

    $this->get('/')->assertStatus(429);
});

test('up and healthz are not rate limited', function () {
    foreach (range(1, 65) as $attempt) {
        $this->get('/up')->assertOk();
        $this->get('/healthz')->assertOk();
    }
});

test('named limiters are registered with their values', function () {
    $request = Request::create('/', 'POST', ['email' => 'a@b.test'], server: ['REMOTE_ADDR' => '1.2.3.4']);

    $single = [
        'login' => [10, 60],
        'magic-link-consume' => [10, 60],
        'vote' => [30, 60],
        'proposal' => [5, 3600],
        'public' => [60, 60],
        'status' => [60, 60],
    ];

    foreach ($single as $name => [$attempts, $seconds]) {
        $limit = RateLimiter::limiter($name)($request);
        expect($limit)->toBeInstanceOf(Limit::class)
            ->and($limit->maxAttempts)->toBe($attempts)
            ->and($limit->decaySeconds)->toBe($seconds);
    }

    $magic = RateLimiter::limiter('magic-link')($request);
    expect($magic)->toHaveCount(2)
        ->and($magic[0]->maxAttempts)->toBe(5)
        ->and($magic[0]->decaySeconds)->toBe(3600)
        ->and($magic[1]->maxAttempts)->toBe(20)
        ->and($magic[1]->decaySeconds)->toBe(3600);

    expect(RateLimiter::limiter('passkeys'))->not->toBeNull();
});
