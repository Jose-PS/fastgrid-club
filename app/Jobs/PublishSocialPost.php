<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SocialPostStatus;
use App\Integration\Social\BlueskyChannel;
use App\Integration\Social\DiscordChannel;
use App\Integration\Social\InstagramChannel;
use App\Integration\Social\SocialChannel;
use App\Integration\Social\SocialChannelException;
use App\Models\SocialPost;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PublishSocialPost implements ShouldQueue
{
    use Queueable;

    public const int IMAGE_WAIT_SECONDS = 60;

    public int $tries = 40;

    public function __construct(public int $socialPostId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->socialPostId))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(DiscordChannel $discord, BlueskyChannel $bluesky, InstagramChannel $instagram): void
    {
        $post = SocialPost::query()->find($this->socialPostId);

        if ($post === null || ! in_array($post->status, [SocialPostStatus::Approved, SocialPostStatus::Failed], true)) {
            return;
        }

        $image = $this->image($post);

        if ($image === null) {
            $this->release(self::IMAGE_WAIT_SECONDS);

            return;
        }

        $deliveries = is_array($post->deliveries) ? $post->deliveries : [];

        foreach ([$discord, $bluesky, $instagram] as $channel) {
            if (($deliveries[$channel->name()]['status'] ?? null) === 'ok') {
                continue;
            }

            $deliveries[$channel->name()] = $this->deliver($channel, $post, $image);

            $post->forceFill(['deliveries' => $deliveries])->save();
        }

        $failed = collect($deliveries)->contains(fn (array $delivery): bool => ($delivery['status'] ?? null) === 'failed');

        $post->forceFill($failed
            ? ['status' => SocialPostStatus::Failed]
            : ['status' => SocialPostStatus::Published, 'published_at' => CarbonImmutable::now()])->save();
    }

    public function failed(Throwable $exception): void
    {
        SocialPost::query()
            ->whereKey($this->socialPostId)
            ->where('status', SocialPostStatus::Approved->value)
            ->update(['status' => SocialPostStatus::Failed->value]);

        Log::warning('Social post publishing job failed', ['social_post_id' => $this->socialPostId, 'exception' => $exception::class]);
    }

    /**
     * @return array<string, string>
     */
    private function deliver(SocialChannel $channel, SocialPost $post, string $image): array
    {
        $at = CarbonImmutable::now()->toIso8601String();

        if (! $channel->isConfigured($post)) {
            return ['status' => 'skipped', 'at' => $at];
        }

        try {
            return ['status' => 'ok', ...$channel->publish($post, $image), 'at' => $at];
        } catch (Throwable $exception) {
            $error = $exception instanceof SocialChannelException ? $exception->getMessage() : $exception::class;

            Log::warning('Social channel failed', ['social_post_id' => $post->id, 'channel' => $channel->name(), 'error' => $error]);

            return ['status' => 'failed', 'error' => $error, 'at' => $at];
        }
    }

    private function image(SocialPost $post): ?string
    {
        if ($post->image_path === null || $post->image_path === '') {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($post->image_path) ? $disk->get($post->image_path) : null;
    }
}
