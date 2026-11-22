<?php

declare(strict_types=1);

use App\Models\DonationMethod;
use App\Models\ScheduleSegment;
use App\Models\ScheduleVersion;
use App\Support\PublicRoutes;
use Carbon\CarbonImmutable;

function metaContent(string $html, string $attribute, string $name): ?string
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);
    $nodes = $xpath->query("//meta[@{$attribute}='{$name}']");

    return $nodes->length === 0 ? null : $nodes->item(0)->getAttribute('content');
}

function canonicalOf(string $html): ?string
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $nodes = (new DOMXPath($document))->query("//link[@rel='canonical']");

    return $nodes->length === 0 ? null : $nodes->item(0)->getAttribute('href');
}

function titleOf(string $html): ?string
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $nodes = $document->getElementsByTagName('title');

    return $nodes->length === 0 ? null : $nodes->item(0)->textContent;
}

function jsonLdOf(string $html): array
{
    preg_match('/<script type="application\/ld\+json" nonce="[^"]+">(.+?)<\/script>/s', $html, $matches);

    return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
}

dataset('seo locales', [
    'es' => ['es', 'FastGrid - Corre limpio, sé más rápido.', 'es_ES'],
    'en' => ['en', 'FastGrid - Race fair. Get faster.', 'en_US'],
]);

test('the home page renders the full seo block on the server', function (string $locale, string $title, string $ogLocale) {
    config(['mvp.brand.social_handle' => '@fastgrid_club']);

    $html = $this->get($locale === 'en' ? '/en' : '/')->assertOk()->getContent();
    $base = rtrim(config('app.url'), '/');
    $home = $base.($locale === 'en' ? '/en' : '/');

    expect(titleOf($html))->toBe($title)
        ->and(metaContent($html, 'name', 'description'))->toBe(__('meta.home.description', [], $locale))
        ->and(canonicalOf($html))->toBe($home)
        ->and(metaContent($html, 'property', 'og:title'))->toBe($title)
        ->and(metaContent($html, 'property', 'og:description'))->toBe(__('hero.h1', [], $locale))
        ->and(metaContent($html, 'property', 'og:type'))->toBe('website')
        ->and(metaContent($html, 'property', 'og:url'))->toBe($home)
        ->and(metaContent($html, 'property', 'og:site_name'))->toBe(config('app.name'))
        ->and(metaContent($html, 'property', 'og:locale'))->toBe($ogLocale)
        ->and(metaContent($html, 'property', 'og:image'))->toBe("{$base}/og-image.png")
        ->and(metaContent($html, 'name', 'twitter:card'))->toBe('summary_large_image')
        ->and(metaContent($html, 'name', 'twitter:title'))->toBe($title)
        ->and(metaContent($html, 'name', 'twitter:description'))->toBe(__('hero.h1', [], $locale))
        ->and(metaContent($html, 'name', 'twitter:image'))->toBe("{$base}/og-image.png")
        ->and(metaContent($html, 'name', 'twitter:site'))->toBe('@fastgrid_club');

    $graph = jsonLdOf($html)['@graph'];
    $organization = collect($graph)->firstWhere('@type', 'Organization');

    expect(collect($graph)->pluck('@type')->all())->toBe(['WebSite', 'Organization'])
        ->and($graph[0])->toMatchArray(['name' => config('app.name'), 'url' => $home, 'inLanguage' => $locale])
        ->and($organization)->toMatchArray(['name' => config('app.name'), 'url' => $home, 'logo' => rtrim(config('app.url'), '/').'/apple-touch-icon.png', 'sameAs' => ['https://www.instagram.com/fastgrid.club/', 'https://x.com/fastgrid_club', 'https://bsky.app/profile/fastgrid.club']]);
})->with('seo locales');

test('the og image exists as a static asset', function () {
    expect(file_exists(public_path('og-image.png')))->toBeTrue();
});

test('public pages render title, description and canonical per locale', function (string $locale, string $routeName, string $titleKey) {
    if ($routeName === 'donate') {
        DonationMethod::factory()->create();
    }

    $publicName = PublicRoutes::isPublic($routeName) ? PublicRoutes::name($routeName, $locale) : $routeName;
    $html = $this->withCookie('locale', $locale)->get(route($publicName))->assertOk()->getContent();
    $base = rtrim(config('app.url'), '/');
    $path = route($publicName, absolute: false);
    $descriptionKey = match ($routeName) {
        'calendar' => 'meta.calendar.description',
        'proposals' => 'meta.proposals.description',
        'proposals.create' => 'meta.proposalsCreate.description',
        'donate' => 'meta.donate.description',
        'login' => 'meta.login.description',
    };

    expect(titleOf($html))->toBe(__($titleKey, [], $locale).' - '.config('app.name'))
        ->and(metaContent($html, 'name', 'description'))->toBe(__($descriptionKey, [], $locale))
        ->and(canonicalOf($html))->toBe($base.$path);

    if ($routeName !== 'calendar') {
        expect($html)->not->toContain('application/ld+json');
    }
})->with([
    ['es', 'calendar', 'cal.title'],
    ['en', 'calendar', 'cal.title'],
    ['es', 'proposals', 'props.title'],
    ['en', 'proposals', 'props.title'],
    ['es', 'donate', 'donate.pageTitle'],
    ['en', 'donate', 'donate.pageTitle'],
    ['es', 'login', 'login.title'],
    ['en', 'login', 'login.title'],
]);

test('private areas do not render the public seo block', function () {
    $html = $this->get('/login/link/not-a-token')->getContent();

    expect(metaContent((string) $html, 'property', 'og:title'))->toBeNull();
});

test('the calendar emits one SportsEvent per upcoming race window', function (string $locale, string $name) {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00:00', 'Europe/Madrid'));

    $html = $this->withCookie('locale', $locale)->get(route(PublicRoutes::name('calendar', $locale)))->assertOk()->getContent();
    $graph = jsonLdOf($html)['@graph'];

    expect($graph)->toHaveCount(1)
        ->and($graph[0])->toMatchArray([
            '@type' => 'SportsEvent',
            'name' => $name,
            'startDate' => '2026-11-07T18:00:00+01:00',
            'endDate' => '2026-11-08T00:00:00+01:00',
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
            'sport' => 'Sim racing',
            'inLanguage' => $locale,
        ])
        ->and($graph[0]['location']['url'])->toBe(canonicalOf($html));
})->with([
    ['es', 'Carrera de FastGrid'],
    ['en', 'FastGrid race'],
]);

test('the calendar skips races that start after the next seven days or already ended', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'Europe/Madrid'));
    $version = ScheduleVersion::factory()->create(['effective_from' => CarbonImmutable::parse('2026-11-01 00:00', 'Europe/Madrid')->setTimezone('UTC')]);
    foreach ([
        ['weekday' => 1, 'starts_at' => '00:00', 'practice_minutes' => 60],
        ['weekday' => 3, 'starts_at' => '10:00', 'qualifying_minutes' => 30, 'race_duration_type' => 'laps', 'race_laps' => 10],
        ['weekday' => 3, 'starts_at' => '11:00', 'practice_minutes' => 60],
        ['weekday' => 7, 'starts_at' => '10:00', 'qualifying_minutes' => 30, 'race_duration_type' => 'laps', 'race_laps' => 10],
        ['weekday' => 7, 'starts_at' => '11:00', 'practice_minutes' => 60],
    ] as $segment) {
        ScheduleSegment::factory()->create(array_merge(['schedule_version_id' => $version->id], $segment));
    }

    $graph = jsonLdOf($this->get(route('calendar'))->assertOk()->getContent())['@graph'];

    expect(collect($graph)->pluck('startDate')->all())->toBe(['2026-11-11T10:00:00+01:00', '2026-11-15T10:00:00+01:00']);
});

test('the calendar emits no structured data without upcoming races', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00:00', 'Europe/Madrid'));
    $version = ScheduleVersion::factory()->create(['effective_from' => CarbonImmutable::parse('2026-11-01 00:00', 'Europe/Madrid')->setTimezone('UTC')]);
    ScheduleSegment::factory()->create(['schedule_version_id' => $version->id, 'weekday' => 1, 'starts_at' => '00:00', 'practice_minutes' => 60]);
    ScheduleVersion::query()->where('id', '!=', $version->id)->delete();

    $html = $this->get(route('calendar'))->assertOk()->getContent();

    expect($html)->not->toContain('application/ld+json');
});
