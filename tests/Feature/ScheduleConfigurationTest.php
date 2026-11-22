<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

dataset('frequent scheduled commands', [
    'servers:poll' => ['servers:poll', 2],
    'servers:ingest-logs' => ['servers:ingest-logs', 2],
    'servers:reconcile' => ['servers:reconcile', 5],
    'weeks:ensure' => ['weeks:ensure', 5],
    'heartbeats:check' => ['heartbeats:check', 5],
]);

test('frequent scheduled commands release their overlap mutex quickly', function (string $command, int $minutes) {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, $command));

    expect($event)->not->toBeNull()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe($minutes);
})->with('frequent scheduled commands');
