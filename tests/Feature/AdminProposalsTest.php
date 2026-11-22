<?php

declare(strict_types=1);

use App\Enums\WeekStatus;
use App\Models\AuditLog;
use App\Models\Car;
use App\Models\Proposal;
use App\Models\Server;
use App\Models\User;
use App\Models\Vote;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));
    $this->server = Server::factory()->create(['is_active' => true]);
    $this->week = Week::factory()->for($this->server)->startingAt(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'))->create();
    $this->author = User::factory()->withSteam()->create(['name' => 'Ana']);
    $this->proposal = Proposal::factory()->withCars(Car::factory()->count(2)->create()->all())->create([
        'week_id' => $this->week->id,
        'user_id' => $this->author->id,
    ]);
    Vote::factory()->count(2)->create(['proposal_id' => $this->proposal->id]);
    $this->admin = User::factory()->admin()->create();
});

function publicProposalIds(): array
{
    return collect(test()->get(route('proposals'))->inertiaProps()['proposals'])->pluck('id')->all();
}

test('guests and non admins get not found on every proposal route', function () {
    $hide = route('admin.proposals.hide', $this->proposal);
    $show = route('admin.proposals.show', $this->proposal);

    $this->get(route('admin.proposals.index'))->assertNotFound();
    $this->post($hide, ['reason' => 'spam'])->assertNotFound();
    $this->post($show)->assertNotFound();

    $this->actingAs(User::factory()->create());
    $this->get(route('admin.proposals.index'))->assertNotFound();
    $this->post($hide, ['reason' => 'spam'])->assertNotFound();
    $this->post($show)->assertNotFound();

    expect($this->proposal->fresh()->hidden_at)->toBeNull();
});

test('the listing shows the voting week proposals with the internal user id and no steam id', function () {
    Proposal::factory()->create(['week_id' => Week::factory()->for($this->server)->status(WeekStatus::Scheduled)->startingAt(CarbonImmutable::parse('2026-11-04', 'Europe/Madrid'))->create()->id]);

    $response = $this->actingAs($this->admin)->get(route('admin.proposals.index'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/proposals')
        ->where('week.id', $this->week->id)
        ->has('proposals', 1)
        ->where('proposals.0.id', $this->proposal->id)
        ->where('proposals.0.author', ['id' => $this->author->id, 'name' => 'Ana'])
        ->where('proposals.0.votes', 2)
        ->where('proposals.0.hidden', false)
        ->where('proposals.0.hidden_reason', null)
        ->has('proposals.0.cars', 2));

    expect(json_encode($response->inertiaProps()))->not->toContain((string) $this->author->steam_id);
});

test('the listing is empty without a voting week', function () {
    $this->week->update(['status' => WeekStatus::Scheduled]);

    $this->actingAs($this->admin)->get(route('admin.proposals.index'))
        ->assertInertia(fn (Assert $page) => $page->where('week', null)->where('proposals', []));
});

test('hiding requires a reason', function (mixed $reason) {
    $this->actingAs($this->admin)
        ->post(route('admin.proposals.hide', $this->proposal), ['reason' => $reason])
        ->assertSessionHasErrors('reason');

    expect($this->proposal->fresh()->hidden_at)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
})->with([null, '', '   ']);

test('an admin hides a proposal which leaves the public list and shows it again with audit', function () {
    expect(publicProposalIds())->toBe([$this->proposal->id]);

    $this->actingAs($this->admin)
        ->post(route('admin.proposals.hide', $this->proposal), ['reason' => ' spam '])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $proposal = $this->proposal->fresh();

    expect($proposal->hidden_reason)->toBe('spam')
        ->and($proposal->hidden_by)->toBe($this->admin->id)
        ->and(publicProposalIds())->toBe([]);

    $this->get(route('admin.proposals.index'))
        ->assertInertia(fn (Assert $page) => $page->where('proposals.0.hidden', true)->where('proposals.0.hidden_reason', 'spam'));

    $this->post(route('admin.proposals.show', $this->proposal))->assertRedirect()->assertSessionHasNoErrors();

    expect($this->proposal->fresh()->hidden_at)->toBeNull()
        ->and(publicProposalIds())->toBe([$this->proposal->id]);

    $hidden = AuditLog::query()->where('action', 'proposal.hidden')->sole();
    $shown = AuditLog::query()->where('action', 'proposal.shown')->sole();

    expect($hidden->actor_id)->toBe($this->admin->id)
        ->and($hidden->subject_id)->toBe($this->proposal->id)
        ->and($hidden->before)->toBe(['hidden' => false, 'reason' => null])
        ->and($hidden->after)->toBe(['hidden' => true, 'reason' => 'spam'])
        ->and($shown->before)->toBe(['hidden' => true, 'reason' => 'spam'])
        ->and($shown->after)->toBe(['hidden' => false, 'reason' => null]);
});

test('proposals of a week that is no longer in voting cannot be moderated', function () {
    $this->week->update(['status' => WeekStatus::Scheduled]);

    $this->actingAs($this->admin)
        ->post(route('admin.proposals.hide', $this->proposal), ['reason' => 'spam'])
        ->assertSessionHasErrors('proposal');

    $this->post(route('admin.proposals.show', $this->proposal))->assertSessionHasErrors('proposal');

    expect($this->proposal->fresh()->hidden_at)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});
