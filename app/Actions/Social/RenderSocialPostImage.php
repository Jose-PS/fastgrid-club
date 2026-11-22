<?php

declare(strict_types=1);

namespace App\Actions\Social;

use App\Models\SocialPost;
use App\Models\Track;
use App\Support\TrackSvg;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RenderSocialPostImage
{
    public const DISK = 'public';

    private const TIMEOUT = 60;

    public function __construct(private TrackSvg $trackSvg) {}

    public function handle(SocialPost $post): bool
    {
        $output = tempnam(sys_get_temp_dir(), 'social-');

        if ($output === false) {
            Log::error('Social post image could not be rendered', ['post' => $post->id, 'reason' => 'no temporary file']);

            return false;
        }

        try {
            $result = Process::timeout(self::TIMEOUT)
                ->input($this->payload($post))
                ->run(['node', resource_path('social/render.mjs'), $output]);

            if ($result->failed()) {
                Log::error('Social post image could not be rendered', [
                    'post' => $post->id,
                    'exit_code' => $result->exitCode(),
                    'error' => $result->errorOutput(),
                ]);

                return false;
            }

            $png = file_get_contents($output);

            if ($png === false || $png === '') {
                Log::error('Social post image could not be rendered', ['post' => $post->id, 'reason' => 'empty output']);

                return false;
            }

            $path = "social/{$post->id}-".substr(sha1($png), 0, 12).'.png';

            if (! Storage::disk(self::DISK)->put($path, $png)) {
                Log::error('Social post image could not be stored', ['post' => $post->id, 'path' => $path]);

                return false;
            }

            $previous = $post->image_path;

            $post->forceFill(['image_path' => $path])->save();

            if ($previous !== null && $previous !== $path) {
                Storage::disk(self::DISK)->delete([$previous, preg_replace('/\.png$/i', '', $previous).'.jpg']);
            }

            return true;
        } catch (Throwable $exception) {
            Log::error('Social post image could not be rendered', ['post' => $post->id, 'exception' => $exception->getMessage()]);

            return false;
        } finally {
            @unlink($output);
        }
    }

    private function payload(SocialPost $post): string
    {
        return json_encode([
            'type' => $post->type->value,
            'data' => $post->data,
            'svg' => $this->svg($post),
            'url' => rtrim((string) config('app.url'), '/'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function svg(SocialPost $post): ?string
    {
        $slug = $post->data['svg_slug'] ?? null;

        if (is_string($slug) && $this->trackSvg->has($slug)) {
            return $this->trackSvg->load($slug);
        }

        $trackId = $post->data['track_id'] ?? null;
        $track = $trackId !== null ? Track::query()->whereKey($trackId)->first() : null;

        return $this->trackSvg->forTrack($track)['markup'] ?? null;
    }
}
