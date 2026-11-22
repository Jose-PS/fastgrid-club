<?php

declare(strict_types=1);

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\AccountLinkController;
use App\Http\Controllers\Account\ConfirmationController;
use App\Http\Controllers\Account\EmailController;
use App\Http\Controllers\Account\IdentityController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\SessionController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AlertsController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\DefaultConfigController;
use App\Http\Controllers\Admin\DonationsController;
use App\Http\Controllers\Admin\ProposalsController as AdminProposalsController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\ServerOverrideController;
use App\Http\Controllers\Admin\ServersController;
use App\Http\Controllers\Admin\SocialAccountsController;
use App\Http\Controllers\Admin\SocialPostsController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Admin\WeeksController as AdminWeeksController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LoginLinkController;
use App\Http\Controllers\Auth\SteamController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DonateController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IndexNowKeyController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\Proposals\ProposalController;
use App\Http\Controllers\Proposals\ProposalCreateController;
use App\Http\Controllers\Proposals\VoteController;
use App\Http\Controllers\Proposals\VoteIntentController;
use App\Http\Controllers\ProposalsController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Models\Proposal;
use Illuminate\Support\Facades\Route;

$publicPages = function (): void {
    Route::get('/', HomeController::class)->name('home');
    Route::get('/calendar', CalendarController::class)->name('calendar');
    Route::get('/proposals', ProposalsController::class)->name('proposals');
    Route::get('/proposals/new', ProposalCreateController::class)->name('proposals.create');
    Route::get('/donate', DonateController::class)->name('donate');
};

Route::group([], $publicPages);
Route::prefix('en')->name('en.')->group($publicPages);

Route::get('/robots.txt', RobotsController::class)->name('robots');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/{key}.txt', IndexNowKeyController::class)->where('key', '[A-Za-z0-9-]{8,128}')->name('indexnow.key');

Route::prefix('proposals')->name('proposals.')->group(function (): void {
    Route::post('/', [ProposalController::class, 'store'])
        ->middleware(['auth', 'throttle:proposal', 'can:create,'.Proposal::class])
        ->name('store');
    Route::delete('/{proposal}', [ProposalController::class, 'destroy'])
        ->middleware(['auth', 'can:delete,proposal'])
        ->name('destroy');
    Route::post('/{proposal}/vote', [VoteController::class, 'store'])
        ->middleware(['auth', 'throttle:vote', 'can:vote,proposal'])
        ->name('vote');
    Route::post('/{proposal}/intent', [VoteIntentController::class, 'store'])
        ->middleware('guest')
        ->name('intent');
});

Route::post('/locale', LocaleController::class)->name('locale.update');
Route::post('/logout', LogoutController::class)->name('logout');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', LoginController::class)->middleware('no-store')->name('login');
    Route::post('/login/link', [LoginLinkController::class, 'store'])
        ->middleware('throttle:magic-link')
        ->name('login.link.store');
    Route::get('/login/link/{token}', [LoginLinkController::class, 'show'])
        ->middleware('no-store')
        ->name('login.link.show');
    Route::post('/login/link/{token}', [LoginLinkController::class, 'consume'])
        ->middleware('throttle:magic-link-consume')
        ->name('login.link.consume');
});

Route::middleware(['throttle:login', 'throttle:link'])
    ->prefix('auth/steam')
    ->name('auth.steam.')
    ->group(function (): void {
        Route::get('/redirect', [SteamController::class, 'redirect'])->middleware('no-store')->name('redirect');
        Route::get('/callback', [SteamController::class, 'callback'])->middleware('no-store')->name('callback');
    });

Route::middleware(['throttle:login', 'throttle:link'])
    ->prefix('auth/google')
    ->name('auth.google.')
    ->group(function (): void {
        Route::get('/redirect', [GoogleController::class, 'redirect'])->middleware('no-store')->name('redirect');
        Route::get('/callback', [GoogleController::class, 'callback'])->middleware('no-store')->name('callback');
    });

Route::middleware(['auth', 'noindex', 'no-store'])
    ->prefix('account')
    ->name('account.')
    ->group(function (): void {
        Route::get('/', [AccountController::class, 'show'])->name('show');
        Route::delete('/', [AccountController::class, 'destroy'])->middleware('recently-confirmed')->name('destroy');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/email', [EmailController::class, 'update'])->middleware(['recently-confirmed', 'throttle:magic-link'])->name('email.update');
        Route::delete('/identities/{provider}', [IdentityController::class, 'destroy'])
            ->middleware(['recently-confirmed', 'throttle:link'])
            ->name('identities.destroy');
        Route::get('/identities/steam/link', [SteamController::class, 'redirect'])
            ->middleware(['recently-confirmed', 'throttle:link', 'no-store'])
            ->name('identities.steam.link');
        Route::get('/identities/google/link', [GoogleController::class, 'redirect'])
            ->middleware(['recently-confirmed', 'throttle:link', 'no-store'])
            ->name('identities.google.link');
        Route::post('/sessions/logout-others', [SessionController::class, 'destroy'])->name('sessions.destroy');
        Route::get('/confirm', [ConfirmationController::class, 'show'])->name('confirm.show');
        Route::post('/confirm/link', [ConfirmationController::class, 'store'])
            ->middleware('throttle:magic-link')
            ->name('confirm.link');
        Route::get('/links/{token}', [AccountLinkController::class, 'show'])->name('link.show');
        Route::post('/links/{token}', [AccountLinkController::class, 'consume'])
            ->middleware('throttle:magic-link-consume')
            ->name('link.consume');
    });

Route::middleware(['admin', 'auth', 'noindex', 'no-store'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', AdminController::class)->name('index');
        Route::get('/users', [UsersController::class, 'index'])->name('users.index');
        Route::get('/donations', [DonationsController::class, 'show'])->name('donations.show');
        Route::put('/donations', [DonationsController::class, 'update'])->middleware('throttle:admin')->name('donations.update');
        Route::get('/default-config', [DefaultConfigController::class, 'show'])->name('default-config.show');
        Route::put('/default-config', [DefaultConfigController::class, 'update'])->middleware('throttle:admin')->name('default-config.update');
        Route::delete('/default-config', [DefaultConfigController::class, 'destroy'])->middleware('throttle:admin')->name('default-config.destroy');
        Route::get('/proposals', [AdminProposalsController::class, 'index'])->name('proposals.index');
        Route::post('/proposals/{proposal}/hide', [AdminProposalsController::class, 'hide'])->middleware('throttle:admin')->name('proposals.hide');
        Route::post('/proposals/{proposal}/show', [AdminProposalsController::class, 'show'])->middleware('throttle:admin')->name('proposals.show');
        Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
        Route::post('/catalog/sync', [CatalogController::class, 'sync'])->middleware('throttle:admin')->name('catalog.sync');
        Route::put('/catalog/tracks/{track}/proposable', [CatalogController::class, 'track'])->middleware('throttle:admin')->name('catalog.tracks.proposable');
        Route::put('/catalog/cars/{car}/proposable', [CatalogController::class, 'car'])->middleware('throttle:admin')->name('catalog.cars.proposable');
        Route::post('/alerts/{alert}/close', [AlertsController::class, 'close'])->middleware('throttle:admin')->name('alerts.close');
        Route::get('/weeks', [AdminWeeksController::class, 'index'])->name('weeks.index');
        Route::put('/weeks/{week}', [AdminWeeksController::class, 'update'])->middleware('throttle:admin')->name('weeks.update');
        Route::post('/weeks/{week}/winner', [AdminWeeksController::class, 'winner'])->middleware('throttle:admin')->name('weeks.winner');
        Route::get('/servers', [ServersController::class, 'index'])->name('servers.index');
        Route::put('/servers/{server}/planning', [ServersController::class, 'planning'])->middleware('throttle:admin')->name('servers.planning');
        Route::post('/servers/{server}/reapply', [ServersController::class, 'reapply'])->middleware('throttle:admin')->name('servers.reapply');
        Route::get('/override', [ServerOverrideController::class, 'show'])->name('override.show');
        Route::post('/override', [ServerOverrideController::class, 'store'])->middleware('throttle:admin')->name('override.store');
        Route::delete('/override', [ServerOverrideController::class, 'destroy'])->middleware('throttle:admin')->name('override.destroy');
        Route::get('/schedule', [ScheduleController::class, 'index'])->name('schedule.index');
        Route::post('/schedule', [ScheduleController::class, 'store'])->middleware('throttle:admin')->name('schedule.store');
        Route::get('/redes', [SocialPostsController::class, 'index'])->name('social.index');
        Route::patch('/redes/{socialPost}', [SocialPostsController::class, 'update'])->middleware('throttle:admin')->name('social.update');
        Route::post('/redes/{socialPost}/approve', [SocialPostsController::class, 'approve'])->middleware('throttle:admin')->name('social.approve');
        Route::post('/redes/{socialPost}/reject', [SocialPostsController::class, 'reject'])->middleware('throttle:admin')->name('social.reject');
        Route::post('/redes/{socialPost}/retry', [SocialPostsController::class, 'retry'])->middleware('throttle:admin')->name('social.retry');
        Route::put('/redes/cuentas/{channel}', [SocialAccountsController::class, 'update'])->middleware('throttle:admin')->name('social.accounts.update');
        Route::post('/redes/cuentas/{channel}/test', [SocialAccountsController::class, 'test'])->middleware('throttle:admin')->name('social.accounts.test');
        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
    });

require __DIR__.'/passkeys.php';
