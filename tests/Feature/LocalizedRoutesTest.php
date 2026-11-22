<?php

declare(strict_types=1);

use App\Actions\Donations\DonationPayload;
use App\Models\DonationMethod;
use App\Models\Proposal;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function linkHrefOf(string $html, string $rel): ?string
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $nodes = (new DOMXPath($document))->query("//link[@rel='{$rel}']");

    return $nodes->length === 0 ? null : $nodes->item(0)->getAttribute('href');
}

function ogUrlOf(string $html): ?string
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $nodes = (new DOMXPath($document))->query("//meta[@property='og:url']");

    return $nodes->length === 0 ? null : $nodes->item(0)->getAttribute('content');
}

function alternatesOf(string $html): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $alternates = [];

    foreach ((new DOMXPath($document))->query("//link[@rel='alternate']") as $node) {
        $alternates[$node->getAttribute('hreflang')] = $node->getAttribute('href');
    }

    return $alternates;
}

test('the english url is english even when the cookie says spanish', function () {
    $this->withCookie('locale', 'es')->withHeader('Accept-Language', 'es')
        ->get('/en/calendar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('calendar')->where('locale', 'en'));
});

test('the root url is spanish even when the cookie and the header say english', function () {
    $this->withCookie('locale', 'en')->withHeader('Accept-Language', 'en')
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('welcome')->where('locale', 'es'));
});

test('the url wins over the stored locale of the user', function () {
    $user = User::factory()->create(['locale' => 'es']);

    $this->actingAs($user)->get('/en/proposals')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

test('the english home has no trailing slash and no automatic redirect happens', function () {
    $this->withHeader('Accept-Language', 'en')->get('/')->assertOk();
    $this->get('/en')->assertOk();
});

test('every public page declares canonical and hreflang alternates in both languages', function (string $path, string $name) {
    $base = rtrim(config('app.url'), '/');
    $slug = ['home' => '', 'proposals.create' => 'proposals/new'][$name] ?? $name;
    $es = $base.'/'.$slug;
    $en = $base.'/en'.($slug === '' ? '' : '/'.$slug);
    $expectedCanonical = str_starts_with($path, '/en') ? $en : $es;

    DonationMethod::factory()->create();
    $html = $this->get($path)->assertOk()->getContent();

    expect(linkHrefOf($html, 'canonical'))->toBe($expectedCanonical)
        ->and(alternatesOf($html))->toBe(['es' => $es, 'en' => $en, 'x-default' => $es])
        ->and(ogUrlOf($html))->toBe($expectedCanonical)
        ->and($html)->toContain('<html lang="'.(str_starts_with($path, '/en') ? 'en' : 'es').'"');
})->with([
    ['/', 'home'],
    ['/en', 'home'],
    ['/calendar', 'calendar'],
    ['/en/calendar', 'calendar'],
    ['/proposals', 'proposals'],
    ['/en/proposals', 'proposals'],
    ['/proposals/new', 'proposals.create'],
    ['/en/proposals/new', 'proposals.create'],
    ['/donate', 'donate'],
    ['/en/donate', 'donate'],
]);

test('non public pages do not declare alternates', function () {
    expect(alternatesOf($this->get('/login')->getContent()))->toBe([]);
});

test('the sitemap lists both versions with their alternates', function () {
    $base = rtrim(config('app.url'), '/');
    $xml = simplexml_load_string($this->get('/sitemap.xml')->getContent());
    $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $xml->registerXPathNamespace('x', 'http://www.w3.org/1999/xhtml');

    $entries = $xml->xpath('//s:url');
    expect($entries)->toHaveCount(6);

    $calendar = $xml->xpath("//s:url[s:loc='{$base}/en/calendar']")[0];
    $calendar->registerXPathNamespace('x', 'http://www.w3.org/1999/xhtml');
    $links = [];
    foreach ($calendar->xpath('x:link') as $link) {
        $links[(string) $link['hreflang']] = (string) $link['href'];
    }

    expect($links)->toBe(['es' => "{$base}/calendar", 'en' => "{$base}/en/calendar", 'x-default' => "{$base}/calendar"]);
});

test('the sitemap adds donate in both languages only when it is visible', function () {
    $base = rtrim(config('app.url'), '/');
    DonationMethod::factory()->create();
    expect(app(DonationPayload::class)->get()['visible'])->toBeTrue();

    $xml = simplexml_load_string($this->get('/sitemap.xml')->getContent());
    $locations = array_map('strval', iterator_to_array($xml->xpath('//*[local-name()="url"]/*[local-name()="loc"]'), false));

    expect($locations)->toHaveCount(8)->toContain("{$base}/donate", "{$base}/en/donate");
});

test('actions keep the language of the page they come from', function () {
    $this->post(route('logout'), [], ['Referer' => url('/en/proposals')])->assertRedirect(url('/en'));
    $this->post(route('logout'), [], ['Referer' => url('/calendar')])->assertRedirect(url('/'));
});

test('a guest vote intent from the english page returns to the english page after login', function () {
    $proposal = Proposal::factory()->create();

    $this->post(route('proposals.intent', $proposal), [], ['Referer' => url('/en/proposals')]);

    expect(session('url.intended'))->toBe('/en/proposals');
});
