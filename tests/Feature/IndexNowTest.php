<?php

declare(strict_types=1);

use App\Console\Commands\SeoIndexNow;
use App\Models\DonationMethod;
use App\Models\Proposal;
use App\Models\ScheduleVersion;
use App\Models\Vote;
use App\Models\Week;
use App\Support\SitemapEntries;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Cache::flush();
    ScheduleVersion::query()->delete();
    config(['services.indexnow.key' => 'abcdef1234567890', 'app.url' => 'https://fastgrid.club']);
});

function sitemapUrls(): array
{
    return array_column(app(SitemapEntries::class)->all(), 'loc');
}

test('the key file returns the configured key as plain text', function () {
    $response = $this->get('/abcdef1234567890.txt');

    $response->assertOk();
    expect($response->getContent())->toBe('abcdef1234567890')
        ->and($response->headers->get('Content-Type'))->toContain('text/plain');
});

test('the key file is a 404 for another key or when none is configured', function () {
    $this->get('/otherkey1234567890.txt')->assertNotFound();

    config(['services.indexnow.key' => null]);

    $this->get('/abcdef1234567890.txt')->assertNotFound();
});

test('robots.txt is not captured by the key route', function () {
    $this->get('/robots.txt')->assertOk()->assertSee('Sitemap:');
});

test('the lastmod of a page is the latest updated_at of the data it shows', function () {
    $week = Week::factory()->startingAt(CarbonImmutable::now()->addWeek())->create();
    $old = CarbonImmutable::parse('2026-01-01 10:00:00');
    $recent = CarbonImmutable::parse('2026-03-05 08:30:00');
    Week::query()->whereKey($week->id)->update(['updated_at' => $old]);
    $proposal = Proposal::factory()->create(['week_id' => $week->id]);
    Proposal::query()->whereKey($proposal->id)->update(['updated_at' => $old]);
    Vote::factory()->create(['week_id' => $week->id, 'proposal_id' => $proposal->id]);
    Vote::query()->update(['updated_at' => $recent]);

    $entries = app(SitemapEntries::class);

    expect($entries->lastmod('proposals')?->equalTo($recent))->toBeTrue()
        ->and($entries->lastmod('calendar')?->equalTo($old))->toBeTrue();
});

test('the sitemap shows lastmod only for pages with data', function () {
    $empty = $this->get('/sitemap.xml')->getContent();
    expect($empty)->not->toContain('<lastmod>');
    expect(app(SitemapEntries::class)->lastmod('proposals'))->toBeNull();

    $week = Week::factory()->create();
    Week::query()->whereKey($week->id)->update(['updated_at' => '2026-02-02 12:00:00']);

    $xml = $this->get('/sitemap.xml')->getContent();

    expect($xml)->toContain('<lastmod>2026-02-02T12:00:00+00:00</lastmod>');
});

test('the command sends nothing and skips the request when no key is configured', function () {
    Http::fake();
    config(['services.indexnow.key' => null]);

    $this->artisan('seo:indexnow')->assertSuccessful();

    Http::assertNothingSent();
});

test('the command sends all urls the first time and only changed ones afterwards', function () {
    Http::fake(['api.indexnow.org/*' => Http::response('', 202)]);
    $week = Week::factory()->create();
    Week::query()->whereKey($week->id)->update(['updated_at' => '2026-02-02 12:00:00']);

    $this->artisan('seo:indexnow')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === SeoIndexNow::ENDPOINT
        && $request['host'] === 'fastgrid.club'
        && $request['key'] === 'abcdef1234567890'
        && $request['keyLocation'] === 'https://fastgrid.club/abcdef1234567890.txt'
        && $request['urlList'] === sitemapUrls());

    Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);
    $this->artisan('seo:indexnow')->assertSuccessful();
    Http::assertNothingSent();

    Week::query()->whereKey($week->id)->update(['updated_at' => '2026-02-03 12:00:00']);
    DonationMethod::factory()->create();
    $this->artisan('seo:indexnow')->assertSuccessful();

    Http::assertSent(function (Request $request): bool {
        $urls = $request['urlList'];

        return in_array('https://fastgrid.club/', $urls, true)
            && in_array('https://fastgrid.club/en/calendar', $urls, true)
            && ! in_array('https://fastgrid.club/proposals', $urls, true);
    });
});

test('the all option resends every url', function () {
    Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);

    $this->artisan('seo:indexnow')->assertSuccessful();
    $this->artisan('seo:indexnow --all')->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request['urlList'] === sitemapUrls());
});

test('a failed response keeps the cache untouched, logs a warning and retries next run', function () {
    Log::spy();
    Http::fake(['api.indexnow.org/*' => Http::sequence()->push('', 429)->push('', 200)]);

    $this->artisan('seo:indexnow')->assertSuccessful();

    expect(Cache::get(SeoIndexNow::CACHE_KEY))->toBeNull();
    Log::shouldHaveReceived('warning')->once();

    $this->artisan('seo:indexnow')->assertSuccessful();

    Http::assertSentCount(2);
    expect(Cache::get(SeoIndexNow::CACHE_KEY))->not->toBeNull();
});
