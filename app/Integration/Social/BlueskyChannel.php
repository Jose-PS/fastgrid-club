<?php

declare(strict_types=1);

namespace App\Integration\Social;

use App\Enums\SocialChannelName;
use App\Enums\SocialPostType;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use Carbon\CarbonImmutable;

class BlueskyChannel implements SocialChannel
{
    private const IMAGE_WIDTH = 1080;

    private const IMAGE_HEIGHT = 1350;

    public function name(): string
    {
        return 'bluesky';
    }

    public function isConfigured(SocialPost $post): bool
    {
        return $this->credential('identifier') !== null && $this->credential('app_password') !== null;
    }

    public function publish(SocialPost $post, string $image): array
    {
        $identifier = $this->credential('identifier');
        $password = $this->credential('app_password');

        if ($identifier === null || $password === null) {
            throw new SocialChannelException('bluesky: not configured');
        }

        $service = rtrim(config()->string('services.bluesky.service'), '/');

        $session = SocialHttp::send(
            fn () => SocialHttp::pending()->asJson()->post("{$service}/xrpc/com.atproto.server.createSession", [
                'identifier' => ltrim($identifier, '@'),
                'password' => $password,
            ]),
            'bluesky session',
        );

        $token = $session->json('accessJwt');
        $did = $session->json('did');

        if (! is_string($token) || ! is_string($did)) {
            throw new SocialChannelException('bluesky session: unexpected response');
        }

        $upload = SocialHttp::send(
            fn () => SocialHttp::pending()
                ->withToken($token)
                ->withBody($image, 'image/png')
                ->post("{$service}/xrpc/com.atproto.repo.uploadBlob"),
            'bluesky upload',
        );

        $blob = $upload->json('blob');

        if (! is_array($blob)) {
            throw new SocialChannelException('bluesky upload: unexpected response');
        }

        $record = SocialHttp::send(
            fn () => SocialHttp::pending()->withToken($token)->asJson()->post("{$service}/xrpc/com.atproto.repo.createRecord", [
                'repo' => $did,
                'collection' => 'app.bsky.feed.post',
                'record' => $this->record($post, $blob),
            ]),
            'bluesky post',
        );

        $uri = $record->json('uri');

        if (! is_string($uri)) {
            throw new SocialChannelException('bluesky post: unexpected response');
        }

        return ['uri' => $uri];
    }

    /**
     * @param  array<array-key, mixed>  $blob
     * @return array<string, mixed>
     */
    public function record(SocialPost $post, array $blob): array
    {
        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $post->text,
            'createdAt' => CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'langs' => ['es', 'en'],
            'embed' => [
                '$type' => 'app.bsky.embed.images',
                'images' => [[
                    'alt' => $this->altText($post),
                    'image' => $blob,
                    'aspectRatio' => ['width' => self::IMAGE_WIDTH, 'height' => self::IMAGE_HEIGHT],
                ]],
            ],
        ];

        $facets = $this->facets($post->text);

        if ($facets !== []) {
            $record['facets'] = $facets;
        }

        return $record;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function facets(string $text): array
    {
        $facets = [];

        if (preg_match_all('~https?://[^\s]+~u', $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$match, $offset]) {
                $url = rtrim($match, '.,;:!?)]}\'"');

                if ($url === '') {
                    continue;
                }

                $facets[] = [
                    'index' => ['byteStart' => $offset, 'byteEnd' => $offset + strlen($url)],
                    'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]],
                ];
            }
        }

        if (preg_match_all('/(?<![\p{L}\p{N}_&#])#([\p{L}\p{N}_]*\p{L}[\p{L}\p{N}_]*)/u', $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$match, $offset]) {
                if ($this->insideUrl($facets, $offset)) {
                    continue;
                }

                $facets[] = [
                    'index' => ['byteStart' => $offset, 'byteEnd' => $offset + strlen($match)],
                    'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => substr($match, 1)]],
                ];
            }
        }

        usort($facets, fn (array $a, array $b): int => $a['index']['byteStart'] <=> $b['index']['byteStart']);

        return $facets;
    }

    /**
     * @param  list<array<string, mixed>>  $facets
     */
    private function insideUrl(array $facets, int $offset): bool
    {
        foreach ($facets as $facet) {
            if ($offset >= $facet['index']['byteStart'] && $offset < $facet['index']['byteEnd']) {
                return true;
            }
        }

        return false;
    }

    private function altText(SocialPost $post): string
    {
        $label = match ($post->type) {
            SocialPostType::WeekAnnouncement => 'Anuncio de la nueva semana en FastGrid',
            SocialPostType::DailyResults => 'Resultados del día en FastGrid',
            SocialPostType::WeeklyBestLaps => 'Mejores vueltas de la semana en FastGrid',
        };

        $circuit = $post->data['circuit'] ?? null;

        return is_string($circuit) && $circuit !== '' ? "{$label}, circuito {$circuit}" : $label;
    }

    private function credential(string $key): ?string
    {
        return SocialAccount::enabledFor(SocialChannelName::Bluesky)?->credential($key);
    }
}
