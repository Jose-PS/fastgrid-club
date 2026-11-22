<?php

declare(strict_types=1);

use App\Enums\ConfigSource;
use App\Enums\WeekStatus;
use App\Models\Car;
use App\Models\Server;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

test('guests get not found on the admin area', function () {
    $this->get(route('admin.index'))->assertNotFound();
});

test('non admins get not found on the admin area', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.index'))
        ->assertNotFound();
});

test('admins open the admin area without indexing or caching', function () {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.index'));

    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/index'));

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

test('the admin gate follows the admin flag', function () {
    expect(Gate::forUser(User::factory()->admin()->create())->allows('admin'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('admin'))->toBeFalse();
});

test('the admin flag is shared with the front end', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.is_admin', true));

    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.is_admin', false));
});

test('the new admin sections are gated like the rest', function (string $name) {
    $this->get(route($name))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route($name))->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->get(route($name))->assertOk();
})->with(['admin.donations.show', 'admin.audit.index', 'admin.proposals.index', 'admin.weeks.index', 'admin.catalog.index']);

test('the panel summarises the current and next weeks of the active server', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));
    $server = Server::factory()->create(['is_active' => true]);
    $track = Track::factory()->create(['display' => 'Monza']);
    $car = Car::factory()->create(['display_name' => 'Ferrari 296']);
    $current = Week::factory()->for($server)->status(WeekStatus::Active)->create([
        'track_id' => $track->id,
        'config_source' => ConfigSource::Fallback,
        'custom_title' => 'Casual night',
    ]);
    $current->cars()->attach($car->id);
    Week::factory()->for($server)->startingAt(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'))->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('weeks.current.id', $current->id)
            ->where('weeks.current.status', 'active')
            ->where('weeks.current.track', 'Monza')
            ->where('weeks.current.cars', ['Ferrari 296'])
            ->where('weeks.current.config_source', 'fallback')
            ->where('weeks.current.custom_title', 'Casual night')
            ->where('weeks.next.status', 'voting')
            ->where('weeks.next.track', null));
});

test('the panel handles a server without weeks', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('weeks.current', null)->where('weeks.next', null));
});

test('the panel warns when the admin has no passkey and stops when there is one', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('missingPasskey', true));

    $admin->passkeys()->create(['name' => 'Portátil', 'credential_id' => Str::random(32), 'credential' => []]);

    $this->actingAs($admin)->get(route('admin.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('missingPasskey', false));
});
