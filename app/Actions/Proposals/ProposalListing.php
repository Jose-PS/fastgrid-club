<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Http\Resources\CatalogCarResource;
use App\Http\Resources\CatalogTrackResource;
use App\Http\Resources\ProposalResource;
use App\Models\Car;
use App\Models\Proposal;
use App\Models\Track;
use App\Models\Week;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ProposalListing
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function forWeek(Week $week): array
    {
        return Cache::remember(
            $this->key($week->id),
            (int) config('mvp.proposals_cache_seconds'),
            fn (): array => ProposalResource::collection($this->ranked($week))->resolve(),
        );
    }

    /**
     * @return array{tracks: array<int, array{id: int, display: string, maxPitSlot: int}>, cars: array<int, array{id: int, displayName: string}>}
     */
    public function options(): array
    {
        return Cache::remember('proposals.options', (int) config('mvp.proposals_cache_seconds'), fn (): array => [
            'tracks' => Track::query()
                ->proposable()
                ->orderBy('display')
                ->orderBy('id')
                ->get(['id', 'display', 'max_pit_slot'])
                ->map(fn (Track $track): array => [
                    'id' => $track->id,
                    'display' => $track->display,
                    'maxPitSlot' => $track->max_pit_slot,
                ])
                ->values()
                ->all(),
            'cars' => Car::query()
                ->proposable()
                ->orderBy('display_name')
                ->orderBy('id')
                ->get(['id', 'display_name'])
                ->map(fn (Car $car): array => [
                    'id' => $car->id,
                    'displayName' => $car->display_name,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array{
     *     cars: array<int, array<string, mixed>>,
     *     tracks: array<int, array<string, mixed>>,
     *     ranges: array{pi: array{min: float, max: float}, lengthM: array{min: int, max: int}, pitSlots: array{min: int, max: int}},
     *     facets: array{brands: array<int, string>, types: array<int, string>, eras: array<int, string>, engines: array<int, string>, classes: array<int, string>, countries: array<int, string>}
     * }
     */
    public function catalog(): array
    {
        return Cache::remember('proposals.catalog', (int) config('mvp.proposals_cache_seconds'), function (): array {
            $cars = CatalogCarResource::collection(
                Car::query()->proposable()->orderBy('display_name')->orderBy('id')->get(),
            )->resolve();
            $tracks = CatalogTrackResource::collection(
                Track::query()->proposable()->orderBy('display')->orderBy('id')->get(),
            )->resolve();

            return [
                'cars' => $cars,
                'tracks' => $tracks,
                'ranges' => [
                    'pi' => $this->floatRange(array_column($cars, 'pi')),
                    'lengthM' => $this->intRange(array_column($tracks, 'lengthM')),
                    'pitSlots' => $this->intRange(array_column($tracks, 'maxPitSlot')),
                ],
                'facets' => [
                    'brands' => $this->distinct(array_column($cars, 'brand')),
                    'types' => $this->distinct(array_column($cars, 'type')),
                    'eras' => $this->distinct(array_column($cars, 'era')),
                    'engines' => $this->distinct(array_column($cars, 'engine')),
                    'classes' => $this->distinct(array_merge(...array_column($cars, 'classes') ?: [[]])),
                    'countries' => $this->distinct(array_column($tracks, 'country')),
                ],
            ];
        });
    }

    /**
     * @param  array<int, int|null>  $values
     * @return array{min: int, max: int}
     */
    private function intRange(array $values): array
    {
        $values = array_filter($values, fn (?int $value): bool => $value !== null);

        return $values === [] ? ['min' => 0, 'max' => 0] : ['min' => min($values), 'max' => max($values)];
    }

    /**
     * @param  array<int, float|null>  $values
     * @return array{min: float, max: float}
     */
    private function floatRange(array $values): array
    {
        $values = array_filter($values, fn (?float $value): bool => $value !== null);

        return $values === [] ? ['min' => 0.0, 'max' => 0.0] : ['min' => min($values), 'max' => max($values)];
    }

    /**
     * @param  array<int, string|null>  $values
     * @return array<int, string>
     */
    private function distinct(array $values): array
    {
        $values = array_values(array_unique(array_filter($values, fn (?string $value): bool => $value !== null && $value !== '')));
        sort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return $values;
    }

    public function forgetCatalog(): void
    {
        Cache::forget('proposals.options');
        Cache::forget('proposals.catalog');
    }

    public function forget(int $weekId): void
    {
        Cache::forget($this->key($weekId));
        Cache::forget(LastVoting::key($weekId));
    }

    /**
     * @return Collection<int, Proposal>
     */
    private function ranked(Week $week): Collection
    {
        return $week->proposals()
            ->whereNull('hidden_at')
            ->with([
                'track:id,display,max_pit_slot',
                'cars' => fn (BelongsToMany $query) => $query->select(['cars.id', 'cars.display_name'])->orderBy('cars.display_name'),
                'user:id,name',
            ])
            ->withCount('votes')
            ->orderByDesc('votes_count')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->values()
            ->each(fn (Proposal $proposal, int $index) => $proposal->setAttribute('rank', $index + 1));
    }

    private function key(int $weekId): string
    {
        return "proposals.listing.{$weekId}";
    }
}
