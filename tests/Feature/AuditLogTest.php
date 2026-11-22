<?php

declare(strict_types=1);

use App\Actions\Audit\RecordAudit;
use App\Models\AuditLog;
use App\Models\DonationPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));
    Cache::flush();
});

test('updating donations records the before and after values', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put(route('admin.donations.update'), [
        'urls' => ['ko_fi' => 'https://ko-fi.com/acevo', 'patreon' => '', 'paypal' => ''],
        'goal_cents' => 5000,
        'covered_cents' => 1000,
    ])->assertRedirect();

    $entry = AuditLog::query()->sole();

    expect($entry->action)->toBe('donations.updated')
        ->and($entry->actor_id)->toBe($admin->id)
        ->and($entry->subject_type)->toBe((new DonationPeriod)->getMorphClass())
        ->and($entry->ip)->not->toBeNull()
        ->and($entry->before['goal_cents'])->toBe(0)
        ->and($entry->after['goal_cents'])->toBe(5000)
        ->and($entry->after['covered_cents'])->toBe(1000)
        ->and($entry->after['urls'])->toBe(['ko_fi' => 'https://ko-fi.com/acevo']);
});

test('saving donations without changes records nothing', function () {
    $admin = User::factory()->admin()->create();
    $payload = ['urls' => ['ko_fi' => '', 'patreon' => '', 'paypal' => ''], 'goal_cents' => 0, 'covered_cents' => 0];

    $this->actingAs($admin)->put(route('admin.donations.update'), $payload)->assertRedirect();

    expect(AuditLog::query()->count())->toBe(0);
});

test('granting and revoking admin by command is audited without actor or ip', function () {
    $user = User::factory()->create();

    $this->artisan('user:admin', ['user' => (string) $user->id])->assertSuccessful();
    $this->artisan('user:admin', ['user' => (string) $user->id, '--revoke' => true])->assertSuccessful();

    $entries = AuditLog::query()->orderBy('id')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->action)->toBe('user.admin_granted')
        ->and($entries[0]->before)->toBe(['is_admin' => false])
        ->and($entries[0]->after)->toBe(['is_admin' => true])
        ->and($entries[1]->action)->toBe('user.admin_revoked')
        ->and($entries[1]->actor_id)->toBeNull()
        ->and($entries[1]->ip)->toBeNull()
        ->and($entries[1]->subject_id)->toBe($user->id);
});

test('sensitive keys are dropped and emails are masked', function () {
    $entry = app(RecordAudit::class)->handle(null, 'test.action', null, [
        'password' => 'secret',
        'api_token' => 'abc',
        'remember_token' => 'def',
        'nested' => ['client_secret' => 'x', 'credentials' => 'y', 'note' => 'mail jose@example.com ok'],
        'email' => 'jose@example.com',
    ], ['goal' => 5]);

    $stored = AuditLog::query()->findOrFail($entry->id);

    expect($stored->before)->toBe([
        'nested' => ['note' => 'mail j***@example.com ok'],
        'email' => 'j***@example.com',
    ])->and($stored->after)->toBe(['goal' => 5]);
});

test('audit entries cannot be updated or deleted from the model', function () {
    $entry = AuditLog::factory()->create();

    expect(fn () => $entry->update(['action' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(AuditLog::query()->count())->toBe(1);
});

test('the audit page is hidden from guests and normal users', function () {
    $this->get(route('admin.audit.index'))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('admin.audit.index'))->assertNotFound();
});

test('admins see the audit page', function () {
    $actor = User::factory()->admin()->create(['name' => 'Ana']);
    AuditLog::factory()->create(['actor_id' => $actor->id]);
    AuditLog::factory()->create(['action' => 'user.admin_granted', 'actor_id' => null]);

    $this->actingAs($actor)->get(route('admin.audit.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/audit')
            ->has('entries.data', 2)
            ->where('entries.total', 2)
            ->where('actions', ['donations.updated', 'user.admin_granted']));
});

test('the audit page filters by action and date', function () {
    $admin = User::factory()->admin()->create();
    AuditLog::factory()->create(['action' => 'donations.updated', 'created_at' => now()->subDays(10)]);
    AuditLog::factory()->create(['action' => 'donations.updated', 'created_at' => now()]);
    AuditLog::factory()->create(['action' => 'user.admin_granted', 'created_at' => now()]);

    $this->actingAs($admin)->get(route('admin.audit.index', ['action' => 'user.admin_granted']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1));

    $this->actingAs($admin)->get(route('admin.audit.index', ['from' => now()->subDays(2)->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 2));

    $this->actingAs($admin)->get(route('admin.audit.index', ['to' => now()->subDays(5)->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1));

    $this->actingAs($admin)->get(route('admin.audit.index', ['from' => 'nope']))
        ->assertSessionHasErrors('from');
});

test('the audit page paginates by 25', function () {
    $admin = User::factory()->admin()->create();
    AuditLog::factory()->count(30)->create();

    $this->actingAs($admin)->get(route('admin.audit.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 25)
            ->where('entries.last_page', 2)
            ->where('entries.total', 30));

    $this->actingAs($admin)->get(route('admin.audit.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 5));
});

test('no route modifies the audit log', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'admin/audit'));

    expect($routes)->not->toBeEmpty()
        ->and($routes->flatMap(fn ($route) => $route->methods())->unique()->diff(['GET', 'HEAD'])->all())->toBe([]);
});

test('pruning removes entries older than a year', function () {
    $old = AuditLog::factory()->create(['created_at' => now()->subYear()->subDay()]);
    $recent = AuditLog::factory()->create(['created_at' => now()->subMonths(11)]);

    $this->artisan('model:prune', ['--model' => AuditLog::class])->assertSuccessful();

    expect(AuditLog::query()->find($old->id))->toBeNull()
        ->and(AuditLog::query()->find($recent->id))->not->toBeNull();
});
