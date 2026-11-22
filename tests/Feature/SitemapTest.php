<?php

declare(strict_types=1);

test('the sitemap lists both language versions of the public pages with absolute urls', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/xml');

    $xml = simplexml_load_string($response->getContent());
    expect($xml)->not->toBeFalse();

    $base = rtrim(config('app.url'), '/');
    $locations = [];
    foreach ($xml->url as $url) {
        $locations[] = (string) $url->loc;
    }

    expect($locations)->toBe([
        "{$base}/", "{$base}/en",
        "{$base}/calendar", "{$base}/en/calendar",
        "{$base}/proposals", "{$base}/en/proposals",
    ]);
});
