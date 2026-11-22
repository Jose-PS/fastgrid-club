<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

test('healthz reports ok when database and redis respond', function () {
    $this->getJson('/healthz')->assertOk()->assertExactJson(['status' => 'ok']);
});

test('healthz sets no session cookies', function () {
    $response = $this->get('/healthz');

    expect($response->headers->getCookies())->toBeEmpty();
});

test('healthz fails with 503 when the database fails without leaking details', function () {
    DB::shouldReceive('select')->andThrow(new RuntimeException('secret-db-host down'));
    Log::spy();

    $response = $this->getJson('/healthz');

    $response->assertStatus(503)->assertExactJson(['status' => 'error']);
    expect($response->getContent())->not->toContain('secret-db-host');
    Log::shouldHaveReceived('error')->once();
});

test('healthz fails with 503 when redis fails without leaking details', function () {
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('redis-internal down'));
    Log::spy();

    $response = $this->getJson('/healthz');

    $response->assertStatus(503)->assertExactJson(['status' => 'error']);
    expect($response->getContent())->not->toContain('redis-internal');
    Log::shouldHaveReceived('error')->once();
});

test('up still responds', function () {
    $this->get('/up')->assertOk();
});
