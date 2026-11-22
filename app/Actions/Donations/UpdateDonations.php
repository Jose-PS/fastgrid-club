<?php

declare(strict_types=1);

namespace App\Actions\Donations;

use App\Actions\Audit\RecordAudit;
use App\Enums\DonationPlatform;
use App\Models\DonationChange;
use App\Models\DonationMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateDonations
{
    public function __construct(private readonly CurrentDonationPeriod $currentPeriod, private readonly RecordAudit $recordAudit) {}

    /**
     * @param  array<string, string|null>  $urls
     */
    public function handle(User $user, array $urls, int $goalCents, int $coveredCents): void
    {
        DB::transaction(function () use ($user, $urls, $goalCents, $coveredCents): void {
            $urlsBefore = DonationMethod::query()->pluck('url', 'platform')->all();

            foreach (DonationPlatform::cases() as $platform) {
                $url = $urls[$platform->value] ?? null;

                if ($url === null || $url === '') {
                    DonationMethod::query()->where('platform', $platform->value)->delete();

                    continue;
                }

                DonationMethod::query()->updateOrCreate(
                    ['platform' => $platform->value],
                    ['url' => $url, 'position' => $platform->position(), 'updated_by' => $user->id],
                );
            }

            $period = $this->currentPeriod->handle();
            $before = ['goal_cents' => $period->goal_cents, 'covered_cents' => $period->covered_cents];

            foreach (['goal' => $goalCents, 'covered' => $coveredCents] as $field => $new) {
                $column = "{$field}_cents";
                $old = $period->{$column};

                if ($old === $new) {
                    continue;
                }

                DonationChange::query()->create([
                    'donation_period_id' => $period->id,
                    'field' => $field,
                    'old_cents' => $old,
                    'new_cents' => $new,
                    'changed_by' => $user->id,
                ]);

                $period->{$column} = $new;
            }

            $period->updated_by = $user->id;
            $period->save();

            $after = ['goal_cents' => $period->goal_cents, 'covered_cents' => $period->covered_cents];
            $urlsAfter = DonationMethod::query()->pluck('url', 'platform')->all();

            if ($before !== $after || $urlsBefore !== $urlsAfter) {
                $this->recordAudit->handle(
                    $user,
                    'donations.updated',
                    $period,
                    [...$before, 'urls' => $urlsBefore],
                    [...$after, 'urls' => $urlsAfter],
                );
            }
        });

        DonationPayload::forget();
    }
}
