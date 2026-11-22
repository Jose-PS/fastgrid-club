<?php

declare(strict_types=1);

use App\Actions\Catalog\SyncCatalog;
use App\Enums\IntegrationEventType;
use App\Enums\TrackMode;
use App\Integration\Server\Data\Catalog;
use App\Integration\Server\ZinoMetadata;
use App\Models\AuditLog;
use App\Models\Car;
use App\Models\CatalogSync;
use App\Models\IntegrationEvent;
use App\Models\Proposal;
use App\Models\Server;
use App\Models\Track;
use App\Models\Week;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/ZinoPanelHelpers.php';

/**
 * @param  callable(array<string, mixed>): array<string, mixed>|null  $change
 */
function metadataCatalog(?callable $change = null): Catalog
{
    $data = zinoFixture('metadata');

    return ZinoMetadata::toCatalog($change === null ? $data : $change($data));
}

function syncCatalog(Catalog $catalog, ?Server $server = null): CatalogSync
{
    return app(SyncCatalog::class)->handle($server ?? Server::factory()->create(), $catalog);
}

test('catalog:sync creates every car and circuit of the real metadata through the fake driver', function () {
    $server = Server::factory()->create(['slug' => 'main', 'is_active' => true]);

    $this->artisan('catalog:sync', ['server' => 'main'])->assertSuccessful();

    $summary = CatalogSync::query()->sole()->summary;

    expect($summary)->toBe([
        'cars' => ['created' => 100, 'updated' => 0, 'deactivated' => 0],
        'tracks' => ['created' => 71, 'updated' => 0, 'deactivated' => 0],
        'unpaired_race_tracks' => [],
    ])
        ->and(Car::count())->toBe(100)
        ->and(Track::where('mode', TrackMode::Practice)->count())->toBe(36)
        ->and(Track::where('mode', TrackMode::RaceWeekend)->count())->toBe(35)
        ->and(CatalogSync::query()->sole()->server_id)->toBe($server->id);
});

test('catalog:sync without argument syncs every active server', function () {
    Server::factory()->create(['is_active' => true]);
    Server::factory()->create(['is_active' => false]);

    $this->artisan('catalog:sync')->assertSuccessful();

    expect(CatalogSync::count())->toBe(1);
});

test('catalog:sync fails for an unknown server', function () {
    $this->artisan('catalog:sync', ['server' => 'nope'])->assertFailed();

    expect(CatalogSync::count())->toBe(0);
});

test('a second identical sync reports no changes', function () {
    $server = Server::factory()->create();
    syncCatalog(metadataCatalog(), $server);

    $summary = syncCatalog(metadataCatalog(), $server)->summary;

    expect($summary['cars'])->toBe(['created' => 0, 'updated' => 0, 'deactivated' => 0])
        ->and($summary['tracks'])->toBe(['created' => 0, 'updated' => 0, 'deactivated' => 0]);
});

test('a changed max_pit_slot counts as an update', function () {
    syncCatalog(metadataCatalog());

    $summary = syncCatalog(metadataCatalog(function (array $data): array {
        $data['tracks']['race_weekend'][0]['max_pit_slot'] = 20;

        return $data;
    }))->summary;

    expect($summary['tracks'])->toBe(['created' => 0, 'updated' => 1, 'deactivated' => 0])
        ->and(Track::where('token', 'Brands Hatch|GP|GP Race|3916')->value('max_pit_slot'))->toBe(20);
});

test('rows missing from the catalog are deactivated and never deleted', function () {
    syncCatalog(metadataCatalog());
    $race = Track::where('token', 'Brands Hatch|GP|GP Race|3916')->firstOrFail();
    $car = Car::where('internal_name', 'preset_695b_mech_1')->firstOrFail();
    $proposal = Proposal::factory()->withCars([$car])->create(['track_id' => $race->id]);
    $week = Week::factory()->create(['track_id' => $race->id]);

    $summary = syncCatalog(metadataCatalog(function (array $data): array {
        $data['tracks']['race_weekend'] = array_values(array_filter($data['tracks']['race_weekend'], fn (array $track): bool => $track['token'] !== 'Brands Hatch|GP|GP Race|3916'));
        $data['cars'] = array_values(array_filter($data['cars'], fn (array $car): bool => $car['internal_name'] !== 'preset_695b_mech_1'));

        return $data;
    }))->summary;

    expect($summary['tracks']['deactivated'])->toBe(1)
        ->and($summary['cars']['deactivated'])->toBe(1)
        ->and($race->fresh()->active)->toBeFalse()
        ->and($car->fresh()->active)->toBeFalse()
        ->and($proposal->fresh()->track_id)->toBe($race->id)
        ->and($week->fresh()->track_id)->toBe($race->id)
        ->and(Track::count())->toBe(71)
        ->and(Car::count())->toBe(100);
});

test('race circuits are paired with their practice and unpaired ones are reported and not proposable', function () {
    $summary = syncCatalog(metadataCatalog(function (array $data): array {
        $data['tracks']['race_weekend'][] = ['token' => 'Nowhere|GP|GP Race|1000', 'display' => 'Nowhere GP', 'track' => 'Nowhere', 'layout' => 'GP', 'event_name' => 'GP Race', 'length_m' => 1000, 'max_pit_slot' => 10];

        return $data;
    }))->summary;

    $race = Track::where('token', 'Brands Hatch|GP|GP Race|3916')->firstOrFail();
    $orphan = Track::where('token', 'Nowhere|GP|GP Race|1000')->firstOrFail();

    expect($summary['unpaired_race_tracks'])->toBe(['Nowhere|GP|GP Race|1000'])
        ->and($race->pairedTrack->token)->toBe('Brands Hatch|GP|GP Time Attack|3916')
        ->and($orphan->paired_track_id)->toBeNull()
        ->and($orphan->proposable)->toBeFalse()
        ->and($orphan->country)->toBeNull()
        ->and(Track::proposable()->whereKey($orphan->id)->exists())->toBeFalse();
});

test('pairing is recalculated when the practice circuit appears later', function () {
    $withoutPractice = function (array $data): array {
        $data['tracks']['practice'] = array_values(array_filter($data['tracks']['practice'], fn (array $track): bool => $track['token'] !== 'Brands Hatch|GP|GP Time Attack|3916'));

        return $data;
    };

    syncCatalog(metadataCatalog($withoutPractice));
    $race = Track::where('token', 'Brands Hatch|GP|GP Race|3916')->firstOrFail();
    expect($race->paired_track_id)->toBeNull();

    syncCatalog(metadataCatalog());

    expect($race->fresh()->pairedTrack->token)->toBe('Brands Hatch|GP|GP Time Attack|3916');
});

test('mod cars are born not proposable', function () {
    syncCatalog(metadataCatalog(function (array $data): array {
        $data['cars'][0]['is_mod'] = true;

        return $data;
    }));

    $mod = Car::where('internal_name', 'preset_695b_mech_1')->firstOrFail();

    expect($mod->is_mod)->toBeTrue()
        ->and($mod->proposable)->toBeFalse()
        ->and(Car::where('internal_name', 'preset_75t_mech_1')->value('proposable'))->toBeTrue();
});

test('admin managed fields survive a sync', function () {
    syncCatalog(metadataCatalog());
    $track = Track::where('token', 'Brands Hatch|GP|GP Race|3916')->firstOrFail();
    $track->update(['proposable' => false, 'svg_slug' => 'custom-slug', 'country' => 'ES']);
    $car = Car::where('internal_name', 'preset_695b_mech_1')->firstOrFail();
    $car->update(['proposable' => false]);

    $summary = syncCatalog(metadataCatalog())->summary;
    $track->refresh();

    expect($track->proposable)->toBeFalse()
        ->and($track->svg_slug)->toBe('custom-slug')
        ->and($track->country)->toBe('ES')
        ->and($car->fresh()->proposable)->toBeFalse()
        ->and($summary['tracks']['updated'])->toBe(0)
        ->and($summary['cars']['updated'])->toBe(0);
});

test('the summary is stored in catalog_syncs and in the audit log', function () {
    $sync = syncCatalog(metadataCatalog());

    $audit = AuditLog::where('action', 'catalog.synced')->sole();

    expect($sync->started_at)->not->toBeNull()
        ->and($sync->finished_at->gte($sync->started_at))->toBeTrue()
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->subject_id)->toBe($sync->id)
        ->and($audit->after)->toBe($sync->summary);
});

test('a driver failure touches no rows and records a sync failure', function () {
    zinoFake(['GET /api/metadata' => Http::response(['error' => 'down'], 500)]);
    $server = zinoServer();
    $track = Track::factory()->create();
    $car = Car::factory()->create();

    $this->artisan('catalog:sync', ['server' => $server->slug])->assertFailed();

    $event = IntegrationEvent::sole();

    expect($event->type)->toBe(IntegrationEventType::SyncFailed)
        ->and($event->summary)->toBe('zino_panel catalog: HTTP 500')
        ->and($track->fresh()->active)->toBeTrue()
        ->and($car->fresh()->active)->toBeTrue()
        ->and(CatalogSync::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

test('catalog:sync from the panel stores the runtime name and the real classes', function () {
    zinoFake();
    $server = zinoServer();

    $this->artisan('catalog:sync', ['server' => $server->slug])->assertSuccessful();

    $car = Car::where('internal_name', 'preset_e46csl_mech_1')->firstOrFail();

    expect($car->runtime_name)->toBe('ks_bmw_m3_e46_csl')
        ->and(Car::where('internal_name', 'preset_r8gt2_mech_1')->value('classes'))->toBe(['gt2'])
        ->and(Track::where('token', 'Brands Hatch|GP|GP Race|3916')->value('display'))->toBe('Brands Hatch GP')
        ->and(IntegrationEvent::count())->toBe(0);
});

test('catalog:sync is scheduled daily without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'catalog:sync'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->timezone)->toBe(config('mvp.timezone'));
});
