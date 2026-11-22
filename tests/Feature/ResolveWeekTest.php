<?php

declare(strict_types=1);

use App\Actions\Weeks\ResolveWeek;
use App\Enums\ConfigSource;
use App\Enums\WeekStatus;
use App\Mail\WeekWithoutWinnerMail;
use App\Models\Car;
use App\Models\Proposal;
use App\Models\Server;
use App\Models\ServerDefaultConfig;
use App\Models\Track;
use App\Models\User;
use App\Models\Vote;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();

    $this->server = Server::factory()->create(['max_players' => 24]);
    $this->week = Week::factory()
        ->for($this->server)
        ->startingAt(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'))
        ->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'));
    $this->track = Track::factory()->withPracticePair()->create(['max_pit_slot' => 24]);
    $this->cars = Car::factory()->count(2)->create();
});

function weekProposal(Week $week, int $votes, array $attributes = [], ?array $cars = null): Proposal
{
    $proposal = Proposal::factory()->withCars($cars ?? test()->cars->all())->create([
        'week_id' => $week->id,
        'track_id' => test()->track->id,
        ...$attributes,
    ]);
    Vote::factory()->count($votes)->create(['proposal_id' => $proposal->id]);

    return $proposal;
}

function resolveWeek(Week $week): bool
{
    return app(ResolveWeek::class)->handle($week->id);
}

test('the proposal with most votes wins and configures the week', function () {
    weekProposal($this->week, 1, ['signature' => 'a']);
    $winner = weekProposal($this->week, 3, ['signature' => 'b']);

    expect(resolveWeek($this->week))->toBeTrue();

    $week = $this->week->fresh();

    expect($week->status)->toBe(WeekStatus::Scheduled)
        ->and($week->proposal_id)->toBe($winner->id)
        ->and($week->track_id)->toBe($this->track->id)
        ->and($week->config_source)->toBe(ConfigSource::Proposal)
        ->and($week->max_players)->toBe(24)
        ->and($week->resolved_at)->not->toBeNull()
        ->and($week->cars()->pluck('cars.id')->sort()->values()->all())->toBe($this->cars->pluck('id')->sort()->values()->all());
    Mail::assertNothingQueued();
});

test('a tie is broken by the oldest proposal and then by id', function () {
    $older = weekProposal($this->week, 2, ['signature' => 'a', 'created_at' => now()->subHour()]);
    weekProposal($this->week, 2, ['signature' => 'b', 'created_at' => now()]);

    resolveWeek($this->week);

    expect($this->week->fresh()->proposal_id)->toBe($older->id);
});

test('a tie on age is broken by the lowest id', function () {
    $moment = now()->subMinute();
    $first = weekProposal($this->week, 2, ['signature' => 'a', 'created_at' => $moment]);
    weekProposal($this->week, 2, ['signature' => 'b', 'created_at' => $moment]);

    resolveWeek($this->week);

    expect($this->week->fresh()->proposal_id)->toBe($first->id);
});

test('a hidden proposal that was first is skipped and its votes do not count', function () {
    weekProposal($this->week, 5, ['signature' => 'a', 'hidden_at' => now()]);
    $visible = weekProposal($this->week, 1, ['signature' => 'b']);

    resolveWeek($this->week);

    expect($this->week->fresh()->proposal_id)->toBe($visible->id);
});

test('the week max players is the lowest of the server and the track slots', function () {
    $small = Track::factory()->withPracticePair()->create(['max_pit_slot' => 16]);
    $proposal = weekProposal($this->week, 1, ['track_id' => $small->id]);

    resolveWeek($this->week);

    expect($this->week->fresh()->max_players)->toBe(16)
        ->and($this->week->fresh()->proposal_id)->toBe($proposal->id);
});

test('without proposals the week is left to be announced and admins are told', function () {
    User::factory()->admin()->create();

    resolveWeek($this->week);

    $week = $this->week->fresh();

    expect($week->status)->toBe(WeekStatus::Scheduled)
        ->and($week->config_source)->toBe(ConfigSource::Fallback)
        ->and($week->track_id)->toBeNull()
        ->and($week->max_players)->toBeNull()
        ->and($week->proposal_id)->toBeNull()
        ->and($week->cars()->count())->toBe(0);
    Mail::assertQueued(WeekWithoutWinnerMail::class, 1);
});

test('proposals without votes do not win', function () {
    weekProposal($this->week, 0);

    resolveWeek($this->week);

    expect($this->week->fresh()->config_source)->toBe(ConfigSource::Fallback);
});

test('with every proposal hidden there is no winner', function () {
    weekProposal($this->week, 3, ['hidden_at' => now()]);

    resolveWeek($this->week);

    expect($this->week->fresh()->config_source)->toBe(ConfigSource::Fallback);
});

test('without a winner the server default configuration is applied', function () {
    $admin = User::factory()->admin()->create();
    $defaultTrack = Track::factory()->withPracticePair()->create(['max_pit_slot' => 12]);
    $default = ServerDefaultConfig::factory()->for($this->server)->create(['track_id' => $defaultTrack->id, 'custom_title' => 'Casual night']);
    $default->cars()->attach($this->cars->pluck('id'));

    resolveWeek($this->week);

    $week = $this->week->fresh();

    expect($week->status)->toBe(WeekStatus::Scheduled)
        ->and($week->config_source)->toBe(ConfigSource::Fallback)
        ->and($week->track_id)->toBe($defaultTrack->id)
        ->and($week->custom_title)->toBe('Casual night')
        ->and($week->max_players)->toBe(12)
        ->and($week->proposal_id)->toBeNull()
        ->and($week->resolved_at)->not->toBeNull()
        ->and($week->cars()->pluck('cars.id')->sort()->values()->all())->toBe($this->cars->pluck('id')->sort()->values()->all());
    Mail::assertQueued(WeekWithoutWinnerMail::class, fn (WeekWithoutWinnerMail $mail) => $mail->hasTo($admin->email)
        && $mail->source === ConfigSource::Fallback
        && str_contains($mail->render(), __('mail.week.default')));
});

test('without a winner and without a default configuration the week has no track', function () {
    $admin = User::factory()->admin()->create();
    Week::factory()
        ->for($this->server)
        ->startingAt(CarbonImmutable::parse('2026-11-09 00:00:00', 'Europe/Madrid'))
        ->status(WeekStatus::Active)
        ->create(['track_id' => $this->track->id]);

    resolveWeek($this->week);

    $week = $this->week->fresh();

    expect($week->config_source)->toBe(ConfigSource::Fallback)
        ->and($week->track_id)->toBeNull()
        ->and($week->max_players)->toBeNull()
        ->and($week->cars()->count())->toBe(0);
    Mail::assertQueued(WeekWithoutWinnerMail::class, fn (WeekWithoutWinnerMail $mail) => $mail->hasTo($admin->email)
        && str_contains($mail->render(), __('mail.week.fallback')));
});

test('changing the default configuration later does not alter a resolved week', function () {
    $default = ServerDefaultConfig::factory()->for($this->server)->create(['track_id' => $this->track->id, 'custom_title' => 'Before']);
    $default->cars()->attach($this->cars->pluck('id'));

    resolveWeek($this->week);

    $otherTrack = Track::factory()->withPracticePair()->create(['max_pit_slot' => 10]);
    $default->update(['track_id' => $otherTrack->id, 'custom_title' => 'After']);
    $default->cars()->detach();
    resolveWeek($this->week);

    $week = $this->week->fresh();

    expect($week->track_id)->toBe($this->track->id)
        ->and($week->custom_title)->toBe('Before')
        ->and($week->cars()->count())->toBe(2);
});

test('resolving twice gives the same result and a single email', function () {
    User::factory()->admin()->create();

    expect(resolveWeek($this->week))->toBeTrue()
        ->and(resolveWeek($this->week))->toBeFalse();

    Mail::assertQueued(WeekWithoutWinnerMail::class, 1);
});

test('a second run does not change a resolved week even if votes change', function () {
    $winner = weekProposal($this->week, 1, ['signature' => 'a']);
    $other = weekProposal($this->week, 0, ['signature' => 'b']);

    resolveWeek($this->week);
    Vote::factory()->count(5)->create(['proposal_id' => $other->id]);
    resolveWeek($this->week);

    expect($this->week->fresh()->proposal_id)->toBe($winner->id);
});

test('it does not resolve before the voting closes', function () {
    weekProposal($this->week, 1);
    $this->travelTo(CarbonImmutable::parse('2026-11-15 23:59:59', 'Europe/Madrid'));

    expect(resolveWeek($this->week))->toBeFalse()
        ->and($this->week->fresh()->status)->toBe(WeekStatus::Voting);
});

test('a vote at the last second counts and one at midnight is rejected', function () {
    $first = weekProposal($this->week, 1, ['signature' => 'a']);
    $second = weekProposal($this->week, 0, ['signature' => 'b']);
    $early = User::factory()->withSteam('76561198000000901')->create();
    $late = User::factory()->withSteam('76561198000000902')->create();

    $this->travelTo(CarbonImmutable::parse('2026-11-15 23:59:59', 'Europe/Madrid'));
    $this->actingAs($early)->post(route('proposals.vote', $second))->assertSessionHasNoErrors();

    $this->travelTo(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'));
    $this->actingAs($late)->post(route('proposals.vote', $second))->assertSessionHasErrors();

    resolveWeek($this->week);

    expect(Vote::query()->where('proposal_id', $second->id)->count())->toBe(1)
        ->and($this->week->fresh()->proposal_id)->toBe($first->id);
});

test('no vote after the resolution changes the result', function () {
    $winner = weekProposal($this->week, 1, ['signature' => 'a']);
    $other = weekProposal($this->week, 0, ['signature' => 'b']);
    $voters = collect(['76561198000000911', '76561198000000912', '76561198000000913'])
        ->map(fn (string $steamId) => User::factory()->withSteam($steamId)->create());

    resolveWeek($this->week);

    $this->travelTo(CarbonImmutable::parse('2026-11-15 23:59:59', 'Europe/Madrid'));

    foreach ($voters as $voter) {
        $this->actingAs($voter)->post(route('proposals.vote', $other));
    }

    expect(Vote::query()->where('proposal_id', $other->id)->count())->toBe(0)
        ->and($this->week->fresh()->proposal_id)->toBe($winner->id);
});

test('the resolve command handles one week or every pending one', function () {
    $winner = weekProposal($this->week, 1);

    $this->artisan('weeks:resolve', ['week' => $this->week->id])->assertSuccessful();
    $this->artisan('weeks:resolve', ['week' => $this->week->id])->assertFailed();
    $this->artisan('weeks:resolve')->assertSuccessful();

    expect($this->week->fresh()->proposal_id)->toBe($winner->id);
});
