<?php

declare(strict_types=1);

namespace App\Actions\Donations;

use App\Models\DonationMethod;
use Illuminate\Support\Facades\Cache;

class DonationPayload
{
    public const string KEY = 'donations.public';

    public function __construct(private readonly CurrentDonationPeriod $currentPeriod) {}

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }

    /**
     * @return array{methods: array<int, array{platform: string, url: string}>, goal_cents: int, covered_cents: int, percent: int, visible: bool}
     */
    public function get(): array
    {
        return Cache::remember(
            self::KEY,
            (int) config('mvp.donations.cache_seconds'),
            fn (): array => $this->build(),
        );
    }

    /**
     * @return array{methods: array<int, array{platform: string, url: string}>, goal_cents: int, covered_cents: int, percent: int, visible: bool}
     */
    private function build(): array
    {
        $period = $this->currentPeriod->find();

        $goalCents = $period->goal_cents ?? 0;

        $methods = DonationMethod::query()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (DonationMethod $method): array => [
                'platform' => $method->platform->value,
                'url' => $method->url,
            ])
            ->all();

        return [
            'methods' => $methods,
            'goal_cents' => $goalCents,
            'covered_cents' => $period->covered_cents ?? 0,
            'percent' => $period?->percent() ?? 0,
            'visible' => $methods !== [] || $goalCents > 0,
        ];
    }
}
