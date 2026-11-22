<?php

declare(strict_types=1);

use App\Enums\WeekStatus;
use App\Models\Server;
use App\Models\Track;
use App\Models\Week;
use App\Support\TrackSvg;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['mvp.tracks.path' => base_path('tests/Fixtures/tracks')]);
});

test('an invalid slug never reaches the filesystem', function (?string $slug) {
    expect(app(TrackSvg::class)->load($slug))->toBeNull();
})->with([
    'traversal' => '../x',
    'uppercase' => 'Fixture-Valid',
    'slash' => 'a/b',
    'dot' => 'fixture.valid',
    'empty' => '',
    'null' => null,
]);

test('a missing file gives no markup', function () {
    expect(app(TrackSvg::class)->load('does-not-exist'))->toBeNull();
});

test('a valid svg keeps its elements and drops the xml prolog', function () {
    $markup = app(TrackSvg::class)->load('fixture-valid');

    expect($markup)->toStartWith('<svg')
        ->and($markup)->not->toContain('<?xml')
        ->and($markup)->toContain('<path')
        ->and($markup)->toContain('<line')
        ->and($markup)->toContain('<text')
        ->and($markup)->toContain('<linearGradient')
        ->and($markup)->toContain('<use')
        ->and($markup)->toContain('#shape')
        ->and($markup)->toContain('url(#g1)')
        ->and($markup)->toContain('currentColor');
});

test('forbidden content is stripped from the output', function (string $slug, array $forbidden) {
    $markup = app(TrackSvg::class)->load($slug);

    expect($markup)->not->toBeNull();

    foreach ($forbidden as $needle) {
        expect(strtolower($markup))->not->toContain(strtolower($needle));
    }
})->with([
    'script' => ['evil-script', ['<script', 'alert']],
    'onload' => ['evil-onload', ['onload', 'onclick', 'alert']],
    'foreignObject' => ['evil-foreignobject', ['foreignObject', '<div']],
    'style element' => ['evil-style', ['<style', 'fill: red']],
    'style attribute' => ['evil-style-attribute', ['style=']],
    'href' => ['evil-href', ['https://', 'javascript:', 'href=']],
    'xlink href' => ['evil-xlink-href', ['https://', 'href=']],
    'image' => ['evil-image', ['<image', 'https://']],
    'external url fill' => ['evil-url-fill', ['url(', 'https://']],
    'animation and links' => ['evil-animate', ['<a', '<animate', '<set']],
]);

test('a document with a doctype or entities is rejected whole', function () {
    expect(app(TrackSvg::class)->load('evil-doctype'))->toBeNull();
});

test('a document that is not svg is rejected', function () {
    $sanitizer = app(TrackSvg::class);

    expect($sanitizer->sanitize('<html><body>x</body></html>'))->toBeNull()
        ->and($sanitizer->sanitize('not xml at all'))->toBeNull()
        ->and($sanitizer->sanitize('<svg><path d="M0 0"/></svg>'))->toBeNull();
});

test('the real track svgs need no sanitising', function () {
    config(['mvp.tracks.path' => resource_path('tracks')]);
    $sanitizer = app(TrackSvg::class);

    foreach (glob(resource_path('tracks/*.svg')) ?: [] as $file) {
        $report = $sanitizer->report((string) file_get_contents($file));

        expect($report)->not->toBeNull($file)
            ->and($report['removed'])->toBe(0, $file);
    }

    expect(true)->toBeTrue();
});

function homeWithTrack(array $trackAttributes): Server
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-04 12:00:00', 'Europe/Madrid'));
    $server = Server::factory()->create(['is_active' => true]);
    $track = Track::factory()->create(['display' => 'Fixture GP', ...$trackAttributes]);
    Week::factory()->for($server)->startingAt(CarbonImmutable::now())->status(WeekStatus::Active)->create(['track_id' => $track->id]);

    return $server;
}

test('the home page passes the sanitised svg and its attribution', function () {
    homeWithTrack(['svg_slug' => 'fixture-valid']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('trackSvg.markup', fn (string $markup) => str_starts_with($markup, '<svg') && ! str_contains($markup, '<script'))
        ->where('trackSvg.attribution', 'Fixture de prueba, no es un circuito real'));
});

test('a track without slug or with a missing file has no svg', function (?string $slug) {
    homeWithTrack(['svg_slug' => $slug]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('trackSvg', null));
})->with([
    'no slug' => null,
    'missing file' => 'does-not-exist',
]);

test('an unsafe svg exposes only the cleaned markup', function () {
    homeWithTrack(['svg_slug' => 'evil-script']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('trackSvg.markup', fn (string $markup) => ! str_contains($markup, 'script'))
        ->where('trackSvg.attribution', null));
});

test('every slug in the track map has a real svg file', function () {
    $slugs = config('mvp.tracks.slugs');

    expect($slugs)->toHaveCount(36);

    foreach ($slugs as $key => $slug) {
        expect(is_file(resource_path("tracks/{$slug}.svg")))->toBeTrue("{$key} => {$slug}");
    }
});

test('a track without a manual slug resolves its svg through the track and layout map', function () {
    config(['mvp.tracks.path' => resource_path('tracks')]);
    $track = Track::factory()->create(['track' => 'Monza', 'layout' => 'GP', 'svg_slug' => null]);
    $unmapped = Track::factory()->create(['track' => 'Nowhere', 'layout' => 'GP', 'svg_slug' => null]);
    $svg = app(TrackSvg::class);

    expect($svg->slugFor($track))->toBe('monza-gp')
        ->and($svg->forTrack($track)['markup'])->toContain('<svg')
        ->and($svg->slugFor($unmapped))->toBeNull()
        ->and($svg->forTrack($unmapped))->toBeNull()
        ->and($svg->slugFor(null))->toBeNull();
});

test('a manual svg slug wins over the track and layout map', function () {
    $track = Track::factory()->create(['track' => 'Monza', 'layout' => 'GP', 'svg_slug' => 'fixture-valid']);

    expect(app(TrackSvg::class)->slugFor($track))->toBe('fixture-valid')
        ->and(app(TrackSvg::class)->forTrack($track)['attribution'])->toBe('Fixture de prueba, no es un circuito real');
});
