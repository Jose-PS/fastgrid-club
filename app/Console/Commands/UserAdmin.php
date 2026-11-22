<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Audit\RecordAudit;
use App\Enums\IdentityProvider;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:admin {user : User id, email or SteamID} {--revoke : Remove the admin role instead of granting it}')]
#[Description('Grant or revoke the admin role of a user')]
class UserAdmin extends Command
{
    public function handle(RecordAudit $recordAudit): int
    {
        $reference = trim((string) $this->argument('user'));
        $user = $this->resolveUser($reference);

        if ($user === null) {
            $this->error("No user matches [{$reference}].");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');

        $wasAdmin = (bool) $user->is_admin;

        $user->forceFill(['is_admin' => $grant])->save();

        if ($wasAdmin !== $grant) {
            $recordAudit->handle(
                null,
                $grant ? 'user.admin_granted' : 'user.admin_revoked',
                $user,
                ['is_admin' => $wasAdmin],
                ['is_admin' => $grant],
            );
        }

        $this->info($grant
            ? "User #{$user->id} is now an admin."
            : "User #{$user->id} is no longer an admin.");

        return self::SUCCESS;
    }

    private function resolveUser(string $reference): ?User
    {
        if (preg_match('/^\d{17}$/', $reference) === 1) {
            return UserIdentity::query()
                ->where('provider', IdentityProvider::Steam)
                ->where('provider_id', $reference)
                ->first()
                ?->user;
        }

        if (preg_match('/^\d{1,16}$/', $reference) === 1) {
            return User::query()->find((int) $reference);
        }

        if (str_contains($reference, '@')) {
            return User::query()->where('email', mb_strtolower($reference))->first();
        }

        return null;
    }
}
