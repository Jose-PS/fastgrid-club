<?php

declare(strict_types=1);

use App\Enums\WeekStatus;
use App\Models\Proposal;
use App\Models\User;
use App\Models\Vote;
use App\Models\Week;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));

    $this->user = User::factory()->withVerifiedEmail()->withSteam('76561198000000700')->create();
    $this->open = Week::factory()->startingAt(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'))->create();
    $this->closed = Week::factory()->startingAt(CarbonImmutable::parse('2026-11-02 00:00:00', 'Europe/Madrid'))->status(WeekStatus::Finished)->create();

    $this->openOwn = Proposal::factory()->create(['week_id' => $this->open->id, 'user_id' => $this->user->id]);
    $this->openOther = Proposal::factory()->create(['week_id' => $this->open->id]);
    $this->closedOwn = Proposal::factory()->create(['week_id' => $this->closed->id, 'user_id' => $this->user->id]);
    $this->closedOther = Proposal::factory()->create(['week_id' => $this->closed->id]);

    Vote::factory()->create(['proposal_id' => $this->openOwn->id]);
    Vote::factory()->create(['proposal_id' => $this->openOther->id, 'user_id' => $this->user->id, 'steam_id' => '76561198000000700']);
    Vote::factory()->count(2)->create(['proposal_id' => $this->closedOwn->id]);
    Vote::factory()->create(['proposal_id' => $this->closedOther->id, 'user_id' => $this->user->id, 'steam_id' => '76561198000000700']);
});

function closedTally(Week $week): array
{
    return Vote::query()
        ->where('week_id', $week->id)
        ->selectRaw('proposal_id, count(*) as total')
        ->groupBy('proposal_id')
        ->orderBy('proposal_id')
        ->pluck('total', 'proposal_id')
        ->map(fn ($total): int => (int) $total)
        ->all();
}

function expectAnonymized(object $test, array $tallyBefore): void
{
    expect(Proposal::query()->whereKey($test->openOwn->id)->exists())->toBeFalse()
        ->and(Vote::query()->where('week_id', $test->open->id)->count())->toBe(0)
        ->and(Proposal::query()->whereKey($test->openOther->id)->exists())->toBeTrue()
        ->and($test->closedOwn->fresh()->user_id)->toBeNull()
        ->and(closedTally($test->closed))->toBe($tallyBefore)
        ->and(Vote::query()->where('steam_id', '76561198000000700')->exists())->toBeFalse()
        ->and(Vote::query()->where('user_id', $test->user->id)->exists())->toBeFalse();
}

test('deleting the account removes open week data and anonymizes closed weeks', function () {
    $tallyBefore = closedTally($this->closed);

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->delete(route('account.destroy'), ['confirmation' => 'BORRAR'])
        ->assertRedirect(route('en.home'));

    expectAnonymized($this, $tallyBefore);
});

test('unlinking steam removes open week data and anonymizes closed weeks', function () {
    $tallyBefore = closedTally($this->closed);

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->delete(route('account.identities.destroy', 'steam'))
        ->assertRedirect(route('account.show'));

    expectAnonymized($this, $tallyBefore);
    expect($this->user->fresh())->not->toBeNull();
});

test('deleting a google only account removes its open week vote and keeps closed tallies', function () {
    $googleUser = User::factory()->withVerifiedEmail()->withGoogle()->create();
    Vote::factory()->create(['proposal_id' => $this->openOther->id, 'user_id' => $googleUser->id, 'steam_id' => null]);
    Vote::factory()->create(['proposal_id' => $this->closedOther->id, 'user_id' => $googleUser->id, 'steam_id' => null]);
    $openVotesBefore = $this->openOther->votes()->count();
    $tallyBefore = closedTally($this->closed);

    $this->actingAs($googleUser)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->delete(route('account.destroy'), ['confirmation' => 'BORRAR'])
        ->assertRedirect(route('en.home'));

    expect($this->openOther->votes()->count())->toBe($openVotesBefore - 1)
        ->and($this->openOther->votes()->where('user_id', $this->user->id)->exists())->toBeTrue()
        ->and(closedTally($this->closed))->toBe($tallyBefore);
});
