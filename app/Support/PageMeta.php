<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

class PageMeta
{
    private const PAGES = [
        'home' => ['title' => null, 'description' => 'meta.home.description'],
        'calendar' => ['title' => 'cal.title', 'description' => 'meta.calendar.description'],
        'proposals' => ['title' => 'props.title', 'description' => 'meta.proposals.description'],
        'proposals.create' => ['title' => 'props.newTitle', 'description' => 'meta.proposalsCreate.description'],
        'donate' => ['title' => 'donate.pageTitle', 'description' => 'meta.donate.description'],
        'login' => ['title' => 'login.title', 'description' => 'meta.login.description'],
    ];

    private const OG_LOCALES = [
        'es' => 'es_ES',
        'en' => 'en_US',
    ];

    /**
     * @return array<int, string>
     */
    public static function sitemapRoutes(): array
    {
        return ['home', 'calendar', 'proposals'];
    }

    public function appName(): string
    {
        return config()->string('app.name');
    }

    public function absoluteUrl(string $routeName, ?string $locale = null): string
    {
        if (PublicRoutes::isPublic($routeName)) {
            return PublicRoutes::url((string) PublicRoutes::baseName($routeName), $locale);
        }

        return rtrim(config()->string('app.url'), '/').route($routeName, absolute: false);
    }

    /**
     * @return array<string, string>
     */
    public function alternates(string $routeName): array
    {
        return [
            'es' => $this->absoluteUrl($routeName, 'es'),
            'en' => $this->absoluteUrl($routeName, 'en'),
            'x-default' => $this->absoluteUrl($routeName, 'es'),
        ];
    }

    public function imageUrl(): string
    {
        return rtrim(config()->string('app.url'), '/').'/og-image.png';
    }

    public function title(?string $routeName): string
    {
        if ($routeName === null || ! isset(self::PAGES[$routeName])) {
            return $this->appName();
        }

        $key = self::PAGES[$routeName]['title'];

        if ($key === null) {
            return $this->appName().' - '.__('hero.h1');
        }

        return __($key).' - '.$this->appName();
    }

    public function currentRouteName(): ?string
    {
        return Route::currentRouteName();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forRoute(?string $routeName): ?array
    {
        $routeName = PublicRoutes::baseName($routeName);

        if ($routeName === null || ! isset(self::PAGES[$routeName])) {
            return null;
        }

        $locale = app()->getLocale();
        $isHome = $routeName === 'home';
        $title = $this->title($routeName);
        $description = __(self::PAGES[$routeName]['description']);

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $this->absoluteUrl($routeName, $locale),
            'alternates' => PublicRoutes::isPublic($routeName) ? $this->alternates($routeName) : [],
            'siteName' => $this->appName(),
            'locale' => self::OG_LOCALES[$locale] ?? self::OG_LOCALES['en'],
            'image' => $this->imageUrl(),
            'twitterSite' => config()->string('mvp.brand.social_handle') ?: null,
            'ogDescription' => $isHome ? __('hero.h1') : $description,
            'jsonLd' => match ($routeName) {
                'home' => app(StructuredData::class)->home($locale),
                'calendar' => app(StructuredData::class)->calendar($locale, CarbonImmutable::now()),
                default => null,
            },
        ];
    }
}
