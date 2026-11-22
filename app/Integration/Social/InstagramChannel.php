<?php

declare(strict_types=1);

namespace App\Integration\Social;

use App\Enums\SocialChannelName;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use Illuminate\Support\Facades\Storage;

class InstagramChannel implements SocialChannel
{
    private const CAPTION_LIMIT = 2200;

    private const JPEG_QUALITY = 90;

    public function name(): string
    {
        return 'instagram';
    }

    public function isConfigured(SocialPost $post): bool
    {
        return $this->token() !== null;
    }

    public function publish(SocialPost $post, string $image): array
    {
        $token = $this->token();

        if ($token === null) {
            throw new SocialChannelException('instagram: not configured');
        }

        $base = rtrim(config()->string('services.instagram.base_url'), '/').'/'.config()->string('services.instagram.api_version');

        $imageUrl = $this->imageUrl($post, $image);

        $container = SocialHttp::send(
            fn () => SocialHttp::pending()->asForm()->post("{$base}/me/media", [
                'image_url' => $imageUrl,
                'caption' => mb_substr($post->text, 0, self::CAPTION_LIMIT),
                'access_token' => $token,
            ]),
            'instagram container',
        );

        $containerId = $container->json('id');

        if (! is_string($containerId)) {
            throw new SocialChannelException('instagram container: unexpected response');
        }

        $this->waitUntilReady($base, $containerId, $token);

        $published = SocialHttp::send(
            fn () => SocialHttp::pending()->asForm()->post("{$base}/me/media_publish", [
                'creation_id' => $containerId,
                'access_token' => $token,
            ]),
            'instagram publish',
        );

        $mediaId = $published->json('id');

        if (! is_string($mediaId)) {
            throw new SocialChannelException('instagram publish: unexpected response');
        }

        $details = SocialHttp::send(
            fn () => SocialHttp::pending()->get("{$base}/{$mediaId}", ['fields' => 'permalink', 'access_token' => $token]),
            'instagram permalink',
        );

        $permalink = $details->json('permalink');

        return ['id' => $mediaId, 'permalink' => is_string($permalink) ? $permalink : ''];
    }

    private function token(): ?string
    {
        return SocialAccount::enabledFor(SocialChannelName::Instagram)?->credential('access_token');
    }

    private function waitUntilReady(string $base, string $containerId, string $token): void
    {
        $attempts = max(1, config()->integer('services.instagram.poll_attempts'));
        $sleepMilliseconds = max(0, config()->integer('services.instagram.poll_sleep_ms'));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $status = SocialHttp::send(
                fn () => SocialHttp::pending()->get("{$base}/{$containerId}", ['fields' => 'status_code', 'access_token' => $token]),
                'instagram status',
            )->json('status_code');

            if ($status === 'FINISHED') {
                return;
            }

            if ($status === 'ERROR' || $status === 'EXPIRED') {
                throw new SocialChannelException("instagram status: container {$status}");
            }

            if ($attempt < $attempts && $sleepMilliseconds > 0) {
                usleep($sleepMilliseconds * 1000);
            }
        }

        throw new SocialChannelException('instagram status: container not ready in time');
    }

    private function imageUrl(SocialPost $post, string $png): string
    {
        $disk = Storage::disk('public');
        $path = (string) preg_replace('/\.png$/i', '', (string) $post->image_path).'.jpg';

        if (! $disk->exists($path)) {
            $jpeg = $this->toJpeg($png);

            if ($jpeg === null || ! $disk->put($path, $jpeg)) {
                throw new SocialChannelException('instagram image: jpeg conversion failed');
            }
        }

        return $disk->url($path);
    }

    private function toJpeg(string $png): ?string
    {
        $source = @imagecreatefromstring($png);

        if ($source === false) {
            return null;
        }

        $canvas = imagecreatetruecolor(imagesx($source), imagesy($source));

        if ($canvas === false) {
            return null;
        }

        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));

        ob_start();
        $written = imagejpeg($canvas, null, self::JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();

        return $written && $jpeg !== '' ? $jpeg : null;
    }
}
