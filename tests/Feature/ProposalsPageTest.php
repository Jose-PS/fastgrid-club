<?php

declare(strict_types=1);

use App\Enums\ConfigSource;
use App\Enums\WeekStatus;
use App\Models\Car;
use App\Models\Proposal;
use App\Models\Track;
use App\Models\User;
use App\Models\Vote;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));

    $this->week = Week::factory()
        ->startingAt(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'))
        ->create();
    $this->track = Track::factory()->withPracticePair()->create(['display' => 'Monza', 'max_pit_slot' => 20]);
});

function proposalsPageProps(): array
{
    return test()->get(route('proposals'))->assertOk()->inertiaProps();
}

function proposalWithVotes(Week $week, int $votes, array $attributes = []): Proposal
{
    $proposal = Proposal::factory()->create(['week_id' => $week->id, ...$attributes]);
    Vote::factory()->count($votes)->create(['proposal_id' => $proposal->id]);

    return $proposal;
}

test('the listing is public and sorted by votes, age and id', function () {
    $older = proposalWithVotes($this->week, 1);
    $this->travel(1)->minutes();
    $top = proposalWithVotes($this->week, 3, ['track_id' => $this->track->id]);
    $newer = proposalWithVotes($this->week, 1);
    $tied = proposalWithVotes($this->week, 1, ['created_at' => $newer->created_at]);
    $top->cars()->attach(Car::factory()->create(['display_name' => 'Porsche 911 GT3 R'])->id);

    $props = proposalsPageProps();

    expect(collect($props['proposals'])->pluck('id')->all())->toBe([$top->id, $older->id, $newer->id, $tied->id])
        ->and(collect($props['proposals'])->pluck('rank')->all())->toBe([1, 2, 3, 4])
        ->and($props['total'])->toBe(4)
        ->and($props['proposals'][0])->toMatchArray([
            'track' => ['display' => 'Monza', 'maxPitSlot' => 20],
            'cars' => [['id' => $top->cars()->value('cars.id'), 'displayName' => 'Porsche 911 GT3 R']],
            'author' => $top->user->name,
            'votes' => 3,
            'isMine' => false,
            'canWithdraw' => false,
        ])
        ->and($props['week'])->toMatchArray([
            'id' => $this->week->id,
            'startsAt' => '2026-11-16T00:00:00+01:00',
            'endsAt' => '2026-11-23T00:00:00+01:00',
            'votingClosesAt' => '2026-11-15T23:59:59+01:00',
            'zone' => 'CET',
            'status' => 'voting',
            'isOpen' => true,
        ])
        ->and($props['viewer'])->toBe(['state' => 'guest', 'vote' => null, 'proposalLimitReached' => false])
        ->and($props['serverNow'])->toBe('2026-11-11T12:01:00+01:00');
});

test('hidden proposals are not listed and their votes do not count', function () {
    proposalWithVotes($this->week, 2, ['hidden_at' => now()]);
    $visible = proposalWithVotes($this->week, 1);

    $props = proposalsPageProps();

    expect(collect($props['proposals'])->pluck('id')->all())->toBe([$visible->id])
        ->and($props['total'])->toBe(1);
});

test('a deleted author is shown as null', function () {
    $proposal = proposalWithVotes($this->week, 0);
    $proposal->user->delete();

    expect(proposalsPageProps()['proposals'][0]['author'])->toBeNull();
});

test('a signed in user sees their vote, their proposal and their quota', function () {
    $user = User::factory()->withSteam('76561198000000800')->create();
    $own = proposalWithVotes($this->week, 0, ['user_id' => $user->id]);
    $other = proposalWithVotes($this->week, 2);
    Vote::factory()->create(['proposal_id' => $own->id, 'user_id' => $user->id, 'steam_id' => '76561198000000800']);

    $props = $this->actingAs($user)->get(route('proposals'))->inertiaProps();
    $cards = collect($props['proposals'])->keyBy('id');

    expect($props['viewer'])->toBe([
        'state' => 'ready',
        'vote' => ['proposalId' => $own->id, 'hidden' => false],
        'proposalLimitReached' => true,
    ])
        ->and($cards[$own->id]['isMine'])->toBeTrue()
        ->and($cards[$own->id]['canWithdraw'])->toBeTrue()
        ->and($cards[$other->id]['isMine'])->toBeFalse();
});

test('a vote kept in a hidden proposal is flagged so the user can choose another', function () {
    $user = User::factory()->withSteam('76561198000000801')->create();
    $hidden = proposalWithVotes($this->week, 0);
    Vote::factory()->create(['proposal_id' => $hidden->id, 'user_id' => $user->id, 'steam_id' => '76561198000000801']);
    $hidden->update(['hidden_at' => now()]);

    $props = $this->actingAs($user)->get(route('proposals'))->inertiaProps();

    expect($props['viewer']['vote'])->toBe(['proposalId' => $hidden->id, 'hidden' => true])
        ->and($props['proposals'])->toBe([])
        ->and(Vote::query()->where('proposal_id', $hidden->id)->exists())->toBeTrue();
});

test('a user without steam nor google is asked to link one of them', function () {
    $props = $this->actingAs(User::factory()->withVerifiedEmail()->create())->get(route('proposals'))->inertiaProps();

    expect($props['viewer'])->toBe(['state' => 'needs_identity', 'vote' => null, 'proposalLimitReached' => false]);
});

test('the form options list proposable tracks with their pit slots and proposable cars', function () {
    Track::factory()->withPracticePair()->inactive()->create();
    $car = Car::factory()->create(['display_name' => 'BMW M4 GT3']);
    Car::factory()->mod()->create();

    $options = proposalsPageProps()['options'];

    expect($options['tracks'])->toBe([['id' => $this->track->id, 'display' => 'Monza', 'maxPitSlot' => 20]])
        ->and($options['cars'])->toBe([['id' => $car->id, 'displayName' => 'BMW M4 GT3']]);
});

test('the public listing is cached and invalidated by a vote', function () {
    $proposal = proposalWithVotes($this->week, 0);

    expect(proposalsPageProps()['proposals'][0]['votes'])->toBe(0);

    Vote::factory()->create(['proposal_id' => $proposal->id]);

    expect(proposalsPageProps()['proposals'][0]['votes'])->toBe(0);

    $this->actingAs(User::factory()->withSteam()->create())->post(route('proposals.vote', $proposal));

    expect(proposalsPageProps()['proposals'][0]['votes'])->toBe(2);
});

test('the response exposes no steam id or email', function () {
    $author = User::factory()->withVerifiedEmail()->withSteam('76561198000000802')->create(['email' => 'author@example.test']);
    $voter = User::factory()->withVerifiedEmail()->withSteam('76561198000000803')->create(['email' => 'voter@example.test']);
    $proposal = proposalWithVotes($this->week, 0, ['user_id' => $author->id]);
    Vote::factory()->create(['proposal_id' => $proposal->id, 'user_id' => $voter->id, 'steam_id' => '76561198000000803']);

    $content = $this->actingAs($voter)->get(route('proposals'))->assertOk()->getContent();

    expect($content)->not->toContain('76561198000000802')
        ->and($content)->not->toContain('76561198000000803')
        ->and($content)->not->toContain('author@example.test')
        ->and($content)->not->toContain('steam_id')
        ->and($content)->not->toContain('"email"');
});

test('the page stays within its query budget with data and a signed in user', function () {
    $user = User::factory()->withSteam()->create();
    proposalWithVotes($this->week, 2, ['user_id' => $user->id]);
    proposalWithVotes($this->week, 1);
    proposalWithVotes($this->week, 0);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)->get(route('proposals'))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(12);
});

function resolvedWeek(array $attributes = []): Week
{
    return Week::factory()
        ->startingAt(CarbonImmutable::parse('2026-11-09 00:00:00', 'Europe/Madrid'))
        ->status(WeekStatus::Active)
        ->create(['resolved_at' => now()->subDays(2), 'config_source' => ConfigSource::Proposal, ...$attributes]);
}

test('there is no last vote block before any week is resolved', function () {
    expect(proposalsPageProps()['lastVote'])->toBeNull();
});

test('the last vote shows the winner and the final standings', function () {
    $closed = resolvedWeek();
    $car = Car::factory()->create(['display_name' => 'Porsche 911 GT3 R']);
    $winner = Proposal::factory()->withCars([$car])->create(['week_id' => $closed->id, 'track_id' => $this->track->id]);
    $loser = proposalWithVotes($closed, 1);
    Vote::factory()->count(3)->create(['proposal_id' => $winner->id]);
    $closed->update(['proposal_id' => $winner->id, 'track_id' => $this->track->id]);
    proposalWithVotes($closed, 9, ['hidden_at' => now()]);

    $lastVote = proposalsPageProps()['lastVote'];

    expect($lastVote['source'])->toBe('proposal')
        ->and($lastVote['winnerId'])->toBe($winner->id)
        ->and($lastVote['config'])->toBeNull()
        ->and($lastVote['totalVotes'])->toBe(4)
        ->and(collect($lastVote['ranking'])->pluck('id')->all())->toBe([$winner->id, $loser->id])
        ->and($lastVote['ranking'][0])->toMatchArray([
            'rank' => 1,
            'votes' => 3,
            'track' => ['display' => 'Monza', 'maxPitSlot' => 20],
            'cars' => [['id' => $car->id, 'displayName' => 'Porsche 911 GT3 R']],
        ])
        ->and($lastVote['week'])->toMatchArray([
            'id' => $closed->id,
            'startsAt' => '2026-11-09T00:00:00+01:00',
            'endsAt' => '2026-11-16T00:00:00+01:00',
        ]);
});

test('the last vote explains a default configuration', function () {
    $closed = resolvedWeek(['config_source' => ConfigSource::Fallback, 'track_id' => $this->track->id, 'max_players' => 20]);
    $car = Car::factory()->create(['display_name' => 'BMW M4 GT3']);
    $closed->cars()->attach($car->id);

    $lastVote = proposalsPageProps()['lastVote'];

    expect($lastVote['source'])->toBe('fallback')
        ->and($lastVote['winnerId'])->toBeNull()
        ->and($lastVote['ranking'])->toBe([])
        ->and($lastVote['config'])->toBe([
            'track' => ['display' => 'Monza', 'maxPitSlot' => 20],
            'cars' => [['id' => $car->id, 'displayName' => 'BMW M4 GT3']],
            'maxPlayers' => 20,
        ]);
});

test('the last vote explains an unconfigured week', function () {
    resolvedWeek(['config_source' => ConfigSource::Fallback]);

    $lastVote = proposalsPageProps()['lastVote'];

    expect($lastVote['source'])->toBe('fallback')
        ->and($lastVote['config'])->toBeNull();
});

test('without an open voting only the closed result is sent', function () {
    $this->week->update(['status' => WeekStatus::Scheduled]);
    resolvedWeek(['config_source' => ConfigSource::Fallback]);

    $props = proposalsPageProps();

    expect($props['week'])->toBeNull()
        ->and($props['proposals'])->toBe([])
        ->and($props['lastVote']['source'])->toBe('fallback');
});

test('the last vote exposes no steam id or email', function () {
    $closed = resolvedWeek();
    $author = User::factory()->withVerifiedEmail()->withSteam('76561198000000810')->create(['email' => 'closed-author@example.test']);
    $winner = proposalWithVotes($closed, 2, ['user_id' => $author->id]);
    $closed->update(['proposal_id' => $winner->id]);

    $content = $this->get(route('proposals'))->assertOk()->getContent();

    expect($content)->toContain('lastVote')
        ->and($content)->not->toContain('76561198000000810')
        ->and($content)->not->toContain('closed-author@example.test')
        ->and($content)->not->toContain('steam_id');
});

test('the page with an open voting and a last vote stays within its query budget', function () {
    $closed = resolvedWeek(['config_source' => ConfigSource::Fallback, 'track_id' => $this->track->id]);
    proposalWithVotes($closed, 2);
    proposalWithVotes($this->week, 2);
    $user = User::factory()->withSteam()->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)->get(route('proposals'))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(12);
});
