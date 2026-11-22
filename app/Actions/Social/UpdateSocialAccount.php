<?php

declare(strict_types=1);

namespace App\Actions\Social;

use App\Actions\Audit\RecordAudit;
use App\Enums\SocialChannelName;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateSocialAccount
{
    public function __construct(private readonly RecordAudit $recordAudit) {}

    /**
     * @param  array<string, string|null>  $values
     * @param  list<string>  $cleared
     */
    public function handle(User $admin, SocialChannelName $channel, bool $enabled, array $values, array $cleared): void
    {
        DB::transaction(function () use ($admin, $channel, $enabled, $values, $cleared): void {
            $account = SocialAccount::query()->where('channel', $channel->value)->lockForUpdate()->first()
                ?? new SocialAccount(['channel' => $channel->value, 'enabled' => $enabled, 'credentials' => []]);

            $before = ['enabled' => $account->exists ? $account->enabled : null];
            $credentials = $account->credentials;
            $changed = [];

            foreach ($channel->plainFields() as $field) {
                $new = $values[$field] ?? null;

                if (($credentials[$field] ?? null) !== $new) {
                    $changed[] = $field;
                }

                $this->assign($credentials, $field, $new);
            }

            foreach ($channel->secretFields() as $field) {
                $new = $values[$field] ?? null;

                if ($new !== null) {
                    $this->assign($credentials, $field, $new);
                    $changed[] = $field;
                } elseif (in_array($field, $cleared, true) && isset($credentials[$field])) {
                    unset($credentials[$field]);
                    $changed[] = $field;
                }
            }

            $account->enabled = $enabled;
            $account->credentials = $credentials;

            if ($channel === SocialChannelName::Instagram && in_array('access_token', $changed, true)) {
                $account->expires_at = null;
                $account->refreshed_at = null;
            }

            $account->save();

            $this->recordAudit->handle($admin, 'social_account.updated', $account, $before, [
                'channel' => $channel->value,
                'enabled' => $enabled,
                'changed' => $changed,
            ]);
        });
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function assign(array &$credentials, string $field, ?string $value): void
    {
        if ($value === null) {
            unset($credentials[$field]);

            return;
        }

        $credentials[$field] = $value;
    }
}
