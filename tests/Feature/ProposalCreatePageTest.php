<?php

declare(strict_types=1);

use App\Models\Car;
use App\Models\Proposal;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));

    $this->week = Week::factory()
        ->startingAt(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'))
        ->create();
});

function createPageProps(?User $user = null): array
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->get(route('proposals.create'))->assertOk()->inertiaProps();
}

test('the page is public and reports the viewer state', function () {
    expect(createPageProps()['viewer'])->toBe(['state' => 'guest', 'proposalLimitReached' => false])
        ->and(createPageProps(User::factory()->withVerifiedEmail()->create())['viewer'])->toBe(['state' => 'needs_identity', 'proposalLimitReached' => false])
        ->and(createPageProps(User::factory()->withSteam('76561198000000900')->create())['viewer'])->toBe(['state' => 'ready', 'proposalLimitReached' => false])
        ->and(createPageProps(User::factory()->withGoogle()->create())['viewer'])->toBe(['state' => 'ready', 'proposalLimitReached' => false]);
});

test('a user who used their quota is flagged', function () {
    $user = User::factory()->withSteam('76561198000000901')->create();
    Proposal::factory()->create(['week_id' => $this->week->id, 'user_id' => $user->id]);

    expect(createPageProps($user)['viewer']['proposalLimitReached'])->toBeTrue();
});

test('the week is null when no week is open and open when voting', function () {
    expect(createPageProps()['week'])->toMatchArray(['id' => $this->week->id, 'isOpen' => true]);

    $this->week->delete();

    expect(createPageProps()['week'])->toBeNull();
});

test('the catalog only lists proposable cars and tracks', function () {
    $track = Track::factory()->withPracticePair()->create(['display' => 'Monza GP']);
    Track::factory()->withPracticePair()->inactive()->create();
    Track::factory()->withPracticePair()->notProposable()->create();
    Track::factory()->create();
    $car = Car::factory()->create(['display_name' => 'BMW M4 GT3']);
    Car::factory()->mod()->create();
    Car::factory()->inactive()->create();
    Car::factory()->notProposable()->create();

    $catalog = createPageProps()['catalog'];

    expect(collect($catalog['tracks'])->pluck('id')->all())->toBe([$track->id])
        ->and(collect($catalog['cars'])->pluck('id')->all())->toBe([$car->id]);
});

test('cars and tracks expose only their public fields', function () {
    $track = Track::factory()->withPracticePair()->create([
        'display' => 'Monza GP',
        'track' => 'Monza',
        'layout' => 'GP',
        'country' => 'IT',
        'length_m' => 5793,
        'max_pit_slot' => 30,
    ]);
    $car = Car::factory()->create([
        'display_name' => 'Porsche 911 GT3 R',
        'brand' => 'Porsche',
        'pi' => 30.5,
        'type' => 'race',
        'era' => 'modern',
        'engine' => 'ice',
        'classes' => ['GT3', 'gt3', 'Cup'],
    ]);

    $catalog = createPageProps()['catalog'];

    expect($catalog['tracks'])->toBe([[
        'id' => $track->id,
        'display' => 'Monza GP',
        'track' => 'Monza',
        'layout' => 'GP',
        'country' => 'IT',
        'lengthM' => 5793,
        'maxPitSlot' => 30,
    ]])
        ->and($catalog['cars'])->toBe([[
            'id' => $car->id,
            'displayName' => 'Porsche 911 GT3 R',
            'brand' => 'Porsche',
            'pi' => 30.5,
            'type' => 'race',
            'era' => 'modern',
            'engine' => 'ice',
            'classes' => ['gt3', 'cup'],
        ]]);
});

test('ranges and facets summarise the proposable catalog', function () {
    Track::factory()->withPracticePair()->create(['length_m' => 1929, 'max_pit_slot' => 10, 'country' => 'GB']);
    Track::factory()->withPracticePair()->create(['length_m' => 25947, 'max_pit_slot' => 45, 'country' => 'DE']);
    Track::factory()->withPracticePair()->create(['length_m' => 5000, 'max_pit_slot' => 20, 'country' => 'GB']);
    Track::factory()->withPracticePair()->inactive()->create(['length_m' => 90000, 'max_pit_slot' => 99, 'country' => 'JP']);
    Car::factory()->create(['brand' => 'Ford', 'pi' => 12.5, 'type' => 'road', 'era' => 'vintage', 'engine' => 'ice', 'classes' => []]);
    Car::factory()->create(['brand' => 'Audi', 'pi' => 40.0, 'type' => 'race', 'era' => 'modern', 'engine' => 'hybrid', 'classes' => ['gt3']]);
    Car::factory()->create(['brand' => 'Ford', 'pi' => null, 'type' => 'road', 'era' => 'yt', 'engine' => 'ev', 'classes' => ['cup', 'gt3']]);
    Car::factory()->mod()->create(['brand' => 'Mod', 'pi' => 99.0]);

    $catalog = createPageProps()['catalog'];

    expect($catalog['ranges'])->toEqual([
        'pi' => ['min' => 12.5, 'max' => 40.0],
        'lengthM' => ['min' => 1929, 'max' => 25947],
        'pitSlots' => ['min' => 10, 'max' => 45],
    ])
        ->and($catalog['facets'])->toBe([
            'brands' => ['Audi', 'Ford'],
            'types' => ['race', 'road'],
            'eras' => ['modern', 'vintage', 'yt'],
            'engines' => ['ev', 'hybrid', 'ice'],
            'classes' => ['cup', 'gt3'],
            'countries' => ['DE', 'GB'],
        ]);
});

test('an empty catalog yields zero ranges and no facets', function () {
    $catalog = createPageProps()['catalog'];

    expect($catalog['cars'])->toBe([])
        ->and($catalog['tracks'])->toBe([])
        ->and($catalog['ranges']['pi'])->toBe(['min' => 0, 'max' => 0])
        ->and($catalog['facets']['classes'])->toBe([]);
});

test('the response exposes no steam id or email', function () {
    $user = User::factory()->withVerifiedEmail()->withSteam('76561198000000902')->create(['email' => 'viewer@example.test']);
    Car::factory()->create();

    $content = $this->actingAs($user)->get(route('proposals.create'))->assertOk()->getContent();

    expect($content)->not->toContain('76561198000000902')
        ->and($content)->not->toContain('viewer@example.test')
        ->and($content)->not->toContain('steam_id')
        ->and($content)->not->toContain('internal_name');
});

test('the page stays within its query budget', function () {
    $user = User::factory()->withSteam('76561198000000903')->create();
    Track::factory()->withPracticePair()->count(3)->create();
    Car::factory()->count(5)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)->get(route('proposals.create'))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(12);
});
