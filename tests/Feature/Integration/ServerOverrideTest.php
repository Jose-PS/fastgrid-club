<?php

declare(strict_types=1);

use App\Actions\Servers\ApplyServerConfig;
use App\Actions\Servers\ApplyServerOverride;
use App\Actions\Servers\BuildConfigPlan;
use App\Actions\Servers\BuildOverridePlan;
use App\Actions\Servers\EndServerOverride;
use App\Actions\Servers\ReconcileServer;
use App\Enums\ConfigApplicationStatus;
use App\Enums\ConfigSource;
use App\Enums\InitialGrip;
use App\Enums\RaceDurationType;
use App\Enums\ReconcileResult;
use App\Enums\ServerDriverType;
use App\Enums\ServerType;
use App\Enums\TrackMode;
use App\Enums\TuningType;
use App\Enums\WeatherBehaviour;
use App\Enums\WeatherType;
use App\Enums\WeekStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Integration\Server\Data\ConfigPlan;
use App\Integration\Server\Data\ConfigSnapshot;
use App\Integration\Server\Data\MandatoryPitstop;
use App\Integration\Server\Data\OperationResult;
use App\Integration\Server\Data\ServerSecrets;
use App\Integration\Server\Data\SessionSettings;
use App\Integration\Server\FakeDriver;
use App\Integration\Server\ServerDriver;
use App\Integration\Server\ServerDriverManager;
use App\Models\AuditLog;
use App\Models\Car;
use App\Models\ConfigApplication;
use App\Models\Server;
use App\Models\ServerOverride;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/ZinoPanelHelpers.php';

final class OvrTestDriver extends FakeDriver
{
    public static bool $verifyMismatch = false;

    public static bool $failApply = false;

    public function currentConfig(): ConfigSnapshot
    {
        if (self::$verifyMismatch) {
            return new ConfigSnapshot(null, null, [], null, null, null, null, null, null);
        }

        return parent::currentConfig();
    }

    public function apply(ConfigPlan $plan, ?ServerSecrets $secrets = null): OperationResult
    {
        if (self::$failApply) {
            return new OperationResult(false, 'panel refused it');
        }

        return parent::apply($plan, $secrets);
    }
}

final class OvrTestDriverManager extends ServerDriverManager
{
    public function for(Server $server): ServerDriver
    {
        return new OvrTestDriver;
    }
}

const OVR_SECRET_DRIVER = 'drv-Zx9-never-stored';
const OVR_SECRET_ADMIN = 'adm-Qw7-never-stored';

function ovrInput(array $overrides = []): array
{
    $base = [
        'server_name' => 'FastGrid | Open night',
        'max_players' => 20,
        'cycle_enabled' => false,
        'server_type' => 'unranked',
        'tuning_type' => 'allowed',
        'driver_password' => null,
        'admin_password' => null,
        'spectator_password' => null,
        'mode' => 'practice',
        'track_id' => test()->raceTrack->id,
        'weather' => 'rain',
        'weather_behaviour' => 'dynamic',
        'initial_grip' => 'green',
        'car_ids' => [test()->cars[0]->id],
        'until' => null,
        'sessions' => [
            'practice' => ['length_sec' => 900, 'hour' => 21, 'minute' => 30, 'time_multiplier' => 2, 'max_wait_to_box' => 15, 'overtime_waiting_next_session' => 12],
            'qualify' => ['length_sec' => 600, 'hour' => 21, 'minute' => 30, 'time_multiplier' => 1],
            'warmup' => ['length_sec' => 120, 'hour' => 21, 'minute' => 30, 'time_multiplier' => 1],
            'race' => [
                'length_sec' => 1800, 'hour' => 21, 'minute' => 30, 'time_multiplier' => 1, 'duration_type' => 'time', 'laps' => 10,
                'min_waiting_for_players' => 10, 'max_waiting_for_players' => 60,
                'mandatory_pitstop_enabled' => false, 'mandatory_pitstop_window_seconds' => 600,
                'mandatory_pitstop_refuel' => true, 'mandatory_pitstop_tyre_change' => true,
            ],
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

function ovrNow(): CarbonImmutable
{
    return CarbonImmutable::now();
}

function ovrReconcile(Server $server): ReconcileResult
{
    return app(ReconcileServer::class)->handle($server->fresh(), ovrNow());
}

function ovrZinoServer(): Server
{
    config(['servers.main' => ['url' => ZINO_URL, 'user' => ZINO_USER, 'password' => ZINO_PASS]]);

    return Server::factory()->create([
        'driver' => ServerDriverType::ZinoPanel,
        'credentials_ref' => 'main',
        'is_active' => true,
        'ingame_prefix' => 'FastGrid',
        'max_players' => 24,
    ]);
}

function ovrLegacyPlan(array $overrides = []): ConfigPlan
{
    return new ConfigPlan(...($overrides + [
        'mode' => TrackMode::Practice,
        'trackRef' => 'Monza|GP|GP Time Attack|5793',
        'carRefs' => ['preset_695b_mech_1', 'preset_r8gt2_mech_1'],
        'serverName' => 'FastGrid | Semana 3',
        'maxPlayers' => 20,
        'qualifyingSeconds' => 1800,
        'raceSeconds' => 1200,
        'raceDurationType' => RaceDurationType::Time,
        'raceLaps' => null,
        'practiceSeconds' => 7200,
        'cycleEnabled' => false,
        'startHour' => 14,
        'weather' => WeatherType::Clear,
    ]));
}

function ovrDump(): string
{
    return json_encode([
        DB::table('server_overrides')->get(),
        DB::table('config_applications')->get(),
        DB::table('audit_logs')->get(),
        DB::table('integration_events')->get(),
    ], JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));
    $this->admin = User::factory()->admin()->create();
    $this->server = Server::factory()->create(['is_active' => true, 'ingame_prefix' => 'FastGrid', 'max_players' => 24]);
    $practiceTrack = Track::factory()->practice()->create(['track' => 'Monza', 'layout' => 'GP', 'length_m' => 5793, 'token' => 'Monza|GP|GP Time Attack|5793', 'max_pit_slot' => 24]);
    $this->raceTrack = Track::factory()->create(['track' => 'Monza', 'layout' => 'GP', 'length_m' => 5793, 'token' => 'Monza|GP|GP Race|5793', 'max_pit_slot' => 24, 'paired_track_id' => $practiceTrack->id]);
    $this->cars = collect(['preset_695b_mech_1', 'preset_r8gt2_mech_1'])->map(fn (string $name): Car => Car::factory()->create(['internal_name' => $name]))->values();
    $week = Week::factory()->for($this->server)->startingAt(CarbonImmutable::parse('2026-11-09 00:00:00', 'Europe/Madrid'))->status(WeekStatus::Active)->create([
        'track_id' => $this->raceTrack->id,
        'max_players' => 20,
        'config_source' => ConfigSource::Proposal,
        'resolved_at' => now()->subDays(3),
    ]);
    $week->cars()->sync($this->cars->pluck('id')->all());
});

test('a schedule plan keeps exactly the snapshot format it had before the override fields existed', function () {
    $apply = app(ApplyServerConfig::class);

    $practice = $apply->snapshotFor($this->server, ovrLegacyPlan());
    $race = $apply->snapshotFor($this->server, ovrLegacyPlan([
        'mode' => TrackMode::RaceWeekend,
        'trackRef' => 'Monza|GP|GP Race|5793',
        'raceSeconds' => null,
        'raceDurationType' => RaceDurationType::Laps,
        'raceLaps' => 12,
        'practiceSeconds' => 0,
        'cycleEnabled' => true,
        'startHour' => 9,
        'weather' => WeatherType::HeavyRain,
    ]));

    expect($practice)->toBe([
        'mode' => 'practice',
        'track_ref' => 'Monza|GP|GP Time Attack|5793',
        'car_refs' => ['preset_695b_mech_1', 'preset_r8gt2_mech_1'],
        'server_name' => 'FastGrid | Semana 3',
        'max_players' => 20,
        'qualifying_seconds' => 1800,
        'race_seconds' => 1200,
        'race_duration_type' => 'time',
        'race_laps' => null,
        'practice_seconds' => 7200,
        'cycle_enabled' => false,
        'start_hour' => 14,
        'weather' => 'clear',
    ])->and($race)->toBe([
        'mode' => 'race_weekend',
        'track_ref' => 'Monza|GP|GP Race|5793',
        'car_refs' => ['preset_695b_mech_1', 'preset_r8gt2_mech_1'],
        'server_name' => 'FastGrid | Semana 3',
        'max_players' => 20,
        'qualifying_seconds' => 1800,
        'race_seconds' => null,
        'race_duration_type' => 'laps',
        'race_laps' => 12,
        'practice_seconds' => 0,
        'cycle_enabled' => true,
        'start_hour' => 9,
        'weather' => 'heavy_rain',
    ]);
});

test('the plan the schedule builds produces no override keys in its snapshot', function () {
    $week = Week::query()->with(['track.pairedTrack', 'cars'])->first();
    $window = app(WeekCalendar::class)->scheduleWindowAt(ovrNow());
    $plan = app(BuildConfigPlan::class)->handle($this->server, $week, $window);

    expect(array_keys(app(ApplyServerConfig::class)->snapshotFor($this->server, $plan)))->toBe([
        'mode', 'track_ref', 'car_refs', 'server_name', 'max_players', 'qualifying_seconds', 'race_seconds',
        'race_duration_type', 'race_laps', 'practice_seconds', 'cycle_enabled', 'start_hour', 'weather',
    ]);
});

test('an indefinite override is applied right away and the reconciliation keeps it without applying again', function () {
    $result = app(ApplyServerOverride::class)->handle($this->admin, $this->server, ovrInput());

    expect($result->ok())->toBeTrue();

    $override = $result->override;
    expect($override->until)->toBeNull()
        ->and(ConfigApplication::query()->count())->toBe(1)
        ->and(ConfigApplication::query()->sole()->block_key)->toBe("override:{$override->key}")
        ->and(ConfigApplication::query()->sole()->status)->toBe(ConfigApplicationStatus::Ok);

    expect(ovrReconcile($this->server))->toBe(ReconcileResult::UpToDate);

    $this->travelTo(ovrNow()->addHours(6));

    expect(ovrReconcile($this->server))->toBe(ReconcileResult::UpToDate)
        ->and(ConfigApplication::query()->count())->toBe(1);
});

test('an override whose until has passed hands the server back to the schedule slot', function () {
    $result = app(ApplyServerOverride::class)->handle($this->admin, $this->server, ovrInput(['until' => '2026-11-11T14:00']));

    expect($result->override->until->toIso8601String())->toBe('2026-11-11T13:00:00+00:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-11 13:59:00', 'Europe/Madrid'));
    expect(ovrReconcile($this->server))->toBe(ReconcileResult::UpToDate);

    $this->travelTo(CarbonImmutable::parse('2026-11-11 14:00:30', 'Europe/Madrid'));
    expect(ovrReconcile($this->server))->toBe(ReconcileResult::Applied);

    $last = ConfigApplication::query()->latest('id')->first();
    $window = app(WeekCalendar::class)->scheduleWindowAt(ovrNow());

    expect($last->block_key)->toBe($window->key)
        ->and($last->snapshot['server_name'])->not->toBe('FastGrid | Open night')
        ->and($last->snapshot['track_ref'])->toBe('Monza|GP|GP Time Attack|5793')
        ->and(ovrReconcile($this->server))->toBe(ReconcileResult::UpToDate);
});

test('cutting an override by hand goes back to the schedule slot and is audited', function () {
    $override = app(ApplyServerOverride::class)->handle($this->admin, $this->server, ovrInput())->override;

    $result = app(EndServerOverride::class)->handle($this->admin, $this->server);

    expect($result)->toBe(ReconcileResult::Applied)
        ->and($override->fresh()->ended_at)->not->toBeNull()
        ->and(ConfigApplication::query()->latest('id')->first()->block_key)->not->toStartWith('override:')
        ->and(AuditLog::query()->where('action', 'server.override_ended')->count())->toBe(1)
        ->and(app(EndServerOverride::class)->handle($this->admin, $this->server))->toBeNull();
});

test('a new override closes the previous one', function () {
    $first = app(ApplyServerOverride::class)->handle($this->admin, $this->server, ovrInput())->override;
    $second = app(ApplyServerOverride::class)->handle($this->admin, $this->server, ovrInput(['server_name' => 'FastGrid | Second']))->override;

    expect($first->fresh()->ended_at)->not->toBeNull()
        ->and($second->fresh()->ended_at)->toBeNull()
        ->and(ServerOverride::query()->active(ovrNow())->pluck('id')->all())->toBe([$second->id]);
});

test('planning paused still wins over an active override', function () {
    app(ApplyServerOverride::class)->handle($this->admin, $this->server, ovrInput());
    $this->server->update(['planning_paused' => true]);

    expect(ovrReconcile($this->server))->toBe(ReconcileResult::Paused);
});

test('an override the panel rejects is not kept and the previous one stays active', function () {
    $first = app(ApplyServerOverride::class)->handle($this->admin, $this->server, ovrInput())->override;

    $zino = ovrZinoServer();
    zinoFake(['POST /api/server/apply' => Http::response(['ok' => false, 'error' => 'bad launcher config'])]);

    $result = app(ApplyServerOverride::class)->handle($this->admin, $zino, ovrInput());

    expect($result->ok())->toBeFalse()
        ->and($result->error)->toContain('bad launcher config')
        ->and(ServerOverride::query()->where('server_id', $zino->id)->count())->toBe(0)
        ->and($first->fresh()->ended_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'server.override_failed')->count())->toBe(1);
});

test('passwords go to the panel but never to the database, the audit log or the snapshots', function () {
    $zino = ovrZinoServer();
    zinoFake();

    $result = app(ApplyServerOverride::class)->handle($this->admin, $zino, ovrInput([
        'driver_password' => OVR_SECRET_DRIVER,
        'admin_password' => OVR_SECRET_ADMIN,
        'clear_spectator_password' => true,
    ]));

    expect($result->ok())->toBeTrue();

    $sent = null;
    Http::assertSent(function (Request $request) use (&$sent): bool {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/api/server/apply')) {
            $sent = $request->data()['form']['server'];
        }

        return true;
    });

    expect($sent['driver_password'])->toBe(OVR_SECRET_DRIVER)
        ->and($sent['admin_password'])->toBe(OVR_SECRET_ADMIN)
        ->and($sent['spectator_password'])->toBe('');

    $dump = ovrDump();
    expect($dump)->not->toContain(OVR_SECRET_DRIVER)->not->toContain(OVR_SECRET_ADMIN)->not->toContain('password');
});

test('the driver writes the override fields and leaves empty passwords as the panel has them', function () {
    $zino = ovrZinoServer();
    zinoFake();

    $plan = ovrLegacyPlan([
        'mode' => TrackMode::RaceWeekend,
        'trackRef' => 'Monza|GP|GP Race|5793',
        'raceSeconds' => 1800,
        'practiceSeconds' => 0,
        'warmupSeconds' => 120,
        'serverType' => ServerType::Ranked,
        'tuningType' => TuningType::Denied,
        'weatherBehaviour' => WeatherBehaviour::Dynamic,
        'initialGrip' => InitialGrip::Fast,
        'sessionSettings' => ['race' => new SessionSettings(20, 45, 3, 15, 12)],
        'minWaitingForPlayers' => 5,
        'maxWaitingForPlayers' => 40,
        'mandatoryPitstop' => new MandatoryPitstop(true, 900, false, true),
    ]);

    expect(zinoDriver($zino)->apply($plan, new ServerSecrets(null, OVR_SECRET_ADMIN, null))->ok)->toBeTrue();

    $form = null;
    Http::assertSent(function (Request $request) use (&$form): bool {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/api/server/apply')) {
            $form = $request->data()['form'];
        }

        return true;
    });

    expect($form['server']['server_type'])->toBe('MultiplayerServerListSessionType_RANKED')
        ->and($form['server']['tuning_type'])->toBe('TuningDenied')
        ->and($form['server']['driver_password'])->toBe('fixture-secret-driver_password')
        ->and($form['server']['admin_password'])->toBe(OVR_SECRET_ADMIN)
        ->and($form['server']['spectator_password'])->toBe('fixture-secret-spectator_password')
        ->and($form['event']['weather_behaviour'])->toBe('GameModeSelectionWeatherBehaviour_DYNAMIC')
        ->and($form['event']['initial_grip'])->toBe('InitialGrip_FAST')
        ->and($form['sessions']['race'])->toMatchArray(['hour' => 20, 'minute' => 45, 'time_multiplier' => 3, 'max_wait_to_box' => 15, 'overtime_waiting_next_session' => 12, 'min_waiting_for_players' => 5, 'max_waiting_for_players' => 40, 'mandatory_pitstop_enabled' => true, 'mandatory_pitstop_window_seconds' => 900, 'mandatory_pitstop_refuel' => false, 'mandatory_pitstop_tyre_change' => true])
        ->and($form['sessions']['practice'])->toMatchArray(['hour' => 14, 'minute' => 0, 'time_multiplier' => 1])
        ->and($form['sessions']['warmup']['length_sec'])->toBe(120);
});

test('max players above the pit slots of the track are rejected', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.override.store'), ovrInput(['max_players' => 25]))
        ->assertSessionHasErrors('max_players');

    $this->raceTrack->update(['max_pit_slot' => 24]);
    $this->raceTrack->pairedTrack->update(['max_pit_slot' => 16]);

    expect(fn () => app(BuildOverridePlan::class)->handle($this->server, ovrInput(['max_players' => 20])))->toThrow(ValidationException::class);
    expect(ServerOverride::query()->count())->toBe(0);
});

test('a practice mode override without practice time is rejected and a race weekend one may skip practice', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.override.store'), ovrInput(['sessions' => ['practice' => ['length_sec' => 0]]]))
        ->assertSessionHasErrors('sessions.practice.length_sec');

    $plan = app(BuildOverridePlan::class)->handle($this->server, ovrInput(['mode' => 'race_weekend', 'sessions' => ['practice' => ['length_sec' => 0]]]));

    expect($plan->practiceSeconds)->toBe(0)->and($plan->mode)->toBe(TrackMode::RaceWeekend);
});

test('the mandatory pit stop is forced off unless it is a timed race weekend race over 1200 seconds', function () {
    $build = fn (array $overrides) => app(BuildOverridePlan::class)->handle($this->server, ovrInput($overrides))->mandatoryPitstop;
    $pit = fn (array $race) => ['mode' => 'race_weekend', 'sessions' => ['race' => $race + ['mandatory_pitstop_enabled' => true]]];

    expect($build($pit(['length_sec' => 1200]))->enabled)->toBeFalse()
        ->and($build($pit(['length_sec' => 1800, 'duration_type' => 'laps', 'laps' => 10]))->enabled)->toBeFalse()
        ->and($build(['mode' => 'practice', 'sessions' => ['race' => ['mandatory_pitstop_enabled' => true]]])->enabled)->toBeFalse()
        ->and($build($pit(['length_sec' => 1800]))->enabled)->toBeTrue();

    expect(fn () => $build($pit(['length_sec' => 1800, 'mandatory_pitstop_window_seconds' => 1900])))->toThrow(ValidationException::class);
});

test('the override page is reserved to admins', function () {
    $this->get(route('admin.override.show'))->assertNotFound();
    $this->post(route('admin.override.store'), ovrInput())->assertNotFound();
    $this->delete(route('admin.override.destroy'))->assertNotFound();

    $this->actingAs(User::factory()->create());
    $this->get(route('admin.override.show'))->assertNotFound();
    $this->post(route('admin.override.store'), ovrInput())->assertNotFound();
    $this->delete(route('admin.override.destroy'))->assertNotFound();

    expect(ServerOverride::query()->count())->toBe(0);
});

function ovrPage(): TestResponse
{
    return test()->actingAs(test()->admin)->get(route('admin.override.show'), ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())]);
}

test('the page prefills from the panel, with the race track and the car ids, and falls back to null when the panel is down', function () {
    ovrZinoServer();
    $this->server->update(['is_active' => false]);
    $carId = Car::factory()->create(['internal_name' => 'preset_e46csl_mech_1'])->id;
    zinoFake();

    $page = ovrPage()->assertOk()->json();

    expect($page['component'])->toBe('admin/server-override')
        ->and($page['props']['configError'])->toBeNull()
        ->and($page['props']['override'])->toBeNull()
        ->and($page['props']['config']['mode'])->toBe('race_weekend')
        ->and($page['props']['config']['driver_password'])->toBe('fixture-secret-driver_password')
        ->and($page['props']['config']['server_type'])->toBe('unranked')
        ->and($page['props']['config']['car_ids'])->toBe([$carId])
        ->and($page['props']['config']['sessions']['race']['duration_type'])->toBe('time')
        ->and($page['props']['options']['tracks'])->not->toBeEmpty()
        ->and($page['props']['options']['cars'])->not->toBeEmpty();

    Http::swap(new Factory);
    Http::fake(fn () => Http::response([], 500));

    $down = ovrPage()->assertOk()->json();

    expect($down['props']['config'])->toBeNull()->and($down['props']['configError'])->not->toBeNull();
});

test('the page exposes the active override without any password', function () {
    app(ApplyServerOverride::class)->handle($this->admin, $this->server, ovrInput(['driver_password' => OVR_SECRET_DRIVER]));

    $response = ovrPage()->assertOk();
    $override = $response->json('props.override');

    expect($override['created_by'])->toBe($this->admin->name)
        ->and($override['until'])->toBeNull()
        ->and($override['summary']['server_name'])->toBe('FastGrid | Open night')
        ->and($response->getContent())->not->toContain(OVR_SECRET_DRIVER);
});

test('a schedule plan after an override resets the race format fields and keeps the server ones', function () {
    $zino = ovrZinoServer();
    zinoFake();
    $driver = zinoDriver($zino);

    $driver->apply(ovrLegacyPlan(['serverType' => ServerType::Ranked, 'initialGrip' => InitialGrip::Green, 'warmupSeconds' => 120, 'sessionSettings' => ['race' => new SessionSettings(null, null, null, 15, null)], 'mandatoryPitstop' => new MandatoryPitstop(true, 900, false, false)]));
    $driver->apply(ovrLegacyPlan());

    $form = null;
    Http::assertSent(function (Request $request) use (&$form): bool {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/api/server/apply')) {
            $form = $request->data()['form'];
        }

        return true;
    });

    expect($form['event']['initial_grip'])->toBe('InitialGrip_OPTIMUM')
        ->and($form['server']['server_type'])->toBe('MultiplayerServerListSessionType_RANKED')
        ->and($form['sessions']['race'])->toMatchArray(['mandatory_pitstop_enabled' => false, 'mandatory_pitstop_window_seconds' => 600, 'mandatory_pitstop_refuel' => true, 'mandatory_pitstop_tyre_change' => true, 'min_waiting_for_players' => 10, 'max_waiting_for_players' => 60, 'max_wait_to_box' => 10])
        ->and($form['sessions']['warmup']['length_sec'])->toBe(0);
});

test('after a manual apply that failed verification the next reconcile applies the schedule again on the same server', function () {
    app()->instance(ServerDriverManager::class, new OvrTestDriverManager);
    OvrTestDriver::$verifyMismatch = false;

    expect(ovrReconcile($this->server))->toBe(ReconcileResult::Applied);

    OvrTestDriver::$verifyMismatch = true;
    $failed = app(ApplyServerOverride::class)->handle($this->admin, $this->server, ovrInput());
    OvrTestDriver::$verifyMismatch = false;

    expect($failed->ok())->toBeFalse()
        ->and(ServerOverride::query()->count())->toBe(0)
        ->and(ovrReconcile($this->server))->toBe(ReconcileResult::Applied)
        ->and(ConfigApplication::query()->latest('id')->first()->block_key)->not->toStartWith('override:')
        ->and(ovrReconcile($this->server))->toBe(ReconcileResult::UpToDate);
});

test('the override row only exists once the apply went through', function () {
    app()->instance(ServerDriverManager::class, new OvrTestDriverManager);
    OvrTestDriver::$failApply = true;

    $result = app(ApplyServerOverride::class)->handle($this->admin, $this->server, ovrInput());
    OvrTestDriver::$failApply = false;

    expect($result->ok())->toBeFalse()->and(ServerOverride::query()->count())->toBe(0);
});

test('a validation failure never flashes the passwords into the session', function (array $overrides, string $field) {
    $this->actingAs($this->admin)
        ->post(route('admin.override.store'), ovrInput($overrides + ['driver_password' => OVR_SECRET_DRIVER, 'admin_password' => OVR_SECRET_ADMIN]))
        ->assertSessionHasErrors($field);

    expect(json_encode(session()->all()))->not->toContain(OVR_SECRET_DRIVER)->not->toContain(OVR_SECRET_ADMIN);
})->with([
    'request rules' => [['server_name' => ''], 'server_name'],
    'plan rules' => [['max_players' => 25], 'max_players'],
]);

test('cutting an override while planning is paused only ends it and does not touch the server', function () {
    app(ApplyServerOverride::class)->handle($this->admin, $this->server, ovrInput());
    $this->server->update(['planning_paused' => true]);
    $applications = ConfigApplication::query()->count();

    $this->actingAs($this->admin)->delete(route('admin.override.destroy'))->assertRedirect();

    expect(ConfigApplication::query()->count())->toBe($applications)
        ->and(ServerOverride::query()->active(ovrNow())->count())->toBe(0)
        ->and(ovrPage()->json('flash.toast.message'))->toBe(__('admin.override.endedPaused'));
});

test('cutting an override whose return to the schedule fails reports the panel reason', function () {
    app()->instance(ServerDriverManager::class, new OvrTestDriverManager);
    OvrTestDriver::$failApply = false;
    app(ApplyServerOverride::class)->handle($this->admin, $this->server, ovrInput());
    OvrTestDriver::$failApply = true;

    $this->actingAs($this->admin)->delete(route('admin.override.destroy'))->assertRedirect();
    OvrTestDriver::$failApply = false;

    expect(ovrPage()->json('flash.toast.message'))->toBe(__('admin.override.endedFailed', ['error' => 'panel refused it']));
});
