<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Donations\DonationPayload;
use App\Actions\Proposals\TargetWeek;
use App\Models\BestLap;
use App\Models\DonationMethod;
use App\Models\DonationPeriod;
use App\Models\Proposal;
use App\Models\RaceResult;
use App\Models\ScheduleVersion;
use App\Models\Vote;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class SitemapEntries
{
    public function __construct(
        private readonly PageMeta $pageMeta,
        private readonly DonationPayload $donationPayload,
        private readonly TargetWeek $targetWeek,
    ) {}

    /**
     * @return array<int, array{loc: string, alternates: array<string, string>, lastmod: CarbonImmutable|null}>
     */
    public function all(): array
    {
        $routes = [...PageMeta::sitemapRoutes(), ...($this->donationPayload->get()['visible'] ? ['donate'] : [])];

        $entries = [];

        foreach ($routes as $name) {
            $alternates = $this->pageMeta->alternates($name);
            $lastmod = $this->lastmod($name);

            foreach (['es', 'en'] as $locale) {
                $entries[] = ['loc' => $alternates[$locale], 'alternates' => $alternates, 'lastmod' => $lastmod];
            }
        }

        return $entries;
    }

    public function lastmod(string $name): ?CarbonImmutable
    {
        return match ($name) {
            'home' => $this->latest([
                $this->activeWeeks()->max('updated_at'),
                DonationPeriod::query()->max('updated_at'),
                DonationMethod::query()->max('updated_at'),
                BestLap::query()->max('achieved_at'),
                RaceResult::query()->max('created_at'),
            ]),
            'calendar' => $this->latest([
                $this->activeWeeks()->max('updated_at'),
                ScheduleVersion::query()->max('updated_at'),
            ]),
            'proposals' => $this->proposalsLastmod(),
            'donate' => $this->latest([
                DonationPeriod::query()->max('updated_at'),
                DonationMethod::query()->max('updated_at'),
            ]),
            default => null,
        };
    }

    private function proposalsLastmod(): ?CarbonImmutable
    {
        $week = $this->targetWeek->find();

        if ($week === null) {
            return null;
        }

        return $this->latest([
            $week->updated_at?->toDateTimeString(),
            Proposal::query()->where('week_id', $week->id)->max('updated_at'),
            Vote::query()->where('week_id', $week->id)->max('updated_at'),
        ]);
    }

    /**
     * @return Builder<Week>
     */
    private function activeWeeks(): Builder
    {
        return Week::query()->whereHas('server', fn (Builder $query) => $query->where('is_active', true));
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function latest(array $values): ?CarbonImmutable
    {
        $dates = array_map(
            fn (mixed $value): CarbonImmutable => CarbonImmutable::parse((string) $value),
            array_filter($values, fn (mixed $value): bool => $value !== null && $value !== ''),
        );

        if ($dates === []) {
            return null;
        }

        return collect($dates)->max();
    }
}
