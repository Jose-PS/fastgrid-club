<?php

declare(strict_types=1);

use App\Actions\Weeks\ResolveWeek;
use App\Enums\ConfigSource;
use App\Enums\WeekStatus;
use App\Models\AuditLog;
use App\Models\Car;
use App\Models\Proposal;
use App\Models\Server;
use App\Models\Track;
use App\Models\User;
use App\Models\Vote;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));
    $this->server = Server::factory()->create(['is_active' => true, 'max_players' => 24]);
    $this->admin = User::factory()->admin()->create();
    $this->track = Track::factory()->withPracticePair()->create(['max_pit_slot' => 16]);
    $this->cars = Car::factory()->count(3)->create();
    $this->current = Week::factory()->for($this->server)->status(WeekStatus::Active)->create();
    $this->next = Week::factory()->for($this->server)->startingAt(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'))->create();
});

function forcePayload(array $overrides = []): array
{
    return array_merge([
        'track_id' => test()->track->id,
        'cars' => test()->cars->take(2)->pluck('id')->all(),
        'custom_title' => 'Noche especial',
    ], $overrides);
}

function resolvedFallbackWeek(): Week
{
    $week = Week::factory()->for(test()->server)->startingAt(CarbonImmutable::parse('2026-11-23 00:00:00', 'Europe/Madrid'))->create();
    $week->update(['status' => WeekStatus::Scheduled, 'config_source' => ConfigSource::Fallback, 'resolved_at' => now()]);

    return $week;
}

test('guests and non admins get not found on every week route', function () {
    $proposal = Proposal::factory()->create(['week_id' => $this->next->id]);

    $this->get(route('admin.weeks.index'))->assertNotFound();
    $this->put(route('admin.weeks.update', $this->next), forcePayload())->assertNotFound();
    $this->post(route('admin.weeks.winner', $this->next), ['proposal_id' => $proposal->id])->assertNotFound();

    $this->actingAs(User::factory()->create());
    $this->get(route('admin.weeks.index'))->assertNotFound();
    $this->put(route('admin.weeks.update', $this->next), forcePayload())->assertNotFound();
    $this->post(route('admin.weeks.winner', $this->next), ['proposal_id' => $proposal->id])->assertNotFound();

    expect($this->next->fresh()->config_source)->toBeNull();
});

test('the listing shows the latest weeks with their state', function () {
    $this->actingAs($this->admin)->get(route('admin.weeks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/weeks')
            ->has('weeks', 2)
            ->where('weeks.0.id', $this->next->id)
            ->where('weeks.0.status', 'voting')
            ->where('weeks.0.is_current', false)
            ->where('weeks.0.editable', true)
            ->where('weeks.1.id', $this->current->id)
            ->where('weeks.1.is_current', true)
            ->has('options.tracks', 1));
});

test('the listing is limited to twenty weeks', function () {
    foreach (range(1, 25) as $i) {
        Week::factory()->for($this->server)->startingAt(CarbonImmutable::parse('2026-11-23 00:00:00', 'Europe/Madrid')->addWeeks($i))->create();
    }

    $this->actingAs($this->admin)->get(route('admin.weeks.index'))
        ->assertInertia(fn (Assert $page) => $page->has('weeks', 20));
});

test('forcing a week sets the admin configuration with cars title and capacity', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.weeks.update', $this->current), forcePayload(['custom_title' => ' Noche especial ']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $week = $this->current->fresh();

    expect($week->config_source)->toBe(ConfigSource::Admin)
        ->and($week->proposal_id)->toBeNull()
        ->and($week->track_id)->toBe($this->track->id)
        ->and($week->custom_title)->toBe('Noche especial')
        ->and($week->max_players)->toBe(16)
        ->and($week->resolved_at)->not->toBeNull()
        ->and($week->cars()->pluck('cars.id')->sort()->values()->all())->toBe($this->cars->take(2)->pluck('id')->sort()->values()->all());
});

test('forcing keeps the original resolution time and replaces a previous winner', function () {
    $resolvedAt = CarbonImmutable::parse('2026-11-09 10:00:00');
    $proposal = Proposal::factory()->create(['week_id' => $this->current->id]);
    $this->current->update(['config_source' => ConfigSource::Proposal, 'proposal_id' => $proposal->id, 'resolved_at' => $resolvedAt, 'track_id' => $proposal->track_id]);

    $this->actingAs($this->admin)->put(route('admin.weeks.update', $this->current), forcePayload(['custom_title' => '']))->assertSessionHasNoErrors();

    $week = $this->current->fresh();

    expect($week->proposal_id)->toBeNull()
        ->and($week->custom_title)->toBeNull()
        ->and($week->resolved_at->equalTo($resolvedAt))->toBeTrue();
});

test('forcing a week in voting leaves it open and resolution does not overwrite it', function () {
    $proposal = Proposal::factory()->withCars([$this->cars[2]])->create(['week_id' => $this->next->id]);
    Vote::factory()->count(3)->create(['proposal_id' => $proposal->id]);

    $this->actingAs($this->admin)->put(route('admin.weeks.update', $this->next), forcePayload())->assertSessionHasNoErrors();

    $forced = $this->next->fresh();

    expect($forced->status)->toBe(WeekStatus::Voting)
        ->and($forced->resolved_at)->toBeNull();

    $this->travelTo($forced->voting_closes_at->addMinute());

    expect(app(ResolveWeek::class)->handle($forced->id))->toBeTrue();

    $week = $this->next->fresh();

    expect($week->status)->toBe(WeekStatus::Scheduled)
        ->and($week->config_source)->toBe(ConfigSource::Admin)
        ->and($week->proposal_id)->toBeNull()
        ->and($week->track_id)->toBe($this->track->id)
        ->and($week->custom_title)->toBe('Noche especial')
        ->and($week->resolved_at)->not->toBeNull()
        ->and($week->cars()->pluck('cars.id')->sort()->values()->all())->toBe($this->cars->take(2)->pluck('id')->sort()->values()->all());

    Mail::assertNothingSent();
});

test('forcing validates like a proposal', function (Closure $payload, string $field) {
    $this->actingAs($this->admin)
        ->put(route('admin.weeks.update', $this->next), $payload())
        ->assertSessionHasErrors($field);

    expect($this->next->fresh()->config_source)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'missing track' => [fn () => forcePayload(['track_id' => null]), 'track_id'],
    'track without practice pair' => [fn () => forcePayload(['track_id' => Track::factory()->create()->id]), 'track_id'],
    'no cars' => [fn () => forcePayload(['cars' => []]), 'cars'],
    'repeated cars' => [fn () => forcePayload(['cars' => [test()->cars[0]->id, test()->cars[0]->id]]), 'cars'],
    'not proposable car' => [fn () => forcePayload(['cars' => [Car::factory()->notProposable()->create()->id]]), 'cars'],
    'long title' => [fn () => forcePayload(['custom_title' => Str::repeat('a', 256)]), 'custom_title'],
]);

test('a finished week cannot be forced', function () {
    $finished = Week::factory()->for($this->server)->status(WeekStatus::Finished)->startingAt(CarbonImmutable::parse('2026-11-02 00:00:00', 'Europe/Madrid'))->create();
    $over = Week::factory()->for($this->server)->status(WeekStatus::Active)->startingAt(CarbonImmutable::parse('2026-10-26 00:00:00', 'Europe/Madrid'))->create();

    foreach ([$finished, $over] as $week) {
        $this->actingAs($this->admin)->put(route('admin.weeks.update', $week), forcePayload())->assertSessionHasErrors('week');

        expect($week->fresh()->config_source)->toBeNull();
    }

    expect(AuditLog::query()->count())->toBe(0);
});

test('a week of an inactive server cannot be forced', function () {
    $other = Week::factory()->for(Server::factory()->create(['is_active' => false]))->create();

    $this->actingAs($this->admin)->put(route('admin.weeks.update', $other), forcePayload())->assertNotFound();
});

test('forcing is audited with before and after', function () {
    $this->actingAs($this->admin)->put(route('admin.weeks.update', $this->current), forcePayload())->assertSessionHasNoErrors();

    $log = AuditLog::query()->where('action', 'week.forced')->sole();

    expect($log->actor_id)->toBe($this->admin->id)
        ->and($log->subject_id)->toBe($this->current->id)
        ->and($log->before)->toBe(['track_id' => null, 'car_ids' => [], 'custom_title' => null, 'config_source' => null, 'proposal_id' => null])
        ->and($log->after)->toBe([
            'track_id' => $this->track->id,
            'car_ids' => $this->cars->take(2)->pluck('id')->sort()->values()->all(),
            'custom_title' => 'Noche especial',
            'config_source' => 'admin',
            'proposal_id' => null,
        ]);
});

test('resolution leaves an already resolved admin week untouched', function () {
    $this->actingAs($this->admin)->put(route('admin.weeks.update', $this->current), forcePayload())->assertSessionHasNoErrors();
    $before = $this->current->fresh();

    expect(app(ResolveWeek::class)->handle($this->current->id))->toBeFalse()
        ->and($this->current->fresh()->updated_at->equalTo($before->updated_at))->toBeTrue();
});

test('only weeks resolved without a winner offer candidates', function () {
    $week = resolvedFallbackWeek();
    $visible = Proposal::factory()->withCars([$this->cars[0]])->create(['week_id' => $week->id]);
    Proposal::factory()->hidden()->create(['week_id' => $week->id]);

    $this->actingAs($this->admin)->get(route('admin.weeks.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('weeks.0.id', $week->id)
            ->has('weeks.0.proposals', 1)
            ->where('weeks.0.proposals.0.id', $visible->id)
            ->where('weeks.1.proposals', null));
});

test('choosing a winner applies the proposal as admin and is audited', function () {
    $week = resolvedFallbackWeek();
    $week->update(['custom_title' => 'Por defecto']);
    $proposal = Proposal::factory()->withCars([$this->cars[0], $this->cars[1]])->create(['week_id' => $week->id, 'track_id' => $this->track->id]);

    $this->actingAs($this->admin)
        ->post(route('admin.weeks.winner', $week), ['proposal_id' => $proposal->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $fresh = $week->fresh();

    expect($fresh->config_source)->toBe(ConfigSource::Admin)
        ->and($fresh->proposal_id)->toBe($proposal->id)
        ->and($fresh->track_id)->toBe($this->track->id)
        ->and($fresh->max_players)->toBe(16)
        ->and($fresh->custom_title)->toBeNull()
        ->and($fresh->resolved_at)->not->toBeNull()
        ->and($fresh->status)->toBe(WeekStatus::Scheduled)
        ->and($fresh->cars()->pluck('cars.id')->sort()->values()->all())->toBe([$this->cars[0]->id, $this->cars[1]->id]);

    $log = AuditLog::query()->where('action', 'week.winner_chosen')->sole();

    expect($log->before['config_source'])->toBe('fallback')
        ->and($log->after['config_source'])->toBe('admin')
        ->and($log->after['proposal_id'])->toBe($proposal->id);
});

test('a winner cannot be chosen in invalid situations', function (Closure $setup, string $field) {
    [$week, $proposalId] = $setup();

    $this->actingAs($this->admin)
        ->post(route('admin.weeks.winner', $week), ['proposal_id' => $proposalId])
        ->assertSessionHasErrors($field);

    expect(AuditLog::query()->count())->toBe(0)
        ->and($week->fresh()->proposal_id)->toBeNull();
})->with([
    'week with a winner' => [function () {
        $week = resolvedFallbackWeek();
        $proposal = Proposal::factory()->create(['week_id' => $week->id]);
        $week->update(['config_source' => ConfigSource::Proposal, 'proposal_id' => $proposal->id]);
        $other = Proposal::factory()->create(['week_id' => $week->id]);
        $week->update(['proposal_id' => null]);

        return [$week, $other->id];
    }, 'week'],
    'unresolved week' => [function () {
        $week = test()->next;

        return [$week, Proposal::factory()->create(['week_id' => $week->id])->id];
    }, 'week'],
    'proposal of another week' => [function () {
        $week = resolvedFallbackWeek();

        return [$week, Proposal::factory()->create(['week_id' => test()->next->id])->id];
    }, 'proposal_id'],
    'hidden proposal' => [function () {
        $week = resolvedFallbackWeek();

        return [$week, Proposal::factory()->hidden()->create(['week_id' => $week->id])->id];
    }, 'proposal_id'],
    'finished week' => [function () {
        $week = resolvedFallbackWeek();
        $week->update(['status' => WeekStatus::Finished]);

        return [$week, Proposal::factory()->create(['week_id' => $week->id])->id];
    }, 'week'],
    'missing proposal' => [function () {
        return [resolvedFallbackWeek(), null];
    }, 'proposal_id'],
]);
