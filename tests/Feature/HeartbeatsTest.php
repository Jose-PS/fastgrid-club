<?php

declare(strict_types=1);

use App\Actions\Heartbeats\CheckHeartbeats;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\HeartbeatSource;
use App\Jobs\RecordQueueHeartbeat;
use App\Models\Alert;
use App\Models\User;
use App\Support\Heartbeats;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));
});

function beatAll(): void
{
    foreach (HeartbeatSource::cases() as $source) {
        app(Heartbeats::class)->beat($source);
    }
}

function openAlertOf(AlertType $type): ?Alert
{
    return Alert::query()->open()->where('type', $type)->first();
}

test('the scheduler command and the queue job record their heartbeat at the current time', function () {
    $this->artisan('heartbeats:beat')->assertSuccessful();
    RecordQueueHeartbeat::dispatch();

    $heartbeats = app(Heartbeats::class);

    expect($heartbeats->lastBeat(HeartbeatSource::Scheduler)?->getTimestamp())->toBe(now()->getTimestamp())
        ->and($heartbeats->lastBeat(HeartbeatSource::Queue)?->getTimestamp())->toBe(now()->getTimestamp())
        ->and($heartbeats->lastBeat(HeartbeatSource::Backup))->toBeNull();
});

test('the scheduler beats, queues the heartbeat job and checks the heartbeats every minute', function () {
    $events = collect(app(Schedule::class)->events());

    $everyMinute = fn (string $needle): bool => $events->contains(
        fn ($event): bool => str_contains((string) ($event->command ?? $event->description), $needle) && $event->expression === '* * * * *',
    );

    expect($everyMinute('heartbeats:beat'))->toBeTrue()
        ->and($everyMinute('heartbeats:check'))->toBeTrue()
        ->and($everyMinute(RecordQueueHeartbeat::class))->toBeTrue();
});

test('a scheduler heartbeat older than five minutes opens one alert that closes when it beats again', function () {
    beatAll();
    $check = app(CheckHeartbeats::class);

    $this->travel(5)->minutes();
    $check->handle();
    expect(Alert::query()->count())->toBe(0);

    $this->travel(1)->minutes();
    app(Heartbeats::class)->beat(HeartbeatSource::Queue);
    app(Heartbeats::class)->beat(HeartbeatSource::Backup);
    $check->handle();
    $check->handle();

    $alert = openAlertOf(AlertType::SchedulerHeartbeatMissing);

    expect(Alert::query()->count())->toBe(1)
        ->and($alert)->not->toBeNull()
        ->and($alert->summary_key)->toBe('admin.alerts.schedulerHeartbeatMissing')
        ->and($alert->summary)->toContain('5');

    app(Heartbeats::class)->beat(HeartbeatSource::Scheduler);
    $check->handle();

    expect($alert->fresh()->status)->toBe(AlertStatus::Closed)
        ->and($alert->fresh()->closed_at)->not->toBeNull();
});

test('a queue that stops processing opens its own alert', function () {
    beatAll();

    $this->travel(6)->minutes();
    app(Heartbeats::class)->beat(HeartbeatSource::Scheduler);
    app(CheckHeartbeats::class)->handle();

    expect(openAlertOf(AlertType::QueueHeartbeatMissing))->not->toBeNull()
        ->and(openAlertOf(AlertType::SchedulerHeartbeatMissing))->toBeNull()
        ->and(openAlertOf(AlertType::BackupStale))->toBeNull();
});

test('a missing heartbeat only opens an alert once the grace period since the first check has passed', function () {
    $check = app(CheckHeartbeats::class);

    $check->handle();
    $this->travel(5)->minutes();
    $check->handle();

    expect(Alert::query()->count())->toBe(0);

    $this->travel(1)->minutes();
    $check->handle();

    expect(openAlertOf(AlertType::SchedulerHeartbeatMissing))->not->toBeNull()
        ->and(openAlertOf(AlertType::QueueHeartbeatMissing))->not->toBeNull()
        ->and(openAlertOf(AlertType::BackupStale))->toBeNull();
});

test('a backup older than 36 hours opens the backup alert and a new backup closes it', function () {
    Cache::forever('heartbeat:backup', (string) now()->getTimestamp());
    $check = app(CheckHeartbeats::class);

    $this->travel(36)->hours();
    beatAllButBackup();
    $check->handle();
    expect(openAlertOf(AlertType::BackupStale))->toBeNull();

    $this->travel(1)->minutes();
    beatAllButBackup();
    $check->handle();

    $alert = openAlertOf(AlertType::BackupStale);
    expect($alert)->not->toBeNull()->and($alert->summary)->toContain('36');

    Cache::forever('heartbeat:backup', (string) now()->getTimestamp());
    $check->handle();

    expect($alert->fresh()->status)->toBe(AlertStatus::Closed);
});

function beatAllButBackup(): void
{
    app(Heartbeats::class)->beat(HeartbeatSource::Scheduler);
    app(Heartbeats::class)->beat(HeartbeatSource::Queue);
}

test('the alive command fails when the heartbeat is missing or older than the maximum age', function () {
    $this->artisan('heartbeats:alive', ['source' => 'queue'])->assertFailed();

    app(Heartbeats::class)->beat(HeartbeatSource::Queue);
    $this->travel(179)->seconds();
    $this->artisan('heartbeats:alive', ['source' => 'queue'])->assertSuccessful();

    $this->travel(2)->seconds();
    $this->artisan('heartbeats:alive', ['source' => 'queue'])->assertFailed();
    $this->artisan('heartbeats:alive', ['source' => 'queue', '--max-age' => 600])->assertSuccessful();
    $this->artisan('heartbeats:alive', ['source' => 'nope'])->assertExitCode(2);
});

test('the admin summary shows each heartbeat as healthy or stale with its age and whether the backup went off site', function () {
    app(Heartbeats::class)->beat(HeartbeatSource::Scheduler);
    app(Heartbeats::class)->beat(HeartbeatSource::Queue, CarbonImmutable::now()->subMinutes(10));
    Cache::forever('heartbeat:backup', (string) CarbonImmutable::now()->subHours(2)->getTimestamp());
    Cache::forever(Heartbeats::BACKUP_OFFSITE_KEY, '0');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/index')
            ->where('heartbeats.0', [
                'source' => 'scheduler',
                'last_beat_at' => now()->toIso8601String(),
                'age_seconds' => 0,
                'healthy' => true,
                'offsite' => null,
            ])
            ->where('heartbeats.1.source', 'queue')
            ->where('heartbeats.1.age_seconds', 600)
            ->where('heartbeats.1.healthy', false)
            ->where('heartbeats.2.source', 'backup')
            ->where('heartbeats.2.age_seconds', 7200)
            ->where('heartbeats.2.healthy', true)
            ->where('heartbeats.2.offsite', false)
            ->has('pageTranslations', fn (Assert $translations) => $translations
                ->where('admin.heartbeats.title', __('admin.heartbeats.title'))
                ->etc()));
});

test('the admin summary shows every heartbeat as stale when nothing has beaten yet', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('heartbeats.0.healthy', false)
            ->where('heartbeats.0.age_seconds', null)
            ->where('heartbeats.1.healthy', false)
            ->where('heartbeats.2.healthy', false)
            ->where('heartbeats.2.offsite', null));
});
