<?php

declare(strict_types=1);

use App\Enums\WeekStatus;
use App\Models\Proposal;
use App\Models\User;
use App\Models\Vote;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withHeader('Accept-Language', 'es');
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));

    $this->week = Week::factory()
        ->startingAt(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'))
        ->create();
    $this->first = Proposal::factory()->create(['week_id' => $this->week->id]);
    $this->second = Proposal::factory()->create(['week_id' => $this->week->id]);
    $this->voter = User::factory()->withSteam('76561198000000500')->create();
});

function votingToast(): ?string
{
    return test()->get(route('proposals'))->inertiaPage()['flash']['toast']['message'] ?? null;
}

test('a new vote is registered for the steam id', function () {
    $this->actingAs($this->voter)
        ->post(route('proposals.vote', $this->first))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('proposals'));

    $vote = Vote::query()->sole();

    expect($vote->proposal_id)->toBe($this->first->id)
        ->and($vote->week_id)->toBe($this->week->id)
        ->and($vote->steam_id)->toBe('76561198000000500')
        ->and($vote->user_id)->toBe($this->voter->id)
        ->and(votingToast())->toBe(__('props.voted'));
});

test('voting the same proposal again changes nothing', function () {
    $this->actingAs($this->voter)->post(route('proposals.vote', $this->first));
    $before = Vote::query()->sole()->updated_at;

    $this->travel(5)->seconds();

    $this->actingAs($this->voter)->post(route('proposals.vote', $this->first))->assertSessionHasNoErrors();

    $vote = Vote::query()->sole();

    expect($vote->proposal_id)->toBe($this->first->id)
        ->and($vote->updated_at->equalTo($before))->toBeTrue();
});

test('voting another proposal moves the vote', function () {
    $this->actingAs($this->voter)->post(route('proposals.vote', $this->first));

    $this->actingAs($this->voter)->post(route('proposals.vote', $this->second))->assertSessionHasNoErrors();

    expect(Vote::query()->sole()->proposal_id)->toBe($this->second->id)
        ->and($this->first->votes()->count())->toBe(0)
        ->and($this->second->votes()->count())->toBe(1)
        ->and(votingToast())->toBe(__('props.moved'));
});

test('a hidden proposal does not accept votes', function () {
    $hidden = Proposal::factory()->hidden()->create(['week_id' => $this->week->id]);

    $this->actingAs($this->voter)
        ->post(route('proposals.vote', $hidden))
        ->assertSessionHasErrors(['vote' => __('props.unavailable')]);

    expect(Vote::query()->count())->toBe(0);
});

test('a proposal of a week that is no longer voting does not accept votes', function () {
    $this->week->update(['status' => WeekStatus::Scheduled]);

    $this->actingAs($this->voter)
        ->post(route('proposals.vote', $this->first))
        ->assertSessionHasErrors(['vote' => __('props.closed')]);

    expect(Vote::query()->count())->toBe(0);
});

test('votes are accepted until the last second and rejected right after closing', function (string $moment, bool $accepted) {
    $this->travelTo(CarbonImmutable::parse($moment, 'Europe/Madrid'));

    $response = $this->actingAs($this->voter)->post(route('proposals.vote', $this->first));

    if ($accepted) {
        $response->assertSessionHasNoErrors();
    } else {
        $response->assertSessionHasErrors(['vote' => __('props.closed')]);
    }

    expect(Vote::query()->count())->toBe($accepted ? 1 : 0);
})->with([
    'last second' => ['2026-11-15 23:59:59', true],
    'right after closing' => ['2026-11-16 00:00:00', false],
]);

test('the database allows a single vote per week and user', function () {
    Vote::factory()->create(['proposal_id' => $this->first->id, 'user_id' => $this->voter->id, 'steam_id' => null]);

    expect(fn () => Vote::factory()->create(['proposal_id' => $this->second->id, 'user_id' => $this->voter->id, 'steam_id' => null]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('votes of deleted accounts can coexist in the same week', function () {
    Vote::factory()->count(2)->create(['proposal_id' => $this->first->id, 'user_id' => null, 'steam_id' => null]);

    expect($this->first->votes()->count())->toBe(2);
});

test('a user with only google can vote once per week and stores no steam id', function () {
    $voter = User::factory()->withGoogle()->create();

    $this->actingAs($voter)->post(route('proposals.vote', $this->first))->assertSessionHasNoErrors();
    $this->actingAs($voter)->post(route('proposals.vote', $this->second))->assertSessionHasNoErrors();

    $vote = Vote::query()->sole();

    expect($vote->user_id)->toBe($voter->id)
        ->and($vote->proposal_id)->toBe($this->second->id)
        ->and($vote->steam_id)->toBeNull();
});

test('a concurrent insert of the same user leaves a single row', function () {
    $raced = false;

    DB::connection()->beforeExecuting(function (string $query) use (&$raced): void {
        if ($raced || ! str_contains($query, 'insert into "votes"')) {
            return;
        }

        $raced = true;

        DB::table('votes')->insert([
            'week_id' => $this->week->id,
            'proposal_id' => $this->second->id,
            'user_id' => $this->voter->id,
            'steam_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($this->voter)->post(route('proposals.vote', $this->first))->assertSessionHasNoErrors();

    expect($raced)->toBeTrue()
        ->and(Vote::query()->count())->toBe(1)
        ->and(Vote::query()->sole()->proposal_id)->toBe($this->first->id);
});

test('guests and users without steam nor google cannot vote', function () {
    $this->postJson(route('proposals.vote', $this->first))->assertUnauthorized();

    $this->actingAs(User::factory()->withVerifiedEmail()->create())
        ->postJson(route('proposals.vote', $this->first))
        ->assertForbidden()
        ->assertJson(['message' => __('props.needsIdentity')]);

    expect(Vote::query()->count())->toBe(0);
});

test('votes are rate limited per user', function () {
    foreach (range(1, 30) as $attempt) {
        $this->actingAs($this->voter)->post(route('proposals.vote', $attempt % 2 === 0 ? $this->first : $this->second))->assertRedirect();
    }

    $this->actingAs($this->voter)->post(route('proposals.vote', $this->first))->assertTooManyRequests();
});

test('the author withdraws a proposal that only has their own vote', function () {
    $author = $this->first->user;
    Vote::factory()->create(['proposal_id' => $this->first->id, 'user_id' => $author->id, 'steam_id' => $author->steam_id]);

    $this->actingAs($author)
        ->delete(route('proposals.destroy', $this->first))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('proposals'));

    expect(Proposal::query()->whereKey($this->first->id)->exists())->toBeFalse()
        ->and(Vote::query()->count())->toBe(0)
        ->and(votingToast())->toBe(__('props.withdrawn'));
});

test('the author cannot withdraw a proposal voted by another user or by a deleted account', function (bool $deletedVoter) {
    $voter = $deletedVoter ? null : User::factory()->withGoogle()->create();
    Vote::factory()->create(['proposal_id' => $this->first->id, 'user_id' => $voter?->id, 'steam_id' => null]);

    $this->actingAs($this->first->user)->delete(route('proposals.destroy', $this->first))->assertForbidden();

    expect(Proposal::query()->whereKey($this->first->id)->exists())->toBeTrue();
})->with([
    'another user' => [false],
    'deleted account' => [true],
]);

test('another user cannot withdraw a proposal', function () {
    $this->actingAs($this->voter)->delete(route('proposals.destroy', $this->first))->assertForbidden();

    expect(Proposal::query()->whereKey($this->first->id)->exists())->toBeTrue();
});

test('only admins pass the hide policy', function () {
    expect(User::factory()->admin()->create()->can('hide', $this->first))->toBeTrue()
        ->and($this->voter->can('hide', $this->first))->toBeFalse();
});
