<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Social\RenderSocialPostImage;
use App\Models\SocialPost;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class RenderSocialPostImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public SocialPost $post) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(RenderSocialPostImage $renderSocialPostImage): void
    {
        if ($this->post->image_path !== null) {
            return;
        }

        if (! $renderSocialPostImage->handle($this->post)) {
            throw new RuntimeException("Social post {$this->post->id} image could not be rendered.");
        }
    }
}
