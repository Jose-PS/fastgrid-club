<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

class PublicRoutes
{
    public const string ALTERNATE_LOCALE = 'en';

    public const string DEFAULT_LOCALE = 'es';

    /**
     * @var array<int, string>
     */
    public const array NAMES = ['home', 'calendar', 'proposals', 'proposals.create', 'donate'];

    public static function name(string $baseName, ?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === self::ALTERNATE_LOCALE ? self::ALTERNATE_LOCALE.'.'.$baseName : $baseName;
    }

    public static function baseName(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }

        return str_starts_with($routeName, self::ALTERNATE_LOCALE.'.') ? substr($routeName, strlen(self::ALTERNATE_LOCALE) + 1) : $routeName;
    }

    public static function isPublic(?string $routeName): bool
    {
        return in_array(self::baseName($routeName), self::NAMES, true);
    }

    public static function localeOf(?string $routeName): ?string
    {
        if (! self::isPublic($routeName)) {
            return null;
        }

        return str_starts_with((string) $routeName, self::ALTERNATE_LOCALE.'.') ? self::ALTERNATE_LOCALE : self::DEFAULT_LOCALE;
    }

    public static function path(string $baseName, ?string $locale = null): string
    {
        return route(self::name($baseName, $locale), absolute: false);
    }

    public static function url(string $baseName, ?string $locale = null): string
    {
        return rtrim(config()->string('app.url'), '/').self::path($baseName, $locale);
    }

    /**
     * @return array{baseName: string, locale: string, query: string}|null
     */
    public static function matchReferer(Request $request): ?array
    {
        $referer = $request->headers->get('referer');
        $parts = is_string($referer) ? parse_url($referer) : false;

        if (! is_array($parts) || ! isset($parts['path'])) {
            return null;
        }

        $path = '/'.trim($parts['path'], '/');

        foreach (self::NAMES as $baseName) {
            foreach ([self::DEFAULT_LOCALE, self::ALTERNATE_LOCALE] as $locale) {
                if (self::path($baseName, $locale) === $path) {
                    return ['baseName' => $baseName, 'locale' => $locale, 'query' => isset($parts['query']) ? '?'.$parts['query'] : ''];
                }
            }
        }

        return null;
    }

    public static function localeFromReferer(Request $request): string
    {
        return self::matchReferer($request)['locale'] ?? app()->getLocale();
    }
}
