<?php

declare(strict_types=1);

use App\Models\Car;
use App\Models\Proposal;
use App\Models\Server;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->withHeader('Accept-Language', 'es');
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));

    $this->week = Week::factory()
        ->for(Server::factory()->create(['max_players' => 24]))
        ->startingAt(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'))
        ->create();
    $this->track = Track::factory()->withPracticePair()->create();
    $this->cars = Car::factory()->count(4)->create();
    $this->user = User::factory()->withSteam()->create();
});

function creationPayload(Track $track, iterable $cars, array $extra = []): array
{
    return ['track_id' => $track->id, 'cars' => collect($cars)->pluck('id')->all(), ...$extra];
}

test('a user with steam creates a proposal for the voting week', function () {
    $cars = $this->cars->take(2);

    $this->actingAs($this->user)
        ->post(route('proposals.store'), creationPayload($this->track, $cars))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('proposals'));

    $proposal = Proposal::query()->sole();

    expect($proposal->week_id)->toBe($this->week->id)
        ->and($proposal->user_id)->toBe($this->user->id)
        ->and($proposal->track_id)->toBe($this->track->id)
        ->and($proposal->signature)->toBe(Proposal::signatureFor($this->track->id, $cars->pluck('id')->all()))
        ->and($proposal->cars()->pluck('cars.id')->sort()->values()->all())->toBe($cars->pluck('id')->sort()->values()->all())
        ->and($this->get(route('proposals'))->inertiaPage()['flash']['toast']['message'])->toBe(__('props.added'));
});

test('zero cars are rejected with the cars message', function () {
    $this->actingAs($this->user)
        ->post(route('proposals.store'), creationPayload($this->track, []))
        ->assertSessionHasErrors(['cars' => __('props.errCars')]);

    expect(Proposal::query()->count())->toBe(0);
});

test('more than three cars are accepted', function () {
    $this->actingAs($this->user)
        ->post(route('proposals.store'), creationPayload($this->track, $this->cars))
        ->assertSessionHasNoErrors();

    expect(Proposal::query()->sole()->cars()->count())->toBe(4);
});

test('repeated cars are rejected', function () {
    $car = $this->cars->first();

    $this->actingAs($this->user)
        ->post(route('proposals.store'), creationPayload($this->track, [$car, $car]))
        ->assertSessionHasErrors(['cars' => __('props.errCarsInvalid')]);

    expect(Proposal::query()->count())->toBe(0);
});

test('a track that is inactive, not proposable or without practice pair is rejected', function (string $case) {
    $track = match ($case) {
        'inactive' => Track::factory()->withPracticePair()->inactive()->create(),
        'not proposable' => Track::factory()->withPracticePair()->notProposable()->create(),
        'without practice pair' => Track::factory()->create(),
    };

    $this->actingAs($this->user)
        ->post(route('proposals.store'), creationPayload($track, $this->cars->take(1)))
        ->assertSessionHasErrors(['track_id' => __('props.errTrack')]);

    expect(Proposal::query()->count())->toBe(0);
})->with(['inactive', 'not proposable', 'without practice pair']);

test('a track with fewer pit slots than the server players is accepted', function () {
    $small = Track::factory()->withPracticePair()->create(['max_pit_slot' => 16]);

    $this->actingAs($this->user)
        ->post(route('proposals.store'), creationPayload($small, $this->cars->take(1)))
        ->assertSessionHasNoErrors();

    expect(Proposal::query()->sole()->track_id)->toBe($small->id);
});

test('a mod or inactive car is rejected', function (string $case) {
    $car = $case === 'mod' ? Car::factory()->mod()->create() : Car::factory()->inactive()->create();

    $this->actingAs($this->user)
        ->post(route('proposals.store'), creationPayload($this->track, [$this->cars->first(), $car]))
        ->assertSessionHasErrors(['cars' => __('props.errCarsInvalid')]);

    expect(Proposal::query()->count())->toBe(0);
})->with(['mod', 'inactive']);

test('format fields are ignored and have no columns', function () {
    $this->actingAs($this->user)
        ->post(route('proposals.store'), creationPayload($this->track, $this->cars->take(1), [
            'fmt' => 'laps',
            'val' => 12,
            'laps' => 12,
            'minutes' => 20,
        ]))
        ->assertSessionHasNoErrors();

    expect(Proposal::query()->count())->toBe(1);

    foreach (['fmt', 'val', 'laps', 'minutes', 'format'] as $column) {
        expect(Schema::hasColumn('proposals', $column))->toBeFalse($column);
    }
});

test('the weekly quota counts hidden proposals too', function () {
    Proposal::factory()->hidden()->create([
        'week_id' => $this->week->id,
        'user_id' => $this->user->id,
        'track_id' => $this->track->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('proposals.store'), creationPayload($this->track, $this->cars->take(2)))
        ->assertSessionHasErrors(['proposal' => __('props.quota')]);

    expect(Proposal::query()->count())->toBe(1);
});

test('the same track and cars cannot be proposed twice in a week', function () {
    Proposal::factory()->withCars($this->cars->take(2)->all())->create([
        'week_id' => $this->week->id,
        'track_id' => $this->track->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('proposals.store'), creationPayload($this->track, $this->cars->take(2)->reverse()))
        ->assertSessionHasErrors(['proposal' => __('props.duplicate')]);

    expect(Proposal::query()->count())->toBe(1);
});

test('a signature that matches a hidden proposal is blocked with a neutral message', function () {
    Proposal::factory()->hidden()->withCars($this->cars->take(2)->all())->create([
        'week_id' => $this->week->id,
        'track_id' => $this->track->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('proposals.store'), creationPayload($this->track, $this->cars->take(2)))
        ->assertSessionHasErrors(['proposal' => __('props.duplicateHidden')]);

    expect(Proposal::query()->count())->toBe(1)
        ->and(__('props.duplicateHidden'))->not->toBe(__('props.duplicate'));
});

test('proposals are accepted until the last second of the window', function (string $moment, bool $accepted) {
    $this->travelTo(CarbonImmutable::parse($moment, 'Europe/Madrid'));

    $response = $this->actingAs($this->user)
        ->post(route('proposals.store'), creationPayload($this->track, $this->cars->take(1)));

    if ($accepted) {
        $response->assertSessionHasNoErrors();
    } else {
        $response->assertSessionHasErrors(['proposal' => __('props.closed')]);
    }

    expect(Proposal::query()->count())->toBe($accepted ? 1 : 0);
})->with([
    'last second' => ['2026-11-15 23:59:59', true],
    'right after closing' => ['2026-11-16 00:00:00', false],
]);

test('guests and users without steam nor google cannot propose', function () {
    $this->postJson(route('proposals.store'), creationPayload($this->track, $this->cars->take(1)))->assertUnauthorized();

    $this->actingAs(User::factory()->withVerifiedEmail()->create())
        ->postJson(route('proposals.store'), creationPayload($this->track, $this->cars->take(1)))
        ->assertForbidden()
        ->assertJson(['message' => __('props.needsIdentity')]);

    expect(Proposal::query()->count())->toBe(0);
});

test('a user with only google can propose', function () {
    $this->actingAs(User::factory()->withGoogle()->create())
        ->post(route('proposals.store'), creationPayload($this->track, $this->cars->take(1)))
        ->assertSessionHasNoErrors();

    expect(Proposal::query()->count())->toBe(1);
});

test('proposal attempts are rate limited including rejected ones', function () {
    foreach (range(1, 5) as $attempt) {
        $this->actingAs($this->user)
            ->post(route('proposals.store'), creationPayload($this->track, []))
            ->assertSessionHasErrors('cars');
    }

    $this->actingAs($this->user)
        ->post(route('proposals.store'), creationPayload($this->track, $this->cars->take(1)))
        ->assertTooManyRequests();

    expect(Proposal::query()->count())->toBe(0);
});
