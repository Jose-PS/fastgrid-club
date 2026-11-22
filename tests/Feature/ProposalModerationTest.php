<?php

declare(strict_types=1);

use App\Actions\Proposals\HideProposal;
use App\Actions\Proposals\ShowProposal;
use App\Actions\Weeks\ResolveWeek;
use App\Models\Proposal;
use App\Models\User;
use App\Models\Vote;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));

    $this->week = Week::factory()
        ->startingAt(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'))
        ->create();
    $this->proposal = Proposal::factory()->create(['week_id' => $this->week->id]);
    $this->admin = User::factory()->admin()->create();
});

function proposalListIds(): array
{
    return collect(test()->get(route('proposals'))->inertiaProps()['proposals'])->pluck('id')->all();
}

test('an admin hides a proposal with a reason and shows it again', function () {
    app(HideProposal::class)->handle($this->admin, $this->proposal, 'spam');

    $proposal = $this->proposal->fresh();

    expect($proposal->hidden_at)->not->toBeNull()
        ->and($proposal->hidden_by)->toBe($this->admin->id)
        ->and($proposal->hidden_reason)->toBe('spam');

    app(ShowProposal::class)->handle($this->admin, $this->proposal);

    $proposal = $this->proposal->fresh();

    expect($proposal->hidden_at)->toBeNull()
        ->and($proposal->hidden_by)->toBeNull()
        ->and($proposal->hidden_reason)->toBeNull();
});

test('only an admin can hide or show a proposal', function () {
    $user = User::factory()->withSteam()->create();

    expect(Gate::forUser($user)->allows('hide', $this->proposal))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('hide', $this->proposal))->toBeTrue()
        ->and(fn () => app(HideProposal::class)->handle($user, $this->proposal, 'spam'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ShowProposal::class)->handle($user, $this->proposal))->toThrow(AuthorizationException::class)
        ->and($this->proposal->fresh()->hidden_at)->toBeNull();
});

test('hiding and showing refresh the cached listing', function () {
    expect(proposalListIds())->toBe([$this->proposal->id]);

    app(HideProposal::class)->handle($this->admin, $this->proposal, 'spam');

    expect(proposalListIds())->toBe([]);

    app(ShowProposal::class)->handle($this->admin, $this->proposal);

    expect(proposalListIds())->toBe([$this->proposal->id]);
});

test('a vote in a hidden proposal stops counting but is kept', function () {
    $other = Proposal::factory()->create(['week_id' => $this->week->id]);
    Vote::factory()->count(2)->create(['proposal_id' => $this->proposal->id]);
    Vote::factory()->create(['proposal_id' => $other->id]);

    app(HideProposal::class)->handle($this->admin, $this->proposal, 'spam');

    $props = $this->get(route('proposals'))->inertiaProps();

    expect(collect($props['proposals'])->pluck('votes', 'id')->all())->toBe([$other->id => 1])
        ->and(Vote::query()->where('proposal_id', $this->proposal->id)->count())->toBe(2);

    $this->travelTo(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'));
    app(ResolveWeek::class)->handle($this->week->id);

    expect($this->week->fresh()->proposal_id)->toBe($other->id);
});

test('hiding the leading proposal makes the resolution skip it', function () {
    $other = Proposal::factory()->create(['week_id' => $this->week->id]);
    Vote::factory()->count(3)->create(['proposal_id' => $this->proposal->id]);
    Vote::factory()->create(['proposal_id' => $other->id]);

    app(HideProposal::class)->handle($this->admin, $this->proposal, 'offensive');
    $this->travelTo(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'));
    app(ResolveWeek::class)->handle($this->week->id);

    expect($this->week->fresh()->proposal_id)->toBe($other->id);
});
