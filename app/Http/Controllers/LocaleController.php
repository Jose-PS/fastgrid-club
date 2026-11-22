<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLocaleRequest;
use App\Support\PublicRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

class LocaleController extends Controller
{
    private const int COOKIE_MINUTES = 60 * 24 * 365;

    public function __invoke(UpdateLocaleRequest $request): RedirectResponse
    {
        $locale = $request->validated('locale');

        $request->user()?->update(['locale' => $locale]);

        $page = PublicRoutes::matchReferer($request);

        $response = $page === null
            ? back()
            : redirect()->to(PublicRoutes::path($page['baseName'], $locale).$page['query']);

        return $response->withCookie(Cookie::make('locale', $locale, self::COOKIE_MINUTES));
    }
}
