<?php

declare(strict_types=1);

use App\Http\Controllers\HealthzController;
use App\Http\Controllers\StatusController;
use App\Http\Middleware\EnsureSessionIsCurrent;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoIndex;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Support\Translations;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::get('/healthz', HealthzController::class)->name('healthz');
            Route::get('/api/estado', StatusController::class)->middleware('throttle:status')->name('api.status');
            Route::fallback(function (Request $request): never {
                $allowed = [];

                foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                    try {
                        Route::getRoutes()->match(Request::create($request->getPathInfo(), $method));
                        $allowed[] = $method;
                    } catch (HttpExceptionInterface) {
                    }
                }

                if ($allowed !== []) {
                    throw new MethodNotAllowedHttpException($allowed);
                }

                throw new NotFoundHttpException;
            })->middleware('web');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'noindex' => NoIndex::class,
            'no-store' => SetCacheHeaders::using('no_store;private'),
            'admin' => EnsureUserIsAdmin::class,
            'recently-confirmed' => RequireRecentConfirmation::class,
        ]);

        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnsureSessionIsCurrent::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnsureUserIsAdmin::class);

        $middleware->web(append: [
            EnsureSessionIsCurrent::class,
            'throttle:public',
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['driver_password', 'admin_password', 'spectator_password']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        Inertia::handleExceptionsUsing(function (ExceptionResponse $response): ?SymfonyResponse {
            $request = $response->request;
            $status = $response->statusCode();

            if ($request->is('api/*') || $request->expectsJson() || ! in_array($status, [403, 404, 419, 429, 500, 503], true)) {
                return null;
            }

            if (config('app.debug') && in_array($status, [500, 503], true)) {
                return null;
            }

            try {
                app()->setLocale(app(SetLocale::class)->resolveLocale($request));
            } catch (Throwable) {
                app()->setLocale(config()->string('mvp.default_locale'));
            }

            $original = $response->response;
            $rendered = $response
                ->render('errors/error', [
                    'status' => $status,
                    'pageTranslations' => app(Translations::class)->forGroups(['error']),
                ])
                ->withSharedData()
                ->usingMiddleware(HandleInertiaRequests::class)
                ->toResponse($request);

            $rendered->headers->set('X-Robots-Tag', 'noindex');

            if ($original->headers->has('Retry-After')) {
                $rendered->headers->set('Retry-After', (string) $original->headers->get('Retry-After'));
            }

            return $rendered;
        });
    })->create();
