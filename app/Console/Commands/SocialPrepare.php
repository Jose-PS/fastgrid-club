<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Social\PrepareSocialPosts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('social:prepare')]
#[Description('Create pending social posts for announced weeks, finished race days and finished weeks, and notify admins')]
class SocialPrepare extends Command
{
    public function handle(PrepareSocialPosts $prepareSocialPosts): int
    {
        $created = $prepareSocialPosts->handle();

        $this->info("Social posts created: {$created}.");

        return self::SUCCESS;
    }
}
