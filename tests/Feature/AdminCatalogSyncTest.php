<?php

declare(strict_types=1);

use App\Actions\Catalog\CheckCatalogFreshness;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\IntegrationEventType;
use App\Enums\ServerDriverType;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Car;
use App\Models\CatalogSync;
use App\Models\IntegrationEvent;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));
    $this->admin = User::factory()->admin()->create();
    $this->server = Server::factory()->create(['is_active' => true, 'slug' => 'main', 'name' => 'Main server']);
});

test('the sync button runs the catalog sync now through the fake driver, audits it and shows the summary', function () {
    $this->actingAs($this->admin)->get(route('admin.catalog.index'))
        ->assertInertia(fn (Assert $page) => $page->where('lastSync', null)->where('canSync', true));

    $this->actingAs($this->admin)->post(route('admin.catalog.sync'))->assertRedirect();

    $sync = CatalogSync::query()->sole();
    $audit = AuditLog::query()->where('action', 'catalog.synced')->sole();

    expect(Car::query()->count())->toBe(100)
        ->and($sync->server_id)->toBe($this->server->id)
        ->and($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->after['cars']['created'])->toBe(100);

    $this->actingAs($this->admin)->get(route('admin.catalog.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lastSync.cars.created', 100)
            ->where('lastSync.tracks.created', 71)
            ->where('lastSync.unpaired', 0));
});

test('a failing sync records the integration event, creates no sync and reports an error', function () {
    $this->server->update(['driver' => ServerDriverType::ZinoPanel, 'credentials_ref' => null]);

    $this->actingAs($this->admin)->post(route('admin.catalog.sync'))->assertRedirect();

    expect(CatalogSync::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'catalog.synced')->count())->toBe(0)
        ->and(IntegrationEvent::query()->where('type', IntegrationEventType::SyncFailed)->count())->toBe(1);
});

test('the sync action is rate limited', function () {
    $this->server->update(['driver' => ServerDriverType::ZinoPanel, 'credentials_ref' => null]);
    $limit = (int) config('mvp.rate_limits.admin.per_minute');

    foreach (range(1, $limit) as $_) {
        $this->actingAs($this->admin)->post(route('admin.catalog.sync'))->assertRedirect();
    }

    $this->actingAs($this->admin)->post(route('admin.catalog.sync'))->assertStatus(429);
});

test('the catalog stale alert opens after 48 hours without a successful sync and closes by itself', function () {
    CatalogSync::factory()->create(['server_id' => $this->server->id, 'finished_at' => now()->subHours(47)]);

    expect(app(CheckCatalogFreshness::class)->handle($this->server))->toBeFalse()
        ->and(Alert::query()->count())->toBe(0);

    $this->travelTo(now()->addHours(2));

    expect(app(CheckCatalogFreshness::class)->handle($this->server))->toBeTrue();

    $alert = Alert::query()->sole();
    expect($alert->type)->toBe(AlertType::CatalogStale)
        ->and($alert->status)->toBe(AlertStatus::Open)
        ->and($alert->server_id)->toBe($this->server->id);

    app(CheckCatalogFreshness::class)->handle($this->server);
    expect(Alert::query()->count())->toBe(1);

    $this->actingAs($this->admin)->post(route('admin.catalog.sync'))->assertRedirect();

    expect(Alert::query()->sole()->status)->toBe(AlertStatus::Closed);
});

test('the freshness check closes the alert when the latest sync is recent', function () {
    $this->travelTo(now()->addDays(3));
    app(CheckCatalogFreshness::class)->handle($this->server);
    expect(Alert::query()->open()->count())->toBe(1);

    CatalogSync::factory()->create(['server_id' => $this->server->id, 'finished_at' => now()]);
    app(CheckCatalogFreshness::class)->handle($this->server);

    expect(Alert::query()->open()->count())->toBe(0);
});

test('catalog:check-freshness checks every active server and ignores inactive ones', function () {
    Server::factory()->create(['is_active' => false, 'created_at' => now()->subDays(10)]);
    $this->server->forceFill(['created_at' => now()->subDays(10)])->save();

    $this->artisan('catalog:check-freshness')->assertSuccessful();

    expect(Alert::query()->open()->where('type', AlertType::CatalogStale)->count())->toBe(1)
        ->and(Alert::query()->sole()->server_id)->toBe($this->server->id);
});
