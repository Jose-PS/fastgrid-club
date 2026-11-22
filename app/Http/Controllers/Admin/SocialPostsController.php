<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Social\ReviewSocialPost;
use App\Enums\SocialChannelName;
use App\Enums\SocialPostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSocialPostTextRequest;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SocialPostsController extends Controller
{
    private const int HISTORY_LIMIT = 50;

    public function index(Translations $translations): Response
    {
        return Inertia::render('admin/social', [
            'pageTranslations' => $translations->forGroups(['admin']),
            'maxLength' => UpdateSocialPostTextRequest::MAX_LENGTH,
            'accounts' => $this->accounts(),
            'pending' => $this->posts([SocialPostStatus::Pending], null),
            'approved' => $this->posts([SocialPostStatus::Approved, SocialPostStatus::Published, SocialPostStatus::Failed], self::HISTORY_LIMIT),
            'rejected' => $this->posts([SocialPostStatus::Rejected], self::HISTORY_LIMIT),
        ]);
    }

    public function update(UpdateSocialPostTextRequest $request, SocialPost $socialPost, ReviewSocialPost $review): RedirectResponse
    {
        $review->updateText($this->admin($request), $socialPost, $request->text());

        return back();
    }

    public function approve(Request $request, SocialPost $socialPost, ReviewSocialPost $review): RedirectResponse
    {
        $review->approve($this->admin($request), $socialPost);

        return back();
    }

    public function reject(Request $request, SocialPost $socialPost, ReviewSocialPost $review): RedirectResponse
    {
        $review->reject($this->admin($request), $socialPost);

        return back();
    }

    public function retry(Request $request, SocialPost $socialPost, ReviewSocialPost $review): RedirectResponse
    {
        $review->retry($this->admin($request), $socialPost);

        return back();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accounts(): array
    {
        $stored = SocialAccount::query()->get()->keyBy('channel');

        return array_map(function (SocialChannelName $channel) use ($stored): array {
            $account = $stored->get($channel->value);

            return [
                'channel' => $channel->value,
                'enabled' => $account === null || $account->enabled,
                'values' => collect($channel->plainFields())
                    ->mapWithKeys(fn (string $field): array => [$field => $account?->credential($field) ?? ''])
                    ->all(),
                'secrets' => collect($channel->secretFields())
                    ->mapWithKeys(fn (string $field): array => [$field => $account?->credential($field) !== null])
                    ->all(),
            ];
        }, SocialChannelName::cases());
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }

    /**
     * @param  array<int, SocialPostStatus>  $statuses
     * @return array<int, array<string, mixed>>
     */
    private function posts(array $statuses, ?int $limit): array
    {
        return SocialPost::query()
            ->whereIn('status', array_map(fn (SocialPostStatus $status): string => $status->value, $statuses))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get()
            ->map(fn (SocialPost $post): array => [
                'id' => $post->id,
                'type' => $post->type->value,
                'status' => $post->status->value,
                'text' => $post->text,
                'image_url' => $post->image_path === null ? null : Storage::disk('public')->url($post->image_path),
                'channels' => $this->channels($post),
                'created_at' => $post->created_at?->toIso8601String(),
                'approved_at' => $post->approved_at?->toIso8601String(),
                'published_at' => $post->published_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, array{status: string, url: string|null, error: string|null}>
     */
    private function channels(SocialPost $post): array
    {
        $channels = [];

        foreach (['discord', 'bluesky', 'instagram'] as $name) {
            $delivery = $post->deliveries[$name] ?? null;

            if (! is_array($delivery) || ! is_string($delivery['status'] ?? null)) {
                continue;
            }

            $channels[$name] = [
                'status' => $delivery['status'],
                'url' => match ($name) {
                    'bluesky' => $this->blueskyUrl($delivery['uri'] ?? null),
                    'instagram' => $this->instagramUrl($delivery['permalink'] ?? null),
                    default => null,
                },
                'error' => is_string($delivery['error'] ?? null) ? $delivery['error'] : null,
            ];
        }

        return $channels;
    }

    private function instagramUrl(mixed $permalink): ?string
    {
        return is_string($permalink) && str_starts_with($permalink, 'https://') ? $permalink : null;
    }

    private function blueskyUrl(mixed $uri): ?string
    {
        if (! is_string($uri) || preg_match('~^at://([^/]+)/app\.bsky\.feed\.post/([^/]+)$~', $uri, $matches) !== 1) {
            return null;
        }

        $handle = SocialAccount::forChannel(SocialChannelName::Bluesky)?->credential('identifier');
        $profile = $handle !== null ? ltrim($handle, '@') : $matches[1];

        return "https://bsky.app/profile/{$profile}/post/{$matches[2]}";
    }
}
