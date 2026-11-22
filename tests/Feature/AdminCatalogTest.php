<?php

declare(strict_types=1);

use App\Actions\Proposals\ProposalListing;
use App\Enums\TrackMode;
use App\Models\AuditLog;
use App\Models\Car;
use App\Models\Server;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    config(['mvp.tracks.path' => base_path('tests/Fixtures/tracks')]);
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));
    $this->admin = User::factory()->admin()->create();
});

test('guests and non admins get not found on every catalog route', function () {
    $track = Track::factory()->create();
    $car = Car::factory()->create();

    $this->get(route('admin.catalog.index'))->assertNotFound();
    $this->put(route('admin.catalog.tracks.proposable', $track), ['proposable' => false])->assertNotFound();
    $this->put(route('admin.catalog.cars.proposable', $car), ['proposable' => false])->assertNotFound();

    $this->actingAs(User::factory()->create());
    $this->get(route('admin.catalog.index'))->assertNotFound();
    $this->put(route('admin.catalog.tracks.proposable', $track), ['proposable' => false])->assertNotFound();
    $this->put(route('admin.catalog.cars.proposable', $car), ['proposable' => false])->assertNotFound();

    expect($track->fresh()->proposable)->toBeTrue()->and($car->fresh()->proposable)->toBeTrue();
});

test('the listing reports svg presence only for slugs that resolve to a real file', function () {
    Track::factory()->withPracticePair()->create(['display' => 'Con svg', 'svg_slug' => 'fixture-valid']);
    Track::factory()->withPracticePair()->create(['display' => 'Slug roto', 'svg_slug' => 'no-existe']);
    Track::factory()->create(['display' => 'Sin slug', 'svg_slug' => null]);
    Track::factory()->practice()->create(['display' => 'Práctica']);

    $this->actingAs($this->admin)->get(route('admin.catalog.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog')
            ->has('tracks', 3)
            ->where('tracks.0.display', 'Con svg')
            ->where('tracks.0.has_svg', true)
            ->where('tracks.0.has_practice', true)
            ->where('tracks.1.display', 'Sin slug')
            ->where('tracks.1.has_svg', false)
            ->where('tracks.1.has_practice', false)
            ->where('tracks.2.display', 'Slug roto')
            ->where('tracks.2.has_svg', false));
});

test('it warns when the current or next week track has no svg', function () {
    $server = Server::factory()->create(['is_active' => true]);
    $withSvg = Track::factory()->create(['display' => 'Bien', 'svg_slug' => 'fixture-valid']);
    $without = Track::factory()->create(['display' => 'Mal', 'svg_slug' => null]);
    Week::factory()->for($server)->create(['track_id' => $withSvg->id]);
    Week::factory()->for($server)->startingAt(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'))->create(['track_id' => $without->id]);

    $this->actingAs($this->admin)->get(route('admin.catalog.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('missingSvgWeeks', 1)
            ->where('missingSvgWeeks.0.when', 'next')
            ->where('missingSvgWeeks.0.track', 'Mal'));
});

test('there is no warning without weeks or when the tracks have svg', function () {
    $this->actingAs($this->admin)->get(route('admin.catalog.index'))
        ->assertInertia(fn (Assert $page) => $page->where('missingSvgWeeks', []));

    $server = Server::factory()->create(['is_active' => true]);
    Week::factory()->for($server)->create(['track_id' => Track::factory()->create(['svg_slug' => 'fixture-valid'])->id]);

    $this->actingAs($this->admin)->get(route('admin.catalog.index'))
        ->assertInertia(fn (Assert $page) => $page->where('missingSvgWeeks', []));
});

test('the car listing includes inactive cars with their state', function () {
    Car::factory()->create(['display_name' => 'Activo']);
    Car::factory()->create(['display_name' => 'Zeta', 'active' => false, 'proposable' => false]);

    $this->actingAs($this->admin)->get(route('admin.catalog.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('cars', 2)
            ->where('cars.0.display_name', 'Activo')
            ->where('cars.1.active', false)
            ->where('cars.1.proposable', false));
});

test('turning a track off removes it from the options and from proposal validation, and is audited', function () {
    $track = Track::factory()->withPracticePair()->create();
    $cars = Car::factory()->count(2)->create();
    Week::factory()->for(Server::factory()->create(['max_players' => 24]))->startingAt(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'))->create();
    $proposer = User::factory()->withSteam()->create();

    expect(collect(app(ProposalListing::class)->options()['tracks'])->pluck('id')->all())->toBe([$track->id]);

    $this->actingAs($this->admin)
        ->put(route('admin.catalog.tracks.proposable', $track), ['proposable' => false])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($track->fresh()->proposable)->toBeFalse()
        ->and(app(ProposalListing::class)->options()['tracks'])->toBe([]);

    $this->actingAs($proposer)
        ->post(route('proposals.store'), ['track_id' => $track->id, 'cars' => $cars->pluck('id')->all()])
        ->assertSessionHasErrors('track_id');

    $log = AuditLog::query()->where('action', 'catalog.track_proposable')->sole();

    expect($log->subject_id)->toBe($track->id)
        ->and($log->before)->toBe(['proposable' => true])
        ->and($log->after)->toBe(['proposable' => false])
        ->and($log->actor_id)->toBe($this->admin->id);
});

test('turning a car off removes it from the options and from proposal validation, and is audited', function () {
    $track = Track::factory()->withPracticePair()->create();
    $cars = Car::factory()->count(2)->create();
    Week::factory()->for(Server::factory()->create(['max_players' => 24]))->startingAt(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'))->create();
    $proposer = User::factory()->withSteam()->create();

    expect(app(ProposalListing::class)->options()['cars'])->toHaveCount(2);

    $this->actingAs($this->admin)
        ->put(route('admin.catalog.cars.proposable', $cars[0]), ['proposable' => false])
        ->assertSessionHasNoErrors();

    expect(collect(app(ProposalListing::class)->options()['cars'])->pluck('id')->all())->toBe([$cars[1]->id]);

    $this->actingAs($proposer)
        ->post(route('proposals.store'), ['track_id' => $track->id, 'cars' => [$cars[0]->id]])
        ->assertSessionHasErrors('cars');

    $log = AuditLog::query()->where('action', 'catalog.car_proposable')->sole();

    expect($log->subject_id)->toBe($cars[0]->id)
        ->and($log->before)->toBe(['proposable' => true])
        ->and($log->after)->toBe(['proposable' => false]);

    $this->actingAs($this->admin)
        ->put(route('admin.catalog.cars.proposable', $cars[0]), ['proposable' => true])
        ->assertSessionHasNoErrors();

    expect(app(ProposalListing::class)->options()['cars'])->toHaveCount(2);
});

test('an unchanged value writes no audit entry', function () {
    $car = Car::factory()->create();

    $this->actingAs($this->admin)->put(route('admin.catalog.cars.proposable', $car), ['proposable' => true])->assertSessionHasNoErrors();

    expect(AuditLog::query()->count())->toBe(0);
});

test('an inactive item cannot be marked proposable', function () {
    $track = Track::factory()->inactive()->notProposable()->create();
    $car = Car::factory()->create(['active' => false, 'proposable' => false]);

    $this->actingAs($this->admin)
        ->put(route('admin.catalog.tracks.proposable', $track), ['proposable' => true])
        ->assertSessionHasErrors('proposable');
    $this->actingAs($this->admin)
        ->put(route('admin.catalog.cars.proposable', $car), ['proposable' => true])
        ->assertSessionHasErrors('proposable');

    expect($track->fresh()->proposable)->toBeFalse()
        ->and($car->fresh()->proposable)->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(0);
});

test('inactive items never reach the proposable scopes even if flagged', function () {
    Track::factory()->withPracticePair()->inactive()->create(['proposable' => true]);
    Car::factory()->create(['active' => false, 'proposable' => true]);

    expect(Track::query()->proposable()->count())->toBe(0)->and(Car::query()->proposable()->count())->toBe(0);
});

test('the proposable value is required and boolean and practice tracks are not found', function () {
    $car = Car::factory()->create();
    $practice = Track::factory()->practice()->create();

    $this->actingAs($this->admin)->put(route('admin.catalog.cars.proposable', $car), [])->assertSessionHasErrors('proposable');
    $this->actingAs($this->admin)->put(route('admin.catalog.cars.proposable', $car), ['proposable' => 'maybe'])->assertSessionHasErrors('proposable');
    $this->actingAs($this->admin)->put(route('admin.catalog.tracks.proposable', $practice), ['proposable' => false])->assertNotFound();

    expect($practice->fresh()->mode)->toBe(TrackMode::Practice)->and($practice->fresh()->proposable)->toBeTrue();
});

test('a race circuit without a practice pair cannot be marked as proposable', function () {
    $track = Track::factory()->create(['proposable' => false, 'paired_track_id' => null]);

    $this->actingAs($this->admin)->put(route('admin.catalog.tracks.proposable', $track), ['proposable' => true])
        ->assertSessionHasErrors('proposable');

    expect($track->fresh()->proposable)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'catalog.track_proposable')->count())->toBe(0);
});

test('a race circuit with a practice pair can be marked as proposable and a pairless one can still be switched off', function () {
    $paired = Track::factory()->withPracticePair()->create(['proposable' => false]);
    $pairless = Track::factory()->create(['proposable' => true, 'paired_track_id' => null]);

    $this->actingAs($this->admin)->put(route('admin.catalog.tracks.proposable', $paired), ['proposable' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put(route('admin.catalog.tracks.proposable', $pairless), ['proposable' => false])->assertSessionHasNoErrors();

    expect($paired->fresh()->proposable)->toBeTrue()
        ->and($pairless->fresh()->proposable)->toBeFalse();
});

test('a track covered by the slug map is not listed as missing its svg', function () {
    config(['mvp.tracks.path' => resource_path('tracks')]);
    $server = Server::factory()->create(['is_active' => true]);
    $mapped = Track::factory()->create(['display' => 'Monza', 'track' => 'Monza', 'layout' => 'GP', 'svg_slug' => null]);
    Week::factory()->for($server)->create(['track_id' => $mapped->id]);

    $this->actingAs($this->admin)->get(route('admin.catalog.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tracks.0.has_svg', true)
            ->where('missingSvgWeeks', []));
});
