<?php

declare(strict_types=1);

use App\Actions\Donations\CurrentDonationPeriod;
use App\Actions\Donations\DonationPayload;
use App\Enums\DonationPlatform;
use App\Models\DonationChange;
use App\Models\DonationMethod;
use App\Models\DonationPeriod;
use App\Models\User;
use App\Rules\DonationUrl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));
    Cache::flush();
});

function donationPayload(array $overrides = []): array
{
    return array_merge([
        'urls' => ['ko_fi' => '', 'patreon' => '', 'paypal' => ''],
        'goal_cents' => 5000,
        'covered_cents' => 1000,
    ], $overrides);
}

test('donation urls accept the platform domain with and without www', function (DonationPlatform $platform) {
    $rule = new DonationUrl($platform);

    expect($rule->accepts("https://{$platform->domain()}/acevo"))->toBeTrue()
        ->and($rule->accepts("https://www.{$platform->domain()}/acevo"))->toBeTrue()
        ->and($rule->accepts("https://WWW.{$platform->domain()}/acevo"))->toBeTrue();
})->with(DonationPlatform::cases());

test('donation urls reject insecure, foreign and tricky addresses', function (string $url) {
    expect((new DonationUrl(DonationPlatform::KoFi))->accepts($url))->toBeFalse();
})->with([
    'http' => 'http://ko-fi.com/acevo',
    'other domain' => 'https://example.com/acevo',
    'deceptive suffix' => 'https://ko-fi.com.evil.com/acevo',
    'deceptive prefix' => 'https://evilko-fi.com/acevo',
    'subdomain' => 'https://evil.ko-fi.com/acevo',
    'javascript' => 'javascript:alert(1)',
    'ftp' => 'ftp://ko-fi.com/acevo',
    'user only' => 'https://user@ko-fi.com/acevo',
    'user and password' => 'https://user:secret@ko-fi.com/acevo',
    'credentials host trick' => 'https://ko-fi.com@evil.com/acevo',
    'port' => 'https://ko-fi.com:8443/acevo',
    'protocol relative' => '//ko-fi.com/acevo',
    'no scheme' => 'ko-fi.com/acevo',
    'backslash' => 'https://evil.com\\@ko-fi.com/acevo',
    'whitespace' => 'https://ko-fi.com/ace vo',
]);

test('each platform only accepts its own domain', function () {
    expect((new DonationUrl(DonationPlatform::Patreon))->accepts('https://ko-fi.com/acevo'))->toBeFalse()
        ->and((new DonationUrl(DonationPlatform::Paypal))->accepts('https://paypal.me/acevo'))->toBeTrue();
});

test('the current period copies goal and covered from the latest previous one', function () {
    DonationPeriod::factory()->forMonth('2026-08-01')->amounts(1000, 100)->create();
    DonationPeriod::factory()->forMonth('2026-09-01')->amounts(8000, 3000)->create();

    $period = app(CurrentDonationPeriod::class)->handle();

    expect($period->month->format('Y-m-d'))->toBe('2026-11-01')
        ->and($period->goal_cents)->toBe(8000)
        ->and($period->covered_cents)->toBe(3000);
});

test('the current period starts at zero without a previous one', function () {
    $period = app(CurrentDonationPeriod::class)->handle();

    expect($period->goal_cents)->toBe(0)->and($period->covered_cents)->toBe(0);
});

test('the current period uses the Madrid month at the month boundary', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 23:30:00', 'UTC'));

    expect(app(CurrentDonationPeriod::class)->handle()->month->format('Y-m-d'))->toBe('2026-11-01');
});

test('creating the current period twice is idempotent', function () {
    $first = app(CurrentDonationPeriod::class)->handle();
    $second = app(CurrentDonationPeriod::class)->handle();

    expect($second->id)->toBe($first->id)->and(DonationPeriod::query()->count())->toBe(1);
});

test('the ensure command creates the period and can be repeated', function () {
    DonationPeriod::factory()->forMonth('2026-10-01')->amounts(4000, 500)->create();

    $this->artisan('donations:ensure-period')->assertSuccessful();
    $this->artisan('donations:ensure-period')->assertSuccessful();

    $current = DonationPeriod::query()->whereDate('month', '2026-11-01')->get();

    expect($current)->toHaveCount(1)->and($current[0]->goal_cents)->toBe(4000)->and($current[0]->covered_cents)->toBe(500);
});

test('the public pages read donations without creating the current period', function () {
    DonationPeriod::factory()->forMonth('2026-10-01')->amounts(4000, 500)->create();
    DonationMethod::factory()->create();

    $this->get(route('home'))->assertOk();
    $this->get(route('donate'))->assertOk();

    expect(DonationPeriod::query()->count())->toBe(1);
});

test('the payload is empty without a period for the current month', function () {
    $payload = app(DonationPayload::class)->get();

    expect($payload['goal_cents'])->toBe(0)
        ->and($payload['covered_cents'])->toBe(0)
        ->and($payload['percent'])->toBe(0)
        ->and($payload['visible'])->toBeFalse()
        ->and(DonationPeriod::query()->count())->toBe(0);
});

test('the database rejects negative amounts', function () {
    expect(fn () => DB::table('donation_periods')->insert(['month' => '2026-01-01', 'goal_cents' => -1, 'covered_cents' => 0]))
        ->toThrow(Exception::class);
});

test('admins edit the donations and the changes are logged', function () {
    $admin = User::factory()->admin()->create();
    DonationPeriod::factory()->amounts(2000, 500)->create();

    $this->actingAs($admin)
        ->from(route('admin.donations.show'))
        ->put(route('admin.donations.update'), donationPayload([
            'urls' => ['ko_fi' => 'https://ko-fi.com/acevo', 'patreon' => '', 'paypal' => 'https://paypal.me/acevo'],
            'goal_cents' => 5000,
            'covered_cents' => 500,
        ]))
        ->assertRedirect(route('admin.donations.show'))
        ->assertSessionHasNoErrors();

    $changes = DonationChange::query()->get();

    expect(DonationMethod::query()->orderBy('position')->pluck('url', 'platform')->all())->toBe([
        'ko_fi' => 'https://ko-fi.com/acevo',
        'paypal' => 'https://paypal.me/acevo',
    ])
        ->and($changes)->toHaveCount(1)
        ->and($changes[0]->field)->toBe('goal')
        ->and($changes[0]->old_cents)->toBe(2000)
        ->and($changes[0]->new_cents)->toBe(5000)
        ->and($changes[0]->changed_by)->toBe($admin->id)
        ->and(DonationPeriod::query()->first()->updated_by)->toBe($admin->id);
});

test('both amounts are logged when both change and an empty url removes the method', function () {
    DonationMethod::factory()->platform(DonationPlatform::Patreon)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.donations.update'), donationPayload(['goal_cents' => 3000, 'covered_cents' => 4000]))
        ->assertSessionHasNoErrors();

    expect(DonationMethod::query()->count())->toBe(0)
        ->and(DonationChange::query()->orderBy('id')->pluck('new_cents', 'field')->all())->toBe(['goal' => 3000, 'covered' => 4000]);
});

test('covered may exceed the goal', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.donations.update'), donationPayload(['goal_cents' => 1000, 'covered_cents' => 5000]))
        ->assertSessionHasNoErrors();

    expect(DonationPeriod::query()->first()->covered_cents)->toBe(5000);
});

test('unchanged amounts leave no history', function () {
    DonationPeriod::factory()->amounts(5000, 1000)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.donations.update'), donationPayload())
        ->assertSessionHasNoErrors();

    expect(DonationChange::query()->count())->toBe(0);
});

test('invalid amounts and urls are rejected', function (array $overrides, string $errorKey) {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.donations.update'), donationPayload($overrides))
        ->assertSessionHasErrors($errorKey);

    expect(DonationChange::query()->count())->toBe(0)->and(DonationMethod::query()->count())->toBe(0);
})->with([
    'negative goal' => [['goal_cents' => -1], 'goal_cents'],
    'negative covered' => [['covered_cents' => -5], 'covered_cents'],
    'non integer' => [['goal_cents' => 'abc'], 'goal_cents'],
    'missing covered' => [['covered_cents' => null], 'covered_cents'],
    'http url' => [['urls' => ['ko_fi' => 'http://ko-fi.com/acevo']], 'urls.ko_fi'],
    'wrong domain for platform' => [['urls' => ['patreon' => 'https://ko-fi.com/acevo']], 'urls.patreon'],
    'deceptive suffix' => [['urls' => ['paypal' => 'https://paypal.me.evil.com/x']], 'urls.paypal'],
    'javascript' => [['urls' => ['ko_fi' => 'javascript:alert(1)']], 'urls.ko_fi'],
    'credentials' => [['urls' => ['ko_fi' => 'https://a:b@ko-fi.com/x']], 'urls.ko_fi'],
]);

test('non admins and guests cannot reach the donations admin', function () {
    $this->get(route('admin.donations.show'))->assertNotFound();
    $this->put(route('admin.donations.update'), donationPayload())->assertNotFound();

    $this->actingAs(User::factory()->create());
    $this->get(route('admin.donations.show'))->assertNotFound();
    $this->put(route('admin.donations.update'), donationPayload())->assertNotFound();

    expect(DonationPeriod::query()->count())->toBe(0);
});

test('the admin page lists every platform, the current period and the history', function () {
    DonationPeriod::factory()->forMonth('2026-09-01')->amounts(2000, 1000)->create();
    DonationPeriod::factory()->forMonth('2026-10-01')->amounts(4000, 4000)->create();
    DonationMethod::factory()->platform(DonationPlatform::Paypal)->create();

    $response = $this->actingAs(User::factory()->admin()->create())->get(route('admin.donations.show'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/donations'));
    $props = $response->inertiaProps();

    expect(array_column($props['methods'], 'platform'))->toBe(['ko_fi', 'patreon', 'paypal'])
        ->and($props['methods'][0]['url'])->toBeNull()
        ->and($props['methods'][2]['url'])->toBe('https://paypal.me/acevo')
        ->and($props['period']['month'])->toBe('2026-11')
        ->and(array_column($props['history'], 'month'))->toBe(['2026-11', '2026-10', '2026-09'])
        ->and($props['history'][1]['percent'])->toBe(100)
        ->and($props['history'][2]['percent'])->toBe(50);
});

test('the percentage is capped at 100 and is zero without a goal', function () {
    $period = DonationPeriod::factory()->amounts(1000, 5000)->create();
    expect($period->percent())->toBe(100);

    $period->update(['goal_cents' => 0]);
    expect($period->fresh()->percent())->toBe(0);

    $period->update(['goal_cents' => 3000, 'covered_cents' => 1000]);
    expect($period->fresh()->percent())->toBe(33);
});

test('without methods or goal the donate page is not found and the home prop is null', function () {
    $this->get(route('donate'))->assertNotFound();

    expect($this->get(route('home'))->assertOk()->inertiaProps()['donation'])->toBeNull();
});

test('the donate page and the home prop expose only the public payload', function () {
    $admin = User::factory()->admin()->create();
    DonationPeriod::factory()->forMonth('2026-10-01')->amounts(9000, 100)->create(['updated_by' => $admin->id]);
    DonationPeriod::factory()->amounts(2000, 5000)->create(['updated_by' => $admin->id]);
    DonationMethod::factory()->platform(DonationPlatform::Paypal)->create(['updated_by' => $admin->id]);
    DonationMethod::factory()->platform(DonationPlatform::KoFi)->create(['updated_by' => $admin->id]);

    $expected = [
        'methods' => [
            ['platform' => 'ko_fi', 'url' => 'https://ko-fi.com/acevo'],
            ['platform' => 'paypal', 'url' => 'https://paypal.me/acevo'],
        ],
        'goal_cents' => 2000,
        'covered_cents' => 5000,
        'percent' => 100,
        'visible' => true,
    ];

    $donate = $this->get(route('donate'));
    $donate->assertOk()->assertInertia(fn (Assert $page) => $page->component('donate'));

    expect($donate->inertiaProps()['donation'])->toBe($expected)
        ->and($this->get(route('home'))->inertiaProps()['donation'])->toBe($expected);

    $content = $donate->getContent();
    expect($content)->not->toContain('updated_by')->not->toContain($admin->email)->not->toContain('9000');
});

test('a goal alone makes the donations visible', function () {
    DonationPeriod::factory()->amounts(3000, 0)->create();

    $this->get(route('donate'))->assertOk();
});

test('editing from the admin refreshes the cached public payload', function () {
    DonationPeriod::factory()->amounts(2000, 500)->create();

    expect($this->get(route('donate'))->inertiaProps()['donation']['covered_cents'])->toBe(500);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.donations.update'), donationPayload(['goal_cents' => 2000, 'covered_cents' => 1500]));

    expect($this->get(route('donate'))->inertiaProps()['donation']['covered_cents'])->toBe(1500);
});

test('the public payload is cached for sixty seconds', function () {
    DonationPeriod::factory()->amounts(2000, 500)->create();

    $this->get(route('donate'))->assertOk();
    DonationPeriod::query()->update(['covered_cents' => 900]);

    expect($this->get(route('donate'))->inertiaProps()['donation']['covered_cents'])->toBe(500);

    $this->travel(61)->seconds();

    expect($this->get(route('donate'))->inertiaProps()['donation']['covered_cents'])->toBe(900);
});

test('the sitemap lists the donate page only when it is visible', function () {
    $this->get('/sitemap.xml')->assertDontSee('/donate');

    Cache::flush();
    DonationPeriod::factory()->amounts(2000, 0)->create();

    $this->get('/sitemap.xml')->assertSee('/donate');
});
