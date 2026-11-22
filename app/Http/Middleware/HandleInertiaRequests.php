<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Translations;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'locale' => app()->getLocale(),
            'social' => config('mvp.brand.social'),
            'translations' => fn (): array => app(Translations::class)->forGroups(['nav', 'foot', 'login', 'ui']),
            'auth' => [
                'user' => $request->user() === null ? null : [
                    ...$request->user()->only(['id', 'name']),
                    'is_admin' => (bool) $request->user()->is_admin,
                ],
            ],
        ];
    }
}
