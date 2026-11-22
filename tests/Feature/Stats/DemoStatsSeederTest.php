<?php

declare(strict_types=1);

use App\Models\BestLap;
use App\Models\Driver;
use App\Models\RaceResult;
use App\Models\ServerSession;
use App\Support\HomeStats;
use Database\Seeders\DemoStatsSeeder;

test('the demo stats seeder fills the home data and can run twice', function () {
    $this->seed(DemoStatsSeeder::class);

    expect(Driver::query()->count())->toBe(60)
        ->and(BestLap::query()->count())->toBeGreaterThan(50)
        ->and(ServerSession::query()->where('type', 'race')->whereNotNull('cycle_index')->count())->toBe(6)
        ->and(RaceResult::query()->where('classified', false)->exists())->toBeTrue()
        ->and(RaceResult::query()->where('lapped', true)->where('classified', true)->exists())->toBeTrue();

    $day = app(HomeStats::class)->lastRaceDay(null, null);

    expect($day['heats'])->toHaveCount(6)
        ->and($day['rows'])->toHaveCount(20)
        ->and($day['hasGrid'])->toBeTrue();

    $this->seed(DemoStatsSeeder::class);

    expect(Driver::query()->count())->toBe(60)
        ->and(ServerSession::query()->count())->toBe(12)
        ->and(RaceResult::query()->count())->toBe(150);
});
