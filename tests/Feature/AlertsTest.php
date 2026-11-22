<?php

declare(strict_types=1);

use App\Actions\Alerts\OpenAlert;
use App\Actions\Weeks\ResolveWeek;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\ConfigSource;
use App\Models\Alert;
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
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    Mail::fake();
    $this->server = Server::factory()->create(['is_active' => true, 'max_players' => 24]);
    $this->week = Week::factory()
        ->for($this->server)
        ->startingAt(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'))
        ->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'));
    $this->admin = User::factory()->admin()->create();
    $this->track = Track::factory()->withPracticePair()->create(['max_pit_slot' => 16]);
    $this->cars = Car::factory()->count(2)->create();
});

function fallbackAlert(Week $week): ?Alert
{
    return Alert::query()
        ->where('type', AlertType::WeekFallbackPendingReview)
        ->where('subject_type', $week->getMorphClass())
        ->where('subject_id', $week->id)
        ->latest('id')
        ->first();
}

test('resolving a week with the default configuration opens one alert', function () {
    app(ResolveWeek::class)->handle($this->week->id);

    $alert = fallbackAlert($this->week);

    expect($this->week->fresh()->config_source)->toBe(ConfigSource::Fallback)
        ->and(Alert::query()->count())->toBe(1)
        ->and($alert->status)->toBe(AlertStatus::Open)
        ->and($alert->server_id)->toBe($this->server->id)
        ->and($alert->closed_at)->toBeNull()
        ->and($alert->summary)->toContain('16/11/2026');
});

test('resolving a week with a winner opens no alert', function () {
    $proposal = Proposal::factory()->withCars($this->cars->all())->create(['week_id' => $this->week->id, 'track_id' => $this->track->id]);
    Vote::factory()->create(['proposal_id' => $proposal->id]);

    app(ResolveWeek::class)->handle($this->week->id);

    expect(Alert::query()->count())->toBe(0);
});

test('opening the same alert twice does not duplicate it but reopens after closing', function () {
    $open = app(OpenAlert::class);

    $first = $open->handle(AlertType::WeekFallbackPendingReview, 'a', [], $this->week, $this->server->id);
    $second = $open->handle(AlertType::WeekFallbackPendingReview, 'b', [], $this->week, $this->server->id);

    expect($second->id)->toBe($first->id)->and(Alert::query()->count())->toBe(1);

    $first->update(['status' => AlertStatus::Closed, 'closed_at' => now()]);
    $open->handle(AlertType::WeekFallbackPendingReview, 'c', [], $this->week, $this->server->id);

    expect(Alert::query()->count())->toBe(2);
});

test('forcing the week closes its open alert and leaves other weeks alone', function () {
    app(ResolveWeek::class)->handle($this->week->id);
    $other = Alert::factory()->create(['subject_type' => $this->week->getMorphClass(), 'subject_id' => $this->week->id + 999]);

    $this->actingAs($this->admin)
        ->put(route('admin.weeks.update', $this->week), [
            'track_id' => $this->track->id,
            'cars' => $this->cars->pluck('id')->all(),
            'custom_title' => null,
        ])
        ->assertSessionHasNoErrors();

    expect(fallbackAlert($this->week)->status)->toBe(AlertStatus::Closed)
        ->and(fallbackAlert($this->week)->closed_at)->not->toBeNull()
        ->and($other->fresh()->status)->toBe(AlertStatus::Open);
});

test('choosing a winner closes the open alert', function () {
    app(ResolveWeek::class)->handle($this->week->id);
    $proposal = Proposal::factory()->withCars($this->cars->all())->create(['week_id' => $this->week->id, 'track_id' => $this->track->id]);

    $this->actingAs($this->admin)
        ->post(route('admin.weeks.winner', $this->week), ['proposal_id' => $proposal->id])
        ->assertSessionHasNoErrors();

    expect(fallbackAlert($this->week)->status)->toBe(AlertStatus::Closed);
});

test('an admin closes an alert by hand and it is audited', function () {
    $alert = Alert::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.alerts.close', $alert))
        ->assertRedirect();

    $log = AuditLog::query()->where('action', 'alert.closed')->sole();

    expect($alert->fresh()->status)->toBe(AlertStatus::Closed)
        ->and($alert->fresh()->closed_at)->not->toBeNull()
        ->and($log->actor_id)->toBe($this->admin->id)
        ->and($log->subject_id)->toBe($alert->id)
        ->and($log->before['status'])->toBe('open')
        ->and($log->after['status'])->toBe('closed');
});

test('closing an already closed alert does not audit again', function () {
    $alert = Alert::factory()->closed()->create();

    $this->actingAs($this->admin)->post(route('admin.alerts.close', $alert))->assertRedirect();

    expect(AuditLog::query()->where('action', 'alert.closed')->count())->toBe(0);
});

test('guests and non admins cannot close an alert', function () {
    $alert = Alert::factory()->create();

    $this->post(route('admin.alerts.close', $alert))->assertNotFound();
    $this->actingAs(User::factory()->create())->post(route('admin.alerts.close', $alert))->assertNotFound();

    expect($alert->fresh()->status)->toBe(AlertStatus::Open);
});

test('the panel lists only open alerts, newest first, and shows an empty state', function () {
    $this->actingAs($this->admin)->get(route('admin.index'))
        ->assertInertia(fn (Assert $page) => $page->where('alerts', []));

    $old = Alert::factory()->create(['summary' => 'vieja', 'opened_at' => now()->subDay()]);
    $new = Alert::factory()->create(['summary' => 'nueva', 'opened_at' => now()]);
    Alert::factory()->closed()->create(['summary' => 'cerrada']);

    $this->actingAs($this->admin)->get(route('admin.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('alerts', 2)
            ->where('alerts.0.id', $new->id)
            ->where('alerts.0.summary', 'nueva')
            ->where('alerts.1.id', $old->id)
            ->where('alerts.0.type', 'week_fallback_pending_review'));
});

test('the admin panel receives the type of an integration alert', function () {
    Alert::factory()->create(['type' => AlertType::IntegrationFailing, 'summary' => 'falla']);

    $this->actingAs($this->admin)->get(route('admin.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('alerts', 1)
            ->where('alerts.0.type', 'integration_failing'));
});

test('an alert is shown in the language of the admin who looks at it, whatever language it was opened in', function () {
    app()->setLocale('es');
    app(OpenAlert::class)->handle(AlertType::WeekFallbackPendingReview, 'admin.alerts.weekFallback', ['date' => '16/11/2026'], $this->week, $this->server->id);
    app()->setLocale('en');

    $alert = Alert::query()->sole();

    expect($alert->summary)->toContain('semana del 16/11/2026')
        ->and($alert->summary_key)->toBe('admin.alerts.weekFallback')
        ->and($alert->summary_params)->toBe(['date' => '16/11/2026']);

    $english = User::factory()->admin()->create(['locale' => 'en']);
    $spanish = User::factory()->admin()->create(['locale' => 'es']);

    $this->actingAs($english)->get(route('admin.index'))
        ->assertInertia(fn (Assert $page) => $page->where('alerts.0.summary', 'The week of 16/11/2026 was resolved with the default configuration and is pending review.'));

    $this->actingAs($spanish)->get(route('admin.index'))
        ->assertInertia(fn (Assert $page) => $page->where('alerts.0.summary', 'La semana del 16/11/2026 se resolvió con la configuración por defecto y está pendiente de revisar.'));
});

test('an alert stored before the key existed keeps showing its stored summary', function () {
    Alert::factory()->create(['summary' => 'resumen antiguo', 'summary_key' => null, 'summary_params' => null]);

    $this->actingAs(User::factory()->admin()->create(['locale' => 'en']))->get(route('admin.index'))
        ->assertInertia(fn (Assert $page) => $page->where('alerts.0.summary', 'resumen antiguo'));
});
