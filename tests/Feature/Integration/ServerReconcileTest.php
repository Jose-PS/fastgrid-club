<?php

declare(strict_types=1);

use App\Actions\Servers\ApplyServerConfig;
use App\Actions\Servers\BuildConfigPlan;
use App\Actions\Servers\ReconcileServer;
use App\Actions\Weeks\ForceWeek;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\ConfigApplicationStatus;
use App\Enums\ConfigSource;
use App\Enums\RaceDurationType;
use App\Enums\ReconcileResult;
use App\Enums\TrackMode;
use App\Enums\WeatherType;
use App\Enums\WeekStatus;
use App\Integration\Server\Data\ConfigPlan;
use App\Integration\Server\Data\ConfigSnapshot;
use App\Integration\Server\Data\OperationResult;
use App\Integration\Server\Data\ServerSecrets;
use App\Integration\Server\FakeDriver;
use App\Integration\Server\ServerDriver;
use App\Integration\Server\ServerDriverManager;
use App\Mail\IntegrationFailingMail;
use App\Models\Alert;
use App\Models\Car;
use App\Models\ConfigApplication;
use App\Models\ScheduleSegment;
use App\Models\ScheduleVersion;
use App\Models\Server;
use App\Models\ServerDefaultConfig;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use App\Support\IngameName;
use App\Support\ScheduleWindow;
use App\Support\ServerSnapshot;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;

final class ReconcileTestDriver extends FakeDriver
{
    public static int $starts = 0;

    public static bool $failApply = false;

    public static bool $verifyMismatch = false;

    public static bool $zeroDurations = false;

    /** @var list<ConfigPlan> */
    public static array $plans = [];

    public function apply(ConfigPlan $plan, ?ServerSecrets $secrets = null): OperationResult
    {
        self::$plans[] = $plan;

        if (self::$failApply) {
            return new OperationResult(false, 'panel refused the apply');
        }

        return parent::apply($plan, $secrets);
    }

    public function currentConfig(): ConfigSnapshot
    {
        if (self::$verifyMismatch) {
            return new ConfigSnapshot(null, null, [], null, null, null, null, null, null);
        }

        $current = parent::currentConfig();

        if (self::$zeroDurations) {
            return new ConfigSnapshot($current->mode, $current->trackRef, $current->carRefs, $current->serverName, $current->maxPlayers, 0, 0, $current->practiceSeconds, $current->cycleEnabled);
        }

        return $current;
    }

    public function start(): OperationResult
    {
        self::$starts++;

        return parent::start();
    }
}

final class ReconcileTestDriverManager extends ServerDriverManager
{
    public function for(Server $server): ServerDriver
    {
        return new ReconcileTestDriver;
    }
}

function reconcileMadrid(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Europe/Madrid')->setTimezone('UTC');
}

function reconcileWindow(string $time): ScheduleWindow
{
    return app(WeekCalendar::class)->scheduleWindowAt(reconcileMadrid($time));
}

function reconcileVersion(string $effectiveFrom, array $segments): ScheduleVersion
{
    $version = ScheduleVersion::factory()->create(['effective_from' => CarbonImmutable::parse($effectiveFrom, 'Europe/Madrid')->setTimezone('UTC')]);

    foreach ($segments as $segment) {
        ScheduleSegment::factory()->create(array_merge(['schedule_version_id' => $version->id], $segment));
    }

    app(WeekCalendar::class)->forgetSchedule();

    return $version;
}

function reconcileAt(Server $server, CarbonImmutable|string $instant): ReconcileResult
{
    $now = is_string($instant) ? reconcileMadrid($instant) : $instant;
    CarbonImmutable::setTestNow($now);

    return app(ReconcileServer::class)->handle($server->fresh(), $now);
}

function reconcileTrack(array $attributes = []): Track
{
    return Track::factory()->withPracticePair()->create($attributes + ['max_pit_slot' => 30]);
}

function reconcileWeek(Server $server, string $mondayMadrid, ?Track $track, iterable $cars, array $attributes = []): Week
{
    $week = Week::factory()->for($server)->startingAt(reconcileMadrid($mondayMadrid))->status(WeekStatus::Scheduled)->create($attributes + [
        'track_id' => $track?->id,
        'max_players' => 20,
        'config_source' => ConfigSource::Proposal,
        'resolved_at' => reconcileMadrid($mondayMadrid)->subDay(),
    ]);

    $week->cars()->sync(collect($cars)->pluck('id')->all());

    return $week;
}

function snapshotAt(Server $server, bool $running, bool $reachable, CarbonImmutable $instant): void
{
    CarbonImmutable::setTestNow($instant);

    app(ServerSnapshot::class)->store($server, $running, 0, $reachable, $instant);
}

function appliedPlans(): array
{
    return ReconcileTestDriver::$plans;
}

function lastPlan(): ConfigPlan
{
    return ReconcileTestDriver::$plans[array_key_last(ReconcileTestDriver::$plans)];
}

beforeEach(function () {
    app()->instance(ServerDriverManager::class, new ReconcileTestDriverManager);
    ReconcileTestDriver::$starts = 0;
    ReconcileTestDriver::$failApply = false;
    ReconcileTestDriver::$verifyMismatch = false;
    ReconcileTestDriver::$zeroDurations = false;
    ReconcileTestDriver::$plans = [];

    $this->server = Server::factory()->create(['is_active' => true, 'max_players' => 24, 'ingame_prefix' => 'FastGrid']);
    $this->cars = Car::factory()->count(2)->create(['classes' => ['GT3']]);
    $this->track = reconcileTrack(['display' => 'Monza']);
    $this->week = reconcileWeek($this->server, '2026-11-09 00:00', $this->track, $this->cars);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('monday 00:00 applies the new week in practice mode', function () {
    $previousTrack = reconcileTrack(['display' => 'Spa']);
    reconcileWeek($this->server, '2026-11-02 00:00', $previousTrack, $this->cars);

    expect(reconcileAt($this->server, '2026-11-08 23:59:00'))->toBe(ReconcileResult::Applied)
        ->and(lastPlan()->trackRef)->toBe($previousTrack->pairedTrack->token);

    expect(reconcileAt($this->server, '2026-11-09 00:00:00'))->toBe(ReconcileResult::Applied);

    $plan = lastPlan();
    expect($plan->mode)->toBe(TrackMode::Practice)
        ->and($plan->trackRef)->toBe($this->track->pairedTrack->token)
        ->and($plan->cycleEnabled)->toBeFalse()
        ->and($plan->practiceSeconds)->toBe(7200)
        ->and($plan->maxPlayers)->toBe(20)
        ->and(ConfigApplication::query()->latest('id')->first()->block_key)->toBe(reconcileWindow('2026-11-09 00:00')->key);
});

test('saturday 18:00 applies the race block with cycles, 30 min qualifying and a 20 min timed race', function () {
    expect(reconcileAt($this->server, '2026-11-14 17:59:00'))->toBe(ReconcileResult::Applied)
        ->and(reconcileAt($this->server, '2026-11-14 17:59:40'))->toBe(ReconcileResult::UpToDate)
        ->and(reconcileAt($this->server, '2026-11-14 18:00:00'))->toBe(ReconcileResult::Applied);

    $plan = lastPlan();
    expect(appliedPlans())->toHaveCount(2)
        ->and($plan->mode)->toBe(TrackMode::RaceWeekend)
        ->and($plan->trackRef)->toBe($this->track->token)
        ->and($plan->cycleEnabled)->toBeTrue()
        ->and($plan->qualifyingSeconds)->toBe(1800)
        ->and($plan->raceSeconds)->toBe(1200)
        ->and($plan->practiceSeconds)->toBe(0)
        ->and($plan->maxPlayers)->toBe(20)
        ->and($plan->carRefs)->toBe($this->cars->pluck('internal_name')->sort()->values()->all())
        ->and(ConfigApplication::query()->latest('id')->first()->block)->toBe('race_weekend');
});

test('sunday 00:00 goes back to practice mode', function () {
    reconcileAt($this->server, '2026-11-14 23:59:00');

    expect(reconcileAt($this->server, '2026-11-15 00:00:00'))->toBe(ReconcileResult::Applied)
        ->and(lastPlan()->mode)->toBe(TrackMode::Practice)
        ->and(lastPlan()->cycleEnabled)->toBeFalse()
        ->and(ConfigApplication::query()->latest('id')->first()->block_key)->toBe(reconcileWindow('2026-11-15 00:00')->key);
});

test('it does not apply when the last ok application already matches', function () {
    ConfigApplication::factory()->ok()->create([
        'server_id' => $this->server->id,
        'week_id' => $this->week->id,
        'block_key' => reconcileWindow('2026-11-10 12:00')->key,
        'block' => 'practice',
        'attempt' => 1,
        'snapshot' => app(ApplyServerConfig::class)->snapshotFor(
            $this->server,
            app(BuildConfigPlan::class)->handle($this->server, $this->week->fresh(['track.pairedTrack', 'cars']), reconcileWindow('2026-11-10 12:00')),
        ),
    ]);

    expect(reconcileAt($this->server, '2026-11-10 12:00'))->toBe(ReconcileResult::UpToDate)
        ->and(appliedPlans())->toBe([]);
});

test('a practice apply whose durations the panel zeroes out verifies ok and is not applied again', function () {
    ReconcileTestDriver::$zeroDurations = true;

    expect(reconcileAt($this->server, '2026-11-10 12:00'))->toBe(ReconcileResult::Applied)
        ->and(reconcileAt($this->server, '2026-11-10 12:01'))->toBe(ReconcileResult::UpToDate)
        ->and(reconcileAt($this->server, '2026-11-10 12:11'))->toBe(ReconcileResult::UpToDate)
        ->and(appliedPlans())->toHaveCount(1)
        ->and(ConfigApplication::query()->where('status', ConfigApplicationStatus::Failed)->count())->toBe(0);
});

test('running twice in a row applies only once', function () {
    reconcileAt($this->server, '2026-11-10 12:00:05');
    reconcileAt($this->server, '2026-11-10 12:00:30');
    reconcileAt($this->server, '2026-11-10 12:01:00');

    expect(appliedPlans())->toHaveCount(1)
        ->and(ConfigApplication::query()->count())->toBe(1);
});

test('a configuration forced by the admin in the running week is applied on the next minute', function () {
    reconcileAt($this->server, '2026-11-10 12:00');
    $other = reconcileTrack(['display' => 'Imola']);
    $admin = User::factory()->admin()->create();

    app(ForceWeek::class)->handle($admin, $this->week->fresh(), $other->id, $this->cars->pluck('id')->all(), 'FastGrid | Noche de Imola', reconcileMadrid('2026-11-10 12:00:30'));

    expect(reconcileAt($this->server, '2026-11-10 12:01'))->toBe(ReconcileResult::Applied)
        ->and(lastPlan()->trackRef)->toBe($other->pairedTrack->token)
        ->and(lastPlan()->serverName)->toBe('FastGrid | Noche de Imola')
        ->and(reconcileAt($this->server, '2026-11-10 12:02'))->toBe(ReconcileResult::UpToDate)
        ->and(appliedPlans())->toHaveCount(2);
});

test('it does nothing while planning is paused', function () {
    $this->server->update(['planning_paused' => true]);

    expect(reconcileAt($this->server, '2026-11-14 18:00'))->toBe(ReconcileResult::Paused)
        ->and(appliedPlans())->toBe([])
        ->and(ConfigApplication::query()->count())->toBe(0);
});

test('a week without track or cars applies nothing and does not retry', function () {
    $this->week->update(['track_id' => null]);

    expect(reconcileAt($this->server, '2026-11-10 12:00'))->toBe(ReconcileResult::NoConfig)
        ->and(reconcileAt($this->server, '2026-11-10 12:01'))->toBe(ReconcileResult::NoConfig);

    $this->week->update(['track_id' => $this->track->id]);
    $this->week->cars()->detach();

    expect(reconcileAt($this->server, '2026-11-10 12:02'))->toBe(ReconcileResult::NoConfig)
        ->and(ConfigApplication::query()->count())->toBe(0)
        ->and(appliedPlans())->toBe([]);
});

test('a server without a week row applies nothing', function () {
    expect(reconcileAt($this->server, '2026-11-17 12:00'))->toBe(ReconcileResult::NoConfig)
        ->and(ConfigApplication::query()->count())->toBe(0);
});

test('the pending voting of the starting week is resolved before applying on monday', function () {
    Mail::fake();
    $next = Week::factory()->for($this->server)->startingAt(reconcileMadrid('2026-11-16 00:00'))->create(['status' => WeekStatus::Voting]);
    $defaultTrack = reconcileTrack(['display' => 'Suzuka']);
    $default = ServerDefaultConfig::factory()->for($this->server)->create(['track_id' => $defaultTrack->id]);
    $default->cars()->attach($this->cars->pluck('id'));

    expect(reconcileAt($this->server, '2026-11-16 00:00'))->toBe(ReconcileResult::Applied);

    expect($next->fresh()->status)->toBe(WeekStatus::Scheduled)
        ->and($next->fresh()->config_source)->toBe(ConfigSource::Fallback)
        ->and(lastPlan()->trackRef)->toBe($defaultTrack->pairedTrack->token)
        ->and(ConfigApplication::query()->latest('id')->first()->week_id)->toBe($next->id);
});

test('failed applies are retried at +1, +3, +5 and +10 minutes and then every 10 minutes', function () {
    ReconcileTestDriver::$failApply = true;
    $start = reconcileMadrid('2026-11-10 10:00:00');
    $attemptMinutes = [];

    for ($minute = 0; $minute <= 41; $minute++) {
        $result = reconcileAt($this->server, $start->addMinutes($minute)->addSeconds(2));

        if ($result === ReconcileResult::Failed) {
            $attemptMinutes[] = $minute;
        } else {
            expect($result)->toBe(ReconcileResult::Waiting);
        }
    }

    expect($attemptMinutes)->toBe([0, 1, 3, 5, 10, 20, 30, 40])
        ->and(ConfigApplication::query()->max('attempt'))->toBe(8)
        ->and(ConfigApplication::query()->where('status', ConfigApplicationStatus::Failed)->count())->toBe(8);
});

test('the fifth failure opens one alert with one mail and a later ok apply closes it', function () {
    Mail::fake();
    User::factory()->admin()->create();
    ReconcileTestDriver::$failApply = true;
    $start = reconcileMadrid('2026-11-10 10:00:00');

    foreach ([0, 1, 3, 5] as $minute) {
        reconcileAt($this->server, $start->addMinutes($minute));
    }

    Mail::assertNothingQueued();
    expect(Alert::query()->where('type', AlertType::IntegrationFailing)->count())->toBe(0);

    reconcileAt($this->server, $start->addMinutes(10));
    reconcileAt($this->server, $start->addMinutes(20));

    Mail::assertQueued(IntegrationFailingMail::class, 1);
    expect(Alert::query()->where('type', AlertType::IntegrationFailing)->where('status', AlertStatus::Open)->count())->toBe(1);

    ReconcileTestDriver::$failApply = false;

    expect(reconcileAt($this->server, $start->addMinutes(30)))->toBe(ReconcileResult::Applied)
        ->and(Alert::query()->where('type', AlertType::IntegrationFailing)->where('status', AlertStatus::Open)->count())->toBe(0)
        ->and(Alert::query()->where('type', AlertType::IntegrationFailing)->first()->closed_at)->not->toBeNull();
    Mail::assertQueued(IntegrationFailingMail::class, 1);
});

test('a failed verification after an ok apply counts as a failure and is retried', function () {
    ReconcileTestDriver::$verifyMismatch = true;

    expect(reconcileAt($this->server, '2026-11-10 10:00'))->toBe(ReconcileResult::Failed)
        ->and(ConfigApplication::query()->first()->error)->toContain('verification failed')
        ->and(reconcileAt($this->server, '2026-11-10 10:00:40'))->toBe(ReconcileResult::Waiting);

    ReconcileTestDriver::$verifyMismatch = false;

    expect(reconcileAt($this->server, '2026-11-10 10:01'))->toBe(ReconcileResult::Applied);
});

test('a stopped server is started when the applied config already matches', function () {
    reconcileAt($this->server, '2026-11-10 10:00');
    snapshotAt($this->server, false, true, reconcileMadrid('2026-11-10 10:05:00'));

    expect(reconcileAt($this->server, '2026-11-10 10:05:05'))->toBe(ReconcileResult::Started)
        ->and(ReconcileTestDriver::$starts)->toBe(1)
        ->and(appliedPlans())->toHaveCount(1);
});

test('the server is not started blindly without a fresh snapshot or right after an apply', function () {
    reconcileAt($this->server, '2026-11-10 10:00');

    expect(reconcileAt($this->server, '2026-11-10 10:05'))->toBe(ReconcileResult::UpToDate);

    snapshotAt($this->server, false, true, reconcileMadrid('2026-11-10 10:05:00'));

    expect(reconcileAt($this->server, '2026-11-10 10:06'))->toBe(ReconcileResult::UpToDate);

    snapshotAt($this->server, false, false, reconcileMadrid('2026-11-10 10:06:00'));

    expect(reconcileAt($this->server, '2026-11-10 10:06:05'))->toBe(ReconcileResult::UpToDate);

    snapshotAt($this->server, false, true, reconcileMadrid('2026-11-10 10:00:30'));

    expect(reconcileAt($this->server, '2026-11-10 10:00:40'))->toBe(ReconcileResult::UpToDate)
        ->and(ReconcileTestDriver::$starts)->toBe(0);
});

test('a server down for more than 3 minutes raises one alert and mail that close once it is back', function () {
    Mail::fake();
    User::factory()->admin()->create();
    reconcileAt($this->server, '2026-11-10 10:00');
    $start = reconcileMadrid('2026-11-10 10:10:00');

    foreach (range(0, 3) as $minute) {
        snapshotAt($this->server, false, true, $start->addMinutes($minute));
        reconcileAt($this->server, $start->addMinutes($minute)->addSeconds(5));
    }

    Mail::assertNothingQueued();

    snapshotAt($this->server, false, true, $start->addMinutes(4));
    reconcileAt($this->server, $start->addMinutes(4)->addSeconds(5));

    Mail::assertQueued(IntegrationFailingMail::class, 1);
    expect(ReconcileTestDriver::$starts)->toBe(5)
        ->and(Alert::query()->where('type', AlertType::IntegrationFailing)->where('status', AlertStatus::Open)->count())->toBe(1);

    snapshotAt($this->server, true, true, $start->addMinutes(5));

    expect(reconcileAt($this->server, $start->addMinutes(5)->addSeconds(5)))->toBe(ReconcileResult::UpToDate)
        ->and(Alert::query()->where('type', AlertType::IntegrationFailing)->where('status', AlertStatus::Open)->count())->toBe(0);
});

test('the autumn time change does not restart the server twice', function () {
    reconcileWeek($this->server, '2026-10-19 00:00', $this->track, $this->cars);

    reconcileAt($this->server, '2026-10-24 23:59');

    $instant = reconcileMadrid('2026-10-25 00:00');
    $end = reconcileMadrid('2026-10-25 05:00');
    $results = [];

    while ($instant->lessThan($end)) {
        $results[] = reconcileAt($this->server, $instant);
        $instant = $instant->addMinutes(10);
    }

    expect(collect($results)->filter(fn (ReconcileResult $result): bool => $result === ReconcileResult::Applied))->toHaveCount(1)
        ->and(ConfigApplication::query()->where('block_key', reconcileWindow('2026-10-25 00:00')->key)->count())->toBe(1)
        ->and(appliedPlans())->toHaveCount(2);
});

test('the spring time change applies the sunday block once', function () {
    reconcileWeek($this->server, '2027-03-22 00:00', $this->track, $this->cars);

    $instant = reconcileMadrid('2027-03-27 23:50');
    $end = reconcileMadrid('2027-03-28 05:00');

    while ($instant->lessThan($end)) {
        reconcileAt($this->server, $instant);
        $instant = $instant->addMinutes(10);
    }

    expect(ConfigApplication::query()->where('block_key', reconcileWindow('2027-03-27 19:00')->key)->count())->toBe(1)
        ->and(ConfigApplication::query()->where('block_key', reconcileWindow('2027-03-28 05:00')->key)->count())->toBe(1)
        ->and(appliedPlans())->toHaveCount(2);
});

test('the in-game name follows I3 for custom titles, a single car and a common class', function () {
    $plans = app(BuildConfigPlan::class);
    $week = fn (): Week => $this->week->fresh(['track.pairedTrack', 'cars']);

    $this->week->cars()->sync([$this->cars[0]->id]);
    expect($plans->handle($this->server, $week(), reconcileWindow('2026-11-10 12:00'))->serverName)
        ->toBe('FastGrid | '.$this->cars[0]->display_name.' @ Monza | Race Fair, Get Faster');

    $gt4 = Car::factory()->create(['classes' => ['GT4', 'GT3']]);
    $this->week->cars()->sync([$this->cars[0]->id, $gt4->id]);
    expect($plans->handle($this->server, $week(), reconcileWindow('2026-11-14 19:00'))->serverName)
        ->toBe('FastGrid | GT3 @ Monza | Race Fair, Get Faster');

    $this->week->update(['custom_title' => 'FastGrid | Copa otoño']);
    expect($plans->handle($this->server, $week(), reconcileWindow('2026-11-14 19:00'))->serverName)
        ->toBe('FastGrid | Copa otoño');
});

test('without a week there is no plan and the in-game name is the bare prefix', function () {
    expect(app(BuildConfigPlan::class)->handle($this->server, null, reconcileWindow('2026-11-10 12:00')))->toBeNull()
        ->and(app(IngameName::class)->for($this->server, null))->toBe('FastGrid | Race Fair, Get Faster');
});

test('the seeded schedule yields the same plans as the fixed blocks did', function () {
    $plans = app(BuildConfigPlan::class);
    $week = fn (): Week => $this->week->fresh(['track.pairedTrack', 'cars']);

    $tuesday = $plans->handle($this->server, $week(), reconcileWindow('2026-11-10 10:00'));
    $saturday = $plans->handle($this->server, $week(), reconcileWindow('2026-11-14 19:00'));
    $sunday = $plans->handle($this->server, $week(), reconcileWindow('2026-11-15 10:00'));

    expect($tuesday->mode)->toBe(TrackMode::Practice)
        ->and($tuesday->trackRef)->toBe($this->track->pairedTrack->token)
        ->and($tuesday->practiceSeconds)->toBe(7200)
        ->and($tuesday->qualifyingSeconds)->toBe(1800)
        ->and($tuesday->raceSeconds)->toBe(1200)
        ->and($tuesday->raceDurationType)->toBe(RaceDurationType::Time)
        ->and($tuesday->raceLaps)->toBeNull()
        ->and($tuesday->cycleEnabled)->toBeFalse()
        ->and($sunday->practiceSeconds)->toBe(7200)
        ->and($sunday->mode)->toBe(TrackMode::Practice)
        ->and($saturday->mode)->toBe(TrackMode::RaceWeekend)
        ->and($saturday->trackRef)->toBe($this->track->token)
        ->and($saturday->practiceSeconds)->toBe(0)
        ->and($saturday->qualifyingSeconds)->toBe(1800)
        ->and($saturday->raceSeconds)->toBe(1200)
        ->and($saturday->raceDurationType)->toBe(RaceDurationType::Time)
        ->and($saturday->raceLaps)->toBeNull()
        ->and($saturday->cycleEnabled)->toBeTrue();
});

test('a window with practice and a race by laps maps to a laps plan without qualifying', function () {
    ScheduleVersion::query()->delete();
    reconcileVersion('2026-01-01 00:00', [
        ['weekday' => 2, 'starts_at' => '10:00', 'practice_minutes' => 15, 'qualifying_minutes' => null, 'race_duration_type' => 'laps', 'race_laps' => 10, 'start_hour' => 9, 'weather' => WeatherType::Rain],
    ]);

    $plan = app(BuildConfigPlan::class)->handle($this->server, $this->week->fresh(['track.pairedTrack', 'cars']), reconcileWindow('2026-11-10 12:00'));

    expect($plan->mode)->toBe(TrackMode::RaceWeekend)
        ->and($plan->trackRef)->toBe($this->track->token)
        ->and($plan->practiceSeconds)->toBe(900)
        ->and($plan->qualifyingSeconds)->toBe(0)
        ->and($plan->raceDurationType)->toBe(RaceDurationType::Laps)
        ->and($plan->raceLaps)->toBe(10)
        ->and($plan->raceSeconds)->toBeNull()
        ->and($plan->startHour)->toBe(9)
        ->and($plan->weather)->toBe(WeatherType::Rain)
        ->and($plan->cycleEnabled)->toBeTrue();
});

test('a laps plan is applied and verified through the driver', function () {
    ScheduleVersion::query()->delete();
    reconcileVersion('2026-01-01 00:00', [
        ['weekday' => 2, 'starts_at' => '10:00', 'practice_minutes' => 15, 'qualifying_minutes' => 5, 'race_duration_type' => 'laps', 'race_laps' => 10],
    ]);

    expect(reconcileAt($this->server, '2026-11-10 12:00'))->toBe(ReconcileResult::Applied)
        ->and(reconcileAt($this->server, '2026-11-10 12:01'))->toBe(ReconcileResult::UpToDate)
        ->and(lastPlan()->raceDurationType)->toBe(RaceDurationType::Laps)
        ->and(ConfigApplication::query()->latest('id')->first()->snapshot['race_laps'])->toBe(10);
});

test('crossing into the next window generates a new key and applies again', function () {
    expect(reconcileAt($this->server, '2026-11-14 17:59:00'))->toBe(ReconcileResult::Applied);

    $before = ConfigApplication::query()->latest('id')->first()->block_key;

    expect(reconcileAt($this->server, '2026-11-14 18:00:00'))->toBe(ReconcileResult::Applied)
        ->and(ConfigApplication::query()->latest('id')->first()->block_key)->not->toBe($before)
        ->and(appliedPlans())->toHaveCount(2);
});

test('a new schedule version effective now generates a new key and applies on the next minute', function () {
    expect(reconcileAt($this->server, '2026-11-10 12:00'))->toBe(ReconcileResult::Applied)
        ->and(reconcileAt($this->server, '2026-11-10 12:00:30'))->toBe(ReconcileResult::UpToDate);

    $before = ConfigApplication::query()->latest('id')->first()->block_key;

    reconcileVersion('2026-11-10 12:00:30', [
        ['weekday' => 1, 'starts_at' => '00:00', 'practice_minutes' => 45],
    ]);

    expect(reconcileAt($this->server, '2026-11-10 12:01'))->toBe(ReconcileResult::Applied)
        ->and(ConfigApplication::query()->latest('id')->first()->block_key)->not->toBe($before)
        ->and(lastPlan()->practiceSeconds)->toBe(2700)
        ->and(appliedPlans())->toHaveCount(2);
});

test('a window with nothing to run produces no plan', function () {
    ScheduleVersion::query()->delete();
    reconcileVersion('2026-01-01 00:00', [
        ['weekday' => 1, 'starts_at' => '00:00', 'practice_minutes' => null],
    ]);

    expect(reconcileAt($this->server, '2026-11-10 12:00'))->toBe(ReconcileResult::NoConfig)
        ->and(appliedPlans())->toBe([]);
});

test('the max players of the plan falls back to the server when the week has none', function () {
    $this->week->update(['max_players' => null]);

    expect(app(BuildConfigPlan::class)->handle($this->server, $this->week->fresh(['track.pairedTrack', 'cars']), reconcileWindow('2026-11-14 19:00'))->maxPlayers)->toBe(24);
});

test('the reconcile command runs every active server', function () {
    CarbonImmutable::setTestNow(reconcileMadrid('2026-11-10 12:00'));
    Server::factory()->create(['is_active' => false]);

    $this->artisan('servers:reconcile')->assertSuccessful();

    expect(ConfigApplication::query()->count())->toBe(1)
        ->and(ConfigApplication::query()->first()->server_id)->toBe($this->server->id);
});

test('servers:reconcile is scheduled every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'servers:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
