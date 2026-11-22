<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Stats\LogResults;
use App\Actions\Stats\ResultsSource;
use App\Auth\SteamProvider;
use App\Models\User;
use App\Policies\PasskeyPolicy;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passkeys\Passkey;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ResultsSource::class, LogResults::class);
        $this->app->scoped(WeekCalendar::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureTrustedProxies();
        $this->configureAuthorization();
        $this->configureSocialite();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('public', fn (Request $request): Limit => Limit::perMinute(
            (int) config('mvp.rate_limits.public.per_minute'),
        )->by((string) $request->ip()));

        RateLimiter::for('status', fn (Request $request): Limit => Limit::perMinute(
            (int) config('mvp.rate_limits.status.per_minute'),
        )->by((string) $request->ip()));

        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(
            (int) config('mvp.rate_limits.login.per_minute'),
        )->by((string) $request->ip()));

        RateLimiter::for('link', fn (Request $request): Limit => $request->user() === null
            ? Limit::none()
            : Limit::perMinute((int) config('mvp.rate_limits.link.per_minute'))->by((string) $request->user()->getAuthIdentifier()));

        RateLimiter::for('magic-link', fn (Request $request): array => [
            Limit::perHour((int) config('mvp.rate_limits.magic_link.per_hour_by_email'))
                ->by('email|'.hash('sha256', mb_strtolower(trim($this->magicLinkEmail($request))))),
            Limit::perHour((int) config('mvp.rate_limits.magic_link.per_hour_by_ip'))
                ->by('ip|'.$request->ip()),
        ]);

        RateLimiter::for('magic-link-consume', fn (Request $request): Limit => Limit::perMinute(
            (int) config('mvp.rate_limits.magic_link_consume.per_minute'),
        )->by((string) $request->ip()));

        RateLimiter::for('vote', fn (Request $request): Limit => Limit::perMinute(
            (int) config('mvp.rate_limits.vote.per_minute'),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('proposal', fn (Request $request): Limit => Limit::perHour(
            (int) config('mvp.rate_limits.proposal.per_hour'),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('admin', fn (Request $request): Limit => Limit::perMinute(
            (int) config('mvp.rate_limits.admin.per_minute'),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    private function magicLinkEmail(Request $request): string
    {
        $email = $request->input('email');

        if (is_string($email)) {
            return $email;
        }

        $user = $request->user();

        return $user instanceof User ? (string) $user->email : '';
    }

    private function configureTrustedProxies(): void
    {
        TrustProxies::at(array_values(array_filter(
            array_map('trim', explode(',', (string) config('mvp.trusted_proxies'))),
            fn (string $proxy): bool => $proxy !== '' && $proxy !== '*',
        )));
    }

    private function configureAuthorization(): void
    {
        Gate::define('admin', fn (User $user): bool => $user->is_admin);
        Gate::policy(Passkey::class, PasskeyPolicy::class);
    }

    private function configureSocialite(): void
    {
        Event::listen(SocialiteWasCalled::class, function (SocialiteWasCalled $socialite): void {
            $socialite->extendSocialite('steam', SteamProvider::class);
        });
    }
}
