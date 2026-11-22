<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PublicRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolveLocale($request));

        return $next($request);
    }

    public function resolveLocale(Request $request): string
    {
        $routeLocale = PublicRoutes::localeOf($request->route()?->getName());

        if ($routeLocale !== null && $this->isSupported($routeLocale)) {
            return $routeLocale;
        }

        $candidates = [
            $request->user()?->locale,
            $request->cookie('locale'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $this->isSupported($candidate)) {
                return $candidate;
            }
        }

        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(explode('_', $language)[0]);

            if ($this->isSupported($primary)) {
                return $primary;
            }
        }

        return config()->string('mvp.default_locale');
    }

    private function isSupported(string $locale): bool
    {
        return in_array($locale, config('mvp.locales'), true);
    }
}
