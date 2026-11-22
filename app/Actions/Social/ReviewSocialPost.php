<?php

declare(strict_types=1);

namespace App\Actions\Social;

use App\Actions\Audit\RecordAudit;
use App\Enums\SocialPostStatus;
use App\Jobs\PublishSocialPost;
use App\Models\SocialPost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewSocialPost
{
    public function __construct(private readonly RecordAudit $recordAudit) {}

    public function updateText(User $admin, SocialPost $post, string $text): void
    {
        $this->whilePending($post, function (SocialPost $locked) use ($admin, $text): void {
            $before = ['text' => $locked->text];

            $locked->forceFill(['text' => $text])->save();

            $this->recordAudit->handle($admin, 'social_post.text_updated', $locked, $before, ['text' => $text]);
        });
    }

    public function approve(User $admin, SocialPost $post): void
    {
        $this->whilePending($post, function (SocialPost $locked) use ($admin): void {
            $locked->forceFill([
                'status' => SocialPostStatus::Approved,
                'approved_by' => $admin->id,
                'approved_at' => CarbonImmutable::now(),
            ])->save();

            $this->recordAudit->handle($admin, 'social_post.approved', $locked, ['status' => SocialPostStatus::Pending->value], ['status' => SocialPostStatus::Approved->value]);
        });

        PublishSocialPost::dispatch($post->getKey());
    }

    public function retry(User $admin, SocialPost $post): void
    {
        DB::transaction(function () use ($admin, $post): void {
            $locked = SocialPost::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== SocialPostStatus::Failed) {
                throw ValidationException::withMessages(['post' => __('admin.social.errNotFailed')]);
            }

            $locked->forceFill(['status' => SocialPostStatus::Approved])->save();

            $this->recordAudit->handle($admin, 'social_post.retried', $locked, ['status' => SocialPostStatus::Failed->value], ['status' => SocialPostStatus::Approved->value]);
        });

        PublishSocialPost::dispatch($post->getKey());
    }

    public function reject(User $admin, SocialPost $post): void
    {
        $this->whilePending($post, function (SocialPost $locked) use ($admin): void {
            $locked->forceFill(['status' => SocialPostStatus::Rejected])->save();

            $this->recordAudit->handle($admin, 'social_post.rejected', $locked, ['status' => SocialPostStatus::Pending->value], ['status' => SocialPostStatus::Rejected->value]);
        });
    }

    /**
     * @param  callable(SocialPost): void  $callback
     */
    private function whilePending(SocialPost $post, callable $callback): void
    {
        DB::transaction(function () use ($post, $callback): void {
            $locked = SocialPost::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== SocialPostStatus::Pending) {
                throw ValidationException::withMessages(['post' => __('admin.social.errNotPending')]);
            }

            $callback($locked);
        });
    }
}
