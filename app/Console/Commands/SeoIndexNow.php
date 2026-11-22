<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SitemapEntries;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

#[Signature('seo:indexnow {--all : Send every sitemap url, ignoring what was already sent}')]
#[Description('Notify IndexNow of sitemap urls whose lastmod changed since the last submission')]
class SeoIndexNow extends Command
{
    public const string CACHE_KEY = 'indexnow.sent';

    public const string ENDPOINT = 'https://api.indexnow.org/indexnow';

    public function handle(SitemapEntries $sitemapEntries): int
    {
        $key = config('services.indexnow.key');

        if (! is_string($key) || $key === '') {
            return self::SUCCESS;
        }

        $sent = Cache::get(self::CACHE_KEY, []);
        $sent = is_array($sent) ? $sent : [];
        $current = [];

        foreach ($sitemapEntries->all() as $entry) {
            $current[$entry['loc']] = $entry['lastmod']?->toAtomString() ?? '';
        }

        $pending = $this->option('all')
            ? $current
            : array_filter($current, fn (string $lastmod, string $url): bool => ! array_key_exists($url, $sent) || $sent[$url] !== $lastmod, ARRAY_FILTER_USE_BOTH);

        if ($pending === []) {
            $this->line('No changes to send');

            return self::SUCCESS;
        }

        $baseUrl = rtrim(config()->string('app.url'), '/');

        try {
            $response = Http::timeout(15)->asJson()->post(self::ENDPOINT, [
                'host' => (string) parse_url($baseUrl, PHP_URL_HOST),
                'key' => $key,
                'keyLocation' => "{$baseUrl}/{$key}.txt",
                'urlList' => array_keys($pending),
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('IndexNow request failed', ['error' => $exception->getMessage()]);

            return self::SUCCESS;
        }

        if (! in_array($response->status(), [200, 202], true)) {
            Log::warning('IndexNow rejected the submission', ['status' => $response->status(), 'urls' => count($pending)]);

            return self::SUCCESS;
        }

        Cache::forever(self::CACHE_KEY, [...$sent, ...$pending]);

        $this->line('Sent '.count($pending).' url(s)');

        return self::SUCCESS;
    }
}
