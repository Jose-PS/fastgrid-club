<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Social\RenderSocialPostImage;
use App\Models\SocialPost;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('social:render {post? : Social post id; without it every post without an image is rendered}')]
#[Description('Render the image of a social post, or of every post that has none')]
class SocialRender extends Command
{
    public function handle(RenderSocialPostImage $renderSocialPostImage): int
    {
        $postId = $this->argument('post');

        $posts = $postId !== null
            ? SocialPost::query()->whereKey($postId)->get()
            : SocialPost::query()->whereNull('image_path')->orderBy('id')->get();

        if ($postId !== null && $posts->isEmpty()) {
            $this->error("Social post {$postId} not found.");

            return self::FAILURE;
        }

        $rendered = 0;
        $failed = 0;

        foreach ($posts as $post) {
            if ($renderSocialPostImage->handle($post)) {
                $rendered++;
            } else {
                $failed++;
            }
        }

        $this->info("Social post images rendered: {$rendered}. Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
