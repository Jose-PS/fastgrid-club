<?php

declare(strict_types=1);

use App\Actions\Servers\ReapplyServerConfig;
use App\Enums\ReconcileResult;
use App\Models\AuditLog;
use App\Models\ScheduleSegment;
use App\Models\ScheduleVersion;
use App\Models\Server;
use App\Models\User;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));
    $this->admin = User::factory()->admin()->create();
    $this->active = Server::factory()->create(['is_active' => true]);
});

function segmentPayload(array $overrides = []): array
{
    return array_merge([
        'weekday' => 1,
        'starts_at' => '00:00',
        'practice_minutes' => 90,
        'qualifying_minutes' => null,
        'race_duration_type' => null,
        'race_minutes' => null,
        'race_laps' => null,
        'start_hour' => 14,
        'weather' => 'clear',
    ], $overrides);
}

function schedulePayload(array $segments = [], string $apply = 'tomorrow'): array
{
    return ['apply' => $apply, 'segments' => $segments === [] ? [segmentPayload()] : $segments];
}

test('guests and non admins get not found and admins can open the page', function () {
    $this->get(route('admin.schedule.index'))->assertNotFound();
    $this->post(route('admin.schedule.store'), schedulePayload())->assertNotFound();

    $this->actingAs(User::factory()->create());
    $this->get(route('admin.schedule.index'))->assertNotFound();
    $this->post(route('admin.schedule.store'), schedulePayload())->assertNotFound();

    expect(ScheduleVersion::query()->count())->toBe(1);

    $this->actingAs($this->admin)
        ->get(route('admin.schedule.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/schedule'));
});

test('the page shows the version in force with its segments', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.schedule.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('schedule.pending', false)
            ->where('schedule.effective_from', '01/01/2026 00:00')
            ->has('schedule.segments', 8)
            ->where('schedule.segments.0', segmentPayload(['practice_minutes' => 120])));
});

test('the page shows the pending future version instead of the current one', function () {
    $future = ScheduleVersion::factory()->create(['effective_from' => CarbonImmutable::parse('2026-11-12 00:00:00', 'Europe/Madrid')->utc()]);
    ScheduleSegment::factory()->create(['schedule_version_id' => $future->id, 'weekday' => 3, 'starts_at' => '10:30', 'practice_minutes' => null, 'race_duration_type' => 'laps', 'race_laps' => 12]);

    $this->actingAs($this->admin)
        ->get(route('admin.schedule.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('schedule.pending', true)
            ->where('schedule.effective_from', '12/11/2026 00:00')
            ->has('schedule.segments', 1)
            ->where('schedule.segments.0.starts_at', '10:30')
            ->where('schedule.segments.0.race_duration_type', 'laps')
            ->where('schedule.segments.0.race_laps', 12));
});

test('applying from tomorrow creates a version at tomorrow midnight in Madrid and does not reapply', function () {
    $this->mock(ReapplyServerConfig::class)->shouldNotReceive('handle');

    $this->actingAs($this->admin)
        ->post(route('admin.schedule.store'), schedulePayload())
        ->assertRedirect(route('admin.schedule.index'));

    $version = ScheduleVersion::query()->orderByDesc('id')->first();

    expect($version->effective_from->utc()->toDateTimeString())->toBe('2026-11-11 23:00:00')
        ->and($version->created_by)->toBe($this->admin->id)
        ->and(ScheduleVersion::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'schedule.saved')->sole()->actor_id)->toBe($this->admin->id);
});

test('applying now creates a version at the current instant and reapplies only active servers', function () {
    $inactive = Server::factory()->create(['is_active' => false]);

    $this->mock(ReapplyServerConfig::class)
        ->shouldReceive('handle')
        ->once()
        ->withArgs(fn (User $user, Server $server): bool => $user->is($this->admin) && $server->is($this->active))
        ->andReturn(ReconcileResult::Applied);

    $this->actingAs($this->admin)
        ->post(route('admin.schedule.store'), schedulePayload(apply: 'now'))
        ->assertRedirect(route('admin.schedule.index'));

    $version = ScheduleVersion::query()->orderByDesc('id')->first();

    expect($version->effective_from->utc()->toDateTimeString())->toBe('2026-11-11 11:00:00')
        ->and($version->created_by)->toBe($this->admin->id)
        ->and($inactive->is_active)->toBeFalse();
});

test('the saved segments are exactly the submitted ones including laps races', function () {
    $segments = [
        segmentPayload(['weekday' => 2, 'starts_at' => '00:00', 'practice_minutes' => 120, 'start_hour' => 9, 'weather' => 'heavy_rain']),
        segmentPayload(['weekday' => 2, 'starts_at' => '18:30', 'practice_minutes' => null, 'qualifying_minutes' => 30, 'race_duration_type' => 'laps', 'race_laps' => 10]),
        segmentPayload(['weekday' => 6, 'starts_at' => '20:00', 'practice_minutes' => 15, 'qualifying_minutes' => 20, 'race_duration_type' => 'time', 'race_minutes' => 45]),
    ];

    $this->actingAs($this->admin)->post(route('admin.schedule.store'), schedulePayload($segments))->assertSessionHasNoErrors();

    $saved = ScheduleVersion::query()->orderByDesc('id')->first()->segments->map(fn (ScheduleSegment $segment): array => [
        'weekday' => $segment->weekday,
        'starts_at' => substr($segment->starts_at, 0, 5),
        'practice_minutes' => $segment->practice_minutes,
        'qualifying_minutes' => $segment->qualifying_minutes,
        'race_duration_type' => $segment->race_duration_type?->value,
        'race_minutes' => $segment->race_minutes,
        'race_laps' => $segment->race_laps,
        'start_hour' => $segment->start_hour,
        'weather' => $segment->weather->value,
    ])->all();

    expect($saved)->toBe($segments);
});

test('the previous version is never modified', function () {
    $before = ScheduleSegment::query()->where('schedule_version_id', ScheduleVersion::query()->min('id'))->count();

    $this->actingAs($this->admin)->post(route('admin.schedule.store'), schedulePayload())->assertSessionHasNoErrors();

    expect(ScheduleSegment::query()->where('schedule_version_id', ScheduleVersion::query()->min('id'))->count())->toBe($before);
});

test('invalid schedules are rejected without creating a version', function (array $payload, string $field) {
    $this->actingAs($this->admin)
        ->post(route('admin.schedule.store'), $payload)
        ->assertSessionHasErrors($field);

    expect(ScheduleVersion::query()->count())->toBe(1);
})->with([
    'no segments' => [['apply' => 'tomorrow', 'segments' => []], 'segments'],
    'bad apply' => [fn () => schedulePayload(apply: 'later'), 'apply'],
    'weekday zero' => [fn () => schedulePayload([segmentPayload(['weekday' => 0])]), 'segments.0.weekday'],
    'weekday eight' => [fn () => schedulePayload([segmentPayload(['weekday' => 8])]), 'segments.0.weekday'],
    'bad time' => [fn () => schedulePayload([segmentPayload(['starts_at' => '25:00'])]), 'segments.0.starts_at'],
    'duplicate start' => [fn () => schedulePayload([segmentPayload(), segmentPayload(['practice_minutes' => 30])]), 'segments.1.starts_at'],
    'no session' => [fn () => schedulePayload([segmentPayload(['practice_minutes' => null])]), 'segments.0.practice_minutes'],
    'practice too long' => [fn () => schedulePayload([segmentPayload(['practice_minutes' => 601])]), 'segments.0.practice_minutes'],
    'practice zero' => [fn () => schedulePayload([segmentPayload(['practice_minutes' => 0])]), 'segments.0.practice_minutes'],
    'qualifying too long' => [fn () => schedulePayload([segmentPayload(['qualifying_minutes' => 121])]), 'segments.0.qualifying_minutes'],
    'unknown race type' => [fn () => schedulePayload([segmentPayload(['race_duration_type' => 'rounds'])]), 'segments.0.race_duration_type'],
    'time race without minutes' => [fn () => schedulePayload([segmentPayload(['race_duration_type' => 'time'])]), 'segments.0.race_minutes'],
    'time race too long' => [fn () => schedulePayload([segmentPayload(['race_duration_type' => 'time', 'race_minutes' => 241])]), 'segments.0.race_minutes'],
    'time race with laps' => [fn () => schedulePayload([segmentPayload(['race_duration_type' => 'time', 'race_minutes' => 20, 'race_laps' => 5])]), 'segments.0.race_laps'],
    'laps race without laps' => [fn () => schedulePayload([segmentPayload(['race_duration_type' => 'laps'])]), 'segments.0.race_laps'],
    'laps race too long' => [fn () => schedulePayload([segmentPayload(['race_duration_type' => 'laps', 'race_laps' => 201])]), 'segments.0.race_laps'],
    'laps race with minutes' => [fn () => schedulePayload([segmentPayload(['race_duration_type' => 'laps', 'race_laps' => 5, 'race_minutes' => 20])]), 'segments.0.race_minutes'],
    'hour below range' => [fn () => schedulePayload([segmentPayload(['start_hour' => -1])]), 'segments.0.start_hour'],
    'hour above range' => [fn () => schedulePayload([segmentPayload(['start_hour' => 24])]), 'segments.0.start_hour'],
    'missing hour' => [fn () => schedulePayload([segmentPayload(['start_hour' => null])]), 'segments.0.start_hour'],
    'unknown weather' => [fn () => schedulePayload([segmentPayload(['weather' => 'snow'])]), 'segments.0.weather'],
    'missing weather' => [fn () => schedulePayload([segmentPayload(['weather' => null])]), 'segments.0.weather'],
    'race values without type' => [fn () => schedulePayload([segmentPayload(['race_minutes' => 20])]), 'segments.0.race_duration_type'],
]);

test('applying now discards the pending future version with its segments and the new one is in force', function () {
    $this->mock(ReapplyServerConfig::class)->shouldReceive('handle')->andReturn(ReconcileResult::Applied);

    $pending = ScheduleVersion::factory()->create([
        'effective_from' => CarbonImmutable::parse('2026-11-12 00:00:00', 'Europe/Madrid')->setTimezone('UTC'),
    ]);
    ScheduleSegment::factory()->create(['schedule_version_id' => $pending->id, 'weekday' => 3, 'starts_at' => '10:30', 'practice_minutes' => 60]);

    $this->actingAs($this->admin)
        ->post(route('admin.schedule.store'), schedulePayload(apply: 'now'))
        ->assertSessionHasNoErrors();

    $current = ScheduleVersion::activeAt(CarbonImmutable::now('UTC'));

    expect(ScheduleVersion::query()->whereKey($pending->id)->exists())->toBeFalse()
        ->and(ScheduleSegment::query()->where('schedule_version_id', $pending->id)->exists())->toBeFalse()
        ->and($current->id)->not->toBe($pending->id)
        ->and($current->segments)->toHaveCount(1)
        ->and(ScheduleVersion::query()->where('effective_from', '>', CarbonImmutable::now('UTC'))->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'schedule.saved')->sole()->after['discarded_pending'])->toBe(1);
});

test('applying from tomorrow twice leaves only the last pending version', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.schedule.store'), schedulePayload([segmentPayload(['practice_minutes' => 60])]))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post(route('admin.schedule.store'), schedulePayload([
            segmentPayload(['practice_minutes' => 45]),
            segmentPayload(['starts_at' => '18:00', 'practice_minutes' => null, 'qualifying_minutes' => 30]),
        ]))
        ->assertSessionHasNoErrors();

    $pending = ScheduleVersion::query()->where('effective_from', '>', CarbonImmutable::now('UTC'))->get();

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->segments)->toHaveCount(2)
        ->and($pending->first()->segments->first()->practice_minutes)->toBe(45)
        ->and(ScheduleSegment::query()->count())->toBe(10);
});

test('past and seeded versions are never discarded when a new schedule is saved', function () {
    $this->mock(ReapplyServerConfig::class)->shouldReceive('handle')->andReturn(ReconcileResult::Applied);

    $seededId = ScheduleVersion::query()->min('id');
    $past = ScheduleVersion::factory()->create([
        'effective_from' => CarbonImmutable::parse('2026-11-10 00:00:00', 'Europe/Madrid')->setTimezone('UTC'),
    ]);
    ScheduleSegment::factory()->create(['schedule_version_id' => $past->id, 'weekday' => 2, 'starts_at' => '00:00', 'practice_minutes' => 90]);

    $this->actingAs($this->admin)
        ->post(route('admin.schedule.store'), schedulePayload(apply: 'now'))
        ->assertSessionHasNoErrors();

    expect(ScheduleVersion::query()->whereKey($seededId)->exists())->toBeTrue()
        ->and(ScheduleVersion::query()->whereKey($past->id)->exists())->toBeTrue()
        ->and(ScheduleSegment::query()->where('schedule_version_id', $past->id)->count())->toBe(1);
});

test('after applying now the shared calendar sees the new version when the servers are reapplied', function () {
    $calendar = app(WeekCalendar::class);
    $before = $calendar->scheduleWindowAt(CarbonImmutable::now('UTC'));

    $seenVersionId = null;

    $this->mock(ReapplyServerConfig::class)
        ->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function () use (&$seenVersionId): ReconcileResult {
            $seenVersionId = app(WeekCalendar::class)->scheduleWindowAt(CarbonImmutable::now('UTC'))->versionId;

            return ReconcileResult::Applied;
        });

    $this->actingAs($this->admin)
        ->post(route('admin.schedule.store'), schedulePayload(apply: 'now'))
        ->assertRedirect(route('admin.schedule.index'));

    $newVersion = ScheduleVersion::query()->orderByDesc('id')->first();

    expect($newVersion->id)->not->toBe($before->versionId)
        ->and($seenVersionId)->toBe($newVersion->id)
        ->and($calendar->scheduleWindowAt(CarbonImmutable::now('UTC'))->key)->toContain(":v{$newVersion->id}:");
});
