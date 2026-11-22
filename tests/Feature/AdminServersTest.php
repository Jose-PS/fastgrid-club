<?php

declare(strict_types=1);

use App\Actions\Integration\RecordIntegrationFailure;
use App\Actions\Servers\IngestServerLogs;
use App\Actions\Servers\PollServer;
use App\Actions\Servers\ReconcileServer;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\ConfigApplicationStatus;
use App\Enums\ConfigSource;
use App\Enums\IntegrationEventType;
use App\Enums\ReconcileResult;
use App\Enums\WeekStatus;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Car;
use App\Models\CatalogSync;
use App\Models\ConfigApplication;
use App\Models\IntegrationEvent;
use App\Models\LogCursor;
use App\Models\Server;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use App\Support\AdminServerStatus;
use App\Support\ServerSnapshot;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

const PANEL_PASSWORD = 'fixture-panel-password-9f3a';

beforeEach(function () {
    Cache::flush();
    config(['servers.panel-main' => ['url' => 'https://panel.internal.example', 'user' => 'panel-admin', 'password' => PANEL_PASSWORD]]);
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));
    $this->admin = User::factory()->admin()->create();
    $this->server = Server::factory()->create([
        'is_active' => true,
        'slug' => 'main',
        'name' => 'Main server',
        'credentials_ref' => 'panel-main',
        'base_url' => 'https://panel.internal.example',
        'max_players' => 24,
        'ingame_prefix' => 'FastGrid',
        'meta' => ['panel_version' => 'v1.1.0', 'image_version' => 'img-7', 'secret_token' => 'meta-secret-value'],
    ]);
});

function currentBlockKey(): string
{
    return app(WeekCalendar::class)->scheduleWindowAt(CarbonImmutable::now())->key;
}

function plannedWeek(Server $server): Week
{
    $track = Track::factory()->withPracticePair()->create(['max_pit_slot' => 30]);
    $cars = Car::factory()->count(2)->create();
    $week = Week::factory()->for($server)->startingAt(CarbonImmutable::parse('2026-11-09 00:00:00', 'Europe/Madrid'))->status(WeekStatus::Active)->create([
        'track_id' => $track->id,
        'max_players' => 20,
        'config_source' => ConfigSource::Proposal,
        'resolved_at' => now()->subDays(3),
    ]);
    $week->cars()->sync($cars->pluck('id')->all());

    return $week;
}

function storeSnapshot(Server $server, int $players, bool $running = true): void
{
    app(ServerSnapshot::class)->store($server, $running, $players, true, CarbonImmutable::now());
}

test('guests and non admins get not found on every server route', function () {
    $this->get(route('admin.servers.index'))->assertNotFound();
    $this->put(route('admin.servers.planning', $this->server), ['paused' => true])->assertNotFound();
    $this->post(route('admin.servers.reapply', $this->server))->assertNotFound();
    $this->post(route('admin.catalog.sync'))->assertNotFound();

    $this->actingAs(User::factory()->create());
    $this->get(route('admin.servers.index'))->assertNotFound();
    $this->put(route('admin.servers.planning', $this->server), ['paused' => true])->assertNotFound();
    $this->post(route('admin.servers.reapply', $this->server))->assertNotFound();
    $this->post(route('admin.catalog.sync'))->assertNotFound();

    expect($this->server->fresh()->planning_paused)->toBeFalse()
        ->and(ConfigApplication::query()->count())->toBe(0)
        ->and(CatalogSync::query()->count())->toBe(0);
});

test('an admin opens the servers screen without caching or indexing', function () {
    $response = $this->actingAs($this->admin)->get(route('admin.servers.index'));

    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertInertia(fn (Assert $page) => $page->component('admin/servers'));

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('the servers screen reports the polled state, the cursor, the ingest metrics and the histories', function () {
    storeSnapshot($this->server, 7);
    Cache::forever(PollServer::latencyKey($this->server), 123);
    Cache::forever(PollServer::lastSuccessKey($this->server), now()->subMinute()->toIso8601String());
    Cache::forever(IngestServerLogs::metricsKey($this->server), ['at' => now()->toIso8601String(), 'lines' => 50, 'laps' => 12, 'laps_discarded' => 3, 'laps_rejected' => 1]);
    LogCursor::factory()->create(['server_id' => $this->server->id, 'last_read_at' => now()->subSeconds(30), 'last_gap_at' => now()->subHour()]);
    IntegrationEvent::factory()->create(['server_id' => $this->server->id, 'type' => IntegrationEventType::LogGap, 'summary' => 'gap']);
    IntegrationEvent::factory()->create(['server_id' => $this->server->id, 'type' => IntegrationEventType::PollFailed, 'summary' => 'old', 'created_at' => now()->subDays(31)]);
    ConfigApplication::factory()->failed('boom')->create(['server_id' => $this->server->id, 'block_key' => currentBlockKey()]);

    $this->actingAs($this->admin)->get(route('admin.servers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('server.name', 'Main server')
            ->where('server.live.status', 'online')
            ->where('server.live.players', 7)
            ->where('server.latency_ms', 123)
            ->where('server.circuit.open', false)
            ->where('server.cursor.last_gap_at', fn ($value) => $value !== null)
            ->where('server.ingest.laps', 12)
            ->where('server.ingest.laps_discarded', 3)
            ->where('server.gaps_30d', 1)
            ->where('server.versions.panel', 'v1.1.0')
            ->where('server.versions.image', 'img-7')
            ->where('server.block', 'practice')
            ->where('server.block_key', currentBlockKey())
            ->where('server.pending_config', true)
            ->where('applications.total', 1)
            ->where('applications.data.0.error', 'boom')
            ->where('events.total', 1));
});

test('server and admin responses never include panel credentials or the panel address', function () {
    plannedWeek($this->server);
    ConfigApplication::factory()->ok()->create(['server_id' => $this->server->id, 'snapshot' => ['server_name' => 'FastGrid']]);

    foreach (['admin.servers.index', 'admin.index', 'admin.catalog.index', 'admin.weeks.index'] as $name) {
        $content = $this->actingAs($this->admin)->get(route($name))->assertOk()->getContent();

        expect($content)
            ->not->toContain(PANEL_PASSWORD)
            ->not->toContain('panel.internal.example')
            ->not->toContain('panel-main')
            ->not->toContain('meta-secret-value');
    }
});

test('pausing and resuming planning is audited and stops the automatic reconciliation', function () {
    plannedWeek($this->server);

    $this->actingAs($this->admin)->put(route('admin.servers.planning', $this->server), ['paused' => true])->assertRedirect();

    expect($this->server->fresh()->planning_paused)->toBeTrue()
        ->and(app(ReconcileServer::class)->handle($this->server->fresh()))->toBe(ReconcileResult::Paused)
        ->and(ConfigApplication::query()->count())->toBe(0);

    $audit = AuditLog::query()->where('action', 'server.planning_paused')->sole();
    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->before)->toBe(['planning_paused' => false])
        ->and($audit->after)->toBe(['planning_paused' => true]);

    $this->actingAs($this->admin)->put(route('admin.servers.planning', $this->server), ['paused' => true])->assertRedirect();
    expect(AuditLog::query()->where('action', 'server.planning_paused')->count())->toBe(1);

    $this->actingAs($this->admin)->put(route('admin.servers.planning', $this->server), ['paused' => false])->assertRedirect();

    expect($this->server->fresh()->planning_paused)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'server.planning_resumed')->count())->toBe(1)
        ->and(app(ReconcileServer::class)->handle($this->server->fresh()))->toBe(ReconcileResult::Applied);
});

test('the planning switch requires a boolean', function () {
    $this->actingAs($this->admin)->put(route('admin.servers.planning', $this->server), [])->assertSessionHasErrors('paused');
});

test('reapplying forces the apply even when the server already matches and is audited', function () {
    plannedWeek($this->server);
    storeSnapshot($this->server, 4);

    expect(app(ReconcileServer::class)->handle($this->server->fresh()))->toBe(ReconcileResult::Applied)
        ->and(app(ReconcileServer::class)->handle($this->server->fresh()))->toBe(ReconcileResult::UpToDate)
        ->and(ConfigApplication::query()->count())->toBe(1);

    $this->actingAs($this->admin)->post(route('admin.servers.reapply', $this->server))->assertRedirect();

    expect(ConfigApplication::query()->count())->toBe(2)
        ->and(ConfigApplication::query()->orderByDesc('id')->first()->status)->toBe(ConfigApplicationStatus::Ok);

    $audit = AuditLog::query()->where('action', 'server.reapplied')->sole();
    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->before)->toBe(['connected_players' => 4])
        ->and($audit->after)->toBe(['result' => 'applied']);
});

test('reapplying works while planning is paused and skips the retry wait after a failure', function () {
    plannedWeek($this->server);
    $this->server->update(['planning_paused' => true]);
    ConfigApplication::factory()->failed('earlier failure')->create([
        'server_id' => $this->server->id,
        'block_key' => currentBlockKey(),
        'snapshot' => ['stale' => true],
        'started_at' => now(),
    ]);

    $this->actingAs($this->admin)->post(route('admin.servers.reapply', $this->server))->assertRedirect();

    expect(ConfigApplication::query()->where('status', ConfigApplicationStatus::Ok)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'server.reapplied')->sole()->after)->toBe(['result' => 'applied']);
});

test('reapplying without a planned configuration is audited as no config', function () {
    $this->actingAs($this->admin)->post(route('admin.servers.reapply', $this->server))->assertRedirect();

    expect(ConfigApplication::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'server.reapplied')->sole()->after)->toBe(['result' => 'no_config']);
});

test('server actions on an inactive server are not found', function () {
    $inactive = Server::factory()->create(['is_active' => false]);

    $this->actingAs($this->admin)->put(route('admin.servers.planning', $inactive), ['paused' => true])->assertNotFound();
    $this->actingAs($this->admin)->post(route('admin.servers.reapply', $inactive))->assertNotFound();
});

test('server actions are rate limited', function () {
    $limit = (int) config('mvp.rate_limits.admin.per_minute');

    foreach (range(1, $limit) as $_) {
        $this->actingAs($this->admin)->put(route('admin.servers.planning', $this->server), ['paused' => true])->assertRedirect();
    }

    $this->actingAs($this->admin)->put(route('admin.servers.planning', $this->server), ['paused' => true])->assertStatus(429);
});

test('the admin summary reports the live state, the last sync, the last apply and the pending note', function () {
    storeSnapshot($this->server, 5);
    CatalogSync::factory()->create(['server_id' => $this->server->id, 'finished_at' => now()->subHours(2)]);
    ConfigApplication::factory()->ok()->create(['server_id' => $this->server->id, 'block_key' => '2026-W45:practice_weekdays']);

    $this->actingAs($this->admin)->get(route('admin.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('server.live.status', 'online')
            ->where('server.live.players', 5)
            ->where('server.block', 'practice')
            ->where('server.last_apply.status', 'ok')
            ->where('server.last_catalog_sync.finished_at', fn ($value) => $value !== null)
            ->where('server.pending_config', false));

    ConfigApplication::factory()->failed()->create(['server_id' => $this->server->id, 'block_key' => currentBlockKey()]);

    $this->actingAs($this->admin)->get(route('admin.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('server.pending_config', true)
            ->where('server.last_apply.status', 'failed'));
});

test('the weeks screen reports the connected players and whether planning is paused', function () {
    storeSnapshot($this->server, 9);

    $this->actingAs($this->admin)->get(route('admin.weeks.index'))
        ->assertInertia(fn (Assert $page) => $page->where('live.players', 9)->where('live.paused', false));

    $this->server->update(['planning_paused' => true]);
    Cache::forget(app(ServerSnapshot::class)->key($this->server));

    $this->actingAs($this->admin)->get(route('admin.weeks.index'))
        ->assertInertia(fn (Assert $page) => $page->where('live.players', null)->where('live.paused', true));
});

test('an integration failing alert says which event type is behind it', function () {
    foreach (range(1, 5) as $_) {
        app(RecordIntegrationFailure::class)->handle($this->server, IntegrationEventType::ApplyFailed, 'apply refused');
    }

    expect(Alert::query()->where('type', AlertType::IntegrationFailing)->where('status', AlertStatus::Open)->count())->toBe(1);

    $this->actingAs($this->admin)->get(route('admin.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('alerts.0.type', 'integration_failing')
            ->where('alerts.0.sources', ['apply_failed']));
});

test('the summary reports the race weekend mode and the window key during a race window', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 18:30:00', 'Europe/Madrid'));
    $key = app(WeekCalendar::class)->scheduleWindowAt(CarbonImmutable::now())->key;

    $summary = app(AdminServerStatus::class)->summary($this->server);

    expect($summary['block'])->toBe('race_weekend')
        ->and($summary['block_key'])->toBe($key)
        ->and($key)->toContain('2026-11-14T17:00Z');
});
