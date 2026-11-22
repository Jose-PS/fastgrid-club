<?php

declare(strict_types=1);

use App\Enums\ResultSource;
use App\Integration\Server\Log\SessionType;
use App\Models\Car;
use App\Models\Server;
use App\Models\ServerSession;
use App\Models\Track;
use App\Models\Week;
use App\Support\HomeStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function seedVolume(): array
{
    $server = Server::factory()->create();
    $week = Week::factory()->for($server)->create();
    $tracks = Track::factory()->count(6)->create();
    $cars = Car::factory()->count(8)->create();
    $now = now()->format('Y-m-d H:i:s');

    $drivers = [];
    for ($i = 0; $i < 500; $i++) {
        $drivers[] = ['steam_id' => '7656119'.str_pad((string) $i, 10, '0', STR_PAD_LEFT), 'last_name' => "Pilot {$i}", 'hidden' => false, 'first_seen_at' => $now, 'last_seen_at' => $now];
    }
    DB::table('drivers')->insert($drivers);
    $driverIds = DB::table('drivers')->pluck('id')->all();

    $rows = [];
    foreach ($tracks as $track) {
        foreach ($cars as $car) {
            foreach ($driverIds as $index => $driverId) {
                $rows[] = ['track_id' => $track->id, 'car_id' => $car->id, 'driver_id' => $driverId, 'lap_ms' => 80000 + (($index * 37 + $track->id * 11 + $car->id * 7) % 40000), 'achieved_at' => $now];
            }
        }
    }
    foreach (array_chunk($rows, 2000) as $chunk) {
        DB::table('best_laps')->insert($chunk);
    }

    $start = CarbonImmutable::parse('2026-11-07 17:30:00', 'UTC');
    $selected = null;
    $olderWeek = Week::factory()->for($server)->startingAt(CarbonImmutable::parse('2026-01-05', 'Europe/Madrid'))->create();
    for ($cycle = 0; $cycle < 66; $cycle++) {
        $latest = $cycle < 6;
        $session = ServerSession::factory()->create([
            'server_id' => $server->id,
            'type' => SessionType::Race,
            'started_at' => $latest ? $start->addHours($cycle) : $start->subDays($cycle),
            'ended_at' => ($latest ? $start->addHours($cycle) : $start->subDays($cycle))->addMinutes(20),
            'week_id' => $latest ? $week->id : $olderWeek->id,
            'cycle_index' => $cycle % 6,
        ]);
        $selected = $latest ? $session : $selected;
        $results = [];
        foreach (array_slice($driverIds, 0, 150) as $position => $driverId) {
            $results[] = ['server_session_id' => $session->id, 'week_id' => $week->id, 'cycle_index' => $cycle, 'driver_id' => $driverId, 'car_id' => $cars[0]->id, 'position' => $position + 1, 'laps_completed' => 10, 'lapped' => false, 'best_lap_ms' => 90000 + $position, 'classified' => true, 'source' => ResultSource::Log->value, 'created_at' => $now];
        }
        DB::table('race_results')->insert($results);
    }

    DB::statement('analyze');

    return [$tracks[0], $cars[0], $selected];
}

function capturedQueries(callable $callback): array
{
    $captured = [];
    DB::listen(function ($query) use (&$captured): void {
        $captured[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });
    $callback();

    return $captured;
}

function planOf(array $query): string
{
    $explain = 'explain '.$query['sql'];

    return collect(DB::select($explain, $query['bindings']))->map(fn ($row): string => (string) ((array) $row)['QUERY PLAN'])->implode("\n");
}

test('the home tables stay within three queries each, never read laps and use indexes on large volumes', function () {
    [$track, $car, $heat] = seedVolume();
    $stats = app(HomeStats::class);

    $boards = [
        'all' => fn () => $stats->bestLaps(null, null, null),
        'track' => fn () => $stats->bestLaps($track->id, null, null, 2),
        'track and car' => fn () => $stats->bestLaps($track->id, $car->id, null, 3),
    ];

    foreach ($boards as $name => $board) {
        $queries = capturedQueries($board);

        expect(count($queries))->toBeLessThanOrEqual(3, "best laps ({$name})")
            ->and(collect($queries)->pluck('sql')->filter(fn (string $sql): bool => preg_match('/(from|join)\s+"laps"/i', $sql) === 1))->toBeEmpty();
    }

    $queries = capturedQueries(fn () => $stats->lastRaceDay($heat->id, null, 2));

    expect(count($queries))->toBeLessThanOrEqual(7)
        ->and(collect($queries)->pluck('sql')->filter(fn (string $sql): bool => preg_match('/(from|join)\s+"laps"/i', $sql) === 1))->toBeEmpty();
});

test('the paged queries of best laps and race results use an index and never scan the big tables sequentially', function () {
    [$track, $car, $heat] = seedVolume();
    $stats = app(HomeStats::class);

    $queries = [
        ...capturedQueries(fn () => $stats->bestLaps(null, null, null)),
        ...capturedQueries(fn () => $stats->bestLaps($track->id, null, null)),
        ...capturedQueries(fn () => $stats->bestLaps($track->id, $car->id, null)),
        ...capturedQueries(fn () => $stats->lastRaceDay($heat->id, null)),
    ];

    $checked = 0;

    foreach ($queries as $query) {
        $table = preg_match('/from\s+"(best_laps|race_results)"/i', $query['sql'], $match) === 1 ? $match[1] : null;

        if ($table === null || str_contains($query['sql'], 'group by')) {
            continue;
        }

        $plan = planOf($query);
        $checked++;

        expect($plan)->not->toContain("Seq Scan on {$table}")
            ->and($plan)->toMatch('/Index (Only )?Scan|Bitmap Index Scan/');
    }

    expect($checked)->toBe(5);
});
