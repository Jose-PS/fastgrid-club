<?php

declare(strict_types=1);

use App\Enums\WeekStatus;
use App\Models\Car;
use App\Models\Server;
use App\Models\Track;
use App\Models\Week;
use App\Support\ServerSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('public pages stay within their query budget', function (string $path, int $budget) {
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get($path)->assertOk();

    $queries = count(DB::getQueryLog());

    expect($queries)->toBeLessThanOrEqual($budget);
})->with([
    'home' => ['/', 10],
    'calendar' => ['/calendar', 8],
    'proposals' => ['/proposals', 12],
    'proposals create' => ['/proposals/new', 12],
]);

test('public pages make no outgoing requests', function (string $path) {
    Http::preventStrayRequests();
    Http::fake();

    $this->get($path)->assertOk();

    Http::assertNothingSent();
})->with(['/', '/calendar', '/proposals', '/proposals/new']);

test('the home page with a configured week stays within its query budget', function () {
    $server = Server::factory()->create(['is_active' => true]);
    $week = Week::factory()->for($server)->status(WeekStatus::Active)->create(['track_id' => Track::factory()->create()->id]);
    $week->cars()->attach(Car::factory()->count(3)->create()->pluck('id'));
    app(ServerSnapshot::class)->store($server, true, 5, true, CarbonImmutable::now());

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get('/')->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(12);
});
