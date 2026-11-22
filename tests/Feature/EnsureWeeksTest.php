<?php

declare(strict_types=1);

use App\Enums\ConfigSource;
use App\Enums\WeekStatus;
use App\Models\Server;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00:00', 'Europe/Madrid'));
});

test('it creates the current and next week', function () {
    $server = Server::factory()->create();

    $this->artisan('weeks:ensure')->assertSuccessful();

    $weeks = Week::query()->where('server_id', $server->id)->orderBy('starts_at')->get();

    expect($weeks)->toHaveCount(2)
        ->and($weeks[0]->status)->toBe(WeekStatus::Active)
        ->and($weeks[0]->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-01 23:00:00')
        ->and($weeks[0]->track_id)->toBeNull()
        ->and($weeks[0]->config_source)->toBeNull()
        ->and($weeks[1]->status)->toBe(WeekStatus::Voting)
        ->and($weeks[1]->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-08 23:00:00')
        ->and($weeks[1]->ends_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-15 23:00:00')
        ->and($weeks[1]->voting_closes_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-08 22:59:59');
});

test('running it twice does not duplicate weeks', function () {
    Server::factory()->create();

    $this->artisan('weeks:ensure')->assertSuccessful();
    $this->artisan('weeks:ensure')->assertSuccessful();

    expect(Week::query()->count())->toBe(2);
});

test('it skips inactive servers', function () {
    Server::factory()->create(['is_active' => false]);

    $this->artisan('weeks:ensure')->assertSuccessful();

    expect(Week::query()->count())->toBe(0);
});

test('a scheduled week that has started becomes active', function () {
    $server = Server::factory()->create();
    $week = Week::factory()->for($server)->status(WeekStatus::Scheduled)->startingAt(now()->toImmutable())->create();

    $this->artisan('weeks:ensure')->assertSuccessful();

    expect($week->fresh()->status)->toBe(WeekStatus::Active);
});

test('a scheduled week in the future stays scheduled', function () {
    $server = Server::factory()->create();
    $week = Week::factory()->for($server)->status(WeekStatus::Scheduled)->startingAt(now()->addWeek()->toImmutable())->create();

    $this->artisan('weeks:ensure')->assertSuccessful();

    expect($week->fresh()->status)->toBe(WeekStatus::Scheduled);
});

test('an active week that has ended becomes finished', function () {
    $server = Server::factory()->create();
    $week = Week::factory()->for($server)->status(WeekStatus::Active)->startingAt(now()->subWeek()->toImmutable())->create();

    $this->artisan('weeks:ensure')->assertSuccessful();

    expect($week->fresh()->status)->toBe(WeekStatus::Finished);
});

test('a closed voting week is resolved before it is activated', function () {
    $server = Server::factory()->create();
    $week = Week::factory()->for($server)->status(WeekStatus::Voting)->startingAt(now()->toImmutable())->create();

    $this->artisan('weeks:ensure')->assertSuccessful();

    $week = $week->fresh();

    expect($week->status)->toBe(WeekStatus::Active)
        ->and($week->resolved_at)->not->toBeNull()
        ->and($week->config_source)->toBe(ConfigSource::Fallback)
        ->and(Week::query()->count())->toBe(2);
});

test('a voting week still open is left alone', function () {
    $server = Server::factory()->create();
    $week = Week::factory()->for($server)->status(WeekStatus::Voting)->startingAt(now()->addWeek()->toImmutable())->create();

    $this->artisan('weeks:ensure')->assertSuccessful();

    expect($week->fresh()->status)->toBe(WeekStatus::Voting)
        ->and($week->fresh()->resolved_at)->toBeNull();
});

test('servers are handled independently', function () {
    $first = Server::factory()->create();
    $second = Server::factory()->create();
    Week::factory()->for($first)->status(WeekStatus::Active)->startingAt(now()->subWeek()->toImmutable())->create();

    $this->artisan('weeks:ensure')->assertSuccessful();

    expect(Week::query()->where('server_id', $first->id)->count())->toBe(3)
        ->and(Week::query()->where('server_id', $second->id)->count())->toBe(2)
        ->and(Week::query()->where('server_id', $second->id)->where('status', WeekStatus::Finished)->count())->toBe(0);
});

test('the database rejects a voting close after the start', function () {
    $server = Server::factory()->create();

    expect(fn () => Week::factory()->for($server)->create(['voting_closes_at' => now()->addYear()]))
        ->toThrow(QueryException::class);
});

test('a week has unique server and start', function () {
    $server = Server::factory()->create();
    Week::factory()->for($server)->create();

    expect(fn () => DB::transaction(fn () => Week::factory()->for($server)->create()))
        ->toThrow(QueryException::class);
});
