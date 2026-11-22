<?php

declare(strict_types=1);

use App\Actions\Weeks\ResolveWeek;
use App\Enums\ConfigSource;
use App\Mail\WeekWithoutWinnerMail;
use App\Models\AuditLog;
use App\Models\Car;
use App\Models\Server;
use App\Models\ServerDefaultConfig;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));
    $this->server = Server::factory()->create(['is_active' => true, 'max_players' => 24]);
    $this->admin = User::factory()->admin()->create();
    $this->track = Track::factory()->withPracticePair()->create();
    $this->cars = Car::factory()->count(3)->create();
});

function defaultConfigPayload(array $overrides = []): array
{
    return array_merge([
        'track_id' => test()->track->id,
        'cars' => test()->cars->pluck('id')->all(),
        'custom_title' => 'Casual night',
    ], $overrides);
}

test('guests and non admins get not found and admins can open the page', function () {
    $this->get(route('admin.default-config.show'))->assertNotFound();
    $this->put(route('admin.default-config.update'), defaultConfigPayload())->assertNotFound();
    $this->delete(route('admin.default-config.destroy'))->assertNotFound();

    $this->actingAs(User::factory()->create());
    $this->get(route('admin.default-config.show'))->assertNotFound();
    $this->put(route('admin.default-config.update'), defaultConfigPayload())->assertNotFound();
    $this->delete(route('admin.default-config.destroy'))->assertNotFound();

    $this->actingAs($this->admin)
        ->get(route('admin.default-config.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/default-config')
            ->where('config', null)
            ->has('options.tracks', 1)
            ->has('options.cars', 3));
});

test('the page exposes the saved configuration', function () {
    $config = ServerDefaultConfig::factory()->for($this->server)->create(['track_id' => $this->track->id, 'custom_title' => 'Hola']);
    $config->cars()->attach($this->cars->take(2)->pluck('id'));

    $this->actingAs($this->admin)
        ->get(route('admin.default-config.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('config.track_id', $this->track->id)
            ->where('config.custom_title', 'Hola')
            ->where('config.car_ids', $this->cars->take(2)->pluck('id')->sort()->values()->all()));
});

test('saving a valid configuration creates it with its cars and title', function (int $count) {
    $carIds = $this->cars->take($count)->pluck('id')->all();

    $this->actingAs($this->admin)
        ->put(route('admin.default-config.update'), defaultConfigPayload(['cars' => $carIds, 'custom_title' => '  Casual night  ']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $config = ServerDefaultConfig::query()->sole();

    expect($config->server_id)->toBe($this->server->id)
        ->and($config->track_id)->toBe($this->track->id)
        ->and($config->custom_title)->toBe('Casual night')
        ->and($config->cars()->pluck('cars.id')->sort()->values()->all())->toBe(collect($carIds)->sort()->values()->all());
})->with([1, 2, 3]);

test('an empty title is stored as null', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.default-config.update'), defaultConfigPayload(['custom_title' => '']))
        ->assertSessionHasNoErrors();

    expect(ServerDefaultConfig::query()->sole()->custom_title)->toBeNull();
});

test('invalid configurations are rejected', function (Closure $payload, string $field) {
    $this->actingAs($this->admin)
        ->put(route('admin.default-config.update'), $payload())
        ->assertSessionHasErrors($field);

    expect(ServerDefaultConfig::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'missing track' => [fn () => defaultConfigPayload(['track_id' => null]), 'track_id'],
    'inactive track' => [fn () => defaultConfigPayload(['track_id' => Track::factory()->withPracticePair()->inactive()->create()->id]), 'track_id'],
    'not proposable track' => [fn () => defaultConfigPayload(['track_id' => Track::factory()->withPracticePair()->notProposable()->create()->id]), 'track_id'],
    'track without practice pair' => [fn () => defaultConfigPayload(['track_id' => Track::factory()->create()->id]), 'track_id'],
    'inactive car' => [fn () => defaultConfigPayload(['cars' => [Car::factory()->inactive()->create()->id]]), 'cars'],
    'not proposable car' => [fn () => defaultConfigPayload(['cars' => [Car::factory()->notProposable()->create()->id]]), 'cars'],
    'no cars' => [fn () => defaultConfigPayload(['cars' => []]), 'cars'],
    'repeated cars' => [fn () => defaultConfigPayload(['cars' => [test()->cars[0]->id, test()->cars[0]->id]]), 'cars'],
    'long title' => [fn () => defaultConfigPayload(['custom_title' => Str::repeat('a', 256)]), 'custom_title'],
]);

test('there is one configuration per server and saving twice updates it', function () {
    $this->actingAs($this->admin)->put(route('admin.default-config.update'), defaultConfigPayload())->assertSessionHasNoErrors();

    $other = Track::factory()->withPracticePair()->create();
    $this->put(route('admin.default-config.update'), defaultConfigPayload([
        'track_id' => $other->id,
        'cars' => [$this->cars[0]->id],
        'custom_title' => null,
    ]))->assertSessionHasNoErrors();

    $config = ServerDefaultConfig::query()->sole();

    expect($config->track_id)->toBe($other->id)
        ->and($config->custom_title)->toBeNull()
        ->and($config->cars()->pluck('cars.id')->all())->toBe([$this->cars[0]->id]);
});

test('deleting removes the configuration and its cars', function () {
    $config = ServerDefaultConfig::factory()->for($this->server)->create(['track_id' => $this->track->id]);
    $config->cars()->attach($this->cars->pluck('id'));

    $this->actingAs($this->admin)->delete(route('admin.default-config.destroy'))->assertRedirect();

    expect(ServerDefaultConfig::query()->count())->toBe(0)
        ->and(DB::table('server_default_config_cars')->count())->toBe(0);
});

test('deleting without a configuration does nothing', function () {
    $this->actingAs($this->admin)->delete(route('admin.default-config.destroy'))->assertRedirect();

    expect(AuditLog::query()->count())->toBe(0);
});

test('saving and deleting are audited with before and after', function () {
    $this->actingAs($this->admin)->put(route('admin.default-config.update'), defaultConfigPayload())->assertSessionHasNoErrors();

    $saved = AuditLog::query()->where('action', 'default_config.saved')->sole();
    $expectedCars = $this->cars->pluck('id')->sort()->values()->all();

    expect($saved->actor_id)->toBe($this->admin->id)
        ->and($saved->subject_type)->toBe((new ServerDefaultConfig)->getMorphClass())
        ->and($saved->before)->toBeNull()
        ->and($saved->after)->toBe(['track_id' => $this->track->id, 'car_ids' => $expectedCars, 'custom_title' => 'Casual night']);

    $this->put(route('admin.default-config.update'), defaultConfigPayload(['custom_title' => 'Otro']));

    $updated = AuditLog::query()->where('action', 'default_config.saved')->latest('id')->first();

    expect($updated->before['custom_title'])->toBe('Casual night')
        ->and($updated->after['custom_title'])->toBe('Otro');

    $this->delete(route('admin.default-config.destroy'));

    $deleted = AuditLog::query()->where('action', 'default_config.deleted')->sole();

    expect($deleted->before)->toBe(['track_id' => $this->track->id, 'car_ids' => $expectedCars, 'custom_title' => 'Otro'])
        ->and($deleted->after)->toBeNull();
});

test('saving without changes records nothing new', function () {
    $this->actingAs($this->admin)->put(route('admin.default-config.update'), defaultConfigPayload());
    $this->put(route('admin.default-config.update'), defaultConfigPayload());

    expect(AuditLog::query()->where('action', 'default_config.saved')->count())->toBe(1);
});

test('changing the configuration leaves resolved weeks intact and applies to later ones', function () {
    Mail::fake();
    $oldTrack = Track::factory()->withPracticePair()->create();
    $older = ServerDefaultConfig::factory()->for($this->server)->create(['track_id' => $oldTrack->id, 'custom_title' => 'Antes']);
    $older->cars()->attach($this->cars[0]->id);

    $first = Week::factory()->for($this->server)->startingAt(CarbonImmutable::parse('2026-11-23 00:00:00', 'Europe/Madrid'))->create();
    $second = Week::factory()->for($this->server)->startingAt(CarbonImmutable::parse('2026-11-30 00:00:00', 'Europe/Madrid'))->create();

    $this->travelTo($first->starts_at);
    expect(app(ResolveWeek::class)->handle($first->id))->toBeTrue();

    $this->actingAs($this->admin)->put(route('admin.default-config.update'), defaultConfigPayload())->assertSessionHasNoErrors();

    $first = $first->fresh();

    expect($first->track_id)->toBe($oldTrack->id)
        ->and($first->custom_title)->toBe('Antes')
        ->and($first->cars()->pluck('cars.id')->all())->toBe([$this->cars[0]->id]);

    $this->travelTo($second->starts_at);
    expect(app(ResolveWeek::class)->handle($second->id))->toBeTrue();

    $second = $second->fresh();

    expect($second->track_id)->toBe($this->track->id)
        ->and($second->custom_title)->toBe('Casual night')
        ->and($second->config_source)->toBe(ConfigSource::Fallback)
        ->and($second->cars()->pluck('cars.id')->sort()->values()->all())->toBe($this->cars->pluck('id')->sort()->values()->all());
    Mail::assertQueued(WeekWithoutWinnerMail::class);
});
