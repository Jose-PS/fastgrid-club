<?php

declare(strict_types=1);

use App\Mail\LoginLinkMail;
use App\Models\LoginLink;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function issueLink(string $email = 'ana@example.test'): string
{
    $token = null;

    Mail::assertNothingQueued();
    test()->post(route('login.link.store'), ['email' => $email])->assertRedirect();

    Mail::assertQueued(LoginLinkMail::class, function (LoginLinkMail $mail) use (&$token): bool {
        $token = basename($mail->url);

        return true;
    });

    return $token;
}

function plainLink(string $email = 'ana@example.test', array $state = []): string
{
    $token = Str::random(64);

    LoginLink::factory()->create(['email' => $email, 'token_hash' => hash('sha256', $token), ...$state]);

    return $token;
}

beforeEach(function () {
    Mail::fake();
    RateLimiter::clear('public');
});

test('requesting a link answers the same for existing and unknown accounts', function () {
    User::factory()->withVerifiedEmail()->create(['email' => 'known@example.test']);

    $known = $this->from('/login')->post(route('login.link.store'), ['email' => 'known@example.test']);
    $knownFlash = session('inertia.flash_data');
    $unknown = $this->from('/login')->post(route('login.link.store'), ['email' => 'nobody@example.test']);
    $unknownFlash = session('inertia.flash_data');

    expect($known->getStatusCode())->toBe($unknown->getStatusCode())
        ->and($known->headers->get('Location'))->toBe($unknown->headers->get('Location'))
        ->and($knownFlash)->toBe($unknownFlash)
        ->and($knownFlash['linkSent'])->toBe(__('login.linkSent'));

    Mail::assertQueuedCount(2);
    expect(LoginLink::count())->toBe(2)->and(User::count())->toBe(1);
});

test('the flash message follows the resolved locale', function () {
    $this->withHeader('Accept-Language', 'en')->from('/login')->post(route('login.link.store'), ['email' => 'a@example.test']);

    expect(session('inertia.flash_data')['linkSent'])->toContain('If the email is valid');
});

test('the token is never stored in clear and is long enough', function () {
    $token = issueLink();

    $link = LoginLink::sole();

    expect(strlen($token))->toBeGreaterThanOrEqual(43)
        ->and($link->token_hash)->toBe(hash('sha256', $token))
        ->and($link->token_hash)->not->toContain($token)
        ->and($link->email)->toBe('ana@example.test')
        ->and($link->ip)->not->toBeNull()
        ->and($link->expires_at->equalTo($link->created_at->addMinutes(15)))->toBeTrue();
});

test('a new link invalidates previous unused links of the same email only', function () {
    $first = plainLink('ana@example.test');
    $other = plainLink('luis@example.test');

    $this->post(route('login.link.store'), ['email' => 'ana@example.test'])->assertRedirect();

    $this->post(route('login.link.consume', $first))->assertRedirect(route('login.link.show', $first));
    $this->assertGuest();
    $this->post(route('login.link.consume', $other))->assertRedirect();
    $this->assertAuthenticated();
});

test('the email is normalized', function () {
    $this->post(route('login.link.store'), ['email' => '  Ana@Example.TEST  '])->assertSessionHasNoErrors();

    expect(LoginLink::sole()->email)->toBe('ana@example.test');
    Mail::assertQueued(LoginLinkMail::class, fn (LoginLinkMail $mail): bool => $mail->hasTo('ana@example.test'));
});

test('invalid emails are rejected', function (mixed $email) {
    $this->post(route('login.link.store'), ['email' => $email])->assertSessionHasErrors('email');

    expect(LoginLink::count())->toBe(0);
    Mail::assertNothingQueued();
})->with([
    'not an email' => 'nope',
    'empty' => '',
    'missing' => null,
    'too long' => fn () => str_repeat('a', 250).'@example.test',
    'array' => [['a@example.test']],
]);

test('the mail is sent in the resolved locale with the confirmation link', function (string $locale, string $subject, string $expiry) {
    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstance('mail.manager');

    $this->withHeader('Accept-Language', $locale)->post(route('login.link.store'), ['email' => 'ana@example.test']);

    $message = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    $url = LoginLink::sole() ? config('app.url').'/login/link/' : '';

    expect($message->getSubject())->toBe($subject)
        ->and($message->getHtmlBody())->toContain($url, $expiry)->not->toContain('<img')
        ->and($message->getTextBody())->toContain($url, $expiry)
        ->and($message->getTo()[0]->getAddress())->toBe('ana@example.test');
})->with([
    'es' => ['es', 'Tu enlace para entrar en FastGrid', 'caduca en 15 minutos'],
    'en' => ['en', 'Your link to sign in to FastGrid', 'expires in 15 minutes'],
]);

test('opening the confirmation page never consumes the token', function () {
    $token = plainLink();

    $this->get(route('login.link.show', $token))
        ->assertOk()
        ->assertHeader('Cache-Control')
        ->assertInertia(fn (Assert $page) => $page->component('auth/link')->where('consumeUrl', route('login.link.consume', $token)));

    $this->get(route('login.link.show', $token))->assertOk();
    $this->assertGuest();
    expect(LoginLink::sole()->used_at)->toBeNull();
});

test('the confirmation page of an unknown, used or expired token is generic', function (array $state) {
    $token = plainLink(state: $state);

    $this->get(route('login.link.show', $token))->assertInertia(fn (Assert $page) => $page->where('consumeUrl', null));
})->with([
    'used' => [['used_at' => now()]],
    'expired' => [['expires_at' => now()->subMinute()]],
]);

test('the confirmation page of an unknown token is generic', function () {
    $this->get(route('login.link.show', 'unknown'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('consumeUrl', null));
});

test('posting consumes a link once', function () {
    $token = plainLink();

    $this->post(route('login.link.consume', $token))->assertRedirect(route('en.home'));
    $this->assertAuthenticated();
    expect(LoginLink::sole()->used_at)->not->toBeNull();

    auth()->guard('web')->logout();
    $this->post(route('login.link.consume', $token))->assertRedirect(route('login.link.show', $token));
    $this->assertGuest();
});

test('an expired link cannot be consumed', function () {
    $token = plainLink();

    $this->travel(16)->minutes();

    $this->post(route('login.link.consume', $token))->assertRedirect(route('login.link.show', $token));
    $this->assertGuest();
});

test('a link still works just before it expires', function () {
    $token = plainLink();

    $this->travel(14)->minutes();

    $this->post(route('login.link.consume', $token));
    $this->assertAuthenticated();
});

test('consuming creates a verified user when the email is new', function () {
    app()->setLocale('en');
    $token = plainLink('Pilot.One@example.test');

    $this->post(route('login.link.consume', $token));

    $user = User::sole();
    expect($user->email)->toBe('Pilot.One@example.test')
        ->and($user->name)->toBe('Pilot.One')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->locale)->toBe('en');
    $this->assertAuthenticatedAs($user);
});

test('the derived name is truncated to 32 characters', function () {
    $token = plainLink(str_repeat('x', 40).'@example.test');

    $this->post(route('login.link.consume', $token));

    expect(User::sole()->name)->toHaveLength(32);
});

test('consuming verifies the email of an existing unverified account', function () {
    $user = User::factory()->create(['email' => 'ana@example.test', 'email_verified_at' => null, 'name' => 'Ana']);
    $token = plainLink('ana@example.test');

    $this->post(route('login.link.consume', $token));

    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1)->and($user->fresh()->email_verified_at)->not->toBeNull()->and($user->fresh()->name)->toBe('Ana');
});

test('consuming regenerates the session and respects an internal redirect', function () {
    $token = plainLink();
    $this->withSession(['url.intended' => '/calendar']);
    $before = session()->getId();

    $this->post(route('login.link.consume', $token))->assertRedirect(url('/calendar'));

    expect(session()->getId())->not->toBe($before);
});

test('an external remembered redirect is ignored after consuming', function () {
    $token = plainLink();
    $this->withSession(['url.intended' => 'https://evil.test/']);

    $this->post(route('login.link.consume', $token))->assertRedirect(route('en.home'));
});

test('a link can be consumed from another browser than the one that requested it', function () {
    $token = issueLink();

    $this->flushSession();
    $this->post(route('login.link.consume', $token))->assertRedirect(route('en.home'));
    $this->assertAuthenticated();
});

test('an authenticated user cannot consume or request links', function () {
    $this->actingAs(User::factory()->create());
    $token = plainLink();

    $this->post(route('login.link.consume', $token))->assertRedirect();
    $this->post(route('login.link.store'), ['email' => 'a@example.test'])->assertRedirect();

    expect(LoginLink::sole()->used_at)->toBeNull();
});

test('the post routes reject requests without a csrf token', function (string $name, array $parameters) {
    $this->app['env'] = 'local';

    $this->post(route($name, $parameters), ['email' => 'a@example.test'])->assertStatus(419);

    expect(LoginLink::count())->toBe(0);
})->with([
    'store' => ['login.link.store', []],
    'consume' => ['login.link.consume', ['token' => 'abc']],
]);

test('the sixth request for the same email in an hour is rate limited', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.link.store'), ['email' => 'ana@example.test'])->assertRedirect();
    }

    $this->post(route('login.link.store'), ['email' => ' ANA@example.test '])->assertStatus(429);

    expect(LoginLink::count())->toBe(5);
    Mail::assertQueuedCount(5);
});

test('the 21st request from the same ip in an hour is rate limited', function () {
    foreach (range(1, 20) as $attempt) {
        $this->post(route('login.link.store'), ['email' => "user{$attempt}@example.test"])->assertRedirect();
    }

    $this->post(route('login.link.store'), ['email' => 'user21@example.test'])->assertStatus(429);

    expect(LoginLink::count())->toBe(20);
});

test('consuming is limited to 10 per minute per ip', function () {
    foreach (range(1, 10) as $attempt) {
        $this->post(route('login.link.consume', "bad{$attempt}"))->assertRedirect();
    }

    $this->post(route('login.link.consume', 'bad11'))->assertStatus(429);
});

test('a very short email local part gets a generated pilot name', function () {
    $token = plainLink('a@b.com');

    $this->post(route('login.link.consume', $token))->assertRedirect();

    expect(User::where('email', 'a@b.com')->value('name'))->toMatch('/^Piloto \d{4}$/');
});
